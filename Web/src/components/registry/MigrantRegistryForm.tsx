import { useEffect, useMemo, useState } from 'react'

import {
  CIVIL_STATUS_OPTIONS,
  COUNTRY_OPTIONS,
  GENDER_OPTIONS,
  POPULATION_GROUP_OPTIONS,
} from '../../config/registryFormOptions'
import { AppIcon } from '../ui/AppIcon'
import type { PendingMigrantDocument } from '../../lib/migrantDocuments'
import { ApiRequestError, type MigrantRegistrationPayload } from '../../lib/registry'
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

export type MigrantDocumentContext = {
  canDelete: boolean
  canDownload: boolean
  canUpload: boolean
  canView: boolean
  entryId: number
  onSessionExpired?: () => void
}

type Props = {
  documentContext?: MigrantDocumentContext | null
  documentsEnabled?: boolean
  initialPayload?: Partial<MigrantRegistrationPayload> | null
  onCancel?: () => void
  onSubmit: (
    payload: MigrantRegistrationPayload,
    documents: PendingMigrantDocument[],
  ) => Promise<void>
  submitLabel?: string
  successMessage?: string
}

type FormState = Omit<MigrantRegistrationPayload, 'fullName'>

const localToday = () => {
  const now = new Date()
  const localDate = new Date(now.getTime() - now.getTimezoneOffset() * 60_000)

  return localDate.toISOString().slice(0, 10)
}

const calculateAge = (birthDateValue: string, attentionDateValue: string) => {
  const birthDate = new Date(`${birthDateValue}T00:00:00`)
  const attentionDate = new Date(`${attentionDateValue}T00:00:00`)

  if (Number.isNaN(birthDate.getTime()) || Number.isNaN(attentionDate.getTime()) || birthDate > attentionDate) {
    return null
  }

  let age = attentionDate.getFullYear() - birthDate.getFullYear()
  const birthdayHasOccurred = attentionDate.getMonth() > birthDate.getMonth() ||
    (attentionDate.getMonth() === birthDate.getMonth() && attentionDate.getDate() >= birthDate.getDate())

  if (!birthdayHasOccurred) {
    age -= 1
  }

  return age >= 0 && age <= 120 ? age : null
}

const initialState: FormState = {
  attentionDate: localToday(),
  birthDate: '',
  civilStatus: '',
  countryOfOrigin: '',
  departmentState: '',
  firstLastName: '',
  firstName: '',
  gender: '',
  notes: '',
  phone: '',
  populationGroup: '',
  secondLastName: '',
}

const buildFullName = (state: FormState) =>
  [state.firstName, state.firstLastName, state.secondLastName]
    .map((part) => part.trim())
    .filter(Boolean)
    .join(' ')

const normalizeGender = (gender?: string) => {
  if (gender === 'woman') return 'female'
  if (gender === 'man') return 'male'

  return gender ?? ''
}

const normalizeCivilStatus = (civilStatus?: string) =>
  civilStatus === 'union' ? 'common_law_union' : civilStatus ?? ''

const formStateFromPayload = (
  payload?: Partial<MigrantRegistrationPayload> | null,
): FormState => {
  const attentionDate = payload?.attentionDate ?? initialState.attentionDate
  const birthDate = payload?.birthDate ?? ''
  return {
    ...initialState,
    attentionDate,
    birthDate,
    civilStatus: normalizeCivilStatus(payload?.civilStatus),
    countryOfOrigin: payload?.countryOfOrigin ?? '',
    departmentState: payload?.departmentState ?? '',
    firstLastName: payload?.firstLastName ?? '',
    firstName: payload?.firstName ?? '',
    gender: normalizeGender(payload?.gender),
    notes: payload?.notes ?? '',
    phone: payload?.phone ?? '',
    populationGroup: payload?.populationGroup ?? '',
    secondLastName: payload?.secondLastName ?? '',
  }
}

const validatePopulationGroup = (populationGroup: string, age: number) => {
  if (populationGroup === 'adult') return age >= 18 && age <= 59
  if (populationGroup === 'older_adult') return age >= 60
  if (populationGroup === 'accompanied_girl' || populationGroup === 'accompanied_boy') return age <= 11
  if (populationGroup === 'accompanied_adolescent_boy' || populationGroup === 'accompanied_adolescent_girl') return age >= 12 && age <= 17
  if (populationGroup === 'unaccompanied_minor') return age <= 17

  return false
}

const validateName = (label: string, value: string, required = true) => {
  const normalizedValue = value.trim()

  if (!normalizedValue) {
    return required ? `${label} is required.` : null
  }

  return /^[\p{L}\p{M}][\p{L}\p{M} .'-]*$/u.test(normalizedValue)
    ? null
    : `${label} may contain only letters, spaces, apostrophes, periods, and hyphens.`
}

const validateForm = (form: FormState) => {
  const nameError = validateName('First name', form.firstName)
    ?? validateName('First last name', form.firstLastName)
    ?? validateName('Second last name', form.secondLastName, false)

  if (nameError) {
    return nameError
  }

  const calculatedAge = calculateAge(form.birthDate, form.attentionDate)

  if (calculatedAge === null) {
    return 'Birth date must be on or before the attention date and cannot imply an age over 120.'
  }

  if (!validatePopulationGroup(form.populationGroup, calculatedAge)) {
    return 'Population group is not consistent with the calculated age.'
  }

  if (form.phone && !/^\+?[0-9][0-9 ()-]{6,24}$/.test(form.phone.trim())) {
    return 'Phone must contain 7 to 25 digits or common telephone separators.'
  }

  return null
}

export function MigrantRegistryForm({
  documentContext,
  documentsEnabled = true,
  initialPayload,
  onCancel,
  onSubmit,
  submitLabel = 'Submit registration',
  successMessage = 'Registration submitted for review.',
}: Props) {
  const [form, setForm] = useState<FormState>(() => formStateFromPayload(initialPayload))
  const [submitting, setSubmitting] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const [messageTone, setMessageTone] = useState<'error' | 'success'>('success')
  const [pendingDocuments, setPendingDocuments] = useState<PendingMigrantDocument[]>([])
  const fullName = useMemo(() => buildFullName(form), [form])
  const hasLegacyCountry = form.countryOfOrigin !== '' && !COUNTRY_OPTIONS.includes(form.countryOfOrigin)
  const hasLegacyCivilStatus = form.civilStatus !== '' && !CIVIL_STATUS_OPTIONS.some(({ value }) => value === form.civilStatus)

  useEffect(() => {
    setForm(formStateFromPayload(initialPayload))
    setMessage(null)
    setPendingDocuments([])
  }, [initialPayload])

  const handleDocumentSelection = (files: FileList | null) => {
    if (!files) return

    const selected = Array.from(files)

    if (pendingDocuments.length + selected.length > MAX_DOCUMENTS) {
      setMessageTone('error')
      setMessage(`A registration can include up to ${MAX_DOCUMENTS} supporting documents.`)
      return
    }

    const invalid = selected.find((file) =>
      file.size > MAX_UPLOAD_BYTES || !ACCEPTED_DOCUMENT_TYPES.includes(file.type),
    )

    if (invalid) {
      setMessageTone('error')
      setMessage('Documents must be PDF, JPEG, PNG, DOC, or DOCX files no larger than 16 MB.')
      return
    }

    setPendingDocuments((current) => [
      ...current,
      ...selected.map((file) => ({ file, label: '' })),
    ])
    setMessage(null)
  }

  const updateField = (field: keyof FormState, value: string) => {
    setForm((current) => {
      const next = { ...current, [field]: value }

      return next
    })
  }

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setMessage(null)

    const validationMessage = validateForm(form)

    if (validationMessage) {
      setMessageTone('error')
      setMessage(validationMessage)
      return
    }

    setSubmitting(true)

    try {
      await onSubmit(
        {
          ...form,
          fullName,
        },
        pendingDocuments,
      )

      setForm(formStateFromPayload(initialPayload))
      setPendingDocuments([])
      setMessageTone('success')
      setMessage(successMessage)
    } catch (error) {
      setMessageTone('error')
      const fieldErrors = error instanceof ApiRequestError && error.errors
        ? Object.values(error.errors).flat().filter(Boolean)
        : []
      setMessage(
        fieldErrors.length > 0
          ? fieldErrors.join(' ')
          : error instanceof Error
            ? error.message
            : 'Unable to submit the registration.',
      )
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <form className="registry-form" onSubmit={handleSubmit}>
      <div className="registry-form__grid">
        <label>
          Attention date
          <input
            max={localToday()}
            onChange={(event) => updateField('attentionDate', event.target.value)}
            required
            type="date"
            value={form.attentionDate}
          />
        </label>

        <label>
          First name (without surnames)
          <input
            autoComplete="given-name"
            maxLength={120}
            onChange={(event) => updateField('firstName', event.target.value)}
            required
            value={form.firstName}
          />
        </label>

        <label>
          First last name
          <input
            autoComplete="family-name"
            maxLength={120}
            onChange={(event) => updateField('firstLastName', event.target.value)}
            required
            value={form.firstLastName}
          />
        </label>

        <label>
          Second last name (optional)
          <input
            maxLength={120}
            onChange={(event) => updateField('secondLastName', event.target.value)}
            value={form.secondLastName}
          />
        </label>

        <label>
          Contact phone number
          <input
            autoComplete="tel"
            inputMode="tel"
            maxLength={25}
            onChange={(event) => updateField('phone', event.target.value)}
            pattern="\+?[0-9][0-9 ()-]{6,24}"
            value={form.phone}
          />
        </label>

        <fieldset className="registry-form__choice-group">
          <legend>Gender</legend>
          {GENDER_OPTIONS.map((option) => (
            <label key={option.value}>
              <input
                checked={form.gender === option.value}
                name="gender"
                onChange={() => updateField('gender', option.value)}
                required
                type="radio"
                value={option.value}
              />
              <span>{option.label}</span>
            </label>
          ))}
        </fieldset>

        <label>
          Country of origin
          <select
            aria-label="Country of origin"
            autoComplete="country-name"
            onChange={(event) => updateField('countryOfOrigin', event.target.value)}
            required
            value={form.countryOfOrigin}
          >
            <option value="">Select a country</option>
            {hasLegacyCountry ? <option value={form.countryOfOrigin}>{form.countryOfOrigin} (existing value)</option> : null}
            {COUNTRY_OPTIONS.map((country) => <option key={country} value={country}>{country}</option>)}
          </select>
        </label>

        <label>
          Department / state
          <input
            maxLength={120}
            onChange={(event) => updateField('departmentState', event.target.value)}
            required
            value={form.departmentState}
          />
        </label>

        <label>
          Civil status
          <select
            aria-label="Civil status"
            onChange={(event) => updateField('civilStatus', event.target.value)}
            required
            value={form.civilStatus}
          >
            <option value="">Select one</option>
            {hasLegacyCivilStatus ? <option value={form.civilStatus}>{form.civilStatus.replace(/_/g, ' ')} (existing value)</option> : null}
            {CIVIL_STATUS_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
          </select>
        </label>

        <label>
          Birth date
          <input
            max={form.attentionDate || localToday()}
            onChange={(event) => updateField('birthDate', event.target.value)}
            required
            type="date"
            value={form.birthDate}
          />
        </label>

        <fieldset className="registry-form__choice-group registry-form__choice-group--wide">
          <legend>Population group</legend>
          {POPULATION_GROUP_OPTIONS.map((option) => (
            <label key={option.value}>
              <input
                checked={form.populationGroup === option.value}
                name="populationGroup"
                onChange={() => updateField('populationGroup', option.value)}
                required
                type="radio"
                value={option.value}
              />
              <span>{option.label}</span>
            </label>
          ))}
        </fieldset>
      </div>

      {documentsEnabled && (!documentContext || documentContext.canUpload) ? (
        <fieldset className="registry-form__documents">
          <legend>Supporting documents</legend>
          <label className="registry-form__document-picker">
            Supporting documents
            <input
              accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
              disabled={submitting || pendingDocuments.length >= MAX_DOCUMENTS}
              multiple
              onChange={(event) => {
                handleDocumentSelection(event.target.files)
                event.target.value = ''
              }}
              type="file"
            />
          </label>

          {pendingDocuments.length > 0 ? (
            <ul className="migrant-documents__list">
              {pendingDocuments.map((document, index) => (
                <li className="migrant-documents__item" key={`${document.file.name}-${document.file.lastModified}-${index}`}>
                  <div className="registry-form__document-details">
                    <strong>{document.file.name}</strong>
                    <input
                      aria-label={`Label for ${document.file.name}`}
                      disabled={submitting}
                      maxLength={255}
                      onChange={(event) => setPendingDocuments((current) => current.map((item, itemIndex) =>
                        itemIndex === index ? { ...item, label: event.target.value } : item,
                      ))}
                      placeholder="Label (optional)"
                      value={document.label}
                    />
                  </div>
                  <button
                    aria-label={`Remove ${document.file.name}`}
                    className="session-action session-action--quiet session-action--inline"
                    disabled={submitting}
                    onClick={() => setPendingDocuments((current) => current.filter((_, itemIndex) => itemIndex !== index))}
                    title="Remove document"
                    type="button"
                  >
                    <AppIcon name="delete" />
                  </button>
                </li>
              ))}
            </ul>
          ) : null}

          {documentContext?.canView ? (
            <MigrantDocumentsPanel
              canDelete={documentContext.canDelete}
              canDownload={documentContext.canDownload}
              canView
              embedded
              entryId={documentContext.entryId}
              onSessionExpired={documentContext.onSessionExpired}
            />
          ) : null}
        </fieldset>
      ) : documentsEnabled && documentContext?.canView ? (
        <fieldset className="registry-form__documents">
          <legend>Supporting documents</legend>
          <MigrantDocumentsPanel
            canDelete={documentContext.canDelete}
            canDownload={documentContext.canDownload}
            canView
            embedded
            entryId={documentContext.entryId}
            onSessionExpired={documentContext.onSessionExpired}
          />
        </fieldset>
      ) : null}

      <div className="registry-form__footer">
        <span>Record: {fullName || 'Incomplete name'}</span>
        <div className="registry-form__actions">
          {onCancel ? (
            <button className="session-action session-action--quiet" disabled={submitting} onClick={onCancel} type="button">
              Cancel
            </button>
          ) : null}
          <button className="session-action" disabled={submitting} type="submit">
            {submitting ? 'Submitting...' : submitLabel}
          </button>
        </div>
      </div>

      {message ? (
        <div className={`login-feedback login-feedback--${messageTone}`}>{message}</div>
      ) : null}
    </form>
  )
}
