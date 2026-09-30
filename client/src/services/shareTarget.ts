const SHARE_CACHE = 'share-target'

export async function loadSharedFiles(): Promise<File[]> {
  if (!('caches' in window)) {
    return []
  }

  const cache = await caches.open(SHARE_CACHE)
  const keys = await cache.keys()
  const files: File[] = []

  for (const request of keys) {
    const response = await cache.match(request)
    if (!response) continue

    const blob = await response.blob()
    const encodedName = response.headers.get('X-File-Name')
    const name = encodedName
      ? decodeURIComponent(encodedName)
      : `shared-file-${files.length + 1}`
    const type = response.headers.get('Content-Type') || blob.type || 'application/octet-stream'
    files.push(new File([blob], name, { type }))
  }

  return files
}

export async function clearSharedFiles(): Promise<void> {
  if (!('caches' in window)) {
    return
  }
  await caches.delete(SHARE_CACHE)
}
