<script setup lang="ts">
import { onMounted, ref } from 'vue'

type Holiday = { id: number; tanggal: string; keterangan: string }
type Envelope<T> = { status: string; data: T; message?: string }
const props = defineProps<{ csrf: string }>()
const emit = defineEmits<{ unauthorized: [] }>()
const holidays = ref<Holiday[]>([])
const date = ref(today())
const description = ref('')
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')

async function request<T>(url: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (options.body) headers.set('Content-Type', 'application/json')
  if (options.method && options.method !== 'GET') headers.set('X-CSRF-Token', props.csrf)
  const response = await fetch(url, { ...options, headers, credentials: 'same-origin', cache: 'no-store' })
  const body = await response.json() as Envelope<T>
  if (!response.ok || body.status !== 'ok') {
    if (response.status === 401) emit('unauthorized')
    throw new Error(body.message || 'Permintaan belum berhasil.')
  }
  return body.data
}

async function load() {
  loading.value = true
  error.value = ''
  try { holidays.value = await request<Holiday[]>('/api/v1/holidays') }
  catch (e) { error.value = e instanceof Error ? e.message : 'Hari libur belum dapat dibaca.' }
  finally { loading.value = false }
}

async function save() {
  saving.value = true
  error.value = ''
  notice.value = ''
  try {
    await request('/api/v1/holidays', { method: 'POST', body: JSON.stringify({ tanggal: date.value, keterangan: description.value }) })
    notice.value = 'Hari libur tersimpan. Jika tanggal sudah ada, keterangannya diperbarui.'
    description.value = ''
    await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Hari libur belum dapat disimpan.' }
  finally { saving.value = false }
}

async function remove(item: Holiday) {
  if (!window.confirm(`Hapus penanda libur ${item.tanggal}? Catatan presensi yang sudah ada tetap dipertahankan.`)) return
  error.value = ''
  notice.value = ''
  try {
    await request(`/api/v1/holidays/${item.id}`, { method: 'DELETE' })
    notice.value = 'Penanda libur dihapus. Catatan presensi tidak dihapus.'
    await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Hari libur belum dapat dihapus.' }
}

function today() { return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date()) }
function weekday(value: string) { return new Intl.DateTimeFormat('id-ID', { weekday: 'long', timeZone: 'Asia/Jakarta' }).format(new Date(`${value}T12:00:00+07:00`)) }
onMounted(load)
</script>

<template>
  <div class="holiday-page"><header><div><span class="ops-kicker">JADWAL TAHSIN</span><h1>Hari libur</h1><p>Tanggal libur tidak dihitung sebagai sesi aktif. Menghapus penanda tidak menghapus catatan presensi.</p></div></header>
    <p v-if="notice" class="ops-success" role="status">{{ notice }}</p><p v-if="error" class="ops-error" role="alert">{{ error }} <button @click="load">Coba lagi</button></p>
    <form class="holiday-form" @submit.prevent="save"><h2>Tambah atau perbarui hari libur</h2><label>Tanggal<input v-model="date" type="date" required></label><label>Keterangan (opsional)<input v-model="description" maxlength="255" placeholder="Contoh: Libur Idulfitri"></label><button class="ops-primary" :disabled="saving">{{ saving ? 'Menyimpan…' : 'Simpan hari libur' }}</button></form>
    <section class="holiday-list"><div class="holiday-list-heading"><h2>Daftar libur</h2><span>{{ holidays.length }} tanggal</span></div><p v-if="loading" class="ops-empty">Memuat hari libur…</p><p v-else-if="!holidays.length" class="ops-empty">Belum ada hari libur tercatat.</p><article v-for="item in holidays" :key="item.id" class="holiday-card"><div><strong>{{ item.tanggal }}</strong><span class="holiday-day">{{ weekday(item.tanggal) }}</span><p>{{ item.keterangan || 'Tanpa keterangan' }}</p></div><button class="ops-danger" :disabled="loading" @click="remove(item)">Hapus penanda</button></article></section>
  </div>
</template>

<style scoped>
.holiday-page{max-width:900px;margin:0 auto;display:grid;gap:16px;color:#172b42}.ops-kicker{font-size:11px;font-weight:800;letter-spacing:1.3px;color:#6a7d92}.holiday-page h1{font-size:28px;margin:8px 0}.holiday-page header p{color:#65788e;font-size:13px;line-height:1.6}.ops-success,.ops-error,.ops-empty{margin:0;padding:13px 15px;border-radius:11px;font-size:14px}.ops-success{background:#e8f7ef;color:#176447}.ops-error{background:#fff0ee;color:#9b3931}.ops-empty{background:#fff;border:1px solid #e1e8f0;color:#65788e;text-align:center;padding:28px}.ops-primary,.ops-danger{border:0;border-radius:9px;padding:10px 14px;font:inherit;font-weight:700;cursor:pointer}.ops-primary{background:#245ad1;color:#fff}.ops-primary:disabled,.ops-danger:disabled{opacity:.55}.holiday-form,.holiday-list{background:#fff;border:1px solid #e1e8f0;border-radius:14px;padding:18px}.holiday-form{display:grid;grid-template-columns:1fr 1fr auto;align-items:end;gap:12px}.holiday-form h2{grid-column:1/-1;margin:0;font-size:16px}.holiday-form label{display:grid;gap:6px;font-size:12px;font-weight:700;color:#556b80}.holiday-form input{width:100%;border:1px solid #cbd7e5;border-radius:9px;padding:10px;background:#fff;font:inherit;color:#172b42}.holiday-list{display:grid;gap:10px}.holiday-list-heading{display:flex;align-items:center;justify-content:space-between}.holiday-list-heading h2{margin:0;font-size:17px}.holiday-list-heading span{font-size:12px;color:#65788e}.holiday-card{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:13px;border:1px solid #e7edf3;border-radius:11px}.holiday-card strong{font-size:14px}.holiday-day{margin-left:10px;background:#f1f4f8;border-radius:20px;padding:4px 8px;font-size:11px;color:#60748a}.holiday-card p{margin:7px 0 0;color:#65788e;font-size:13px}.ops-danger{background:#fff0ee;color:#9a3d35}.ops-error button{margin-left:8px}@media(max-width:700px){.holiday-form{grid-template-columns:1fr}.holiday-form h2{grid-column:auto}.holiday-card{align-items:flex-start;flex-direction:column}.ops-danger{width:100%}}
</style>
