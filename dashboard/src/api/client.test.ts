import { http, HttpResponse } from 'msw'
import { describe, expect, it, vi } from 'vitest'

import { db, fakeUser, server } from '@/test/server'

import { api, ApiError, onUnauthorized } from './client'

describe('cliente da API', () => {
  it('obtém o cookie CSRF antes da primeira escrita e envia o token', async () => {
    db.user = fakeUser
    await api('/goals', { method: 'POST', body: { title: 'Ler', type: 'learning' } })

    const post = db.requests.find((r) => r.method === 'POST')
    expect(post?.path).toBe('/api/goals')
    expect(document.cookie).toContain('XSRF-TOKEN=')
  })

  it('renova o token e repete a requisição quando recebe 419', async () => {
    db.user = fakeUser
    document.cookie = 'XSRF-TOKEN=expirado; path=/'

    await expect(api('/goals', { method: 'POST', body: { title: 'Ler', type: 'learning' } })).resolves.toBeTruthy()
    expect(db.requests.filter((r) => r.path === '/api/goals')).toHaveLength(2)
  })

  it('expõe erros de validação por campo', async () => {
    db.user = fakeUser
    const error = await api<never>('/goals', { method: 'POST', body: { title: '' } }).catch((e: ApiError) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect(error.status).toBe(422)
    expect(error.field('title')).toBe('O título é obrigatório.')
  })

  it('expõe o motivo e o Retry-After quando a IA está indisponível', async () => {
    server.use(
      http.post('/api/nexo/messages', () =>
        HttpResponse.json({ message: 'Indisponível', reason: 'timeout' }, { status: 503, headers: { 'Retry-After': '30' } }),
      ),
    )
    const error = await api<never>('/nexo/messages', { method: 'POST', body: { message: 'oi' } }).catch((e: ApiError) => e)

    expect(error.status).toBe(503)
    expect(error.reason).toBe('timeout')
    expect(error.retryAfter).toBe(30)
  })

  it('avisa o app quando a sessão expira (401)', async () => {
    const handler = vi.fn()
    onUnauthorized(handler)

    await expect(api('/me')).rejects.toMatchObject({ status: 401 })
    expect(handler).toHaveBeenCalledOnce()
  })

  it('nunca guarda credenciais no armazenamento do navegador', async () => {
    db.user = fakeUser
    await api('/me')
    expect(localStorage.length).toBe(0)
    expect(sessionStorage.length).toBe(0)
  })
})
