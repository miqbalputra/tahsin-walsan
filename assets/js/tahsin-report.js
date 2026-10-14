(() => {
  const dataNode = document.getElementById('tahsin-chart-data');
  let chart;
  if (dataNode && typeof Chart !== 'undefined') {
    const trend = JSON.parse(dataNode.textContent);
    const colors = {hadir: '#10b981', sakit: '#38bdf8', izin: '#fbbf24', alpha: '#fb7185', missing_presensi: '#a78bfa', conflicts: '#f59e0b'};
    const labels = {hadir: 'Hadir', sakit: 'Sakit', izin: 'Izin', alpha: 'Alfa tercatat', missing_presensi: 'Belum diinput (roster diketahui)', conflicts: 'Perlu verifikasi'};
    chart = new Chart(document.getElementById('tahsin-trend'), {
      type: 'bar', data: {labels: trend.map(r => r.tanggal), datasets: Object.keys(colors).map(key => ({label: labels[key], data: trend.map(r => r[key]), backgroundColor: colors[key]}))},
      options: {responsive: true, maintainAspectRatio: false, animation: false, scales: {x: {stacked: true}, y: {stacked: true, beginAtZero: true, ticks: {precision: 0}}}, plugins: {legend: {position: 'bottom'}}}
    });
  }
  let previouslyOpen = [];
  window.addEventListener('beforeprint', () => {
    previouslyOpen = [...document.querySelectorAll('.tahsin-detail:not([open])')];
    previouslyOpen.forEach(node => node.open = true);
    if (chart) chart.resize(1000, 180);
  });
  window.addEventListener('afterprint', () => { previouslyOpen.forEach(node => node.open = false); if (chart) chart.resize(); });
  document.querySelectorAll('[data-copy-message]').forEach(button => button.addEventListener('click', async () => {
    const field = document.getElementById(button.dataset.copyMessage);
    try { await navigator.clipboard.writeText(field.value); button.textContent = 'Pesan disalin'; }
    catch (_) { field.focus(); field.select(); button.textContent = 'Pesan dipilih — salin dengan Ctrl+C'; }
  }));
})();
