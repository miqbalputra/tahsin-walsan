<?php
/** Read-only PHP session gateway during the first Go/Vue migration stage. */
function goVueEnabled(): bool { return getenv('TAHSIN_NEXT_ENABLED') === '1'; }

/** Independent frontend pilot: never enables Go writes or bypasses PHP authorization. */
function goVueTeacherEnabled(int $groupId): bool
{
    if (getenv('TAHSIN_VUE_TEACHER_ENABLED') !== '1' || $groupId < 1) return false;
    $ids = trim((string)getenv('TAHSIN_VUE_TEACHER_HALAQOH_IDS'));
    if (!preg_match('/^[1-9][0-9]*(?:\s*,\s*[1-9][0-9]*)*$/D', $ids)) return false;
    return in_array($groupId, array_map('intval', explode(',', $ids)), true);
}

function goVueQuery(array $input): string
{
    foreach ($input as $key => $value) if (!in_array($key, ['page','limit','search','scope'], true) || !is_scalar($value)) throw new InvalidArgumentException('Parameter tidak valid.');
    $page = filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>10000]]);
    $limit = filter_var($input['limit'] ?? 25, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>100]]);
    $search = trim((string) ($input['search'] ?? ''));
    $scope = (string) ($input['scope'] ?? 'active');
    if ($page === false || $limit === false || !in_array($scope,['active','all'],true) || !preg_match('//u',$search) || preg_match_all('/./us',$search)>100) throw new InvalidArgumentException('Parameter tidak valid.');
    return http_build_query(['page'=>$page,'limit'=>$limit,'search'=>$search,'scope'=>$scope], '', '&', PHP_QUERY_RFC3986);
}

function goVueSignature(string $secret, string $query, int $user, string $role, int $timestamp): string
{
    return hash_hmac('sha256', implode("\n", ['v1','GET','/api/v1/families',$query,(string)$user,$role,(string)$timestamp]), $secret);
}

function goVueReadFamilies(string $query, int $user, string $role): array
{
    $secret = (string) getenv('TAHSIN_NEXT_SHARED_SECRET');
    if (strlen($secret)<32) throw new RuntimeException('Pratinjau belum dikonfigurasi.');
    $base = rtrim(getenv('TAHSIN_NEXT_API_URL') ?: 'http://127.0.0.1:18090', '/');
    $url = parse_url($base);
    if (!$url || !in_array($url['scheme'] ?? '', ['http','https'], true) || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || ($url['path'] ?? '') !== '') throw new RuntimeException('Alamat layanan pratinjau tidak valid.');
    $timestamp = time();
    $headers = ['Accept: application/json', 'X-Tahsin-User: '.$user, 'X-Tahsin-Role: '.$role,
        'X-Tahsin-Time: '.$timestamp, 'X-Tahsin-Signature: '.goVueSignature($secret,$query,$user,$role,$timestamp)];
    // Do not forward PHP cookies or follow redirects to another service.
    $context = stream_context_create(['http'=>['method'=>'GET','header'=>implode("\r\n",$headers),'timeout'=>5,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0],
        'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $handle = @fopen($base.'/api/v1/families?'.$query,'rb',false,$context);
    if (!$handle) throw new RuntimeException('Layanan pratinjau belum dapat dihubungi.');
    try {
        $metadata = stream_get_meta_data($handle);
        $status = 0;
        foreach ($metadata['wrapper_data'] ?? [] as $header) if (preg_match('/^HTTP\/\S+\s+(\d{3})/',$header,$m)) $status=(int)$m[1];
        $body = stream_get_contents($handle, 2*1024*1024+1);
        if ($status!==200 || $body===false || strlen($body)>2*1024*1024 || !empty(stream_get_meta_data($handle)['timed_out'])) throw new RuntimeException('Data pratinjau belum dapat dibaca.');
        $data = json_decode($body,true,64,JSON_THROW_ON_ERROR);
        if (($data['status'] ?? '')!=='ok' || ($data['meta']['read_only'] ?? null)!==true || !is_array($data['data'] ?? null)) throw new RuntimeException('Respons pratinjau tidak valid.');
        return $data;
    } finally { fclose($handle); }
}

function goVueAssets(string $source = 'src/main.ts'): ?array
{
    $path = __DIR__.'/../assets/go-vue/.vite/manifest.json';
    if (!is_file($path)) return null;
    $manifest = json_decode(file_get_contents($path),true);
    $entry = $manifest[$source] ?? null;
    if (!is_array($entry) || !isset($entry['file'])) return null;
    $css = []; $seen = [];
    $visit = function (string $key) use (&$visit, &$css, &$seen, $manifest): bool {
        if (isset($seen[$key])) return true;
        $seen[$key] = true; $chunk = $manifest[$key] ?? null;
        if (!is_array($chunk) || !is_string($chunk['file'] ?? null)) return false;
        foreach (array_merge([$chunk['file']], $chunk['css'] ?? []) as $file) {
            if (!is_string($file) || !preg_match('~^assets/[a-zA-Z0-9_.-]+\.(js|css)$~D', $file) || !is_file(__DIR__.'/../assets/go-vue/'.$file)) return false;
        }
        foreach ($chunk['imports'] ?? [] as $import) if (!is_string($import) || !$visit($import)) return false;
        foreach ($chunk['css'] ?? [] as $file) $css[$file] = true;
        return true;
    };
    if (!$visit($source)) return null;
    $entry['css'] = array_keys($css);
    return $entry;
}
