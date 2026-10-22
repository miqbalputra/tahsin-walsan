package app

import (
	"database/sql"
	"errors"
	"net/http"
	"strconv"
	"strings"
)

type capaianRow struct {
	WaliID    int64  `json:"wali_id"`
	Name      string `json:"nama_bapak"`
	Phone     string `json:"no_hp"`
	Children  string `json:"children_names"`
	Classes   string `json:"classes"`
	GroupID   int64  `json:"halaqoh_id"`
	GroupName string `json:"nama_halaqoh"`
	Teacher   string `json:"ustadz_name"`
	RecordID  *int64 `json:"presensi_id"`
	Material  string `json:"jenis_materi"`
	Level     string `json:"jilid"`
	Surah     string `json:"nama_surat"`
	Page      string `json:"halaman"`
	Result    string `json:"hasil_talaqqi"`
	LastDate  string `json:"last_date"`
	IsInitial bool   `json:"is_initial"`
}

type capaianGroup struct {
	ID   int64  `json:"id"`
	Name string `json:"nama_halaqoh"`
}

func (s *Server) handleCapaians(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		methodNotAllowed(w, http.MethodGet)
		return
	}
	filters, err := parseCapaianFilters(r)
	if err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, err.Error())
		return
	}
	auth, _ := currentAuth(r)
	childTable, err := familyChildSource(r.Context(), s.DB)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Data keluarga belum dapat dibaca.")
		return
	}
	where := []string{"hm.archived_at IS NULL"}
	args := []any{}
	if auth.User.Role == "ustadz" {
		where = append(where, "h.ustadz_id=?")
		args = append(args, auth.User.ID)
	}
	if filters.GroupID > 0 {
		where = append(where, "h.id=?")
		args = append(args, filters.GroupID)
	}
	if filters.Search != "" {
		where = append(where, "(w.nama_bapak LIKE ? OR h.nama_halaqoh LIKE ?)")
		pattern := "%" + filters.Search + "%"
		args = append(args, pattern, pattern)
	}
	from := " FROM wali_santri w JOIN halaqoh_members hm ON hm.wali_santri_id=w.id JOIN halaqoh h ON h.id=hm.halaqoh_id JOIN users u ON u.id=h.ustadz_id LEFT JOIN (SELECT wali_santri_id,GROUP_CONCAT(DISTINCT nama_anak ORDER BY nama_anak SEPARATOR ', ') AS children_names,GROUP_CONCAT(DISTINCT kelas ORDER BY kelas SEPARATOR ', ') AS classes FROM " + childTable + " GROUP BY wali_santri_id) c ON c.wali_santri_id=w.id LEFT JOIN (SELECT p1.* FROM presensi p1 WHERE p1.status='H' AND p1.tanggal<=CURRENT_DATE AND NOT EXISTS (SELECT 1 FROM presensi p2 WHERE p2.wali_santri_id=p1.wali_santri_id AND p2.status='H' AND p2.tanggal<=CURRENT_DATE AND (p2.tanggal>p1.tanggal OR (p2.tanggal=p1.tanggal AND p2.id>p1.id)))) p ON p.wali_santri_id=w.id WHERE " + strings.Join(where, " AND ")
	var total int64
	if err = s.DB.QueryRowContext(r.Context(), "SELECT COUNT(DISTINCT w.id,h.id)"+from, args...).Scan(&total); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Daftar capaian belum dapat dibaca.")
		return
	}
	pageArgs := append(append([]any{}, args...), filters.Limit, (filters.Page-1)*filters.Limit)
	query := "SELECT DISTINCT w.id,w.nama_bapak,COALESCE(w.no_hp,''),COALESCE(c.children_names,''),COALESCE(c.classes,''),h.id,h.nama_halaqoh,u.nama_lengkap,p.id,COALESCE(p.jenis_materi,''),CAST(COALESCE(p.jilid,'') AS CHAR),COALESCE(p.nama_surat,''),COALESCE(p.halaman,''),COALESCE(p.hasil_talaqqi,''),COALESCE(DATE_FORMAT(p.tanggal,'%Y-%m-%d'),'')" + from + " ORDER BY h.nama_halaqoh,w.nama_bapak LIMIT ? OFFSET ?"
	rows, err := s.DB.QueryContext(r.Context(), query, pageArgs...)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Daftar capaian belum dapat dibaca.")
		return
	}
	items := []capaianRow{}
	for rows.Next() {
		var item capaianRow
		if err = rows.Scan(&item.WaliID, &item.Name, &item.Phone, &item.Children, &item.Classes, &item.GroupID, &item.GroupName, &item.Teacher, &item.RecordID, &item.Material, &item.Level, &item.Surah, &item.Page, &item.Result, &item.LastDate); err != nil {
			rows.Close()
			writeAPIError(w, http.StatusServiceUnavailable, "Daftar capaian belum dapat dibaca.")
			return
		}
		items = append(items, item)
	}
	if err = rows.Err(); err != nil {
		rows.Close()
		writeAPIError(w, http.StatusServiceUnavailable, "Daftar capaian belum dapat dibaca.")
		return
	}
	rows.Close()
	ready, _, err := tahsinReportSchemaReady(r.Context(), s.DB)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Status skema capaian belum dapat diperiksa.")
		return
	}
	if ready && len(items) > 0 {
		ids := make([]string, len(items))
		args := make([]any, len(items))
		for i, item := range items {
			ids[i], args[i] = "?", item.WaliID
		}
		baselineRows, queryErr := s.DB.QueryContext(r.Context(), "SELECT wali_santri_id,COALESCE(jenis_materi,''),CAST(COALESCE(jilid,'') AS CHAR),COALESCE(nama_surat,''),COALESCE(halaman,'') FROM tahsin_capaian_awal WHERE wali_santri_id IN ("+strings.Join(ids, ",")+")", args...)
		if queryErr != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Capaian awal belum dapat dibaca.")
			return
		}
		baselines := map[int64]capaianRow{}
		for baselineRows.Next() {
			var id int64
			var baseline capaianRow
			if err = baselineRows.Scan(&id, &baseline.Material, &baseline.Level, &baseline.Surah, &baseline.Page); err != nil {
				baselineRows.Close()
				writeAPIError(w, http.StatusServiceUnavailable, "Capaian awal belum dapat dibaca.")
				return
			}
			baselines[id] = baseline
		}
		if err = baselineRows.Err(); err != nil {
			baselineRows.Close()
			writeAPIError(w, http.StatusServiceUnavailable, "Capaian awal belum dapat dibaca.")
			return
		}
		baselineRows.Close()
		for i := range items {
			if items[i].RecordID == nil {
				if baseline, ok := baselines[items[i].WaliID]; ok {
					items[i].Material, items[i].Level, items[i].Surah, items[i].Page = baseline.Material, baseline.Level, baseline.Surah, baseline.Page
					items[i].IsInitial = true
				}
			}
		}
	}
	groupsQuery := "SELECT id,nama_halaqoh FROM halaqoh"
	groupArgs := []any{}
	if auth.User.Role == "ustadz" {
		groupsQuery += " WHERE ustadz_id=?"
		groupArgs = append(groupArgs, auth.User.ID)
	}
	groupsQuery += " ORDER BY nama_halaqoh,id"
	groupRows, err := s.DB.QueryContext(r.Context(), groupsQuery, groupArgs...)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Filter kelompok belum dapat dibaca.")
		return
	}
	groups := []capaianGroup{}
	for groupRows.Next() {
		var group capaianGroup
		if err = groupRows.Scan(&group.ID, &group.Name); err != nil {
			groupRows.Close()
			writeAPIError(w, http.StatusServiceUnavailable, "Filter kelompok belum dapat dibaca.")
			return
		}
		groups = append(groups, group)
	}
	if err = groupRows.Err(); err != nil {
		groupRows.Close()
		writeAPIError(w, http.StatusServiceUnavailable, "Filter kelompok belum dapat dibaca.")
		return
	}
	groupRows.Close()
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"rows": items, "groups": groups, "schema_ready": ready}, "meta": map[string]any{"page": filters.Page, "limit": filters.Limit, "total": total, "pages": max(int64(1), (total+int64(filters.Limit)-1)/int64(filters.Limit))}})
}

func parseCapaianFilters(r *http.Request) (struct {
	GroupID int64
	Search  string
	Page    int
	Limit   int
}, error) {
	var filters struct {
		GroupID int64
		Search  string
		Page    int
		Limit   int
	}
	filters.Page, filters.Limit = 1, 50
	q := r.URL.Query()
	if values, ok := q["halaqoh_id"]; ok {
		if len(values) != 1 || values[0] == "" {
			return filters, errors.New("kelompok tidak valid")
		}
		id, err := strconv.ParseInt(values[0], 10, 64)
		if err != nil || id < 1 || strconv.FormatInt(id, 10) != values[0] {
			return filters, errors.New("kelompok tidak valid")
		}
		filters.GroupID = id
	}
	filters.Search = strings.TrimSpace(q.Get("search"))
	if len(filters.Search) > 100 || !validParticipantText(filters.Search, 100, false) {
		return filters, errors.New("pencarian terlalu panjang atau tidak valid")
	}
	for key, target := range map[string]*int{"page": &filters.Page, "limit": &filters.Limit} {
		if values, ok := q[key]; ok {
			if len(values) != 1 || values[0] == "" {
				return filters, errors.New("parameter halaman tidak valid")
			}
			value, err := strconv.Atoi(values[0])
			if err != nil || value < 1 || value > 100000 {
				return filters, errors.New("parameter halaman tidak valid")
			}
			*target = value
		}
	}
	if filters.Limit > 100 {
		filters.Limit = 100
	}
	return filters, nil
}

func (s *Server) handleCapaianUpdate(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		methodNotAllowed(w, http.MethodPost)
		return
	}
	input, err := decodeCapaianInput(w, r)
	if err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, err.Error())
		return
	}
	if input.WaliID < 1 || input.GroupID < 1 || !validCapaianMaterial(input.Material, input.Level, input.Surah, input.Page) {
		writeAPIError(w, http.StatusUnprocessableEntity, "Materi, jilid/surat, halaman, wali, dan kelompok wajib valid.")
		return
	}
	if input.RecordID > 0 && input.Result != "Lulus" && input.Result != "Ulang" {
		writeAPIError(w, http.StatusUnprocessableEntity, "Hasil talaqqi wajib Lulus atau Ulang.")
		return
	}
	ready, _, err := tahsinReportSchemaReady(r.Context(), s.DB)
	if err != nil || !ready {
		writeAPIError(w, http.StatusConflict, "Migrasi histori Tahsin belum siap; capaian belum diubah.")
		return
	}
	auth, _ := currentAuth(r)
	tx, err := s.DB.BeginTx(r.Context(), nil)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Perubahan capaian belum dapat dimulai.")
		return
	}
	defer tx.Rollback()
	var waliID int64
	if err = tx.QueryRowContext(r.Context(), "SELECT id FROM wali_santri WHERE id=? FOR UPDATE", input.WaliID).Scan(&waliID); errors.Is(err, sql.ErrNoRows) {
		writeAPIError(w, http.StatusNotFound, "Wali tidak ditemukan.")
		return
	} else if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Wali belum dapat diperiksa.")
		return
	}
	if input.RecordID > 0 {
		var date string
		if err = tx.QueryRowContext(r.Context(), "SELECT DATE_FORMAT(tanggal,'%Y-%m-%d') FROM presensi WHERE id=? AND wali_santri_id=? AND status='H' FOR UPDATE", input.RecordID, input.WaliID).Scan(&date); errors.Is(err, sql.ErrNoRows) {
			writeAPIError(w, http.StatusConflict, "Catatan Hadir berubah atau bukan milik wali ini; muat ulang data.")
			return
		} else if err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Catatan Hadir belum dapat diperiksa.")
			return
		}
		if _, err = tx.ExecContext(r.Context(), "UPDATE presensi SET jenis_materi=?,jilid=?,nama_surat=?,halaman=?,hasil_talaqqi=? WHERE id=? AND wali_santri_id=? AND status='H'", input.Material, input.LevelValue(), input.SurahValue(), input.Page, input.Result, input.RecordID, input.WaliID); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Capaian Hadir belum dapat disimpan.")
			return
		}
		if _, err = tx.ExecContext(r.Context(), "INSERT INTO tahsin_autosave_revisions(wali_id,session_date,revision) VALUES(?,?,1) ON DUPLICATE KEY UPDATE revision=revision+1", input.WaliID, date); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Revisi draft belum dapat diperbarui.")
			return
		}
	} else {
		var active int
		if err = tx.QueryRowContext(r.Context(), "SELECT COUNT(*) FROM halaqoh_members WHERE wali_santri_id=? AND halaqoh_id=? AND archived_at IS NULL", input.WaliID, input.GroupID).Scan(&active); err != nil || active == 0 {
			writeAPIError(w, http.StatusConflict, "Keanggotaan aktif kelompok berubah; muat ulang data.")
			return
		}
		if _, err = tx.ExecContext(r.Context(), "INSERT INTO tahsin_capaian_awal(wali_santri_id,jenis_materi,jilid,nama_surat,halaman,updated_by) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE jenis_materi=VALUES(jenis_materi),jilid=VALUES(jilid),nama_surat=VALUES(nama_surat),halaman=VALUES(halaman),updated_by=VALUES(updated_by),updated_at=NOW(6)", input.WaliID, input.Material, input.LevelValue(), input.SurahValue(), input.Page, auth.User.ID); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Capaian awal belum dapat disimpan.")
			return
		}
	}
	action := "UPDATE_CAPAIAN"
	description := "Update capaian presensi_id=" + strconv.FormatInt(input.RecordID, 10) + "; wali_id=" + strconv.FormatInt(input.WaliID, 10)
	if input.RecordID == 0 {
		action = "SAVE_CAPAIAN_AWAL"
		description = "Simpan capaian awal wali_id=" + strconv.FormatInt(input.WaliID, 10)
	}
	if _, err = tx.ExecContext(r.Context(), "INSERT INTO activity_logs(user_id,username,action,description,ip_address) VALUES(?,?,?,?,?)", auth.User.ID, auth.User.Username, action, description, clientIP(r)); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Log perubahan capaian belum dapat disimpan.")
		return
	}
	if err = tx.Commit(); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Perubahan capaian belum dapat diselesaikan.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"wali_id": input.WaliID, "presensi_id": input.RecordID, "saved": true}})
}

type capaianInput struct {
	WaliID   int64  `json:"wali_id"`
	GroupID  int64  `json:"halaqoh_id"`
	RecordID int64  `json:"presensi_id"`
	Material string `json:"jenis_materi"`
	Level    string `json:"jilid"`
	Surah    string `json:"nama_surat"`
	Page     string `json:"halaman"`
	Result   string `json:"hasil_talaqqi"`
}

func decodeCapaianInput(w http.ResponseWriter, r *http.Request) (capaianInput, error) {
	var input capaianInput
	if err := decodeSmallJSON(w, r, 16<<10, &input); err != nil {
		return input, errors.New("data capaian tidak valid")
	}
	input.Material, input.Level, input.Surah, input.Page, input.Result = strings.TrimSpace(input.Material), strings.TrimSpace(input.Level), strings.TrimSpace(input.Surah), strings.TrimSpace(input.Page), strings.TrimSpace(input.Result)
	return input, nil
}

func validCapaianMaterial(material, level, surah, page string) bool {
	if page == "" || len(page) > 30 || !validParticipantText(page, 30, false) {
		return false
	}
	switch material {
	case "Iqro":
		value, err := strconv.Atoi(level)
		return err == nil && value >= 1 && value <= 6 && strconv.Itoa(value) == level && surah == ""
	case "Al Quran":
		return level == "" && surah != "" && len(surah) <= 100 && validParticipantText(surah, 100, false)
	default:
		return false
	}
}

func (input capaianInput) LevelValue() any {
	if input.Material != "Iqro" {
		return nil
	}
	value, _ := strconv.Atoi(input.Level)
	return value
}

func (input capaianInput) SurahValue() any {
	if input.Material != "Al Quran" {
		return nil
	}
	return input.Surah
}
