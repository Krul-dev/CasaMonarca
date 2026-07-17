import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import type { MigrantRegistrationPayload } from '../../lib/registry'
import { MigrantRegistryForm } from './MigrantRegistryForm'

const validPayload: MigrantRegistrationPayload = {
  attentionDate: '2026-07-14',
  birthDate: '1996-03-31',
  civilStatus: 'single',
  countryOfOrigin: 'Honduras',
  departmentState: 'Cortes',
  firstLastName: 'Doe',
  firstName: 'John',
  fullName: 'John Doe',
  gender: 'male',
  notes: '',
  phone: '+52 81 3100 8716',
  populationGroup: 'adult',
  secondLastName: '',
}

describe('MigrantRegistryForm', () => {
  it('submits staged supporting documents with the registration payload', async () => {
    const user = userEvent.setup()
    const onSubmit = vi.fn().mockResolvedValue(undefined)
    const document = new File(['document'], 'identification.pdf', {
      type: 'application/pdf',
    })

    render(
      <MigrantRegistryForm
        initialPayload={validPayload}
        onSubmit={onSubmit}
      />,
    )

    await user.upload(screen.getByLabelText('Supporting documents'), document)
    await user.click(screen.getByRole('button', { name: 'Submit registration' }))

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1))
    expect(onSubmit).toHaveBeenCalledWith(
      validPayload,
      [{ file: document, label: '' }],
    )
  })
})
