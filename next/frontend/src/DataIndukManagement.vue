<script setup lang="ts">
import { onMounted, ref } from 'vue'

type State = { change_cursor: string; last_success_at: string | null; last_error_at: string | null; last_error: string; stale: boolean }
type Run = { id: number; scope: string; status: string; started_at: string; finished_at: string | null; stats: Record<string, number>; error_message: string }
type Conflict = { id: number; entity_type: string; master_id: string; local_id: number | null; reason: string; display_name: string; created_at: string }
type SnapshotCounts = { wali: number; anak: number; ustadz: number }
type Data = { configured: boolean; schema_ready: boolean; stale_after_minutes: number; state: State; runs: Run[]; conflicts: Conflict[]; snapshot_counts: SnapshotCounts }
type Envelope<T> = { status: string; data?: T; message?: string }

const props = defineProps<{ csrf: string }>()
const emit = defineEmits<{ unauthorized: [] }>()
const data = ref<Data | null>(null)
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const scope = ref('changes')

async function request<T>(url: string, init: RequestInit = {}) {
  const response = await fetch(url, { ...init, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', ...(init.body ? { 'Content-Type': 'application/json' } : {}), ...(init.method && init.method !== 'GET' ? { 'X-CSRF-Token': props.csrf } : {}), ...init.headers } })
  let body: Envelope<T>
  try { body = await response.json() as Envelope<T> } catch { throw new Error('Respons server tidak dapat dibaca.') }
  if (response.status === 401) { emit('unauthorized'); throw new Error('Sesi login berakhir. Masuk kembali.') }
  if (!response.ok || body.status !== 'ok' || body.data === undefined) throw new Error(body.message || 'Permintaan Data Induk belum berhasil.')
  return body.data
}

async function load() {
  loading.value = true; error.value = ''
  try { data.value = await request<Data>('/api/v1/data-induk') }
  catch (cause) { error.value = (cause as Error).message }
  finally { loading.value = false }
}

async function syncNow() {
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const result = await request<{ scope: string; stats: Record<string, number> }>('/api/v1/data-induk/sync', { method: 'POST', body: JSON.stringify({ scope: scope.value }) })
    notice.value = `Sinkronisasi ${result.scope} selesai: ${Object.entries(result.stats).map(([key, value]) => `${key} ${value}`).join(' · ')}`
    await load()
  } catch (cause) { error.value = (cause as Error).message; await load() }
  finally { busy.value = false }
}

async function resolve(conflict: Conflict, decision: 'approve' | 'ignore') {
  busy.value = true; error.value = ''; notice.value = ''
  try {
    await request(`/api/v1/data-induk/conflicts/${conflict.id}`, { method: 'POST', body: JSON.stringify({ decision }) })
    notice.value = decision === 'approve' ? 'Pemetaan disetujui. Jalankan sinkronisasi roster lagi untuk menerapkan profil pusat.' : 'Konflik diabaikan.'
    await load()
  } catch (cause) { error.value = (cause as Error).message }
  finally { busy.value = false }
}

function formatStats(stats: Record<string, number>) { return Object.entries(stats).map(([key, value]) => `${key}: ${value}`).join(' · ') || '—' }
onMounted(load)
</script>

<template>
  <main class="data-page">
    <header><p class="eyebrow">INTEGRASI ADMIN</p><h1>Status Data Induk</h1><p>Sinkronisasi memakai secret di server. Tidak ada proses sinkronisasi otomatis saat halaman dibuka.</p></header>
    <p v-if="error" class="alert error" role="alert">{{ error }}</p><p v-if="notice" class="alert success" role="status">{{ notice }}</p>
    <section v-if="loading && !data" class="panel">Memuat status Data Induk…</section>
    <template v-else-if="data">
      <p v-if="!data.schema_ready" class="alert warning">Migrasi aditif Data Induk belum tersedia. Jalankan pemeriksaan dan migrasi operator setelah backup sebelum menggunakan sinkronisasi.</p>
      <section class="panel controls">
        <label>Jenis sinkronisasi<select v-model="scope"><option value="changes">Perubahan incremental</option><option value="all">Penuh: referensi, roster, ustadz</option><option value="references">Referensi</option><option value="roster">Roster & keluarga</option><option value="teachers">Ustadz</option></select></label>
        <div><strong :class="data.configured ? 'good' : 'warn'">{{ data.configured ? 'Konfigurasi siap' : 'Integrasi belum aktif' }}</strong><small>Stale setelah {{ data.stale_after_minutes }} menit</small></div>
        <button class="primary" :disabled="busy || loading || !data.configured || !data.schema_ready" @click="syncNow">{{ busy ? 'Memproses…' : 'Sinkronkan sekarang' }}</button>
      </section>
      <section class="stats">
        <article class="panel"><small>Sinkronisasi terakhir</small><strong>{{ data.state.last_success_at || 'Belum pernah' }}</strong></article>
        <article class="panel"><small>Kondisi snapshot</small><strong :class="data.state.stale ? 'warn' : 'good'">{{ data.state.stale ? 'Perlu sinkronisasi' : 'Terkini' }}</strong></article>
        <article class="panel"><small>Terpetakan</small><strong>{{ data.snapshot_counts.wali }} wali · {{ data.snapshot_counts.anak }} anak · {{ data.snapshot_counts.ustadz }} ustadz</strong></article>
        <article class="panel"><small>Konflik tertunda</small><strong>{{ data.conflicts.length }}</strong></article>
      </section>
      <p v-if="data.state.last_error" class="alert warning"><strong>Error terakhir · {{ data.state.last_error_at || 'waktu tidak diketahui' }}</strong><br>{{ data.state.last_error }}</p>
      <section class="panel"><div class="section-title"><h2>Konflik pemetaan</h2><span>{{ data.conflicts.length }} tertunda</span></div>
        <p v-if="!data.conflicts.length" class="empty">Tidak ada konflik pemetaan.</p>
        <article v-for="conflict in data.conflicts" :key="conflict.id" class="conflict">
          <div><strong>{{ conflict.entity_type }} · {{ conflict.reason }}</strong><p>UUID pusat: <code>{{ conflict.master_id }}</code> · Kandidat lokal: {{ conflict.local_id ?? 'tidak ada' }}</p><small>{{ conflict.created_at }} · {{ conflict.display_name || 'Nama tidak tersedia' }}</small></div>
          <div class="actions"><button class="approve" :disabled="busy || !conflict.local_id" @click="resolve(conflict, 'approve')">Setujui</button><button class="secondary" :disabled="busy" @click="resolve(conflict, 'ignore')">Abaikan</button></div>
        </article>
      </section>
      <section class="panel"><div class="section-title"><h2>Riwayat sinkronisasi</h2><button class="secondary" :disabled="loading || busy" @click="load">{{ loading ? 'Memuat…' : 'Perbarui' }}</button></div>
        <p v-if="!data.runs.length" class="empty">Belum ada riwayat sinkronisasi.</p>
        <article v-for="run in data.runs" :key="run.id" class="run"><div><strong>{{ run.scope }} · {{ run.status }}</strong><p>{{ run.started_at }}<span v-if="run.finished_at"> → {{ run.finished_at }}</span></p><small>{{ formatStats(run.stats) }}</small><small v-if="run.error_message" class="warn">{{ run.error_message }}</small></div></article>
      </section>
    </template>
  </main>
</template>

<style scoped>
.data-page{max-width:1100px;margin:0 auto;display:grid;gap:14px;color:#17362b}.data-page header{padding:7px 2px}.eyebrow{margin:0;color:#648073;font-size:11px;font-weight:850;letter-spacing:.13em}h1{margin:5px 0;font-size:clamp(24px,5vw,34px)}header p:last-child{color:#687a73}.panel{padding:16px;border:1px solid #dce8e2;border-radius:16px;background:white}.controls{display:flex;align-items:end;gap:16px;flex-wrap:wrap}.controls label{display:grid;gap:5px;color:#62736d;font-size:12px;font-weight:750;min-width:230px;flex:1}.controls select{min-height:40px;padding:8px 10px;border:1px solid #d5e2dc;border-radius:9px;background:white;color:#17362b}.controls div{display:grid;gap:4px}.controls small,.panel small{color:#718078}.primary,.secondary,.approve{min-height:40px;padding:0 14px;border:0;border-radius:10px;font-weight:750;cursor:pointer}.primary{background:#176b4b;color:white}.secondary{border:1px solid #d5e2dc;background:white;color:#315545}.approve{background:#e9f7ef;color:#176b4b}.primary:disabled,.secondary:disabled,.approve:disabled{opacity:.5}.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}.stats article{display:grid;gap:6px}.stats strong{font-size:15px}.good{color:#176b4b}.warn{color:#9a6710}.alert{padding:12px 14px;border-radius:12px}.error{background:#fff0ee;color:#9b2c24}.success{background:#e9f7ef;color:#176b4b}.warning{background:#fff7df;color:#76571a}.section-title{display:flex;justify-content:space-between;align-items:center;gap:8px}.section-title h2{margin:0;font-size:17px}.section-title span{color:#718078;font-size:12px}.conflict,.run{padding:13px 0;border-top:1px solid #e6ede9;display:flex;justify-content:space-between;gap:16px}.conflict:first-of-type,.run:first-of-type{margin-top:8px}.conflict p,.run p{margin:6px 0;color:#60726a;font-size:13px}.conflict small,.run small{display:block}.actions{display:flex;gap:7px;align-items:center}.empty{padding:16px;text-align:center;color:#718078}code{overflow-wrap:anywhere;font-size:12px}@media(max-width:640px){.controls{align-items:stretch;flex-direction:column}.controls label{min-width:0}.controls button{width:100%}.conflict{flex-direction:column}.actions{width:100%}.actions button{flex:1}.run{flex-direction:column}}
</style>
