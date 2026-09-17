/**
 * API istemcisi. Web panelinde kimlik doğrulama httpOnly oturum çereziyle yapılır;
 * CSRF jetonu Laravel'in XSRF-TOKEN çerezinden okunup başlığa eklenir.
 */

export class ApiError extends Error {
  status: number
  code: string
  errors: Record<string, string[]>
  context: Record<string, unknown>

  constructor(status: number, body: Partial<{ message: string; error_code: string; errors: Record<string, string[]>; context: Record<string, unknown> }>) {
    super(body.message || 'İşlem sırasında bir sorun oluştu. Lütfen tekrar deneyin.')
    this.status = status
    this.code = body.error_code || 'unknown'
    this.errors = body.errors || {}
    this.context = body.context || {}
  }

  /** İlk alan hatası ya da genel mesaj */
  firstError(): string {
    const first = Object.values(this.errors)[0]?.[0]
    return first || this.message
  }
}

export type ListMeta = { page: number; per_page: number; total: number; last_page: number; [k: string]: unknown }
export type Paginated<T> = { data: T[]; meta: ListMeta }

const BASE = '/api/v1'

/** Masaüstü yerel kurulumu mu (KURS_NODE=local)? Sayfa kabuğundaki meta etiketinden okunur. */
export function isLocalNode(): boolean {
  return typeof document !== 'undefined' && document.querySelector('meta[name="kurs-node"]')?.getAttribute('content') === 'local'
}

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[-.]/g, '\\$&') + '=([^;]*)'))
  return match ? decodeURIComponent(match[1]!) : null
}

let onUnauthorized: (() => void) | null = null
export function setUnauthorizedHandler(fn: () => void) {
  onUnauthorized = fn
}

type Query = Record<string, string | number | boolean | null | undefined | (string | number)[]>

export function buildQuery(params?: Query): string {
  if (!params) return ''
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue
    if (Array.isArray(value)) value.forEach((v) => search.append(`${key}[]`, String(v)))
    else search.set(key, String(value))
  }
  const s = search.toString()
  return s ? `?${s}` : ''
}

async function request<T>(method: string, path: string, body?: unknown, query?: Query, retried = false): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  const xsrf = readCookie('XSRF-TOKEN')
  if (xsrf) headers['X-XSRF-TOKEN'] = xsrf

  let payload: BodyInit | undefined
  if (body instanceof FormData) {
    payload = body
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    payload = JSON.stringify(body)
  }

  let response: Response
  try {
    response = await fetch(BASE + path + buildQuery(query), { method, headers, body: payload, credentials: 'same-origin' })
  } catch {
    // Yerel kurulumda (masaüstü) istek bilgisayarın kendi sunucusuna gider: internetle ilgisi yoktur.
    throw new ApiError(0, {
      message: isLocalNode()
        ? 'Uygulamanın yerel sunucusuna ulaşılamadı. Uygulamayı kapatıp yeniden açın; sorun sürerse menüden Günlükler klasörünü gönderin.'
        : 'Sunucuya ulaşılamadı. İnternet bağlantınızı kontrol edin.',
      error_code: 'network',
    })
  }

  if (response.status === 419 && !retried) {
    await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' })
    return request<T>(method, path, body, query, true)
  }

  if (response.status === 204) return undefined as T

  const text = await response.text()
  let data: any = {}
  try {
    data = text ? JSON.parse(text) : {}
  } catch {
    data = {}
  }

  if (!response.ok) {
    if (response.status === 401 && onUnauthorized && !path.startsWith('/auth/login')) onUnauthorized()
    throw new ApiError(response.status, data)
  }

  return data as T
}

export const api = {
  get: <T>(path: string, query?: Query) => request<T>('GET', path, undefined, query),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, body ?? {}),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', path, body ?? {}),
  patch: <T>(path: string, body?: unknown) => request<T>('PATCH', path, body ?? {}),
  delete: <T>(path: string, body?: unknown) => request<T>('DELETE', path, body),

  /** Dosya indirme (PDF/XLSX/CSV). Sunucunun verdiği dosya adı korunur. */
  async download(path: string, query?: Query, fallbackName = 'dosya') {
    const response = await fetch(BASE + path + buildQuery(query), { credentials: 'same-origin', headers: { Accept: '*/*' } })
    if (!response.ok) {
      let data = {}
      try {
        data = await response.json()
      } catch {
        /* ikili yanıt */
      }
      throw new ApiError(response.status, data)
    }
    const disposition = response.headers.get('Content-Disposition') || ''
    const match = disposition.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i)
    const name = match ? decodeURIComponent(match[1]!) : fallbackName
    const blob = await response.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = name
    document.body.appendChild(a)
    a.click()
    a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 2000)
  },
}

/** Tekrarlanan gönderimde çift kayıt oluşmasın diye (ör. tahsilat) istemci anahtarı */
export function idempotencyKey(): string {
  return crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`
}
