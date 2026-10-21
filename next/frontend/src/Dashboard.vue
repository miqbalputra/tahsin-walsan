<script setup lang="ts">
import { onMounted, ref } from 'vue'

type Trend = { tanggal: string; hadir: number; sakit: number; izin: number; alfa: number }
type Warning = { wali_santri_id: number; nama_bapak: string; nama_halaqoh: string; total_alpha: number }
type Activity = { tanggal: string; nama_bapak: string; nama_halaqoh: string; status: string }
type DashboardData = {
  as_of: string; period_start: string
  counts: { users: number; wali: number; wali_aktif: number; anak: number; halaqoh: number }
  presensi_30_hari: Record<string, number>; trend: Trend[]
  peringatan_alpha_tercatat: Warning[]; terbaru: Activity[]
}
type Envelope<T> = { status: string; data: T; message?: string }
const props = defineProps<{ role: string }>()
const data = ref<DashboardData | null>(null)
const loading = ref(false)
const error = ref('')
const statusNames: Record<string, string> = { H: 'Hadir', S: 'Sakit', I: 'Izin', A: 'Alfa' }
const statusColors: Record<string, string> = { H: '#1b8a52', S: '#4484ce', I: '#d39825', A: '#cc4c47' }

async function load() {
  loading.value = true
  error.value = ''
  try {
    const response = await fetch('/api/v1/dashboard', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
    const body = await response.json() as Envelope<DashboardData>
    if (response.status === 401) throw new Error('Sesi login berakhir. Masuk kembali.')
    if (!response.ok || body.status !== 'ok') throw new Error(body.message || 'Dashboard belum dapat dimuat.')
    data.value = body.data
  } catch (e) { error.value = e instanceof Error ? e.message : 'Dashboard belum dapat dimuat.' }
  finally { loading.value = false }
}
function width(value: number) {
  const highest = Math.max(1, ...(data.value?.trend.map(row => row.hadir) || []))
  return `${Math.max(3, Math.round(value / highest * 100))}%`
}
function dateLabel(value: string) { return new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(`${value}T12:00:00+07:00`)) }
onMounted(load)
</script>

<template>
  <section class="dashboard-page">
    <header class="dashboard-heading"><div><span class="dash-kicker">PRESENSI TAHSIN</span><h1>{{ props.role === 'kepsek' ? 'Dashboard Kepala Sekolah' : 'Dashboard Tahsin' }}</h1><p v-if="data">Data tercatat 30 hari terakhir · {{ data.period_start }} sampai {{ data.as_of }}</p></div><button class="dash-refresh" :disabled="loading" @click="load">{{ loading ? 'Memuat…' : 'Muat ulang' }}</button></header>
    <p v-if="error" class="dash-error" role="alert">{{ error }} <button @click="load">Coba lagi</button></p>
    <p v-if="loading && !data" class="dash-message">Memuat ringkasan…</p>
    <template v-if="data">
      <div class="dash-cards"><article><span>Pengguna</span><strong>{{ data.counts.users.toLocaleString('id-ID') }}</strong></article><article><span>Wali aktif / total</span><strong>{{ data.counts.wali_aktif.toLocaleString('id-ID') }} <small>/ {{ data.counts.wali.toLocaleString('id-ID') }}</small></strong></article><article><span>Anak</span><strong>{{ data.counts.anak.toLocaleString('id-ID') }}</strong></article><article><span>Kelompok</span><strong>{{ data.counts.halaqoh.toLocaleString('id-ID') }}</strong></article></div>
      <div class="dash-columns">
        <section class="dash-panel"><div class="dash-title"><div><h2>Presensi tercatat</h2><p>30 hari terakhir · jumlah catatan per status</p></div></div><div class="dash-statuses"><div v-for="status in ['H','S','I','A']" :key="status" class="dash-status"><div><span>{{ statusNames[status] }}</span><strong>{{ (data.presensi_30_hari[status] || 0).toLocaleString('id-ID') }}</strong></div><span class="dash-bar-bg"><i :style="{ width: `${Math.round((data.presensi_30_hari[status] || 0) / Math.max(1, ...Object.values(data.presensi_30_hari)) * 100)}%`, background: statusColors[status] }"></i></span></div></div></section>
        <section class="dash-panel"><div class="dash-title"><div><h2>Tren kehadiran</h2><p>Rekaman hadir per tanggal</p></div></div><p v-if="!data.trend.length" class="dash-empty">Belum ada presensi dalam rentang ini.</p><div v-else class="dash-trend"><div v-for="item in data.trend" :key="item.tanggal" class="trend-item" :title="`${dateLabel(item.tanggal)}: ${item.hadir} hadir`"><span class="trend-bar"><i :style="{ height: width(item.hadir) }"></i></span><small>{{ dateLabel(item.tanggal) }}</small></div></div></section>
      </div>
      <div class="dash-columns lower">
        <section class="dash-panel"><div class="dash-title"><div><h2>Perlu perhatian</h2><p>Wali dengan ≥3 catatan Alfa, perlu ditinjau di laporan lengkap</p></div></div><p v-if="!data.peringatan_alpha_tercatat.length" class="dash-empty">Tidak ada catatan Alfa dengan ambang ini.</p><div v-for="item in data.peringatan_alpha_tercatat" :key="`${item.wali_santri_id}:${item.nama_halaqoh}`" class="dash-row"><div><strong>{{ item.nama_bapak }}</strong><span>{{ item.nama_halaqoh }}</span></div><b>{{ item.total_alpha }} Alfa</b></div></section>
        <section class="dash-panel"><div class="dash-title"><div><h2>Presensi terbaru</h2><p>Catatan paling baru di database</p></div></div><p v-if="!data.terbaru.length" class="dash-empty">Belum ada aktivitas.</p><div v-for="(item,index) in data.terbaru" :key="`${item.tanggal}:${item.nama_bapak}:${index}`" class="dash-row"><div><strong>{{ item.nama_bapak }}</strong><span>{{ item.nama_halaqoh }} · {{ dateLabel(item.tanggal) }}</span></div><b :style="{ color: statusColors[item.status] || '#65788e' }">{{ statusNames[item.status] || item.status }}</b></div></section>
      </div>
      <p class="dash-note">Ringkasan ini menghitung catatan presensi mentah. Status Alfa dan kelengkapan laporan resmi harus ditinjau pada modul laporan Tahsin.</p>
    </template>
  </section>
</template>

<style scoped>
.dashboard-page{max-width:1200px;margin:0 auto;color:#172b42;display:grid;gap:16px}.dashboard-heading{display:flex;justify-content:space-between;align-items:flex-end;gap:16px}.dashboard-heading h1{margin:7px 0;font-size:28px;letter-spacing:-.04em}.dashboard-heading p{margin:0;color:#65788e;font-size:13px}.dash-kicker{font-size:10px;font-weight:850;letter-spacing:1.3px;color:#698096}.dash-refresh{border:1px solid #d5dfeb;border-radius:10px;background:#fff;color:#38566f;padding:9px 12px;font-weight:700}.dash-refresh:disabled{opacity:.5}.dash-error,.dash-message{padding:13px 15px;border-radius:11px;background:#fff0ee;color:#9b3931;font-size:14px}.dash-error button{margin-left:8px}.dash-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.dash-cards article,.dash-panel{background:#fff;border:1px solid #e1e8f0;border-radius:15px;padding:17px}.dash-cards article{display:grid;gap:8px}.dash-cards article span,.dash-title p{font-size:12px;color:#718399}.dash-cards article strong{font-size:25px;letter-spacing:-.03em}.dash-cards small{font-size:15px;color:#78899a}.dash-columns{display:grid;grid-template-columns:1fr 1fr;gap:14px}.dash-title h2{margin:0;font-size:16px}.dash-title p{margin:5px 0 14px}.dash-statuses{display:grid;gap:13px}.dash-status>div{display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px}.dash-status>div span{color:#61748a}.dash-bar-bg{height:7px;border-radius:99px;background:#edf1f5;overflow:hidden;display:block}.dash-bar-bg i{height:100%;display:block;border-radius:99px}.dash-trend{display:flex;gap:8px;align-items:flex-end;height:140px;overflow-x:auto;padding:8px 2px 0}.trend-item{min-width:24px;flex:1;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:6px}.trend-bar{height:110px;width:100%;display:flex;align-items:flex-end;justify-content:center}.trend-bar i{width:min(18px,80%);min-height:3px;background:#237649;border-radius:5px 5px 1px 1px}.trend-item small{font-size:9px;white-space:nowrap;color:#7b8b9b}.lower{align-items:start}.dash-row{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:10px 0;border-top:1px solid #edf1f5}.dash-row>div{display:grid;gap:4px;min-width:0}.dash-row strong{font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.dash-row span{font-size:11px;color:#718399;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.dash-row b{font-size:11px;white-space:nowrap;color:#b64d44}.dash-empty{padding:18px 6px;text-align:center;color:#7b8b9b;font-size:13px}.dash-note{margin:0;color:#7a8998;font-size:11px;line-height:1.5}@media(max-width:720px){.dash-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.dash-columns{grid-template-columns:1fr}.dashboard-heading{align-items:flex-start}.dashboard-heading h1{font-size:24px}} 
</style>
