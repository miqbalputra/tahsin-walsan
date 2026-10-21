<script setup lang="ts">
import { onMounted, ref } from 'vue'

type Settings = { rapor_template: string; pj_tahfidz_name: string; pj_tahfidz_title: string }
type Envelope<T> = { status: string; data: T; message?: string }
const props = defineProps<{ csrf: string }>()
const emit = defineEmits<{ unauthorized: [] }>()
const values = ref<Settings>({ rapor_template: '', pj_tahfidz_name: 'Admin Sekolah', pj_tahfidz_title: 'PJ Tahfidz' })
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')

async function request<T>(method = 'GET'): Promise<T> {
  const headers = new Headers({ Accept: 'application/json' })
  const options: RequestInit = { method, headers, credentials: 'same-origin', cache: 'no-store' }
  if (method !== 'GET') {
    headers.set('Content-Type', 'application/json')
    headers.set('X-CSRF-Token', props.csrf)
    options.body = JSON.stringify(values.value)
  }
  const response = await fetch('/api/v1/settings/report', options)
  const body = await response.json() as Envelope<T>
  if (response.status === 401) emit('unauthorized')
  if (!response.ok || body.status !== 'ok') throw new Error(body.message || 'Pengaturan rapor belum dapat diproses.')
  return body.data
}
async function load() {
  loading.value = true; error.value = ''
  try { values.value = await request<Settings>() }
  catch (e) { error.value = e instanceof Error ? e.message : 'Pengaturan rapor belum dapat dibaca.' }
  finally { loading.value = false }
}
async function save() {
  saving.value = true; error.value = ''; notice.value = ''
  try { values.value = await request<Settings>('PATCH'); notice.value = 'Pengaturan tersimpan ke database lama.' }
  catch (e) { error.value = e instanceof Error ? e.message : 'Pengaturan rapor belum dapat disimpan.' }
  finally { saving.value = false }
}
onMounted(load)
</script>

<template>
  <section class="settings-page"><header><span class="settings-kicker">PENGATURAN ADMIN</span><h1>Template rapor</h1><p>Nama dan jabatan pengesah serta isi template yang tersimpan pada tabel <code>settings</code>.</p></header>
    <p class="settings-warning" role="note"><strong>Catatan migrasi:</strong> isi template lama dipertahankan apa adanya. Runtime Go tidak mengeksekusi PHP; penyajian template rapor sedang dipindahkan ke renderer aman tersendiri. Mengubah template di sini menyimpan nilainya tetapi belum mengubah hasil cetak Go.</p>
    <p v-if="notice" class="settings-success" role="status">{{ notice }}</p><p v-if="error" class="settings-error" role="alert">{{ error }} <button @click="load">Coba lagi</button></p>
    <form class="settings-form" @submit.prevent="save"><label>Nama pengesah<input v-model="values.pj_tahfidz_name" maxlength="100" required></label><label>Jabatan atau gelar<input v-model="values.pj_tahfidz_title" maxlength="100" required></label><label>Template lama (disimpan sebagai teks)<textarea v-model="values.rapor_template" rows="20" spellcheck="false"></textarea></label><div class="settings-actions"><span>{{ loading ? 'Memuat…' : 'Penyimpanan langsung ke database aplikasi' }}</span><button :disabled="loading || saving">{{ saving ? 'Menyimpan…' : 'Simpan pengaturan' }}</button></div></form>
  </section>
</template>

<style scoped>
.settings-page{max-width:1050px;margin:0 auto;display:grid;gap:14px;color:#172b42}.settings-page h1{font-size:28px;margin:7px 0}.settings-page header p{font-size:13px;color:#65788e}.settings-kicker{font-size:10px;font-weight:850;letter-spacing:1.2px;color:#718399}.settings-warning,.settings-success,.settings-error{padding:13px 15px;border-radius:11px;font-size:13px;line-height:1.6}.settings-warning{margin:0;background:#fff8e7;color:#76541c;border:1px solid #f0df9f}.settings-success{margin:0;background:#e8f7ef;color:#176447}.settings-error{margin:0;background:#fff0ee;color:#9b3931}.settings-error button{margin-left:8px}.settings-form{display:grid;gap:14px;padding:18px;background:#fff;border:1px solid #e1e8f0;border-radius:15px}.settings-form label{display:grid;gap:6px;color:#586f84;font-size:12px;font-weight:750}.settings-form input,.settings-form textarea{width:100%;padding:10px 12px;border:1px solid #cfdae6;border-radius:9px;color:#172b42;background:#fff;font:inherit}.settings-form textarea{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:12px;line-height:1.5}.settings-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;color:#7b8b9b;font-size:11px}.settings-actions button{border:0;border-radius:9px;background:#245ad1;color:white;padding:10px 14px;font:inherit;font-weight:800}.settings-actions button:disabled{opacity:.55}@media(max-width:600px){.settings-actions{align-items:stretch;flex-direction:column}.settings-actions button{width:100%}}
</style>
