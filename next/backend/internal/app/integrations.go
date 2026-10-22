package app

import (
	"context"
	"crypto/subtle"
	"database/sql"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"
)

var safeHermesTable = regexp.MustCompile(`^[A-Za-z0-9_]+$`)

func integrationJSON(w http.ResponseWriter, status int, value any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store, no-cache, must-revalidate")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(value)
}

func integrationError(w http.ResponseWriter, status int, message string) {
	integrationJSON(w, status, map[string]any{"status": "error", "message": message})
}

func integrationKeyEqual(expected, provided string) bool {
	if expected == "" || provided == "" || len(expected) > 4096 || len(provided) > 4096 {
		return false
	}
	return subtle.ConstantTimeCompare([]byte(expected), []byte(provided)) == 1
}

func (s *Server) integrationKey() string {
	if s.Config.HermesAPIKey != "" {
		return s.Config.HermesAPIKey
	}
	return s.Config.N8NAPIKey
}

func (s *Server) handleHermes(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Access-Control-Allow-Origin", "*")
	w.Header().Set("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
	w.Header().Set("Access-Control-Allow-Headers", "Content-Type, X-API-Key, Authorization")
	w.Header().Set("Cache-Control", "no-store, no-cache, must-revalidate")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	if r.Method == http.MethodOptions {
		w.WriteHeader(http.StatusNoContent)
		return
	}
	if r.Method != http.MethodGet && r.Method != http.MethodPost {
		integrationError(w, http.StatusMethodNotAllowed, "Method not allowed. Use GET or POST.")
		return
	}
	if s.integrationKey() == "" {
		integrationError(w, http.StatusServiceUnavailable, "API belum dikonfigurasi. Set HERMES_API_KEY di environment.")
		return
	}
	provided := strings.TrimSpace(r.Header.Get("X-API-Key"))
	if provided == "" {
		provided = strings.TrimSpace(r.URL.Query().Get("api_key"))
	}
	if provided == "" && r.Method == http.MethodPost {
		r.Body = http.MaxBytesReader(w, r.Body, 1<<20)
		if err := r.ParseForm(); err != nil {
			integrationError(w, http.StatusBadRequest, "Data permintaan tidak valid.")
			return
		}
		provided = strings.TrimSpace(r.PostForm.Get("api_key"))
	}
	if !integrationKeyEqual(s.integrationKey(), provided) {
		integrationError(w, http.StatusUnauthorized, "Unauthorized. Provide valid API key via X-API-Key header or api_key parameter.")
		return
	}
	if s.DB == nil {
		integrationError(w, http.StatusServiceUnavailable, "Database belum siap.")
		return
	}
	query := r.URL.Query()
	values := query["action"]
	if len(values) == 0 || strings.TrimSpace(values[0]) == "" {
		integrationError(w, http.StatusBadRequest, "Action tidak dikenal. Gunakan: status, peserta, halaqoh, presensi, stats, progress, peringatan, ustadz, libur, pengumuman, capaian, search, schema, logs, users, keluarga")
		return
	}
	action := strings.TrimSpace(values[0])
	var err error
	switch action {
	case "status":
		err = s.hermesStatus(r.Context(), w)
	case "peserta":
		err = s.hermesParticipants(r.Context(), w, query)
	case "halaqoh":
		err = s.hermesGroups(r.Context(), w, query)
	case "presensi":
		err = s.hermesAttendance(r.Context(), w, query)
	case "stats", "progress", "peringatan", "capaian":
		err = s.hermesTahsinReport(r.Context(), w, query, action)
	case "ustadz":
		err = s.hermesTeachers(r.Context(), w, query)
	case "libur":
		err = s.hermesHolidays(r.Context(), w, query)
	case "pengumuman":
		err = s.hermesAnnouncements(r.Context(), w, query)
	case "search":
		err = s.hermesSearch(r.Context(), w, query)
	case "schema":
		err = s.hermesSchema(r.Context(), w)
	case "logs":
		err = s.hermesLogs(r.Context(), w, query)
	case "users":
		err = s.hermesUsers(r.Context(), w, query)
	case "keluarga":
		err = s.hermesFamilies(r.Context(), w, query)
	default:
		integrationError(w, http.StatusBadRequest, "Action tidak dikenal. Gunakan: status, peserta, halaqoh, presensi, stats, progress, peringatan, ustadz, libur, pengumuman, capaian, search, schema, logs, users, keluarga")
	}
	if err != nil {
		integrationError(w, http.StatusInternalServerError, "Database error. Silakan coba lagi.")
	}
}

func hermesMeta(page, limit int, total int64) map[string]any {
	return map[string]any{"page": page, "limit": limit, "total": total, "total_pages": (int(total) + limit - 1) / limit, "has_next": int64(page*limit) < total, "has_prev": page > 1}
}

func hermesPage(query url.Values) (int, int, int, error) {
	page, limit := 1, 25
	for key, target := range map[string]*int{"page": &page, "limit": &limit} {
		values, present := query[key]
		if !present {
			continue
		}
		if len(values) != 1 {
			return 0, 0, 0, fmt.Errorf("parameter %s berulang", key)
		}
		value := strings.TrimSpace(values[0])
		if value == "" {
			continue
		}
		parsed, err := strconv.Atoi(value)
		if err != nil || parsed < 1 || parsed > 1_000_000 || strconv.Itoa(parsed) != value {
			return 0, 0, 0, fmt.Errorf("parameter %s tidak valid", key)
		}
		*target = parsed
	}
	limit = min(max(limit, 1), 100)
	return page, limit, (page - 1) * limit, nil
}

func hermesDateRange(query url.Values, timezone string) (string, string, error) {
	loc := mustLocation(timezone)
	now := time.Now().In(loc)
	today := now.Format("2006-01-02")
	start, end := now.Format("2006-01")+"-01", today
	read := func(primary, alias string, target *string) error {
		for _, key := range []string{primary, alias} {
			values, ok := query[key]
			if !ok {
				continue
			}
			if len(values) != 1 {
				return fmt.Errorf("parameter %s berulang", key)
			}
			if strings.TrimSpace(values[0]) != "" {
				*target = strings.TrimSpace(values[0])
				return nil
			}
		}
		return nil
	}
	if err := read("start", "start_date", &start); err != nil {
		return "", "", err
	}
	if err := read("end", "end_date", &end); err != nil {
		return "", "", err
	}
	for _, date := range []string{start, end} {
		parsed, err := time.Parse("2006-01-02", date)
		if err != nil || parsed.Format("2006-01-02") != date {
			return "", "", fmt.Errorf("format tanggal tidak valid. Gunakan YYYY-MM-DD")
		}
	}
	if start > end {
		return "", "", fmt.Errorf("tanggal awal harus sebelum atau sama dengan tanggal akhir")
	}
	return start, end, nil
}

func hermesLike(value string) string {
	value = strings.ReplaceAll(value, "!", "!!")
	value = strings.ReplaceAll(value, "%", "!%")
	value = strings.ReplaceAll(value, "_", "!_")
	return "%" + value + "%"
}

func hermesRows(ctx context.Context, db *sql.DB, query string, args ...any) ([]map[string]any, error) {
	rows, err := db.QueryContext(ctx, query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	columns, err := rows.Columns()
	if err != nil {
		return nil, err
	}
	result := make([]map[string]any, 0)
	for rows.Next() {
		values := make([]any, len(columns))
		pointers := make([]any, len(columns))
		for i := range values {
			pointers[i] = &values[i]
		}
		if err := rows.Scan(pointers...); err != nil {
			return nil, err
		}
		item := make(map[string]any, len(columns))
		for i, column := range columns {
			switch value := values[i].(type) {
			case []byte:
				item[column] = string(value)
			case time.Time:
				item[column] = value.Format("2006-01-02 15:04:05")
			case int64:
				item[column] = strconv.FormatInt(value, 10)
			case int32:
				item[column] = strconv.FormatInt(int64(value), 10)
			case uint64:
				item[column] = strconv.FormatUint(value, 10)
			default:
				item[column] = value
			}
		}
		result = append(result, item)
	}
	return result, rows.Err()
}

func hermesOne(ctx context.Context, db *sql.DB, query string, args ...any) (map[string]any, error) {
	rows, err := hermesRows(ctx, db, query, args...)
	if err != nil || len(rows) == 0 {
		return nil, err
	}
	return rows[0], nil
}

func hermesCount(ctx context.Context, db *sql.DB, query string, args ...any) (int64, error) {
	var result int64
	if err := db.QueryRowContext(ctx, query, args...).Scan(&result); err != nil {
		return 0, err
	}
	return result, nil
}

func hermesSuccess(w http.ResponseWriter, data any, meta map[string]any) {
	response := map[string]any{"status": "ok", "data": data}
	if len(meta) > 0 {
		response["meta"] = meta
	}
	integrationJSON(w, http.StatusOK, response)
}

func hermesPagedQuery(ctx context.Context, db *sql.DB, selectSQL, countSQL string, args []any, page, limit, offset int) ([]map[string]any, int64, error) {
	total, err := hermesCount(ctx, db, countSQL, args...)
	if err != nil {
		return nil, 0, err
	}
	pageArgs := append(append([]any{}, args...), limit, offset)
	rows, err := hermesRows(ctx, db, selectSQL, pageArgs...)
	return rows, total, err
}

func (s *Server) hermesStatus(ctx context.Context, w http.ResponseWriter) error {
	count := func(table string, where string) (int64, error) {
		if !safeHermesTable.MatchString(table) {
			return 0, fmt.Errorf("invalid table")
		}
		return hermesCount(ctx, s.DB, "SELECT COUNT(*) FROM `"+table+"`"+where)
	}
	totalPeserta, err := count("wali_santri", "")
	if err != nil {
		return err
	}
	active, err := count("wali_santri", " WHERE status_aktif=1")
	if err != nil {
		return err
	}
	groups, err := count("halaqoh", "")
	if err != nil {
		return err
	}
	teachers, err := count("users", " WHERE role='ustadz'")
	if err != nil {
		return err
	}
	users, err := count("users", "")
	if err != nil {
		return err
	}
	children, err := count("santri_detail", "")
	if err != nil {
		return err
	}
	attendance, err := count("presensi", "")
	if err != nil {
		return err
	}
	holidays, err := count("holidays", "")
	if err != nil {
		return err
	}
	today := time.Now().In(mustLocation(s.Config.Timezone)).Format("2006-01-02")
	todayCount, err := hermesCount(ctx, s.DB, "SELECT COUNT(*) FROM presensi WHERE tanggal=?", today)
	if err != nil {
		return err
	}
	rangeData, err := hermesOne(ctx, s.DB, "SELECT DATE_FORMAT(MIN(tanggal),'%Y-%m-%d') AS pertama,DATE_FORMAT(MAX(tanggal),'%Y-%m-%d') AS terakhir FROM presensi")
	if err != nil {
		return err
	}
	distribution, err := hermesRows(ctx, s.DB, "SELECT status,COUNT(*) AS total FROM presensi GROUP BY status ORDER BY status")
	if err != nil {
		return err
	}
	if rangeData != nil && rangeData["pertama"] == nil {
		rangeData = nil
	}
	hermesSuccess(w, map[string]any{"api_version": "1.0.0", "api_name": "Hermes API — Presensi Tahsin", "server_time": time.Now().In(mustLocation(s.Config.Timezone)).Format("2006-01-02 15:04:05"), "timezone": s.Config.Timezone, "database": map[string]any{"total_peserta": totalPeserta, "peserta_aktif": active, "total_halaqoh": groups, "total_ustadz": teachers, "total_users": users, "total_santri_anak": children, "total_presensi": attendance, "presensi_hari_ini": todayCount, "total_hari_libur": holidays, "rentang_data": rangeData, "distribusi_status": distribution}, "endpoints": []string{"status", "peserta", "keluarga", "halaqoh", "presensi", "stats", "progress", "peringatan", "ustadz", "libur", "pengumuman", "capaian", "search", "schema", "logs", "users"}}, nil)
	return nil
}

func (s *Server) hermesParticipants(ctx context.Context, w http.ResponseWriter, query url.Values) error {
	childTable := "santri_detail"
	if id := strings.TrimSpace(query.Get("id")); id != "" {
		parsed, err := strconv.ParseInt(id, 10, 64)
		if err != nil || parsed < 1 {
			integrationError(w, http.StatusBadRequest, "ID peserta tidak valid.")
			return nil
		}
		item, err := hermesOne(ctx, s.DB, `SELECT w.*,h.nama_halaqoh,u.nama_lengkap AS nama_ustadz,
            (SELECT COUNT(*) FROM presensi p WHERE p.wali_santri_id=w.id) AS total_presensi,
            (SELECT COUNT(*) FROM presensi p WHERE p.wali_santri_id=w.id AND p.status='H') AS total_hadir,
            (SELECT COUNT(*) FROM presensi p WHERE p.wali_santri_id=w.id AND p.status='A') AS total_alpha
            FROM wali_santri w LEFT JOIN halaqoh_members hm ON hm.wali_santri_id=w.id AND hm.archived_at IS NULL
            LEFT JOIN halaqoh h ON h.id=hm.halaqoh_id LEFT JOIN users u ON u.id=h.ustadz_id WHERE w.id=? LIMIT 1`, parsed)
		if err != nil {
			return err
		}
		if item == nil {
			integrationError(w, http.StatusNotFound, "Peserta tidak ditemukan.")
			return nil
		}
		children, err := hermesRows(ctx, s.DB, "SELECT id,nama_anak,kelas FROM "+childTable+" WHERE wali_santri_id=? ORDER BY nama_anak", parsed)
		if err != nil {
			return err
		}
		item["anak"] = children
		if ready, readyErr := familySchemaInfo(ctx, s.DB); readyErr != nil {
			return readyErr
		} else if ready {
			shared, err := hermesRows(ctx, s.DB, "SELECT nama_anak,kelas,source_namespace,source_student_id FROM family_children WHERE wali_santri_id=? ORDER BY nama_anak", parsed)
			if err != nil {
				return err
			}
			item["anak_terpadu"] = shared
		}
		history, err := hermesRows(ctx, s.DB, `SELECT p.id,DATE_FORMAT(p.tanggal,'%Y-%m-%d') AS tanggal,p.status,p.jenis_materi,p.jilid,p.nama_surat,p.halaman,p.hasil_talaqqi,p.alasan,h.nama_halaqoh
            FROM presensi p JOIN halaqoh h ON h.id=p.halaqoh_id WHERE p.wali_santri_id=? ORDER BY p.tanggal DESC,p.id DESC LIMIT 5`, parsed)
		if err != nil {
			return err
		}
		item["riwayat_terbaru"] = history
		hermesSuccess(w, item, nil)
		return nil
	}
	page, limit, offset, err := hermesPage(query)
	if err != nil {
		integrationError(w, http.StatusBadRequest, err.Error())
		return nil
	}
	conditions, args := []string{}, []any{}
	if search := strings.TrimSpace(query.Get("search")); search != "" {
		if len([]rune(search)) > 100 {
			integrationError(w, http.StatusBadRequest, "Pencarian terlalu panjang.")
			return nil
		}
		conditions = append(conditions, "(w.nama_bapak LIKE ? ESCAPE '!' OR w.no_hp LIKE ? ESCAPE '!')")
		pattern := hermesLike(search)
		args = append(args, pattern, pattern)
	}
	if category := strings.TrimSpace(query.Get("kategori")); category != "" {
		conditions = append(conditions, "w.kategori=?")
		args = append(args, category)
	}
	if groupID := strings.TrimSpace(query.Get("halaqoh_id")); groupID != "" {
		id, err := strconv.ParseInt(groupID, 10, 64)
		if err != nil || id < 1 {
			integrationError(w, http.StatusBadRequest, "ID halaqoh tidak valid.")
			return nil
		}
		conditions = append(conditions, "EXISTS(SELECT 1 FROM halaqoh_members hm WHERE hm.wali_santri_id=w.id AND hm.halaqoh_id=? AND hm.archived_at IS NULL)")
		args = append(args, id)
	}
	if class := strings.TrimSpace(query.Get("kelas")); class != "" {
		conditions = append(conditions, "EXISTS(SELECT 1 FROM "+childTable+" cc WHERE cc.wali_santri_id=w.id AND cc.kelas=?)")
		args = append(args, class)
	}
	if active := strings.TrimSpace(query.Get("status_aktif")); active != "" {
		if active != "0" && active != "1" {
			integrationError(w, http.StatusBadRequest, "status_aktif hanya boleh 0 atau 1.")
			return nil
		}
		conditions = append(conditions, "w.status_aktif=?")
		args = append(args, active)
	}
	where := ""
	if len(conditions) > 0 {
		where = " WHERE " + strings.Join(conditions, " AND ")
	}
	base := ` FROM wali_santri w` + where
	countSQL := "SELECT COUNT(*)" + base
	selectSQL := `SELECT w.*,
        (SELECT GROUP_CONCAT(CONCAT(c.nama_anak,IF(c.kelas IS NULL OR c.kelas='','',CONCAT(' (',c.kelas,')'))) SEPARATOR ', ') FROM ` + childTable + ` c WHERE c.wali_santri_id=w.id) AS daftar_anak,
        (SELECT h.nama_halaqoh FROM halaqoh_members hm JOIN halaqoh h ON h.id=hm.halaqoh_id WHERE hm.wali_santri_id=w.id AND hm.archived_at IS NULL LIMIT 1) AS nama_halaqoh,
        (SELECT u.nama_lengkap FROM halaqoh_members hm JOIN halaqoh h ON h.id=hm.halaqoh_id JOIN users u ON u.id=h.ustadz_id WHERE hm.wali_santri_id=w.id AND hm.archived_at IS NULL LIMIT 1) AS nama_ustadz` + base + " ORDER BY w.nama_bapak LIMIT ? OFFSET ?"
	rows, total, err := hermesPagedQuery(ctx, s.DB, selectSQL, countSQL, args, page, limit, offset)
	if err != nil {
		return err
	}
	hermesSuccess(w, rows, hermesMeta(page, limit, total))
	return nil
}

func familySchemaInfo(ctx context.Context, db *sql.DB) (bool, error) {
	var count int
	err := db.QueryRowContext(ctx, "SELECT COUNT(*) FROM information_schema.views WHERE table_schema=DATABASE() AND table_name='family_children'").Scan(&count)
	return count == 1, err
}

func (s *Server) hermesGroups(ctx context.Context, w http.ResponseWriter, query url.Values) error {
	if raw := strings.TrimSpace(query.Get("id")); raw != "" {
		id, err := strconv.ParseInt(raw, 10, 64)
		if err != nil || id < 1 {
			integrationError(w, http.StatusBadRequest, "ID halaqoh tidak valid.")
			return nil
		}
		item, err := hermesOne(ctx, s.DB, `SELECT h.*,u.nama_lengkap AS nama_ustadz,u.no_hp AS no_hp_ustadz,
            (SELECT COUNT(*) FROM halaqoh_members hm JOIN wali_santri w ON w.id=hm.wali_santri_id WHERE hm.halaqoh_id=h.id AND hm.archived_at IS NULL AND w.status_aktif=1) AS total_member
            FROM halaqoh h JOIN users u ON u.id=h.ustadz_id WHERE h.id=?`, id)
		if err != nil {
			return err
		}
		if item == nil {
			integrationError(w, http.StatusNotFound, "Halaqoh tidak ditemukan.")
			return nil
		}
		members, err := hermesRows(ctx, s.DB, `SELECT w.id,w.nama_bapak,w.no_hp,w.kategori,w.status_aktif,
            (SELECT GROUP_CONCAT(CONCAT(sd.nama_anak,' (',sd.kelas,')') SEPARATOR ', ') FROM santri_detail sd WHERE sd.wali_santri_id=w.id) AS anak
            FROM halaqoh_members hm JOIN wali_santri w ON w.id=hm.wali_santri_id WHERE hm.halaqoh_id=? AND hm.archived_at IS NULL AND w.status_aktif=1 ORDER BY w.nama_bapak`, id)
		if err != nil {
			return err
		}
		item["anggota"] = members
		stats, err := hermesRows(ctx, s.DB, "SELECT status,COUNT(*) AS total FROM presensi WHERE halaqoh_id=? GROUP BY status", id)
		if err != nil {
			return err
		}
		item["statistik_presensi"] = stats
		hermesSuccess(w, item, nil)
		return nil
	}
	page, limit, offset, err := hermesPage(query)
	if err != nil {
		integrationError(w, http.StatusBadRequest, err.Error())
		return nil
	}
	where, args := "", []any{}
	if raw := strings.TrimSpace(query.Get("ustadz_id")); raw != "" {
		id, parseErr := strconv.ParseInt(raw, 10, 64)
		if parseErr != nil || id < 1 {
			integrationError(w, http.StatusBadRequest, "ID ustadz tidak valid.")
			return nil
		}
		where, args = " WHERE h.ustadz_id=?", []any{id}
	}
	base := " FROM halaqoh h JOIN users u ON h.ustadz_id=u.id" + where
	countSQL := "SELECT COUNT(*)" + base
	selectSQL := `SELECT h.*,u.nama_lengkap AS nama_ustadz,
        (SELECT COUNT(*) FROM halaqoh_members hm JOIN wali_santri w ON w.id=hm.wali_santri_id WHERE hm.halaqoh_id=h.id AND hm.archived_at IS NULL AND w.status_aktif=1) AS total_member` + base + " ORDER BY h.nama_halaqoh LIMIT ? OFFSET ?"
	rows, total, err := hermesPagedQuery(ctx, s.DB, selectSQL, countSQL, args, page, limit, offset)
	if err != nil {
		return err
	}
	hermesSuccess(w, rows, hermesMeta(page, limit, total))
	return nil
}

func (s *Server) hermesAttendance(ctx context.Context, w http.ResponseWriter, query url.Values) error {
	if raw := strings.TrimSpace(query.Get("id")); raw != "" {
		id, err := strconv.ParseInt(raw, 10, 64)
		if err != nil || id < 1 {
			integrationError(w, http.StatusBadRequest, "ID presensi tidak valid.")
			return nil
		}
		item, err := hermesOne(ctx, s.DB, `SELECT p.*,w.nama_bapak,w.no_hp,h.nama_halaqoh,u.nama_lengkap AS nama_ustadz
            FROM presensi p JOIN wali_santri w ON w.id=p.wali_santri_id JOIN halaqoh h ON h.id=p.halaqoh_id JOIN users u ON u.id=h.ustadz_id WHERE p.id=?`, id)
		if err != nil {
			return err
		}
		if item == nil {
			integrationError(w, http.StatusNotFound, "Data presensi tidak ditemukan.")
			return nil
		}
		hermesSuccess(w, item, nil)
		return nil
	}
	page, limit, offset, err := hermesPage(query)
	if err != nil {
		integrationError(w, http.StatusBadRequest, err.Error())
		return nil
	}
	start, end, err := hermesDateRange(query, s.Config.Timezone)
	if err != nil {
		integrationError(w, http.StatusBadRequest, err.Error())
		return nil
	}
	conditions, args := []string{"p.tanggal BETWEEN ? AND ?"}, []any{start, end}
	for _, item := range []struct{ key, column string }{{"wali_id", "p.wali_santri_id"}, {"wali_santri_id", "p.wali_santri_id"}, {"halaqoh_id", "p.halaqoh_id"}} {
		if raw := strings.TrimSpace(query.Get(item.key)); raw != "" {
			id, err := strconv.ParseInt(raw, 10, 64)
			if err != nil || id < 1 {
				integrationError(w, http.StatusBadRequest, "Filter ID tidak valid.")
				return nil
			}
			conditions, args = append(conditions, item.column+"=?"), append(args, id)
		}
	}
	if status := strings.TrimSpace(query.Get("status")); status != "" {
		if !oneOf(status, "H", "S", "I", "A") {
			integrationError(w, http.StatusBadRequest, "status presensi tidak valid.")
			return nil
		}
		conditions, args = append(conditions, "p.status=?"), append(args, status)
	}
	if search := strings.TrimSpace(query.Get("search")); search != "" {
		pattern := hermesLike(search)
		conditions = append(conditions, "(w.nama_bapak LIKE ? ESCAPE '!' OR h.nama_halaqoh LIKE ? ESCAPE '!')")
		args = append(args, pattern, pattern)
	}
	where := " WHERE " + strings.Join(conditions, " AND ")
	base := " FROM presensi p JOIN wali_santri w ON w.id=p.wali_santri_id JOIN halaqoh h ON h.id=p.halaqoh_id JOIN users u ON u.id=h.ustadz_id" + where
	countSQL := "SELECT COUNT(*)" + base
	selectSQL := `SELECT p.*,w.nama_bapak,w.no_hp,h.nama_halaqoh,u.nama_lengkap AS nama_ustadz` + base + " ORDER BY p.tanggal DESC,h.nama_halaqoh,w.nama_bapak LIMIT ? OFFSET ?"
	rows, total, err := hermesPagedQuery(ctx, s.DB, selectSQL, countSQL, args, page, limit, offset)
	if err != nil {
		return err
	}
	hermesSuccess(w, rows, hermesMeta(page, limit, total))
	return nil
}

func hermesQueryValues(query url.Values) map[string][]string {
	values := make(map[string][]string, len(query))
	for key, value := range query {
		values[key] = append([]string(nil), value...)
	}
	return values
}

func tahsinReportForHermes(ctx context.Context, db *sql.DB, query url.Values, role string, userID int64, now time.Time) (tahsinReportResult, error) {
	filters, err := parseTahsinReportFilters(hermesQueryValues(query), now)
	if err != nil {
		return tahsinReportResult{}, err
	}
	data, err := loadTahsinReportDataset(ctx, db, filters.EndDate, now)
	if err != nil {
		return tahsinReportResult{}, err
	}
	return assembleTahsinReport(data, filters, role, userID, now), nil
}

func structMap(value any) (map[string]any, error) {
	raw, err := json.Marshal(value)
	if err != nil {
		return nil, err
	}
	result := map[string]any{}
	err = json.Unmarshal(raw, &result)
	return result, err
}

func (s *Server) hermesTahsinReport(ctx context.Context, w http.ResponseWriter, query url.Values, action string) error {
	if len(query["action"]) > 1 && action == "logs" {
		query = cloneURLValues(query)
		query.Set("action_filter", query["action"][1])
	}
	start, end, err := hermesDateRange(query, s.Config.Timezone)
	if err != nil {
		integrationError(w, http.StatusBadRequest, err.Error())
		return nil
	}
	filters := hermesQueryValues(query)
	filters["start_date"] = []string{start}
	filters["end_date"] = []string{min(end, time.Now().In(mustLocation(s.Config.Timezone)).Format("2006-01-02"))}
	if action == "progress" || action == "capaian" {
		filters["aktif"] = []string{"1"}
	}
	parsed, err := parseTahsinReportFilters(filters, time.Now().In(mustLocation(s.Config.Timezone)))
	if err != nil {
		integrationError(w, http.StatusBadRequest, err.Error())
		return nil
	}
	now := time.Now().In(mustLocation(s.Config.Timezone))
	data, err := loadTahsinReportDataset(ctx, s.DB, parsed.EndDate, now)
	if err != nil {
		return err
	}
	report := assembleTahsinReport(data, parsed, "admin", 0, now)
	switch action {
	case "stats":
		summary, err := structMap(report.Summary)
		if err != nil {
			return err
		}
		summary["total"] = summary["total_presensi"]
		groups := make([]map[string]any, 0, len(report.Groups))
		for _, group := range report.Groups {
			item, err := structMap(group)
			if err != nil {
				return err
			}
			item["id"], item["total_input"] = group.ID, group.TotalPresensi
			groups = append(groups, item)
		}
		warnings := []map[string]any{}
		for _, row := range report.Rows {
			if row.Alpha < 3 {
				continue
			}
			item, err := hermesRowWithoutDetail(row)
			if err != nil {
				return err
			}
			item["total_alpha"] = row.Alpha
			warnings = append(warnings, item)
		}
		hermesSuccess(w, map[string]any{"ringkasan": summary, "per_halaqoh": groups, "tren_harian": report.Trend, "tren_pekanan": report.Trend, "peringatan_dini": warnings, "periode": map[string]string{"start": start, "end": end}, "basis_periode": "tanggal_sesi_ahad"}, nil)
	case "progress":
		page, limit, offset, err := hermesPage(query)
		if err != nil {
			integrationError(w, http.StatusBadRequest, err.Error())
			return nil
		}
		rows := make([]map[string]any, 0, len(report.Rows))
		for _, row := range report.Rows {
			item, err := hermesRowWithoutDetail(row)
			if err != nil {
				return err
			}
			rows = append(rows, item)
		}
		total := len(rows)
		if offset > total {
			offset = total
		}
		pageRows := rows[offset:min(offset+limit, total)]
		hermesSuccess(w, pageRows, hermesMeta(page, limit, int64(total)))
	case "peringatan":
		warnings := []map[string]any{}
		for _, row := range report.Rows {
			if row.Alpha < 3 {
				continue
			}
			item, err := hermesRowWithoutDetail(row)
			if err != nil {
				return err
			}
			item["total_alpha"] = row.Alpha
			warnings = append(warnings, item)
		}
		if len(warnings) > 50 {
			warnings = warnings[:50]
		}
		hermesSuccess(w, map[string]any{"total_peringatan": len(warnings), "data": warnings, "keterangan": "Alfa sah minimal 3 sesi; data kosong dan konflik tidak dianggap Alfa."}, nil)
	case "capaian":
		page, limit, offset, err := hermesPage(query)
		if err != nil {
			integrationError(w, http.StatusBadRequest, err.Error())
			return nil
		}
		rows := []map[string]any{}
		for _, row := range report.Rows {
			if row.CurrentGroupID == 0 {
				continue
			}
			childNames, classes := []string{}, []string{}
			seenClass := map[string]bool{}
			for _, child := range row.Children {
				childNames = append(childNames, child.Name)
				if child.Class != "" && !seenClass[child.Class] {
					seenClass[child.Class] = true
					classes = append(classes, child.Class)
				}
			}
			item := map[string]any{"wali_id": row.ID, "nama_bapak": row.Name, "no_hp": row.Phone, "halaqoh_id": row.GroupID, "nama_halaqoh": row.GroupName, "nama_ustadz": row.TeacherName,
				"children_names": strings.Join(childNames, ", "), "classes": strings.Join(classes, ", "), "jenis_materi": nil, "jilid": nil, "nama_surat": nil, "halaman": nil, "last_date": nil, "hasil_talaqqi": nil, "is_initial": false, "quality": row.Quality, "quality_label": row.QualityLabel}
			record, isInitial := row.LatestRecord, false
			if record == nil && row.BaselineRecord != nil {
				record, isInitial = row.BaselineRecord, true
			}
			if record != nil {
				item["jenis_materi"], item["jilid"], item["nama_surat"], item["halaman"], item["last_date"], item["hasil_talaqqi"], item["is_initial"] = record.Material, record.Level, record.Surah, record.Page, record.Date, record.Result, isInitial
			}
			rows = append(rows, item)
		}
		total := len(rows)
		if offset > total {
			offset = total
		}
		hermesSuccess(w, rows[offset:min(offset+limit, total)], hermesMeta(page, limit, int64(total)))
	}
	return nil
}

func hermesRowWithoutDetail(row tahsinReportRow) (map[string]any, error) {
	item, err := structMap(row)
	if err != nil {
		return nil, err
	}
	delete(item, "details")
	delete(item, "children")
	return item, nil
}

func cloneURLValues(values url.Values) url.Values {
	copy := make(url.Values, len(values))
	for key, value := range values {
		copy[key] = append([]string(nil), value...)
	}
	return copy
}

func (s *Server) hermesTeachers(ctx context.Context, w http.ResponseWriter, query url.Values) error {
	if raw := strings.TrimSpace(query.Get("id")); raw != "" {
		id, err := strconv.ParseInt(raw, 10, 64)
		if err != nil || id < 1 {
			integrationError(w, http.StatusBadRequest, "ID ustadz tidak valid.")
			return nil
		}
		item, err := hermesOne(ctx, s.DB, `SELECT u.id,u.username,u.nama_lengkap,u.no_hp,u.role,u.created_at,
            (SELECT COUNT(*) FROM halaqoh h WHERE h.ustadz_id=u.id) AS total_halaqoh,
            (SELECT COUNT(DISTINCT hm.wali_santri_id) FROM halaqoh h JOIN halaqoh_members hm ON hm.halaqoh_id=h.id WHERE h.ustadz_id=u.id AND hm.archived_at IS NULL) AS total_member,
            (SELECT COUNT(*) FROM presensi p JOIN halaqoh h ON h.id=p.halaqoh_id WHERE h.ustadz_id=u.id) AS total_input_presensi
            FROM users u WHERE u.id=? AND u.role='ustadz'`, id)
		if err != nil {
			return err
		}
		if item == nil {
			integrationError(w, http.StatusNotFound, "Ustadz tidak ditemukan.")
			return nil
		}
		groups, err := hermesRows(ctx, s.DB, `SELECT h.*,(SELECT COUNT(*) FROM halaqoh_members hm JOIN wali_santri w ON w.id=hm.wali_santri_id WHERE hm.halaqoh_id=h.id AND hm.archived_at IS NULL AND w.status_aktif=1) AS total_member FROM halaqoh h WHERE h.ustadz_id=?`, id)
		if err != nil {
			return err
		}
		item["halaqoh"] = groups
		hermesSuccess(w, item, nil)
		return nil
	}
	page, limit, offset, err := hermesPage(query)
	if err != nil {
		integrationError(w, http.StatusBadRequest, err.Error())
		return nil
	}
	base := " FROM users u WHERE u.role='ustadz'"
	selectSQL := `SELECT u.id,u.username,u.nama_lengkap,u.no_hp,u.role,u.created_at,
        (SELECT COUNT(*) FROM halaqoh h WHERE h.ustadz_id=u.id) AS total_halaqoh,
        (SELECT COUNT(DISTINCT hm.wali_santri_id) FROM halaqoh h JOIN halaqoh_members hm ON hm.halaqoh_id=h.id WHERE h.ustadz_id=u.id AND hm.archived_at IS NULL) AS total_member` + base + " ORDER BY u.nama_lengkap LIMIT ? OFFSET ?"
	rows, total, err := hermesPagedQuery(ctx, s.DB, selectSQL, "SELECT COUNT(*)"+base, nil, page, limit, offset)
	if err != nil {
		return err
	}
	hermesSuccess(w, rows, hermesMeta(page, limit, total))
	return nil
}

func (s *Server) hermesHolidays(ctx context.Context, w http.ResponseWriter, query url.Values) error {
	page, limit, offset, err := hermesPage(query)
	if err != nil {
		integrationError(w, http.StatusBadRequest, err.Error())
		return nil
	}
	conditions, args := []string{}, []any{}
	start, end := strings.TrimSpace(query.Get("start")), strings.TrimSpace(query.Get("end"))
	if start != "" && end != "" {
		if !validISODate(start) || !validISODate(end) || start > end {
			integrationError(w, http.StatusBadRequest, "Rentang tanggal tidak valid.")
			return nil
		}
		conditions, args = append(conditions, "tanggal BETWEEN ? AND ?"), append(args, start, end)
	}
	where := ""
	if len(conditions) > 0 {
		where = " WHERE " + strings.Join(conditions, " AND ")
	}
	rows, total, err := hermesPagedQuery(ctx, s.DB, "SELECT * FROM holidays"+where+" ORDER BY tanggal DESC LIMIT ? OFFSET ?", "SELECT COUNT(*) FROM holidays"+where, args, page, limit, offset)
	if err != nil {
		return err
	}
	hermesSuccess(w, rows, hermesMeta(page, limit, total))
	return nil
}

func validISODate(value string) bool {
	parsed, err := time.Parse("2006-01-02", value)
	return err == nil && parsed.Format("2006-01-02") == value
}

func (s *Server) hermesAnnouncements(ctx context.Context, w http.ResponseWriter, query url.Values) error {
	page, limit, offset, err := hermesPage(query)
	if err != nil {
		integrationError(w, http.StatusBadRequest, err.Error())
		return nil
	}
	where := ""
	if active := strings.TrimSpace(query.Get("aktif_only")); active == "1" || active == "true" || query.Get("aktif") == "1" {
		where = " WHERE is_aktif=1"
	}
	rows, total, err := hermesPagedQuery(ctx, s.DB, "SELECT * FROM pengumuman"+where+" ORDER BY created_at DESC LIMIT ? OFFSET ?", "SELECT COUNT(*) FROM pengumuman"+where, nil, page, limit, offset)
	if err != nil {
		return err
	}
	hermesSuccess(w, rows, hermesMeta(page, limit, total))
	return nil
}

func (s *Server) hermesSearch(ctx context.Context, w http.ResponseWriter, query url.Values) error {
	q := strings.TrimSpace(query.Get("q"))
	if q == "" {
		integrationError(w, http.StatusBadRequest, "Parameter \"q\" (query pencarian) wajib diisi.")
		return nil
	}
	if len([]rune(q)) > 100 {
		integrationError(w, http.StatusBadRequest, "Pencarian terlalu panjang.")
		return nil
	}
	_, limit, _, err := hermesPage(query)
	if err != nil {
		integrationError(w, http.StatusBadRequest, err.Error())
		return nil
	}
	pattern := hermesLike(q)
	queries := []struct {
		SQL  string
		Args []any
	}{
		{`SELECT w.id,w.nama_bapak AS nama,w.no_hp,w.kategori,h.nama_halaqoh AS kelompok,'wali_santri' AS tipe FROM wali_santri w LEFT JOIN halaqoh_members hm ON hm.wali_santri_id=w.id AND hm.archived_at IS NULL LEFT JOIN halaqoh h ON h.id=hm.halaqoh_id WHERE w.nama_bapak LIKE ? ESCAPE '!' OR w.no_hp LIKE ? ESCAPE '!' LIMIT ?`, []any{pattern, pattern, limit}},
		{`SELECT sd.id,sd.nama_anak AS nama,sd.kelas,w.nama_bapak AS nama_wali,'santri' AS tipe FROM santri_detail sd JOIN wali_santri w ON w.id=sd.wali_santri_id WHERE sd.nama_anak LIKE ? ESCAPE '!' LIMIT ?`, []any{pattern, limit}},
		{`SELECT h.id,h.nama_halaqoh AS nama,u.nama_lengkap AS ustadz,'halaqoh' AS tipe FROM halaqoh h JOIN users u ON u.id=h.ustadz_id WHERE h.nama_halaqoh LIKE ? ESCAPE '!' LIMIT ?`, []any{pattern, limit}},
		{`SELECT id,nama_lengkap AS nama,username,role,'user' AS tipe FROM users WHERE nama_lengkap LIKE ? ESCAPE '!' OR username LIKE ? ESCAPE '!' LIMIT ?`, []any{pattern, pattern, limit}},
	}
	results := []map[string]any{}
	for _, item := range queries {
		rows, err := hermesRows(ctx, s.DB, item.SQL, item.Args...)
		if err != nil {
			return err
		}
		results = append(results, rows...)
	}
	hermesSuccess(w, map[string]any{"query": q, "total": len(results), "results": results}, nil)
	return nil
}

func (s *Server) hermesSchema(ctx context.Context, w http.ResponseWriter) error {
	rows, err := hermesRows(ctx, s.DB, "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type IN ('BASE TABLE','VIEW') ORDER BY table_name")
	if err != nil {
		return err
	}
	tables := map[string]any{}
	for _, row := range rows {
		name, ok := row["table_name"].(string)
		if !ok || !safeHermesTable.MatchString(name) {
			continue
		}
		columns, err := hermesRows(ctx, s.DB, `SELECT column_name AS name,column_type AS type,is_nullable,column_key,column_default,extra,coalesce(column_comment,'') AS comment FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? ORDER BY ordinal_position`, name)
		if err != nil {
			return err
		}
		for i, column := range columns {
			columns[i] = map[string]any{"name": column["name"], "type": column["type"], "null": column["is_nullable"] == "YES", "key": column["column_key"], "default": column["column_default"], "extra": column["extra"], "comment": column["comment"]}
		}
		count, err := hermesCount(ctx, s.DB, "SELECT COUNT(*) FROM `"+name+"`")
		if err != nil {
			return err
		}
		tables[name] = map[string]any{"columns": columns, "row_count": count}
	}
	hermesSuccess(w, map[string]any{"database": s.Config.DatabaseName, "total_tables": len(tables), "tables": tables}, nil)
	return nil
}

func (s *Server) hermesLogs(ctx context.Context, w http.ResponseWriter, query url.Values) error {
	page, limit, offset, err := hermesPage(query)
	if err != nil {
		integrationError(w, http.StatusBadRequest, err.Error())
		return nil
	}
	conditions, args := []string{}, []any{}
	filterAction := strings.TrimSpace(query.Get("action_filter"))
	if filterAction == "" {
		filterAction = strings.TrimSpace(query.Get("log_action"))
	}
	if filterAction == "" && len(query["action"]) > 1 {
		filterAction = strings.TrimSpace(query["action"][1])
	}
	if filterAction != "" {
		conditions, args = append(conditions, "action=?"), append(args, filterAction)
	}
	start, end := strings.TrimSpace(query.Get("start")), strings.TrimSpace(query.Get("end"))
	if start != "" && end != "" {
		if !validISODate(start) || !validISODate(end) || start > end {
			integrationError(w, http.StatusBadRequest, "Rentang tanggal log tidak valid.")
			return nil
		}
		conditions, args = append(conditions, "created_at BETWEEN ? AND ?"), append(args, start+" 00:00:00", end+" 23:59:59")
	}
	where := ""
	if len(conditions) > 0 {
		where = " WHERE " + strings.Join(conditions, " AND ")
	}
	rows, total, err := hermesPagedQuery(ctx, s.DB, "SELECT * FROM activity_logs"+where+" ORDER BY created_at DESC LIMIT ? OFFSET ?", "SELECT COUNT(*) FROM activity_logs"+where, args, page, limit, offset)
	if err != nil {
		return err
	}
	hermesSuccess(w, rows, hermesMeta(page, limit, total))
	return nil
}

func (s *Server) hermesUsers(ctx context.Context, w http.ResponseWriter, query url.Values) error {
	page, limit, offset, err := hermesPage(query)
	if err != nil {
		integrationError(w, http.StatusBadRequest, err.Error())
		return nil
	}
	where, args := "", []any{}
	if role := strings.TrimSpace(query.Get("role")); role != "" {
		if !oneOf(role, "admin", "pj_tahfidz", "kepsek", "ustadz") {
			integrationError(w, http.StatusBadRequest, "Role tidak valid.")
			return nil
		}
		where, args = " WHERE role=?", []any{role}
	}
	rows, total, err := hermesPagedQuery(ctx, s.DB, "SELECT id,username,nama_lengkap,role,no_hp,created_at FROM users"+where+" ORDER BY nama_lengkap LIMIT ? OFFSET ?", "SELECT COUNT(*) FROM users"+where, args, page, limit, offset)
	if err != nil {
		return err
	}
	hermesSuccess(w, rows, hermesMeta(page, limit, total))
	return nil
}

func (s *Server) hermesFamilies(ctx context.Context, w http.ResponseWriter, query url.Values) error {
	ready, err := familySchemaInfo(ctx, s.DB)
	if err != nil {
		return err
	}
	if !ready {
		integrationError(w, http.StatusServiceUnavailable, "Migrasi keluarga belum diterapkan.")
		return nil
	}
	page, limit, offset, err := hermesPage(query)
	if err != nil {
		integrationError(w, http.StatusBadRequest, err.Error())
		return nil
	}
	search := strings.TrimSpace(query.Get("search"))
	if len([]rune(search)) > 100 {
		integrationError(w, http.StatusBadRequest, "Pencarian terlalu panjang.")
		return nil
	}
	where, args := "", []any{}
	if search != "" {
		pattern := hermesLike(search)
		where = " WHERE (s.nama_anak LIKE ? ESCAPE '!' OR s.nis LIKE ? ESCAPE '!' OR EXISTS(SELECT 1 FROM family_relations r JOIN family_guardians g ON g.id=r.guardian_id WHERE r.student_id=s.id AND r.is_current=1 AND g.source_name LIKE ? ESCAPE '!'))"
		args = []any{pattern, pattern, pattern}
	}
	total, err := hermesCount(ctx, s.DB, "SELECT COUNT(*) FROM family_students s"+where, args...)
	if err != nil {
		return err
	}
	students, err := hermesRows(ctx, s.DB, "SELECT s.* FROM family_students s"+where+" ORDER BY s.nama_anak,s.id LIMIT ? OFFSET ?", append(args, limit, offset)...)
	if err != nil {
		return err
	}
	if len(students) > 0 {
		ids := make([]any, 0, len(students))
		marks := make([]string, 0, len(students))
		byID := map[string][]map[string]any{}
		for _, student := range students {
			id := fmt.Sprint(student["id"])
			ids = append(ids, student["id"])
			marks = append(marks, "?")
			byID[id] = []map[string]any{}
		}
		parents, err := hermesRows(ctx, s.DB, "SELECT r.student_id,r.relationship,r.is_primary,g.source_namespace,g.source_parent_id,g.wali_santri_id,g.source_name,g.guardian_type FROM family_relations r JOIN family_guardians g ON g.id=r.guardian_id WHERE r.is_current=1 AND r.student_id IN ("+strings.Join(marks, ",")+") ORDER BY g.guardian_type,g.source_name", ids...)
		if err != nil {
			return err
		}
		for _, parent := range parents {
			id := fmt.Sprint(parent["student_id"])
			byID[id] = append(byID[id], parent)
		}
		for i := range students {
			students[i]["guardians"] = byID[fmt.Sprint(students[i]["id"])]
		}
	}
	hermesSuccess(w, students, hermesMeta(page, limit, total))
	return nil
}
