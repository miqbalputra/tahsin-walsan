<?php
require_once __DIR__ . '/security_helper.php';
require_once __DIR__ . '/autosave_helper.php';

final class HalaqohManagementException extends RuntimeException {}

function halaqohRequestId(array $input, bool $required = false): ?int
{
    $raw = appRequestString($input, 'id');
    if ($raw === '' && !$required) return null;
    $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) throw new HalaqohManagementException('Halaqoh tidak ditemukan. Buka kembali daftar halaqoh.');
    return $id;
}

/** Includes optional reporting/autosave tables; never disable foreign keys. */
function halaqohHasReferences(PDO $pdo, int $id): bool
{
    $tables = $pdo->query("SELECT c.TABLE_NAME FROM information_schema.columns c
        JOIN information_schema.tables t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME
        WHERE c.TABLE_SCHEMA=DATABASE() AND c.COLUMN_NAME='halaqoh_id' AND t.TABLE_TYPE='BASE TABLE'
        ORDER BY c.TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $statement = $pdo->prepare("SELECT halaqoh_id FROM $quoted WHERE halaqoh_id=? LIMIT 1 FOR UPDATE");
        $statement->execute([$id]);
        if ($statement->fetchColumn() !== false) return true;
    }
    return false;
}

function saveManagedHalaqoh(PDO $pdo, array $input): int
{
    $id = halaqohRequestId($input);
    $name = appRequestString($input, 'nama_halaqoh');
    $teacher = filter_var(appRequestString($input, 'ustadz_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $program = appRequestString($input, 'program_code', 'bapak');
    $hasProgram = array_key_exists('program_code', $input);
    if ($name === '' || appTextLength($name) > 100) throw new HalaqohManagementException('Isi nama halaqoh maksimal 100 karakter.');
    if (!$teacher) throw new HalaqohManagementException('Pilih pengajar dari daftar yang tersedia.');
    if (!in_array($program, ['bapak', 'ibu'], true)) throw new HalaqohManagementException('Pilih program Bapak atau Ibu.');
    $programSchema = autosaveSchemaReady($pdo);
    if (!$programSchema && $program !== 'bapak') throw new HalaqohManagementException('Program Ibu belum tersedia. Hubungi pengelola untuk menyiapkan pembaruan aplikasi.');
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        if ($id !== null) {
            $statement = $pdo->prepare('SELECT id, ' . ($programSchema ? 'program_code' : "'bapak' AS program_code") . ' FROM halaqoh WHERE id=? FOR UPDATE');
            $statement->execute([$id]);
            $existing = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$existing) throw new HalaqohManagementException('Halaqoh tidak ditemukan. Buka kembali daftar halaqoh.');
            if (!$hasProgram) $program = $existing['program_code'];
            if ($programSchema && $existing['program_code'] !== $program && halaqohHasReferences($pdo, $id)) {
                throw new HalaqohManagementException('Program halaqoh yang sudah digunakan tidak dapat diganti. Buat halaqoh baru agar kelompok Bapak, Ibu, dan riwayatnya tetap benar.');
            }
        }
        $statement = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='ustadz' FOR UPDATE");
        $statement->execute([$teacher]);
        if (!$statement->fetchColumn()) throw new HalaqohManagementException('Pengajar tidak tersedia. Pilih akun Ustadz atau Ustadzah yang masih aktif.');
        $values = [$name, $teacher];
        if ($programSchema) $values[] = $program;
        if ($id !== null) {
            $sql = 'UPDATE halaqoh SET nama_halaqoh=?, ustadz_id=?' . ($programSchema ? ', program_code=?' : '') . ' WHERE id=?';
            $pdo->prepare($sql)->execute([...$values, $id]);
        } else {
            $columns = $programSchema ? 'nama_halaqoh, ustadz_id, program_code' : 'nama_halaqoh, ustadz_id';
            $pdo->prepare('INSERT INTO halaqoh (' . $columns . ') VALUES (' . implode(',', array_fill(0,count($values),'?')) . ')')->execute($values);
            $id = (int) $pdo->lastInsertId();
        }
        if ($ownsTransaction) $pdo->commit();
        return $id;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function deleteEmptyHalaqoh(PDO $pdo, int $id): void
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT id FROM halaqoh WHERE id=? FOR UPDATE');
        $statement->execute([$id]);
        if (!$statement->fetchColumn()) throw new HalaqohManagementException('Halaqoh tidak ditemukan. Buka kembali daftar halaqoh.');
        if (halaqohHasReferences($pdo, $id)) {
            throw new HalaqohManagementException('Halaqoh masih memiliki anggota, presensi, draft, atau riwayat. Halaqoh ini tidak dihapus agar data tetap terhubung.');
        }
        $pdo->prepare('DELETE FROM halaqoh WHERE id=?')->execute([$id]);
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
