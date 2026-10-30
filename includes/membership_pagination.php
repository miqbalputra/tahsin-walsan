<nav aria-label="<?= $set['page_key']==='members_page'?'Halaman anggota':'Halaman calon anggota' ?>" class="flex flex-wrap gap-4 py-4 text-sm">
    <p>Halaman <?= $set['page'] ?> dari <?= $set['pages'] ?></p>
    <?php foreach ([-1=>'Sebelumnya',1=>'Berikutnya'] as $delta=>$label): $next=$set['page']+$delta; if ($next<1||$next>$set['pages']) continue; ?>
        <a class="underline text-blue-700" href="<?= appEscape(appUrl('halaqoh-members.php?'.http_build_query(['id'=>$groupId]+array_replace($filters,[$set['page_key']=>$next])))) ?>"><?= $label ?></a>
    <?php endforeach; ?>
</nav>
