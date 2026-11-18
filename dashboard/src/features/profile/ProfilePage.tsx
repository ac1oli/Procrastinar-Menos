import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ClockIcon, KeyRoundIcon, MailIcon, UserRoundIcon } from 'lucide-react'
import { useMemo, useState, type FormEvent } from 'react'
import { toast } from 'sonner'

import { ApiError } from '@/api/client'
import { profile } from '@/api/endpoints'
import { errorMessage, keys } from '@/api/queries'
import type { User } from '@/api/types'
import { PageHeader } from '@/components/layout/AppShell'
import { Button } from '@/components/ui/button'
import { Badge, Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/display'
import { Field, Input, NativeSelect } from '@/components/ui/form-controls'
import { useMe } from '@/features/auth/session'
import { formatOffset } from '@/lib/labels'

/**
 * Perfil: nome de exibição, fuso, e-mail e senha. Só os dados que já existem na conta.
 * Validações aqui são de conforto (campo vazio, confirmação); as regras ficam no backend.
 */
export function ProfilePage() {
  const { data: me } = useMe()
  if (!me) return null

  return (
    <>
      <PageHeader title="Perfil" description="Como o NEXO chama você e em que fuso a sua rotina acontece. Nada além disso é pedido." />
      <div className="grid gap-6 xl:grid-cols-2">
        <NameCard me={me} />
        <TimezoneCard me={me} />
        <EmailCard me={me} />
        <PasswordCard />
      </div>
    </>
  )
}

function useSaveProfile() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: profile.update,
    onSuccess: (user) => {
      queryClient.setQueryData(keys.me, user)
      toast.success('Perfil atualizado.')
    },
  })
}

function NameCard({ me }: { me: User }) {
  const [name, setName] = useState(me.name)
  const save = useSaveProfile()
  const error = save.error instanceof ApiError ? save.error : undefined
  const trimmed = name.trim()
  const unchanged = trimmed === me.name

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <UserRoundIcon className="size-4" /> Nome de exibição
        </CardTitle>
        <CardDescription>Usado na saudação do painel. O NEXO não recebe o seu nome nas conversas.</CardDescription>
      </CardHeader>
      <CardContent>
        <form
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault()
            save.mutate({ name: trimmed })
          }}
        >
          <Field label="Nome" htmlFor="profile-name" error={error?.field('name')} hint={`${trimmed.length}/120`}>
            <Input id="profile-name" value={name} maxLength={120} autoComplete="name" onChange={(e) => setName(e.target.value)} />
          </Field>
          <Button type="submit" className="justify-self-start" disabled={!trimmed || unchanged || save.isPending}>
            {save.isPending ? 'Salvando…' : 'Salvar nome'}
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}

function TimezoneCard({ me }: { me: User }) {
  const zones = useQuery({ queryKey: keys.timezones, queryFn: profile.timezones, staleTime: Infinity })
  const [timezone, setTimezone] = useState(me.timezone)
  const [filter, setFilter] = useState('')
  const save = useSaveProfile()
  const error = save.error instanceof ApiError ? save.error : undefined
  const browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone
  const details = me.timezone_details

  const options = useMemo(() => {
    const term = filter.trim().toLowerCase().replace(/\s+/g, '_')
    const list = (zones.data ?? []).filter((z) => !term || z.toLowerCase().includes(term))
    return list.includes(timezone) ? list : [timezone, ...list]
  }, [zones.data, filter, timezone])

  const localNow = new Intl.DateTimeFormat('pt-BR', { timeZone: me.timezone, weekday: 'long', hour: '2-digit', minute: '2-digit' }).format(new Date())
  const transitionText = details.next_transition
    ? `Próxima mudança de horário: ${new Intl.DateTimeFormat('pt-BR', { timeZone: me.timezone, dateStyle: 'long', timeStyle: 'short' }).format(new Date(details.next_transition.at))}, para ${formatOffset(details.next_transition.offset)}${details.next_transition.is_dst ? ' (início do horário de verão)' : ' (fim do horário de verão)'}.`
    : 'Este fuso não tem mudança de horário prevista para os próximos 12 meses.'

  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate({ timezone })
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <ClockIcon className="size-4" /> Fuso horário
        </CardTitle>
        <CardDescription>
          Define o “hoje” da rotina, o horário que o NEXO considera nas respostas e as datas mostradas aqui. Vale a partir da próxima mensagem.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        <div className="rounded-lg border bg-muted/40 p-3 text-sm" role="status" aria-label="Situação do fuso atual">
          <p className="font-medium">
            {me.timezone} · {formatOffset(details.offset)} {details.is_dst && <Badge variant="secondary">horário de verão</Badge>}
          </p>
          <p className="text-muted-foreground">Agora lá: {localNow}.</p>
          <p className="text-muted-foreground">{transitionText}</p>
        </div>

        {browserZone && browserZone !== me.timezone && (
          <div className="flex flex-wrap items-center gap-2 text-sm">
            <span className="text-muted-foreground">Este navegador está em {browserZone}.</span>
            <Button type="button" size="sm" variant="outline" onClick={() => setTimezone(browserZone)}>
              Usar {browserZone}
            </Button>
          </div>
        )}

        <form className="grid gap-3" onSubmit={submit}>
          <Field label="Filtrar fusos" htmlFor="timezone-filter" hint="Ex.: Sao_Paulo, Lisbon, Tokyo">
            <Input id="timezone-filter" value={filter} onChange={(e) => setFilter(e.target.value)} placeholder="Digite parte do nome" />
          </Field>
          <Field label="Fuso da conta" htmlFor="profile-timezone" error={error?.field('timezone')}>
            <NativeSelect id="profile-timezone" value={timezone} onChange={(e) => setTimezone(e.target.value)} disabled={zones.isPending}>
              {options.map((z) => (
                <option key={z} value={z}>
                  {z}
                </option>
              ))}
            </NativeSelect>
          </Field>
          {zones.isError && (
            <p role="alert" className="text-sm text-destructive">
              {errorMessage(zones.error)}
            </p>
          )}
          <Button type="submit" className="justify-self-start" disabled={timezone === me.timezone || save.isPending}>
            {save.isPending ? 'Salvando…' : 'Salvar fuso'}
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}

function EmailCard({ me }: { me: User }) {
  const queryClient = useQueryClient()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const save = useMutation({
    mutationFn: () => profile.updateEmail(email, password),
    onSuccess: (user) => {
      queryClient.setQueryData(keys.me, user)
      setEmail('')
      setPassword('')
      toast.success('E-mail atualizado. Use o novo endereço para entrar.')
    },
  })
  const error = save.error instanceof ApiError ? save.error : undefined

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <MailIcon className="size-4" /> E-mail de acesso
        </CardTitle>
        <CardDescription>
          Atual: <strong className="text-foreground">{me.email}</strong>. Serve só para entrar; não enviamos mensagens.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault()
            save.mutate()
          }}
        >
          <Field label="Novo e-mail" htmlFor="profile-email" error={error?.field('email')}>
            <Input id="profile-email" type="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} />
          </Field>
          <Field label="Senha atual" htmlFor="profile-email-password" error={error?.field('current_password')}>
            <Input id="profile-email-password" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
          </Field>
          <Button type="submit" variant="secondary" className="justify-self-start" disabled={!email.trim() || !password || save.isPending}>
            Alterar e-mail
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}

function PasswordCard() {
  const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' })
  const save = useMutation({
    mutationFn: () => profile.updatePassword(form),
    onSuccess: ({ other_sessions_ended }) => {
      setForm({ current_password: '', password: '', password_confirmation: '' })
      toast.success(
        other_sessions_ended > 0
          ? `Senha alterada. ${other_sessions_ended} outra(s) sessão(ões) foram encerradas.`
          : 'Senha alterada. Esta sessão continua ativa.',
      )
    },
  })
  const error = save.error instanceof ApiError ? save.error : undefined
  const mismatch = form.password_confirmation !== '' && form.password !== form.password_confirmation
  const set = (key: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm((f) => ({ ...f, [key]: e.target.value }))

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <KeyRoundIcon className="size-4" /> Senha
        </CardTitle>
        <CardDescription>
          Pelo menos 10 caracteres, com letras e números. Trocar a senha encerra as sessões abertas em outros navegadores. Os agentes de
          dispositivo têm credenciais próprias e não são afetados.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault()
            save.mutate()
          }}
        >
          <Field label="Senha atual" htmlFor="pw-current" error={error?.field('current_password')}>
            <Input id="pw-current" type="password" autoComplete="current-password" value={form.current_password} onChange={set('current_password')} />
          </Field>
          <Field label="Nova senha" htmlFor="pw-new" error={error?.field('password')}>
            <Input id="pw-new" type="password" autoComplete="new-password" value={form.password} onChange={set('password')} />
          </Field>
          <Field label="Confirme a nova senha" htmlFor="pw-confirm" error={mismatch ? 'A confirmação não confere.' : undefined}>
            <Input id="pw-confirm" type="password" autoComplete="new-password" value={form.password_confirmation} onChange={set('password_confirmation')} />
          </Field>
          <Button
            type="submit"
            variant="secondary"
            className="justify-self-start"
            disabled={!form.current_password || !form.password || !form.password_confirmation || mismatch || save.isPending}
          >
            Alterar senha
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}
