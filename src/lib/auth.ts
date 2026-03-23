export type LoginCredentials = {
  email: string
  password: string
}

export type AuthenticatedUser = {
  id: string
  email: string
  name: string
  role: string
}

export type LoginResponse = {
  user: AuthenticatedUser
  session?: {
    expiresAt: string
  }
  token?: string
}

export type LoginErrorResponse = {
  errors?: Partial<Record<keyof LoginCredentials, string[]>>
  message: string
}

const delay = (ms: number) =>
  new Promise((resolve) => {
    window.setTimeout(resolve, ms)
  })

export async function login(
  credentials: LoginCredentials,
): Promise<LoginResponse> {
  await delay(650)

  void credentials

  // TODO: Replace this stub with a real POST /login request once the backend contract is ready.
  // Suggested contract:
  // - request: { email, password }
  // - success: { user, session? , token? }
  // - error: { message, errors? }
  throw new Error(
    'Login pendiente. Conecta src/lib/auth.ts al endpoint real del backend para habilitar acceso.',
  )
}
