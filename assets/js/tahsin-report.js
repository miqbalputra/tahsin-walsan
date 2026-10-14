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

  const exportNode = document.getElementById('tahsin-meeting-export-data');
  if (exportNode) {
    const exportData = JSON.parse(exportNode.textContent);
    const labels = ['Hadir', 'Sakit', 'Izin', 'Alfa tercatat', 'Belum diinput', 'Capaian belum lengkap', 'Keterangan belum lengkap', 'Perlu verifikasi'];
    const valueKeys = ['hadir', 'sakit', 'izin', 'alpha', 'missing_presensi', 'missing_capaian', 'missing_keterangan', 'conflicts'];
    const text = (value) => String(value ?? '—').replace(/[\r\n]+/g, ' ');
    const fit = (ctx, value, width) => {
      let result = text(value);
      if (ctx.measureText(result).width <= width) return result;
      while (result.length > 1 && ctx.measureText(`${result}…`).width > width) result = result.slice(0, -1);
      return `${result}…`;
    };
    const attachExport = (button) => button.addEventListener('click', () => {
      const payload = exportData.payload;
      const trend = payload.trend || [];
      const groups = payload.halaqoh || [];
      const teachers = payload.guru || [];
      const width = 2000;
      const margin = 52;
      const chartHeight = 430;
      const titleHeight = 220;
      const weeklyHead = 50;
      const weeklyRow = trend.length > 350 ? 28 : 34;
      const groupHead = 44;
      const groupRow = (groups.length + teachers.length) > 250 ? 25 : 31;
      const height = titleHeight + chartHeight + 76 + weeklyHead + trend.length * weeklyRow
        + 42 + groupHead + groups.length * groupRow + 28 + groupHead + teachers.length * groupRow + 68;
      if (height > 32700) {
        window.alert('Rentang ini terlalu panjang untuk satu gambar PNG. Pilih periode lebih pendek; Excel dan PDF tetap memuat seluruh rentang.');
        return;
      }
      try {
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, width, height);
        ctx.fillStyle = '#0f172a'; ctx.font = 'bold 38px Arial';
        ctx.fillText('Ringkasan Statistik Presensi Tahsin', margin, 58);
        ctx.fillStyle = '#475569'; ctx.font = '22px Arial';
        ctx.fillText(`Periode sesi ${exportData.start_date} s/d ${exportData.end_date} · Data agregat tanpa nama wali atau anak`, margin, 98);
        const s = payload.summary || {};
        const totals = [
          `Peserta unik: ${s.peserta_periode ?? 0}`, `Peserta-sesi roster: ${s.peserta_sesi ?? 0}`,
          ...labels.map((label, i) => `${label}: ${s[valueKeys[i]] ?? 0}`),
          `Kehadiran: ${s.hadir_percent === null ? '—' : `${s.hadir_percent}%`}`,
          `Kelengkapan input: ${s.coverage_percent === null ? '—' : `${s.coverage_percent}%`}`,
        ];
        ctx.fillStyle = '#1d4ed8'; ctx.font = 'bold 19px Arial';
        totals.forEach((item, i) => ctx.fillText(item, margin + (i % 5) * 380, 133 + Math.floor(i / 5) * 30));
        let y = titleHeight;
        ctx.fillStyle = '#f8fafc'; ctx.fillRect(margin, y, width - margin * 2, chartHeight);
        const chartCanvas = document.getElementById('tahsin-trend');
        if (chartCanvas && chartCanvas.width && chartCanvas.height) ctx.drawImage(chartCanvas, margin, y + 8, width - margin * 2, chartHeight - 16);
        else {
          ctx.fillStyle = '#64748b'; ctx.font = '22px Arial'; ctx.fillText('Grafik belum tersedia untuk periode ini.', margin + 20, y + 55);
        }
        y += chartHeight + 35;

        const drawTable = (title, headers, rows, rowHeight, widths) => {
          ctx.fillStyle = '#0f172a'; ctx.font = 'bold 25px Arial'; ctx.fillText(title, margin, y + 28); y += 42;
          let x = margin;
          ctx.fillStyle = '#dbeafe'; ctx.fillRect(margin, y, width - margin * 2, weeklyHead);
          ctx.font = 'bold 15px Arial'; ctx.fillStyle = '#1e293b';
          headers.forEach((header, i) => { const w = widths[i]; ctx.fillText(fit(ctx, header, w - 14), x + 7, y + 31); x += w; });
          y += weeklyHead;
          ctx.font = '16px Arial';
          rows.forEach((row, rowIndex) => {
            x = margin;
            ctx.fillStyle = rowIndex % 2 ? '#ffffff' : '#f8fafc'; ctx.fillRect(margin, y, width - margin * 2, rowHeight);
            ctx.fillStyle = '#334155';
            row.forEach((cell, i) => { const w = widths[i]; ctx.fillText(fit(ctx, cell, w - 14), x + 7, y + Math.max(18, rowHeight * .68)); x += w; });
            ctx.strokeStyle = '#e2e8f0'; ctx.beginPath(); ctx.moveTo(margin, y + rowHeight); ctx.lineTo(width - margin, y + rowHeight); ctx.stroke();
            y += rowHeight;
          });
        };
        const weekWidths = [150, 220, 70, 70, 70, 130, 150, 175, 175, 150, 100, 100];
        const weekHeaders = ['Sesi Ahad', 'Jumlah peserta dalam roster', 'Hadir', 'Sakit', 'Izin', 'Alfa tercatat', 'Belum diinput', 'Capaian belum lengkap', 'Keterangan belum lengkap', 'Perlu verifikasi', 'Kehadiran', 'Kelengkapan'];
        const weekRows = trend.map(r => [r.tanggal, r.roster_verified ? r.peserta_roster : 'Belum Terverifikasi', r.hadir, r.sakit, r.izin, r.alpha,
          r.roster_verified ? r.missing_presensi : '—', r.missing_capaian, r.missing_keterangan, r.conflicts,
          r.hadir_percent === null ? '—' : `${r.hadir_percent}%`, r.coverage_percent === null ? '—' : `${r.coverage_percent}%`]);
        drawTable('Statistik per Pertemuan', weekHeaders, weekRows, weeklyRow, weekWidths);
        const groupWidths = [330, 130, 180, 70, 70, 70, 110, 130, 135, 135, 130, 120, 130];
        const groupHeaders = ['Halaqoh', 'Wali dalam periode', 'Peserta-sesi roster diketahui', 'Hadir', 'Sakit', 'Izin', 'Alfa tercatat', 'Belum diinput', 'Capaian kurang', 'Keterangan kurang', 'Perlu verifikasi', 'Kehadiran', 'Kelengkapan'];
        const groupRows = groups.map(g => [g.label, g.wali, `${g.peserta_sesi}${g.roster_unverified ? ' + histori belum terverifikasi' : ''}`, g.hadir, g.sakit, g.izin, g.alpha, g.belum, g.capaian, g.keterangan, g.verifikasi,
          g.hadir_percent === null ? '—' : `${g.hadir_percent}%`, g.coverage_percent === null ? '—' : `${g.coverage_percent}%`]);
        drawTable('Rekap periode per Halaqoh', groupHeaders, groupRows, groupRow, groupWidths);
        const teacherRows = teachers.map(g => [g.label, g.wali, g.peserta_sesi ?? 'Belum Terverifikasi', g.hadir, g.sakit, g.izin, g.alpha, g.belum, `${g.capaian} / ${g.verifikasi}`]);
        drawTable('Rekap periode per Guru', groupHeaders.map((h, i) => i === 0 ? 'Guru' : h), teacherRows, groupRow, groupWidths);
        ctx.fillStyle = '#475569'; ctx.font = '17px Arial';
        ctx.fillText('Data kosong tetap Belum Diinput; Alfa hanya dihitung dari status yang tersimpan. Wali dengan beberapa anak dihitung sekali per sesi.', margin, height - 28);
        canvas.toBlob(blob => {
          if (!blob) { window.alert('Gambar PNG tidak berhasil dibuat. Coba kembali atau gunakan Excel/PDF.'); return; }
          const link = document.createElement('a');
          link.href = URL.createObjectURL(blob);
          link.download = `Ringkasan_Presensi_Tahsin_${exportData.start_date}_${exportData.end_date}.png`;
          link.click();
          window.setTimeout(() => URL.revokeObjectURL(link.href), 1000);
        }, 'image/png');
      } catch (_) {
        window.alert('PNG tidak berhasil dibuat oleh browser ini. Gunakan Excel atau Cetak / Simpan PDF.');
      }
    });
    document.querySelectorAll('[data-export-meeting-png]').forEach(attachExport);
  }
})();
