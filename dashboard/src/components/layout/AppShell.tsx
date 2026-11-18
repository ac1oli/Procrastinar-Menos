import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  BrainIcon,
  CalendarClockIcon,
  HistoryIcon,
  LayoutDashboardIcon,
  LogOutIcon,
  MenuIcon,
  MessageCircleIcon,
  MonitorIcon,
  MoonIcon,
  ShieldCheckIcon,
  SunIcon,
  TargetIcon,
} from 'lucide-react'
import { useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router'
import { toast } from 'sonner'

import { auth, nexo, suggestions } from '@/api/endpoints'
import { errorMessage, keys } from '@/api/queries'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/display'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogTitle,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/overlays'
import { useMe } from '@/features/auth/session'
import { useTheme } from '@/lib/theme'
import { cn } from '@/lib/utils'

import { NexoWordmark } from './NexoMark'

const nav = [
  { to: '/', label: 'Painel', icon: LayoutDashboardIcon, end: true },
  { to: '/conversar', label: 'Conversar', icon: MessageCircleIcon },
  { to: '/historico', label: 'Histórico', icon: HistoryIcon },
  { to: '/memorias', label: 'Memórias', icon: BrainIcon, badge: 'suggestions' as const },
  { to: '/rotina', label: 'Rotina', icon: CalendarClockIcon },
  { to: '/objetivos', label: 'Objetivos', icon: TargetIcon },
  { to: '/privacidade', label: 'Privacidade', icon: ShieldCheckIcon },
]

function Navigation({ onNavigate }: { onNavigate?: () => void }) {
  const pending = useQuery({ queryKey: keys.suggestions, queryFn: suggestions.list, staleTime: 60_000 })

  return (
    <nav aria-label="Principal" className="grid gap-1">
      {nav.map(({ to, label, icon: Icon, end, badge }) => (
        <NavLink
          key={to}
          to={to}
          end={end}
          onClick={onNavigate}
          className={({ isActive }) =>
            cn(
              'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-sidebar-foreground/80 transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
              isActive && 'bg-sidebar-accent text-sidebar-accent-foreground',
            )
          }
        >
          <Icon className="size-4" />
          <span className="flex-1">{label}</span>
          {badge === 'suggestions' && !!pending.data?.length && (
            <Badge variant="accent" aria-label={`${pending.data.length} sugestões pendentes`}>
              {pending.data.length}
            </Badge>
          )}
        </NavLink>
      ))}
    </nav>
  )
}

function StatusChip() {
  const status = useQuery({ queryKey: keys.status, queryFn: nexo.status, refetchInterval: 60_000 })
  const available = status.data?.available
  return (
    <span className="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs text-muted-foreground" title="Disponibilidade da IA local">
      <span
        className={cn('size-2 rounded-full', available === undefined ? 'bg-muted-foreground/40' : available ? 'bg-emerald-500' : 'bg-amber-500')}
      />
      {available === undefined ? 'Verificando o NEXO…' : available ? 'NEXO disponível' : 'NEXO indisponível'}
    </span>
  )
}

function UserMenu() {
  const { data: me } = useMe()
  const { theme, setTheme } = useTheme()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const logout = useMutation({
    mutationFn: auth.logout,
    onSuccess: () => {
      queryClient.clear()
      queryClient.setQueryData(keys.me, null)
      navigate('/entrar', { replace: true })
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  const initials = (me?.name ?? '?')
    .split(/\s+/)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase())
    .join('')

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon" className="rounded-full" aria-label="Menu da conta">
          <span className="flex size-8 items-center justify-center rounded-full bg-nexo-gradient text-xs font-semibold text-white">{initials}</span>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-60">
        <DropdownMenuLabel>
          <span className="block truncate">{me?.name}</span>
          <span className="block truncate text-xs font-normal text-muted-foreground">{me?.email}</span>
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">Tema</DropdownMenuLabel>
        {(
          [
            ['light', 'Claro', SunIcon],
            ['dark', 'Escuro', MoonIcon],
            ['system', 'Do sistema', MonitorIcon],
          ] as const
        ).map(([value, label, Icon]) => (
          <DropdownMenuItem key={value} onSelect={() => setTheme(value)} aria-checked={theme === value} role="menuitemradio">
            <Icon /> {label} {theme === value && <span className="ml-auto text-xs text-muted-foreground">atual</span>}
          </DropdownMenuItem>
        ))}
        <DropdownMenuSeparator />
        <DropdownMenuItem onSelect={() => logout.mutate()} disabled={logout.isPending}>
          <LogOutIcon /> Sair
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

export function AppShell() {
  const [menuOpen, setMenuOpen] = useState(false)

  return (
    <div className="flex min-h-dvh">
      <aside className="sticky top-0 hidden h-dvh w-64 shrink-0 flex-col gap-6 border-r bg-sidebar p-4 lg:flex">
        <NexoWordmark />
        <Navigation />
      </aside>

      <Dialog open={menuOpen} onOpenChange={setMenuOpen}>
        <DialogContent side="left" aria-describedby={undefined}>
          <DialogTitle className="sr-only">Menu</DialogTitle>
          <DialogDescription className="sr-only">Navegação principal</DialogDescription>
          <NexoWordmark />
          <Navigation onNavigate={() => setMenuOpen(false)} />
        </DialogContent>
      </Dialog>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-30 flex h-14 items-center gap-3 border-b bg-background/85 px-4 backdrop-blur lg:px-8">
          <Button variant="ghost" size="icon" className="lg:hidden" onClick={() => setMenuOpen(true)} aria-label="Abrir menu">
            <MenuIcon />
          </Button>
          <div className="lg:hidden">
            <NexoWordmark className="[&_span_span:last-child]:hidden" />
          </div>
          <div className="ml-auto flex items-center gap-3">
            <StatusChip />
            <UserMenu />
          </div>
        </header>
        <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-6 lg:px-8 lg:py-8">
          <Outlet />
        </main>
      </div>
    </div>
  )
}

export function PageHeader({ title, description, actions }: { title: string; description?: string; actions?: React.ReactNode }) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div className="grid gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
        {description && <p className="max-w-2xl text-sm text-muted-foreground">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </div>
  )
}
