import type { TeacherBoot } from './teacher'

declare global {
  interface Window {
    TahsinAutosave?: { mountEditor(root: HTMLElement, boot: TeacherBoot): Promise<void> }
  }
}

let loadPromise: Promise<void> | null = null

export function loadLegacyAutosave(): Promise<void> {
  if (window.TahsinAutosave) return Promise.resolve()
  if (loadPromise) return loadPromise
  loadPromise = new Promise((resolve, reject) => {
    const script = document.createElement('script')
    script.src = '/assets/js/tahsin-autosave.js'
    script.async = true
    script.onload = () => window.TahsinAutosave ? resolve() : reject(new Error('Mesin autosave belum tersedia.'))
    script.onerror = () => reject(new Error('Mesin autosave tidak dapat dimuat. Perubahan lokal tetap tidak dihapus.'))
    document.head.append(script)
  })
  return loadPromise
}
