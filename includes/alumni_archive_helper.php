<?php
/**
 * Aturan lifecycle wali alumni.
 *
 * Data lama tidak dihapus. Wali diarsipkan melalui status_aktif dan
 * membership halaqoh diberi tanda archived_at agar roster aktif tetap bersih
 * sekaligus relasi lama tetap tersedia untuk audit/laporan historis.
 */

/**
 * Kelas "Lulus" dibandingkan secara normalisasi agar spasi atau kapitalisasi
 * tidak membuat data alumni salah terbaca. Nilai kelas di database tidak pernah
 * diubah oleh helper ini.
 */
function isAlumniClass($kelas): bool
{
    return strtolower(trim((string) $kelas)) === 'lulus';
}

/**
 * Anak dianggap masih aktif/harus dipertahankan bila kelasnya kosong, tidak
 * dikenal, atau bukan Lulus. Ini sengaja konservatif untuk mencegah salah arsip.
 */
function waliHasActiveSchoolChild(PDO $pdo, int $waliId): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM santri_detail
        WHERE wali_santri_id = ?
          AND TRIM(COALESCE(kelas, '')) <> ''
          AND LOWER(TRIM(kelas)) <> 'lulus'
        LIMIT 1
    ");
    $stmt->execute([$waliId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Wali boleh berada di roster tahsin aktif hanya jika status aktif dan memiliki
 * sedikitnya satu anak dengan kelas sekolah yang valid dan belum Lulus.
 */
function waliCanJoinActiveHalaqoh(PDO $pdo, int $waliId): bool
{
    $stmt = $pdo->prepare("SELECT status_aktif FROM wali_santri WHERE id = ?");
    $stmt->execute([$waliId]);
    $statusAktif = $stmt->fetchColumn();

    return $statusAktif !== false
        && (int) $statusAktif === 1
        && waliHasActiveSchoolChild($pdo, $waliId);
}

/**
 * Ambil wali aktif yang seluruh anaknya sudah Lulus.
 *
 * $waliIds dipakai untuk validasi ulang input dari browser. Eligibility selalu
 * dihitung ulang dari database dan tidak pernah mempercayai ID client.
 */
function findEligibleAlumniWaliIds(PDO $pdo, ?array $waliIds = null, bool $forUpdate = false): array
{
    $conditions = [
        'w.status_aktif = 1',
        "EXISTS (
            SELECT 1 FROM santri_detail sd_exists
            WHERE sd_exists.wali_santri_id = w.id
        )",
        "NOT EXISTS (
            SELECT 1
            FROM santri_detail sd_active
            WHERE sd_active.wali_santri_id = w.id
              AND LOWER(TRIM(COALESCE(sd_active.kelas, ''))) <> 'lulus'
        )",
    ];
    $params = [];

    if ($waliIds !== null) {
        $waliIds = array_values(array_unique(array_filter(
            array_map('intval', $waliIds),
            static fn(int $id): bool => $id > 0
        )));
        if (!$waliIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($waliIds), '?'));
        $conditions[] = "w.id IN ($placeholders)";
        $params = $waliIds;
    }

    $sql = 'SELECT w.id FROM wali_santri w WHERE ' . implode(' AND ', $conditions) . ' ORDER BY w.id';
    if ($forUpdate && $pdo->inTransaction() && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Arsipkan wali alumni dan soft-archive seluruh membership halaqohnya.
 * Caller boleh sudah berada dalam transaksi; helper tidak akan commit transaksi
 * milik caller. Bila dipanggil di luar transaksi, helper membuat transaksi sendiri.
 */
function archiveEligibleAlumni(PDO $pdo, ?array $waliIds = null, string $reason = 'AUTO_LULUS'): array
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $eligibleIds = findEligibleAlumniWaliIds($pdo, $waliIds, true);
        if (!$eligibleIds) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return [
                'wali_ids' => [],
                'membership_ids' => [],
                'archived_wali' => 0,
                'archived_memberships' => 0,
                'batch_id' => null,
            ];
        }

        $placeholders = implode(',', array_fill(0, count($eligibleIds), '?'));
        $membershipLock = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') ? ' FOR UPDATE' : '';
        $membershipStmt = $pdo->prepare(
            "SELECT hm.id
             FROM halaqoh_members hm
             WHERE hm.wali_santri_id IN ($placeholders)
               AND hm.archived_at IS NULL{$membershipLock}"
        );
        $membershipStmt->execute($eligibleIds);
        $membershipIds = array_map('intval', $membershipStmt->fetchAll(PDO::FETCH_COLUMN));

        $updateWali = $pdo->prepare(
            "UPDATE wali_santri
             SET status_aktif = 0
             WHERE id IN ($placeholders) AND status_aktif = 1"
        );
        $updateWali->execute($eligibleIds);
        $archivedWali = $updateWali->rowCount();

        $archivedMemberships = 0;
        if ($membershipIds) {
            $updateMembership = $pdo->prepare(
                "UPDATE halaqoh_members
                 SET archived_at = CURRENT_TIMESTAMP, archive_reason = ?
                 WHERE id IN (" . implode(',', array_fill(0, count($membershipIds), '?')) . ")
                   AND archived_at IS NULL"
            );
            $updateMembership->execute(array_merge([$reason], $membershipIds));
            $archivedMemberships = $updateMembership->rowCount();
        }

        $batchId = bin2hex(random_bytes(8));
        if (function_exists('addLog')) {
            addLog($pdo, 'AUTO_ARCHIVE_ALUMNI', json_encode([
                'batch_id' => $batchId,
                'reason' => $reason,
                'wali_ids' => $eligibleIds,
                'membership_ids' => $membershipIds,
                'archived_wali' => $archivedWali,
                'archived_memberships' => $archivedMemberships,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'wali_ids' => $eligibleIds,
            'membership_ids' => $membershipIds,
            'archived_wali' => $archivedWali,
            'archived_memberships' => $archivedMemberships,
            'batch_id' => $batchId,
        ];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Aktifkan kembali wali hanya jika setelah import ia memiliki anak aktif.
 * Membership halaqoh lama tidak diaktifkan otomatis.
 */
function reactivateWaliIfHasActiveChild(PDO $pdo, int $waliId): bool
{
    if (!waliHasActiveSchoolChild($pdo, $waliId)) {
        return false;
    }

    $stmt = $pdo->prepare(
        'UPDATE wali_santri SET status_aktif = 1 WHERE id = ? AND status_aktif = 0'
    );
    $stmt->execute([$waliId]);
    return $stmt->rowCount() > 0;
}
