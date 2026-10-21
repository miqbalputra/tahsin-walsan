<?php
/** Server-to-server staging writer bridge. Browser queues and operation bodies stay unchanged. */
require_once __DIR__.'/go_vue_helper.php';

function goWriteSignature(string $secret,string $query,int $teacher,int $stamp,int $generation,string $requestHash,string $body):string
{
    return hash_hmac('sha256',implode("\n",['v2','POST','/api/v1/autosave',$query,(string)$teacher,'ustadz',(string)$stamp,(string)$generation,$requestHash,hash('sha256',$body)]),$secret);
}
function goWriteAutosave(array $input,int $teacher,int $group,string $date,int $generation):array
{
    $ids=trim((string)getenv('TAHSIN_NEXT_WRITE_HALAQOH_IDS'));
    if(!preg_match('/^[1-9][0-9]*(?:\s*,\s*[1-9][0-9]*)*$/D',$ids)||!in_array($group,array_map('intval',explode(',',$ids)),true))throw new AutosaveException('Kelompok writer belum diaktifkan. Draft dipertahankan.',503);
    $secret=(string)getenv('TAHSIN_NEXT_WRITE_SHARED_SECRET');
    if(strlen($secret)<32||$secret===getenv('TAHSIN_NEXT_SHARED_SECRET'))throw new RuntimeException('Separate staging writer secret required.');
    $base=rtrim((string)getenv('TAHSIN_NEXT_API_URL'),'/');$url=parse_url($base);
    if(!$url||!in_array($url['scheme']??'',['http','https'],true)||empty($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||($url['path']??'')!=='')throw new RuntimeException('Writer URL invalid.');
    $canonical=$input;unset($canonical['csrf_token']);
    // PHP retains the legacy receipt hash, including key order and original scalar types.
    // Its signed hash is bound to the independently validated normalized wire payload.
    $requestHash=autosaveHash([$group,$date,$canonical]);
    $wire=$input;
    if(($input['action']??'save')==='save'){
        if(!isset($input['payload'])||!is_array($input['payload']))throw new AutosaveException('Data peserta tidak valid.');
        $wire['payload']=autosavePayload($input['payload']);
    }
    $body=json_encode($wire,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $query=goVueTeacherQuery(['halaqoh_id'=>$group,'tanggal'=>$date]);$stamp=time();
    $headers=['Content-Type: application/json','Accept: application/json','X-Tahsin-User: '.$teacher,'X-Tahsin-Role: ustadz','X-Tahsin-Time: '.$stamp,'X-Tahsin-Writer-Generation: '.$generation,'X-Tahsin-Request-Hash: '.$requestHash,'X-Tahsin-Write-Signature: '.goWriteSignature($secret,$query,$teacher,$stamp,$generation,$requestHash,$body)];
    $context=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$body,'timeout'=>6,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $handle=@fopen($base.'/api/v1/autosave?'.$query,'rb',false,$context);if(!$handle)throw new AutosaveException('Penyimpanan Go belum terhubung. Pengiriman akan dicoba lagi.',503);
    try{
        $metadata=stream_get_meta_data($handle);$status=0;foreach($metadata['wrapper_data']??[] as $h)if(preg_match('/^HTTP\/\S+\s+(\d{3})/',$h,$m))$status=(int)$m[1];
        $raw=stream_get_contents($handle,2*1024*1024+1);
        if($raw===false||strlen($raw)>2*1024*1024||!empty(stream_get_meta_data($handle)['timed_out']))throw new AutosaveException('Penyimpanan belum dikonfirmasi. ID operasi dipertahankan untuk dicoba lagi.',503);
        $result=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        if($status===200&&($result['status']??'')==='success'&&is_array($result['data']??null))return $result['data'];
        if(in_array($status,[403,409,413,422],true))throw new AutosaveException((string)($result['message']??'Perubahan perlu ditinjau.'),$status,is_array($result['details']??null)?$result['details']:[]);
        throw new AutosaveException('Penyimpanan Go sedang dijeda atau belum tersedia. Draft dipertahankan.',503);
    }finally{fclose($handle);}
}
