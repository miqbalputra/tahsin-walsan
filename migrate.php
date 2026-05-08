<?php
require_once 'config/database.php';
try {
    $pdo->exec("ALTER TABLE wali_santri ADD COLUMN access_code VARCHAR(10) AFTER no_hp");
    echo "Column added successfully.";
} catch (Exception $e) {
    echo "Error or already exists: " . $e->getMessage();
}
unlink(__FILE__);
