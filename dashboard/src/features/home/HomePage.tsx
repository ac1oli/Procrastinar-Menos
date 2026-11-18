import { useQuery } from '@tanstack/react-query'
import { ArrowRightIcon, BrainIcon, CalendarClockIcon, LightbulbIcon, MessageCircleIcon, ShieldCheckIcon, TargetIcon } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router'

import { goals, memories, nexo, routine, suggestions } from '@/api/endpoints'
import { keys } from '@/api/queries'
import { NexoMark } from '@/components/layout/NexoMark'
import { Button } from '@/components/ui/button'
import { Badge, Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle, Skeleton } from '@/components/ui/display'
import { Input } from '@/components/ui/form-controls'
import { useConsents, useMe } from '@/features/auth/session'
import { categoryLabel, formatDate, goalTypeLabel, scopeInfo, todayIso } from '@/lib/labels'

function greeting(timezone: string) {
  const hour = Number(new Intl.DateTimeFormat('en-US', { timeZone: timezone, hour: 'numeric', hourCycle: 'h23' }).format(new Date()))
  return hour < 12 ? 'Bom dia' : hour < 18 ? 'Boa tarde' : 'Boa noite'
}

export function HomePage() {
  const { data: me } = useMe()
  const timezone = me?.timezone ?? 'America/Sao_Paulo'
  const navigate = useNavigate()
  const [draft, setDraft] = useState('')

  const status = useQuery({ queryKey: keys.status, queryFn: nexo.status })
  const blocks = useQuery({ queryKey: keys.routine, queryFn: routine.list })
  const goalList = useQuery({ queryKey: keys.goals, queryFn: goals.list })
  const pending = useQuery({ queryKey: keys.suggestions, queryFn: suggestions.list })
  const toReview = useQuery({ queryKey: keys.memories('needs_review', ''), queryFn: () => memories.list({ status: 'needs_review' }) })
  const consents = useConsents()

  const today = todayIso(timezone)
  const todayBlocks = (blocks.data ?? []).filter((b) => b.is_active && b.days_of_week.includes(today)).sort((a, b) => a.start_time.localeCompare(b.start_time))
  const activeGoals = (goalList.data ?? []).filter((g) => g.status === 'active')
  const allowed = (consents.data?.scopes ?? []).filter((s) => s.granted)

  return (
    <div className="grid gap-6">
      <section className="relative overflow-hidden rounded-2xl border bg-card p-6 sm:p-8">
        <div aria-hidden className="pointer-events-none absolute -top-24 -right-24 size-72 rounded-full bg-nexo-gradient opacity-20 blur-3xl" />
        <div className="relative flex flex-wrap items-start gap-5">
          <NexoMark className="size-12" />
          <div className="grid min-w-0 flex-1 gap-1">
            <h1 className="text-2xl font-semibold tracking-tight">
              {greeting(timezone)}, {me?.name.split(' ')[0]}.
            </h1>
            <p className="text-sm text-muted-foreground">
              {todayBlocks.length
                ? `Você planejou ${todayBlocks.length} bloco(s) para hoje. Um passo de cada vez.`
                : 'Nada planejado para hoje. Que tal um bloco pequeno e possível?'}
            </p>
          </div>
        </div>
        <form
          className="relative mt-6 flex gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            navigate('/conversar', { state: { draft } })
          }}
        >
          <Input
            aria-label="Começar uma conversa"
            placeholder={status.data?.available === false ? 'O NEXO está indisponível agora' : 'Pergunte algo ao NEXO…'}
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
          />
          <Button type="submit" variant="nexo">
            <MessageCircleIcon /> Conversar
          </Button>
        </form>
      </section>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <CalendarClockIcon className="size-4" /> Hoje
            </CardTitle>
            <CardDescription>Blocos ativos da sua rotina para hoje.</CardDescription>
            <CardAction>
              <Button asChild variant="ghost" size="sm">
                <Link to="/rotina">
                  Rotina <ArrowRightIcon />
                </Link>
              </Button>
            </CardAction>
          </CardHeader>
          <CardContent>
            {blocks.isPending ? (
              <Skeleton className="h-20" />
            ) : todayBlocks.length ? (
              <ul className="grid gap-2">
                {todayBlocks.map((b) => (
                  <li key={b.id} className="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm">
                    <span className="font-medium">{b.title}</span>
                    <span className="flex items-center gap-2 text-muted-foreground">
                      <Badge variant="secondary">{categoryLabel[b.category]}</Badge>
                      {b.start_time}–{b.end_time}
                    </span>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-muted-foreground">Sem blocos para hoje.</p>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <TargetIcon className="size-4" /> Objetivos ativos
            </CardTitle>
            <CardDescription>O que você está buscando agora.</CardDescription>
            <CardAction>
              <Button asChild variant="ghost" size="sm">
                <Link to="/objetivos">
                  Objetivos <ArrowRightIcon />
                </Link>
              </Button>
            </CardAction>
          </CardHeader>
          <CardContent>
            {goalList.isPending ? (
              <Skeleton className="h-20" />
            ) : activeGoals.length ? (
              <ul className="grid gap-2">
                {activeGoals.slice(0, 5).map((g) => (
                  <li key={g.id} className="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm">
                    <span className="font-medium">{g.title}</span>
                    <span className="flex items-center gap-2 text-xs text-muted-foreground">
                      <Badge variant="secondary">{goalTypeLabel[g.type]}</Badge>
                      {g.target_date && formatDate(g.target_date)}
                    </span>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-muted-foreground">Nenhum objetivo ativo.</p>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <BrainIcon className="size-4" /> Memória
            </CardTitle>
            <CardDescription>O que precisa da sua atenção.</CardDescription>
          </CardHeader>
          <CardContent className="grid gap-2 text-sm">
            <Link to="/memorias?aba=sugestoes" className="flex items-center justify-between rounded-lg border px-3 py-2 hover:bg-accent/50">
              <span className="flex items-center gap-2">
                <LightbulbIcon className="size-4" /> Sugestões aguardando você
              </span>
              <Badge variant={pending.data?.length ? 'accent' : 'outline'}>{pending.data?.length ?? '…'}</Badge>
            </Link>
            <Link to="/memorias" className="flex items-center justify-between rounded-lg border px-3 py-2 hover:bg-accent/50">
              <span className="flex items-center gap-2">
                <BrainIcon className="size-4" /> Memórias para revisar
              </span>
              <Badge variant={toReview.data?.length ? 'accent' : 'outline'}>{toReview.data?.length ?? '…'}</Badge>
            </Link>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <ShieldCheckIcon className="size-4" /> O que o NEXO pode usar
            </CardTitle>
            <CardDescription>Suas autorizações atuais.</CardDescription>
            <CardAction>
              <Button asChild variant="ghost" size="sm">
                <Link to="/privacidade">
                  Ajustar <ArrowRightIcon />
                </Link>
              </Button>
            </CardAction>
          </CardHeader>
          <CardContent>
            {allowed.length ? (
              <ul className="flex flex-wrap gap-2">
                {allowed.map((s) => (
                  <li key={s.scope}>
                    <Badge variant="secondary">{scopeInfo[s.scope].title}</Badge>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-muted-foreground">Nada autorizado: o NEXO responde sem guardar nem usar seus dados pessoais.</p>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
