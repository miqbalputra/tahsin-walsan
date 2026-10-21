import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import Portal from './Portal.vue'
import './app.css'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', redirect: '/hari-ini' },
    { path: '/dashboard.php', redirect: '/dashboard' },
    { path: '/logs.php', redirect: '/logs' },
    { path: '/pengaturan-rapor.php', redirect: '/pengaturan-rapor' },
    { path: '/ustadz/dashboard.php', redirect: '/hari-ini' },
    { path: '/ustadz/hari-ini.php', redirect: '/hari-ini' },
    { path: '/ustadz/input-presensi.php', redirect: '/sesi' },
    { path: '/ustadz/form-presensi.php', redirect: to => ({ path: '/sesi', query: to.query }) },
    { path: '/ustadz/riwayat.php', redirect: '/riwayat' },
    { path: '/ustadz/akun.php', redirect: '/akun' },
    { path: '/peserta.php', redirect: '/peserta' },
    { path: '/keluarga.php', redirect: '/keluarga' },
    { path: '/halaqoh.php', redirect: '/halaqoh' },
    { path: '/halaqoh-members.php', redirect: '/halaqoh' },
    { path: '/users.php', redirect: '/users' },
    { path: '/laporan.php', redirect: '/laporan' },
    { path: '/laporan-bulanan.php', redirect: '/laporan' },
    { path: '/progress-wali.php', redirect: '/progress' },
    { path: '/rekap-halaqoh.php', redirect: '/rekap' },
    { path: '/rekap-lengkap.php', redirect: '/rekap' },
    { path: '/capaian.php', redirect: '/capaian' },
    { path: '/libur.php', redirect: '/libur' },
    { path: '/pengumuman.php', redirect: '/pengumuman' },
    { path: '/data-induk.php', redirect: '/data-induk' },
    { path: '/backup-restore.php', redirect: '/backup' },
    { path: '/login.php', redirect: '/login' },
    { path: '/:pathMatch(.*)*', component: Portal },
  ],
})

createApp(Portal).use(router).mount('#app')

const autosaveStyles = document.createElement('link')
autosaveStyles.rel = 'stylesheet'
autosaveStyles.href = '/assets/css/tahsin-autosave.css'
document.head.append(autosaveStyles)

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/app-sw.js').catch(() => undefined)
  })
}
