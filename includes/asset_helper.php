<?php
/** Content hashes keep each deployment distinct in browser and worker caches. */
function appAssetUrl(string $path): string
{
    if ((!preg_match('~^assets/[A-Za-z0-9_./-]+\.(?:css|js|png|svg|ico|webp|woff2?|ttf)$~D',$path)
        && !in_array($path,['manifest.json','icon-512.png'],true)) || preg_match('~(^|/)\.~',$path)) {
        throw new InvalidArgumentException('Jalur aset aplikasi tidak valid.');
    }
    static $versions=[];
    if (!isset($versions[$path])) {
        $root=realpath(__DIR__.'/..'); $file=realpath($root.'/'.$path);
        $assetRoot=str_starts_with($path,'assets/')?realpath($root.'/assets'):$root;
        $prefix=str_replace('\\','/',$assetRoot).'/';
        if ($file===false || !is_file($file) || is_link($root.'/'.$path)) throw new RuntimeException('Aset aplikasi tidak tersedia. Jalankan build sebelum rilis.');
        $normalized=str_replace('\\','/',$file);
        $within=DIRECTORY_SEPARATOR==='\\'?strncasecmp($normalized,$prefix,strlen($prefix))===0:strncmp($normalized,$prefix,strlen($prefix))===0;
        if (!$within) throw new RuntimeException('Aset berada di luar direktori publik aplikasi.');
        $hash=hash_file('sha256',$file);
        if ($hash===false) throw new RuntimeException('Versi aset belum dapat dibaca.');
        $versions[$path]=substr($hash,0,16);
    }
    $relative=$path.'?v='.$versions[$path];
    return function_exists('appUrl')?appUrl($relative):'/'.$relative;
}
