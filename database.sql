-- Database Schema for Presensi Tahsin Wali Santri



-- 1. Users Table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    nama_lengkap VARCHAR(100) NOT NULL,
    role ENUM('admin', 'pj_tahfidz', 'kepsek', 'ustadz') NOT NULL,
    no_hp VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Wali Santri Table
CREATE TABLE wali_santri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_bapak VARCHAR(100) NOT NULL,
    no_hp VARCHAR(20),
    alamat TEXT,
    kategori ENUM('reguler', 'tahsin_luar', 'askar') DEFAULT 'reguler',
    status_aktif TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3. Santri Detail (Children of Wali Santri)
CREATE TABLE santri_detail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wali_santri_id INT NOT NULL,
    nama_anak VARCHAR(100) NOT NULL,
    kelas VARCHAR(20),
    FOREIGN KEY (wali_santri_id) REFERENCES wali_santri(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. Halaqoh Table
CREATE TABLE halaqoh (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_halaqoh VARCHAR(100) NOT NULL,
    ustadz_id INT NOT NULL,
    FOREIGN KEY (ustadz_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 5. Halaqoh Members (Mapping Wali Santri to Halaqoh)
CREATE TABLE halaqoh_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    halaqoh_id INT NOT NULL,
    wali_santri_id INT NOT NULL,
    FOREIGN KEY (halaqoh_id) REFERENCES halaqoh(id) ON DELETE CASCADE,
    FOREIGN KEY (wali_santri_id) REFERENCES wali_santri(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 6. Presensi Table
CREATE TABLE presensi (
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
    FOREIGN KEY (wali_santri_id) REFERENCES wali_santri(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed initial admin user (password: admin123)
-- Pastikan untuk mengganti password di produksi
INSERT INTO users (username, password, nama_lengkap, role) 
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator Utama', 'admin');
