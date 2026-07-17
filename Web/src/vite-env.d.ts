/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_APP_NAME?: string
  readonly VITE_APP_CHANNEL?: string
  readonly VITE_API_BASE_URL?: string
  readonly VITE_MIGRANT_DOCUMENTS_ENABLED?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
