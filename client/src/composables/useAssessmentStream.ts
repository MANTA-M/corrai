import { onUnmounted, watch, type ComputedRef, type Ref } from 'vue'
import type { AssessmentStreamEvent } from '@/utils/assessmentStream'

/**
 * Keep an open assessment or student page in sync through Nchan.
 * The assessment page listens on assessment:{id}. The student page listens on student:{id}.
 */
export function useAssessmentStream(options: {
  assessmentId: ComputedRef<string>
  studentId?: ComputedRef<string>
  locale: Ref<string> | ComputedRef<string>
  enabled: ComputedRef<boolean>
  onEvent: (event: AssessmentStreamEvent) => void
}) {
  let controller: AbortController | null = null

  const stop = () => {
    controller?.abort()
    controller = null
  }

  const sleep = (ms: number, signal: AbortSignal) =>
    new Promise<void>((resolve) => {
      const timer = window.setTimeout(resolve, ms)
      signal.addEventListener(
        'abort',
        () => {
          window.clearTimeout(timer)
          resolve()
        },
        { once: true },
      )
    })

  const applyBlock = (block: string) => {
    const data = block
      .split('\n')
      .filter((line) => line.startsWith('data:'))
      .map((line) => line.slice(5).trimStart())
      .join('\n')
    if (!data) return
    try {
      options.onEvent(JSON.parse(data) as AssessmentStreamEvent)
    } catch (error) {
      console.error('Invalid assessment event', error)
    }
  }

  const streamUrl = (): string | null => {
    const base = import.meta.env.BASE_URL.replace(/\/?$/, '/')
    const studentId = options.studentId?.value
    if (studentId) return `${base}events-api/student:${studentId}`
    const assessmentId = options.assessmentId.value
    if (!assessmentId) return null
    return `${base}events-api/assessment:${assessmentId}`
  }

  const readStream = async (signal: AbortSignal): Promise<'stop' | 'retry'> => {
    const url = streamUrl()
    if (!url) return 'stop'
    const headers = new Headers({ Accept: 'text/event-stream' })

    const response = await fetch(url, {
      method: 'GET',
      headers,
      signal,
    })
    if (response.status === 401 || response.status === 403 || response.status === 404) return 'stop'
    if (!response.ok || !response.body) {
      throw new Error('Assessment event stream failed')
    }

    const reader = response.body.getReader()
    const decoder = new TextDecoder()
    let buffer = ''
    while (!signal.aborted) {
      const { value, done } = await reader.read()
      if (done) break
      buffer += decoder.decode(value, { stream: true })
      const blocks = buffer.split('\n\n')
      buffer = blocks.pop() ?? ''
      for (const block of blocks) applyBlock(block)
    }
    return 'retry'
  }

  const run = async (signal: AbortSignal) => {
    let delay = 1000
    while (!signal.aborted && options.enabled.value) {
      try {
        const outcome = await readStream(signal)
        if (signal.aborted || outcome === 'stop') return
        delay = 1000
      } catch (error) {
        if (signal.aborted) return
        console.error('Assessment event stream interrupted', error)
      }
      await sleep(delay, signal)
      delay = Math.min(delay * 2, 10000)
    }
  }

  const start = () => {
    stop()
    if (!options.enabled.value || !options.assessmentId.value) return
    controller = new AbortController()
    void run(controller.signal)
  }

  watch(
    () =>
      [
        options.assessmentId.value,
        options.studentId?.value ?? '',
        options.locale.value,
        options.enabled.value,
      ] as const,
    start,
    { immediate: true },
  )

  onUnmounted(stop)
}
