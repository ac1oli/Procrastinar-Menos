import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router'

import { errorMessage } from '@/api/queries'
import { NexoMark } from '@/components/layout/NexoMark'
import { Button } from '@/components/ui/button'

import { useConsents, useMe } from './session'

export function FullScreenStatus({ children }: { children: ReactNode }) {
  return (
    <div className="flex min-h-dvh flex-col items-center justify-center gap-4 p-6 text-center">
      <NexoMark className="size-10 animate-pulse" />
      <div className="max-w-sm text-sm text-muted-foreground">{children}</div>
    </div>
  )
}

function LoadError({ error, retry }: { error: unknown; retry: () => void }) {
  return (
    <FullScreenStatus>
      <p role="alert">{errorMessage(error)}</p>
      <Button className="mt-4" variant="outline" onClick={retry}>
        Tentar de novo
      </Button>
    </FullScreenStatus>
  )
}

/** Exige sessão. A autorização real é sempre do backend; isto só decide a navegação. */
export function RequireAuth({ children }: { children: ReactNode }) {
  const me = useMe()
  const location = useLocation()

  if (me.isPending) return <FullScreenStatus>Carregando…</FullScreenStatus>
  if (me.isError) return <LoadError error={me.error} retry={() => me.refetch()} />
  if (!me.data) return <Navigate to="/entrar" replace state={{ from: location.pathname }} />
  return children
}

/** No primeiro acesso, as autorizações são decididas antes de usar o app (D1). */
export function RequireOnboarding({ children }: { children: ReactNode }) {
  const consents = useConsents()

  if (consents.isPending) return <FullScreenStatus>Carregando suas preferências…</FullScreenStatus>
  if (consents.isError) return <LoadError error={consents.error} retry={() => consents.refetch()} />
  if (consents.data.pending.length > 0) return <Navigate to="/boas-vindas" replace />
  return children
}

export function PublicOnly({ children }: { children: ReactNode }) {
  const me = useMe()
  if (me.isPending) return <FullScreenStatus>Carregando…</FullScreenStatus>
  if (me.data) return <Navigate to="/" replace />
  return children
}
