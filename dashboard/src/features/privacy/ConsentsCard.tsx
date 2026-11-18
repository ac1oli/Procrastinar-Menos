import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { toast } from 'sonner'

import { consents as consentsApi } from '@/api/endpoints'
import { errorMessage, keys } from '@/api/queries'
import type { ConsentScope, DataAction } from '@/api/types'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { Card, CardContent, CardDescription, CardHeader, CardTitle, Separator } from '@/components/ui/display'
import { Checkbox, Label, RadioGroup, RadioGroupItem, Switch } from '@/components/ui/form-controls'
import { useConsents, useFormatDate } from '@/features/auth/session'
import { scopeInfo } from '@/lib/labels'

type Pending = { scope: ConsentScope; grant: boolean }

/**
 * Autorizações independentes e revogáveis (ADR-0008 §2.2, D7).
 * Revogar um escopo com dados abre a escolha "excluir" (pré-selecionada) ou
 * "manter inativo"; excluir só acontece com a confirmação marcada.
 */
export function ConsentsCard() {
  const formatDate = useFormatDate()
  const consents = useConsents()
  const queryClient = useQueryClient()
  const [pending, setPending] = useState<Pending | null>(null)
  const [dataAction, setDataAction] = useState<DataAction>('delete')
  const [confirmed, setConfirmed] = useState(false)

  const update = useMutation({
    mutationFn: ({ scope, grant }: Pending) =>
      grant
        ? consentsApi.grant(scope.scope, consents.data!.policy_version)
        : consentsApi.revoke(scope.scope, scope.holds_data && scope.granted ? dataAction : undefined),
    onSuccess: (state) => {
      queryClient.setQueryData(keys.consents, state)
      queryClient.invalidateQueries({ queryKey: keys.consentHistory })
      queryClient.invalidateQueries({ queryKey: ['memories'] })
      queryClient.invalidateQueries({ queryKey: ['conversations'] })
      queryClient.invalidateQueries({ queryKey: keys.suggestions })
      toast.success('Autorização atualizada.')
      setPending(null)
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  const ask = (scope: ConsentScope, grant: boolean) => {
    setDataAction('delete')
    setConfirmed(false)
    setPending({ scope, grant })
  }

  if (!consents.data) return null
  const scopes = consents.data.scopes
  const granted = new Set(scopes.filter((s) => s.granted).map((s) => s.scope))
  const target = pending?.scope
  const needsDataChoice = !!target && !pending?.grant && target.holds_data && target.granted === true
  const dependents = target ? scopes.filter((s) => s.requires.includes(target.scope) && s.granted) : []

  return (
    <Card>
      <CardHeader>
        <CardTitle>Autorizações</CardTitle>
        <CardDescription>
          Cada uma é independente e vale a partir da próxima mensagem. Política de memória: {consents.data.policy_version}.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-0">
        {scopes.map((scope, index) => {
          const info = scopeInfo[scope.scope]
          const missing = scope.requires.filter((r) => !granted.has(r))
          const id = `consent-${scope.scope.replaceAll('.', '-')}`
          return (
            <div key={scope.scope}>
              {index > 0 && <Separator className="my-4" />}
              <div className="flex items-start justify-between gap-4">
                <div className="grid gap-1">
                  <Label htmlFor={id} className="text-sm">
                    {info.title}
                  </Label>
                  <p className="text-sm text-muted-foreground">{info.description}</p>
                  <p className="text-xs text-muted-foreground">
                    {scope.granted === null ? 'Ainda não decidido' : `${scope.granted ? 'Permitido' : 'Não permitido'} desde ${formatDate(scope.decided_at)}`}
                    {!scope.granted && missing.length > 0 && scope.available && ` · requer: ${missing.map((m) => scopeInfo[m].title.toLowerCase()).join(' e ')}`}
                  </p>
                </div>
                <Switch
                  id={id}
                  checked={scope.granted === true}
                  disabled={!scope.available || (!scope.granted && missing.length > 0) || update.isPending}
                  onCheckedChange={(on) => ask(scope, on)}
                />
              </div>
            </div>
          )
        })}
      </CardContent>

      <ConfirmDialog
        open={!!pending}
        onOpenChange={(o) => !o && setPending(null)}
        title={pending?.grant ? `Permitir: ${target && scopeInfo[target.scope].title}` : `Revogar: ${target && scopeInfo[target.scope].title}`}
        destructive={!pending?.grant && needsDataChoice && dataAction === 'delete'}
        confirmLabel={pending?.grant ? 'Permitir' : 'Revogar'}
        pending={update.isPending}
        confirmDisabled={needsDataChoice && dataAction === 'delete' && !confirmed}
        onConfirm={() => pending && update.mutate(pending)}
        description={
          pending?.grant ? (
            <>{target && scopeInfo[target.scope].description} Você pode revogar quando quiser.</>
          ) : (
            <>
              O NEXO para de usar isto imediatamente.
              {dependents.length > 0 && <> Também serão revogados: {dependents.map((d) => scopeInfo[d.scope].title.toLowerCase()).join(', ')}.</>}
            </>
          )
        }
      >
        {needsDataChoice && target && (
          <div className="grid gap-3">
            <p className="text-sm font-medium">O que fazer com as {scopeInfo[target.scope].holdsDataNote} já guardadas?</p>
            <RadioGroup value={dataAction} onValueChange={(v) => setDataAction(v as DataAction)} aria-label="Destino dos dados guardados">
              <div className="flex items-start gap-2">
                <RadioGroupItem value="delete" id="data-delete" />
                <Label htmlFor="data-delete" className="leading-snug font-normal">
                  Excluir agora (recomendado)
                </Label>
              </div>
              <div className="flex items-start gap-2">
                <RadioGroupItem value="keep" id="data-keep" />
                <Label htmlFor="data-keep" className="leading-snug font-normal">
                  Manter inativas: nada é usado nem gravado; continuam visíveis para você e os prazos de retenção seguem valendo
                </Label>
              </div>
            </RadioGroup>
            {dataAction === 'delete' && (
              <div className="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 p-3">
                <Checkbox id="confirm-delete" checked={confirmed} onCheckedChange={(v) => setConfirmed(v === true)} />
                <Label htmlFor="confirm-delete" className="leading-snug font-normal">
                  Entendo que a exclusão é definitiva (cópias em backup somem na rotação, em até 35 dias).
                </Label>
              </div>
            )}
          </div>
        )}
      </ConfirmDialog>
    </Card>
  )
}
