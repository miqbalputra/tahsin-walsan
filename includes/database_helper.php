<?php
/** Atomic multi-table operations require transactional storage, including triggers. */
function appRequireTransactionalTables(PDO $pdo, ?array $tables = null): void
{
    $engines = $pdo->query("SELECT TABLE_NAME,ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($tables ?? array_keys($engines) as $table) {
        if (strtoupper($engines[$table] ?? '') !== 'INNODB') {
            throw new InvalidArgumentException('Tabel ' . $table . ' belum mendukung pemulihan atomik. Hubungi pengelola; data belum diproses.');
        }
    }
}

function appUseStrictSqlMode(PDO $pdo, bool $preserveExplicitZero = false): string
{
    $original = (string)$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    $required = $preserveExplicitZero ? ['STRICT_ALL_TABLES','NO_AUTO_VALUE_ON_ZERO'] : ['STRICT_ALL_TABLES'];
    $modes = array_unique(array_merge(array_filter(explode(',',$original)),$required));
    $pdo->prepare('SET SESSION sql_mode=?')->execute([implode(',',$modes)]);
    return $original;
}
