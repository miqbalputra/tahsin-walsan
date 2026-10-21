<script setup lang="ts">
import { onMounted, ref } from 'vue'

type Group = { id: number; nama_halaqoh: string; ustadz_id: number; nama_ustadz: string; program_code: 'bapak' | 'ibu'; total_member: number }
type Teacher = { id: number; nama_lengkap: string }
type GroupPayload = { groups: Group[]; teachers: Teacher[] }
type Envelope<T> = { status: string; data?: T; message?: string }

const props = defineProps<{ csrf: string }>()
const emit = defineEmits<{ unauthorized: [] }>()
const groups = ref<Group[]>([])
const teachers = ref<Teacher[]>([])
const loading = ref(true)
const saving = ref(false)
const editorOpen = ref(false)
const editingID = ref(0)
const name = ref('')
const teacherID = ref(0)
const program = ref<'bapak' | 'ibu'>('bapak')
const error = ref('')
const notice = ref('')

async function request<T>(url: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (options.body) headers.set('Content-Type', 'application/json')
  if (props.csrf && options.method && options.method !== 'GET') headers.set('X-CSRF-Token', props.csrf)
  const response = await fetch(url, { ...options, headers, credentials: 'same-origin', cache: 'no-store' })
  let body: Envelope<T>
  try { body = await response.json() as Envelope<T> } catch { throw new Error('Respons server tidak dapat dibaca.') }
  if (!response.ok || (body.status !== 'ok' && body.status !== 'success')) {
    if (response.status === 401) emit('unauthorized')
    throw new Error(body.message || 'Permintaan belum berhasil.')
  }
  return body.data as T
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const payload = await request<GroupPayload>('/api/v1/groups')
    groups.value = payload.groups
    teachers.value = payload.teachers
  } catch (e) { error.value = (e as Error).message }
  finally { loading.value = false }
}

function openNew() {
  editingID.value = 0
  name.value = ''
  teacherID.value = teachers.value[0]?.id || 0
  program.value = 'bapak'
  error.value = ''
  notice.value = ''
  editorOpen.value = true
}

function openEdit(group: Group) {
  editingID.value = group.id
  name.value = group.nama_halaqoh
  teacherID.value = group.ustadz_id
  program.value = group.program_code
  error.value = ''
  notice.value = ''
  editorOpen.value = true
}

async function save() {
  saving.value = true
  error.value = ''
  notice.value = ''
  try {
    await request<{ id: number }>(editingID.value ? `/api/v1/groups/${editingID.value}` : '/api/v1/groups', {
      method: editingID.value ? 'PATCH' : 'POST',
      body: JSON.stringify({ nama_halaqoh: name.value, ustadz_id: teacherID.value, program_code: program.value }),
    })
    editorOpen.value = false
    notice.value = editingID.value ? 'Kelompok diperbarui.' : 'Kelompok berhasil dibuat.'
    await load()
  } catch (e) { error.value = (e as Error).message }
  finally { saving.value = false }
}

onMounted(load)
</script>

<template>
  <section class="groups-page">
    <header class="page-heading">
      <p class="eyebrow">DATA INTI</p>
      <div class="groups-heading-row"><div><h1>Kelompok Tahsin</h1><p class="muted">Bapak dan Ibu dikelola terpisah agar riwayat sesi tetap sesuai program.</p></div><button class="primary" :disabled="loading || teachers.length === 0" @click="openNew">Tambah kelompok</button></div>
    </header>

    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-if="notice" class="notice" role="status">{{ notice }}</p>
    <div v-if="loading" class="panel" aria-live="polite">Memuat kelompok…</div>
    <div v-else-if="!groups.length" class="panel">Belum ada kelompok. Tambahkan kelompok untuk mulai menata roster.</div>
    <div v-else class="groups-grid">
      <article v-for="group in groups" :key="group.id" class="panel group-card">
        <div class="group-card-top"><span class="tag">Tahsin {{ group.program_code === 'ibu' ? 'Ibu' : 'Bapak' }}</span><button class="secondary" @click="openEdit(group)">Edit</button></div>
        <h2>{{ group.nama_halaqoh }}</h2>
        <p class="muted">Pengajar: {{ group.nama_ustadz || 'Belum ditentukan' }}</p>
        <p class="group-count">{{ group.total_member }} peserta aktif</p>
        <p v-if="group.total_member > 0" class="group-note">Program tidak dapat diganti jika kelompok ini memiliki riwayat atau draft.</p>
      </article>
    </div>

    <div v-if="!loading && !teachers.length" class="panel warning-panel">Belum ada akun guru Tahsin. Tambahkan akun guru sebelum membuat kelompok.</div>

    <div v-if="editorOpen" class="modal-backdrop" @click.self="editorOpen = false">
      <form class="group-editor panel" @submit.prevent="save">
        <div><p class="eyebrow">{{ editingID ? 'UBAH KELOMPOK' : 'KELOMPOK BARU' }}</p><h2>{{ editingID ? 'Edit kelompok' : 'Tambah kelompok' }}</h2></div>
        <label>Nama kelompok<input v-model.trim="name" required maxlength="100" autocomplete="off"></label>
        <label>Program<select v-model="program"><option value="bapak">Tahsin Bapak</option><option value="ibu">Tahsin Ibu</option></select></label>
        <label>Guru<select v-model.number="teacherID" required><option :value="0" disabled>Pilih guru</option><option v-for="teacher in teachers" :key="teacher.id" :value="teacher.id">{{ teacher.nama_lengkap }}</option></select></label>
        <p v-if="error" class="error" role="alert">{{ error }}</p>
        <p v-if="editingID" class="muted editor-note">Perubahan nama dan pengajar tidak mengubah histori presensi. Program dikunci jika sudah ada histori atau draft.</p>
        <div class="editor-actions"><button type="button" class="secondary" :disabled="saving" @click="editorOpen = false">Batal</button><button type="submit" class="primary" :disabled="saving || !name.trim() || !teacherID">{{ saving ? 'Menyimpan…' : 'Simpan' }}</button></div>
      </form>
    </div>
  </section>
</template>

<style scoped>
.groups-page{display:grid;gap:18px}.groups-heading-row{display:flex;align-items:end;justify-content:space-between;gap:16px}.groups-heading-row h1{margin-bottom:8px}.groups-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px}.group-card{display:grid;gap:10px}.group-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px}.group-card h2{font-size:18px;margin:6px 0 0}.group-card p{margin:0}.group-count{font-weight:700;color:#234b8c}.group-note,.editor-note{font-size:12px;line-height:1.5}.notice{background:#e8f7ee;border:1px solid #c5e8d2;color:#17633a;padding:12px 14px;border-radius:12px}.warning-panel{background:#fff7e7;color:#7a5719}.modal-backdrop{position:fixed;inset:0;z-index:30;background:#13243bb0;display:grid;place-items:center;padding:18px}.group-editor{width:min(100%,460px);display:grid;gap:15px}.group-editor h2{margin:0}.group-editor label{display:grid;gap:7px;font-size:13px;font-weight:700}.group-editor input,.group-editor select{width:100%;border:1px solid #cbd7e5;border-radius:9px;background:white;padding:11px 12px;font:inherit}.editor-actions{display:flex;justify-content:flex-end;gap:9px}.editor-note{margin:0}@media(max-width:600px){.groups-heading-row{align-items:stretch;flex-direction:column}.groups-heading-row .primary{width:100%}.group-editor{max-height:calc(100dvh - 36px);overflow:auto}}
</style>
