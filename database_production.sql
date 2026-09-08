-- Database Schema for Presensi Tahsin (Production Safe)
-- This file is additive: it never drops, truncates, or replaces existing data.
-- Existing schema differences must be handled by a reviewed migration.

-- 1. Users Table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    nama_lengkap VARCHAR(100) NOT NULL,
    role ENUM('admin', 'pj_tahfidz', 'kepsek', 'ustadz') NOT NULL,
    no_hp VARCHAR(20),
    data_induk_teacher_id VARCHAR(36) NULL UNIQUE,
    data_induk_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Wali Santri Table
CREATE TABLE IF NOT EXISTS wali_santri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_bapak VARCHAR(100) NOT NULL,
    no_hp VARCHAR(20),
    alamat TEXT,
    kategori ENUM('reguler', 'tahsin_luar', 'askar') DEFAULT 'reguler',
    tempat_tahsin VARCHAR(255),
    ustadz_luar VARCHAR(100),
    status_aktif TINYINT(1) DEFAULT 1,
    data_induk_guardian_id VARCHAR(36) NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3. Santri Detail (Children)
CREATE TABLE IF NOT EXISTS santri_detail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wali_santri_id INT NOT NULL,
    nama_anak VARCHAR(100) NOT NULL,
    kelas VARCHAR(20),
    data_induk_student_id VARCHAR(36) NULL UNIQUE,
    data_induk_enrollment_id VARCHAR(36) NULL,
    data_induk_class_id VARCHAR(36) NULL,
    status_roster ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    FOREIGN KEY (wali_santri_id) REFERENCES wali_santri(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. Halaqoh Table
CREATE TABLE IF NOT EXISTS halaqoh (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_halaqoh VARCHAR(100) NOT NULL,
    ustadz_id INT NOT NULL,
    FOREIGN KEY (ustadz_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 5. Halaqoh Members
CREATE TABLE IF NOT EXISTS halaqoh_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    halaqoh_id INT NOT NULL,
    wali_santri_id INT NOT NULL,
    archived_at DATETIME NULL,
    archive_reason VARCHAR(50) NULL,
    FOREIGN KEY (halaqoh_id) REFERENCES halaqoh(id) ON DELETE CASCADE,
    FOREIGN KEY (wali_santri_id) REFERENCES wali_santri(id) ON DELETE CASCADE,
    KEY idx_halaqoh_members_active (halaqoh_id, archived_at, wali_santri_id)
) ENGINE=InnoDB;

-- 6. Presensi Table
CREATE TABLE IF NOT EXISTS presensi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    halaqoh_id INT NOT NULL,
    wali_santri_id INT NOT NULL,
    tanggal DATE NOT NULL,
    status ENUM('H','S','I','A') NOT NULL COMMENT 'H=Hadir, S=Sakit, I=Izin, A=Alpha',
    alasan TEXT,
    jenis_materi ENUM('Iqro', 'Al Quran'),
    jilid INT,
    nama_surat VARCHAR(100),
    halaman VARCHAR(20),
    hasil_talaqqi ENUM('Lulus', 'Ulang'),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (halaqoh_id) REFERENCES halaqoh(id) ON DELETE CASCADE,
    FOREIGN KEY (wali_santri_id) REFERENCES wali_santri(id) ON DELETE CASCADE,
    UNIQUE KEY uq_presensi_halaqoh_wali_tanggal (halaqoh_id, wali_santri_id, tanggal)
) ENGINE=InnoDB;

-- 7. Activity Logs
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    username VARCHAR(50),
    action VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 8. Settings Table
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) NOT NULL UNIQUE,
    setting_value LONGTEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Snapshot identitas dari Data Induk; transaksi halaqoh dan presensi tetap lokal.
CREATE TABLE IF NOT EXISTS data_induk_references (
    master_id VARCHAR(36) PRIMARY KEY,
    reference_type VARCHAR(30) NOT NULL,
    reference_code VARCHAR(100),
    reference_name VARCHAR(255),
    payload_json LONGTEXT,
    archived_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_reference_type_name (reference_type, reference_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS data_induk_sync_state (
    id TINYINT PRIMARY KEY,
    change_cursor VARCHAR(64) NOT NULL DEFAULT '0',
    last_success_at DATETIME NULL,
    last_error_at DATETIME NULL,
    last_error TEXT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO data_induk_sync_state (id) VALUES (1);

CREATE TABLE IF NOT EXISTS data_induk_sync_runs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(30) NOT NULL,
    status VARCHAR(20) NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    stats_json LONGTEXT NULL,
    error_message TEXT NULL,
    KEY idx_sync_runs_status_started (status, started_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS data_induk_sync_conflicts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(30) NOT NULL,
    master_id VARCHAR(36) NOT NULL,
    local_id INT NULL,
    reason VARCHAR(255) NOT NULL,
    payload_json LONGTEXT NULL,
    status ENUM('pending', 'approved', 'ignored') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    resolved_by INT NULL,
    KEY idx_conflict_status_created (status, created_at),
    KEY idx_conflict_master (entity_type, master_id)
) ENGINE=InnoDB;

-- 9. Pengumuman
CREATE TABLE IF NOT EXISTS pengumuman (
    id INT AUTO_INCREMENT PRIMARY KEY,
    judul VARCHAR(200),
    isi TEXT NOT NULL,
    is_aktif TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Initial Seed Data
INSERT IGNORE INTO users (username, password, nama_lengkap, role)
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator Utama', 'admin');

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('pj_tahfidz_name', 'Admin Sekolah'),
('pj_tahfidz_title', 'PJ Tahfidz');
