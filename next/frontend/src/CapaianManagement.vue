<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'

type Capaian = { wali_id: number; nama_bapak: string; no_hp: string; children_names: string; classes: string; halaqoh_id: number; nama_halaqoh: string; ustadz_name: string; presensi_id: number | null; jenis_materi: string; jilid: string; nama_surat: string; halaman: string; hasil_talaqqi: string; last_date: string; is_initial: boolean }
type Group = { id: number; nama_halaqoh: string }
type Envelope<T> = { status: string; data?: T; meta?: { page: number; pages: number; total: number }; message?: string }

const props = defineProps<{ role: string; csrf: string }>()
const emit = defineEmits<{ unauthorized: [] }>()
const rows = ref<Capaian[]>([])
const groups = ref<Group[]>([])
const groupFilter = ref('')
const search = ref('')
const page = ref(1)
const pages = ref(1)
const total = ref(0)
const loading = ref(false)
const saving = ref(false)
const editing = ref<string | null>(null)
const error = ref('')
const notice = ref('')
const schemaReady = ref(false)
const form = reactive({ wali_id: 0, halaqoh_id: 0, presensi_id: 0, jenis_materi: 'Al Quran', jilid: '', nama_surat: '', halaman: '', hasil_talaqqi: '' })
const canEdit = props.role === 'admin' || props.role === 'pj_tahfidz'

async function load(target = page.value) {
  loading.value = true; error.value = ''; notice.value = ''
  const query = new URLSearchParams({ page: String(target), limit: '50' })
  if (groupFilter.value) query.set('halaqoh_id', groupFilter.value)
  if (search.value.trim()) query.set('search', search.value.trim())
  try {
    const response = await fetch(`/api/v1/capaian?${query}`, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
    const body = await response.json() as Envelope<{ rows: Capaian[]; groups: Group[]; schema_ready: boolean }>
    if (response.status === 401) { emit('unauthorized'); throw new Error('Sesi login berakhir. Masuk kembali.') }
    if (!response.ok || body.status !== 'ok' || !body.data || !body.meta) throw new Error(body.message || 'Daftar capaian belum dapat dimuat.')
    rows.value = body.data.rows; groups.value = body.data.groups; schemaReady.value = body.data.schema_ready
    page.value = body.meta.page; pages.value = body.meta.pages; total.value = body.meta.total
  } catch (cause) { error.value = (cause as Error).message }
  finally { loading.value = false }
}

function startEdit(row: Capaian) {
  editing.value = rowKey(row)
  Object.assign(form, { wali_id: row.wali_id, halaqoh_id: row.halaqoh_id, presensi_id: row.presensi_id || 0, jenis_materi: row.jenis_materi || 'Al Quran', jilid: row.jilid || '', nama_surat: row.nama_surat || '', halaman: row.halaman || '', hasil_talaqqi: row.hasil_talaqqi || '' })
  error.value = ''
}

function rowKey(row: Capaian) { return `${row.wali_id}:${row.halaqoh_id}` }

async function save() {
  if (!form.wali_id || !form.halaqoh_id || !form.halaman.trim()) { error.value = 'Halaman atau ayat wajib diisi.'; return }
  if (form.jenis_materi === 'Iqro' && !form.jilid) { error.value = 'Pilih jilid Iqro.'; return }
  if (form.jenis_materi === 'Al Quran' && !form.nama_surat.trim()) { error.value = 'Nama surat wajib diisi.'; return }
  if (form.presensi_id && !['Lulus', 'Ulang'].includes(form.hasil_talaqqi)) { error.value = 'Pilih hasil Lulus atau Ulang.'; return }
  saving.value = true; error.value = ''; notice.value = ''
  try {
    const response = await fetch('/api/v1/capaian', { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': props.csrf }, body: JSON.stringify(form) })
    const body = await response.json() as Envelope<{ saved: boolean }>
    if (response.status === 401) { emit('unauthorized'); throw new Error('Sesi login berakhir. Masuk kembali.') }
    if (!response.ok || body.status !== 'ok') throw new Error(body.message || 'Perubahan capaian belum tersimpan.')
    editing.value = null
    notice.value = form.presensi_id ? 'Capaian pada catatan Hadir diperbarui. Presensi dan histori tetap dipertahankan.' : 'Capaian awal tersimpan sebagai referensi; tidak dicatat sebagai presensi.'
    await load(page.value)
  } catch (cause) { error.value = (cause as Error).message }
  finally { saving.value = false }
}

function move(delta: number) { const target = Math.max(1, Math.min(pages.value, page.value + delta)); void load(target) }
onMounted(() => load(1))
</script>

<template>
  <main class="capaian-page">
    <header><p class="eyebrow">DATA PEMBELAJARAN</p><h1>Capaian Peserta</h1><p>Capaian paling akhir dari catatan Hadir ditampilkan. Jika belum ada catatan Hadir, capaian awal ditampilkan terpisah dan tidak dihitung sebagai presensi.</p></header>
    <p v-if="error" class="alert error" role="alert">{{ error }}</p><p v-if="notice" class="alert success" role="status">{{ notice }}</p>
    <p v-if="!schemaReady" class="alert warning">Skema histori Tahsin belum lengkap. Data dapat dibaca, tetapi perubahan capaian dinonaktifkan sampai migrasi diverifikasi.</p>
    <form class="filters" @submit.prevent="load(1)"><label>Kelompok<select v-model="groupFilter"><option value="">Semua kelompok</option><option v-for="group in groups" :key="group.id" :value="String(group.id)">{{ group.nama_halaqoh }}</option></select></label><label class="search">Cari wali atau kelompok<input v-model="search" maxlength="100" placeholder="Nama wali / halaqoh"></label><button :disabled="loading">{{ loading ? 'Memuat…' : 'Terapkan' }}</button></form>
    <section class="list-panel">
      <div class="list-head"><strong>{{ total.toLocaleString('id-ID') }} baris</strong><span>Halaman {{ page }} / {{ pages }}</span></div>
      <p v-if="loading && !rows.length" class="empty">Memuat data capaian…</p><p v-else-if="!rows.length" class="empty">Tidak ada peserta sesuai filter.</p>
      <article v-for="row in rows" :key="`${row.wali_id}:${row.halaqoh_id}`" class="person-card">
        <div class="person-head"><div><h2>{{ row.nama_bapak }}</h2><p>{{ row.children_names || 'Data anak belum tersedia' }}<span v-if="row.classes"> · {{ row.classes }}</span></p><small>{{ row.nama_halaqoh }} · {{ row.ustadz_name }}</small></div><button v-if="canEdit && schemaReady" class="secondary" @click="startEdit(row)">{{ editing === rowKey(row) ? 'Sedang diedit' : 'Ubah capaian' }}</button></div>
        <div class="achievement"><strong>{{ row.is_initial ? 'Capaian awal · bukan presensi' : row.presensi_id ? `Catatan Hadir · ${row.last_date}` : 'Belum ada capaian' }}</strong><span v-if="row.jenis_materi">{{ row.jenis_materi === 'Iqro' ? `Iqro jilid ${row.jilid}` : `Al-Qur’an ${row.nama_surat}` }} · {{ row.halaman }}<template v-if="row.hasil_talaqqi"> · {{ row.hasil_talaqqi }}</template></span><span v-else>Belum dicatat</span></div>
        <form v-if="editing === rowKey(row) && canEdit" class="edit-form" @submit.prevent="save">
          <div class="fields"><label>Materi<select v-model="form.jenis_materi"><option>Al Quran</option><option>Iqro</option></select></label><label v-if="form.jenis_materi === 'Iqro'">Jilid<select v-model="form.jilid"><option value="">Pilih</option><option v-for="n in 6" :key="n" :value="String(n)">{{ n }}</option></select></label><label v-else>Surat<input v-model="form.nama_surat" maxlength="100"></label><label>Halaman / ayat<input v-model="form.halaman" required maxlength="30"></label><label v-if="form.presensi_id">Hasil talaqqi<select v-model="form.hasil_talaqqi" required><option value="">Pilih</option><option>Lulus</option><option>Ulang</option></select></label></div>
          <p v-if="form.presensi_id" class="edit-note">Perubahan mengoreksi hanya capaian pada catatan Hadir ini. Data presensi lain tidak berubah.</p><p v-else class="edit-note">Capaian ini disimpan sebagai titik awal dan tidak membuat presensi Hadir.</p>
          <div class="actions"><button class="primary" :disabled="saving || !schemaReady">{{ saving ? 'Menyimpan…' : 'Simpan capaian' }}</button><button type="button" class="secondary" :disabled="saving" @click="editing = null">Batal</button></div>
        </form>
      </article>
      <div class="pagination"><button :disabled="loading || page <= 1" @click="move(-1)">Sebelumnya</button><button :disabled="loading || page >= pages" @click="move(1)">Berikutnya</button></div>
    </section>
  </main>
</template>

<style scoped>
.capaian-page{max-width:1050px;margin:0 auto;display:grid;gap:14px;color:#17362b}.capaian-page header{padding:7px 2px}.eyebrow{margin:0;color:#648073;font-size:11px;font-weight:850;letter-spacing:.13em}.capaian-page h1{margin:5px 0;font-size:clamp(24px,5vw,34px)}.capaian-page header p:last-child{max-width:720px;color:#687a73;line-height:1.55}.alert{padding:12px 14px;border-radius:12px}.error{background:#fff0ee;color:#9b2c24}.success{background:#e9f7ef;color:#176b4b}.warning{background:#fff7df;color:#76571a}.filters,.list-panel{padding:16px;border:1px solid #dce8e2;border-radius:16px;background:white}.filters{display:flex;align-items:end;gap:12px;flex-wrap:wrap}.filters label,.fields label{display:grid;gap:5px;color:#62736d;font-size:12px;font-weight:750}.filters .search{flex:1;min-width:220px}.filters input,.filters select,.fields input,.fields select{min-height:40px;padding:8px 10px;border:1px solid #d5e2dc;border-radius:9px;background:white;color:#17362b}.filters button,.primary,.secondary{min-height:40px;padding:0 14px;border:0;border-radius:10px;font-weight:750;cursor:pointer}.filters button,.primary{background:#176b4b;color:white}.secondary{border:1px solid #d5e2dc;background:white;color:#315545}.filters button:disabled,.primary:disabled,.secondary:disabled{opacity:.5}.list-head{display:flex;justify-content:space-between;color:#63746e;font-size:13px}.person-card{margin-top:12px;padding:14px;border:1px solid #e6ede9;border-radius:13px}.person-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}.person-head h2{margin:0;font-size:17px}.person-head p{margin:5px 0;color:#60726a;font-size:13px}.person-head small{color:#7a8982}.achievement{display:grid;gap:5px;margin-top:12px;padding:11px;border-radius:10px;background:#f4f8f5;font-size:13px}.achievement span{color:#52665d}.edit-form{margin-top:12px;padding:13px;border-radius:11px;background:#fafcfb}.fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:10px}.edit-note{color:#61736b;font-size:12px}.actions,.pagination{display:flex;gap:8px}.pagination{justify-content:space-between;margin-top:14px}.pagination button{min-height:38px;padding:0 12px;border:1px solid #d5e2dc;border-radius:9px;background:white;color:#315545}.pagination button:disabled{opacity:.5}.empty{padding:20px;text-align:center;color:#718078}@media(max-width:620px){.filters{align-items:stretch;flex-direction:column}.filters .search{min-width:0}.filters button{width:100%}.person-head{flex-direction:column}.person-head button{width:100%}}
</style>
