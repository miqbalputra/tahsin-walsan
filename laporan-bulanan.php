<?php
$month = (string) ($_GET['month'] ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m'));
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) && (isset($_GET['month']) || (!isset($_GET['start_date']) && !isset($_GET['end_date'])))) {
    $_GET['start_date'] = $month . '-01';
    $_GET['end_date'] = (new DateTimeImmutable($month . '-01'))->format('Y-m-t');
}
$tahsinPageTitle = 'Laporan Bulanan';
require __DIR__ . '/rekap-lengkap.php';
