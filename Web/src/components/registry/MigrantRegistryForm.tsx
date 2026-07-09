import { useEffect, useMemo, useState } from 'react'
import type { MigrantRegistrationPayload } from '../../lib/registry'

type Props = {
  initialPayload?: Partial<MigrantRegistrationPayload> | null
  onCancel?: () => void
  onSubmit: (payload: MigrantRegistrationPayload) => Promise<void>
  submitLabel?: string
}

type FormState = Omit<MigrantRegistrationPayload, 'fullName'>

const initialState: FormState = {
  attentionDate: new Date().toISOString().slice(0, 10),
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
  [
    state.firstName,
    state.firstLastName,
    state.secondLastName,
  ]
    .map((part) => part?.trim() ?? '')
    .filter(Boolean)
    .join(' ')

const formStateFromPayload = (
  payload?: Partial<MigrantRegistrationPayload> | null,
): FormState => ({
  ...initialState,
  attentionDate: payload?.attentionDate ?? initialState.attentionDate,
  birthDate: payload?.birthDate ?? '',
  civilStatus: payload?.civilStatus ?? '',
  countryOfOrigin: payload?.countryOfOrigin ?? '',
  departmentState: payload?.departmentState ?? '',
  firstLastName: payload?.firstLastName ?? '',
  firstName: payload?.firstName ?? '',
  gender: payload?.gender ?? '',
  notes: payload?.notes ?? '',
  phone: payload?.phone ?? '',
  populationGroup: payload?.populationGroup ?? '',
  secondLastName: payload?.secondLastName ?? '',
})

export function MigrantRegistryForm({
  initialPayload,
  onCancel,
  onSubmit,
  submitLabel = 'Submit registration',
}: Props) {
  const [form, setForm] = useState<FormState>(() => formStateFromPayload(initialPayload))
  const [submitting, setSubmitting] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const fullName = useMemo(() => buildFullName(form), [form])

  useEffect(() => {
    setForm(formStateFromPayload(initialPayload))
    setMessage(null)
  }, [initialPayload])

  const updateField = (field: keyof FormState, value: string) => {
    setForm((current) => ({
      ...current,
      [field]: value,
    }))
  }

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setSubmitting(true)
    setMessage(null)

    try {
      await onSubmit({
        ...form,
        fullName,
      })

      setForm(formStateFromPayload(initialPayload))
      setMessage('Registration submitted for coordinator/admin approval.')
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'Unable to submit the registration.')
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
            max={new Date().toISOString().slice(0, 10)}
            required
            type="date"
            value={form.attentionDate}
            onChange={(event) => updateField('attentionDate', event.target.value)}
          />
        </label>

        <label>
          First name
          <input
            required
            value={form.firstName}
            onChange={(event) => updateField('firstName', event.target.value)}
          />
        </label>

        <label>
          First last name
          <input
            required
            value={form.firstLastName}
            onChange={(event) => updateField('firstLastName', event.target.value)}
          />
        </label>

        <label>
          Second last name
          <input
            value={form.secondLastName}
            onChange={(event) => updateField('secondLastName', event.target.value)}
          />
        </label>

        <label>
          Phone
          <input
            inputMode="tel"
            value={form.phone}
            onChange={(event) => updateField('phone', event.target.value)}
          />
        </label>

        <label>
          Country of origin
          <input
            required
            value={form.countryOfOrigin}
            onChange={(event) => updateField('countryOfOrigin', event.target.value)}
          />
        </label>

        <label>
          Department / state
          <input
            value={form.departmentState}
            onChange={(event) => updateField('departmentState', event.target.value)}
          />
        </label>

        <label>
          Civil status
          <select
            value={form.civilStatus}
            onChange={(event) => updateField('civilStatus', event.target.value)}
          >
            <option value="">Select one</option>
            <option value="single">Single</option>
            <option value="married">Married</option>
            <option value="union">Union</option>
            <option value="divorced">Divorced</option>
            <option value="widowed">Widowed</option>
          </select>
        </label>

        <label>
          Birth date
          <input
            max={new Date().toISOString().slice(0, 10)}
            required
            type="date"
            value={form.birthDate}
            onChange={(event) => updateField('birthDate', event.target.value)}
          />
        </label>

        <label>
          Gender
          <select
            required
            value={form.gender}
            onChange={(event) => updateField('gender', event.target.value)}
          >
            <option value="">Select one</option>
            <option value="woman">Woman</option>
            <option value="man">Man</option>
            <option value="non_binary">Non-binary</option>
            <option value="prefer_not_to_say">Prefer not to say</option>
            <option value="other">Other</option>
          </select>
        </label>

        <label>
          Population group
          <select
            required
            value={form.populationGroup}
            onChange={(event) => updateField('populationGroup', event.target.value)}
          >
            <option value="">Select one</option>
            <option value="migrant">Migrant</option>
            <option value="refugee">Refugee</option>
            <option value="asylum_seeker">Asylum seeker</option>
            <option value="returnee">Returnee</option>
            <option value="displaced">Displaced person</option>
            <option value="other">Other</option>
          </select>
        </label>
      </div>

      <label>
        Notes
        <textarea
          value={form.notes}
          onChange={(event) => updateField('notes', event.target.value)}
        />
      </label>

      <div className="registry-form__footer">
        <span>Record: {fullName || 'Incomplete name'}</span>
        <div className="registry-form__actions">
          {onCancel ? (
            <button
              className="session-action session-action--quiet"
              disabled={submitting}
              onClick={onCancel}
              type="button"
            >
              Cancel
            </button>
          ) : null}
          <button className="session-action" disabled={submitting} type="submit">
            {submitting ? 'Submitting...' : submitLabel}
          </button>
        </div>
      </div>

      {message ? <p className="workspace-panel__copy">{message}</p> : null}
    </form>
  )
}
