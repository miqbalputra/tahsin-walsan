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
