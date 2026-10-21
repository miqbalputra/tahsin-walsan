package app

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

type AttendanceReportFilters struct {
	StartDate string
	EndDate   string
	GroupID   int64
	TeacherID int64
	WaliID    int64
	Class     string
	Search    string
	Page      int
	Limit     int
}

type AttendanceReportRow struct {
	ID        int64  `json:"id"`
	Date      string `json:"tanggal"`
	GroupID   int64  `json:"halaqoh_id"`
	WaliID    int64  `json:"wali_santri_id"`
	Status    string `json:"status"`
	Material  string `json:"jenis_materi"`
	Level     string `json:"jilid"`
	Surah     string `json:"nama_surat"`
	Page      string `json:"halaman"`
	Result    string `json:"hasil_talaqqi"`
	Reason    string `json:"alasan"`
	WaliName  string `json:"nama_bapak"`
	GroupName string `json:"nama_halaqoh"`
	Class     string `json:"kelas"`
}

func parseAttendanceReportFilters(values url.Values, now time.Time) (AttendanceReportFilters, error) {
	loc := mustLocation("Asia/Jakarta")
	today := now.In(loc).Format("2006-01-02")
	monthStart := now.In(loc).Format("2006-01") + "-01"
	f := AttendanceReportFilters{StartDate: monthStart, EndDate: today, Page: 1, Limit: 50}
	for key, target := range map[string]*string{"start_date": &f.StartDate, "end_date": &f.EndDate, "kelas": &f.Class, "search": &f.Search} {
		if len(values[key]) > 1 {
			return f, fmt.Errorf("parameter %s berulang", key)
		}
		if len(values[key]) == 1 {
			*target = strings.TrimSpace(values.Get(key))
		}
	}
	for _, dateValue := range []string{f.StartDate, f.EndDate} {
		parsed, err := time.Parse("2006-01-02", dateValue)
		if err != nil || parsed.Format("2006-01-02") != dateValue {
			return f, errors.New("tanggal laporan tidak valid")
		}
	}
	if f.StartDate > f.EndDate || f.EndDate > today {
		return f, errors.New("rentang tanggal laporan tidak valid")
	}
	start, _ := time.Parse("2006-01-02", f.StartDate)
	end, _ := time.Parse("2006-01-02", f.EndDate)
	if end.Sub(start) > 3660*24*time.Hour {
		return f, errors.New("periode laporan maksimal sepuluh tahun")
	}
	for key, target := range map[string]*int64{"halaqoh_id": &f.GroupID, "wali_santri_id": &f.WaliID} {
		if len(values[key]) > 1 {
			return f, fmt.Errorf("parameter %s berulang", key)
		}
		if raw := strings.TrimSpace(values.Get(key)); raw != "" {
			n, err := strconv.ParseInt(raw, 10, 64)
			if err != nil || n < 1 || strconv.FormatInt(n, 10) != raw {
				return f, fmt.Errorf("parameter %s tidak valid", key)
			}
			*target = n
		}
	}
	for key, target := range map[string]*int{"page": &f.Page, "limit": &f.Limit} {
		if len(values[key]) > 1 {
			return f, fmt.Errorf("parameter %s berulang", key)
		}
		if raw := strings.TrimSpace(values.Get(key)); raw != "" {
			n, err := strconv.Atoi(raw)
			if err != nil || n < 1 || n > 10000 {
				return f, fmt.Errorf("parameter %s tidak valid", key)
			}
			*target = n
		}
	}
	if f.Limit > 100 {
		f.Limit = 100
	}
	if len([]rune(f.Class)) > 50 || len([]rune(f.Search)) > 100 {
		return f, errors.New("filter pencarian terlalu panjang")
	}
	return f, nil
}

func attendanceReportWhere(f AttendanceReportFilters) (string, []any) {
	where := []string{"p.tanggal BETWEEN ? AND ?"}
	args := []any{f.StartDate, f.EndDate}
	if f.GroupID > 0 {
		where = append(where, "p.halaqoh_id=?")
		args = append(args, f.GroupID)
	}
	if f.TeacherID > 0 {
		where = append(where, "h.ustadz_id=?")
		args = append(args, f.TeacherID)
	}
	if f.WaliID > 0 {
		where = append(where, "p.wali_santri_id=?")
		args = append(args, f.WaliID)
	}
	if f.Class != "" {
		where = append(where, "EXISTS (SELECT 1 FROM %s child_class WHERE child_class.wali_santri_id=w.id AND child_class.kelas=?)")
		args = append(args, f.Class)
	}
	if f.Search != "" {
		where = append(where, "(w.nama_bapak LIKE ? OR EXISTS (SELECT 1 FROM %s child_search WHERE child_search.wali_santri_id=w.id AND child_search.nama_anak LIKE ?))")
		pattern := "%" + f.Search + "%"
		args = append(args, pattern, pattern)
	}
	return strings.Join(where, " AND "), args
}

func familyChildSource(ctx context.Context, db interface {
	QueryRowContext(context.Context, string, ...any) *sql.Row
}) (string, error) {
	var views int
	if err := db.QueryRowContext(ctx, "SELECT COUNT(*) FROM information_schema.views WHERE table_schema=DATABASE() AND table_name='family_children'").Scan(&views); err != nil {
		return "", err
	}
	if views == 1 {
		return "family_children", nil
	}
	return "santri_detail", nil
}

func fetchAttendanceReport(ctx context.Context, db *sql.DB, filters AttendanceReportFilters) ([]AttendanceReportRow, int64, error) {
	childTable, err := familyChildSource(ctx, db)
	if err != nil {
		return nil, 0, err
	}
	where, args := attendanceReportWhere(filters)
	where = fmt.Sprintf(where, childTable)
	base := " FROM presensi p JOIN wali_santri w ON w.id=p.wali_santri_id JOIN halaqoh h ON h.id=p.halaqoh_id WHERE " + where
	var total int64
	if err = db.QueryRowContext(ctx, "SELECT COUNT(*)"+base, args...).Scan(&total); err != nil {
		return nil, 0, err
	}
	pageOffset := (filters.Page - 1) * filters.Limit
	query := "SELECT p.id,DATE_FORMAT(p.tanggal,'%Y-%m-%d'),p.halaqoh_id,p.wali_santri_id,COALESCE(p.status,''),COALESCE(p.jenis_materi,''),COALESCE(p.jilid,''),COALESCE(p.nama_surat,''),COALESCE(p.halaman,''),COALESCE(p.hasil_talaqqi,''),COALESCE(p.alasan,''),w.nama_bapak,h.nama_halaqoh,(SELECT GROUP_CONCAT(DISTINCT c.kelas ORDER BY c.kelas SEPARATOR ', ') FROM " + childTable + " c WHERE c.wali_santri_id=w.id AND c.kelas IS NOT NULL AND c.kelas<>'') AS kelas" + base + " ORDER BY p.tanggal DESC,h.nama_halaqoh,w.nama_bapak,p.id DESC LIMIT ? OFFSET ?"
	pageArgs := append(append([]any{}, args...), filters.Limit, pageOffset)
	rows, err := db.QueryContext(ctx, query, pageArgs...)
	if err != nil {
		return nil, 0, err
	}
	defer rows.Close()
	result := make([]AttendanceReportRow, 0, filters.Limit)
	for rows.Next() {
		var item AttendanceReportRow
		if err := rows.Scan(&item.ID, &item.Date, &item.GroupID, &item.WaliID, &item.Status, &item.Material, &item.Level, &item.Surah, &item.Page, &item.Result, &item.Reason, &item.WaliName, &item.GroupName, &item.Class); err != nil {
			return nil, 0, err
		}
		result = append(result, item)
	}
	if err := rows.Err(); err != nil {
		return nil, 0, err
	}
	return result, total, nil
}

func (s *Server) handleAttendanceReport(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		methodNotAllowed(w, http.MethodGet)
		return
	}
	filters, err := parseAttendanceReportFilters(r.URL.Query(), time.Now().In(mustLocation(s.Config.Timezone)))
	if err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, err.Error())
		return
	}
	auth, _ := currentAuth(r)
	if auth.User.Role == "ustadz" {
		filters.TeacherID = auth.User.ID
	}
	rows, total, err := fetchAttendanceReport(r.Context(), s.DB, filters)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Laporan presensi belum dapat dibaca.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": rows, "meta": map[string]any{"page": filters.Page, "limit": filters.Limit, "total": total, "start_date": filters.StartDate, "end_date": filters.EndDate}})
}

func (s *Server) handleAttendanceReportOptions(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		methodNotAllowed(w, http.MethodGet)
		return
	}
	childTable, err := familyChildSource(r.Context(), s.DB)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Pilihan filter laporan belum dapat dibaca.")
		return
	}
	auth, _ := currentAuth(r)
	groups := []map[string]any{}
	groupQuery, groupArgs := "SELECT id,nama_halaqoh FROM halaqoh ORDER BY nama_halaqoh,id", []any{}
	if auth.User.Role == "ustadz" {
		groupQuery, groupArgs = "SELECT id,nama_halaqoh FROM halaqoh WHERE ustadz_id=? ORDER BY nama_halaqoh,id", []any{auth.User.ID}
	}
	rows, err := s.DB.QueryContext(r.Context(), groupQuery, groupArgs...)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Pilihan filter laporan belum dapat dibaca.")
		return
	}
	for rows.Next() {
		var id int64
		var name string
		if err = rows.Scan(&id, &name); err != nil {
			rows.Close()
			writeAPIError(w, http.StatusServiceUnavailable, "Pilihan filter laporan belum dapat dibaca.")
			return
		}
		groups = append(groups, map[string]any{"id": id, "name": name})
	}
	if err = rows.Err(); err != nil {
		rows.Close()
		writeAPIError(w, http.StatusServiceUnavailable, "Pilihan filter laporan belum dapat dibaca.")
		return
	}
	rows.Close()
	walis := []map[string]any{}
	waliQuery, waliArgs := "SELECT id,nama_bapak FROM wali_santri ORDER BY nama_bapak,id", []any{}
	if auth.User.Role == "ustadz" {
		waliQuery, waliArgs = "SELECT DISTINCT w.id,w.nama_bapak FROM wali_santri w JOIN presensi p ON p.wali_santri_id=w.id JOIN halaqoh h ON h.id=p.halaqoh_id WHERE h.ustadz_id=? ORDER BY w.nama_bapak,w.id", []any{auth.User.ID}
	}
	rows, err = s.DB.QueryContext(r.Context(), waliQuery, waliArgs...)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Pilihan filter laporan belum dapat dibaca.")
		return
	}
	for rows.Next() {
		var id int64
		var name string
		if err = rows.Scan(&id, &name); err != nil {
			rows.Close()
			writeAPIError(w, http.StatusServiceUnavailable, "Pilihan filter laporan belum dapat dibaca.")
			return
		}
		walis = append(walis, map[string]any{"id": id, "name": name})
	}
	if err = rows.Err(); err != nil {
		rows.Close()
		writeAPIError(w, http.StatusServiceUnavailable, "Pilihan filter laporan belum dapat dibaca.")
		return
	}
	rows.Close()
	classes := []string{}
	classQuery, classArgs := "SELECT DISTINCT kelas FROM "+childTable+" WHERE kelas IS NOT NULL AND kelas<>'' ORDER BY kelas", []any{}
	if auth.User.Role == "ustadz" {
		classQuery = "SELECT DISTINCT c.kelas FROM " + childTable + " c WHERE c.kelas IS NOT NULL AND c.kelas<>'' AND EXISTS(SELECT 1 FROM presensi p JOIN halaqoh h ON h.id=p.halaqoh_id WHERE h.ustadz_id=? AND p.wali_santri_id=c.wali_santri_id) ORDER BY c.kelas"
		classArgs = []any{auth.User.ID}
	}
	rows, err = s.DB.QueryContext(r.Context(), classQuery, classArgs...)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Pilihan filter laporan belum dapat dibaca.")
		return
	}
	for rows.Next() {
		var class string
		if err = rows.Scan(&class); err != nil {
			rows.Close()
			writeAPIError(w, http.StatusServiceUnavailable, "Pilihan filter laporan belum dapat dibaca.")
			return
		}
		classes = append(classes, class)
	}
	if err = rows.Err(); err != nil {
		rows.Close()
		writeAPIError(w, http.StatusServiceUnavailable, "Pilihan filter laporan belum dapat dibaca.")
		return
	}
	rows.Close()
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"groups": groups, "walis": walis, "classes": classes}})
}

func (s *Server) handleDeleteAttendance(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodDelete {
		methodNotAllowed(w, http.MethodDelete)
		return
	}
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil || id < 1 {
		writeAPIError(w, http.StatusUnprocessableEntity, "ID presensi tidak valid.")
		return
	}
	auth, _ := currentAuth(r)
	tx, err := s.DB.BeginTx(r.Context(), nil)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Data presensi belum dapat diubah.")
		return
	}
	defer tx.Rollback()
	var groupID, waliID int64
	var dateText string
	var teacherID int64
	err = tx.QueryRowContext(r.Context(), "SELECT p.halaqoh_id,p.wali_santri_id,DATE_FORMAT(p.tanggal,'%Y-%m-%d'),h.ustadz_id FROM presensi p JOIN halaqoh h ON h.id=p.halaqoh_id WHERE p.id=? FOR UPDATE", id).Scan(&groupID, &waliID, &dateText, &teacherID)
	if errors.Is(err, sql.ErrNoRows) {
		writeAPIError(w, http.StatusNotFound, "Catatan presensi tidak ditemukan.")
		return
	}
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Data presensi belum dapat diubah.")
		return
	}
	if auth.User.Role == "ustadz" && teacherID != auth.User.ID {
		writeAPIError(w, http.StatusForbidden, "Catatan ini bukan milik kelompok yang Anda ajar.")
		return
	}
	day, err := time.Parse("2006-01-02", dateText)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Tanggal presensi tidak valid.")
		return
	}
	sessionDate := day.AddDate(0, 0, -int(day.Weekday())).Format("2006-01-02")
	if _, err = tx.ExecContext(r.Context(), "DELETE FROM presensi WHERE id=?", id); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Data presensi belum dapat diubah.")
		return
	}
	if _, err = tx.ExecContext(r.Context(), "INSERT INTO tahsin_autosave_revisions(wali_id,session_date,revision) VALUES(?,?,1) ON DUPLICATE KEY UPDATE revision=revision+1", waliID, sessionDate); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Revisi autosave belum dapat diperbarui.")
		return
	}
	if _, err = tx.ExecContext(r.Context(), "DELETE FROM tahsin_autosave_sessions WHERE halaqoh_id=? AND session_date=?", groupID, sessionDate); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Status sesi belum dapat diperbarui.")
		return
	}
	if _, err = tx.ExecContext(r.Context(), "INSERT INTO activity_logs(user_id,username,action,description,ip_address) VALUES(?,?,?,?,?)", auth.User.ID, auth.User.Username, "DELETE_PRESENSI", "id="+strconv.FormatInt(id, 10), clientIP(r)); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Log perubahan belum dapat disimpan.")
		return
	}
	if err = tx.Commit(); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Perubahan presensi belum dapat diselesaikan.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "message": "Data presensi berhasil dihapus."})
}
