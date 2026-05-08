-- Performance indexes for report, export, dashboard, and n8n reminder queries.
-- Run once on the production database after taking a backup.
--
-- MySQL 8 example:
-- mysql -h HOST -u USER -p DATABASE < migrations/20260509_add_performance_indexes.sql

CREATE INDEX idx_presensi_tanggal ON presensi (tanggal);
CREATE INDEX idx_presensi_halaqoh_tanggal ON presensi (halaqoh_id, tanggal);
CREATE INDEX idx_presensi_wali_tanggal ON presensi (wali_santri_id, tanggal);
CREATE INDEX idx_presensi_status_tanggal ON presensi (status, tanggal);

CREATE INDEX idx_halaqoh_ustadz ON halaqoh (ustadz_id);

CREATE INDEX idx_halaqoh_members_halaqoh ON halaqoh_members (halaqoh_id);
CREATE INDEX idx_halaqoh_members_wali ON halaqoh_members (wali_santri_id);

CREATE INDEX idx_santri_detail_wali ON santri_detail (wali_santri_id);
CREATE INDEX idx_santri_detail_kelas_wali ON santri_detail (kelas, wali_santri_id);
