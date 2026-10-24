<?php
require_once __DIR__ . '/security_helper.php';
require_once __DIR__ . '/data_induk_helper.php';

final class UserManagementException extends RuntimeException {}

function userRoleLabel(string $role): string
{
    return ['admin' => 'Admin', 'pj_tahfidz' => 'PJ Tahfidz', 'kepsek' => 'Kepala Sekolah',
        'ustadz' => 'Ustadz / Ustadzah'][$role] ?? 'Peran belum dikenali';
}

/** Explicit projection: password hashes must never reach templates or JSON. */
function userPublicColumns(PDO $pdo): string
{
    return 'id, username, nama_lengkap, role, no_hp, '
        . (dataIndukIdentityMode($pdo) ? 'data_induk_teacher_id' : 'NULL AS data_induk_teacher_id');
}

function listManagedUsers(PDO $pdo): array
{
    return $pdo->query('SELECT ' . userPublicColumns($pdo) . ' FROM users ORDER BY role, nama_lengkap')->fetchAll(PDO::FETCH_ASSOC);
}

function userRequestId(array $input, bool $required = false): ?int
{
    $raw = appRequestString($input, 'id');
    if ($raw === '' && !$required) return null;
    $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) throw new UserManagementException('Pengguna tidak ditemukan. Buka kembali daftar pengguna.');
    return $id;
}

/** Returns the actor role so callers can enforce admin-only privileges. */
function userRequireManager(PDO $pdo, int $actorId): string
{
    $statement = $pdo->prepare('SELECT role FROM users WHERE id=?');
    $statement->execute([$actorId]);
    $role = $statement->fetchColumn();
    if (!in_array($role, ['admin', 'pj_tahfidz'], true)) {
        throw new UserManagementException('Akun Anda tidak memiliki izin mengelola pengguna.');
    }
    return $role;
}

/** Lock administrators first so concurrent requests cannot remove the last one. */
function userLockAdministrators(PDO $pdo): array
{
    return $pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
}

function saveManagedUser(PDO $pdo, int $actorId, array $input): int
{
    $id = userRequestId($input);
    $username = appRequestString($input, 'username');
    $name = appRequestString($input, 'nama_lengkap');
    $role = appRequestString($input, 'role');
    $phone = appRequestString($input, 'no_hp');
    $password = appRequestString($input, 'password', '', false);
    if ($username === '' || appTextLength($username) > 50 || preg_match('/[\x00-\x1f\x7f]/', $username)) {
        throw new UserManagementException('Nama pengguna wajib diisi, maksimal 50 karakter.');
    }
    if (!in_array($role, APP_USER_ROLES, true)) throw new UserManagementException('Pilih peran pengguna dari daftar yang tersedia.');
    if (($id === null || $password !== '') && (appTextLength($password) < 8 || strlen($password) > 72 || str_contains($password, "\0"))) {
        throw new UserManagementException('Kata sandi harus minimal 8 karakter. Jika terlalu panjang, gunakan lebih sedikit karakter dan hindari karakter kosong tersembunyi.');
    }
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $administrators = userLockAdministrators($pdo);
        $actorRole = userRequireManager($pdo, $actorId);
        $existing = null;
        if ($id !== null) {
            $statement = $pdo->prepare('SELECT ' . userPublicColumns($pdo) . ' FROM users WHERE id=? FOR UPDATE');
            $statement->execute([$id]);
            $existing = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$existing) throw new UserManagementException('Pengguna tidak ditemukan. Buka kembali daftar pengguna.');
            if ($existing['role'] === 'admin' && $role !== 'admin' && count($administrators) <= 1) {
                throw new UserManagementException('Admin terakhir harus tetap aktif. Tetapkan admin lain sebelum mengganti peran ini.');
            }
            if ($actorRole !== 'admin' && $existing['role'] === 'admin') {
                throw new UserManagementException('Akun admin hanya dapat diubah oleh admin.');
            }
        }
        if ($actorRole !== 'admin' && $role === 'admin') {
            throw new UserManagementException('Hanya admin yang dapat menetapkan peran admin.');
        }
        $identityLocked = !empty($existing['data_induk_teacher_id']);
        if (!$identityLocked && ($name === '' || appTextLength($name) > 100 || appTextLength($phone) > 20)) {
            throw new UserManagementException('Isi nama lengkap maksimal 100 karakter dan nomor HP maksimal 20 karakter.');
        }
        $duplicate = $pdo->prepare('SELECT id FROM users WHERE username=? AND id<>? LIMIT 1');
        $duplicate->execute([$username, $id ?? 0]);
        if ($duplicate->fetchColumn()) throw new UserManagementException('Nama pengguna sudah dipakai. Gunakan nama pengguna lain.');
        $values = ['username' => $username, 'role' => $role];
        if (!$identityLocked) $values += ['nama_lengkap' => $name, 'no_hp' => $phone];
        if ($password !== '') $values['password'] = password_hash($password, PASSWORD_DEFAULT);
        if ($id !== null) {
            $assignments = implode(', ', array_map(static fn($column) => "$column=?", array_keys($values)));
            $statement = $pdo->prepare("UPDATE users SET $assignments WHERE id=?");
            $statement->execute([...array_values($values), $id]);
        } else {
            $columns = implode(', ', array_keys($values));
            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            $statement = $pdo->prepare("INSERT INTO users ($columns) VALUES ($placeholders)");
            $statement->execute(array_values($values));
            $id = (int) $pdo->lastInsertId();
        }
        if ($ownsTransaction) $pdo->commit();
        return $id;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function deleteManagedUser(PDO $pdo, int $actorId, int $id): void
{
    if ($id === $actorId) throw new UserManagementException('Akun yang sedang Anda gunakan tidak dapat dihapus.');
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $administrators = userLockAdministrators($pdo);
        $actorRole = userRequireManager($pdo, $actorId);
        $statement = $pdo->prepare('SELECT role FROM users WHERE id=? FOR UPDATE');
        $statement->execute([$id]);
        $role = $statement->fetchColumn();
        if ($role === false) throw new UserManagementException('Pengguna tidak ditemukan. Buka kembali daftar pengguna.');
        if ($actorRole !== 'admin' && $role === 'admin') {
            throw new UserManagementException('Akun admin hanya dapat dihapus oleh admin.');
        }
        if ($role === 'admin' && count($administrators) <= 1) {
            throw new UserManagementException('Admin terakhir tidak dapat dihapus. Tetapkan admin lain terlebih dahulu.');
        }
        $groups = $pdo->prepare('SELECT COUNT(*) FROM halaqoh WHERE ustadz_id=?');
        $groups->execute([$id]);
        if ((int) $groups->fetchColumn() > 0) {
            throw new UserManagementException('Pengguna masih mengampu halaqoh. Alihkan pengampu halaqoh terlebih dahulu agar riwayat tetap terhubung.');
        }
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
