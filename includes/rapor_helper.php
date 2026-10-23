<?php
require_once __DIR__ . '/security_helper.php';
require_once __DIR__ . '/tahsin_report_helper.php';

const RAPOR_TOKENS = ['nama_wali', 'nama_halaqoh', 'nama_anak_kelas', 'nama_ustadz', 'periode',
    'hadir', 'sakit', 'izin', 'alfa', 'capaian_terakhir', 'riwayat_tabel',
    'tanggal_cetak', 'nama_pengesah', 'jabatan_pengesah'];

function raporDefaultTemplate(): string
{
    $template = file_get_contents(__DIR__ . '/../templates/rapor_template.html');
    if ($template === false) throw new RuntimeException('Desain rapor belum tersedia.');
    return $template;
}

function raporCheckCss(string $css): void
{
    // Restrict CSS fetches, escapes, executable legacy properties and data.
    // CSP independently blocks active content.
    if (str_contains($css, '{{')) throw new InvalidArgumentException('Letakkan penanda data di dalam isi rapor, bukan CSS.');
    $plain = preg_replace('~/\*.*?\*/~s', '', $css);
    if (preg_match('~[\\\\\x00-\x08\x0b\x0c\x0e-\x1f]|url\s*\(|@import|expression\s*\(|behavior\s*:|-moz-binding|\{\{~i', $plain)) {
        throw new InvalidArgumentException('CSS rapor hanya boleh mengatur tampilan. Hapus URL, kode aktif, atau penanda data di dalam CSS.');
    }
}

function raporCheckUrl(string $url, bool $image): void
{
    if (preg_match('~[\x00-\x20\\\\]|\{\{|%7b~i', $url)) {
        throw new InvalidArgumentException('Gunakan alamat gambar atau tautan tetap, tanpa penanda data.');
    }
    if ($image && preg_match('~^data:image/(png|jpeg|gif|webp);base64,[a-z0-9+/=]+$~iD', $url)) return;
    if (preg_match('~^https://~i', $url)) {
        $parts = parse_url($url);
        if ($parts && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])) return;
    }
    if ($url !== '' && !str_starts_with($url, '//') && !str_contains($url, ':')
        && preg_match('~^[a-zA-Z0-9_./#?&=%+\~-]+$~D', $url)) return;
    throw new InvalidArgumentException('Gunakan alamat HTTPS, gambar PNG/JPEG/GIF/WebP, atau berkas di aplikasi.');
}

/** Validate inert HTML/CSS. Stored PHP is never evaluated. */
function raporTemplateDocument(string $source): DOMDocument
{
    if (trim($source) === '' || strlen($source) > 60000 || str_contains($source, "\0") || !preg_match('//u', $source)) {
        throw new InvalidArgumentException('Isi desain rapor dengan teks UTF-8, maksimal 60 KB.');
    }
    if (str_contains($source, '<?') || str_contains($source, '<%')
        || preg_match('~<!ENTITY|<!DOCTYPE[^>]*(?:SYSTEM|PUBLIC|\[)~i', $source)) {
        throw new InvalidArgumentException('Desain rapor memakai HTML, CSS, dan penanda {{nama_wali}}. Kode PHP tidak diperbolehkan.');
    }
    preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/', $source, $matches);
    foreach ($matches[1] as $token) {
        if (!in_array($token, RAPOR_TOKENS, true)) throw new InvalidArgumentException('Penanda {{' . $token . '}} tidak dikenal. Gunakan daftar penanda pada halaman pengaturan.');
    }
    if (!class_exists(DOMDocument::class)) throw new RuntimeException('Komponen pembaca desain rapor belum tersedia. Hubungi pengelola.');
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->resolveExternals = false;
    $dom->substituteEntities = false;
    $previous = libxml_use_internal_errors(true);
    try {
        // Do not enable NOENT, DTDLOAD or XInclude. Remove our encoding PI below.
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $source, LIBXML_NONET);
        if (!$loaded) throw new InvalidArgumentException('Desain rapor tidak dapat dibaca. Periksa struktur HTML.');
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    if (!$dom->documentElement) throw new InvalidArgumentException('Tambahkan isi HTML pada desain rapor.');
    foreach (iterator_to_array($dom->childNodes) as $child) {
        if (in_array($child->nodeType, [XML_PI_NODE, XML_DOCUMENT_TYPE_NODE], true)) $dom->removeChild($child);
    }
    // loadHTML may synthesize an HTML4 PUBLIC doctype for fragments. Normalize
    // it so validation of a serialized custom template stays idempotent.
    $dom->insertBefore($dom->implementation->createDocumentType('html'), $dom->documentElement);
    $allowed = explode(' ', 'html head body title meta style div span p h1 h2 h3 h4 h5 h6 header footer main section article aside strong b em i u s small sup sub br hr table thead tbody tfoot tr th td caption colgroup col ul ol li dl dt dd img a');
    $common = ['id', 'class', 'style', 'title', 'lang', 'dir'];
    $attributes = ['meta' => ['charset', 'name', 'content'], 'table' => ['border', 'cellpadding', 'cellspacing', 'width'],
        'td' => ['colspan', 'rowspan', 'width', 'height', 'align', 'valign'],
        'th' => ['colspan', 'rowspan', 'width', 'height', 'align', 'valign', 'scope'],
        'col' => ['span', 'width'], 'colgroup' => ['span', 'width'], 'img' => ['src', 'alt', 'width', 'height'],
        'a' => ['href', 'target', 'rel'], 'ol' => ['start', 'type'], 'li' => ['value']];
    $count = 0;
    foreach ($dom->getElementsByTagName('*') as $element) {
        if (++$count > 4000 || !in_array($element->tagName, $allowed, true)) throw new InvalidArgumentException('Desain rapor hanya mendukung HTML tampilan. Hapus script, formulir, iframe, atau elemen yang tidak didukung.');
        foreach ($element->attributes as $attribute) {
            if (!in_array($attribute->name, array_merge($common, $attributes[$element->tagName] ?? []), true)) throw new InvalidArgumentException('Atribut ' . $attribute->name . ' tidak didukung pada desain rapor.');
            if (str_contains($attribute->value, '{{')) throw new InvalidArgumentException('Letakkan penanda data di antara tag HTML, bukan pada atribut, alamat, atau CSS.');
            if ($attribute->name === 'style') raporCheckCss($attribute->value);
            if (in_array($attribute->name, ['src', 'href'], true)) raporCheckUrl($attribute->value, $element->tagName === 'img');
            if ($attribute->name === 'target' && $attribute->value !== '_blank') throw new InvalidArgumentException('Tautan rapor hanya boleh dibuka pada tab baru.');
        }
        if ($element->tagName === 'meta') {
            $charset = strtolower($element->getAttribute('charset'));
            $name = strtolower($element->getAttribute('name'));
            if (!(($charset === 'utf-8' && $name === '') || ($name === 'viewport' && $charset === ''))) throw new InvalidArgumentException('Gunakan meta charset UTF-8 atau viewport saja pada desain rapor.');
        }
        if ($element->tagName === 'style') raporCheckCss($element->textContent);
        if ($element->tagName === 'title' && preg_match('/\{\{\s*riwayat_tabel\s*\}\}/', $element->textContent)) throw new InvalidArgumentException('Letakkan tabel riwayat di dalam isi rapor.');
        if ($element->tagName === 'a') $element->setAttribute('rel', 'noopener noreferrer');
    }
    preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/', $dom->saveHTML(), $decodedTokens);
    foreach ($decodedTokens[1] as $token) {
        if (!in_array($token, RAPOR_TOKENS, true)) throw new InvalidArgumentException('Penanda data pada desain rapor tidak dikenal.');
    }
    if (!$dom->getElementsByTagName('body')->length) $dom->documentElement->appendChild($dom->createElement('body'));
    return $dom;
}

/** Legacy content stays in the database until an explicit save. */
function raporSelectTemplate(string $source): array
{
    if (trim($source) === '') return ['html' => raporDefaultTemplate(), 'notice' => '', 'mode' => 'default'];
    $legacy = file_get_contents(__DIR__ . '/../templates/rapor_template.php');
    $normalize = static fn(string $text): string => trim(str_replace("\r\n", "\n", $text));
    if ($legacy !== false && $normalize($source) === $normalize($legacy)) return ['html' => raporDefaultTemplate(), 'notice' => '', 'mode' => 'legacy_default'];
    try {
        $dom = raporTemplateDocument($source);
        return ['html' => $dom->saveHTML(), 'notice' => '', 'mode' => 'custom'];
    } catch (InvalidArgumentException $error) {
        return ['html' => raporDefaultTemplate(), 'mode' => 'legacy_review',
            'notice' => 'Desain lama masih tersimpan dan perlu disesuaikan oleh admin. Rapor ini memakai desain standar sementara.'];
    }
}

function raporSettings(PDO $pdo): array
{
    try {
        return $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('rapor_template','pj_tahfidz_name','pj_tahfidz_title')")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $error) {
        if (($error->errorInfo[0] ?? '') !== '42S02') throw $error;
        return [];
    }
}

/** Atomic save with a separate setting for every replaced template. */
function raporSaveSettings(PDO $pdo, array $input): void
{
    $source = appRequestString($input, 'template_content', '', false);
    raporTemplateDocument($source);
    $name = appRequestString($input, 'pj_tahfidz_name');
    $title = appRequestString($input, 'pj_tahfidz_title');
    if ($name === '' || $title === '' || appTextLength($name) > 100 || appTextLength($title) > 100) throw new InvalidArgumentException('Isi nama dan jabatan pengesah, masing-masing maksimal 100 karakter.');
    $pdo->beginTransaction();
    try {
        $old = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='rapor_template' FOR UPDATE")->fetchColumn();
        if ($old !== false && $old !== '' && $old !== $source) {
            $key = 'rapor_backup_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
            $pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?)')->execute([$key, $old]);
        }
        $save = $pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
        foreach (['rapor_template' => $source, 'pj_tahfidz_name' => $name, 'pj_tahfidz_title' => $title] as $key => $value) $save->execute([$key, $value]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function raporFilters(array $query, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? tahsinNow();
    $id = filter_var(appRequestString($query, 'wali_santri_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) throw new InvalidArgumentException('Pilih peserta dari halaman rekap untuk mencetak rapor.');
    $dates = ['start_date' => $now->format('Y-m-01'), 'end_date' => $now->format('Y-m-t')];
    foreach ($dates as $key => $default) {
        $dates[$key] = appRequestString($query, $key, $default);
        if (!tahsinDate($dates[$key])) throw new InvalidArgumentException('Gunakan tanggal yang valid dengan format tahun-bulan-hari.');
    }
    return tahsinFilters($dates + ['wali_santri_id' => $id], $now);
}

function raporParticipant(PDO $pdo, array $filters, string $role, int $userId): ?array
{
    $report = tahsinFetchReport($pdo, $filters, $role, $userId);
    return $report['rows'][0] ?? null;
}

function raporHistoryTable(array $participant): string
{
    $html = '<table><thead><tr><th scope="col">Tanggal sesi</th><th scope="col">Kegiatan / materi</th><th scope="col">Status</th><th scope="col">Hasil</th></tr></thead><tbody>';
    $status = ['H' => 'Hadir', 'S' => 'Sakit', 'I' => 'Izin', 'A' => 'Alfa'];
    foreach ($participant['details'] as $cell) {
        if (!$cell['in_schedule']) continue;
        $record = $cell['record'];
        if ($record) {
            $content = $record['status'] === 'H' ? tahsinMaterial($record) : ($record['alasan'] ?: '—');
            if (!$cell['complete']) $content .= ' — Belum lengkap';
            $label = $status[$record['status']] ?? 'Perlu verifikasi';
            $result = $record['status'] === 'H' ? ($record['hasil_talaqqi'] ?: '—') : '—';
        } else {
            $content = in_array($cell['state'], ['conflict', 'invalid_date'], true) ? 'Catatan perlu diverifikasi pada halaman rekap.' : 'Belum ada presensi terverifikasi.';
            $label = $result = '—';
        }
        $html .= '<tr><td>' . appEscape(date('d/m/Y', strtotime($cell['session_date']))) . '</td><td>' . appEscape($content)
            . '</td><td>' . appEscape($label) . '</td><td>' . appEscape($result) . '</td></tr>';
    }
    if (!str_contains($html, '<tr><td>')) $html .= '<tr><td colspan="4">Belum ada catatan pada periode ini.</td></tr>';
    return $html . '</tbody></table>';
}

function raporRender(string $template, array $participant, array $filters, array $settings, string $notice = ''): string
{
    $dom = raporTemplateDocument($template);
    $head = $dom->getElementsByTagName('head')->item(0);
    if (!$head) {
        $head = $dom->createElement('head');
        $dom->documentElement->insertBefore($head, $dom->documentElement->firstChild);
    }
    $referrer = $dom->createElement('meta');
    $referrer->setAttribute('name', 'referrer');
    $referrer->setAttribute('content', 'no-referrer');
    $head->insertBefore($referrer, $head->firstChild);
    $head->appendChild($dom->createElement('style', '.rapor-controls{font:16px Arial,sans-serif;padding:16px;background:#f1f5f9;color:#0f172a;text-align:center}.rapor-controls button,.rapor-controls a{display:inline-block;margin:4px;padding:12px 18px;font:inherit}.rapor-quality{padding:12px;border:1px solid #92400e;color:#78350f;background:#fffbeb;margin:12px 0}@media print{.rapor-controls{display:none!important}}'));
    $script = $dom->createElement('script', '');
    $script->setAttribute('src', function_exists('appUrl') ? appUrl('assets/js/print-report.js') : 'assets/js/print-report.js');
    $script->setAttribute('defer', 'defer');
    $head->appendChild($script);
    $body = $dom->getElementsByTagName('body')->item(0);
    $controls = $dom->createElement('div');
    $controls->setAttribute('class', 'rapor-controls');
    $button = $dom->createElement('button', 'Cetak / simpan PDF');
    $button->setAttribute('type', 'button');
    $button->setAttribute('id', 'rapor-print-button');
    $controls->appendChild($button);
    $link = $dom->createElement('a', 'Kembali ke rekap');
    $link->setAttribute('href', function_exists('appUrl') ? appUrl('rekap-lengkap.php') : 'rekap-lengkap.php');
    $controls->appendChild($link);
    $controls->appendChild($dom->createElement('p', 'Pilih kertas A4 pada pengaturan cetak. Anda juga dapat memakai menu Cetak pada browser.'));
    if ($notice !== '') $controls->appendChild($dom->createElement('p', $notice));
    $body->insertBefore($controls, $body->firstChild);
    if ($participant['provisional']) {
        $quality = $dom->createElement('p', 'Data belum final: ' . $participant['quality_label'] . '. Ringkasan menghitung presensi tercatat yang dapat ditentukan; catatan kosong atau konflik tidak dianggap Alfa.');
        $quality->setAttribute('class', 'rapor-quality');
        $body->insertBefore($quality, $controls->nextSibling);
    }
    $text = ['nama_wali' => $participant['nama_bapak'], 'nama_halaqoh' => $participant['nama_halaqoh'],
        'nama_anak_kelas' => $participant['anak'] ?: '—', 'nama_ustadz' => $participant['nama_ustadz'],
        'periode' => date('d/m/Y', strtotime($filters['start_date'])) . ' – ' . date('d/m/Y', strtotime($filters['end_date'])),
        'hadir' => $participant['hadir'], 'sakit' => $participant['sakit'], 'izin' => $participant['izin'], 'alfa' => $participant['alpha'],
        'capaian_terakhir' => $participant['materi_terakhir'], 'tanggal_cetak' => tahsinNow()->format('d/m/Y'),
        'nama_pengesah' => $settings['pj_tahfidz_name'] ?? 'Admin Sekolah', 'jabatan_pengesah' => $settings['pj_tahfidz_title'] ?? 'PJ Tahfidz'];
    // One pass: tokens occurring inside data cannot expand other data.
    return preg_replace_callback('/\{\{\s*([^{}]+?)\s*\}\}/', static function ($match) use ($text, $participant) {
        return $match[1] === 'riwayat_tabel' ? raporHistoryTable($participant) : appEscape($text[$match[1]] ?? '');
    }, $dom->saveHTML());
}
