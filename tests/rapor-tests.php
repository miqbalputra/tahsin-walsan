<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/rapor_helper.php';
$checks = 0;
function raporCheck(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function raporReject(string $source): void {
    try { raporTemplateDocument($source); }
    catch (InvalidArgumentException $error) { raporCheck(true, 'Rejected active or invalid template'); return; }
    throw new RuntimeException('Unsafe template accepted: ' . $source);
}
raporCheck(raporSelectTemplate('')['mode'] === 'default', 'Empty template uses the default');
$legacy = file_get_contents(__DIR__ . '/../templates/rapor_template.php');
raporCheck(raporSelectTemplate($legacy)['mode'] === 'legacy_default', 'Original seeded template is recognized without execution');
raporCheck(raporSelectTemplate("<?php echo 'UNSAFE_EXECUTION'; ?>")['mode'] === 'legacy_review', 'Custom PHP needs review and is never executed');
raporCheck(raporSelectTemplate('<p>{{nama_wali}}</p>')['mode'] === 'custom', 'Safe custom HTML is supported');
foreach ([
    '<?php echo 1;?>', '<% echo 1;%>', '<script>alert(1)</script>', '<img src="x" onerror="alert(1)">',
    '<iframe src="https://example.com"></iframe>', '<svg><script>alert(1)</script></svg>',
    '<form action="https://example.com"><input name="data"></form>', '<meta http-equiv="refresh" content="0;url=https://example.com">',
    '<a href="javascript:alert(1)">X</a>', '<a href="data:text/html,hello">X</a>',
    '<img src="https://example.com/{{nama_wali}}">', '<img src="https://example.com/&#123;&#123;nama_wali&#125;&#125;">',
    '<p title="{{nama_wali}}">X</p>', '<p style="content:attr(title);background:url(https://example.com)">X</p>',
    '<style>@im/**/port "https://example.com";</style>', '<style>p{background:u\\72l(https://example.com)}</style>',
    '<style>/* {{nama_wali}} */</style>', '<p>{{unknown}}</p>', '<p>&#123;&#123;unknown&#125;&#125;</p>',
    '<title>{{ riwayat_tabel }}</title>', '<!DOCTYPE html SYSTEM "file:///secret"><p>X</p>',
    str_repeat('x',60001), "invalid\xff", '<object data="https://example.com"></object>', '<!-- only a comment -->', "text\0invalid",
] as $source) raporReject($source);
foreach (['assets/logo.png', '/icon-512.png', 'https://example.com/logo.png', 'data:image/png;base64,aGVsbG8='] as $url) {
    raporTemplateDocument('<img alt="Logo" src="' . $url . '"><p>{{nama_wali}}</p>');
    raporCheck(true, 'Static image URLs remain supported');
}
$now = new DateTimeImmutable('2026-10-23 10:00:00', new DateTimeZone('Asia/Jakarta'));
$filters = raporFilters(['wali_santri_id'=>'1','start_date'=>'2026-10-01','end_date'=>'2026-10-31'], $now);
raporCheck($filters['end_date'] === '2026-10-23', 'Report never includes future records');
foreach ([['wali_santri_id'=>[]], ['wali_santri_id'=>'0'], ['wali_santri_id'=>'1','start_date'=>'2026-02-30'],
    ['wali_santri_id'=>'1','start_date'=>[]], ['wali_santri_id'=>'1','start_date'=>'2026-10-23','end_date'=>'2026-10-01']] as $query) {
    try { raporFilters($query, $now); throw new RuntimeException('Invalid query accepted'); }
    catch (InvalidArgumentException $error) { raporCheck(true, 'Invalid query rejected'); }
}
$record = ['status'=>'S','alasan'=>'<img src=x onerror=alert(1)> & "catatan"','hasil_talaqqi'=>null];
$participant = ['nama_bapak'=>'Wali <script>alert(1)</script>', 'nama_halaqoh'=>'Kelompok & A', 'nama_ustadz'=>'Guru "A"',
    'anak'=>'Anak <b>Uji</b>', 'hadir'=>0, 'sakit'=>1, 'izin'=>0, 'alpha'=>0, 'materi_terakhir'=>'{{nama_pengesah}}',
    'provisional'=>true, 'quality_label'=>'Belum Terverifikasi', 'details'=>[
        ['in_schedule'=>true,'record'=>$record,'complete'=>true,'session_date'=>'2026-10-18'],
        ['in_schedule'=>true,'record'=>null,'state'=>'conflict','session_date'=>'2026-10-11']]];
$rendered = raporRender(raporDefaultTemplate(),$participant,$filters,['pj_tahfidz_name'=>'Pengesah <b>Uji</b>']);
raporCheck(str_contains($rendered,'&lt;script&gt;') && !str_contains($rendered,'<script>alert(1)'), 'Participant text cannot become markup');
raporCheck(str_contains($rendered,'&lt;img src=x onerror=alert(1)&gt;'), 'Attendance reasons are escaped');
raporCheck(str_contains($rendered,'{{nama_pengesah}}'), 'A token inside student data does not expand recursively');
raporCheck(str_contains($rendered,'Pengesah &lt;b&gt;Uji&lt;/b&gt;'), 'Saved signer is displayed safely');
raporCheck(str_contains($rendered,'Data belum final') && str_contains($rendered,'Catatan perlu diverifikasi'), 'Quality warnings remain visible');
raporCheck(str_contains($rendered,'print-report.js') && !str_contains($rendered,'onclick='), 'Only trusted external print control is added');
raporCheck(str_contains(raporRender('<style>p{color:red}</style>', $participant,$filters,[]),'rapor-print-button'), 'Head-only custom document does not crash');
$fragment = raporSelectTemplate('<p>{{nama_wali}}</p>');
raporCheck(str_contains(raporRender($fragment['html'],$participant,$filters,[]),'Wali &lt;script&gt;'), 'Saved custom fragments can be validated and rendered repeatedly');
echo "PASS $checks rapor assertions\n";
