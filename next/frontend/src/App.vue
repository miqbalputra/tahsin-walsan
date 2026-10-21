<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { readFamilies, type Result, type Guardian } from './api'

const props = defineProps<{ endpoint: string; hideBackLink?: boolean }>()
const result = ref<Result | null>(null)
const loading = ref(false)
const error = ref('')
const search = ref('')
const scope = ref<'active' | 'all'>('active')
const page = ref(1)
let controller: AbortController | undefined
let generation = 0
const students = computed(() => result.value?.data || [])
const total = computed(() => result.value?.meta.total || 0)
const pages = computed(() => Math.max(1, Math.ceil(total.value / 25)))
const labels: Record<string, string> = { active: 'Aktif', graduated: 'Lulus', withdrawn: 'Keluar', transferred: 'Pindah', archived: 'Arsip', unknown: 'Perlu ditinjau' }
const guardianLabel = (g: Guardian) => g.guardian_type === 'mother' ? 'Ibu' : g.guardian_type === 'father' ? 'Bapak' : 'Wali terdata'

async function load(targetPage = 1) {
  controller?.abort()
  const current = ++generation
  const request = new AbortController()
  controller = request
  const timeout = window.setTimeout(() => request.abort(), 10000)
  loading.value = true
  error.value = ''
  try {
    const fresh = await readFamilies(props.endpoint, search.value.trim(), scope.value, targetPage, request.signal)
    if (current !== generation) return
    result.value = fresh
    page.value = fresh.meta.page
  } catch (e) {
    if (current !== generation) return
    result.value = null
    error.value = request.signal.aborted ? 'Pembacaan data terlalu lama. Coba lagi atau buka aplikasi utama.' : e instanceof Error ? e.message : 'Pratinjau belum tersedia.'
  } finally {
    window.clearTimeout(timeout)
    if (current === generation) loading.value = false
  }
}
onMounted(() => load())
onBeforeUnmount(() => { generation++; controller?.abort() })
</script>

<template>
  <div class="nxt-wrap">
    <header class="nxt-heading">
      <div><span class="nxt-kicker">PRATINJAU · BACA DATA</span><h1>Keluarga Anak–Bapak–Ibu</h1><p>Lihat peserta aktif dan hubungan keluarga. Perubahan data tetap dilakukan di aplikasi utama.</p></div>
      <a v-if="!hideBackLink" href="keluarga.php" class="nxt-back">Buka Keluarga utama ↗</a>
    </header>

    <p v-if="result?.meta.data_source === 'demo'" class="nxt-banner" role="status" data-demo>Data contoh untuk pengujian. Daftar ini tidak memakai data keluarga sebenarnya.</p>
    <p v-if="result?.meta.schema === 'legacy'" class="nxt-banner nxt-muted" data-legacy>Data berasal dari pencatatan lama. Relasi Bapak–Ibu ditampilkan setelah terhubung ke master keluarga.</p>

    <section class="nxt-controls" aria-label="Pencarian keluarga">
      <form class="nxt-search" @submit.prevent="load(1)"><label for="nxt-search">Cari anak atau orang tua</label><div><input id="nxt-search" v-model="search" maxlength="100" type="search" placeholder="Nama anak, NIS, atau nama wali" autocomplete="off"><button type="submit" :disabled="loading">Cari</button></div></form>
      <div class="nxt-scope"><span>Tampilkan</span><div role="group" aria-label="Cakupan anak"><button :aria-pressed="scope === 'active'" :class="{ selected: scope === 'active' }" @click="scope = 'active'; load(1)">Anak aktif</button><button :aria-pressed="scope === 'all'" :class="{ selected: scope === 'all' }" @click="scope = 'all'; load(1)">Semua anak</button></div></div>
    </section>

    <section class="nxt-results" :aria-busy="loading" aria-label="Hasil keluarga">
      <div class="nxt-results-heading"><h2>Daftar anak <span v-if="result">{{ total }}</span></h2><p v-if="result">{{ scope === 'active' ? 'Anak yang masih aktif dan memiliki kelas' : 'Termasuk anak lulus, pindah, atau keluar' }}</p></div>
      <p v-if="loading" role="status" class="nxt-empty">Memuat data keluarga…</p>
      <div v-else-if="error" role="alert" class="nxt-error"><p>{{ error }}</p><button @click="load(page)">Coba lagi</button><a href="keluarga.php">Buka aplikasi utama</a></div>
      <p v-else-if="students.length === 0" class="nxt-empty">Belum ada anak yang sesuai dengan pencarian ini.</p>
      <div v-else class="nxt-grid">
        <article v-for="child in students" :key="`${result?.meta.schema}:${child.id}`" class="nxt-child" :data-student="child.id">
          <div class="nxt-child-top"><h3>{{ child.nama_anak }}</h3><span class="nxt-status" :class="{ active: child.school_status === 'active' }">{{ labels[child.school_status] || 'Perlu ditinjau' }}</span></div>
          <p class="nxt-class">{{ child.kelas || 'Tidak ada kelas aktif' }}<span v-if="child.nis"> · NIS {{ child.nis }}</span></p>
          <dl v-if="child.guardians.length" class="nxt-parents"><div v-for="guardian in child.guardians" :key="guardian.wali_santri_id"><dt>{{ guardianLabel(guardian) }}</dt><dd>{{ guardian.source_name }}</dd></div></dl>
          <p v-else class="nxt-incomplete">Relasi orang tua belum tersedia di sumber.</p>
          <p v-if="result?.meta.schema === 'family' && !child.guardians.some(g => g.guardian_type === 'father')" class="nxt-incomplete">Relasi Bapak belum tersedia.</p>
          <p v-if="result?.meta.schema === 'family' && !child.guardians.some(g => g.guardian_type === 'mother')" class="nxt-incomplete">Relasi Ibu belum tersedia.</p>
        </article>
      </div>
      <nav v-if="result && pages > 1" class="nxt-pagination" aria-label="Halaman daftar anak"><button :disabled="loading || page <= 1" @click="load(page - 1)">Sebelumnya</button><span>{{ page }} / {{ pages }}</span><button :disabled="loading || page >= pages" @click="load(page + 1)">Berikutnya</button></nav>
    </section>
  </div>
</template>
