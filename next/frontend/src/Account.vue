<script setup lang="ts">
const props = defineProps<{ user: { nama_lengkap: string; username: string; role: string } }>()
const emit = defineEmits<{ logout: [] }>()
const roleNames: Record<string, string> = { admin: 'Administrator', pj_tahfidz: 'Penanggung jawab Tahfidz', kepsek: 'Kepala sekolah', ustadz: 'Guru Tahsin' }
</script>

<template>
  <main class="account-page">
    <header><p class="eyebrow">AKUN</p><h1>{{ props.user.nama_lengkap || props.user.username }}</h1><p>{{ props.user.username }} · {{ roleNames[props.user.role] || props.user.role }}</p></header>
    <section class="panel"><h2>Pendataan otomatis</h2><p>Pilih kehadiran pada daftar peserta. Untuk status Hadir, lengkapi materi, rentang bacaan, dan hasil Lulus/Ulang.</p><p>Draft belum lengkap disimpan otomatis. Periksa status penyimpanan sebelum meninggalkan sesi. Jika koneksi terputus, draft lokal akan dicoba kirim kembali saat aplikasi aktif dan koneksi pulih.</p></section>
    <section class="panel"><h2>Bantuan dan riwayat</h2><p>Hubungi admin untuk perubahan penempatan, jadwal, atau koreksi yang perlu ditinjau.</p><RouterLink v-if="props.user.role === 'ustadz'" to="/riwayat" class="history-link">Buka riwayat presensi</RouterLink></section>
    <button class="logout" @click="emit('logout')">Keluar dari akun</button>
  </main>
</template>

<style scoped>
.account-page{max-width:820px;margin:0 auto;display:grid;gap:14px;color:#17362b}.account-page header{padding:8px 2px}.eyebrow{margin:0;color:#648073;font-size:11px;font-weight:850;letter-spacing:.13em}.account-page h1{margin:5px 0;font-size:clamp(24px,5vw,34px)}.account-page header p:last-child{color:#687a73}.panel{padding:18px;border:1px solid #dce8e2;border-radius:16px;background:white}.panel h2{margin:0 0 8px;font-size:17px}.panel p{color:#62736d;line-height:1.6}.history-link{display:inline-block;margin-top:4px;color:#176b4b;font-weight:750}.logout{justify-self:start;min-height:42px;padding:0 16px;border:0;border-radius:11px;background:#edf3ef;color:#315545;font-weight:750}
</style>
