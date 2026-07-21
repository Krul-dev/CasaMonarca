import { useEffect, useMemo, useState } from 'react'

import { getCurrentMigrantQuestionnaire } from '../../lib/registry'
import { answersFromPayload, canonicalAnswerText, reachableQuestions } from '../../lib/migrantQuestionnaire'
import type { MigrantQuestionnaireDefinition, MigrantRegistrationPayload } from '../../types/registry'

export function MigrantQuestionnaireViewer({ payload }: { payload: Partial<MigrantRegistrationPayload> & Record<string, unknown> }) {
  const [definition, setDefinition] = useState<MigrantQuestionnaireDefinition | null>(null)

  useEffect(() => {
    let active = true
    getCurrentMigrantQuestionnaire().then(({ data }) => { if (active) setDefinition(data) }).catch(() => undefined)
    return () => { active = false }
  }, [])

  const answers = useMemo(() => definition ? answersFromPayload(definition, payload) : {}, [definition, payload])
  const questions = useMemo(() => definition ? reachableQuestions(definition, answers) : [], [answers, definition])

  if (!definition || payload.schemaVersion !== 2) return null

  return (
    <div className="registry-questionnaire-viewer">
      {definition.sections.map((section) => {
        const answered = questions.filter((question) => question.sectionId === section.id && answers[question.id])
        if (answered.length === 0) return null
        return (
          <section key={section.id}>
            <h4>{section.title.es}</h4>
            <dl>{answered.map((question) => <div key={question.id}><dt>{question.title.es}</dt><dd>{canonicalAnswerText(question, answers[question.id])}</dd></div>)}</dl>
          </section>
        )
      })}
    </div>
  )
}
