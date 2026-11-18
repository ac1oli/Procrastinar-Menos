import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BrainIcon, EyeOffIcon, PlusIcon, SendIcon, ShieldAlertIcon } from 'lucide-react'
import { useEffect, useRef, useState, type FormEvent, type KeyboardEvent } from 'react'
import { Link, useLocation, useSearchParams } from 'react-router'

import { ApiError } from '@/api/client'
import { conversations, nexo } from '@/api/endpoints'
import { errorMessage, keys } from '@/api/queries'
import type { Turn, UsedMemory } from '@/api/types'
import { PageHeader } from '@/components/layout/AppShell'
import { NexoMark } from '@/components/layout/NexoMark'
import { Button } from '@/components/ui/button'
import { Badge, Card } from '@/components/ui/display'
import { Label, Switch, Textarea } from '@/components/ui/form-controls'
import { aiFailureLabel, kindLabel } from '@/lib/labels'
import { cn } from '@/lib/utils'

interface ChatMessage {
  key: string
  role: 'user' | 'assistant'
  content: string
  memories?: UsedMemory[]
  masked?: number | null
}

/** Turnos que o navegador reenvia quando nada é guardado no servidor (no máximo 6). */
const MAX_CLIENT_TURNS = 6

export function ChatPage() {
  const [params, setParams] = useSearchParams()
  const location = useLocation()
  const conversationId = params.get('c')
  const [messages, setMessages] = useState<ChatMessage[]>([])
  // Rascunho vindo do painel: aparece na caixa, mas só é enviado quando a pessoa enviar.
  const [text, setText] = useState(() => (location.state as { draft?: string } | null)?.draft ?? '')
  const [isPrivate, setPrivate] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)
  const [failure, setFailure] = useState<string | null>(null)
  const queryClient = useQueryClient()
  const endRef = useRef<HTMLDivElement>(null)

  const status = useQuery({ queryKey: keys.status, queryFn: nexo.status })
  const existing = useQuery({
    queryKey: keys.conversation(conversationId ?? ''),
    queryFn: () => conversations.get(conversationId!),
    enabled: !!conversationId,
  })

  // Ao abrir uma conversa guardada, mostra o que já foi dito até a primeira nova mensagem.
  const shown: ChatMessage[] =
    messages.length || !existing.data ? messages : existing.data.messages.map((m) => ({ key: m.id, role: m.role, content: m.content }))

  useEffect(() => {
    endRef.current?.scrollIntoView?.({ behavior: 'smooth', block: 'end' })
  }, [shown.length])

  const reset = (privateMode = isPrivate) => {
    setMessages([])
    setNotice(null)
    setFailure(null)
    setPrivate(privateMode)
    setParams({}, { replace: true })
  }

  const send = useMutation({
    // Os turnos anteriores são calculados no envio, antes de a nova mensagem entrar na tela.
    mutationFn: ({ message, history }: { message: string; history: Turn[] }) => {
      if (isPrivate) return nexo.send({ message, private: true, history })
      if (conversationId) return nexo.send({ message, conversation_id: conversationId })
      return nexo.send({ message, history })
    },
    onMutate: ({ message }) => {
      setFailure(null)
      const key = crypto.randomUUID()
      setMessages([...shown, { key, role: 'user', content: message }])
      setText('')
      return { key, message }
    },
    onSuccess: (reply, _vars, ctx) => {
      setMessages((m) => [
        ...m.map((msg) => (msg.key === ctx?.key ? { ...msg, masked: reply.masked_in_history } : msg)),
        { key: crypto.randomUUID(), role: 'assistant', content: reply.reply, memories: reply.memories_used },
      ])
      if (reply.conversation_id && reply.conversation_id !== conversationId) {
        setParams({ c: reply.conversation_id }, { replace: true })
      }
      if (!reply.stored && !reply.private) {
        setNotice('O histórico está desligado: esta conversa existe só nesta tela. Você pode ligá-lo em Privacidade.')
      }
      queryClient.invalidateQueries({ queryKey: ['conversations'] })
      queryClient.invalidateQueries({ queryKey: keys.suggestions })
    },
    onError: (error, _vars, ctx) => {
      // A mensagem volta para a caixa de texto: nada foi gravado e ela pode tentar de novo.
      setMessages((m) => m.filter((msg) => msg.key !== ctx?.key))
      setText(ctx?.message ?? '')
      if (error instanceof ApiError && error.status === 503) {
        setFailure(`${aiFailureLabel[error.reason ?? ''] ?? 'O NEXO está indisponível.'} Sua mensagem não foi perdida; tente de novo em instantes.`)
      } else if (error instanceof ApiError && error.status === 409) {
        setParams({}, { replace: true })
        setFailure('O histórico foi desligado, então esta conversa guardada não pode continuar. Envie de novo para começar uma nova.')
      } else {
        setFailure(errorMessage(error))
      }
    },
  })

  const submit = (e?: FormEvent) => {
    e?.preventDefault()
    const message = text.trim()
    if (message && !send.isPending) {
      const history: Turn[] = shown.slice(-MAX_CLIENT_TURNS).map(({ role, content }) => ({ role, content }))
      send.mutate({ message, history })
    }
  }

  const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault()
      submit()
    }
  }

  return (
    <div className="flex h-[calc(100dvh-9rem)] min-h-[28rem] flex-col">
      <PageHeader
        title="Conversar com o NEXO"
        description={
          isPrivate
            ? 'Conversa privada: nada é guardado e nenhuma memória é sugerida.'
            : conversationId
              ? 'Continuando uma conversa guardada.'
              : 'O que for guardado segue as suas autorizações.'
        }
        actions={
          <Button variant="outline" onClick={() => reset(false)}>
            <PlusIcon /> Nova conversa
          </Button>
        }
      />

      {status.data && !status.data.available && (
        <div role="status" className="mb-3 flex items-center gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm">
          <ShieldAlertIcon className="size-4 text-amber-600" /> O NEXO está indisponível agora. Rotina, objetivos e memórias continuam acessíveis.
        </div>
      )}

      <Card className="flex min-h-0 flex-1 flex-col gap-0 py-0">
        <div className="flex-1 space-y-4 overflow-y-auto p-4 sm:p-6" aria-live="polite">
          {existing.isPending && conversationId && <p className="text-sm text-muted-foreground">Carregando a conversa…</p>}
          {shown.length === 0 && !conversationId && (
            <div className="mx-auto flex max-w-md flex-col items-center gap-3 py-10 text-center">
              <NexoMark className="size-12" />
              <p className="font-medium">Como posso ajudar hoje?</p>
              <p className="text-sm text-muted-foreground">
                Fale sobre a sua rotina, um estudo travado ou um objetivo. O NEXO só usa as memórias, a rotina e os objetivos que você autorizou.
              </p>
            </div>
          )}
          {shown.map((m) => (
            <MessageBubble key={m.key} message={m} />
          ))}
          {send.isPending && (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <NexoMark className="size-6 animate-pulse" /> O NEXO está pensando…
            </div>
          )}
          <div ref={endRef} />
        </div>

        <form onSubmit={submit} className="grid gap-3 border-t p-3 sm:p-4">
          {notice && <p className="text-xs text-muted-foreground">{notice}</p>}
          {failure && (
            <p role="alert" className="text-sm text-destructive">
              {failure}
            </p>
          )}
          <div className="flex items-end gap-2">
            <Textarea
              aria-label="Mensagem para o NEXO"
              placeholder="Escreva para o NEXO… (Enter envia, Shift+Enter quebra a linha)"
              value={text}
              maxLength={4000}
              onChange={(e) => setText(e.target.value)}
              onKeyDown={onKeyDown}
              className="max-h-40 min-h-11"
            />
            <Button type="submit" variant="nexo" size="icon" disabled={!text.trim() || send.isPending} aria-label="Enviar">
              <SendIcon />
            </Button>
          </div>
          <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
            <div className="flex items-center gap-2">
              <Switch id="private" checked={isPrivate} onCheckedChange={(v) => reset(v)} />
              <Label htmlFor="private" className="text-xs font-normal">
                <EyeOffIcon className="size-3.5" /> Conversa privada (não é guardada)
              </Label>
            </div>
            <span>{text.length}/4000</span>
          </div>
        </form>
      </Card>
    </div>
  )
}

function MessageBubble({ message }: { message: ChatMessage }) {
  const isUser = message.role === 'user'
  return (
    <div className={cn('flex flex-col gap-1.5', isUser ? 'items-end' : 'items-start')}>
      <div
        className={cn(
          'max-w-[85%] rounded-2xl px-4 py-2.5 text-sm leading-relaxed whitespace-pre-wrap',
          isUser ? 'rounded-br-md bg-primary text-primary-foreground' : 'rounded-bl-md border bg-card',
        )}
      >
        {message.content}
      </div>
      {!!message.masked && (
        <span className="text-xs text-muted-foreground">
          {message.masked} dado(s) mascarado(s) antes de guardar no histórico.
        </span>
      )}
      {!!message.memories?.length && (
        <details className="max-w-[85%] text-xs text-muted-foreground">
          <summary className="flex cursor-pointer list-none items-center gap-1.5">
            <BrainIcon className="size-3.5" /> O NEXO considerou {message.memories.length} memória(s)
          </summary>
          <ul className="mt-2 grid gap-1.5">
            {message.memories.map((m) => (
              <li key={m.id} className="flex flex-wrap items-center gap-2">
                <Badge variant="secondary">{kindLabel[m.kind]}</Badge> {m.content}
              </li>
            ))}
          </ul>
          <Link to="/memorias" className="mt-2 inline-block text-primary underline-offset-4 hover:underline">
            Gerenciar memórias
          </Link>
        </details>
      )}
    </div>
  )
}
