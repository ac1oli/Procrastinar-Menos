/**
 * Faz o Node se comportar como o navegador atrás do proxy do Vite: URLs relativas
 * vão para o backend, cookies (sessão HttpOnly e XSRF-TOKEN) são guardados e
 * reenviados, e a origem é a do dashboard (localhost:5173), que o Sanctum trata
 * como primeira parte. Assim o contrato testado é exatamente o do app real.
 */
const jar = new Map<string, string>()

export function installBrowserLikeFetch(baseUrl: string, origin = 'http://localhost:5173') {
  const realFetch = globalThis.fetch

  Object.defineProperty(globalThis, 'document', {
    configurable: true,
    value: {
      get cookie() {
        return [...jar].map(([k, v]) => `${k}=${v}`).join('; ')
      },
      set cookie(_value: string) {
        // O código do app não grava cookies; nada a fazer.
      },
    },
  })

  globalThis.fetch = async (input: RequestInfo | URL, init: RequestInit = {}) => {
    const url = typeof input === 'string' && input.startsWith('/') ? `${baseUrl}${input}` : input
    const headers = new Headers(init.headers)
    if (jar.size) headers.set('Cookie', [...jar].map(([k, v]) => `${k}=${v}`).join('; '))
    headers.set('Origin', origin)
    headers.set('Referer', `${origin}/`)

    const response = await realFetch(url, { ...init, headers, credentials: undefined })
    for (const line of response.headers.getSetCookie()) {
      const [pair, ...attrs] = line.split(';')
      const index = pair.indexOf('=')
      const name = pair.slice(0, index).trim()
      const value = pair.slice(index + 1).trim()
      const expired = attrs.some((a) => /max-age=0|expires=thu, 01 jan 1970/i.test(a.trim()))
      if (expired || value === '') jar.delete(name)
      else jar.set(name, value)
    }
    return response
  }
}

export function clearCookies() {
  jar.clear()
}
