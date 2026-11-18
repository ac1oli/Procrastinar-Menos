import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ArchiveIcon,
  ArchiveRestoreIcon,
  BrainIcon,
  CheckIcon,
  LightbulbIcon,
  PencilIcon,
  PinIcon,
  PlusIcon,
  SearchIcon,
  Trash2Icon,
  XIcon,
} from 'lucide-react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { toast } from 'sonner'

import { memories as memoriesApi, suggestions as suggestionsApi } from '@/api/endpoints'
import { errorMessage, keys } from '@/api/queries'
import type { Memory, MemoryStatus, MemorySuggestion } from '@/api/types'
import { ConfirmDialog, useConfirm } from '@/components/ConfirmDialog'
import { PageHeader } from '@/components/layout/AppShell'
import { Button } from '@/components/ui/button'
import { Badge, Card, CardContent, EmptyState, Skeleton, Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/display'
import { Input, NativeSelect } from '@/components/ui/form-controls'
import { useConsents } from '@/features/auth/session'
import { confidenceLabel, evidenceLabel, formatDate, kindLabel, originLabel, statusLabel } from '@/lib/labels'

import { MemoryForm } from './MemoryForm'

export function MemoriesPage() {
  const [params, setParams] = useSearchParams()
  const tab = params.get('aba') === 'sugestoes' ? 'sugestoes' : 'memorias'
  const pending = useQuery({ queryKey: keys.suggestions, queryFn: suggestionsApi.list })

  return (
    <>
      <PageHeader
        title="Memórias"
        description="O que o NEXO sabe sobre você porque você criou ou confirmou. Origem, evidência e confiança de cada item ficam visíveis."
      />
      <Tabs value={tab} onValueChange={(v) => setParams(v === 'sugestoes' ? { aba: 'sugestoes' } : {}, { replace: true })}>
        <TabsList>
          <TabsTrigger value="memorias">
            <BrainIcon className="size-4" /> Memórias
          </TabsTrigger>
          <TabsTrigger value="sugestoes">
            <LightbulbIcon className="size-4" /> Sugestões
            {!!pending.data?.length && <Badge variant="accent">{pending.data.length}</Badge>}
          </TabsTrigger>
        </TabsList>
        <TabsContent value="memorias">
          <MemoryList />
        </TabsContent>
        <TabsContent value="sugestoes">
          <SuggestionList />
        </TabsContent>
      </Tabs>
    </>
  )
}

function MemoryList() {
  const [status, setStatus] = useState<MemoryStatus | ''>('')
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [creating, setCreating] = useState(false)
  const [editing, setEditing] = useState<Memory | null>(null)
  const removing = useConfirm<Memory>()
  const clearing = useConfirm()
  const queryClient = useQueryClient()
  const consents = useConsents()
  const memoryOn = consents.data?.scopes.find((s) => s.scope === 'nexo.memory')?.granted === true

  const list = useQuery({ queryKey: keys.memories(status, query), queryFn: () => memoriesApi.list({ status: status || undefined, q: query || undefined }) })
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['memories'] })
  const mutate = useMutation({
    mutationFn: async ({ action, memory }: { action: 'archive' | 'restore' | 'review' | 'pin' | 'unpin' | 'remove'; memory: Memory }) => {
      const calls = {
        archive: () => memoriesApi.update(memory.id, { status: 'archived' }),
        restore: () => memoriesApi.update(memory.id, { status: 'active' }),
        review: () => memoriesApi.review(memory.id),
        pin: () => memoriesApi.update(memory.id, { pinned: true }),
        unpin: () => memoriesApi.update(memory.id, { pinned: false }),
        remove: () => memoriesApi.remove(memory.id),
      }
      await calls[action]()
    },
    onSuccess: (_r, { action }) => {
      if (action === 'remove') {
        removing.close()
        toast.success('Memória excluída.')
      }
      refresh()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const clear = useMutation({
    mutationFn: memoriesApi.clear,
    onSuccess: (r) => {
      clearing.close()
      toast.success(`${r.deleted_memories} memória(s) excluída(s).`)
      refresh()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  return (
    <div className="grid gap-4">
      {consents.data && !memoryOn && (
        <p className="rounded-lg border bg-muted/50 px-3 py-2 text-sm text-muted-foreground">
          A memória de longo prazo está desligada: o NEXO não usa estas memórias e novas não podem ser criadas. Você ainda pode consultar, editar e
          excluir as existentes.{' '}
          <Link to="/privacidade" className="text-primary underline-offset-4 hover:underline">
            Alterar
          </Link>
        </p>
      )}

      <div className="flex flex-wrap items-center gap-2">
        <form
          className="flex min-w-60 flex-1 gap-2"
          role="search"
          onSubmit={(e) => {
            e.preventDefault()
            setQuery(search.trim())
          }}
        >
          <Input aria-label="Buscar memórias" placeholder="Buscar…" value={search} onChange={(e) => setSearch(e.target.value)} />
          <Button type="submit" variant="secondary" size="icon" aria-label="Buscar">
            <SearchIcon />
          </Button>
        </form>
        <NativeSelect aria-label="Filtrar por estado" className="w-44" value={status} onChange={(e) => setStatus(e.target.value as MemoryStatus | '')}>
          <option value="">Todas</option>
          {Object.entries(statusLabel).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </NativeSelect>
        <Button onClick={() => setCreating(true)} disabled={!memoryOn}>
          <PlusIcon /> Nova memória
        </Button>
        {!!list.data?.length && (
          <Button variant="ghost" onClick={() => clearing.open(true)}>
            <Trash2Icon /> Excluir todas
          </Button>
        )}
      </div>

      {list.isPending ? (
        <Skeleton className="h-32" />
      ) : list.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorMessage(list.error)}
        </p>
      ) : list.data.length === 0 ? (
        <EmptyState icon={<BrainIcon />} title={query || status ? 'Nenhuma memória com esses filtros' : 'Nenhuma memória ainda'}>
          Crie uma memória ou confirme uma sugestão do NEXO. Nada é guardado sem você.
        </EmptyState>
      ) : (
        <ul className="grid gap-3 md:grid-cols-2">
          {list.data.map((m) => (
            <li key={m.id}>
              <MemoryCard
                memory={m}
                busy={mutate.isPending}
                onEdit={() => setEditing(m)}
                onAction={(action) => (action === 'remove' ? removing.open(m) : mutate.mutate({ action, memory: m }))}
              />
            </li>
          ))}
        </ul>
      )}

      {creating && (
        <MemoryForm
          open
          onOpenChange={setCreating}
          title="Nova memória"
          description="Escreva algo duradouro sobre você que ajude o NEXO. Documentos, contatos e senhas são recusados."
          submitLabel="Guardar memória"
          onSubmit={async (input) => {
            await memoriesApi.create(input)
            toast.success('Memória guardada.')
            refresh()
          }}
        />
      )}
      {editing && (
        <MemoryForm
          open
          onOpenChange={(o) => !o && setEditing(null)}
          title="Editar memória"
          description="Editar substitui o texto; versões anteriores não são guardadas."
          initial={{ kind: editing.kind, content: editing.content, pinned: editing.pinned, sensitive: editing.sensitivity === 'sensitive' }}
          submitLabel="Salvar"
          onSubmit={async (input) => {
            await memoriesApi.update(editing.id, input)
            refresh()
          }}
        />
      )}
      <ConfirmDialog
        open={removing.isOpen}
        onOpenChange={(o) => !o && removing.close()}
        title="Excluir esta memória?"
        description={<>“{removing.target?.content}” será excluída, junto com o índice de busca dela. Esta ação não pode ser desfeita.</>}
        confirmLabel="Excluir"
        pending={mutate.isPending}
        onConfirm={() => removing.target && mutate.mutate({ action: 'remove', memory: removing.target })}
      />
      <ConfirmDialog
        open={clearing.isOpen}
        onOpenChange={(o) => !o && clearing.close()}
        title="Excluir todas as memórias?"
        description="Todas as memórias e seus índices de busca serão excluídos. Esta ação não pode ser desfeita."
        confirmLabel="Excluir todas"
        pending={clear.isPending}
        onConfirm={() => clear.mutate()}
      />
    </div>
  )
}

function MemoryCard({
  memory: m,
  busy,
  onEdit,
  onAction,
}: {
  memory: Memory
  busy: boolean
  onEdit: () => void
  onAction: (action: 'archive' | 'restore' | 'review' | 'pin' | 'unpin' | 'remove') => void
}) {
  return (
    <Card className="h-full gap-3" data-testid="memory-card">
      <CardContent className="grid gap-3">
        <div className="flex flex-wrap items-center gap-1.5">
          <Badge variant="secondary">{kindLabel[m.kind]}</Badge>
          {m.status !== 'active' && <Badge variant={m.status === 'needs_review' ? 'accent' : 'outline'}>{statusLabel[m.status]}</Badge>}
          {m.pinned && (
            <Badge variant="outline">
              <PinIcon /> Fixada
            </Badge>
          )}
          {m.sensitivity === 'sensitive' && <Badge variant="destructive">Sensível</Badge>}
          {m.semantic_index === 'pending' && <Badge variant="outline" title="Busca por significado pendente; a busca por palavras já funciona">Indexando</Badge>}
        </div>
        <p className="text-sm leading-relaxed">{m.content}</p>
        <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-muted-foreground">
          <dt>Origem</dt>
          <dd>{originLabel[m.origin]}</dd>
          <dt>Evidência</dt>
          <dd>
            {evidenceLabel[m.evidence_level]} · {confidenceLabel[m.confidence]}
          </dd>
          <dt>Criada</dt>
          <dd>{formatDate(m.created_at)}</dd>
          <dt>Último uso</dt>
          <dd>{formatDate(m.last_used_at)}</dd>
          <dt>Revisão sugerida</dt>
          <dd>{formatDate(m.review_due_at)}</dd>
        </dl>
        <div className="flex flex-wrap gap-1">
          {m.status === 'needs_review' && (
            <Button size="sm" variant="secondary" onClick={() => onAction('review')} disabled={busy}>
              <CheckIcon /> Continua válida
            </Button>
          )}
          <Button size="sm" variant="ghost" onClick={onEdit}>
            <PencilIcon /> Editar
          </Button>
          {m.status === 'archived' ? (
            <Button size="sm" variant="ghost" onClick={() => onAction('restore')} disabled={busy}>
              <ArchiveRestoreIcon /> Reativar
            </Button>
          ) : (
            <Button size="sm" variant="ghost" onClick={() => onAction('archive')} disabled={busy}>
              <ArchiveIcon /> Arquivar
            </Button>
          )}
          <Button size="sm" variant="ghost" onClick={() => onAction(m.pinned ? 'unpin' : 'pin')} disabled={busy}>
            <PinIcon /> {m.pinned ? 'Desafixar' : 'Fixar'}
          </Button>
          <Button size="sm" variant="ghost" className="text-destructive" onClick={() => onAction('remove')} disabled={busy}>
            <Trash2Icon /> Excluir
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}

function SuggestionList() {
  const queryClient = useQueryClient()
  const list = useQuery({ queryKey: keys.suggestions, queryFn: suggestionsApi.list })
  const [editing, setEditing] = useState<MemorySuggestion | null>(null)

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: keys.suggestions })
    queryClient.invalidateQueries({ queryKey: ['memories'] })
  }
  const accept = useMutation({
    mutationFn: (s: MemorySuggestion) => suggestionsApi.accept(s.id),
    onSuccess: () => {
      toast.success('Sugestão guardada como memória.')
      refresh()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const reject = useMutation({
    mutationFn: (s: MemorySuggestion) => suggestionsApi.reject(s.id),
    onSuccess: () => {
      toast.success('Sugestão recusada e apagada.')
      refresh()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  if (list.isPending) return <Skeleton className="h-32" />
  if (list.isError) return <p role="alert" className="text-sm text-destructive">{errorMessage(list.error)}</p>
  if (list.data.length === 0)
    return (
      <EmptyState icon={<LightbulbIcon />} title="Nenhuma sugestão pendente">
        Com as sugestões ligadas, o NEXO propõe memórias a partir das conversas guardadas. Nada vira memória sem a sua confirmação.
      </EmptyState>
    )

  return (
    <>
      <ul className="grid gap-3">
        {list.data.map((s) => (
          <li key={s.id}>
            <Card className="gap-3" data-testid="suggestion-card">
              <CardContent className="grid gap-3">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge variant="secondary">{kindLabel[s.kind]}</Badge>
                  <span className="text-xs text-muted-foreground">expira em {formatDate(s.expires_at)}</span>
                </div>
                <p className="text-sm">{s.content}</p>
                {s.rationale && <p className="text-xs text-muted-foreground">Por quê: {s.rationale}</p>}
                <div className="flex flex-wrap gap-2">
                  <Button size="sm" onClick={() => accept.mutate(s)} disabled={accept.isPending || reject.isPending}>
                    <CheckIcon /> Guardar
                  </Button>
                  <Button size="sm" variant="outline" onClick={() => setEditing(s)}>
                    <PencilIcon /> Editar e guardar
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => reject.mutate(s)} disabled={accept.isPending || reject.isPending}>
                    <XIcon /> Recusar
                  </Button>
                  <Link to={`/historico/${s.source.conversation_id}`} className="ml-auto self-center text-xs text-primary underline-offset-4 hover:underline">
                    Ver conversa
                  </Link>
                </div>
              </CardContent>
            </Card>
          </li>
        ))}
      </ul>
      {editing && (
        <MemoryForm
          open
          onOpenChange={(o) => !o && setEditing(null)}
          title="Editar sugestão"
          description="Ajuste o texto antes de guardar. Dados sensíveis não entram por sugestão; crie-os manualmente."
          initial={{ kind: editing.kind, content: editing.content }}
          showSensitive={false}
          showPinned={false}
          submitLabel="Guardar memória"
          onSubmit={async (input) => {
            await suggestionsApi.accept(editing.id, { kind: input.kind, content: input.content })
            toast.success('Sugestão guardada como memória.')
            refresh()
          }}
        />
      )}
    </>
  )
}
