package app

import (
	"database/sql"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"strconv"
	"strings"
	"unicode/utf8"
)

type groupInput struct {
	Name      string `json:"nama_halaqoh"`
	TeacherID int64  `json:"ustadz_id"`
	Program   string `json:"program_code"`
}

type managedGroup struct {
	ID          int64  `json:"id"`
	Name        string `json:"nama_halaqoh"`
	TeacherID   int64  `json:"ustadz_id"`
	TeacherName string `json:"nama_ustadz"`
	Program     string `json:"program_code"`
	MemberCount int64  `json:"total_member"`
}

type groupTeacher struct {
	ID   int64  `json:"id"`
	Name string `json:"nama_lengkap"`
}

type groupMember struct {
	ID       int64  `json:"id"`
	Name     string `json:"nama_bapak"`
	Phone    string `json:"no_hp"`
	Children string `json:"daftar_anak"`
}

func validateGroupInput(input groupInput) (groupInput, error) {
	input.Name = strings.TrimSpace(input.Name)
	if input.Name == "" || !utf8.ValidString(input.Name) || utf8.RuneCountInString(input.Name) > 100 || strings.ContainsAny(input.Name, "\x00\r\n") {
		return groupInput{}, errors.New("nama kelompok wajib diisi, maksimal 100 karakter")
	}
	if input.TeacherID < 1 {
		return groupInput{}, errors.New("pilih guru tahsin")
	}
	if input.Program != "bapak" && input.Program != "ibu" {
		return groupInput{}, errors.New("program harus Bapak atau Ibu")
	}
	return input, nil
}

func (s *Server) handleGroupManagement(w http.ResponseWriter, r *http.Request) {
	switch r.Method {
	case http.MethodGet:
		s.listManagedGroups(w, r)
	case http.MethodPost:
		s.saveManagedGroup(w, r, 0)
	case http.MethodPatch:
		id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
		if err != nil || id < 1 {
			writeAPIError(w, http.StatusUnprocessableEntity, "ID kelompok tidak valid.")
			return
		}
		s.saveManagedGroup(w, r, id)
	default:
		methodNotAllowed(w, "GET, POST, PATCH")
	}
}

func (s *Server) listManagedGroups(w http.ResponseWriter, r *http.Request) {
	rows, err := s.DB.QueryContext(r.Context(), `SELECT h.id,h.nama_halaqoh,h.ustadz_id,COALESCE(u.nama_lengkap,''),h.program_code,
		(SELECT COUNT(*) FROM halaqoh_members hm JOIN wali_santri ws ON ws.id=hm.wali_santri_id
		 WHERE hm.halaqoh_id=h.id AND hm.archived_at IS NULL AND ws.status_aktif=1) AS total_member
		FROM halaqoh h LEFT JOIN users u ON u.id=h.ustadz_id ORDER BY h.nama_halaqoh,h.id`)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Daftar kelompok belum dapat dibaca.")
		return
	}
	defer rows.Close()
	groups := make([]managedGroup, 0)
	for rows.Next() {
		var item managedGroup
		if err := rows.Scan(&item.ID, &item.Name, &item.TeacherID, &item.TeacherName, &item.Program, &item.MemberCount); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Daftar kelompok belum dapat dibaca.")
			return
		}
		groups = append(groups, item)
	}
	if err := rows.Err(); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Daftar kelompok belum dapat dibaca.")
		return
	}
	teacherRows, err := s.DB.QueryContext(r.Context(), "SELECT id,nama_lengkap FROM users WHERE role='ustadz' ORDER BY nama_lengkap,id")
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Daftar guru belum dapat dibaca.")
		return
	}
	defer teacherRows.Close()
	teachers := make([]groupTeacher, 0)
	for teacherRows.Next() {
		var item groupTeacher
		if err := teacherRows.Scan(&item.ID, &item.Name); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Daftar guru belum dapat dibaca.")
			return
		}
		teachers = append(teachers, item)
	}
	if err := teacherRows.Err(); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Daftar guru belum dapat dibaca.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"groups": groups, "teachers": teachers}})
}

func (s *Server) saveManagedGroup(w http.ResponseWriter, r *http.Request, id int64) {
	r.Body = http.MaxBytesReader(w, r.Body, 16<<10)
	decoder := json.NewDecoder(r.Body)
	decoder.DisallowUnknownFields()
	var input groupInput
	if err := decoder.Decode(&input); err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, "Isian kelompok tidak valid.")
		return
	}
	var extra any
	if err := decoder.Decode(&extra); !errors.Is(err, io.EOF) {
		writeAPIError(w, http.StatusUnprocessableEntity, "Permintaan kelompok harus berisi satu objek JSON.")
		return
	}
	input, err := validateGroupInput(input)
	if err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, err.Error()+".")
		return
	}
	tx, err := s.DB.BeginTx(r.Context(), nil)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Perubahan kelompok belum dapat dimulai.")
		return
	}
	defer tx.Rollback()
	var teacherExists int
	if err = tx.QueryRowContext(r.Context(), "SELECT COUNT(*) FROM users WHERE id=? AND role='ustadz'", input.TeacherID).Scan(&teacherExists); err != nil || teacherExists != 1 {
		writeAPIError(w, http.StatusUnprocessableEntity, "Guru tahsin yang dipilih tidak ditemukan.")
		return
	}
	var action, description string
	if id == 0 {
		result, insertErr := tx.ExecContext(r.Context(), "INSERT INTO halaqoh(nama_halaqoh,ustadz_id,program_code) VALUES(?,?,?)", input.Name, input.TeacherID, input.Program)
		if insertErr != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Kelompok belum dapat dibuat.")
			return
		}
		id, err = result.LastInsertId()
		if err != nil || id < 1 {
			writeAPIError(w, http.StatusServiceUnavailable, "Kelompok belum dapat dibuat.")
			return
		}
		action = "CREATE_HALAQOH"
		description = "id=" + strconv.FormatInt(id, 10) + "; program=" + input.Program
	} else {
		var existingProgram string
		if err = tx.QueryRowContext(r.Context(), "SELECT program_code FROM halaqoh WHERE id=? FOR UPDATE", id).Scan(&existingProgram); errors.Is(err, sql.ErrNoRows) {
			writeAPIError(w, http.StatusNotFound, "Kelompok tidak ditemukan.")
			return
		} else if err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Kelompok belum dapat diperbarui.")
			return
		}
		if existingProgram != input.Program {
			var history, drafts int
			if err = tx.QueryRowContext(r.Context(), "SELECT COUNT(*) FROM presensi WHERE halaqoh_id=?", id).Scan(&history); err != nil {
				writeAPIError(w, http.StatusServiceUnavailable, "Riwayat kelompok belum dapat diperiksa.")
				return
			}
			if err = tx.QueryRowContext(r.Context(), "SELECT COUNT(*) FROM tahsin_autosave_drafts WHERE halaqoh_id=?", id).Scan(&drafts); err != nil {
				writeAPIError(w, http.StatusServiceUnavailable, "Draft kelompok belum dapat diperiksa.")
				return
			}
			if history > 0 || drafts > 0 {
				writeAPIError(w, http.StatusConflict, "Program kelompok yang sudah memiliki riwayat atau draft tidak dapat diganti. Buat kelompok baru agar riwayat Bapak dan Ibu tetap terpisah.")
				return
			}
		}
		if _, err = tx.ExecContext(r.Context(), "UPDATE halaqoh SET nama_halaqoh=?,ustadz_id=?,program_code=? WHERE id=?", input.Name, input.TeacherID, input.Program, id); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Kelompok belum dapat diperbarui.")
			return
		}
		action = "UPDATE_HALAQOH"
		description = "id=" + strconv.FormatInt(id, 10) + "; program=" + input.Program
	}
	auth, _ := currentAuth(r)
	if _, err = tx.ExecContext(r.Context(), "INSERT INTO activity_logs(user_id,username,action,description,ip_address) VALUES(?,?,?,?,?)", auth.User.ID, auth.User.Username, action, description, clientIP(r)); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Log perubahan kelompok belum dapat disimpan.")
		return
	}
	if err = tx.Commit(); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Perubahan kelompok belum dapat diselesaikan.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"id": id, "nama_halaqoh": input.Name, "ustadz_id": input.TeacherID, "program_code": input.Program}})
}

func groupIDFromPath(r *http.Request) (int64, error) {
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil || id < 1 {
		return 0, errors.New("ID kelompok tidak valid")
	}
	return id, nil
}

func (s *Server) handleGroupMembers(w http.ResponseWriter, r *http.Request) {
	groupID, err := groupIDFromPath(r)
	if err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, err.Error()+".")
		return
	}
	switch r.Method {
	case http.MethodGet:
		s.listGroupMembers(w, r, groupID)
	case http.MethodPost:
		var input struct {
			WaliID int64 `json:"wali_santri_id"`
		}
		if err := decodeSmallJSON(w, r, 8<<10, &input); err != nil || input.WaliID < 1 {
			writeAPIError(w, http.StatusUnprocessableEntity, "ID peserta tidak valid.")
			return
		}
		s.addGroupMember(w, r, groupID, input.WaliID)
	case http.MethodDelete:
		waliID, err := strconv.ParseInt(r.PathValue("waliID"), 10, 64)
		if err != nil || waliID < 1 {
			writeAPIError(w, http.StatusUnprocessableEntity, "ID peserta tidak valid.")
			return
		}
		s.removeGroupMember(w, r, groupID, waliID)
	default:
		methodNotAllowed(w, "GET, POST, DELETE")
	}
}

func decodeSmallJSON(w http.ResponseWriter, r *http.Request, limit int64, target any) error {
	r.Body = http.MaxBytesReader(w, r.Body, limit)
	decoder := json.NewDecoder(r.Body)
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(target); err != nil {
		return err
	}
	var trailing any
	if err := decoder.Decode(&trailing); !errors.Is(err, io.EOF) {
		return errors.New("additional JSON values")
	}
	return nil
}

func (s *Server) listGroupMembers(w http.ResponseWriter, r *http.Request, groupID int64) {
	var exists int
	if err := s.DB.QueryRowContext(r.Context(), "SELECT COUNT(*) FROM halaqoh WHERE id=?", groupID).Scan(&exists); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Kelompok belum dapat dibaca.")
		return
	}
	if exists != 1 {
		writeAPIError(w, http.StatusNotFound, "Kelompok tidak ditemukan.")
		return
	}
	childTable, err := familyChildSource(r.Context(), s.DB)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Daftar anak belum dapat dibaca.")
		return
	}
	childrenExpr := "COALESCE(GROUP_CONCAT(DISTINCT CONCAT(c.nama_anak,IF(COALESCE(c.kelas,'')='', '', CONCAT(' [',c.kelas,']'))) ORDER BY c.nama_anak SEPARATOR ', '),'')"
	memberRows, err := s.DB.QueryContext(r.Context(), "SELECT w.id,w.nama_bapak,COALESCE(w.no_hp,''),"+childrenExpr+" FROM wali_santri w JOIN halaqoh_members hm ON hm.wali_santri_id=w.id LEFT JOIN "+childTable+" c ON c.wali_santri_id=w.id WHERE hm.halaqoh_id=? AND hm.archived_at IS NULL AND w.status_aktif=1 GROUP BY w.id,w.nama_bapak,w.no_hp ORDER BY w.nama_bapak,w.id", groupID)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Daftar anggota belum dapat dibaca.")
		return
	}
	current, err := scanGroupMembers(memberRows)
	memberRows.Close()
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Daftar anggota belum dapat dibaca.")
		return
	}
	activeChild := "LOWER(TRIM(COALESCE(c.kelas,''))) <> 'lulus'"
	if childTable == "family_children" {
		activeChild = "c.school_status='active' AND TRIM(COALESCE(c.kelas,''))<>''"
	} else {
		activeChild += " AND TRIM(COALESCE(c.kelas,''))<>''"
	}
	availableQuery := "SELECT w.id,w.nama_bapak,COALESCE(w.no_hp,''),(SELECT COALESCE(GROUP_CONCAT(DISTINCT CONCAT(c2.nama_anak,IF(COALESCE(c2.kelas,'')='', '', CONCAT(' [',c2.kelas,']'))) ORDER BY c2.nama_anak SEPARATOR ', '),'') FROM " + childTable + " c2 WHERE c2.wali_santri_id=w.id) FROM wali_santri w WHERE w.status_aktif=1 AND EXISTS(SELECT 1 FROM " + childTable + " c WHERE c.wali_santri_id=w.id AND " + activeChild + ") AND NOT EXISTS(SELECT 1 FROM halaqoh_members hm WHERE hm.wali_santri_id=w.id AND hm.archived_at IS NULL) ORDER BY w.nama_bapak,w.id"
	availableRows, err := s.DB.QueryContext(r.Context(), availableQuery)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Peserta yang dapat ditambahkan belum dapat dibaca.")
		return
	}
	available, err := scanGroupMembers(availableRows)
	availableRows.Close()
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Peserta yang dapat ditambahkan belum dapat dibaca.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"members": current, "available": available}})
}

func scanGroupMembers(rows *sql.Rows) ([]groupMember, error) {
	items := make([]groupMember, 0)
	for rows.Next() {
		var item groupMember
		if err := rows.Scan(&item.ID, &item.Name, &item.Phone, &item.Children); err != nil {
			return nil, err
		}
		items = append(items, item)
	}
	return items, rows.Err()
}

func (s *Server) addGroupMember(w http.ResponseWriter, r *http.Request, groupID, waliID int64) {
	tx, err := s.DB.BeginTx(r.Context(), nil)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Penempatan peserta belum dapat dimulai.")
		return
	}
	defer tx.Rollback()
	var lockedGroupID int64
	if err = tx.QueryRowContext(r.Context(), "SELECT id FROM halaqoh WHERE id=? FOR UPDATE", groupID).Scan(&lockedGroupID); errors.Is(err, sql.ErrNoRows) {
		writeAPIError(w, http.StatusNotFound, "Kelompok tidak ditemukan.")
		return
	} else if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Kelompok belum dapat diperiksa.")
		return
	}
	var active int
	if err = tx.QueryRowContext(r.Context(), "SELECT status_aktif FROM wali_santri WHERE id=? FOR UPDATE", waliID).Scan(&active); errors.Is(err, sql.ErrNoRows) {
		writeAPIError(w, http.StatusNotFound, "Peserta tidak ditemukan.")
		return
	} else if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Peserta belum dapat diperiksa.")
		return
	}
	if active != 1 {
		writeAPIError(w, http.StatusConflict, "Peserta alumni/tidak aktif tidak dapat dimasukkan ke kelompok.")
		return
	}
	childTable, err := familyChildSource(r.Context(), s.DB)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Status anak belum dapat diperiksa.")
		return
	}
	activeChild := "LOWER(TRIM(COALESCE(kelas,''))) <> 'lulus' AND TRIM(COALESCE(kelas,''))<>''"
	if childTable == "family_children" {
		activeChild = "school_status='active' AND TRIM(COALESCE(kelas,''))<>''"
	}
	var hasActiveChild int
	if err = tx.QueryRowContext(r.Context(), "SELECT COUNT(*) FROM "+childTable+" WHERE wali_santri_id=? AND "+activeChild, waliID).Scan(&hasActiveChild); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Status anak belum dapat diperiksa.")
		return
	}
	if hasActiveChild == 0 {
		writeAPIError(w, http.StatusConflict, "Peserta tanpa anak aktif tidak dapat dimasukkan ke kelompok.")
		return
	}
	activeRows, err := tx.QueryContext(r.Context(), "SELECT halaqoh_id FROM halaqoh_members WHERE wali_santri_id=? AND archived_at IS NULL FOR UPDATE", waliID)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Keanggotaan peserta belum dapat diperiksa.")
		return
	}
	alreadyActive := false
	for activeRows.Next() {
		var activeGroup int64
		if err = activeRows.Scan(&activeGroup); err != nil {
			activeRows.Close()
			writeAPIError(w, http.StatusServiceUnavailable, "Keanggotaan peserta belum dapat diperiksa.")
			return
		}
		if activeGroup == groupID {
			alreadyActive = true
		} else {
			activeRows.Close()
			writeAPIError(w, http.StatusConflict, "Peserta sudah ditempatkan di kelompok lain. Pindahkan melalui pengaturan peserta.")
			return
		}
	}
	if err = activeRows.Err(); err != nil {
		activeRows.Close()
		writeAPIError(w, http.StatusServiceUnavailable, "Keanggotaan peserta belum dapat diperiksa.")
		return
	}
	activeRows.Close()
	if alreadyActive {
		writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"wali_santri_id": waliID, "halaqoh_id": groupID, "already_active": true}})
		return
	}
	var membershipID int64
	err = tx.QueryRowContext(r.Context(), "SELECT id FROM halaqoh_members WHERE halaqoh_id=? AND wali_santri_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE", groupID, waliID).Scan(&membershipID)
	if err == nil {
		_, err = tx.ExecContext(r.Context(), "UPDATE halaqoh_members SET archived_at=NULL,archive_reason=NULL WHERE id=?", membershipID)
	} else if errors.Is(err, sql.ErrNoRows) {
		_, err = tx.ExecContext(r.Context(), "INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(?,?)", groupID, waliID)
	}
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Peserta belum dapat ditambahkan ke kelompok.")
		return
	}
	if err = s.logGroupMembership(r, tx, "ADD_HALAQOH_MEMBER", groupID, waliID); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Log penempatan belum dapat disimpan.")
		return
	}
	if err = tx.Commit(); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Penempatan peserta belum dapat diselesaikan.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"wali_santri_id": waliID, "halaqoh_id": groupID}})
}

func (s *Server) removeGroupMember(w http.ResponseWriter, r *http.Request, groupID, waliID int64) {
	tx, err := s.DB.BeginTx(r.Context(), nil)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Perubahan keanggotaan belum dapat dimulai.")
		return
	}
	defer tx.Rollback()
	var lockedGroupID int64
	if err = tx.QueryRowContext(r.Context(), "SELECT id FROM halaqoh WHERE id=? FOR UPDATE", groupID).Scan(&lockedGroupID); errors.Is(err, sql.ErrNoRows) {
		writeAPIError(w, http.StatusNotFound, "Kelompok tidak ditemukan.")
		return
	} else if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Kelompok belum dapat diperiksa.")
		return
	}
	var lockedWaliID int64
	if err = tx.QueryRowContext(r.Context(), "SELECT id FROM wali_santri WHERE id=? FOR UPDATE", waliID).Scan(&lockedWaliID); errors.Is(err, sql.ErrNoRows) {
		writeAPIError(w, http.StatusNotFound, "Peserta tidak ditemukan.")
		return
	} else if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Peserta belum dapat diperiksa.")
		return
	}
	result, err := tx.ExecContext(r.Context(), "UPDATE halaqoh_members SET archived_at=CURRENT_TIMESTAMP,archive_reason='MANUAL_REMOVE' WHERE halaqoh_id=? AND wali_santri_id=? AND archived_at IS NULL", groupID, waliID)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Keanggotaan belum dapat diarsipkan.")
		return
	}
	changed, err := result.RowsAffected()
	if err != nil || changed == 0 {
		writeAPIError(w, http.StatusNotFound, "Anggota aktif tidak ditemukan di kelompok ini.")
		return
	}
	if err = s.logGroupMembership(r, tx, "REMOVE_HALAQOH_MEMBER", groupID, waliID); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Log perubahan belum dapat disimpan.")
		return
	}
	if err = tx.Commit(); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Perubahan keanggotaan belum dapat diselesaikan.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"wali_santri_id": waliID, "halaqoh_id": groupID, "archived": true}})
}

func (s *Server) logGroupMembership(r *http.Request, tx *sql.Tx, action string, groupID, waliID int64) error {
	auth, _ := currentAuth(r)
	description := "halaqoh_id=" + strconv.FormatInt(groupID, 10) + "; wali_santri_id=" + strconv.FormatInt(waliID, 10)
	_, err := tx.ExecContext(r.Context(), "INSERT INTO activity_logs(user_id,username,action,description,ip_address) VALUES(?,?,?,?,?)", auth.User.ID, auth.User.Username, action, description, clientIP(r))
	return err
}
