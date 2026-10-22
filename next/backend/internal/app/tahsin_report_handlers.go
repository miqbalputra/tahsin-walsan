package app

import (
	"database/sql"
	"errors"
	"net/http"
	"strconv"
	"strings"
	"time"
)

func (s *Server) handleTahsinReport(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		methodNotAllowed(w, http.MethodGet)
		return
	}
	now := time.Now().In(mustLocation(s.Config.Timezone))
	filters, err := parseTahsinReportFilters(r.URL.Query(), now)
	if err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, err.Error())
		return
	}
	auth, _ := currentAuth(r)
	if auth.User.Role == "ustadz" {
		filters.TeacherID = auth.User.ID
	}
	page, limit, err := parseTahsinPage(r)
	if err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, err.Error())
		return
	}
	data, err := loadTahsinReportDataset(r.Context(), s.DB, filters.EndDate, now)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Rekap Tahsin belum dapat dibaca.")
		return
	}
	report := assembleTahsinReport(data, filters, auth.User.Role, auth.User.ID, now)
	total := len(report.Rows)
	start := (page - 1) * limit
	if start > total {
		start = total
	}
	end := min(start+limit, total)
	report.Rows = report.Rows[start:end]
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": report, "meta": map[string]any{"page": page, "limit": limit, "total": total, "pages": max(1, (total+limit-1)/limit)}})
}

func parseTahsinPage(r *http.Request) (int, int, error) {
	page, limit := 1, 50
	for key, target := range map[string]*int{"page": &page, "limit": &limit} {
		values, ok := r.URL.Query()[key]
		if !ok {
			continue
		}
		if len(values) != 1 {
			return 0, 0, errors.New("parameter " + key + " berulang")
		}
		raw := strings.TrimSpace(values[0])
		if raw == "" {
			continue
		}
		value, err := strconv.Atoi(raw)
		if err != nil || value < 1 || value > 100000 {
			return 0, 0, errors.New("parameter " + key + " tidak valid")
		}
		*target = value
	}
	if limit > 100 {
		limit = 100
	}
	return page, limit, nil
}

type tahsinVerificationInput struct {
	WaliID      int64  `json:"wali_id"`
	Session     string `json:"session"`
	Fingerprint string `json:"fingerprint"`
	SelectedID  int64  `json:"selected_id"`
	Note        string `json:"note"`
}

func (s *Server) handleTahsinVerification(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		methodNotAllowed(w, http.MethodPost)
		return
	}
	var input tahsinVerificationInput
	if err := decodeSmallJSON(w, r, 16<<10, &input); err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, "Data verifikasi tidak valid.")
		return
	}
	input.Note = strings.TrimSpace(input.Note)
	session, err := tahsinSessionDate(input.Session)
	if err != nil || session != input.Session || input.WaliID < 1 || input.Fingerprint == "" || input.SelectedID < 0 || input.Note == "" || !validParticipantText(input.Note, 2000, true) {
		writeAPIError(w, http.StatusUnprocessableEntity, "Tanggal, wali, pilihan, fingerprint, dan alasan verifikasi wajib benar.")
		return
	}
	ready, _, err := tahsinReportSchemaReady(r.Context(), s.DB)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Status migrasi laporan belum dapat diperiksa.")
		return
	}
	if !ready {
		writeAPIError(w, http.StatusConflict, "Histori roster dan verifikasi Tahsin belum siap.")
		return
	}
	if _, err = time.Parse("2006-01-02", input.Session); err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, "Tanggal sesi tidak valid.")
		return
	}
	lastDate, _ := time.Parse("2006-01-02", input.Session)
	last := min(lastDate.AddDate(0, 0, 6).Format("2006-01-02"), time.Now().In(mustLocation(s.Config.Timezone)).Format("2006-01-02"))
	if last < input.Session {
		writeAPIError(w, http.StatusUnprocessableEntity, "Sesi belum dapat diverifikasi.")
		return
	}
	auth, _ := currentAuth(r)
	tx, err := s.DB.BeginTx(r.Context(), nil)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Verifikasi belum dapat dimulai.")
		return
	}
	defer tx.Rollback()
	var locked int64
	if err = tx.QueryRowContext(r.Context(), "SELECT id FROM wali_santri WHERE id=? FOR UPDATE", input.WaliID).Scan(&locked); errors.Is(err, sql.ErrNoRows) {
		writeAPIError(w, http.StatusNotFound, "Wali tidak ditemukan.")
		return
	} else if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Wali belum dapat diperiksa.")
		return
	}
	rows, err := tx.QueryContext(r.Context(), "SELECT id,wali_santri_id,COALESCE(halaqoh_id,0),DATE_FORMAT(tanggal,'%Y-%m-%d'),COALESCE(status,''),COALESCE(alasan,''),COALESCE(jenis_materi,''),CAST(COALESCE(jilid,'') AS CHAR),COALESCE(nama_surat,''),COALESCE(halaman,''),COALESCE(hasil_talaqqi,''),COALESCE(CAST(created_at AS CHAR),'') FROM presensi WHERE wali_santri_id=? AND tanggal BETWEEN ? AND ? ORDER BY id FOR UPDATE", input.WaliID, input.Session, last)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Catatan sumber belum dapat dibaca.")
		return
	}
	records := []tahsinRecord{}
	for rows.Next() {
		var item tahsinRecord
		if err = rows.Scan(&item.ID, &item.WaliID, &item.GroupID, &item.Date, &item.Status, &item.Reason, &item.Material, &item.Level, &item.Surah, &item.Page, &item.Result, &item.CreatedAt); err != nil {
			rows.Close()
			writeAPIError(w, http.StatusServiceUnavailable, "Catatan sumber belum dapat dibaca.")
			return
		}
		records = append(records, item)
	}
	if err = rows.Err(); err != nil {
		rows.Close()
		writeAPIError(w, http.StatusServiceUnavailable, "Catatan sumber belum dapat dibaca.")
		return
	}
	rows.Close()
	if len(records) == 0 || input.Fingerprint != tahsinFingerprint(records, false) && input.Fingerprint != tahsinFingerprint(records, true) {
		writeAPIError(w, http.StatusConflict, "Catatan sumber berubah. Muat ulang lalu tinjau kembali.")
		return
	}
	if input.SelectedID > 0 {
		found := false
		for _, record := range records {
			if record.ID == input.SelectedID {
				found = true
				break
			}
		}
		if !found {
			writeAPIError(w, http.StatusUnprocessableEntity, "Catatan terpilih bukan bagian dari sesi ini.")
			return
		}
	}
	result, err := tx.ExecContext(r.Context(), "INSERT INTO tahsin_verifications(wali_santri_id,session_date,fingerprint,selected_presensi_id,decided_by,note) VALUES(?,?,?,?,?,?)", input.WaliID, input.Session, input.Fingerprint, nullIfZero(input.SelectedID), auth.User.ID, input.Note)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Verifikasi belum dapat disimpan.")
		return
	}
	id, _ := result.LastInsertId()
	if _, err = tx.ExecContext(r.Context(), "INSERT INTO activity_logs(user_id,username,action,description,ip_address) VALUES(?,?,?,?,?)", auth.User.ID, auth.User.Username, "VERIFY_TAHSIN", "wali_id="+strconv.FormatInt(input.WaliID, 10)+"; session="+input.Session+"; selected_id="+strconv.FormatInt(input.SelectedID, 10), clientIP(r)); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Log verifikasi belum dapat disimpan.")
		return
	}
	if err = tx.Commit(); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Verifikasi belum dapat diselesaikan.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"id": id, "wali_id": input.WaliID, "session": input.Session}})
}

func nullIfZero(value int64) any {
	if value == 0 {
		return nil
	}
	return value
}
