<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

type Child = { id: number; nama_anak: string; kelas: string }
type Participant = {
  id: number; nama_bapak: string; no_hp: string; alamat: string; kategori: string
  tempat_tahsin: string; ustadz_luar: string; lanjut_tahsin: boolean; status_aktif: boolean
  family_managed: boolean; children_editable: boolean; halaqoh_id: number; nama_halaqoh: string; nama_ustadz_halaqoh: string; anak: Child[]
}
type Group = { id: number; nama_halaqoh: string; program_code: string }
type Envelope<T> = { status: string; data: T; message?: string }
type PageData = { participants: Participant[]; groups: Group[]; can_create: boolean; page: number; limit: number; total: number }

const props = defineProps<{ csrf: string }>()
const emit = defineEmits<{ unauthorized: [] }>()
const data = ref<PageData | null>(null)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const search = ref('')
const scope = ref<'active' | 'archived' | 'all'>('active')
const page = ref(1)
const showForm = ref(false)
const editing = ref(false)
const form = ref<Participant>(emptyParticipant())
const pages = computed(() => Math.max(1, Math.ceil((data.value?.total || 0) / (data.value?.limit || 25))))

function emptyParticipant(): Participant {
  return { id: 0, nama_bapak: '', no_hp: '', alamat: '', kategori: 'reguler', tempat_tahsin: '', ustadz_luar: '', lanjut_tahsin: false, status_aktif: true, family_managed: false, children_editable: true, halaqoh_id: 0, nama_halaqoh: '', nama_ustadz_halaqoh: '', anak: [{ id: 0, nama_anak: '', kelas: '' }] }
}

async function request<T>(url: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (options.body) headers.set('Content-Type', 'application/json')
  if (options.method && options.method !== 'GET') headers.set('X-CSRF-Token', props.csrf)
  const response = await fetch(url, { ...options, headers, credentials: 'same-origin', cache: 'no-store' })
  let body: Envelope<T>
  try { body = await response.json() as Envelope<T> } catch { throw new Error('Respons server tidak dapat dibaca.') }
  if (!response.ok || (body.status !== 'ok' && body.status !== 'success')) {
    if (response.status === 401) emit('unauthorized')
    throw new Error(body.message || 'Permintaan belum berhasil.')
  }
  return body.data
}

async function load(targetPage = 1) {
  loading.value = true
  error.value = ''
  notice.value = ''
  try {
    const query = new URLSearchParams({ search: search.value.trim(), scope: scope.value, page: String(targetPage) })
    data.value = await request<PageData>(`/api/v1/participants?${query}`)
    page.value = data.value.page
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Daftar peserta belum dapat dibaca.'
  } finally { loading.value = false }
}

function add() {
  editing.value = false
  form.value = emptyParticipant()
  showForm.value = true
  error.value = ''
  notice.value = ''
}

function edit(item: Participant) {
  editing.value = true
  form.value = { ...item, anak: item.anak.map(child => ({ ...child })) }
  showForm.value = true
  error.value = ''
  notice.value = ''
}

function addChild() { form.value.anak.push({ id: 0, nama_anak: '', kelas: '' }) }

async function save() {
  saving.value = true
  error.value = ''
  notice.value = ''
  const payload = {
    nama_bapak: form.value.nama_bapak,
    no_hp: form.value.no_hp,
    alamat: form.value.alamat,
    kategori: form.value.kategori,
    tempat_tahsin: form.value.tempat_tahsin,
    ustadz_luar: form.value.ustadz_luar,
    lanjut_tahsin: form.value.lanjut_tahsin,
    halaqoh_id: Number(form.value.halaqoh_id) || 0,
    ...(form.value.children_editable ? { anak: form.value.anak } : {}),
  }
  try {
    const url = editing.value ? `/api/v1/participants/${form.value.id}` : '/api/v1/participants'
    await request(url, { method: editing.value ? 'PATCH' : 'POST', body: JSON.stringify(payload) })
    showForm.value = false
    notice.value = 'Perubahan peserta tersimpan.'
    await load(page.value)
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Perubahan peserta belum dapat disimpan.'
  } finally { saving.value = false }
}

async function archive(item: Participant) {
  if (!window.confirm(`Arsipkan ${item.nama_bapak}? Data anak, keanggotaan lama, dan histori presensi tetap disimpan.`)) return
  error.value = ''
  notice.value = ''
  try {
    await request(`/api/v1/participants/${item.id}`, { method: 'DELETE' })
    notice.value = 'Peserta diarsipkan. Histori presensi tetap tersimpan.'
    await load(page.value)
  } catch (e) { error.value = e instanceof Error ? e.message : 'Peserta belum dapat diarsipkan.' }
}
</script>

<template>
  <div class="participants-page">
    <header class="participants-heading">
      <div><span class="participants-kicker">DATA TAHSIN</span><h1>Peserta</h1><p>Kelola data orang tua, anak, dan penempatan kelompok.</p></div>
      <button v-if="data?.can_create" class="participants-primary" @click="add">Tambah peserta</button>
    </header>
    <p class="participants-info">Mengarsipkan peserta tidak menghapus anak atau histori presensi. Peserta dengan identitas yang dikelola Keluarga/Data Induk hanya dapat mengubah pengaturan Tahsin dari sini.</p>
    <p v-if="notice" class="participants-success" role="status">{{ notice }}</p>
    <p v-if="error && !showForm" class="participants-error" role="alert">{{ error }} <button @click="load(page)">Coba lagi</button></p>
    <section class="participants-controls">
      <form @submit.prevent="load(1)"><label for="participants-search">Cari orang tua, anak, atau nomor HP</label><div><input id="participants-search" v-model="search" type="search" maxlength="100" placeholder="Ketik nama atau nomor HP"><button :disabled="loading">Cari</button></div></form>
      <div class="participants-scopes" role="group" aria-label="Status peserta">
        <button :aria-pressed="scope === 'active'" :class="{ selected: scope === 'active' }" @click="scope = 'active'; load(1)">Aktif</button>
        <button :aria-pressed="scope === 'archived'" :class="{ selected: scope === 'archived' }" @click="scope = 'archived'; load(1)">Arsip</button>
        <button :aria-pressed="scope === 'all'" :class="{ selected: scope === 'all' }" @click="scope = 'all'; load(1)">Semua</button>
      </div>
    </section>
    <p v-if="loading" class="participants-empty" role="status">Memuat peserta…</p>
    <p v-else-if="!data?.participants.length" class="participants-empty">Belum ada peserta pada pilihan ini.</p>
    <section v-else class="participants-list" aria-label="Daftar peserta">
      <article v-for="item in data.participants" :key="item.id" class="participant-card">
        <div class="participant-title"><div><h2>{{ item.nama_bapak }}</h2><p>{{ item.no_hp || 'Nomor HP belum diisi' }}</p></div><span :class="['participant-badge', item.status_aktif ? 'active' : 'archived']">{{ item.status_aktif ? 'Aktif' : 'Arsip' }}</span></div>
        <p class="participant-children"><strong>Anak:</strong> {{ item.anak.map(child => `${child.nama_anak}${child.kelas ? ` · ${child.kelas}` : ''}`).join(', ') || 'Belum ada data anak' }}</p>
        <p class="participant-meta">{{ item.nama_halaqoh || 'Belum ditempatkan di kelompok' }}<span v-if="item.nama_ustadz_halaqoh"> · {{ item.nama_ustadz_halaqoh }}</span></p>
        <div class="participant-actions"><button @click="edit(item)">Ubah</button><button v-if="item.status_aktif && !item.family_managed" class="archive-button" @click="archive(item)">Arsipkan</button></div>
      </article>
    </section>
    <nav v-if="data && pages > 1" class="participants-pagination" aria-label="Halaman peserta"><button :disabled="loading || page <= 1" @click="load(page - 1)">Sebelumnya</button><span>{{ page }} / {{ pages }} · {{ data.total }} peserta</span><button :disabled="loading || page >= pages" @click="load(page + 1)">Berikutnya</button></nav>

    <div v-if="showForm" class="participant-modal-backdrop" @click.self="showForm = false">
      <section class="participant-modal" role="dialog" aria-modal="true" :aria-labelledby="'participant-form-title'">
        <header><div><span class="participants-kicker">{{ editing ? 'UBAH DATA' : 'DATA BARU' }}</span><h2 id="participant-form-title">{{ editing ? form.nama_bapak : 'Tambah peserta' }}</h2></div><button class="modal-close" aria-label="Tutup" @click="showForm = false">×</button></header>
        <form @submit.prevent="save">
          <div v-if="form.family_managed" class="participants-info">Biodata anak dan orang tua dikelola oleh master keluarga. Pengaturan Tahsin tetap dapat diubah.</div>
          <fieldset :disabled="form.family_managed"><legend>Data orang tua</legend><label>Nama orang tua<input v-model="form.nama_bapak" required maxlength="100"></label><label>Nomor HP<input v-model="form.no_hp" inputmode="tel" maxlength="20"></label><label>Alamat<textarea v-model="form.alamat" rows="2" maxlength="10000"></textarea></label></fieldset>
          <fieldset><legend>Pengaturan Tahsin</legend><label>Kategori<select v-model="form.kategori"><option value="reguler">Reguler</option><option value="tahsin_luar">Tahsin luar</option><option value="askar">Askar</option></select></label><label>Tempat Tahsin<input v-model="form.tempat_tahsin" maxlength="255"></label><label>Ustadz luar<input v-model="form.ustadz_luar" maxlength="100"></label><label class="participant-check"><input v-model="form.lanjut_tahsin" type="checkbox"> Lanjut Tahsin</label><label>Kelompok<select v-model.number="form.halaqoh_id"><option :value="0">Tanpa kelompok</option><option v-for="group in data?.groups || []" :key="group.id" :value="group.id">{{ group.nama_halaqoh }} · {{ group.program_code === 'ibu' ? 'Ibu' : 'Bapak' }}</option></select></label></fieldset>
          <fieldset :disabled="!form.children_editable"><legend>Data anak</legend><p class="child-preserve-note">{{ form.children_editable ? 'Data anak yang tidak diubah tetap dipertahankan; layar ini tidak menghapus anak atau ID lama.' : 'Data anak dikelola master keluarga dan tidak dapat diubah dari formulir peserta.' }}</p><div v-for="(child, index) in form.anak" :key="child.id ? `child-${child.id}` : `new-${index}`" class="participant-child-inputs"><label>Nama anak<input v-model="child.nama_anak" :required="child.id > 0 || !!child.kelas" maxlength="100"></label><label>Kelas<input v-model="child.kelas" maxlength="20" placeholder="Contoh: Kelas 2 atau Lulus"></label></div><button v-if="form.children_editable" type="button" class="add-child" @click="addChild">Tambah anak</button></fieldset>
          <p v-if="error && showForm" class="participants-error" role="alert">{{ error }}</p>
          <footer><button type="button" class="participants-secondary" :disabled="saving" @click="showForm = false">Batal</button><button class="participants-primary" :disabled="saving">{{ saving ? 'Menyimpan…' : 'Simpan perubahan' }}</button></footer>
        </form>
      </section>
    </div>
  </div>
</template>

<style scoped>
.participants-page{max-width:1100px;margin:0 auto;display:grid;gap:16px;color:#172b42}.participants-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}.participants-kicker{font-size:11px;font-weight:800;letter-spacing:1.3px;color:#6a7d92}.participants-heading h1{font-size:28px;margin:8px 0}.participants-heading p,.participant-title p,.participant-meta{color:#65788e;font-size:13px;margin:0}.participants-info{margin:0;padding:13px 15px;border:1px solid #d5e3f3;border-radius:12px;background:#f0f6ff;color:#385a7c;font-size:13px;line-height:1.6}.participants-success,.participants-error{padding:12px 14px;border-radius:10px;margin:0;font-size:14px}.participants-success{color:#176447;background:#e8f7ef}.participants-error{color:#9b3931;background:#fff0ee}.participants-error button{margin-left:8px}.participants-controls{display:flex;justify-content:space-between;align-items:end;gap:16px;padding:17px;background:#fff;border:1px solid #e1e8f0;border-radius:14px}.participants-controls form{flex:1;max-width:580px}.participants-controls label,.participant-modal label{display:grid;gap:6px;font-size:12px;font-weight:700;color:#556b80}.participants-controls form>div{display:flex;gap:8px;margin-top:7px}.participants-controls input,.participant-modal input:not([type=checkbox]),.participant-modal textarea,.participant-modal select{width:100%;min-width:0;border:1px solid #cbd7e5;border-radius:9px;padding:10px 11px;background:#fff;color:#172b42;font:inherit}.participants-controls button,.participants-scopes button,.participant-actions button,.participants-pagination button,.participants-secondary,.add-child{border:1px solid #d2dce8;background:#fff;color:#36516d;border-radius:8px;padding:9px 12px;font:inherit;cursor:pointer}.participants-controls form button{background:#245ad1;color:#fff;border-color:#245ad1}.participants-scopes{display:flex;gap:4px;padding:3px;border:1px solid #d7e1ec;border-radius:10px;background:#f6f8fb}.participants-scopes button{border:0;background:transparent}.participants-scopes button.selected{background:#e4edff;color:#1d4eac;font-weight:700}.participants-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.participant-card{background:#fff;border:1px solid #e1e8f0;border-radius:14px;padding:17px;min-width:0}.participant-title{display:flex;justify-content:space-between;gap:12px}.participant-title h2{font-size:16px;margin:0 0 5px;overflow-wrap:anywhere}.participant-badge{height:max-content;border-radius:20px;padding:4px 9px;font-size:11px;font-weight:700}.participant-badge.active{background:#e5f5ec;color:#18784d}.participant-badge.archived{background:#f0f2f5;color:#667486}.participant-children{font-size:13px;line-height:1.6;margin:13px 0 8px;overflow-wrap:anywhere}.participant-meta{line-height:1.6}.participant-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}.participant-actions .archive-button{color:#9a3d35}.participants-empty{padding:32px 16px;text-align:center;color:#65788e;background:#fff;border:1px solid #e1e8f0;border-radius:14px}.participants-pagination{display:flex;justify-content:center;align-items:center;gap:16px;font-size:13px}.participant-modal-backdrop{position:fixed;inset:0;z-index:50;background:#13243888;display:grid;place-items:center;padding:16px}.participant-modal{width:min(620px,100%);max-height:min(92dvh,900px);overflow:auto;background:#fff;border-radius:16px;padding:20px;box-shadow:0 20px 70px #12243b44}.participant-modal>header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:17px}.participant-modal h2{font-size:21px;margin:6px 0}.modal-close{border:0;background:#eef2f7;border-radius:50%;width:34px;height:34px;font-size:22px;cursor:pointer}.participant-modal form{display:grid;gap:13px}.participant-modal fieldset{border:1px solid #e1e8f0;border-radius:12px;padding:13px;display:grid;gap:11px;min-width:0}.participant-modal legend{padding:0 5px;font-size:13px;font-weight:800}.participant-modal fieldset:disabled{opacity:.65}.participant-check{display:flex!important;align-items:center;gap:8px!important}.participant-check input{width:18px;height:18px}.participant-child-inputs{display:grid;grid-template-columns:1fr 130px;gap:8px}.child-preserve-note{margin:0;color:#63778d;font-size:12px;line-height:1.5}.add-child{justify-self:start}.participant-modal footer{display:flex;justify-content:flex-end;gap:8px;margin-top:3px}.participants-primary{border:0;border-radius:9px;padding:10px 15px;background:#245ad1;color:#fff;font:inherit;font-weight:700;cursor:pointer}.participants-primary:disabled,.participants-controls button:disabled{opacity:.55;cursor:default}.participants-secondary{font:inherit}
@media(max-width:700px){.participants-heading{display:block}.participants-heading>.participants-primary{margin-top:13px;width:100%}.participants-controls{display:grid;align-items:stretch}.participants-controls form{max-width:none}.participants-scopes{width:max-content;max-width:100%}.participants-scopes button{padding:8px 10px}.participants-list{grid-template-columns:1fr}.participant-card{padding:15px}.participant-modal{padding:16px}.participant-child-inputs{grid-template-columns:1fr 105px}}
</style>
