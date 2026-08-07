import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  downloadDocumentRevisionSignaturePresentation,
  getDocumentRevisionVerificationPackageUrl,
} from './documents'
import { setAppLocale } from './i18n'

describe('document signature presentation downloads', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    setAppLocale('es')
  })

  it('requests the selected revision presentation in the current language', async () => {
    setAppLocale('en')
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response('pdf', {
        headers: {
          'Content-Disposition': 'attachment; filename="agreement-revision-2-signatures.pdf"',
          'Content-Type': 'application/pdf',
          'X-CasaMonarca-Presentation-Mode': 'merged',
        },
      }),
    )

    const result = await downloadDocumentRevisionSignaturePresentation(4, 9)

    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining('/documents/4/revisions/9/signed-pdf?locale=en'),
      expect.objectContaining({ credentials: 'include' }),
    )
    expect(result.fileName).toBe('agreement-revision-2-signatures.pdf')
    expect(result.mode).toBe('merged')
    expect(await result.blob.text()).toBe('pdf')
  })

  it('adds the current language to verification package links', () => {
    setAppLocale('es')

    expect(getDocumentRevisionVerificationPackageUrl(4, 9)).toContain(
      '/documents/4/revisions/9/verification-package?locale=es',
    )
  })
})
