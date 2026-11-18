import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeftIcon, HistoryIcon, MessageCircleIcon, PencilIcon, SearchIcon, Trash2Icon } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'

import { conversations } from '@/api/endpoints'
import { errorMessage, keys } from '@/api/queries'
import type { ConversationDetail } from '@/api/types'
import { ConfirmDialog, useConfirm } from '@/components/ConfirmDialog'
import { PageHeader } from '@/components/layout/AppShell'
import { Button } from '@/components/ui/button'
import { Badge, Card, CardContent, EmptyState, Skeleton } from '@/components/ui/display'
import { Checkbox, Input, Label } from '@/components/ui/form-controls'
import { useConsents } from '@/features/auth/session'
import { formatDate } from '@/lib/labels'
import { cn } from '@/lib/utils'

export function HistoryPage() {
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const clearAll = useConfirm()
  const queryClient = useQueryClient()
  const consents = useConsents()
  const historyOn = consents.data?.scopes.find((s) => s.scope === 'nexo.history')?.granted

  const list = useQuery({ queryKey: keys.conversations(query), queryFn: () => conversations.list(query) })
  const clear = useMutation({
    mutationFn: conversations.clear,
    onSuccess: (r) => {
      toast.success(`${r.deleted_conversations} conversa(s) excluída(s).`)
      clearAll.close()
      queryClient.invalidateQueries({ queryKey: ['conversations'] })
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  return (
    <>
      <PageHeader
        title="Histórico de conversas"
        description="Conversas guardadas com o seu consentimento. Dados como CPF, cartões e senhas aparecem mascarados."
        actions={
          !!list.data?.length && (
            <Button variant="outline" onClick={() => clearAll.open(true)}>
              <Trash2Icon /> Apagar todo o histórico
            </Button>
          )
        }
      />

      {historyOn === false && (
        <p className="mb-4 rounded-lg border bg-muted/50 px-3 py-2 text-sm text-muted-foreground">
          O histórico está desligado: novas conversas não são guardadas. As existentes continuam aqui até você excluí-las ou o prazo vencer.{' '}
          <Link to="/privacidade" className="text-primary underline-offset-4 hover:underline">
            Alterar
          </Link>
        </p>
      )}

      <form
        className="mb-4 flex gap-2"
        role="search"
        onSubmit={(e) => {
          e.preventDefault()
          setQuery(search.trim())
        }}
      >
        <Input aria-label="Buscar nas conversas" placeholder="Buscar por palavras…" value={search} onChange={(e) => setSearch(e.target.value)} />
        <Button type="submit" variant="secondary">
          <SearchIcon /> Buscar
        </Button>
      </form>

      {list.isPending ? (
        <div className="grid gap-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-16" />
          ))}
        </div>
      ) : list.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {errorMessage(list.error)}
        </p>
      ) : list.data.length === 0 ? (
        <EmptyState icon={<HistoryIcon />} title={query ? 'Nada encontrado' : 'Nenhuma conversa guardada'}>
          {query ? 'Tente outras palavras.' : 'Converse com o NEXO com o histórico ligado para retomar depois.'}
        </EmptyState>
      ) : (
        <ul className="grid gap-2">
          {list.data.map((c) => (
            <li key={c.id}>
              <Link
                to={`/historico/${c.id}`}
                className="flex flex-wrap items-center justify-between gap-2 rounded-xl border bg-card px-4 py-3 transition-colors hover:bg-accent/50"
              >
                <span className="min-w-0 flex-1 truncate font-medium">{c.title}</span>
                <span className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                  <Badge variant="secondary">{c.messages_count} mensagens</Badge>
                  <span>{formatDate(c.last_message_at, true)}</span>
                  <span>{c.expires_at ? `expira em ${formatDate(c.expires_at)}` : 'sem prazo'}</span>
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}

      <ConfirmDialog
        open={clearAll.isOpen}
        onOpenChange={(o) => !o && clearAll.close()}
        title="Apagar todo o histórico?"
        description="Todas as conversas guardadas e as sugestões pendentes geradas a partir delas serão excluídas. Memórias que você confirmou permanecem. Esta ação não pode ser desfeita."
        confirmLabel="Apagar tudo"
        pending={clear.isPending}
        onConfirm={() => clear.mutate()}
      />
    </>
  )
}

export function ConversationPage() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const conversation = useQuery({ queryKey: keys.conversation(id), queryFn: () => conversations.get(id) })
  const [editing, setEditing] = useState(false)
  const [title, setTitle] = useState('')
  const remove = useConfirm()
  const [withMemories, setWithMemories] = useState(false)

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: keys.conversation(id) })
    queryClient.invalidateQueries({ queryKey: ['conversations'] })
  }

  const rename = useMutation({
    mutationFn: () => conversations.rename(id, title),
    onSuccess: () => {
      setEditing(false)
      refresh()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const removeConversation = useMutation({
    mutationFn: () => conversations.remove(id, withMemories),
    onSuccess: () => {
      toast.success('Conversa excluída.')
      queryClient.invalidateQueries({ queryKey: ['conversations'] })
      queryClient.invalidateQueries({ queryKey: ['memories'] })
      navigate('/historico', { replace: true })
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const removeMessage = useMutation({
    mutationFn: (messageId: string) => conversations.removeMessage(id, messageId),
    onSuccess: (_r, messageId) => {
      const current = queryClient.getQueryData<ConversationDetail>(keys.conversation(id))
      if (current && current.messages.length <= 1) {
        // Sem mensagens, o backend exclui a conversa.
        queryClient.invalidateQueries({ queryKey: ['conversations'] })
        navigate('/historico', { replace: true })
        return
      }
      queryClient.setQueryData<ConversationDetail>(keys.conversation(id), (c) => c && { ...c, messages: c.messages.filter((m) => m.id !== messageId) })
      refresh()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  if (conversation.isPending) return <Skeleton className="h-64" />
  if (conversation.isError)
    return (
      <EmptyState title="Conversa não encontrada">
        <Link to="/historico" className="text-primary">
          Voltar ao histórico
        </Link>
      </EmptyState>
    )

  const c = conversation.data
  const linked = c.memories_from_this_conversation.length

  return (
    <>
      <Link to="/historico" className="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeftIcon className="size-4" /> Histórico
      </Link>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        {editing ? (
          <form
            className="flex flex-1 gap-2"
            onSubmit={(e) => {
              e.preventDefault()
              rename.mutate()
            }}
          >
            <Input aria-label="Título da conversa" value={title} maxLength={120} onChange={(e) => setTitle(e.target.value)} autoFocus />
            <Button type="submit" disabled={!title.trim() || rename.isPending}>
              Salvar
            </Button>
            <Button type="button" variant="ghost" onClick={() => setEditing(false)}>
              Cancelar
            </Button>
          </form>
        ) : (
          <div className="grid gap-1">
            <h1 className="text-2xl font-semibold tracking-tight">{c.title}</h1>
            <p className="text-sm text-muted-foreground">
              {c.messages_count} mensagens · última em {formatDate(c.last_message_at, true)} · {c.expires_at ? `expira em ${formatDate(c.expires_at)}` : 'sem prazo de expiração'}
            </p>
          </div>
        )}
        {!editing && (
          <div className="flex flex-wrap gap-2">
            <Button asChild variant="nexo">
              <Link to={`/conversar?c=${c.id}`}>
                <MessageCircleIcon /> Continuar
              </Link>
            </Button>
            <Button
              variant="outline"
              onClick={() => {
                setTitle(c.title)
                setEditing(true)
              }}
            >
              <PencilIcon /> Renomear
            </Button>
            <Button variant="outline" onClick={() => remove.open(true)}>
              <Trash2Icon /> Excluir
            </Button>
          </div>
        )}
      </div>

      <Card>
        <CardContent className="grid gap-4">
          {c.messages.map((m) => (
            <div key={m.id} className={cn('group flex flex-col gap-1', m.role === 'user' ? 'items-end' : 'items-start')}>
              <div
                className={cn(
                  'max-w-[85%] rounded-2xl px-4 py-2.5 text-sm whitespace-pre-wrap',
                  m.role === 'user' ? 'rounded-br-md bg-primary text-primary-foreground' : 'rounded-bl-md border bg-card',
                )}
              >
                {m.content}
              </div>
              <div className="flex items-center gap-2 text-xs text-muted-foreground">
                <span>{formatDate(m.created_at, true)}</span>
                {m.masked > 0 && <span>· {m.masked} dado(s) mascarado(s)</span>}
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-6 px-2 text-xs"
                  aria-label="Excluir mensagem"
                  onClick={() => removeMessage.mutate(m.id)}
                  disabled={removeMessage.isPending}
                >
                  <Trash2Icon className="size-3" />
                </Button>
              </div>
            </div>
          ))}
        </CardContent>
      </Card>

      <ConfirmDialog
        open={remove.isOpen}
        onOpenChange={(o) => !o && remove.close()}
        title="Excluir esta conversa?"
        description="As mensagens e as sugestões pendentes desta conversa serão excluídas. Esta ação não pode ser desfeita."
        confirmLabel="Excluir conversa"
        pending={removeConversation.isPending}
        onConfirm={() => removeConversation.mutate()}
      >
        {linked > 0 && (
          <div className="flex items-start gap-2 rounded-lg border p-3 text-sm">
            <Checkbox id="with-memories" checked={withMemories} onCheckedChange={(v) => setWithMemories(v === true)} />
            <Label htmlFor="with-memories" className="leading-snug font-normal">
              Excluir também {linked} memória(s) confirmada(s) a partir desta conversa. Sem marcar, elas ficam e só perdem o vínculo.
            </Label>
          </div>
        )}
      </ConfirmDialog>
    </>
  )
}
