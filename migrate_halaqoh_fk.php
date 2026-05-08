<?php
/**
 * Migration: Ubah FK presensi.halaqoh_id agar nullable dan ON DELETE SET NULL
 * Tujuan: Agar saat halaqoh dihapus, data capaian (presensi) tetap tersimpan
 */
require_once 'config/database.php';

try {
    $pdo->beginTransaction();

    // 1. Drop existing FK constraint on presensi.halaqoh_id
    // First, find the constraint name
    $stmt = $pdo->query("
        SELECT CONSTRAINT_NAME 
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'presensi' 
        AND COLUMN_NAME = 'halaqoh_id' 
        AND REFERENCED_TABLE_NAME = 'halaqoh'
    ");
    $fk = $stmt->fetch();

    if ($fk) {
        $constraintName = $fk['CONSTRAINT_NAME'];
        $pdo->exec("ALTER TABLE presensi DROP FOREIGN KEY `$constraintName`");
        echo "✅ Dropped FK constraint: $constraintName\n";
    }

    // 2. Modify halaqoh_id column to be nullable
    $pdo->exec("ALTER TABLE presensi MODIFY COLUMN halaqoh_id INT NULL");
    echo "✅ Modified presensi.halaqoh_id to be nullable\n";

    // 3. Re-add FK with ON DELETE SET NULL
    $pdo->exec("ALTER TABLE presensi ADD CONSTRAINT fk_presensi_halaqoh 
                FOREIGN KEY (halaqoh_id) REFERENCES halaqoh(id) ON DELETE SET NULL");
    echo "✅ Added new FK with ON DELETE SET NULL\n";

    // 4. Also fix halaqoh_members FK to remove CASCADE dependency
    // (we handle deletion manually in PHP code)
    $stmt2 = $pdo->query("
        SELECT CONSTRAINT_NAME 
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'halaqoh_members' 
        AND COLUMN_NAME = 'halaqoh_id' 
        AND REFERENCED_TABLE_NAME = 'halaqoh'
    ");
    $fk2 = $stmt2->fetch();

    if ($fk2) {
        $constraintName2 = $fk2['CONSTRAINT_NAME'];
        $pdo->exec("ALTER TABLE halaqoh_members DROP FOREIGN KEY `$constraintName2`");
        $pdo->exec("ALTER TABLE halaqoh_members ADD CONSTRAINT fk_members_halaqoh 
                    FOREIGN KEY (halaqoh_id) REFERENCES halaqoh(id) ON DELETE CASCADE");
        echo "✅ Re-added halaqoh_members FK with CASCADE (safe for members)\n";
    }

    $pdo->commit();
    echo "\n🎉 Migrasi selesai! Sekarang menghapus halaqoh tidak akan menghapus data presensi.\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
}
