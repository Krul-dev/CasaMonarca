import type {
  MigrantQuestionnaireAnswer,
  MigrantQuestionnaireDefinition,
  MigrantRegistrationPayload,
  QuestionnaireQuestion,
} from '../types/registry'

export const emptyQuestionnairePayload = (definition: MigrantQuestionnaireDefinition): MigrantRegistrationPayload => ({
  schemaVersion: 2,
  questionnaire: { definitionId: definition.id, answers: {} },
})

export const answersFromPayload = (
  definition: MigrantQuestionnaireDefinition,
  payload?: Partial<MigrantRegistrationPayload> | null,
) => {
  if (payload?.schemaVersion === 2 && payload.questionnaire?.definitionId === definition.id) {
    return payload.questionnaire.answers
  }

  return upgradeLegacyAnswers(definition, payload ?? {})
}

export const buildQuestionnairePayload = (
  definition: MigrantQuestionnaireDefinition,
  answers: Record<string, MigrantQuestionnaireAnswer>,
): MigrantRegistrationPayload => ({
  schemaVersion: 2,
  questionnaire: { definitionId: definition.id, answers },
})

export function reachableQuestions(
  definition: MigrantQuestionnaireDefinition,
  answers: Record<string, MigrantQuestionnaireAnswer>,
) {
  const byId = new Map(definition.questions.map((question) => [question.id, question]))
  const reachable: QuestionnaireQuestion[] = []
  const visited = new Set<string>()
  let current: QuestionnaireQuestion | undefined = definition.questions[0]

  while (current && !visited.has(current.id)) {
    visited.add(current.id)
    reachable.push(current)
    const answer = answers[current.id]
    let destination = current.defaultNext

    if (current.type === 'choice' && !current.multipleSelection && typeof answer?.value === 'string') {
      destination = current.choices.find((choice) => choice.value === answer.value)?.next ?? destination
    }

    if (current.type === 'choice' && !answerHasValue(answer)) {
      const destinations = new Set(current.choices.map((choice) => JSON.stringify(choice.next)))
      if (destinations.size > 1) break
    }

    if (destination.kind === 'end') break
    current = byId.get(destination.questionId)
  }

  return reachable
}

export const pruneUnreachableAnswers = (
  definition: MigrantQuestionnaireDefinition,
  answers: Record<string, MigrantQuestionnaireAnswer>,
) => {
  const reachableIds = new Set(reachableQuestions(definition, answers).map((question) => question.id))
  return Object.fromEntries(Object.entries(answers).filter(([questionId]) => reachableIds.has(questionId)))
}

export function answerHasValue(answer?: MigrantQuestionnaireAnswer) {
  if (!answer) return false
  return Array.isArray(answer.value) ? answer.value.length > 0 : answer.value.trim() !== ''
}

export function validateQuestionAnswer(
  question: QuestionnaireQuestion,
  answer?: MigrantQuestionnaireAnswer,
) {
  if (question.required && !answerHasValue(answer)) return 'Esta respuesta es obligatoria.'
  if (!answerHasValue(answer)) return null
  if (question.numeric && !Number.isFinite(Number(answer?.value))) return 'Ingrese un número válido.'

  const selected = Array.isArray(answer?.value) ? answer.value : [answer?.value]
  const usesOther = question.choices.some((choice) => choice.custom && selected.includes(choice.value))
  if (usesOther && !answer?.otherText?.trim()) return 'Especifique la respuesta en español.'

  return null
}

export const canonicalAnswerText = (
  _question: QuestionnaireQuestion,
  answer?: MigrantQuestionnaireAnswer,
) => {
  if (!answerHasValue(answer)) return 'Sin respuesta'
  const values = Array.isArray(answer?.value) ? answer.value : [answer?.value]
  return values.map((value) => value === 'Otro' && answer?.otherText ? `Otro: ${answer.otherText}` : value).join(', ')
}

function upgradeLegacyAnswers(
  definition: MigrantQuestionnaireDefinition,
  payload: Partial<MigrantRegistrationPayload>,
) {
  const answers: Record<string, MigrantQuestionnaireAnswer> = {}
  const values: Record<string, string | undefined> = {
    attentionDate: payload.attentionDate,
    firstName: payload.firstName,
    firstLastName: payload.firstLastName,
    secondLastName: payload.secondLastName,
    phone: payload.phone,
    gender: ({ female: 'Femenino', woman: 'Femenino', male: 'Masculino', man: 'Masculino', non_binary: 'No binario', lgbtq_plus: 'LGBTIQ+' } as Record<string, string>)[payload.gender ?? ''],
    countryOfOrigin: payload.countryOfOrigin === 'Venezuela'
      ? 'Venezuela (República Bolivariana de)'
      : payload.countryOfOrigin,
    departmentState: payload.departmentState,
    civilStatus: ({ single: 'Soltera / Soltero', married: 'Casado / Casado', common_law_union: 'Unión Libre', union: 'Unión Libre', separated: 'Separada / Separado', divorced: 'Divorciada / Divorciado', widowed: 'Viuda / Viudo', other: 'Otro' } as Record<string, string>)[payload.civilStatus ?? ''],
    birthDate: payload.birthDate,
    populationGroup: ({ adult: 'Adulto (18-59 años)', older_adult: 'Adulto mayor (+60 años)', accompanied_girl: 'Niña acompañada', accompanied_boy: 'Niño acompañado', accompanied_adolescent_boy: 'Adolescente hombre acompañado', accompanied_adolescent_girl: 'Adolescente mujer acompañada', unaccompanied_minor: 'NNA No acompañado' } as Record<string, string>)[payload.populationGroup ?? ''],
    notes: payload.notes,
  }

  Object.entries(values).forEach(([field, value]) => {
    const questionId = definition.summaryMappings[field]
    if (questionId && value?.trim()) answers[questionId] = { value: value.trim() }
  })

  if (payload.birthDate && payload.attentionDate) {
    const birth = new Date(`${payload.birthDate}T00:00:00`)
    const attention = new Date(`${payload.attentionDate}T00:00:00`)
    if (!Number.isNaN(birth.getTime()) && !Number.isNaN(attention.getTime()) && birth <= attention) {
      let years = attention.getFullYear() - birth.getFullYear()
      if (attention.getMonth() < birth.getMonth() || (attention.getMonth() === birth.getMonth() && attention.getDate() < birth.getDate())) years -= 1
      answers[definition.summaryMappings.age] = { value: years === 0 ? '0 - 11 meses' : String(Math.min(90, years)) }
    }
  }

  return answers
}
