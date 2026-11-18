import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { DownloadIcon } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'

import { ApiError } from '@/api/client'
import { auth, consents, exports as exportsApi, preferences } from '@/api/endpoints'
import { errorMessage, keys } from '@/api/queries'
import type { DataExport } from '@/api/types'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { PageHeader } from '@/components/layout/AppShell'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/display'
import { Field, Input, NativeSelect } from '@/components/ui/form-controls'
import { formatDate, retentionLabel, scopeInfo } from '@/lib/labels'

import { ConsentsCard } from './ConsentsCard'

export function PrivacyPage() {
  return (
    <>
      <PageHeader
        title="Privacidade e dados"
        description="Você controla o que o NEXO guarda e usa, por quanto tempo, e pode exportar ou excluir tudo. Tudo roda no seu servidor."
      />
      <div className="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div className="grid content-start gap-6">
          <ConsentsCard />
          <ConsentHistoryCard />
        </div>
        <div className="grid content-start gap-6">
          <RetentionCard />
          <ExportCard />
          <DeleteAccountCard />
        </div>
      </div>
    </>
  )
}

function RetentionCard() {
  const queryClient = useQueryClient()
  const prefs = useQuery({ queryKey: keys.preferences, queryFn: preferences.get })
  const [pending, setPending] = useState<{ days: number | null; affected: number } | null>(null)

  const apply = useMutation({
    mutationFn: (days: number | null) => preferences.setRetention(days),
    onSuccess: (data) => {
      queryClient.setQueryData(keys.preferences, data)
      queryClient.invalidateQueries({ queryKey: ['conversations'] })
      toast.success(`Retenção do histórico: ${retentionLabel(data.history_retention_days)}.`)
      setPending(null)
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  // Antes de encurtar o prazo, mostra quantas conversas serão apagadas.
  const choose = async (value: string) => {
    const days = value === 'null' ? null : Number(value)
    if (days === null) return apply.mutate(null)
    try {
      const preview = await preferences.previewRetention(days)
      if (preview.conversations_to_delete > 0) setPending({ days, affected: preview.conversations_to_delete })
      else apply.mutate(days)
    } catch (e) {
      toast.error(errorMessage(e))
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Retenção do histórico</CardTitle>
        <CardDescription>Conversas mais antigas que o prazo (contado da última mensagem) são apagadas automaticamente.</CardDescription>
      </CardHeader>
      <CardContent>
        {prefs.data && (
          <Field label="Guardar conversas por" htmlFor="retention">
            <NativeSelect id="retention" value={String(prefs.data.history_retention_days)} onChange={(e) => choose(e.target.value)} disabled={apply.isPending}>
              {prefs.data.history_retention_options.map((d) => (
                <option key={String(d)} value={String(d)}>
                  {retentionLabel(d)}
                </option>
              ))}
            </NativeSelect>
          </Field>
        )}
        <p className="mt-3 text-xs text-muted-foreground">
          Memórias não expiram: depois de 180 dias sem uso, o NEXO pede que você as revise.
        </p>
      </CardContent>
      <ConfirmDialog
        open={!!pending}
        onOpenChange={(o) => !o && setPending(null)}
        title={`Mudar para ${pending ? retentionLabel(pending.days) : ''}?`}
        description={`${pending?.affected} conversa(s) já passaram desse prazo e serão apagadas na próxima rotina diária de retenção.`}
        confirmLabel="Mudar prazo"
        pending={apply.isPending}
        onConfirm={() => pending && apply.mutate(pending.days)}
      />
    </Card>
  )
}

function ConsentHistoryCard() {
  const history = useQuery({ queryKey: keys.consentHistory, queryFn: consents.history })
  const actionLabel = { granted: 'Permitido', revoked: 'Revogado', declined: 'Não permitido' } as const

  return (
    <Card>
      <CardHeader>
        <CardTitle>Histórico de decisões</CardTitle>
        <CardDescription>Registro de quando você permitiu ou revogou cada uso, e sob qual versão da política.</CardDescription>
      </CardHeader>
      <CardContent>
        {history.data?.length ? (
          <ul className="grid max-h-72 gap-2 overflow-y-auto pr-1 text-sm">
            {history.data.map((e, i) => (
              <li key={i} className="flex flex-wrap justify-between gap-2 border-b pb-2 last:border-0">
                <span>
                  <strong className="font-medium">{actionLabel[e.action]}</strong> · {scopeInfo[e.scope]?.title ?? e.scope}
                  {e.data_action && <span className="text-muted-foreground"> ({e.data_action === 'delete' ? 'dados excluídos' : 'dados mantidos inativos'})</span>}
                </span>
                <span className="text-xs text-muted-foreground">{formatDate(e.created_at, true)}</span>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-sm text-muted-foreground">Nenhuma decisão registrada.</p>
        )}
      </CardContent>
    </Card>
  )
}

function ExportCard() {
  const [password, setPassword] = useState('')
  const [current, setCurrent] = useState<DataExport | null>(null)

  const request = useMutation({
    mutationFn: () => exportsApi.request(password),
    onSuccess: (e) => {
      setPassword('')
      setCurrent(e)
    },
  })
  const status = useQuery({
    queryKey: ['export', current?.id],
    queryFn: () => exportsApi.get(current!.id),
    enabled: !!current && current.status === 'pending',
    refetchInterval: (q) => (q.state.data?.status === 'pending' ? 1500 : false),
  })
  // Depois do download, o estado local ("baixado") vale mais que a última consulta ("pronto").
  const effective = current?.status === 'downloaded' ? current : (status.data ?? current)

  const download = useMutation({
    mutationFn: () => exportsApi.download(effective!.id),
    onSuccess: (blob) => {
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = 'procrastinar-menos-dados-nexo.zip'
      a.click()
      URL.revokeObjectURL(url)
      setCurrent({ ...effective!, status: 'downloaded' })
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const error = request.error instanceof ApiError ? request.error : undefined

  return (
    <Card>
      <CardHeader>
        <CardTitle>Exportar meus dados</CardTitle>
        <CardDescription>
          Pacote ZIP com conversas, memórias, sugestões e autorizações (JSON e versão legível). Uma vez a cada 24 h; o link vale para um único download.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-3">
        {effective?.status === 'ready' ? (
          <Button onClick={() => download.mutate()} disabled={download.isPending}>
            <DownloadIcon /> Baixar pacote (expira em {formatDate(effective.expires_at, true)})
          </Button>
        ) : effective?.status === 'pending' ? (
          <p className="text-sm text-muted-foreground" role="status">
            Preparando o pacote…
          </p>
        ) : effective?.status === 'downloaded' ? (
          <p className="text-sm text-muted-foreground" role="status">
            Download concluído. O arquivo foi apagado do servidor.
          </p>
        ) : effective?.status === 'failed' ? (
          <p className="text-sm text-destructive" role="alert">
            Não foi possível gerar o pacote. Tente novamente amanhã ou fale com o administrador.
          </p>
        ) : (
          <form
            className="grid gap-3"
            onSubmit={(e) => {
              e.preventDefault()
              request.mutate()
            }}
          >
            <Field label="Confirme sua senha" htmlFor="export-password" error={error?.field('password')}>
              <Input id="export-password" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
            </Field>
            {request.isError && !error?.field('password') && (
              <p role="alert" className="text-sm text-destructive">
                {errorMessage(request.error)}
              </p>
            )}
            <Button type="submit" variant="secondary" disabled={!password || request.isPending}>
              Pedir exportação
            </Button>
          </form>
        )}
      </CardContent>
    </Card>
  )
}

function DeleteAccountCard() {
  const [open, setOpen] = useState(false)
  const [password, setPassword] = useState('')
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  const remove = useMutation({
    mutationFn: () => auth.deleteAccount(password),
    onSuccess: () => {
      queryClient.clear()
      queryClient.setQueryData(keys.me, null)
      navigate('/entrar', { replace: true })
      toast.success('Conta excluída.')
    },
  })
  const error = remove.error instanceof ApiError ? remove.error : undefined

  return (
    <Card className="border-destructive/30">
      <CardHeader>
        <CardTitle>Excluir conta</CardTitle>
        <CardDescription>Apaga perfil, rotina, objetivos, dispositivos, conversas, memórias e autorizações. Irreversível.</CardDescription>
      </CardHeader>
      <CardContent>
        <Button variant="destructive" onClick={() => setOpen(true)}>
          Excluir minha conta
        </Button>
      </CardContent>
      <ConfirmDialog
        open={open}
        onOpenChange={(o) => {
          setOpen(o)
          if (!o) setPassword('')
        }}
        title="Excluir a conta definitivamente?"
        description="Todos os seus dados serão excluídos. Ficam apenas registros de segurança anônimos, sem nada que identifique você."
        confirmLabel="Excluir conta"
        pending={remove.isPending}
        confirmDisabled={!password}
        onConfirm={() => remove.mutate()}
      >
        <Field label="Confirme sua senha" htmlFor="delete-password" error={error?.field('password') ?? (remove.isError && !error?.field('password') ? errorMessage(remove.error) : undefined)}>
          <Input id="delete-password" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
        </Field>
      </ConfirmDialog>
    </Card>
  )
}
