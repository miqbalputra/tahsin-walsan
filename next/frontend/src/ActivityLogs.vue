<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

type Log = { id: number; created_at: string; user_id: number; username: string; action: string; description: string; ip_address: string }
type Result = { status: string; data: Log[]; meta: { page: number; limit: number; total: number }; message?: string }
const items = ref<Log[]>([])
const search = ref('')
const appliedSearch = ref('')
const page = ref(1)
const total = ref(0)
const busy = ref(false)
const error = ref('')
const pages = computed(() => Math.max(1, Math.ceil(total.value / 50)))
let requestID = 0

async function load(target = page.value) {
  const id = ++requestID
  busy.value = true
  error.value = ''
  try {
    const query = new URLSearchParams({ page: String(target), limit: '50', search: appliedSearch.value })
    const response = await fetch(`/api/v1/logs?${query}`, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
    const body = await response.json() as Result
    if (response.status === 401) throw new Error('Sesi login berakhir. Masuk kembali.')
    if (!response.ok || body.status !== 'ok') throw new Error(body.message || 'Log aktivitas belum dapat dibaca.')
    if (id !== requestID) return
    items.value = body.data
    total.value = body.meta.total
    page.value = body.meta.page
  } catch (e) { if (id === requestID) error.value = e instanceof Error ? e.message : 'Log aktivitas belum dapat dibaca.' }
  finally { if (id === requestID) busy.value = false }
}
function submit() { appliedSearch.value = search.value.trim(); page.value = 1; void load(1) }
function move(delta: number) { const target = Math.min(pages.value, Math.max(1, page.value + delta)); void load(target) }
onMounted(() => { void load(1) })
</script>

<template>
  <section class="logs-page"><header><div><span class="ops-kicker">KEAMANAN & AUDIT</span><h1>Log aktivitas</h1><p>Riwayat perubahan dan akses yang dicatat aplikasi.</p></div></header>
    <form class="logs-search" @submit.prevent="submit"><label>Cari pengguna, aksi, atau keterangan<input v-model="search" maxlength="100" placeholder="Contoh: UPDATE_USER"></label><button :disabled="busy">{{ busy ? 'Memuat…' : 'Cari' }}</button></form>
    <p v-if="error" class="logs-error" role="alert">{{ error }} <button @click="load()">Coba lagi</button></p>
    <div class="logs-meta"><span>{{ total.toLocaleString('id-ID') }} aktivitas</span><span>Halaman {{ page }} / {{ pages }}</span></div>
    <div class="logs-table-wrap"><table><thead><tr><th>Waktu</th><th>Pengguna</th><th>Aksi</th><th>Keterangan</th><th>IP</th></tr></thead><tbody><tr v-if="busy && !items.length"><td colspan="5">Memuat log…</td></tr><tr v-else-if="!items.length"><td colspan="5">Belum ada aktivitas yang cocok.</td></tr><tr v-for="item in items" :key="item.id"><td>{{ item.created_at }}</td><td>{{ item.username || `User #${item.user_id}` }}</td><td><span class="log-action">{{ item.action }}</span></td><td class="log-description">{{ item.description }}</td><td>{{ item.ip_address || '—' }}</td></tr></tbody></table></div>
    <div class="logs-pagination"><button :disabled="busy || page <= 1" @click="move(-1)">Sebelumnya</button><button :disabled="busy || page >= pages" @click="move(1)">Berikutnya</button></div>
  </section>
</template>

<style scoped>
.logs-page{max-width:1200px;margin:0 auto;display:grid;gap:14px;color:#172b42}.logs-page h1{font-size:28px;margin:7px 0}.logs-page header p{font-size:13px;color:#687c91;margin:0}.ops-kicker{font-size:10px;font-weight:850;letter-spacing:1.2px;color:#718399}.logs-search{display:flex;align-items:end;gap:10px;padding:15px;background:#fff;border:1px solid #e1e8f0;border-radius:14px}.logs-search label{display:grid;gap:6px;flex:1;font-size:12px;color:#61748a;font-weight:700}.logs-search input{width:100%;border:1px solid #cfdae6;border-radius:9px;padding:10px;font:inherit;color:#172b42}.logs-search button,.logs-pagination button{border:1px solid #d4deea;border-radius:9px;padding:10px 13px;background:#fff;color:#365671;font-weight:700}.logs-search button:disabled,.logs-pagination button:disabled{opacity:.5}.logs-error{padding:12px;background:#fff0ee;border-radius:10px;color:#9b3931;font-size:13px}.logs-error button{margin-left:8px}.logs-meta,.logs-pagination{display:flex;justify-content:space-between;gap:12px;color:#718399;font-size:12px}.logs-table-wrap{overflow:auto;background:#fff;border:1px solid #e1e8f0;border-radius:14px}.logs-table-wrap table{width:100%;border-collapse:collapse;font-size:12px}.logs-table-wrap th,.logs-table-wrap td{padding:11px 13px;text-align:left;vertical-align:top;border-bottom:1px solid #edf1f5;white-space:nowrap}.logs-table-wrap th{font-size:10px;color:#718399;text-transform:uppercase}.logs-table-wrap td[colspan]{padding:28px;text-align:center;color:#718399}.log-action{font-size:10px;font-weight:800;color:#345e89;background:#eef4fb;border-radius:7px;padding:5px 7px}.log-description{white-space:normal;min-width:230px;max-width:480px;overflow-wrap:anywhere}.logs-pagination{justify-content:flex-end}.logs-pagination button:first-child{margin-right:auto}@media(max-width:600px){.logs-search{align-items:stretch;flex-direction:column}.logs-search button{width:100%}}
</style>
