import { QueryClientProvider, type QueryClient } from '@tanstack/react-query'
import { lazy, Suspense, useEffect, type ComponentType } from 'react'
import { BrowserRouter, MemoryRouter, useRoutes, type RouteObject } from 'react-router'
import { Toaster } from 'sonner'

import { onUnauthorized } from '@/api/client'
import { keys } from '@/api/queries'
import { AppShell } from '@/components/layout/AppShell'
import { LoginPage, RegisterPage } from '@/features/auth/AuthPages'
import { PublicOnly, RequireAuth, RequireOnboarding } from '@/features/auth/guards'
import { FullScreenStatus } from '@/features/auth/guards'
import { ThemeProvider } from '@/lib/theme'

// Cada página vira um pedaço separado do bundle, carregado ao navegar.
// Se o trecho sumiu do servidor (publicação nova), main.tsx recarrega a página e o import
// resolve sem módulo: a tela fica no carregamento até o recarregamento, sem erro de script.
const page = <K extends string>(load: () => Promise<Record<K, ComponentType> | undefined>, name: K) =>
  lazy(() => load().then((m) => (m ? { default: m[name] } : new Promise<never>(() => {}))))

const ChatPage = page(() => import('@/features/chat/ChatPage'), 'ChatPage')
const ConversationPage = page(() => import('@/features/history/HistoryPages'), 'ConversationPage')
const HistoryPage = page(() => import('@/features/history/HistoryPages'), 'HistoryPage')
const HomePage = page(() => import('@/features/home/HomePage'), 'HomePage')
const MemoriesPage = page(() => import('@/features/memories/MemoriesPage'), 'MemoriesPage')
const OnboardingPage = page(() => import('@/features/onboarding/OnboardingPage'), 'OnboardingPage')
const GoalsPage = page(() => import('@/features/planning/GoalsPage'), 'GoalsPage')
const RoutinePage = page(() => import('@/features/planning/RoutinePage'), 'RoutinePage')
const PrivacyPage = page(() => import('@/features/privacy/PrivacyPage'), 'PrivacyPage')

export const routes: RouteObject[] = [
  { path: '/entrar', element: <PublicOnly><LoginPage /></PublicOnly> },
  { path: '/criar-conta', element: <PublicOnly><RegisterPage /></PublicOnly> },
  { path: '/boas-vindas', element: <RequireAuth><OnboardingPage /></RequireAuth> },
  {
    path: '/',
    element: (
      <RequireAuth>
        <RequireOnboarding>
          <AppShell />
        </RequireOnboarding>
      </RequireAuth>
    ),
    children: [
      { index: true, element: <HomePage /> },
      { path: 'conversar', element: <ChatPage /> },
      { path: 'historico', element: <HistoryPage /> },
      { path: 'historico/:id', element: <ConversationPage /> },
      { path: 'memorias', element: <MemoriesPage /> },
      { path: 'rotina', element: <RoutinePage /> },
      { path: 'objetivos', element: <GoalsPage /> },
      { path: 'privacidade', element: <PrivacyPage /> },
      { path: '*', element: <p className="text-muted-foreground">Página não encontrada.</p> },
    ],
  },
]

function AppRoutes() {
  return <Suspense fallback={<FullScreenStatus>Carregando…</FullScreenStatus>}>{useRoutes(routes)}</Suspense>
}

/** Sem loaders/actions: o roteador declarativo basta e os dados ficam no TanStack Query. */
export function App({ queryClient, initialPath }: { queryClient: QueryClient; initialPath?: string }) {
  // Sessão expirada em qualquer requisição: volta para a tela de entrada.
  useEffect(() => onUnauthorized(() => queryClient.setQueryData(keys.me, null)), [queryClient])

  const content = (
    <>
      <AppRoutes />
      <Toaster richColors position="top-right" closeButton />
    </>
  )

  return (
    <ThemeProvider>
      <QueryClientProvider client={queryClient}>
        {initialPath ? <MemoryRouter initialEntries={[initialPath]}>{content}</MemoryRouter> : <BrowserRouter>{content}</BrowserRouter>}
      </QueryClientProvider>
    </ThemeProvider>
  )
}
