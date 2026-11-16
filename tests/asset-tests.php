<?php
if (PHP_SAPI!=='cli') exit;
putenv('SESSION_SAVE_PATH='.__DIR__.'/../.sessions/audit-20261023/asset-unit-'.getmypid());
$_SERVER['SCRIPT_NAME']='/login.php';
require_once __DIR__.'/../includes/auth_helper.php';
$checks=0;
function assetCheck(bool $condition,string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
foreach (['assets/css/app.css','assets/js/tahsin-autosave.js','assets/js/print-report.js','manifest.json','icon-512.png'] as $path) {
    $expected='/'.$path.'?v='.substr(hash_file('sha256',__DIR__.'/../'.$path),0,16);
    assetCheck(appAssetUrl($path)===$expected,'Asset URL matches file contents: '.$path);
}
$_SERVER['SCRIPT_NAME']='/school/ustadz/form-presensi.php';
assetCheck(str_starts_with(appAssetUrl('assets/js/alpine.min.js'),'/school/assets/js/alpine.min.js?v='),'Teacher page uses its installation root');
$_SERVER['SCRIPT_NAME']='/school/peserta.php';
assetCheck(str_starts_with(appAssetUrl('assets/css/app.css'),'/school/assets/css/app.css?v='),'Admin page uses the same installation root');
foreach (['../config/database.php','assets/../config.js','assets/.secret.js','assets/js/a.js?v=1','https://example.test/x.js','/assets/js/a.js','assets/js/a.php','assets/js/a.js#x'] as $path) {
    try { appAssetUrl($path); throw new LogicException('Invalid path accepted: '.$path); }
    catch (InvalidArgumentException $error) { assetCheck(true,'Invalid asset path rejected'); }
}
try { appAssetUrl('assets/js/nonexistent-audit-asset.js'); throw new LogicException('Missing asset accepted'); }
catch (RuntimeException $error) { assetCheck(true,'Missing public asset fails clearly'); }
$relative='assets/js/audit-asset-'.getmypid().'-'.bin2hex(random_bytes(4)).'.js';
$absolute=__DIR__.'/../'.$relative; $handle=fopen($absolute,'x');
if ($handle===false) throw new RuntimeException('Exclusive asset fixture creation failed');
try {
    fwrite($handle,'/* first version */'); fclose($handle);
    $first=appAssetUrl($relative);
    file_put_contents($absolute,'/* changed contents */');
    $child=proc_open([PHP_BINARY,'-r','require '.var_export(realpath(__DIR__.'/../includes/asset_helper.php'),true).'; echo appAssetUrl('.var_export($relative,true).');'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__.'/..');
    if (!is_resource($child)) throw new RuntimeException('Asset request simulation failed');
    $second=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    assetCheck(proc_close($child)===0 && $errors==='', 'Independent asset request completes without warnings');
    assetCheck(substr($first,strpos($first,'?'))!==substr($second,strpos($second,'?')),'Changed contents get a new URL on the next request');
    assetCheck(str_ends_with($second,substr(hash_file('sha256',$absolute),0,16)),'Asset version changes even without running the build');
} finally { if (is_resource($handle)) fclose($handle); unlink($absolute); }
echo "PASS $checks asset URL assertions\n";
