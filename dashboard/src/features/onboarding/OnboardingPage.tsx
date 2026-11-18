import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2Icon, LockIcon } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Navigate, useNavigate } from 'react-router'

import { consents as consentsApi } from '@/api/endpoints'
import { errorMessage, keys } from '@/api/queries'
import type { ConsentScope, ConsentScopeId } from '@/api/types'
import { NexoMark } from '@/components/layout/NexoMark'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/display'
import { Label, RadioGroup, RadioGroupItem } from '@/components/ui/form-controls'
import { FullScreenStatus } from '@/features/auth/guards'
import { useConsents, useMe } from '@/features/auth/session'
import { scopeInfo } from '@/lib/labels'

type Choice = 'yes' | 'no'

/**
 * Primeiro acesso: cada autorização pendente precisa de uma decisão explícita,
 * sem nenhuma opção pré-marcada (ADR-0008, D1). O backend valida dependências
 * e a versão da política; aqui só se evita oferecer o que não pode ser concedido.
 */
export function OnboardingPage() {
  const { data: me } = useMe()
  const consents = useConsents()
  const [choices, setChoices] = useState<Partial<Record<ConsentScopeId, Choice>>>({})
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  const pending = useMemo(
    () => (consents.data?.scopes ?? []).filter((s) => s.available && consents.data?.pending.includes(s.scope)),
    [consents.data],
  )
  const granted = useMemo(() => new Set((consents.data?.scopes ?? []).filter((s) => s.granted).map((s) => s.scope)), [consents.data])

  const satisfied = (scope: ConsentScope) => scope.requires.every((r) => granted.has(r) || choices[r] === 'yes')

  const choose = (scope: ConsentScopeId, choice: Choice) => {
    setChoices((current) => {
      const next = { ...current, [scope]: choice }
      // Recusar um requisito desfaz (sem marcar nada) a escolha "sim" de quem depende dele.
      if (choice === 'no') {
        for (const s of pending) if (s.requires.includes(scope) && next[s.scope] === 'yes') delete next[s.scope]
      }
      return next
    })
  }

  const save = useMutation({
    mutationFn: async () => {
      const version = consents.data!.policy_version
      // Requisitos antes dos dependentes.
      const order = [...pending].sort((a, b) => a.requires.length - b.requires.length)
      for (const scope of order) {
        if (choices[scope.scope] === 'yes') await consentsApi.grant(scope.scope, version)
      }
      for (const scope of order) {
        if (choices[scope.scope] === 'no') await consentsApi.revoke(scope.scope)
      }
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: keys.consents }),
    onSuccess: () => navigate('/', { replace: true }),
  })

  if (consents.isPending) return <FullScreenStatus>Carregando…</FullScreenStatus>
  if (consents.isError) return <FullScreenStatus>{errorMessage(consents.error)}</FullScreenStatus>
  if (pending.length === 0 && !save.isPending) return <Navigate to="/" replace />

  const decided = pending.filter((s) => choices[s.scope]).length
  const complete = decided === pending.length

  return (
    <div className="mx-auto grid min-h-dvh max-w-3xl content-start gap-6 px-4 py-10">
      <div className="flex items-start gap-4">
        <NexoMark className="size-11" />
        <div className="grid gap-2">
          <h1 className="text-2xl font-semibold tracking-tight">Olá{me ? `, ${me.name.split(' ')[0]}` : ''}! Antes de começar, decida o que o NEXO pode fazer.</h1>
          <p className="text-sm text-muted-foreground">
            Cada item é independente e começa sem resposta. Você pode mudar tudo depois em <strong>Privacidade</strong>. Rotina, objetivos e a conversa com
            o NEXO funcionam mesmo que você recuse tudo.
          </p>
        </div>
      </div>

      <Card className="bg-accent/40">
        <CardContent className="grid gap-2 text-sm">
          <p className="flex items-center gap-2 font-medium">
            <LockIcon className="size-4" /> Resumo da política de memória ({consents.data.policy_version})
          </p>
          <ul className="ml-5 list-disc space-y-1 text-muted-foreground">
            <li>Tudo roda no seu servidor. Os dados não treinam modelos e não vão para terceiros.</li>
            <li>Memórias só existem quando você cria ou confirma. Informação inferida nunca é guardada sozinha.</li>
            <li>Documentos, cartões, contatos e senhas são mascarados antes de gravar. A detecção ajuda, mas não é infalível.</li>
            <li>Você pode consultar, editar, exportar e excluir tudo. Conversas privadas nunca são guardadas.</li>
          </ul>
        </CardContent>
      </Card>

      <div className="grid gap-4">
        {pending.map((scope) => {
          const info = scopeInfo[scope.scope]
          const canGrant = satisfied(scope)
          const id = scope.scope.replaceAll('.', '-')
          return (
            <Card key={scope.scope} className={choices[scope.scope] ? 'border-primary/30' : undefined}>
              <CardHeader>
                <CardTitle id={`${id}-title`} className="flex items-center gap-2">
                  {choices[scope.scope] && <CheckCircle2Icon className="size-4 text-primary" aria-hidden />}
                  {info.title}
                </CardTitle>
                <CardDescription>{info.description}</CardDescription>
              </CardHeader>
              <CardContent>
                <RadioGroup
                  aria-labelledby={`${id}-title`}
                  value={choices[scope.scope] ?? ''}
                  onValueChange={(v) => choose(scope.scope, v as Choice)}
                  className="flex flex-wrap gap-6"
                >
                  <div className="flex items-center gap-2">
                    <RadioGroupItem value="yes" id={`${id}-yes`} disabled={!canGrant} />
                    <Label htmlFor={`${id}-yes`}>Permitir</Label>
                  </div>
                  <div className="flex items-center gap-2">
                    <RadioGroupItem value="no" id={`${id}-no`} />
                    <Label htmlFor={`${id}-no`}>Não permitir</Label>
                  </div>
                </RadioGroup>
                {!canGrant && (
                  <p className="mt-3 text-xs text-muted-foreground">
                    Para permitir, antes permita: {scope.requires.map((r) => scopeInfo[r].title.toLowerCase()).join(' e ')}.
                  </p>
                )}
              </CardContent>
            </Card>
          )
        })}
      </div>

      {save.isError && (
        <p role="alert" className="text-sm text-destructive">
          {errorMessage(save.error)}
        </p>
      )}

      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-muted-foreground" aria-live="polite">
          {decided} de {pending.length} decididas
        </p>
        <Button variant="nexo" size="lg" disabled={!complete || save.isPending} onClick={() => save.mutate()}>
          {save.isPending ? 'Salvando…' : 'Salvar e continuar'}
        </Button>
      </div>
    </div>
  )
}
