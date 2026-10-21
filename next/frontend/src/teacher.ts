import { createApp } from 'vue'
import Teacher from './Teacher.vue'

const root = document.getElementById('vue-teacher-root')
const data = document.getElementById('tahsin-autosave-data')
if (root && data) {
  try {
    const boot: unknown = JSON.parse(data.textContent || '')
    createApp(Teacher, { boot: boot as TeacherBoot }).mount(root)
  } catch {
    root.textContent = 'Tampilan belum dapat disiapkan. Gunakan tautan tampilan sebelumnya; draft di HP tetap tersedia.'
    root.setAttribute('aria-busy', 'false')
  }
}

export interface TeacherBoot {
  user_id: number
  halaqoh_id: number
  tanggal: string
  csrf_token: string
  api: string
  state: { program: string; active: boolean; session_date: string; entries: unknown[] }
  view: { title: string; group_name: string; today: string; session_label: string; surahs: string[]; members: Array<{ id: number; name: string; children: string; reference: string }> }
}

declare global {
  interface Window {
    TahsinAutosave?: { mountEditor(root: HTMLElement, boot: TeacherBoot): Promise<void> }
  }
}
