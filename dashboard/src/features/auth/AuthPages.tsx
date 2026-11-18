import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent, type ReactNode } from 'react'
import { Link, useLocation, useNavigate } from 'react-router'

import { ApiError } from '@/api/client'
import { auth } from '@/api/endpoints'
import { errorMessage, keys } from '@/api/queries'
import { NexoMark } from '@/components/layout/NexoMark'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/display'
import { Field, Input } from '@/components/ui/form-controls'

function AuthLayout({ title, description, children, footer }: { title: string; description: string; children: ReactNode; footer: ReactNode }) {
  return (
    <div className="relative flex min-h-dvh items-center justify-center overflow-hidden p-4">
      <div aria-hidden className="pointer-events-none absolute -top-40 left-1/2 h-96 w-[48rem] -translate-x-1/2 rounded-full bg-nexo-gradient opacity-15 blur-3xl" />
      <div className="relative grid w-full max-w-md gap-6">
        <div className="flex flex-col items-center gap-3 text-center">
          <NexoMark className="size-12" />
          <p className="text-sm text-muted-foreground">
            <span className="text-nexo-gradient font-semibold">NEXO</span> · foco e desenvolvimento, no seu ritmo
          </p>
        </div>
        <Card>
          <CardHeader>
            <CardTitle className="text-xl">{title}</CardTitle>
            <CardDescription>{description}</CardDescription>
          </CardHeader>
          <CardContent>{children}</CardContent>
        </Card>
        <p className="text-center text-sm text-muted-foreground">{footer}</p>
      </div>
    </div>
  )
}

export function LoginPage() {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const location = useLocation()
  const from = (location.state as { from?: string } | null)?.from ?? '/'

  const login = useMutation({
    mutationFn: () => auth.login(email, password),
    onSuccess: (user) => {
      queryClient.setQueryData(keys.me, user)
      navigate(from, { replace: true })
    },
  })
  const error = login.error instanceof ApiError ? login.error : undefined

  const submit = (e: FormEvent) => {
    e.preventDefault()
    login.mutate()
  }

  return (
    <AuthLayout
      title="Entrar"
      description="Bem-vindo de volta."
      footer={
        <>
          Ainda não tem conta? <Link to="/criar-conta" className="font-medium text-primary underline-offset-4 hover:underline">Criar conta</Link>
        </>
      }
    >
      <form onSubmit={submit} className="grid gap-4" noValidate>
        <Field label="E-mail" htmlFor="email" error={error?.field('email')}>
          <Input id="email" type="email" autoComplete="email" required value={email} onChange={(e) => setEmail(e.target.value)} aria-invalid={!!error?.field('email')} />
        </Field>
        <Field label="Senha" htmlFor="password" error={error?.field('password')}>
          <Input id="password" type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
        </Field>
        {login.isError && !error?.field('email') && !error?.field('password') && (
          <p role="alert" className="text-sm text-destructive">
            {errorMessage(login.error)}
          </p>
        )}
        <Button type="submit" variant="nexo" disabled={login.isPending}>
          {login.isPending ? 'Entrando…' : 'Entrar'}
        </Button>
      </form>
    </AuthLayout>
  )
}

export function RegisterPage() {
  const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '' })
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'America/Sao_Paulo'

  const register = useMutation({
    mutationFn: () => auth.register({ ...form, timezone }),
    onSuccess: (user) => {
      queryClient.setQueryData(keys.me, user)
      navigate('/boas-vindas', { replace: true })
    },
  })
  const error = register.error instanceof ApiError ? register.error : undefined
  const set = (field: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm((f) => ({ ...f, [field]: e.target.value }))

  return (
    <AuthLayout
      title="Criar conta"
      description="Seus dados ficam no seu servidor. Você decide o que o NEXO pode guardar."
      footer={
        <>
          Já tem conta? <Link to="/entrar" className="font-medium text-primary underline-offset-4 hover:underline">Entrar</Link>
        </>
      }
    >
      <form
        onSubmit={(e) => {
          e.preventDefault()
          register.mutate()
        }}
        className="grid gap-4"
        noValidate
      >
        <Field label="Nome" htmlFor="name" error={error?.field('name')}>
          <Input id="name" autoComplete="name" value={form.name} onChange={set('name')} />
        </Field>
        <Field label="E-mail" htmlFor="email" error={error?.field('email')}>
          <Input id="email" type="email" autoComplete="email" value={form.email} onChange={set('email')} />
        </Field>
        <Field label="Senha" htmlFor="password" hint="Mínimo de 10 caracteres, com letras e números." error={error?.field('password')}>
          <Input id="password" type="password" autoComplete="new-password" value={form.password} onChange={set('password')} />
        </Field>
        <Field label="Confirme a senha" htmlFor="password_confirmation">
          <Input id="password_confirmation" type="password" autoComplete="new-password" value={form.password_confirmation} onChange={set('password_confirmation')} />
        </Field>
        <p className="text-xs text-muted-foreground">Fuso horário detectado: {timezone}.</p>
        {register.isError && Object.keys(error?.errors ?? {}).length === 0 && (
          <p role="alert" className="text-sm text-destructive">
            {errorMessage(register.error)}
          </p>
        )}
        <Button type="submit" variant="nexo" disabled={register.isPending}>
          {register.isPending ? 'Criando…' : 'Criar conta'}
        </Button>
      </form>
    </AuthLayout>
  )
}
