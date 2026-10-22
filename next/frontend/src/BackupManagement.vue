<script setup lang="ts">
import { onMounted, ref } from 'vue'

type BackupFile = { name: string; size: number; created_at: string; kind: 'backup' | 'safety' }
type PreviewRow = { table: string; zip_rows: number | null; db_rows: number; cols_ok: boolean; primary_key_ok: boolean; checksum_ok: boolean; missing: string[]; note: string }
type RestorePreview = { rows: PreviewRow[]; total_zip: number; total_db: number; summary: string; can_restore: boolean }
type Envelope<T> = { status: string; data?: T; message?: string }

const props = defineProps<{ csrf: string }>()
const emit = defineEmits<{ unauthorized: [] }>()
const files = ref<BackupFile[]>([])
const preview = ref<RestorePreview | null>(null)
const restoreToken = ref('')
const confirmText = ref('')
const selectedFile = ref<File | null>(null)
const busy = ref(false)
const error = ref('')
const notice = ref('')

async function request<T>(url: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (props.csrf && options.method && options.method !== 'GET') headers.set('X-CSRF-Token', props.csrf)
  const response = await fetch(url, { ...options, headers, credentials: 'same-origin', cache: 'no-store' })
  let body: Envelope<T>
  try { body = await response.json() as Envelope<T> } catch { throw new Error('Respons server tidak dapat dibaca.') }
  if (!response.ok || (body.status !== 'ok' && body.status !== 'success')) {
    if (response.status === 401) emit('unauthorized')
    const failure = new Error(body.message || 'Permintaan belum berhasil.') as Error & { status?: number }
    failure.status = response.status
    throw failure
  }
  return body.data as T
}

async function loadFiles() {
  try { files.value = await request<BackupFile[]>('/api/v1/backups') }
  catch (cause) { error.value = (cause as Error).message }
}

async function createBackup() {
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const result = await request<{ name: string; tables: number }>('/api/v1/backups', { method: 'POST', body: '{}' })
    notice.value = `Backup ${result.name} berhasil dibuat dari ${result.tables} tabel.`
    await loadFiles()
  } catch (cause) { error.value = (cause as Error).message }
  finally { busy.value = false }
}

async function previewRestore() {
  if (!selectedFile.value) { error.value = 'Pilih file ZIP backup terlebih dahulu.'; return }
  busy.value = true; error.value = ''; notice.value = ''; preview.value = null; restoreToken.value = ''; confirmText.value = ''
  const form = new FormData()
  form.set('backup_file', selectedFile.value)
  try {
    const result = await request<{ token: string; preview: RestorePreview }>('/api/v1/backups/restore-preview', { method: 'POST', body: form })
    preview.value = result.preview
    restoreToken.value = result.token
  } catch (cause) { error.value = (cause as Error).message }
  finally { busy.value = false }
}

async function executeRestore() {
  if (!preview.value?.can_restore || confirmText.value !== 'RESTORE') return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const result = await request<{ safety_backup: string; inserted: Record<string, number>; conflicts: Record<string, number>; skipped: Record<string, string> }>('/api/v1/backups/restore', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ restore_token: restoreToken.value, confirm_text: confirmText.value }),
    })
    preview.value = null; restoreToken.value = ''; confirmText.value = ''; selectedFile.value = null
    notice.value = `Merge selesai. Backup keselamatan: ${result.safety_backup}. ID yang sudah ada tetap dipertahankan.`
    await loadFiles()
  } catch (cause) { error.value = (cause as Error).message }
  finally { busy.value = false }
}

function formatSize(bytes: number) {
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

onMounted(loadFiles)
</script>

<template>
  <div class="backup-page">
    <header class="heading"><p class="eyebrow">ADMINISTRASI DATA</p><h1>Backup &amp; Restore</h1><p>Backup memuat semua tabel database. Restore hanya menambahkan ID yang belum ada; baris lama tidak diubah atau dihapus.</p></header>
    <p v-if="error" class="alert error" role="alert">{{ error }}</p>
    <p v-if="notice" class="alert success" role="status">{{ notice }}</p>

    <section class="panel">
      <div class="panel-title"><div><h2>Buat backup sekarang</h2><p>Arsip disimpan di volume persisten server dengan akses terbatas.</p></div><button class="primary" :disabled="busy" @click="createBackup">{{ busy ? 'Memproses…' : 'Buat backup' }}</button></div>
      <p class="caution">Backup di home server melindungi dari kesalahan aplikasi, tetapi belum menggantikan salinan independen di perangkat atau lokasi lain.</p>
    </section>

    <section class="panel">
      <h2>Pratinjau restore</h2><p>Unggah ZIP, periksa tabel dan jumlah baris, lalu ketik <strong>RESTORE</strong>. Sebelum merge, aplikasi membuat safety backup.</p>
      <div class="upload-row"><input type="file" accept=".zip,application/zip" :disabled="busy" @change="selectedFile = ($event.target as HTMLInputElement).files?.[0] || null"><button class="secondary" :disabled="busy || !selectedFile" @click="previewRestore">{{ busy ? 'Memeriksa…' : 'Periksa file ZIP' }}</button></div>
      <div v-if="preview" class="preview-box">
        <div class="preview-summary"><strong>{{ preview.summary }}</strong><span>{{ preview.total_zip.toLocaleString('id-ID') }} baris dalam arsip · {{ preview.total_db.toLocaleString('id-ID') }} baris di database</span></div>
        <div class="table-wrap"><table><thead><tr><th>Tabel</th><th>Arsip</th><th>Database</th><th>Pemeriksaan</th></tr></thead><tbody>
          <tr v-for="row in preview.rows" :key="row.table"><td>{{ row.table }}</td><td>{{ row.zip_rows ?? '—' }}</td><td>{{ row.db_rows }}</td><td><span v-if="row.note && row.cols_ok && row.primary_key_ok" class="muted-note">{{ row.note }}</span><span v-else-if="row.cols_ok && row.primary_key_ok && row.checksum_ok" class="ok">Siap</span><span v-else class="bad">{{ row.note || row.missing.join(', ') || 'Tidak valid' }}</span></td></tr>
        </tbody></table></div>
        <div v-if="preview.can_restore" class="restore-confirm"><p>Baris dengan primary key yang sudah ada akan dilewati. Tidak ada UPDATE atau DELETE.</p><label>Ketik RESTORE untuk mengonfirmasi<input v-model="confirmText" autocomplete="off" spellcheck="false" placeholder="RESTORE"></label><button class="danger" :disabled="busy || confirmText !== 'RESTORE'" @click="executeRestore">{{ busy ? 'Memulihkan…' : 'Jalankan merge restore' }}</button></div>
        <p v-else class="bad">Preview ini tidak memenuhi syarat restore. Database belum diubah.</p>
      </div>
    </section>

    <section class="panel">
      <div class="panel-title"><div><h2>Arsip di server</h2><p>Backup manual dan safety backup sebelum restore.</p></div><button class="secondary" :disabled="busy" @click="loadFiles">Muat ulang</button></div>
      <div v-if="!files.length" class="empty">Belum ada arsip backup.</div>
      <ul v-else class="archive-list"><li v-for="file in files" :key="file.name"><div><strong>{{ file.kind === 'safety' ? 'Safety backup' : 'Backup' }}</strong><span>{{ new Date(file.created_at).toLocaleString('id-ID') }} · {{ formatSize(file.size) }}</span></div><a :href="`/api/v1/backups/${encodeURIComponent(file.name)}`">Unduh ZIP</a></li></ul>
    </section>
  </div>
</template>

<style scoped>
.backup-page{max-width:1040px;margin:0 auto;padding-bottom:24px;color:#16332b}.heading{margin-bottom:18px}.heading h1{margin:3px 0 8px;font-size:clamp(24px,5vw,34px)}.heading p:last-child,.panel>p{color:#62736d;line-height:1.55}.eyebrow{margin:0;color:#5b786d;font-size:11px;font-weight:800;letter-spacing:.14em}.panel{margin:14px 0;padding:18px;border:1px solid #dce8e2;border-radius:18px;background:#fff;box-shadow:0 8px 28px #173a2b0a}.panel h2{margin:0 0 5px;font-size:18px}.panel-title{display:flex;align-items:center;justify-content:space-between;gap:14px}.panel-title p{margin:0;color:#64756e;font-size:13px}.primary,.secondary,.danger{min-height:42px;padding:0 15px;border:0;border-radius:11px;font-weight:750;cursor:pointer}.primary{background:#176b4b;color:#fff}.secondary{border:1px solid #d5e2dc;background:#fff;color:#1c5540}.danger{background:#a52d25;color:#fff}.primary:disabled,.secondary:disabled,.danger:disabled{opacity:.48;cursor:not-allowed}.caution{padding:10px 12px;border-radius:10px;background:#fff7df;color:#76571a!important;font-size:13px}.alert{padding:12px 14px;border-radius:12px}.error,.bad{color:#9b2c24}.alert.error{background:#fff0ee}.success,.ok{color:#176b4b}.alert.success{background:#e9f7ef}.upload-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:14px}.upload-row input{max-width:100%}.preview-box{margin-top:18px;padding-top:16px;border-top:1px solid #e4ece8}.preview-summary{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}.preview-summary span,.archive-list span{color:#6c7b75;font-size:13px}.table-wrap{max-height:360px;overflow:auto;margin-top:12px;border:1px solid #e4ece8;border-radius:10px}table{width:100%;border-collapse:collapse;font-size:13px}th,td{text-align:left;padding:9px 11px;border-bottom:1px solid #edf1ef}th{position:sticky;top:0;background:#f5f8f6}.ok{font-weight:750}.restore-confirm{display:grid;grid-template-columns:1fr minmax(180px,280px) auto;align-items:end;gap:14px;margin-top:16px;padding:14px;border-radius:13px;background:#fff7f5}.restore-confirm p{grid-column:1/-1;margin:0;color:#79372e;font-size:13px}.restore-confirm label{display:grid;gap:5px;font-size:12px;font-weight:700}.restore-confirm input{height:40px;padding:0 10px;border:1px solid #d7dedb;border-radius:9px}.empty{padding:20px;color:#718078}.archive-list{list-style:none;margin:12px 0 0;padding:0}.archive-list li{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 0;border-top:1px solid #edf1ef}.archive-list li div{display:grid;gap:4px}.archive-list a{color:#176b4b;font-weight:750;white-space:nowrap}@media(max-width:680px){.panel{padding:15px}.panel-title{align-items:flex-start;flex-direction:column}.restore-confirm{grid-template-columns:1fr}.restore-confirm p{grid-column:auto}.restore-confirm button{width:100%}.archive-list li{align-items:flex-start;flex-direction:column}}
</style>
