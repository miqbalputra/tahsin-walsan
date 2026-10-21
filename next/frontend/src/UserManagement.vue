<script setup lang="ts">
import { onMounted, ref } from 'vue'

type ManagedUser = { id: number; username: string; nama_lengkap: string; role: string; no_hp: string; identity_locked: boolean }
type Envelope<T> = { status: string; data: T; message?: string }
const props = defineProps<{ csrf: string }>()
const emit = defineEmits<{ unauthorized: [] }>()
const users = ref<ManagedUser[]>([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const open = ref(false)
const editing = ref(false)
const form = ref<ManagedUser & { password: string }>(blankUser())
const roles = [{ value: 'admin', label: 'Admin' }, { value: 'pj_tahfidz', label: 'PJ Tahfidz' }, { value: 'kepsek', label: 'Kepala Sekolah' }, { value: 'ustadz', label: 'Ustadz' }]

function blankUser(): ManagedUser & { password: string } {
  return { id: 0, username: '', nama_lengkap: '', role: 'ustadz', no_hp: '', identity_locked: false, password: '' }
}

async function request<T>(url: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (options.body) headers.set('Content-Type', 'application/json')
  if (options.method && options.method !== 'GET') headers.set('X-CSRF-Token', props.csrf)
  const response = await fetch(url, { ...options, headers, credentials: 'same-origin', cache: 'no-store' })
  let body: Envelope<T>
  try { body = await response.json() as Envelope<T> } catch { throw new Error('Respons server tidak dapat dibaca.') }
  if (!response.ok || body.status !== 'ok') {
    if (response.status === 401) emit('unauthorized')
    throw new Error(body.message || 'Permintaan belum berhasil.')
  }
  return body.data
}

async function load() {
  loading.value = true
  error.value = ''
  try { users.value = await request<ManagedUser[]>('/api/v1/users') }
  catch (e) { error.value = e instanceof Error ? e.message : 'Daftar user belum dapat dibaca.' }
  finally { loading.value = false }
}

function add() { editing.value = false; form.value = blankUser(); open.value = true; error.value = '' }
function edit(user: ManagedUser) { editing.value = true; form.value = { ...user, password: '' }; open.value = true; error.value = '' }

async function save() {
  saving.value = true
  error.value = ''
  notice.value = ''
  const payload = { username: form.value.username, nama_lengkap: form.value.nama_lengkap, role: form.value.role, no_hp: form.value.no_hp, password: form.value.password }
  try {
    await request(editing.value ? `/api/v1/users/${form.value.id}` : '/api/v1/users', { method: editing.value ? 'PATCH' : 'POST', body: JSON.stringify(payload) })
    open.value = false
    notice.value = editing.value ? 'User berhasil diperbarui.' : 'User berhasil ditambahkan.'
    await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'User belum dapat disimpan.' }
  finally { saving.value = false }
}

onMounted(load)
</script>

<template>
  <div class="users-page">
    <header class="users-heading"><div><span class="users-kicker">ADMINISTRASI</span><h1>Pengguna</h1><p>Kelola akun login dan hak akses aplikasi.</p></div><button class="users-primary" @click="add">Tambah user</button></header>
    <p class="users-info">Password lama tidak pernah ditampilkan. Kosongkan password saat mengubah akun untuk mempertahankan kata sandi yang ada. Nama dan nomor HP yang dikelola Data Induk terkunci di aplikasi ini.</p>
    <p v-if="notice" class="users-success" role="status">{{ notice }}</p>
    <p v-if="error && !open" class="users-error" role="alert">{{ error }} <button @click="load">Coba lagi</button></p>
    <p v-if="loading" class="users-empty" role="status">Memuat akun…</p>
    <p v-else-if="users.length === 0" class="users-empty">Belum ada akun.</p>
    <section v-else class="users-list" aria-label="Daftar user">
      <article v-for="user in users" :key="user.id" class="user-card"><div class="user-card-main"><div><h2>{{ user.nama_lengkap }}</h2><p>@{{ user.username }}<span v-if="user.no_hp"> · {{ user.no_hp }}</span></p></div><span :class="['user-role', `role-${user.role}`]">{{ roles.find(role => role.value === user.role)?.label || user.role }}</span></div><div class="user-card-actions"><span v-if="user.identity_locked" class="user-locked">Identitas dari Data Induk</span><button @click="edit(user)">Ubah akun</button></div></article>
    </section>
    <div v-if="open" class="user-modal-backdrop" @click.self="open = false"><section class="user-modal" role="dialog" aria-modal="true" aria-labelledby="user-form-title"><header><div><span class="users-kicker">{{ editing ? 'UBAH AKUN' : 'AKUN BARU' }}</span><h2 id="user-form-title">{{ editing ? form.nama_lengkap : 'Tambah user' }}</h2></div><button class="user-close" aria-label="Tutup" @click="open = false">×</button></header><form @submit.prevent="save"><p v-if="form.identity_locked" class="users-info">Identitas guru dikelola Data Induk. Username, role, dan password tetap dapat dikelola di sini.</p><label>Nama lengkap<input v-model="form.nama_lengkap" required maxlength="100" :readonly="form.identity_locked"></label><label>Username<input v-model="form.username" required maxlength="50" autocomplete="username"></label><label>Role<select v-model="form.role"><option v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</option></select></label><label>Nomor HP<input v-model="form.no_hp" maxlength="20" inputmode="tel" :readonly="form.identity_locked"></label><label>Password<input v-model="form.password" :required="!editing" :minlength="8" maxlength="72" type="password" autocomplete="new-password"><small>{{ editing ? 'Kosongkan untuk mempertahankan password saat ini.' : 'Gunakan minimal 8 karakter.' }}</small></label><p v-if="error && open" class="users-error" role="alert">{{ error }}</p><footer><button type="button" class="users-secondary" :disabled="saving" @click="open = false">Batal</button><button class="users-primary" :disabled="saving">{{ saving ? 'Menyimpan…' : 'Simpan user' }}</button></footer></form></section></div>
  </div>
</template>

<style scoped>
.users-page{max-width:1000px;margin:0 auto;display:grid;gap:16px;color:#172b42}.users-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}.users-kicker{font-size:11px;font-weight:800;letter-spacing:1.3px;color:#6a7d92}.users-heading h1{font-size:28px;margin:8px 0}.users-heading p,.user-card-main p{margin:0;color:#65788e;font-size:13px}.users-primary{border:0;border-radius:9px;padding:10px 15px;background:#245ad1;color:#fff;font:inherit;font-weight:700;cursor:pointer}.users-primary:disabled{opacity:.55}.users-info{margin:0;padding:13px 15px;border:1px solid #d5e3f3;border-radius:12px;background:#f0f6ff;color:#385a7c;font-size:13px;line-height:1.6}.users-success,.users-error{padding:12px 14px;border-radius:10px;margin:0;font-size:14px}.users-success{color:#176447;background:#e8f7ef}.users-error{color:#9b3931;background:#fff0ee}.users-error button{margin-left:8px}.users-list{display:grid;gap:10px}.user-card{background:#fff;border:1px solid #e1e8f0;border-radius:14px;padding:16px}.user-card-main{display:flex;justify-content:space-between;gap:12px}.user-card-main h2{font-size:16px;margin:0 0 5px}.user-role{height:max-content;border-radius:20px;padding:4px 9px;font-size:11px;font-weight:700;background:#e7efff;color:#2452a6}.role-admin{background:#fff0ee;color:#993e35}.role-pj_tahfidz{background:#f2eaff;color:#643d92}.role-kepsek{background:#fff4dc;color:#875e13}.user-card-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-top:12px}.user-card-actions button,.users-secondary{border:1px solid #d2dce8;background:#fff;color:#36516d;border-radius:8px;padding:8px 11px;font:inherit;cursor:pointer}.user-locked{font-size:11px;color:#687b90}.users-empty{padding:32px 16px;text-align:center;color:#65788e;background:#fff;border:1px solid #e1e8f0;border-radius:14px}.user-modal-backdrop{position:fixed;inset:0;z-index:51;background:#13243888;display:grid;place-items:center;padding:16px}.user-modal{width:min(500px,100%);max-height:92dvh;overflow:auto;background:#fff;border-radius:16px;padding:20px;box-shadow:0 20px 70px #12243b44}.user-modal>header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px}.user-modal h2{font-size:21px;margin:6px 0}.user-close{border:0;background:#eef2f7;border-radius:50%;width:34px;height:34px;font-size:22px;cursor:pointer}.user-modal form{display:grid;gap:12px}.user-modal label{display:grid;gap:6px;font-size:12px;font-weight:700;color:#556b80}.user-modal input,.user-modal select{width:100%;border:1px solid #cbd7e5;border-radius:9px;padding:10px 11px;background:#fff;color:#172b42;font:inherit}.user-modal input[readonly]{background:#f1f4f8;color:#627387}.user-modal small{font-weight:400;color:#65788e}.user-modal footer{display:flex;justify-content:flex-end;gap:8px;margin-top:6px}.users-secondary:disabled{opacity:.55}
@media(max-width:650px){.users-heading{display:block}.users-heading>.users-primary{width:100%;margin-top:13px}.user-card{padding:14px}.user-card-main{align-items:flex-start}.user-card-actions{justify-content:space-between}}
</style>
