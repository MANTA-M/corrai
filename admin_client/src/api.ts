export interface School {
  id: string | null
  name: string
  created_at: string
}

export interface Teacher {
  id: string | null
  school_id: string
  email: string
  name: string
  role: string
  created_at: string
}

interface ApiMessage {
  level: string
  message: string
}

interface ApiErrorBody {
  messages?: ApiMessage[]
}

export function apiUrl(path: string): string {
  return `${import.meta.env.BASE_URL}api/${path}`
}

export async function apiJson<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(apiUrl(path), {
    ...init,
    headers: {
      'Content-Type': 'application/json',
      ...(init?.headers ?? {}),
    },
  })

  let data: (T & ApiErrorBody) | ApiErrorBody = {}
  try {
    data = (await response.json()) as T & ApiErrorBody
  } catch {
    data = {}
  }

  if (!response.ok) {
    const messages = data.messages
    const message =
      Array.isArray(messages) && messages.length > 0
        ? messages.map((item) => item.message).join(' ')
        : `Request failed (${response.status})`
    throw new Error(message)
  }

  return data as T
}

export function formatDate(value: string): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}
