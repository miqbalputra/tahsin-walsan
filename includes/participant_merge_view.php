<?php
require_once __DIR__.'/security_helper.php';

function participantMergeValidateSnapshot(array $snapshot): void
{
    $parent=$snapshot['parent']??null;
    if (!is_array($parent) || !filter_var($parent['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) || !is_string($parent['nama_bapak']??null)) throw new RuntimeException('Identitas snapshot penggabungan tidak valid.');
    if (!is_array($snapshot['children']??null) || !is_array($snapshot['memberships']??null)) throw new RuntimeException('Rincian snapshot penggabungan tidak valid.');
    foreach ($snapshot['children'] as $row) if (!is_array($row) || !filter_var($row['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])) throw new RuntimeException('ID anak pada snapshot penggabungan tidak valid.');
    foreach ($snapshot['memberships'] as $row) {
        if (!is_array($row) || !filter_var($row['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) || !is_string($row['participant_group_name']??null) || !is_string($row['participant_program']??null) || !array_key_exists('archived_at',$row)) throw new RuntimeException('Keanggotaan snapshot penggabungan tidak valid.');
    }
}

function participantMergeFieldLabel(string $field): string
{
    return ['nama_bapak'=>'Nama peserta','no_hp'=>'Nomor HP','alamat'=>'Alamat','kategori'=>'Kategori',
        'tempat_tahsin'=>'Tempat tahsin luar','ustadz_luar'=>'Pengajar luar','status_aktif'=>'Status peserta','lanjut_tahsin'=>'Catatan lanjut tahsin'][$field]??$field;
}

function participantMergeFieldValue(string $field, $value): string
{
    if ($field==='status_aktif') return (int)$value===1?'Aktif':'Arsip';
    if ($field==='lanjut_tahsin') return (int)$value===1?'Ya':'Tidak';
    return $value===null || $value===''?'Tidak diisi':(string)$value;
}

function participantMergeRenderPerson(array $snapshot, string $title): void
{
    ?><section class="border border-slate-200 rounded-xl p-4 bg-white"><h2 class="font-bold text-lg mb-3"><?= appEscape($title) ?> · ID <?= (int)$snapshot['parent']['id'] ?></h2><dl class="space-y-2 text-sm"><?php foreach (PARTICIPANT_MERGE_FIELDS as $field): ?><div><dt class="font-bold"><?= appEscape(participantMergeFieldLabel($field)) ?></dt><dd class="whitespace-pre-line break-words"><?= appEscape(participantMergeFieldValue($field,$snapshot['parent'][$field]??null)) ?></dd></div><?php endforeach; ?></dl>
    <h3 class="font-bold mt-4 mb-2">Anak</h3><?php if (!$snapshot['children']): ?><p>Belum ada anak.</p><?php else: ?><ul class="list-disc pl-5 text-sm space-y-2"><?php foreach ($snapshot['children'] as $child): ?><li>ID <?= (int)$child['id'] ?>: <?= appEscape($child['nama_anak']) ?> — <?= appEscape(($child['kelas']??'')!==''?$child['kelas']:'kelas belum diisi') ?></li><?php endforeach; ?></ul><?php endif; ?>
    <h3 class="font-bold mt-4 mb-2">Keanggotaan</h3><?php if (!$snapshot['memberships']): ?><p>Belum ada keanggotaan.</p><?php else: ?><ul class="list-disc pl-5 text-sm space-y-2"><?php foreach ($snapshot['memberships'] as $row): ?><li>ID <?= (int)$row['id'] ?>: <?= appEscape($row['participant_group_name']) ?> (<?= appEscape($row['participant_program']) ?>), <?= $row['archived_at']===null?'aktif':'arsip' ?></li><?php endforeach; ?></ul><?php endif; ?></section><?php
}

function participantMergeRenderPayload(array $row, bool $draft=false): void
{
    $payload=$row;
    if ($draft) {
        try { $payload=json_decode($row['payload_json'],true,32,JSON_THROW_ON_ERROR); }
        catch (Throwable $error) { $payload=null; }
        if (!is_array($payload)) { ?><p class="text-sm">Draft format lama:</p><pre class="text-sm whitespace-pre-wrap break-words"><?= appEscape($row['payload_json']) ?></pre><?php return; }
    }
    ?><dl class="space-y-2 text-sm"><?php foreach (['status'=>'Kehadiran','alasan'=>'Alasan','jenis_materi'=>'Materi','jilid'=>'Jilid','nama_surat'=>'Surat','halaman'=>'Halaman / ayat','hasil_talaqqi'=>'Hasil'] as $field=>$label): if (!array_key_exists($field,$payload)) continue; $value=$payload[$field]; if ($field==='status' && is_string($value)) $value=['H'=>'Hadir','S'=>'Sakit','I'=>'Izin','A'=>'Alfa'][$value]??$value; ?><div><dt class="font-bold"><?= $label ?></dt><dd class="whitespace-pre-line break-words"><?= appEscape($value===null || $value===''?'—':(is_scalar($value)?$value:'Format lama; periksa salinan rincian')) ?></dd></div><?php endforeach; ?></dl><?php
}

function participantMergeRenderAlternatives(array $before): void
{
    ?><section class="mt-6"><h2 class="font-bold text-xl mb-4">Data asal yang disimpan</h2><?php
    participantMergeRenderPerson($before['merge_source'],'Peserta sumber sebelum digabung');
    participantMergeRenderRelated($before['merge_related'],'asal');
    ?></section><?php
}

function participantMergeRenderRelated(array $related, string $label): void
{
    foreach ($related['tahsin_capaian_awal']??[] as $row) {
        ?><details class="border border-slate-200 rounded-xl p-4 mt-3"><summary class="font-bold cursor-pointer py-2">Capaian awal <?= appEscape($label) ?> · ID peserta <?= (int)$row['wali_santri_id'] ?></summary><?php participantMergeRenderPayload($row); ?></details><?php
    }
    foreach ($related['tahsin_autosave_drafts']??[] as $row) {
        ?><details class="border border-slate-200 rounded-xl p-4 mt-3"><summary class="font-bold cursor-pointer py-2">Draft <?= appEscape($label) ?> · peserta <?= (int)$row['wali_id'] ?> · akun <?= (int)$row['user_id'] ?> · halaqoh <?= (int)$row['halaqoh_id'] ?> · <?= appEscape($row['session_date']) ?></summary><?php participantMergeRenderPayload($row,true); ?></details><?php
    }
}
