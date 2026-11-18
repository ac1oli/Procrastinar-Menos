import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { DownloadIcon, PencilIcon } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { toast } from 'sonner'

import { ApiError } from '@/api/client'
import { auth, consents, exports as exportsApi, preferences } from '@/api/endpoints'
import { errorMessage, keys } from '@/api/queries'
import type { DataExport } from '@/api/types'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { PageHeader } from '@/components/layout/AppShell'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle, Skeleton } from '@/components/ui/display'
import { Field, Input, NativeSelect } from '@/components/ui/form-controls'
import { useFormatDate, useMe } from '@/features/auth/session'
import { ClearHistoryAction, ClearMemoriesAction, ImpactList } from '@/features/data/BulkActions'
import { accountImpact, useDataSummary } from '@/features/data/impact'
import { formatOffset, retentionLabel, scopeInfo } from '@/lib/labels'

import { ConsentsCard } from './ConsentsCard'

/**
 * Central "Meus dados" (FASE 4): o que a conta guarda, quem pode usar, por quanto tempo, e como
 * exportar ou excluir. Reúne os cartões que já existiam (autorizações, retenção, exportação,
 * decisões) com o inventário e as exclusões com prévia. Gerenciar cada item continua nas telas
 * próprias (Histórico, Memórias, Rotina, Objetivos, Perfil), sem duplicar funções.
 */
export function MyDataPage() {
  const sections = [
    ['conta', 'Conta'],
    ['inventario', 'O que está guardado'],
    ['autorizacoes', 'Autorizações'],
    ['retencao', 'Retenção'],
    ['exportar', 'Exportar'],
    ['excluir', 'Excluir'],
    ['decisoes', 'Decisões'],
  ] as const
  return (
    <>
      <PageHeader
        title="Meus dados"
        description="Tudo o que a sua conta guarda, quem pode usar, por quanto tempo, e como exportar ou excluir. Tudo roda no seu servidor."
      />
      <nav aria-label="Seções de Meus dados" className="-mt-2 mb-6 flex flex-wrap gap-2">
        {sections.map(([id, label]) => (
          <a key={id} href={`#${id}`} className="rounded-full border px-3 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground">
            {label}
          </a>
        ))}
      </nav>
      <div className="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div className="grid content-start gap-6">
          <section id="conta" className="scroll-mt-20">
            <AccountCard />
          </section>
          <section id="inventario" className="scroll-mt-20">
            <InventoryCard />
          </section>
          <section id="autorizacoes" className="scroll-mt-20">
            <ConsentsCard />
          </section>
          <section id="decisoes" className="scroll-mt-20">
            <ConsentHistoryCard />
          </section>
        </div>
        <div className="grid content-start gap-6">
          <section id="retencao" className="scroll-mt-20">
            <RetentionCard />
          </section>
          <section id="exportar" className="scroll-mt-20">
            <ExportCard />
          </section>
          <section id="excluir" className="scroll-mt-20">
            <DeletionCard />
          </section>
        </div>
      </div>
    </>
  )
}

function AccountCard() {
  const { data: me } = useMe()
  const formatDate = useFormatDate()
  if (!me) return null
  return (
    <Card>
      <CardHeader>
        <CardTitle>Conta</CardTitle>
        <CardDescription>Só o necessário para entrar e para o NEXO saber o seu horário. Nenhum outro dado pessoal é pedido.</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
          <div>
            <dt className="text-muted-foreground">Nome de exibição</dt>
            <dd className="font-medium">{me.name}</dd>
          </div>
          <div>
            <dt className="text-muted-foreground">E-mail de acesso</dt>
            <dd className="font-medium break-all">{me.email}</dd>
          </div>
          <div>
            <dt className="text-muted-foreground">Fuso horário</dt>
            <dd className="font-medium">
              {me.timezone} ({formatOffset(me.timezone_details.offset)})
            </dd>
          </div>
          <div>
            <dt className="text-muted-foreground">Conta criada em</dt>
            <dd className="font-medium">{formatDate(me.created_at)}</dd>
          </div>
        </dl>
        <Button asChild variant="outline" className="justify-self-start">
          <Link to="/perfil">
            <PencilIcon /> Editar perfil, e-mail ou senha
          </Link>
        </Button>
      </CardContent>
    </Card>
  )
}

function InventoryCard() {
  const summary = useDataSummary()
  const formatDate = useFormatDate()
  const s = summary.data

  return (
    <Card>
      <CardHeader>
        <CardTitle>O que está guardado</CardTitle>
        <CardDescription>Contagens atuais. Cada item é gerenciado na própria tela.</CardDescription>
      </CardHeader>
      <CardContent>
        {summary.isPending ? (
          <Skeleton className="h-40" />
        ) : summary.isError || !s ? (
          <p role="alert" className="text-sm text-destructive">
            {errorMessage(summary.error)}
          </p>
        ) : (
          <ul className="grid gap-3 sm:grid-cols-2" aria-label="Inventário dos dados">
            <InventoryItem
              title="Conversas guardadas"
              value={`${s.conversations.count} conversa(s), ${s.conversations.messages} mensagem(ns)`}
              detail={`Prazo: ${retentionLabel(s.conversations.retention_days)}${s.conversations.oldest_at ? `; a mais antiga é de ${formatDate(s.conversations.oldest_at)}` : ''}.`}
              to="/historico"
              action="Abrir histórico"
            />
            <InventoryItem
              title="Memórias"
              value={`${s.memories.count} memória(s)`}
              detail={`${s.memories.active} ativa(s) · ${s.memories.needs_review} para revisar · ${s.memories.archived} arquivada(s) · ${s.memories.pinned} fixada(s) · ${s.memories.sensitive} sensível(is)`}
              to="/memorias"
              action="Gerenciar memórias"
            />
            <InventoryItem
              title="Sugestões pendentes"
              value={`${s.suggestions.pending} sugestão(ões)`}
              detail="Só viram memória com a sua confirmação; expiram em 14 dias."
              to="/memorias?aba=sugestoes"
              action="Revisar sugestões"
            />
            <InventoryItem
              title="Rotina e objetivos"
              value={`${s.planning.routine_blocks} bloco(s) · ${s.planning.goals} objetivo(s)`}
              detail={`${s.planning.goals_active} objetivo(s) ativo(s).`}
              to="/rotina"
              action="Abrir rotina"
            />
            <InventoryItem
              title="Dispositivos"
              value={`${s.devices.active} ativo(s) · ${s.devices.revoked} revogado(s)`}
              detail="A gestão de dispositivos chega com os agentes de computador e celular."
            />
            <InventoryItem
              title="Registro de segurança"
              value={`${s.security_log.events} evento(s)`}
              detail={`Entradas, saídas e mudanças de conta, sem conteúdo. O IP some em ${s.security_log.ip_retention_days} dias; o registro, em ${s.security_log.retention_days}.`}
            />
          </ul>
        )}
      </CardContent>
    </Card>
  )
}

function InventoryItem({ title, value, detail, to, action }: { title: string; value: string; detail: string; to?: string; action?: string }) {
  return (
    <li className="grid content-start gap-1 rounded-lg border p-3">
      <p className="text-xs text-muted-foreground">{title}</p>
      <p className="font-medium">{value}</p>
      <p className="text-xs text-muted-foreground">{detail}</p>
      {to && action && (
        <Link to={to} className="mt-1 text-sm text-primary underline-offset-4 hover:underline">
          {action}
        </Link>
      )}
    </li>
  )
}

function DeletionCard() {
  return (
    <Card className="border-destructive/30">
      <CardHeader>
        <CardTitle>Excluir dados</CardTitle>
        <CardDescription>
          Antes de confirmar, cada ação mostra exatamente o que será apagado e o que continua. Para apagar itens soltos, use as telas de Histórico
          e Memórias.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        <div className="flex flex-wrap gap-2">
          <ClearHistoryAction />
          <ClearMemoriesAction variant="outline" />
        </div>
        <DeleteAccount />
      </CardContent>
    </Card>
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
  const formatDate = useFormatDate()
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
  const formatDate = useFormatDate()
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
          Pacote ZIP com perfil, rotina, objetivos, dispositivos (sem credenciais), conversas, memórias, sugestões e autorizações, em JSON e versão legível. Uma vez a cada 24 h; o link vale para um único download.
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

function DeleteAccount() {
  const [open, setOpen] = useState(false)
  const summary = useDataSummary(open)
  const impact = summary.data && accountImpact(summary.data)
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
    <div className="grid gap-2 border-t pt-4">
      <p className="text-sm text-muted-foreground">
        Excluir a conta apaga tudo de uma vez. Se quiser uma cópia, exporte antes: o pacote não pode ser pedido depois.
      </p>
      <Button variant="destructive" className="justify-self-start" onClick={() => setOpen(true)}>
        Excluir minha conta
      </Button>
      <ConfirmDialog
        open={open}
        onOpenChange={(o) => {
          setOpen(o)
          if (!o) setPassword('')
        }}
        title="Excluir a conta definitivamente?"
        description={<ImpactList loading={!impact} removed={impact?.removed ?? []} kept={impact?.kept} />}
        confirmLabel="Excluir conta"
        pending={remove.isPending}
        confirmDisabled={!password || !impact}
        onConfirm={() => remove.mutate()}
      >
        <Field label="Confirme sua senha" htmlFor="delete-password" error={error?.field('password') ?? (remove.isError && !error?.field('password') ? errorMessage(remove.error) : undefined)}>
          <Input id="delete-password" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
        </Field>
      </ConfirmDialog>
    </div>
  )
}
