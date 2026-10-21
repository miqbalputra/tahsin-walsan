<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Teacher from './Teacher.vue'
import FamilyPreview from './App.vue'
import type { TeacherBoot } from './teacher'
import { loadLegacyAutosave } from './autosave-loader'

type User = { id: number; username: string; nama_lengkap: string; role: string }
type Group = { id: number; nama_halaqoh: string; ustadz_id: number; nama_ustadz: string; program_code: string }
type Envelope<T> = { status: string; data: T; message?: string }

const router = useRouter()
const route = useRoute()
const user = ref<User | null>(null)
const csrf = ref('')
const groups = ref<Group[]>([])
const selectedGroup = ref(0)
const selectedDate = ref(localDate())
const boot = ref<TeacherBoot | null>(null)
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const username = ref('')
const password = ref('')
const loginError = ref('')

const menus = computed(() => {
  const role = user.value?.role
  if (role === 'ustadz') return [
    { path: '/hari-ini', label: 'Hari Ini' }, { path: '/riwayat', label: 'Riwayat' }, { path: '/akun', label: 'Akun' },
  ]
  if (role === 'kepsek') return [
    { path: '/dashboard', label: 'Dashboard' }, { path: '/progress', label: 'Progress' }, { path: '/rekap', label: 'Rekap' }, { path: '/laporan', label: 'Laporan' },
  ]
  return [
    { path: '/dashboard', label: 'Dashboard' }, { path: '/peserta', label: 'Peserta' }, { path: '/keluarga', label: 'Keluarga' }, { path: '/halaqoh', label: 'Kelompok' }, { path: '/laporan', label: 'Laporan' }, { path: '/akun', label: 'Akun' },
  ]
})
const screen = computed(() => route.path.replace(/^\//, '') || 'hari-ini')
const isTeacherSession = computed(() => screen.value === 'sesi')
const displayName = computed(() => user.value?.nama_lengkap || user.value?.username || '')

async function api<T>(url: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (options.body) headers.set('Content-Type', 'application/json')
  if (csrf.value && options.method && options.method !== 'GET') headers.set('X-CSRF-Token', csrf.value)
  const response = await fetch(url, { ...options, headers, credentials: 'same-origin', cache: 'no-store' })
  let body: Envelope<T>
  try { body = await response.json() as Envelope<T> } catch { throw new Error('Respons server tidak dapat dibaca.') }
  if (!response.ok || (body.status !== 'success' && body.status !== 'ok')) {
    if (response.status === 401) { user.value = null; boot.value = null }
    const failure = new Error(body.message || 'Permintaan belum berhasil.') as Error & { status?: number }
    failure.status = response.status
    throw failure
  }
  return body.data
}

async function loadIdentity() {
  try {
    const data = await api<{ user: User; csrf_token: string }>('/api/v1/auth/me')
    user.value = data.user
    csrf.value = data.csrf_token
    await loadGroups()
    if (route.path === '/sesi') {
      if (typeof route.query.tanggal === 'string') selectedDate.value = route.query.tanggal
      const groupID = Number(route.query.id)
      if (groupID > 0) await openSession(groupID)
    }
  } catch (e) {
    if ((e as Error & { status?: number }).status !== 401) error.value = (e as Error).message
  } finally { loading.value = false }
}

async function loadGroups() {
  try { groups.value = await api<Group[]>('/api/v1/teacher/groups') }
  catch (e) { error.value = (e as Error).message }
}

async function signIn() {
  busy.value = true
  loginError.value = ''
  try {
    const data = await api<{ user: User; csrf_token: string }>('/api/v1/auth/login', { method: 'POST', body: JSON.stringify({ username: username.value.trim(), password: password.value }) })
    user.value = data.user
    csrf.value = data.csrf_token
    password.value = ''
    await loadGroups()
    await router.replace('/hari-ini')
  } catch (e) { loginError.value = (e as Error).message }
  finally { busy.value = false }
}

async function signOut() {
  try { await api('/api/v1/auth/logout', { method: 'POST', body: '{}' }) }
  catch (e) { error.value = (e as Error).message; return }
  user.value = null
  csrf.value = ''
  boot.value = null
  groups.value = []
  await router.replace('/login')
}

async function openSession(groupID = selectedGroup.value) {
  if (!groupID) { error.value = 'Pilih kelompok terlebih dahulu.'; return }
  selectedGroup.value = groupID
  busy.value = true
  error.value = ''
  boot.value = null
  try {
    await loadLegacyAutosave()
    const result = await api<{ state: TeacherBoot['state']; members: Array<{ id: number; name: string; children: string; reference: string }> }>(`/api/v1/attendance/state?group_id=${groupID}&date=${encodeURIComponent(selectedDate.value)}`)
    const group = groups.value.find(item => item.id === groupID)
    if (!group || !user.value) throw new Error('Kelompok tidak tersedia untuk akun ini.')
    boot.value = {
      user_id: user.value.id,
      halaqoh_id: groupID,
      tanggal: selectedDate.value,
      csrf_token: csrf.value,
      api: '/ustadz/api-autosave.php',
      state: result.state,
      view: { title: group.program_code === 'ibu' ? 'Tahsin Ibu' : 'Tahsin Bapak', group_name: group.nama_halaqoh, today: localDate(), session_label: result.state.session_date, surahs: surahNames, members: result.members },
    }
    if (screen.value !== 'sesi') await router.push({ path: '/sesi', query: { id: String(groupID), tanggal: selectedDate.value } })
  } catch (e) { error.value = (e as Error).message }
  finally { busy.value = false }
}

watch(() => [route.path, route.query.id, route.query.tanggal], async ([path, groupID, date]) => {
  if (!user.value || path !== '/sesi') { boot.value = null; return }
  if (typeof date === 'string') selectedDate.value = date
  const parsedGroup = Number(typeof groupID === 'string' ? groupID : selectedGroup.value)
  if (parsedGroup > 0 && (!boot.value || boot.value.halaqoh_id !== parsedGroup || boot.value.tanggal !== selectedDate.value)) await openSession(parsedGroup)
}, { immediate: true })

onMounted(loadIdentity)

const moduleLabel: Record<string, string> = {
  dashboard: 'Dashboard', peserta: 'Data Peserta', keluarga: 'Keluarga Anak–Bapak–Ibu', halaqoh: 'Data Kelompok', users: 'Data User', riwayat: 'Riwayat Presensi', laporan: 'Laporan', progress: 'Progress', rekap: 'Rekap', akun: 'Akun', capaian: 'Capaian', libur: 'Hari Libur', pengumuman: 'Pengumuman', 'data-induk': 'Sinkronisasi Data Induk', backup: 'Backup & Restore',
}
const surahNames = ['Al-Fatihah','Al-Baqarah',"Ali 'Imran",'An-Nisa',"Al-Ma’idah",'Al-An’am','Al-A’raf','Al-Anfal','At-Taubah','Yunus','Hud','Yusuf','Ar-Ra’d','Ibrahim','Al-Hijr','An-Nahl','Al-Isra','Al-Kahf','Maryam','Taha','Al-Anbiya','Al-Hajj','Al-Mu’minun','An-Nur','Al-Furqan','Ash-Shu’ara','An-Naml','Al-Qasas','Al-Ankabut','Ar-Rum','Luqman','As-Sajdah','Al-Ahzab','Saba','Fatir','Yasin','As-Saffat','Sad','Az-Zumar','Ghafir','Fussilat','Ash-Shura','Az-Zukhruf','Ad-Dukhan','Al-Jathiyah','Al-Ahqaf','Muhammad','Al-Fath','Al-Hujurat','Qaf','Adh-Dhariyat','At-Tur','An-Najm','Al-Qamar','Ar-Rahman','Al-Waqi’ah','Al-Hadid','Al-Mujadilah','Al-Hashr','Al-Mumtahanah','As-Saff','Al-Jumu’ah','Al-Munafiqun','At-Taghabun','At-Talaq','At-Tahrim','Al-Mulk','Al-Qalam','Al-Haqqah','Al-Ma’arij','Nuh','Al-Jinn','Al-Muzzammil','Al-Muddaththir','Al-Qiyamah','Al-Insan','Al-Mursalat','An-Naba','An-Nazi’at','Abasa','At-Takwir','Al-Infitar','Al-Mutaffifin','Al-Inshiqaq','Al-Buruj','At-Tariq','Al-A’la','Al-Ghashiyah','Al-Fajr','Al-Balad','Ash-Shams','Al-Lail','Ad-Duha','Ash-Sharh','At-Tin','Al-Alaq','Al-Qadr','Al-Bayyinah','Az-Zalzalah','Al-Adiyat','Al-Qari’ah','At-Takathur','Al-Asr','Al-Humazah','Al-Fil','Quraysh','Al-Ma’un','Al-Kawthar','Al-Kafirun','An-Nasr','Al-Masad','Al-Ikhlas','Al-Falaq','An-Nas']
function localDate() { return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date()) }
</script>

<template>
  <main v-if="loading" class="center"><p>Memuat sesi…</p></main>
  <main v-else-if="!user" class="login-shell">
    <form class="login-card" @submit.prevent="signIn">
      <div class="brand-mark">ت</div><p class="eyebrow">PRESENSI TAHSIN</p><h1>Selamat datang</h1><p class="muted">Masuk dengan akun yang biasa digunakan. Kata sandi lama tetap berlaku.</p>
      <label>Username<input v-model="username" required autocomplete="username" maxlength="50"></label>
      <label>Password<input v-model="password" required type="password" autocomplete="current-password"></label>
      <p v-if="loginError" class="error" role="alert">{{ loginError }}</p><button class="primary" :disabled="busy">{{ busy ? 'Memeriksa…' : 'Masuk' }}</button>
    </form>
  </main>
  <div v-else class="app-shell">
    <header class="topbar"><div><span class="brand-mark small">ت</span><span class="brand-name">Presensi Tahsin</span></div><div class="account"><span>{{ displayName }}</span><button @click="signOut">Keluar</button></div></header>
    <div v-if="error" class="global-error" role="alert"><span>{{ error }}</span><button @click="error = ''">Tutup</button></div>
    <section v-if="isTeacherSession && boot" class="content teacher-content"><Teacher :key="boot.halaqoh_id + ':' + boot.tanggal" :boot="boot"></Teacher></section>
    <section v-else-if="screen === 'keluarga'" class="content"><div id="go-vue-preview"><FamilyPreview endpoint="/api/v1/families" :hide-back-link="true" /></div></section>
    <section v-else-if="screen === 'hari-ini' || screen === 'sesi'" class="content">
      <div class="page-heading"><p class="eyebrow">{{ user.role === 'ustadz' ? 'HARI INI' : 'TAHSIN' }}</p><h1>{{ user.role === 'ustadz' ? 'Sesi mengajar' : 'Ringkasan Tahsin' }}</h1><p class="muted">Pilih kelompok dan tanggal untuk membuka daftar peserta.</p></div>
      <div v-if="busy" class="panel">Memuat peserta dan capaian…</div>
      <div v-else-if="!groups.length" class="panel">Belum ada kelompok yang dapat ditampilkan untuk akun ini.</div>
      <div v-else class="session-grid"><article v-for="group in groups" :key="group.id" class="session-card"><span class="tag">{{ group.program_code === 'ibu' ? 'Tahsin Ibu' : 'Tahsin Bapak' }}</span><h2>{{ group.nama_halaqoh }}</h2><p class="muted">Pengajar: {{ group.nama_ustadz || 'Belum ditentukan' }}</p><label>Tanggal<input v-model="selectedDate" type="date" :max="new Date().toISOString().slice(0, 10)"></label><button class="primary" :disabled="busy" @click="openSession(group.id)">Buka sesi</button></article></div>
      <p v-if="error" class="error">{{ error }}</p>
    </section>
    <section v-else class="content">
      <div class="page-heading"><p class="eyebrow">{{ user.role.toUpperCase() }}</p><h1>{{ moduleLabel[screen] || 'Tahsin' }}</h1><p class="muted">Modul ini masih berada pada tahap pemindahan dan belum diaktifkan untuk pencatatan operasional.</p></div>
      <div class="panel migration-note"><strong>Data lama tetap menjadi sumber utama.</strong><p>Modul ini belum dialihkan ke Go. Gunakan aplikasi Tahsin yang sedang berjalan sampai uji kesetaraan dan cutover dinyatakan selesai.</p><button class="secondary" @click="router.push('/hari-ini')">Kembali ke sesi</button></div>
    </section>
    <nav class="bottom-nav" aria-label="Navigasi utama"><RouterLink v-for="item in menus" :key="item.path" :to="item.path" :aria-current="route.path === item.path ? 'page' : undefined">{{ item.label }}</RouterLink></nav>
  </div>
</template>
