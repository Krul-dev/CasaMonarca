import { useEffect, useMemo, useRef, useState } from 'react'

import { ApiRequestError, getCurrentMigrantQuestionnaire } from '../../lib/registry'
import type { PendingMigrantDocument } from '../../lib/migrantDocuments'
import {
  answerHasValue,
  answersFromPayload,
  buildQuestionnairePayload,
  canonicalAnswerText,
  pruneUnreachableAnswers,
  reachableQuestions,
  validateQuestionAnswer,
} from '../../lib/migrantQuestionnaire'
import type {
  MigrantQuestionnaireAnswer,
  MigrantQuestionnaireDefinition,
  MigrantRegistrationPayload,
  QuestionnaireLocale,
  QuestionnaireQuestion,
  RegistryEntry,
} from '../../types/registry'
import { AppIcon } from '../ui/AppIcon'
import { MigrantDocumentsPanel } from './MigrantDocumentsPanel'

const MAX_DOCUMENTS = 10
const MAX_UPLOAD_BYTES = 16 * 1024 * 1024
const ACCEPTED_DOCUMENT_TYPES = [
  'application/pdf',
  'image/jpeg',
  'image/png',
  'application/msword',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
]

const localToday = () => {
  const now = new Date()
  return new Date(now.getTime() - now.getTimezoneOffset() * 60_000).toISOString().slice(0, 10)
}

const withDefaultAttentionDate = (
  definition: MigrantQuestionnaireDefinition,
  answers: Record<string, MigrantQuestionnaireAnswer>,
  date: string,
) => {
  const questionId = definition.summaryMappings.attentionDate
  if (!questionId || answerHasValue(answers[questionId])) return answers

  return { ...answers, [questionId]: { value: date } }
}

export type MigrantDocumentContext = {
  canDelete: boolean
  canDownload: boolean
  canDownloadArcoApproved: boolean
  canUpload: boolean
  canView: boolean
  entryId: number
  onSessionExpired?: () => void
}

type Props = {
  documentContext?: MigrantDocumentContext | null
  documentsEnabled?: boolean
  draftEntryId?: number | null
  draftsEnabled?: boolean
  initialPayload?: Partial<MigrantRegistrationPayload> | null
  onCancel?: () => void
  onDraftSaved?: (entry: RegistryEntry) => void
  onSaveDraft?: (payload: MigrantRegistrationPayload, draftId: number | null) => Promise<RegistryEntry>
  onSubmit: (
    payload: MigrantRegistrationPayload,
    documents: PendingMigrantDocument[],
    draftId: number | null,
  ) => Promise<void>
  submitLabel?: string
  successMessage?: string
}

type SaveState = 'idle' | 'saving' | 'saved' | 'error'

export function MigrantRegistryForm({
  documentContext,
  documentsEnabled = true,
  draftEntryId = null,
  draftsEnabled = false,
  initialPayload,
  onCancel,
  onDraftSaved,
  onSaveDraft,
  onSubmit,
  submitLabel = 'Enviar registro',
  successMessage = 'Registro enviado a revisión.',
}: Props) {
  const [definition, setDefinition] = useState<MigrantQuestionnaireDefinition | null>(null)
  const [answers, setAnswers] = useState<Record<string, MigrantQuestionnaireAnswer>>({})
  const [locale, setLocale] = useState<QuestionnaireLocale>('es')
  const [sectionIndex, setSectionIndex] = useState(0)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [loadError, setLoadError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [messageTone, setMessageTone] = useState<'error' | 'success'>('success')
  const [pendingDocuments, setPendingDocuments] = useState<PendingMigrantDocument[]>([])
  const [submitting, setSubmitting] = useState(false)
  const [saveState, setSaveState] = useState<SaveState>('idle')
  const [currentDraftId, setCurrentDraftId] = useState<number | null>(draftEntryId)
  const [dirtyRevision, setDirtyRevision] = useState(0)
  const lastSavedPayload = useRef('')
  const registrationStartDate = useRef(localToday())

  useEffect(() => {
    let active = true
    getCurrentMigrantQuestionnaire()
      .then(({ data }) => {
        if (!active) return
        setDefinition(data)
        const initialAnswers = draftsEnabled && !initialPayload
          ? withDefaultAttentionDate(data, answersFromPayload(data, initialPayload), registrationStartDate.current)
          : answersFromPayload(data, initialPayload)
        setAnswers(initialAnswers)
        lastSavedPayload.current = JSON.stringify(buildQuestionnairePayload(data, initialAnswers))
      })
      .catch((error) => {
        if (active) setLoadError(error instanceof Error ? error.message : 'No fue posible cargar el cuestionario.')
      })
    return () => { active = false }
  }, [draftsEnabled, initialPayload])

  useEffect(() => {
    setCurrentDraftId(draftEntryId)
    setSectionIndex(0)
    setPendingDocuments([])
    setMessage(null)
  }, [draftEntryId])

  const reachable = useMemo(
    () => definition ? reachableQuestions(definition, answers) : [],
    [answers, definition],
  )
  const activeSections = useMemo(() => {
    if (!definition) return []
    const ids = new Set(reachable.map((question) => question.sectionId))
    return definition.sections.filter((section) => ids.has(section.id))
  }, [definition, reachable])
  const isReviewStep = sectionIndex >= activeSections.length
  const currentSection = activeSections[Math.min(sectionIndex, Math.max(0, activeSections.length - 1))]
  const currentQuestions = currentSection
    ? reachable.filter((question) => question.sectionId === currentSection.id)
    : []

  useEffect(() => {
    if (!definition) return
    const attentionId = definition.summaryMappings.attentionDate
    const birthId = definition.summaryMappings.birthDate
    const ageId = definition.summaryMappings.age
    const attentionValue = answers[attentionId]?.value
    const birthValue = answers[birthId]?.value
    if (typeof attentionValue !== 'string' || typeof birthValue !== 'string' || !attentionValue || !birthValue) return
    const birth = new Date(`${birthValue}T00:00:00`)
    const attention = new Date(`${attentionValue}T00:00:00`)
    if (Number.isNaN(birth.getTime()) || Number.isNaN(attention.getTime()) || birth > attention) return
    let years = attention.getFullYear() - birth.getFullYear()
    if (attention.getMonth() < birth.getMonth() || (attention.getMonth() === birth.getMonth() && attention.getDate() < birth.getDate())) years -= 1
    const value = years === 0 ? '0 - 11 meses' : String(Math.min(90, years))
    if (answers[ageId]?.value === value) return
    setAnswers((current) => ({ ...current, [ageId]: { value } }))
    setDirtyRevision((revision) => revision + 1)
  }, [answers, definition])

  useEffect(() => {
    if (sectionIndex > activeSections.length) setSectionIndex(activeSections.length)
  }, [activeSections.length, sectionIndex])

  useEffect(() => {
    if (!draftsEnabled || !definition || !onSaveDraft || dirtyRevision === 0 || submitting) return
    const payload = buildQuestionnairePayload(definition, answers)
    const serialized = JSON.stringify(payload)
    if (serialized === lastSavedPayload.current) return

    const timeout = window.setTimeout(() => {
      setSaveState('saving')
      onSaveDraft(payload, currentDraftId)
        .then((entry) => {
          setCurrentDraftId(entry.id)
          lastSavedPayload.current = serialized
          setSaveState('saved')
          onDraftSaved?.(entry)
        })
        .catch(() => setSaveState('error'))
    }, 1000)

    return () => window.clearTimeout(timeout)
  }, [answers, currentDraftId, definition, dirtyRevision, draftsEnabled, onDraftSaved, onSaveDraft, submitting])

  const updateAnswer = (question: QuestionnaireQuestion, answer: MigrantQuestionnaireAnswer) => {
    if (!definition) return
    setAnswers((current) => pruneUnreachableAnswers(definition, { ...current, [question.id]: answer }))
    setErrors((current) => {
      const next = { ...current }
      delete next[question.id]
      return next
    })
    setDirtyRevision((revision) => revision + 1)
    setMessage(null)
  }

  const validateQuestions = (questions: QuestionnaireQuestion[]) => {
    const nextErrors: Record<string, string> = {}
    questions.forEach((question) => {
      const error = validateQuestionAnswer(question, answers[question.id])
      if (error) nextErrors[question.id] = error
    })
    setErrors(nextErrors)
    return Object.keys(nextErrors).length === 0
  }

  const goNext = () => {
    if (!validateQuestions(currentQuestions)) return
    setSectionIndex((current) => Math.min(current + 1, activeSections.length))
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  const handleDocumentSelection = (files: FileList | null) => {
    if (!files) return
    const selected = Array.from(files)
    if (pendingDocuments.length + selected.length > MAX_DOCUMENTS) {
      setMessageTone('error')
      setMessage(`Se permiten hasta ${MAX_DOCUMENTS} documentos de respaldo.`)
      return
    }
    if (selected.some((file) => file.size > MAX_UPLOAD_BYTES || !ACCEPTED_DOCUMENT_TYPES.includes(file.type))) {
      setMessageTone('error')
      setMessage('Los documentos deben ser PDF, JPEG, PNG, DOC o DOCX y no exceder 16 MB.')
      return
    }
    setPendingDocuments((current) => [...current, ...selected.map((file) => ({ file, label: '' }))])
  }

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!definition) return
    if (!validateQuestions(reachable)) {
      const firstInvalid = reachable.find((question) => validateQuestionAnswer(question, answers[question.id]))
      const invalidSection = activeSections.findIndex((section) => section.id === firstInvalid?.sectionId)
      if (invalidSection >= 0) setSectionIndex(invalidSection)
      return
    }

    setSubmitting(true)
    setMessage(null)
    try {
      let draftId = currentDraftId
      const payload = buildQuestionnairePayload(definition, answers)
      if (draftsEnabled && onSaveDraft && draftId === null) {
        const entry = await onSaveDraft(payload, null)
        draftId = entry.id
        setCurrentDraftId(entry.id)
      }
      await onSubmit(payload, pendingDocuments, draftId)
      registrationStartDate.current = localToday()
      const cleared = draftsEnabled
        ? withDefaultAttentionDate(definition, {}, registrationStartDate.current)
        : {}
      setAnswers(cleared)
      setPendingDocuments([])
      setCurrentDraftId(null)
      setDirtyRevision(0)
      setSectionIndex(0)
      setSaveState('idle')
      setMessageTone('success')
      setMessage(successMessage)
    } catch (error) {
      const fieldErrors = error instanceof ApiRequestError && error.errors
        ? Object.values(error.errors).flat().filter(Boolean)
        : []
      setMessageTone('error')
      setMessage(fieldErrors[0] ?? (error instanceof Error ? error.message : 'No fue posible enviar el registro.'))
    } finally {
      setSubmitting(false)
    }
  }

  if (loadError) return <div className="login-feedback login-feedback--error">{loadError}</div>
  if (!definition) return <p className="workspace-panel__copy">Cargando cuestionario...</p>

  const fullName = [definition.summaryMappings.firstName, definition.summaryMappings.firstLastName, definition.summaryMappings.secondLastName]
    .map((id) => answers[id]?.value)
    .filter((value): value is string => typeof value === 'string' && value.trim() !== '')
    .join(' ')

  return (
    <form className="registry-form registry-questionnaire" onSubmit={handleSubmit}>
      <header className="registry-questionnaire__toolbar">
        <div>
          <strong>{isReviewStep ? 'Revisión final' : currentSection?.title.es}</strong>
          <span>Paso {Math.min(sectionIndex + 1, activeSections.length + 1)} de {activeSections.length + 1}</span>
        </div>
        <label>
          Idioma de apoyo
          <select onChange={(event) => setLocale(event.target.value as QuestionnaireLocale)} value={locale}>
            {definition.locales.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
          </select>
        </label>
      </header>

      <div aria-label="Progreso del cuestionario" className="registry-questionnaire__progress">
        {activeSections.map((section, index) => (
          <span className={index < sectionIndex ? 'is-complete' : index === sectionIndex ? 'is-current' : ''} key={section.id} />
        ))}
        <span className={isReviewStep ? 'is-current' : ''} />
      </div>

      {!isReviewStep ? (
        <section className="registry-questionnaire__questions">
          {currentQuestions.map((question) => (
            <QuestionField
              answer={answers[question.id]}
              error={errors[question.id]}
              key={question.id}
              locale={locale}
              onChange={(answer) => updateAnswer(question, answer)}
              question={question}
            />
          ))}
        </section>
      ) : (
        <section className="registry-questionnaire__review">
          {activeSections.map((section) => (
            <section key={section.id}>
              <h3>{section.title.es}</h3>
              <dl>
                {reachable.filter((question) => question.sectionId === section.id && answerHasValue(answers[question.id])).map((question) => (
                  <div key={question.id}><dt>{question.title.es}</dt><dd>{canonicalAnswerText(question, answers[question.id])}</dd></div>
                ))}
              </dl>
            </section>
          ))}

          {documentsEnabled && (!documentContext || documentContext.canUpload) ? (
            <fieldset className="registry-form__documents">
              <legend>Documentos de respaldo</legend>
              <label className="registry-form__document-picker">
                Agregar documentos al envío
                <input accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" disabled={submitting} multiple onChange={(event) => { handleDocumentSelection(event.target.files); event.target.value = '' }} type="file" />
              </label>
              {pendingDocuments.length ? (
                <ul className="migrant-documents__list">
                  {pendingDocuments.map((document, index) => (
                    <li className="migrant-documents__item" key={`${document.file.name}-${document.file.lastModified}-${index}`}>
                      <div className="registry-form__document-details"><strong>{document.file.name}</strong><input aria-label={`Etiqueta para ${document.file.name}`} maxLength={255} onChange={(event) => setPendingDocuments((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, label: event.target.value } : item))} placeholder="Etiqueta opcional" value={document.label} /></div>
                      <button aria-label={`Quitar ${document.file.name}`} className="session-action session-action--quiet session-action--inline" onClick={() => setPendingDocuments((current) => current.filter((_, itemIndex) => itemIndex !== index))} type="button"><AppIcon name="delete" /></button>
                    </li>
                  ))}
                </ul>
              ) : null}
            </fieldset>
          ) : null}

          {documentsEnabled && documentContext?.canView ? (
            <fieldset className="registry-form__documents">
              <legend>Documentos existentes</legend>
              <MigrantDocumentsPanel canDelete={documentContext.canDelete} canDownload={documentContext.canDownload} canDownloadArcoApproved={documentContext.canDownloadArcoApproved} canView embedded entryId={documentContext.entryId} onSessionExpired={documentContext.onSessionExpired} />
            </fieldset>
          ) : null}
        </section>
      )}

      <footer className="registry-form__footer">
        <div className="registry-questionnaire__record">
          <span>Registro: {fullName || 'Nombre pendiente'}</span>
          {draftsEnabled ? <small className={`registry-questionnaire__save registry-questionnaire__save--${saveState}`}>{saveState === 'saving' ? 'Guardando...' : saveState === 'saved' ? 'Borrador guardado' : saveState === 'error' ? 'Error al guardar; se reintentará con el próximo cambio' : 'El borrador se guardará automáticamente'}</small> : null}
        </div>
        <div className="registry-form__actions">
          {onCancel ? <button className="session-action session-action--quiet" disabled={submitting} onClick={onCancel} type="button">Cancelar</button> : null}
          {sectionIndex > 0 ? <button className="session-action session-action--quiet" disabled={submitting} onClick={() => setSectionIndex((current) => Math.max(0, current - 1))} type="button">Anterior</button> : null}
          {!isReviewStep
            ? <button className="session-action" key="next" onClick={goNext} type="button">Siguiente</button>
            : <button className="session-action" disabled={submitting} key="submit" type="submit">{submitting ? 'Enviando...' : submitLabel}</button>}
        </div>
      </footer>

      {message ? <div className={`login-feedback login-feedback--${messageTone}`}>{message}</div> : null}
    </form>
  )
}

function QuestionField({
  answer,
  error,
  locale,
  onChange,
  question,
}: {
  answer?: MigrantQuestionnaireAnswer
  error?: string
  locale: QuestionnaireLocale
  onChange: (answer: MigrantQuestionnaireAnswer) => void
  question: QuestionnaireQuestion
}) {
  const selectedValues = Array.isArray(answer?.value) ? answer.value : []
  const selectedSingle = typeof answer?.value === 'string' ? answer.value : ''
  const title = question.title[locale] || question.title.es
  const help = question.help?.[locale] || question.help?.es
  const usesCompactSelect = !question.multipleSelection && question.choices.length > 12
  const selectedUsesOther = question.choices.some((choice) => choice.custom && (question.multipleSelection ? selectedValues.includes(choice.value) : selectedSingle === choice.value))
  const usesWideLayout = question.multiline || question.multipleSelection || (!usesCompactSelect && question.choices.length > 4)

  return (
    <fieldset className={`registry-questionnaire__question${usesWideLayout ? ' registry-questionnaire__question--wide' : ''}${error ? ' registry-questionnaire__question--error' : ''}`}>
      <legend>{question.number}. {title}{question.required ? <span aria-label="obligatoria"> *</span> : null}</legend>
      {help ? <p>{help}</p> : null}

      {question.type === 'text' && question.multiline ? (
        <textarea aria-label={title} maxLength={5000} onChange={(event) => onChange({ value: event.target.value })} rows={4} value={selectedSingle} />
      ) : null}
      {question.type === 'text' && !question.multiline ? (
        <input aria-label={title} inputMode={question.numeric ? 'numeric' : undefined} maxLength={5000} onChange={(event) => onChange({ value: event.target.value })} type={question.numeric ? 'number' : 'text'} value={selectedSingle} />
      ) : null}
      {question.type === 'date' ? <input aria-label={title} onChange={(event) => onChange({ value: event.target.value })} type="date" value={selectedSingle} /> : null}

      {question.type === 'choice' && usesCompactSelect ? (
        <select aria-label={title} onChange={(event) => onChange({ value: event.target.value })} value={selectedSingle}>
          <option value="">Seleccione una opción</option>
          {question.choices.map((choice) => <option key={choice.id} value={choice.value}>{choice.label[locale] || choice.label.es}</option>)}
        </select>
      ) : null}

      {question.type === 'choice' && !usesCompactSelect ? (
        <div className="registry-questionnaire__choices">
          {question.choices.map((choice) => {
            const checked = question.multipleSelection ? selectedValues.includes(choice.value) : selectedSingle === choice.value
            return (
              <label key={choice.id}>
                <input
                  checked={checked}
                  name={question.id}
                  onChange={() => {
                    if (!question.multipleSelection) onChange({ value: choice.value, ...(choice.custom && answer?.otherText ? { otherText: answer.otherText } : {}) })
                    else onChange({ value: checked ? selectedValues.filter((value) => value !== choice.value) : [...selectedValues, choice.value], ...(answer?.otherText ? { otherText: answer.otherText } : {}) })
                  }}
                  type={question.multipleSelection ? 'checkbox' : 'radio'}
                />
                <span>{choice.label[locale] || choice.label.es}</span>
              </label>
            )
          })}
        </div>
      ) : null}

      {selectedUsesOther ? <label className="registry-questionnaire__other">Especifique la respuesta en español<input maxLength={5000} onChange={(event) => onChange({ value: answer?.value ?? 'Otro', otherText: event.target.value })} value={answer?.otherText ?? ''} /></label> : null}
      {locale !== 'es' && (question.type === 'text' || selectedUsesOther) ? <small>Registre la respuesta en español.</small> : null}
      {error ? <span className="registry-questionnaire__error">{error}</span> : null}
    </fieldset>
  )
}
