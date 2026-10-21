package app

import (
	"errors"
	"net/http"
	"strconv"
	"strings"
	"time"
)

type dashboardTrend struct {
	Date  string `json:"tanggal"`
	Hadir int64  `json:"hadir"`
	Sakit int64  `json:"sakit"`
	Izin  int64  `json:"izin"`
	Alfa  int64  `json:"alfa"`
}

type dashboardWarning struct {
	WaliID   int64  `json:"wali_santri_id"`
	WaliName string `json:"nama_bapak"`
	Group    string `json:"nama_halaqoh"`
	Alpha    int64  `json:"total_alpha"`
}

type dashboardActivity struct {
	Date     string `json:"tanggal"`
	WaliName string `json:"nama_bapak"`
	Group    string `json:"nama_halaqoh"`
	Status   string `json:"status"`
}

func (s *Server) handleDashboard(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		methodNotAllowed(w, http.MethodGet)
		return
	}
	ctx := r.Context()
	var users, guardians, activeGuardians, groups, children int64
	for _, item := range []struct {
		query  string
		target *int64
	}{
		{"SELECT COUNT(*) FROM users", &users},
		{"SELECT COUNT(*) FROM wali_santri", &guardians},
		{"SELECT COUNT(*) FROM wali_santri WHERE status_aktif=1", &activeGuardians},
		{"SELECT COUNT(*) FROM halaqoh", &groups},
	} {
		if err := s.DB.QueryRowContext(ctx, item.query).Scan(item.target); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Ringkasan dashboard belum dapat dibaca.")
			return
		}
	}
	childTable, err := familyChildSource(ctx, s.DB)
	if err != nil || s.DB.QueryRowContext(ctx, "SELECT COUNT(*) FROM "+childTable).Scan(&children) != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Jumlah anak belum dapat dibaca.")
		return
	}
	loc := mustLocation(s.Config.Timezone)
	now := time.Now().In(loc)
	startDate := now.AddDate(0, 0, -29).Format("2006-01-02")
	endDate := now.Format("2006-01-02")
	stats := map[string]int64{"H": 0, "S": 0, "I": 0, "A": 0}
	rows, err := s.DB.QueryContext(ctx, "SELECT status,COUNT(*) FROM presensi WHERE tanggal BETWEEN ? AND ? GROUP BY status", startDate, endDate)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Ringkasan presensi belum dapat dibaca.")
		return
	}
	for rows.Next() {
		var status string
		var count int64
		if err = rows.Scan(&status, &count); err != nil {
			rows.Close()
			writeAPIError(w, http.StatusServiceUnavailable, "Ringkasan presensi belum dapat dibaca.")
			return
		}
		if _, ok := stats[status]; ok {
			stats[status] = count
		}
	}
	if err = rows.Err(); err != nil {
		rows.Close()
		writeAPIError(w, http.StatusServiceUnavailable, "Ringkasan presensi belum dapat dibaca.")
		return
	}
	rows.Close()
	trend := make([]dashboardTrend, 0, 30)
	rows, err = s.DB.QueryContext(ctx, "SELECT DATE_FORMAT(tanggal,'%Y-%m-%d'),SUM(status='H'),SUM(status='S'),SUM(status='I'),SUM(status='A') FROM presensi WHERE tanggal BETWEEN ? AND ? GROUP BY tanggal ORDER BY tanggal", startDate, endDate)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Tren presensi belum dapat dibaca.")
		return
	}
	for rows.Next() {
		var item dashboardTrend
		if err = rows.Scan(&item.Date, &item.Hadir, &item.Sakit, &item.Izin, &item.Alfa); err != nil {
			rows.Close()
			writeAPIError(w, http.StatusServiceUnavailable, "Tren presensi belum dapat dibaca.")
			return
		}
		trend = append(trend, item)
	}
	if err = rows.Err(); err != nil {
		rows.Close()
		writeAPIError(w, http.StatusServiceUnavailable, "Tren presensi belum dapat dibaca.")
		return
	}
	rows.Close()
	warnings := make([]dashboardWarning, 0, 5)
	rows, err = s.DB.QueryContext(ctx, "SELECT w.id,w.nama_bapak,h.nama_halaqoh,COUNT(*) AS total_alpha FROM presensi p JOIN wali_santri w ON w.id=p.wali_santri_id JOIN halaqoh h ON h.id=p.halaqoh_id WHERE p.status='A' AND p.tanggal<=? GROUP BY w.id,w.nama_bapak,h.id,h.nama_halaqoh HAVING COUNT(*)>=3 ORDER BY total_alpha DESC,w.nama_bapak LIMIT 5", endDate)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Daftar Alfa tercatat belum dapat dibaca.")
		return
	}
	for rows.Next() {
		var item dashboardWarning
		if err = rows.Scan(&item.WaliID, &item.WaliName, &item.Group, &item.Alpha); err != nil {
			rows.Close()
			writeAPIError(w, http.StatusServiceUnavailable, "Daftar Alfa tercatat belum dapat dibaca.")
			return
		}
		warnings = append(warnings, item)
	}
	if err = rows.Err(); err != nil {
		rows.Close()
		writeAPIError(w, http.StatusServiceUnavailable, "Daftar Alfa tercatat belum dapat dibaca.")
		return
	}
	rows.Close()
	recent := make([]dashboardActivity, 0, 8)
	rows, err = s.DB.QueryContext(ctx, "SELECT DATE_FORMAT(p.tanggal,'%Y-%m-%d'),w.nama_bapak,h.nama_halaqoh,p.status FROM presensi p JOIN wali_santri w ON w.id=p.wali_santri_id JOIN halaqoh h ON h.id=p.halaqoh_id ORDER BY p.tanggal DESC,p.id DESC LIMIT 8")
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Aktivitas terbaru belum dapat dibaca.")
		return
	}
	for rows.Next() {
		var item dashboardActivity
		if err = rows.Scan(&item.Date, &item.WaliName, &item.Group, &item.Status); err != nil {
			rows.Close()
			writeAPIError(w, http.StatusServiceUnavailable, "Aktivitas terbaru belum dapat dibaca.")
			return
		}
		recent = append(recent, item)
	}
	if err = rows.Err(); err != nil {
		rows.Close()
		writeAPIError(w, http.StatusServiceUnavailable, "Aktivitas terbaru belum dapat dibaca.")
		return
	}
	rows.Close()
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{
		"as_of": endDate, "period_start": startDate,
		"counts":           map[string]int64{"users": users, "wali": guardians, "wali_aktif": activeGuardians, "anak": children, "halaqoh": groups},
		"presensi_30_hari": stats, "trend": trend, "peringatan_alpha_tercatat": warnings, "terbaru": recent,
	}})
}

type activityLog struct {
	ID          int64  `json:"id"`
	Date        string `json:"created_at"`
	UserID      int64  `json:"user_id"`
	Username    string `json:"username"`
	Action      string `json:"action"`
	Description string `json:"description"`
	IP          string `json:"ip_address"`
}

func (s *Server) handleActivityLogs(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		methodNotAllowed(w, http.MethodGet)
		return
	}
	page, limit := 1, 50
	var err error
	if raw := strings.TrimSpace(r.URL.Query().Get("page")); raw != "" {
		page, err = strconv.Atoi(raw)
		if err != nil || page < 1 || page > 100000 {
			writeAPIError(w, http.StatusUnprocessableEntity, "Halaman log tidak valid.")
			return
		}
	}
	if raw := strings.TrimSpace(r.URL.Query().Get("limit")); raw != "" {
		limit, err = strconv.Atoi(raw)
		if err != nil || limit < 1 || limit > 100 {
			writeAPIError(w, http.StatusUnprocessableEntity, "Batas log tidak valid.")
			return
		}
	}
	search := strings.TrimSpace(r.URL.Query().Get("search"))
	if len([]rune(search)) > 100 {
		writeAPIError(w, http.StatusUnprocessableEntity, "Pencarian log terlalu panjang.")
		return
	}
	pattern := "%" + search + "%"
	where := " WHERE (?='' OR username LIKE ? OR action LIKE ? OR description LIKE ?)"
	args := []any{search, pattern, pattern, pattern}
	var total int64
	if err = s.DB.QueryRowContext(r.Context(), "SELECT COUNT(*) FROM activity_logs"+where, args...).Scan(&total); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Log aktivitas belum dapat dibaca.")
		return
	}
	args = append(args, limit, (page-1)*limit)
	rows, err := s.DB.QueryContext(r.Context(), "SELECT id,DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s'),COALESCE(user_id,0),COALESCE(username,''),action,COALESCE(description,''),COALESCE(ip_address,'') FROM activity_logs"+where+" ORDER BY id DESC LIMIT ? OFFSET ?", args...)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Log aktivitas belum dapat dibaca.")
		return
	}
	defer rows.Close()
	items := make([]activityLog, 0, limit)
	for rows.Next() {
		var item activityLog
		if err = rows.Scan(&item.ID, &item.Date, &item.UserID, &item.Username, &item.Action, &item.Description, &item.IP); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Log aktivitas belum dapat dibaca.")
			return
		}
		items = append(items, item)
	}
	if err = rows.Err(); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Log aktivitas belum dapat dibaca.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": items, "meta": map[string]any{"page": page, "limit": limit, "total": total}})
}

type reportSettings struct {
	Template string `json:"rapor_template"`
	Name     string `json:"pj_tahfidz_name"`
	Title    string `json:"pj_tahfidz_title"`
}

func (s *Server) handleReportSettings(w http.ResponseWriter, r *http.Request) {
	switch r.Method {
	case http.MethodGet:
		values := map[string]string{}
		rows, err := s.DB.QueryContext(r.Context(), "SELECT setting_key,COALESCE(setting_value,'') FROM settings WHERE setting_key IN ('rapor_template','pj_tahfidz_name','pj_tahfidz_title')")
		if err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Pengaturan rapor belum dapat dibaca.")
			return
		}
		for rows.Next() {
			var key, value string
			if err = rows.Scan(&key, &value); err != nil {
				rows.Close()
				writeAPIError(w, http.StatusServiceUnavailable, "Pengaturan rapor belum dapat dibaca.")
				return
			}
			values[key] = value
		}
		if err = rows.Err(); err != nil {
			rows.Close()
			writeAPIError(w, http.StatusServiceUnavailable, "Pengaturan rapor belum dapat dibaca.")
			return
		}
		rows.Close()
		writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": reportSettings{Template: values["rapor_template"], Name: defaultString(values["pj_tahfidz_name"], "Admin Sekolah"), Title: defaultString(values["pj_tahfidz_title"], "PJ Tahfidz")}})
	case http.MethodPatch:
		var input reportSettings
		if err := decodeSmallJSON(w, r, 512<<10, &input); err != nil {
			writeAPIError(w, http.StatusUnprocessableEntity, "Pengaturan rapor tidak valid.")
			return
		}
		input, err := validateReportSettings(input)
		if err != nil {
			writeAPIError(w, http.StatusUnprocessableEntity, err.Error()+".")
			return
		}
		tx, err := s.DB.BeginTx(r.Context(), nil)
		if err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Pengaturan rapor belum dapat disimpan.")
			return
		}
		defer tx.Rollback()
		for _, item := range []struct{ key, value string }{{"rapor_template", input.Template}, {"pj_tahfidz_name", input.Name}, {"pj_tahfidz_title", input.Title}} {
			if _, err = tx.ExecContext(r.Context(), "INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", item.key, item.value); err != nil {
				writeAPIError(w, http.StatusServiceUnavailable, "Pengaturan rapor belum dapat disimpan.")
				return
			}
		}
		auth, _ := currentAuth(r)
		if _, err = tx.ExecContext(r.Context(), "INSERT INTO activity_logs(user_id,username,action,description,ip_address) VALUES(?,?,?,?,?)", auth.User.ID, auth.User.Username, "UPDATE_TEMPLATE", "Memperbarui template rapor dan identitas pengesah.", clientIP(r)); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Log perubahan rapor belum dapat disimpan.")
			return
		}
		if err = tx.Commit(); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Pengaturan rapor belum dapat diselesaikan.")
			return
		}
		writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": input})
	default:
		methodNotAllowed(w, "GET, PATCH")
	}
}

func validateReportSettings(input reportSettings) (reportSettings, error) {
	input.Name = strings.TrimSpace(input.Name)
	input.Title = strings.TrimSpace(input.Title)
	input.Template = strings.ReplaceAll(input.Template, "\r\n", "\n")
	if input.Name == "" || !validParticipantText(input.Name, 100, false) {
		return input, errors.New("nama pengesah wajib diisi dan maksimal 100 karakter")
	}
	if input.Title == "" || !validParticipantText(input.Title, 100, false) {
		return input, errors.New("jabatan pengesah wajib diisi dan maksimal 100 karakter")
	}
	if len(input.Template) > 450<<10 || !validParticipantText(input.Template, 450<<10, true) {
		return input, errors.New("template melebihi batas atau mengandung karakter yang tidak didukung")
	}
	return input, nil
}

func defaultString(value, fallback string) string {
	if strings.TrimSpace(value) == "" {
		return fallback
	}
	return value
}
