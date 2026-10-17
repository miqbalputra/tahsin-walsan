(() => {
  const button = document.querySelector('[data-activity-fullscreen]');
  if (!button) return;
  const status = document.querySelector('[data-activity-fullscreen-status]');
  button.addEventListener('click', async () => {
    try {
      if (document.fullscreenElement) await document.exitFullscreen();
      else await document.documentElement.requestFullscreen();
      status.textContent = '';
    } catch (_) {
      status.textContent = 'Layar penuh belum tersedia. Gunakan tombol layar penuh pada browser (F11 di komputer).';
    }
  });
  document.addEventListener('fullscreenchange', () => {
    button.textContent = document.fullscreenElement ? 'Keluar layar penuh' : 'Layar penuh';
  });
})();
