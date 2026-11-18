/**
 * Cliente HTTP do dashboard. Único ponto de contato com o backend Laravel.
 *
 * - Autenticação por sessão (Sanctum SPA): cookie HttpOnly, sem tokens no navegador.
 * - CSRF: o Laravel grava o cookie XSRF-TOKEN; ele volta no cabeçalho X-XSRF-TOKEN.
 *   Se o token expirar (419), renova uma vez e repete a requisição.
 * - Nenhuma regra de autorização vive aqui: o backend decide tudo.
 */

export type ValidationErrors = Record<string, string[]>

export class ApiError extends Error {
  readonly status: number
  readonly errors: ValidationErrors
  /** Motivo de indisponibilidade da IA (unavailable, timeout, busy…), quando houver. */
  readonly reason?: string
  readonly retryAfter?: number

  constructor(status: number, message: string, errors: ValidationErrors = {}, reason?: string, retryAfter?: number) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
    this.reason = reason
    this.retryAfter = retryAfter
  }

  /** Primeira mensagem de validação de um campo. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0]
  }
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

interface RequestOptions {
  method?: Method
  body?: unknown
  signal?: AbortSignal
}

let unauthorizedHandler: (() => void) | undefined

/** Chamado quando a sessão expira (401), para o app voltar à tela de entrada. */
export function onUnauthorized(handler: () => void) {
  unauthorizedHandler = handler
}

function readXsrfToken(): string | undefined {
  const match = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))
  return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : undefined
}

export async function ensureCsrfCookie(): Promise<void> {
  await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
}

async function send(path: string, { method = 'GET', body, signal }: RequestOptions): Promise<Response> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  }
  const token = readXsrfToken()
  if (token) headers['X-XSRF-TOKEN'] = token
  if (body !== undefined) headers['Content-Type'] = 'application/json'

  return fetch(`/api${path}`, {
    method,
    headers,
    credentials: 'same-origin',
    body: body === undefined ? undefined : JSON.stringify(body),
    signal,
  })
}

async function toError(response: Response): Promise<ApiError> {
  let payload: { message?: string; errors?: ValidationErrors; reason?: string } = {}
  try {
    payload = await response.json()
  } catch {
    // corpo vazio ou não-JSON
  }
  const retryAfter = Number(response.headers.get('Retry-After')) || undefined
  return new ApiError(response.status, payload.message ?? defaultMessage(response.status), payload.errors, payload.reason, retryAfter)
}

function defaultMessage(status: number): string {
  if (status === 401) return 'Sua sessão terminou. Entre novamente.'
  if (status === 404) return 'Não encontrado.'
  if (status === 429) return 'Muitas tentativas. Aguarde um pouco.'
  if (status >= 500) return 'O servidor não conseguiu concluir a operação.'
  return 'Não foi possível concluir a operação.'
}

async function request(path: string, options: RequestOptions = {}): Promise<Response> {
  const mutating = (options.method ?? 'GET') !== 'GET'
  if (mutating && !readXsrfToken()) await ensureCsrfCookie()

  let response = await send(path, options)
  if (response.status === 419) {
    await ensureCsrfCookie()
    response = await send(path, options)
  }
  if (response.status === 401) unauthorizedHandler?.()
  if (!response.ok) throw await toError(response)
  return response
}

export async function api<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const response = await request(path, options)
  if (response.status === 204) return undefined as T
  const text = await response.text()
  return (text ? JSON.parse(text) : undefined) as T
}

export async function apiBlob(path: string): Promise<Blob> {
  const response = await request(path)
  return response.blob()
}
