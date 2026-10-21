<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from 'vue'
import type { TeacherBoot } from './teacher'

const props = defineProps<{ boot: TeacherBoot }>()
const editor = ref<HTMLElement>()
const labels = { H: 'Hadir', S: 'Sakit', I: 'Izin', A: 'Alfa' }
// Transitional adapter: Vue renders once, then the shared legacy controller owns
// this subtree. It never creates a second draft store, sender or interpretation of receipts.
const start = () => {
  if (!editor.value || !window.TahsinAutosave) return
  window.TahsinAutosave.mountEditor(editor.value, props.boot).then(() => {
    document.getElementById('vue-teacher-root')?.setAttribute('aria-busy', 'false')
  }).catch(() => {
    document.getElementById('vue-teacher-root')?.setAttribute('aria-busy', 'false')
  })
}
onMounted(() => {
  if (window.TahsinAutosave) start()
  else window.addEventListener('tahsin-autosave-ready', start, { once: true })
})
onBeforeUnmount(() => window.removeEventListener('tahsin-autosave-ready', start))
</script>

<template>
  <section id="tahsin-autosave" ref="editor" v-once data-vue-editor="true" class="tas-app" aria-label="Pencatatan Tahsin otomatis" inert>
    <header class="tas-heading">
      <a href="hari-ini.php" class="tas-back">← Hari Ini</a><p class="tas-eyebrow">{{ boot.view.title }}</p><h1>{{ boot.view.group_name }}</h1>
      <form method="get" class="tas-date"><input type="hidden" name="id" :value="boot.halaqoh_id"><label>Tanggal pertemuan <input type="date" name="tanggal" :value="boot.tanggal" :max="boot.view.today" required></label><button type="submit" class="tas-button">Buka</button></form>
      <p>Sesi Ahad {{ boot.view.session_label }} · Presensi dan capaian tersimpan otomatis.</p>
    </header>
    <div data-banner class="tas-alert" role="status" hidden></div>
    <p v-if="!boot.state.active" class="tas-alert">Sesi ini libur. Data tersimpan dapat dilihat, pencatatan baru tidak dikirim.</p>
    <div class="tas-overview" aria-live="polite"><strong data-progress>Memuat keadaan penyimpanan…</strong><span data-pending></span></div>
    <div class="tas-toolbar"><label class="tas-search"><span class="sr-only">Cari peserta atau anak</span><input type="search" data-search placeholder="Cari nama peserta atau anak…" autocomplete="off"></label><div class="tas-filters" aria-label="Filter peserta"><button type="button" data-filter="all" aria-pressed="true">Semua</button><button type="button" data-filter="incomplete" aria-pressed="false">Belum lengkap</button></div></div>
    <div class="tas-list">
      <article v-for="member in boot.view.members" :key="member.id" class="tas-person" :data-person="member.id" :data-search-name="member.name + ' ' + member.children">
        <div class="tas-person-header"><div><h2>{{ member.name }}</h2><p v-if="member.children" class="tas-child">Anak: {{ member.children }}</p></div><span data-completeness class="tas-badge">Belum dicatat</span></div>
        <div class="tas-status" role="group" :aria-label="'Kehadiran ' + member.name"><button v-for="(label, code) in labels" :key="code" type="button" :data-status="code" aria-pressed="false">{{ label }}</button></div>
        <button type="button" class="tas-details-toggle" data-toggle aria-expanded="false" :aria-controls="'tas-detail-' + member.id"><span data-detail-label>Capaian / keterangan</span><span aria-hidden="true">⌄</span></button>
        <div class="tas-detail" :id="'tas-detail-' + member.id" hidden>
          <div data-achievement hidden>
            <p class="tas-reference">Referensi terakhir: {{ member.reference }}. Konfirmasikan bacaan hari ini.</p>
            <div class="tas-grid">
              <label>Materi<select data-field="jenis_materi"><option value="">Pilih materi</option><option value="Iqro">Iqro</option><option value="Al Quran">Al-Qur’an</option></select></label>
              <label data-iqro>Jilid<select data-field="jilid"><option value="">Pilih jilid</option><option v-for="j in 6" :key="j" :value="j">{{ j }}</option></select></label>
              <label data-quran hidden>Surat dari<input data-field="nama_surat_dari" list="tas-surahs" maxlength="100" placeholder="Cari surat" autocomplete="off"></label>
              <label data-quran hidden>Surat sampai<input data-field="nama_surat_sampai" list="tas-surahs" maxlength="100" placeholder="Cari surat" autocomplete="off"></label>
              <label><span data-range-start>Halaman dari</span><input data-field="halaman_dari" type="text" inputmode="numeric" maxlength="4" autocomplete="off"></label>
              <label><span data-range-end>Halaman sampai</span><input data-field="halaman_sampai" type="text" inputmode="numeric" maxlength="4" autocomplete="off"></label>
            </div>
            <div class="tas-result" role="group" aria-label="Hasil bacaan"><span>Hasil hari ini</span><button type="button" data-result="Lulus" aria-pressed="false">Lulus</button><button type="button" data-result="Ulang" aria-pressed="false">Ulang</button></div>
          </div>
          <label data-reason hidden>Alasan / keterangan<input data-field="alasan" maxlength="500" placeholder="Tulis keterangan singkat" autocomplete="off"></label>
          <p data-missing class="tas-missing"></p><p data-official-note class="tas-missing" hidden>Laporan masih memakai catatan resmi sebelumnya sampai perubahan ini lengkap.</p>
          <div data-conflict class="tas-conflict" hidden><strong>Perubahan perlu ditinjau</strong><p data-conflict-message></p><p data-server-version></p><button type="button" data-resolve="server" class="tas-button">Gunakan versi server</button><button type="button" data-resolve="mine" class="tas-button">Pertahankan isian saya</button></div>
          <button type="button" class="tas-button tas-next" data-next>Berikutnya ↓</button>
        </div>
        <div class="tas-save"><span data-save-status role="status">Memuat…</span><button type="button" data-retry hidden>Coba Lagi</button></div>
      </article>
    </div>
    <p data-no-results hidden>Tidak ada peserta yang sesuai.</p><p v-if="!boot.view.members.length" class="tas-alert">Belum ada peserta untuk sesi ini. Hubungi admin untuk memeriksa penempatan.</p>
    <section class="tas-summary" data-summary hidden tabindex="-1"><h2>Ringkasan sesi</h2><div data-summary-counts></div><p data-summary-state></p><button type="button" class="tas-button tas-primary" data-finalize>Selesaikan Sesi</button></section>
    <div class="tas-bottom"><span data-bottom-state>Perubahan disimpan otomatis</span><button type="button" class="tas-button tas-primary" data-show-summary>Lihat Ringkasan</button></div>
    <nav class="tas-nav" aria-label="Navigasi guru"><a href="hari-ini.php" aria-current="page">Hari Ini</a><a href="riwayat.php">Riwayat</a><a href="akun.php">Akun</a></nav>
    <datalist id="tas-surahs"><option v-for="name in boot.view.surahs" :key="name" :value="name"></option></datalist>
  </section>
</template>
