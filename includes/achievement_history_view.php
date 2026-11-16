<?php
require_once __DIR__ . '/attendance_history_helper.php';

/** Decode only the journal extension; never consult a participant's current values. */
function achievementHistorySnapshot(array $snapshot): array
{
    $pack = $snapshot['achievement'] ?? null;
    if (!is_array($pack) || !in_array($pack['mode'] ?? null, ['attendance', 'initial'], true)
        || !array_key_exists('record_id', $pack) || !array_key_exists('baseline', $pack)
        || !array_key_exists('verification', $pack) || !is_array($pack['records'] ?? null)
        || !array_is_list($pack['records'])) throw new RuntimeException('Rincian riwayat capaian tidak valid.');
    $recordId = $pack['record_id'];
    if (($pack['mode'] === 'initial' && $recordId !== null)
        || ($pack['mode'] === 'attendance' && (!is_int($recordId) || $recordId < 0))) throw new RuntimeException('Sumber riwayat capaian tidak valid.');
    foreach (['baseline', 'verification'] as $key) {
        if ($pack[$key] !== null && !is_string($pack[$key])) throw new RuntimeException('Versi riwayat capaian tidak valid.');
        $pack[$key] = attendanceHistoryDecode($pack[$key]);
    }
    foreach ($pack['records'] as &$row) {
        if (!is_string($row)) throw new RuntimeException('Catatan riwayat capaian tidak valid.');
        $row = attendanceHistoryDecode($row);
        foreach (['id', 'tanggal', 'status', 'jenis_materi', 'jilid', 'nama_surat', 'halaman', 'hasil_talaqqi'] as $field) {
            if (!array_key_exists($field, $row)) throw new RuntimeException('Isian riwayat capaian tidak lengkap.');
        }
    }
    unset($row);
    if ($pack['mode'] === 'attendance' && !in_array((string) $recordId, array_map(static fn($row) => (string) $row['id'], $pack['records']), true)) throw new RuntimeException('Sumber riwayat capaian tidak tersedia.');
    if ($pack['baseline'] !== null) foreach (['wali_santri_id', 'jenis_materi', 'jilid', 'nama_surat', 'halaman'] as $field) {
        if (!array_key_exists($field, $pack['baseline'])) throw new RuntimeException('Isian capaian awal tidak lengkap.');
    }
    return $pack;
}

function achievementHistoryRender(array $pack, string $label): void
{
    $rows = $pack['mode'] === 'attendance' ? $pack['records'] : ($pack['baseline'] === null ? [] : [$pack['baseline']]);
    $fields = $pack['mode'] === 'attendance'
        ? ['id' => 'ID catatan', 'tanggal' => 'Tanggal pelaksanaan', 'status' => 'Status'] : ['wali_santri_id' => 'ID peserta'];
    $fields += ['jenis_materi' => 'Materi', 'jilid' => 'Jilid', 'nama_surat' => 'Surat', 'halaman' => 'Halaman / ayat'];
    if ($pack['mode'] === 'attendance') $fields['hasil_talaqqi'] = 'Hasil talaqqi';
    echo '<section class="bg-white border border-slate-200 rounded-xl p-4"><h2 class="font-bold mb-3">' . appEscape($label) . '</h2>';
    if (!$rows) echo '<p>Belum ada capaian awal.</p>';
    foreach ($rows as $row) {
        echo '<dl class="mb-5 space-y-2">';
        foreach ($fields as $field => $title) {
            $value = $row[$field];
            $text = $value === null ? '(NULL)' : ($value === '' ? '(kosong)' : (string) $value);
            if (!preg_match('//u', $text)) $text = '0x' . bin2hex($text);
            echo '<div><dt class="text-xs text-slate-500">' . appEscape($title) . '</dt><dd class="whitespace-pre-wrap break-words">' . appEscape($text) . '</dd></div>';
        }
        echo '</dl>';
    }
    if ($pack['verification'] !== null) echo '<p class="text-sm text-slate-600">Keputusan verifikasi asal tetap tersimpan. Perubahan sumber perlu diperiksa kembali melalui Rekap Lengkap.</p>';
    echo '</section>';
}
