<script setup lang="ts">
import { onMounted, ref } from 'vue'

type RecordRow = { id: number; tanggal: string; status: string; alasan: string; jenis_materi: string; jilid: string; nama_surat: string; halaman: string; hasil_talaqqi: string; on_holiday?: boolean }
type Cell = { session_date: string; state: string; missing_fields: string[]; records: RecordRow[]; record: RecordRow | null; owner: { nama_halaqoh: string; nama_ustadz: string }; roster_source: string }
type Row = { id: number; nama_bapak: string; anak: string; children: Array<{ nama_anak: string; kelas: string }>; kategori: string; status_aktif: boolean; nama_halaqoh: string; nama_ustadz: string; hadir: number; sakit: number; izin: number; alpha: number; expected: number; complete_input: number; missing_presensi: number; missing_capaian: number; missing_keterangan: number; conflicts: number; unverified: number; provisional: boolean; terakhir_hadir: string; materi_terakhir: string; details: Cell[] }
type Envelope = { status: string; data: { rows: Row[] }; message?: string }
const props = defineProps<{ waliId: string; startDate: string; endDate: string; viewer: string }>()
const emit = defineEmits<{ unauthorized: [] }>()
const row = ref<Row | null>(null)
const loading = ref(true)
const error = ref('')
const statusNames: Record<string, string> = { H: 'Hadir', S: 'Sakit', I: 'Izin', A: 'Alfa' }
const stateNames: Record<string, string> = { complete: 'Lengkap', missing: 'Belum diinput', baseline: 'Capaian awal; bukan presensi', conflict: 'Perlu verifikasi', invalid_date: 'Tanggal pada hari libur', capaian: 'Capaian belum lengkap', presensi_incomplete: 'Keterangan belum lengkap' }
function today() { return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date()) }
function reportStart() { return props.startDate || `${today().slice(0, 7)}-01` }
function reportEnd() { return props.endDate || today() }

async function load() {
  loading.value = true; error.value = ''
  try {
    if (!/^[1-9]\d*$/.test(props.waliId)) throw new Error('ID peserta tidak valid.')
    const query = new URLSearchParams({ wali_santri_id: props.waliId, start_date: reportStart(), end_date: reportEnd(), page: '1', limit: '100' })
    const response = await fetch(`/api/v1/reports/tahsin?${query}`, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
    const body = await response.json() as Envelope
    if (response.status === 401) { emit('unauthorized'); throw new Error('Sesi login berakhir. Masuk kembali.') }
    if (!response.ok || body.status !== 'ok') throw new Error(body.message || 'Rapor Tahsin belum dapat dimuat.')
    row.value = body.data.rows.find(item => String(item.id) === props.waliId) || null
  } catch (cause) { error.value = cause instanceof Error ? cause.message : 'Rapor Tahsin belum dapat dimuat.' }
  finally { loading.value = false }
}

function material(record: RecordRow | null) {
  if (!record) return '—'
  const title = record.jenis_materi === 'Iqro' ? `Iqro jilid ${record.jilid || '—'}` : record.nama_surat ? `Al-Qur’an ${record.nama_surat}` : record.jenis_materi || 'Materi belum dicatat'
  return [title, record.halaman ? `halaman/ayat ${record.halaman}` : '', record.hasil_talaqqi].filter(Boolean).join(' · ')
}
function statusText(record: RecordRow | null) {
  if (!record) return 'Belum diinput; tidak otomatis dinilai Alfa'
  if (record.status === 'H') return material(record)
  return [statusNames[record.status] || record.status, record.alasan].filter(Boolean).join(' · ')
}
function dateText(value: string) { return value ? new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'long', year: 'numeric', timeZone: 'Asia/Jakarta' }).format(new Date(`${value}T00:00:00+07:00`)) : '—' }
function print() { window.print() }
onMounted(load)
</script>

<template>
  <section class="rapor-page">
    <nav class="rapor-actions no-print"><a href="/rekap">Kembali ke rekap</a><button :disabled="!row" @click="print">Cetak / Simpan PDF</button></nav>
    <p v-if="loading" class="rapor-message">Memuat rapor…</p>
    <p v-else-if="error" class="rapor-message error" role="alert">{{ error }} <button @click="load">Coba lagi</button></p>
    <p v-else-if="!row" class="rapor-message">Peserta tidak ditemukan pada cakupan akun ini.</p>
    <article v-else class="rapor-sheet">
      <header class="rapor-header"><p class="rapor-kicker">GRIYA QUR’AN · TAHSIN</p><h1>Laporan Perkembangan Tahsin</h1><p>Periode sesi {{ dateText(reportStart()) }} – {{ dateText(reportEnd()) }}</p></header>
      <section class="rapor-profile"><div><span>Nama wali</span><strong>{{ row.nama_bapak }}</strong></div><div><span>Kelompok</span><strong>{{ row.nama_halaqoh || 'Belum ditentukan' }}</strong></div><div><span>Nama anak / kelas</span><strong>{{ row.anak || 'Belum tersedia' }}</strong></div><div><span>Guru</span><strong>{{ row.nama_ustadz || 'Belum ditentukan' }}</strong></div><div><span>Status</span><strong>{{ row.status_aktif ? 'Aktif' : 'Arsip' }} · {{ row.kategori.replaceAll('_', ' ') }}</strong></div></section>
      <section class="rapor-summary"><h2>Ringkasan presensi tercatat</h2><div><p><span>Hadir</span><strong>{{ row.hadir }}</strong></p><p><span>Sakit</span><strong>{{ row.sakit }}</strong></p><p><span>Izin</span><strong>{{ row.izin }}</strong></p><p><span>Alfa</span><strong>{{ row.alpha }}</strong></p></div><p class="rapor-note">Data kosong bukan Alfa. {{ row.expected ? `${row.complete_input} dari ${row.expected} catatan lengkap.` : 'Kewajiban sesi belum dapat dipastikan.' }}</p></section>
      <section class="rapor-last"><span>Capaian terakhir</span><strong>{{ row.materi_terakhir || 'Belum ada capaian' }}</strong><span v-if="row.terakhir_hadir">Hadir terakhir: {{ dateText(row.terakhir_hadir) }}</span></section>
      <section class="rapor-history"><h2>Rincian per sesi</h2><table><thead><tr><th>Tanggal</th><th>Kelompok / guru</th><th>Status / capaian</th><th>Catatan sumber</th></tr></thead><tbody>
        <tr v-for="cell in row.details" :key="cell.session_date"><td>{{ dateText(cell.session_date) }}</td><td>{{ cell.owner.nama_halaqoh || 'Tanpa kelompok' }}<small>{{ cell.owner.nama_ustadz || 'Guru belum ditentukan' }}</small></td><td><strong>{{ stateNames[cell.state] || cell.state }}</strong><small v-if="cell.record">{{ statusText(cell.record) }}</small><small v-if="cell.missing_fields.length">Perlu dilengkapi: {{ cell.missing_fields.join(', ') }}</small></td><td><span v-for="source in cell.records" :key="source.id" class="rapor-source">#{{ source.id }} · {{ dateText(source.tanggal) }} · {{ statusText(source) }}<small v-if="source.on_holiday">Tanggal libur; tidak dihitung dalam rekap.</small></span><span v-if="!cell.records.length">{{ statusText(null) }}</span><small>{{ cell.roster_source }}</small></td></tr>
        <tr v-if="!row.details.length"><td colspan="4" class="rapor-empty">Belum ada sesi dalam periode ini.</td></tr>
      </tbody></table></section>
      <footer class="rapor-signatures"><div><span>Guru pembimbing</span><strong>{{ row.nama_ustadz || '—' }}</strong></div><div><span>Dicetak oleh</span><strong>{{ viewer }}</strong><small>{{ dateText(new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date())) }}</small></div></footer>
      <p class="rapor-footnote">Rekap ini menampilkan sumber catatan dan status verifikasi. Konflik harus ditinjau sebelum dipakai sebagai laporan final.</p>
    </article>
  </section>
</template>

<style scoped>
.rapor-page{max-width:1000px;margin:0 auto;color:#18314a}.rapor-actions{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}.rapor-actions a,.rapor-actions button,.rapor-message button{border:1px solid #ccd8e4;border-radius:8px;padding:9px 12px;background:#fff;color:#2e5576;font:inherit;text-decoration:none;cursor:pointer}.rapor-actions button{background:#245ad1;color:#fff;border-color:#245ad1}.rapor-actions button:disabled{opacity:.5}.rapor-message{padding:20px;background:#fff;border-radius:12px}.rapor-message.error{color:#9c3e35}.rapor-sheet{background:#fff;padding:34px;border:1px solid #e0e7ee;border-radius:14px;box-shadow:0 8px 28px #18314a0b}.rapor-header{text-align:center;border-bottom:2px solid #245b43;padding-bottom:17px}.rapor-kicker{font-size:10px;letter-spacing:2px;color:#245b43;font-weight:850;margin:0}.rapor-header h1{margin:6px 0;font-size:25px;text-transform:uppercase}.rapor-header>p:last-child{margin:0;color:#65798d}.rapor-profile{display:grid;grid-template-columns:1fr 1fr;gap:13px;padding:18px 0}.rapor-profile div,.rapor-last{display:grid;gap:4px}.rapor-profile span,.rapor-last span,.rapor-signatures span{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#6e8195}.rapor-profile strong,.rapor-last strong{font-size:13px}.rapor-summary,.rapor-last{border:1px solid #d9e3eb;border-radius:10px;padding:13px;margin-bottom:14px}.rapor-summary h2,.rapor-history h2{font-size:14px;margin:0 0 10px}.rapor-summary>div{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.rapor-summary>div p{display:grid;gap:3px;padding:10px;background:#f5f8fb;border-radius:8px;margin:0}.rapor-summary>div span{font-size:10px;color:#72859a}.rapor-summary>div strong{font-size:21px}.rapor-note,.rapor-footnote{font-size:10px;color:#6a7f92;margin:10px 0 0}.rapor-last{grid-template-columns:1fr auto;align-items:center}.rapor-last span:last-child{grid-column:1/-1}.rapor-history{margin-top:16px}.rapor-history table{width:100%;border-collapse:collapse;font-size:11px}.rapor-history th,.rapor-history td{border:1px solid #d9e2ea;padding:8px;text-align:left;vertical-align:top}.rapor-history th{background:#eef3f7;font-size:9px;text-transform:uppercase}.rapor-history td small,.rapor-source small{display:block;color:#708398;font-size:9px;margin-top:4px}.rapor-source{display:block;margin:0 0 7px;line-height:1.4}.rapor-empty{text-align:center;color:#718398}.rapor-signatures{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:36px;text-align:center}.rapor-signatures div{display:grid;gap:7px;justify-items:center}.rapor-signatures strong{margin-top:45px;text-decoration:underline}.rapor-signatures small{font-size:9px;color:#76879a}.rapor-footnote{border-top:1px solid #e3e9ee;padding-top:10px;margin-top:23px}@media(max-width:640px){.rapor-sheet{padding:18px}.rapor-profile{grid-template-columns:1fr}.rapor-summary>div{grid-template-columns:repeat(2,1fr)}.rapor-last{grid-template-columns:1fr}.rapor-history{overflow:auto}.rapor-history table{min-width:600px}}@media print{@page{size:A4 portrait;margin:14mm}.no-print{display:none!important}.rapor-page{max-width:none}.rapor-sheet{padding:0;border:0;box-shadow:none}.rapor-history tr{break-inside:avoid}.rapor-summary,.rapor-last,.rapor-profile{break-inside:avoid}}
</style>
