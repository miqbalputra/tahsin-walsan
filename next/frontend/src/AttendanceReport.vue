<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'

type ReportRow = {
  id: number; tanggal: string; halaqoh_id: number; wali_santri_id: number; status: string
  jenis_materi: string; jilid: string; nama_surat: string; halaman: string
  hasil_talaqqi: string; alasan: string; nama_bapak: string; nama_halaqoh: string; kelas: string
}
type ReportResponse = { status: string; data: ReportRow[]; meta: { page: number; limit: number; total: number; start_date: string; end_date: string }; message?: string }
type FilterOptions = { status: string; data: { groups: Array<{ id: number; name: string }>; walis: Array<{ id: number; name: string }>; classes: string[] }; message?: string }

const props = defineProps<{ csrf: string; role: string }>()
const emit = defineEmits<{ unauthorized: [] }>()
const filters = reactive({ start_date: monthStart(), end_date: today(), halaqoh_id: '', wali_santri_id: '', kelas: '', search: '' })
const rows = ref<ReportRow[]>([])
const options = ref<FilterOptions['data']>({ groups: [], walis: [], classes: [] })
const page = ref(1)
const total = ref(0)
const busy = ref(false)
const error = ref('')
const statusLabel: Record<string, string> = { H: 'Hadir', S: 'Sakit', I: 'Izin', A: 'Alfa' }
const pages = computed(() => Math.max(1, Math.ceil(total.value / 50)))

async function load() {
  busy.value = true
  error.value = ''
  try {
    const query = new URLSearchParams({ ...filters, page: String(page.value), limit: '50' })
    const response = await fetch(`/api/v1/reports/attendance?${query}`, { credentials: 'same-origin', cache: 'no-store' })
    if (response.status === 401) { emit('unauthorized'); throw new Error('Sesi login berakhir. Masuk kembali.') }
    const body = await response.json() as ReportResponse
    if (!response.ok || body.status !== 'ok') throw new Error(body.message || 'Laporan belum dapat dimuat.')
    rows.value = body.data
    total.value = body.meta.total
  } catch (e) { error.value = (e as Error).message }
  finally { busy.value = false }
}

async function remove(row: ReportRow) {
  if (!window.confirm(`Hapus catatan presensi ${row.nama_bapak} tanggal ${row.tanggal}? Perubahan akan tercatat di log.`)) return
  busy.value = true
  error.value = ''
  try {
    const response = await fetch(`/api/v1/attendance/records/${row.id}`, {
      method: 'DELETE', credentials: 'same-origin', cache: 'no-store', headers: { 'X-CSRF-Token': props.csrf },
    })
    if (response.status === 401) { emit('unauthorized'); throw new Error('Sesi login berakhir. Masuk kembali.') }
    const body = await response.json() as { status: string; message?: string }
    if (!response.ok || body.status !== 'ok') throw new Error(body.message || 'Catatan belum dapat dihapus.')
    await load()
  } catch (e) { error.value = (e as Error).message }
  finally { busy.value = false }
}

function submit() { page.value = 1; void load() }
function movePage(delta: number) { page.value = Math.min(pages.value, Math.max(1, page.value + delta)); void load() }
function material(row: ReportRow) {
  if (row.status !== 'H') return row.alasan ? `${statusLabel[row.status] || row.status} · ${row.alasan}` : statusLabel[row.status] || row.status
  const name = row.jenis_materi === 'Iqro' ? `Iqro ${row.jilid}` : row.nama_surat ? `Al-Qur’an ${row.nama_surat}` : 'Al-Qur’an'
  return [name, row.halaman ? `hlm. ${row.halaman}` : '', row.hasil_talaqqi].filter(Boolean).join(' · ')
}
function today() { return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date()) }
function monthStart() { return `${today().slice(0, 7)}-01` }

async function initialize() {
  const response = await fetch('/api/v1/reports/attendance/options', { credentials: 'same-origin', cache: 'no-store' })
  if (response.status === 401) { emit('unauthorized'); throw new Error('Sesi login berakhir. Masuk kembali.') }
  const body = await response.json() as FilterOptions
  if (!response.ok || body.status !== 'ok') throw new Error(body.message || 'Pilihan filter belum dapat dimuat.')
  options.value = body.data
  await load()
}

onMounted(() => { void initialize().catch(e => { error.value = (e as Error).message }) })
</script>

<template>
  <section class="report-page">
    <div class="page-heading"><p class="eyebrow">{{ role === 'ustadz' ? 'RIWAYAT' : 'LAPORAN' }}</p><h1>{{ role === 'ustadz' ? 'Riwayat presensi' : 'Rekap presensi' }}</h1><p class="muted">{{ role === 'ustadz' ? 'Hanya menampilkan catatan dari kelompok yang Anda ajar.' : 'Data berasal langsung dari database Tahsin yang sama.' }}</p></div>
    <form class="report-filters" @submit.prevent="submit">
      <label>Dari tanggal<input v-model="filters.start_date" type="date" :max="filters.end_date" required></label>
      <label>Sampai tanggal<input v-model="filters.end_date" type="date" :min="filters.start_date" :max="today()" required></label>
      <label>Kelompok<select v-model="filters.halaqoh_id"><option value="">Semua kelompok</option><option v-for="item in options.groups" :key="item.id" :value="String(item.id)">{{ item.name }}</option></select></label>
      <label>Wali<select v-model="filters.wali_santri_id"><option value="">Semua wali</option><option v-for="item in options.walis" :key="item.id" :value="String(item.id)">{{ item.name }}</option></select></label>
      <label>Kelas anak<select v-model="filters.kelas"><option value="">Semua kelas</option><option v-for="item in options.classes" :key="item" :value="item">{{ item }}</option></select></label>
      <label class="search-filter">Cari wali/anak<input v-model="filters.search" maxlength="100" placeholder="Nama wali atau anak"></label>
      <button class="primary" :disabled="busy">{{ busy ? 'Memuat…' : 'Terapkan filter' }}</button>
    </form>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <div class="report-summary"><span>{{ total.toLocaleString('id-ID') }} catatan</span><span>Halaman {{ page }} dari {{ pages }}</span></div>
    <div class="report-table-wrap">
      <table class="report-table">
        <thead><tr><th>Tanggal</th><th>Wali</th><th>Anak / kelas</th><th>Kelompok</th><th>Presensi & capaian</th><th v-if="role === 'admin' || role === 'pj_tahfidz' || role === 'ustadz'">Aksi</th></tr></thead>
        <tbody>
          <tr v-if="busy && !rows.length"><td colspan="6">Memuat laporan…</td></tr>
          <tr v-else-if="!rows.length"><td colspan="6">Tidak ada catatan untuk filter ini.</td></tr>
          <tr v-for="row in rows" :key="row.id">
            <td>{{ row.tanggal }}</td><td>{{ row.nama_bapak }}</td><td>{{ row.kelas || '—' }}</td><td>{{ row.nama_halaqoh }}</td><td>{{ material(row) }}</td>
            <td v-if="role === 'admin' || role === 'pj_tahfidz' || role === 'ustadz'"><button class="danger-link" :disabled="busy" @click="remove(row)">Hapus</button></td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="report-pagination"><button class="secondary" :disabled="busy || page <= 1" @click="movePage(-1)">Sebelumnya</button><button class="secondary" :disabled="busy || page >= pages" @click="movePage(1)">Berikutnya</button></div>
  </section>
</template>
