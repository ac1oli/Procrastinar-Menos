import { useQuery } from '@tanstack/react-query'
import { useCallback } from 'react'

import { ApiError } from '@/api/client'
import { auth, consents } from '@/api/endpoints'
import { keys } from '@/api/queries'
import type { User } from '@/api/types'
import { formatDate } from '@/lib/labels'

/** Pessoa autenticada; null quando não há sessão. */
export function useMe() {
  return useQuery<User | null>({
    queryKey: keys.me,
    queryFn: async () => {
      try {
        return await auth.me()
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) return null
        throw error
      }
    },
    staleTime: 5 * 60_000,
  })
}

/** Estado das autorizações; `enabled` evita pedir antes de haver sessão. */
export function useConsents(enabled = true) {
  return useQuery({ queryKey: keys.consents, queryFn: consents.get, enabled })
}

/** Formata datas no fuso da conta (o mesmo do NEXO), não no do navegador. */
export function useFormatDate() {
  const { data } = useMe()
  const timezone = data?.timezone
  return useCallback((iso: string | null | undefined, withTime = false) => formatDate(iso, withTime, timezone), [timezone])
}
