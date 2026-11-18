import { QueryClient } from '@tanstack/react-query'

import { ApiError } from './client'

export const keys = {
  me: ['me'] as const,
  consents: ['consents'] as const,
  timezones: ['timezones'] as const,
  consentHistory: ['consents', 'history'] as const,
  status: ['nexo', 'status'] as const,
  conversations: (q = '') => ['conversations', q] as const,
  conversation: (id: string) => ['conversation', id] as const,
  memories: (status = '', q = '') => ['memories', status, q] as const,
  suggestions: ['suggestions'] as const,
  preferences: ['preferences'] as const,
  routine: ['routine'] as const,
  goals: ['goals'] as const,
}

export function createQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        refetchOnWindowFocus: false,
        // Erros de cliente (401, 403, 404, 422) não melhoram com nova tentativa.
        retry: (count, error) => !(error instanceof ApiError && error.status < 500) && count < 2,
      },
      mutations: { retry: false },
    },
  })
}

/** Mensagem legível de qualquer erro. */
export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    const first = Object.values(error.errors)[0]?.[0]
    return first ?? error.message
  }
  return 'Não foi possível falar com o servidor. Verifique se o backend está em execução.'
}
