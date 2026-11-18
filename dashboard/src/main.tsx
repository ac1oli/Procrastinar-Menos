import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'

import { createQueryClient } from '@/api/queries'

import { App } from './App'
import './index.css'

const queryClient = createQueryClient()

// Depois de uma publicação nova, os arquivos da versão anterior podem sumir. Se um trecho
// preguiçoso falhar ao carregar, recarrega para buscar o index.html da versão nova. Só uma
// vez por carregamento (sem laço se o servidor estiver fora do ar).
let reloadedForNewVersion = false
window.addEventListener('vite:preloadError', (event) => {
  if (reloadedForNewVersion) return
  reloadedForNewVersion = true
  event.preventDefault()
  window.location.reload()
})

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App queryClient={queryClient} />
  </StrictMode>,
)
