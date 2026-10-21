<script setup lang="ts">
import { onMounted, ref } from 'vue'

type Announcement = { id: number; judul: string; isi: string; is_aktif: boolean; created_at: string }
type Envelope<T> = { status: string; data: T; message?: string }
const props = defineProps<{ csrf: string }>()
const emit = defineEmits<{ unauthorized: [] }>()
const items = ref<Announcement[]>([])
const title = ref('')
const bodyText = ref('')
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
  const result = await response.json() as Envelope<T>
  if (!response.ok || result.status !== 'ok') {
    if (response.status === 401) emit('unauthorized')
    throw new Error(result.message || 'Permintaan belum berhasil.')
  }
  return result.data
}

async function load() {
  loading.value = true
  error.value = ''
  try { items.value = await request<Announcement[]>('/api/v1/announcements') }
  catch (e) { error.value = e instanceof Error ? e.message : 'Riwayat pengumuman belum dapat dibaca.' }
  finally { loading.value = false }
}

async function publish() {
  saving.value = true
  error.value = ''
  notice.value = ''
  try {
    await request('/api/v1/announcements', { method: 'POST', body: JSON.stringify({ judul: title.value, isi: bodyText.value }) })
    title.value = ''
    bodyText.value = ''
    notice.value = 'Pengumuman telah disebarkan dan arsipnya tersimpan.'
    await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Pengumuman belum dapat disimpan.' }
  finally { saving.value = false }
}

async function toggle(item: Announcement) {
  error.value = ''
  notice.value = ''
  try {
    await request(`/api/v1/announcements/${item.id}`, { method: 'PATCH', body: JSON.stringify({ is_aktif: !item.is_aktif }) })
    notice.value = item.is_aktif ? 'Pengumuman diarsipkan; isi dan riwayatnya tetap tersimpan.' : 'Pengumuman diaktifkan kembali.'
    await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Status pengumuman belum dapat diubah.' }
}

onMounted(load)
</script>

<template>
  <div class="announcement-page"><header><div><span class="ops-kicker">INFORMASI GURU</span><h1>Pengumuman</h1><p>Pengumuman aktif ditampilkan di aplikasi guru. Mengarsipkan hanya menonaktifkan tampilannya dan mempertahankan isi.</p></div></header>
    <p v-if="notice" class="ops-success" role="status">{{ notice }}</p><p v-if="error" class="ops-error" role="alert">{{ error }} <button @click="load">Coba lagi</button></p>
    <form class="announcement-form" @submit.prevent="publish"><h2>Buat pengumuman</h2><label>Judul<input v-model="title" maxlength="200" required placeholder="Contoh: Perubahan jadwal pertemuan"></label><label>Isi pesan<textarea v-model="bodyText" maxlength="10000" rows="5" required placeholder="Tuliskan informasi untuk guru"></textarea></label><button class="ops-primary" :disabled="saving">{{ saving ? 'Menyimpan…' : 'Sebarkan pengumuman' }}</button></form>
    <section class="announcement-list"><div class="announcement-list-heading"><h2>Riwayat pengumuman</h2><span>Terbaru ditampilkan lebih dulu</span></div><p v-if="loading" class="ops-empty">Memuat riwayat…</p><p v-else-if="!items.length" class="ops-empty">Belum ada pengumuman.</p><article v-for="item in items" :key="item.id" class="announcement-card"><div class="announcement-top"><h3>{{ item.judul }}</h3><span :class="['announcement-status', item.is_aktif ? 'active' : 'archived']">{{ item.is_aktif ? 'Aktif' : 'Arsip' }}</span></div><p class="announcement-body">{{ item.isi }}</p><footer><time>{{ item.created_at }}</time><button :class="item.is_aktif ? 'ops-secondary' : 'ops-reactivate'" :disabled="loading" @click="toggle(item)">{{ item.is_aktif ? 'Arsipkan' : 'Aktifkan' }}</button></footer></article></section>
  </div>
</template>

<style scoped>
.announcement-page{max-width:900px;margin:0 auto;display:grid;gap:16px;color:#172b42}.ops-kicker{font-size:11px;font-weight:800;letter-spacing:1.3px;color:#6a7d92}.announcement-page h1{font-size:28px;margin:8px 0}.announcement-page header p{color:#65788e;font-size:13px;line-height:1.6}.ops-success,.ops-error,.ops-empty{margin:0;padding:13px 15px;border-radius:11px;font-size:14px}.ops-success{background:#e8f7ef;color:#176447}.ops-error{background:#fff0ee;color:#9b3931}.ops-error button{margin-left:8px}.ops-empty{background:#fff;border:1px solid #e1e8f0;color:#65788e;text-align:center;padding:28px}.announcement-form,.announcement-list{background:#fff;border:1px solid #e1e8f0;border-radius:14px;padding:18px}.announcement-form{display:grid;gap:12px}.announcement-form h2,.announcement-list-heading h2{margin:0;font-size:17px}.announcement-form label{display:grid;gap:6px;font-size:12px;font-weight:700;color:#556b80}.announcement-form input,.announcement-form textarea{width:100%;border:1px solid #cbd7e5;border-radius:9px;padding:10px;background:#fff;font:inherit;color:#172b42}.announcement-form textarea{resize:vertical}.ops-primary,.ops-secondary,.ops-reactivate{border:0;border-radius:9px;padding:10px 14px;font:inherit;font-weight:700;cursor:pointer}.ops-primary{background:#245ad1;color:#fff;justify-self:start}.ops-primary:disabled,.ops-secondary:disabled,.ops-reactivate:disabled{opacity:.55}.announcement-list{display:grid;gap:10px}.announcement-list-heading{display:flex;align-items:center;justify-content:space-between;gap:10px}.announcement-list-heading span{font-size:12px;color:#65788e}.announcement-card{border:1px solid #e7edf3;border-radius:11px;padding:14px}.announcement-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.announcement-top h3{margin:0;font-size:15px}.announcement-status{border-radius:20px;padding:4px 8px;font-size:10px;font-weight:800}.announcement-status.active{background:#e5f5ec;color:#18784d}.announcement-status.archived{background:#f0f2f5;color:#667486}.announcement-body{margin:10px 0;color:#52677d;font-size:13px;line-height:1.6;white-space:pre-line;overflow-wrap:anywhere}.announcement-card footer{display:flex;justify-content:space-between;align-items:center;gap:10px}.announcement-card time{font-size:11px;color:#8291a1}.ops-secondary,.ops-reactivate{border:1px solid #d2dce8;background:#fff;color:#47617c;padding:8px 11px}.ops-reactivate{color:#176447;background:#ecf8f1;border-color:#cfe9d8}
@media(max-width:700px){.announcement-list-heading{align-items:flex-start;flex-direction:column}.announcement-card{padding:12px}}
</style>
