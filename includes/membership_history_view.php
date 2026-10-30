<?php
require_once __DIR__.'/attendance_history_helper.php';

/** Show the school source at the time of the action, never today's mutable view. */
function membershipHistorySchool(array $snapshot): array
{
    $school=$snapshot['membership_source']['school']??null;
    if (!is_array($school)) throw new RuntimeException('Sumber sekolah pada jurnal keanggotaan tidak lengkap.');
    $decoded=[];
    foreach (['guardians','relations','students','legacy'] as $key) {
        if (!is_array($school[$key]??null) || !array_is_list($school[$key])) throw new RuntimeException('Struktur sumber sekolah tidak valid.');
        $decoded[$key]=[];
        foreach ($school[$key] as $encoded) {
            if (!is_string($encoded)) throw new RuntimeException('Baris sumber sekolah tidak valid.');
            $row=attendanceHistoryDecode($encoded);
            $required=['guardians'=>['id'],'relations'=>['guardian_id','student_id','is_current'],'students'=>['id','nama_anak','kelas','school_status','source_namespace','source_student_id'],'legacy'=>['id','nama_anak','kelas']][$key];
            foreach ($required as $field) if (!array_key_exists($field,$row)) throw new RuntimeException('Isian sumber sekolah belum lengkap.');
            $decoded[$key][]=$row;
        }
    }
    $children=[];
    if ($decoded['guardians']) {
        $active=[]; $guardians=array_column($decoded['guardians'],null,'id');
        foreach ($decoded['relations'] as $relation) if (isset($guardians[$relation['guardian_id']]) && (string)$relation['is_current']==='1') $active[$relation['student_id']]=true;
        foreach ($decoded['students'] as $row) if (isset($active[$row['id']])) $children[]=['name'=>$row['nama_anak'],'class'=>$row['kelas'],'status'=>$row['school_status'],'source'=>$row['source_namespace'].' / '.$row['source_student_id']];
    } else foreach ($decoded['legacy'] as $row) $children[]=['name'=>$row['nama_anak'],'class'=>$row['kelas'],'status'=>strtolower(trim((string)$row['kelas']))==='lulus'?'graduated':'active','source'=>'Anak lokal ID '.$row['id']];
    return $children;
}

function membershipHistoryRenderSchool(array $children, string $title): void
{
    $text=static fn($value)=>$value===null?'kelas belum tersedia':(!preg_match('//u',(string)$value)?'0x'.bin2hex((string)$value):(string)$value);
    $labels=['active'=>'Aktif','graduated'=>'Lulus','withdrawn'=>'Keluar','transferred'=>'Pindah','archived'=>'Arsip'];
    echo '<section class="bg-white border border-slate-200 rounded-xl p-4"><h2 class="font-bold mb-3">'.appEscape($title).'</h2>';
    if (!$children) echo '<p>Belum ada anak yang terhubung pada sumber saat tindakan ini.</p>';
    else {
        echo '<ul class="space-y-3">';
        foreach ($children as $child) echo '<li class="break-words">'.appEscape($text($child['name'])).' ['.appEscape($text($child['class'])).'] · '.appEscape($labels[$child['status']]??$text($child['status'])).'<p class="text-xs text-slate-500">'.appEscape($text($child['source'])).'</p></li>';
        echo '</ul>';
    }
    echo '</section>';
}
