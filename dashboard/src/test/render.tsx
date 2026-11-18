import { render } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { createQueryClient } from '@/api/queries'
import { App } from '@/App'

/** Renderiza o app inteiro (rotas, guardas e providers) numa rota inicial. */
export function renderApp(path = '/') {
  const queryClient = createQueryClient()
  queryClient.setDefaultOptions({ queries: { ...queryClient.getDefaultOptions().queries, retry: false } })
  const user = userEvent.setup()
  const utils = render(<App queryClient={queryClient} initialPath={path} />)
  return { ...utils, user, queryClient }
}
