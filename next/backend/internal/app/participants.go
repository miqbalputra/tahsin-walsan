package app

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"net/http"
	"os"
	"strconv"
	"strings"
	"unicode/utf8"
)

type participantChild struct {
	ID    int64  `json:"id"`
	Name  string `json:"nama_anak"`
	Class string `json:"kelas"`
}

type participantRecord struct {
	ID               int64              `json:"id"`
	Name             string             `json:"nama_bapak"`
	Phone            string             `json:"no_hp"`
	Address          string             `json:"alamat"`
	Category         string             `json:"kategori"`
	TahsinPlace      string             `json:"tempat_tahsin"`
	OutsideTeacher   string             `json:"ustadz_luar"`
	ContinueTahsin   bool               `json:"lanjut_tahsin"`
	Active           bool               `json:"status_aktif"`
	FamilyManaged    bool               `json:"family_managed"`
	ChildrenEditable bool               `json:"children_editable"`
	GroupID          int64              `json:"halaqoh_id"`
	GroupName        string             `json:"nama_halaqoh"`
	TeacherName      string             `json:"nama_ustadz_halaqoh"`
	Children         []participantChild `json:"anak"`
}

type participantInput struct {
	Name           string             `json:"nama_bapak"`
	Phone          string             `json:"no_hp"`
	Address        string             `json:"alamat"`
	Category       string             `json:"kategori"`
	TahsinPlace    string             `json:"tempat_tahsin"`
	OutsideTeacher string             `json:"ustadz_luar"`
	ContinueTahsin bool               `json:"lanjut_tahsin"`
	GroupID        int64              `json:"halaqoh_id"`
	Children       []participantChild `json:"anak"`
}

func (s *Server) handleParticipants(w http.ResponseWriter, r *http.Request) {
	switch r.Method {
	case http.MethodGet:
		s.listParticipants(w, r)
	case http.MethodPost:
		s.saveParticipant(w, r, 0)
	default:
		methodNotAllowed(w, "GET, POST")
	}
}

func (s *Server) handleParticipant(w http.ResponseWriter, r *http.Request) {
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil || id < 1 {
		writeAPIError(w, http.StatusUnprocessableEntity, "ID peserta tidak valid.")
		return
	}
	switch r.Method {
	case http.MethodPatch:
		s.saveParticipant(w, r, id)
	case http.MethodDelete:
		s.archiveParticipant(w, r, id)
	default:
		methodNotAllowed(w, "PATCH, DELETE")
	}
}

func (s *Server) listParticipants(w http.ResponseWriter, r *http.Request) {
	page, limit := 1, 25
	var err error
	if raw := r.URL.Query().Get("page"); raw != "" {
		page, err = strconv.Atoi(raw)
		if err != nil || page < 1 || page > 100000 {
			writeAPIError(w, http.StatusUnprocessableEntity, "Halaman peserta tidak valid.")
			return
		}
	}
	if raw := r.URL.Query().Get("limit"); raw != "" {
		limit, err = strconv.Atoi(raw)
		if err != nil || limit < 1 || limit > 100 {
			writeAPIError(w, http.StatusUnprocessableEntity, "Batas peserta tidak valid.")
			return
		}
	}
	search := strings.TrimSpace(r.URL.Query().Get("search"))
	if utf8.RuneCountInString(search) > 100 || strings.ContainsRune(search, '\x00') {
		writeAPIError(w, http.StatusUnprocessableEntity, "Pencarian terlalu panjang.")
		return
	}
	scope := r.URL.Query().Get("scope")
	if scope == "" {
		scope = "active"
	}
	if scope != "active" && scope != "archived" && scope != "all" {
		writeAPIError(w, http.StatusUnprocessableEntity, "Filter status peserta tidak valid.")
		return
	}
	childTable, err := familyChildSource(r.Context(), s.DB)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Sumber data anak belum dapat diperiksa.")
		return
	}
	managedTable, err := tableExists(r.Context(), s.DB, "family_guardians")
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Pengelolaan keluarga belum dapat diperiksa.")
		return
	}
	where := []string{"1=1"}
	args := []any{}
	if scope == "active" {
		where = append(where, "w.status_aktif=1")
	} else if scope == "archived" {
		where = append(where, "w.status_aktif=0")
	}
	if search != "" {
		where = append(where, "(w.nama_bapak LIKE ? OR w.no_hp LIKE ? OR EXISTS(SELECT 1 FROM "+childTable+" csearch WHERE csearch.wali_santri_id=w.id AND csearch.nama_anak LIKE ?))")
		term := "%" + search + "%"
		args = append(args, term, term, term)
	}
	clause := strings.Join(where, " AND ")
	var total int64
	if err = s.DB.QueryRowContext(r.Context(), "SELECT COUNT(*) FROM wali_santri w WHERE "+clause, args...).Scan(&total); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Daftar peserta belum dapat dihitung.")
		return
	}
	identityLockedExpr := "0"
	if managedTable {
		identityLockedExpr = "EXISTS(SELECT 1 FROM family_guardians fg WHERE fg.wali_santri_id=w.id)"
	}
	if mapped, e := columnExists(r.Context(), s.DB, "wali_santri", "data_induk_guardian_id"); e != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Kunci identitas peserta belum dapat diperiksa.")
		return
	} else if mapped {
		identityLockedExpr = "(" + identityLockedExpr + " OR COALESCE(w.data_induk_guardian_id,'')<>'')"
	}
	continueSelect := "0"
	if exists, e := columnExists(r.Context(), s.DB, "wali_santri", "lanjut_tahsin"); e != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Pengaturan peserta belum dapat diperiksa.")
		return
	} else if exists {
		continueSelect = "COALESCE(w.lanjut_tahsin,0)"
	}
	query := "SELECT w.id,w.nama_bapak,COALESCE(w.no_hp,''),COALESCE(w.alamat,''),COALESCE(w.kategori,'reguler'),COALESCE(w.tempat_tahsin,''),COALESCE(w.ustadz_luar,'')," + continueSelect + ",COALESCE(w.status_aktif,0)," + identityLockedExpr + "," +
		"COALESCE((SELECT hm.halaqoh_id FROM halaqoh_members hm WHERE hm.wali_santri_id=w.id AND hm.archived_at IS NULL ORDER BY hm.id DESC LIMIT 1),0)," +
		"COALESCE((SELECT h.nama_halaqoh FROM halaqoh_members hm JOIN halaqoh h ON h.id=hm.halaqoh_id WHERE hm.wali_santri_id=w.id AND hm.archived_at IS NULL ORDER BY hm.id DESC LIMIT 1),'')," +
		"COALESCE((SELECT u.nama_lengkap FROM halaqoh_members hm JOIN halaqoh h ON h.id=hm.halaqoh_id JOIN users u ON u.id=h.ustadz_id WHERE hm.wali_santri_id=w.id AND hm.archived_at IS NULL ORDER BY hm.id DESC LIMIT 1),'') " +
		"FROM wali_santri w WHERE " + strings.Join(where, " AND ") + " ORDER BY w.nama_bapak,w.id LIMIT ? OFFSET ?"
	queryArgs := append(append([]any{}, args...), limit, (page-1)*limit)
	rows, err := s.DB.QueryContext(r.Context(), query, queryArgs...)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Daftar peserta belum dapat dibaca.")
		return
	}
	participants := make([]participantRecord, 0)
	for rows.Next() {
		var item participantRecord
		var active, managed, continues int
		if err = rows.Scan(&item.ID, &item.Name, &item.Phone, &item.Address, &item.Category, &item.TahsinPlace, &item.OutsideTeacher, &continues, &active, &managed, &item.GroupID, &item.GroupName, &item.TeacherName); err != nil {
			rows.Close()
			writeAPIError(w, http.StatusServiceUnavailable, "Daftar peserta belum dapat dibaca.")
			return
		}
		item.Active = active == 1
		item.FamilyManaged = managed == 1
		item.ContinueTahsin = continues == 1
		item.ChildrenEditable = childTable == "santri_detail" && !item.FamilyManaged
		item.Children = []participantChild{}
		participants = append(participants, item)
	}
	if err = rows.Err(); err != nil {
		rows.Close()
		writeAPIError(w, http.StatusServiceUnavailable, "Daftar peserta belum dapat dibaca.")
		return
	}
	rows.Close()
	if len(participants) > 0 {
		if err = s.loadParticipantChildren(r.Context(), participants, childTable); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Daftar anak belum dapat dibaca.")
			return
		}
	}
	groups, err := s.listGroupOptions(r.Context())
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Pilihan kelompok belum dapat dibaca.")
		return
	}
	dataIndukConfigured := dataIndukIdentityModeConfigured()
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"participants": participants, "groups": groups, "can_create": !dataIndukConfigured, "page": page, "limit": limit, "total": total}})
}

func (s *Server) loadParticipantChildren(ctx context.Context, participants []participantRecord, childTable string) error {
	marks, args := make([]string, 0, len(participants)), make([]any, 0, len(participants))
	byID := make(map[int64]int, len(participants))
	for i, item := range participants {
		marks = append(marks, "?")
		args = append(args, item.ID)
		byID[item.ID] = i
	}
	idExpr := "0"
	if childTable == "santri_detail" {
		idExpr = "c.id"
	}
	rows, err := s.DB.QueryContext(ctx, "SELECT c.wali_santri_id,"+idExpr+",c.nama_anak,COALESCE(c.kelas,'') FROM "+childTable+" c WHERE c.wali_santri_id IN ("+strings.Join(marks, ",")+") ORDER BY c.wali_santri_id,c.nama_anak", args...)
	if err != nil {
		return err
	}
	defer rows.Close()
	for rows.Next() {
		var waliID int64
		var child participantChild
		if err = rows.Scan(&waliID, &child.ID, &child.Name, &child.Class); err != nil {
			return err
		}
		if i, ok := byID[waliID]; ok {
			participants[i].Children = append(participants[i].Children, child)
		}
	}
	return rows.Err()
}

type participantGroup struct {
	ID      int64  `json:"id"`
	Name    string `json:"nama_halaqoh"`
	Program string `json:"program_code"`
}

func (s *Server) listGroupOptions(ctx context.Context) ([]participantGroup, error) {
	rows, err := s.DB.QueryContext(ctx, "SELECT id,nama_halaqoh,COALESCE(program_code,'bapak') FROM halaqoh ORDER BY nama_halaqoh,id")
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	groups := make([]participantGroup, 0)
	for rows.Next() {
		var group participantGroup
		if err = rows.Scan(&group.ID, &group.Name, &group.Program); err != nil {
			return nil, err
		}
		groups = append(groups, group)
	}
	return groups, rows.Err()
}

func (s *Server) saveParticipant(w http.ResponseWriter, r *http.Request, id int64) {
	var input participantInput
	if err := decodeSmallJSON(w, r, 64<<10, &input); err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, "Isian peserta tidak valid.")
		return
	}
	input, err := validateParticipantInput(input, id == 0)
	if err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, err.Error()+".")
		return
	}
	if id == 0 && dataIndukIdentityModeConfigured() {
		writeAPIError(w, http.StatusConflict, "Biodata baru dibuat di Data Induk. Sinkronkan Data Induk sebelum menambahkan peserta.")
		return
	}
	ctx := r.Context()
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Perubahan peserta belum dapat dimulai.")
		return
	}
	defer tx.Rollback()
	var waliID int64
	identityLocked := false
	if id > 0 {
		var mapped sql.NullString
		mappedColumn, e := columnExists(ctx, tx, "wali_santri", "data_induk_guardian_id")
		if e != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Identitas peserta belum dapat diperiksa.")
			return
		}
		selectMapping := "''"
		if mappedColumn {
			selectMapping = "data_induk_guardian_id"
		}
		if err = tx.QueryRowContext(ctx, "SELECT id,"+selectMapping+" FROM wali_santri WHERE id=? FOR UPDATE", id).Scan(&waliID, &mapped); errors.Is(err, sql.ErrNoRows) {
			writeAPIError(w, http.StatusNotFound, "Peserta tidak ditemukan.")
			return
		} else if err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Peserta belum dapat diperiksa.")
			return
		}
		managed, e := guardianIsManaged(ctx, tx, id)
		if e != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Kunci identitas keluarga belum dapat diperiksa.")
			return
		}
		identityLocked = managed || mapped.Valid && strings.TrimSpace(mapped.String) != ""
		if identityLocked {
			if e = updateParticipantLocalSettings(ctx, tx, id, input); e != nil {
				writeAPIError(w, http.StatusServiceUnavailable, "Pengaturan Tahsin peserta belum dapat disimpan.")
				return
			}
		} else {
			if e = updateParticipantIdentity(ctx, tx, id, input); e != nil {
				var validation *participantWriteError
				if errors.As(e, &validation) {
					writeAPIError(w, validation.Status, validation.Message)
				} else {
					writeAPIError(w, http.StatusServiceUnavailable, "Biodata peserta belum dapat diperbarui.")
				}
				return
			}
		}
	} else {
		var result sql.Result
		if hasContinue, e := columnExists(ctx, tx, "wali_santri", "lanjut_tahsin"); e != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Skema peserta belum dapat diperiksa.")
			return
		} else if hasContinue {
			result, err = tx.ExecContext(ctx, "INSERT INTO wali_santri(nama_bapak,no_hp,alamat,kategori,tempat_tahsin,ustadz_luar,lanjut_tahsin) VALUES(?,?,?,?,?,?,?)", input.Name, input.Phone, input.Address, input.Category, input.TahsinPlace, input.OutsideTeacher, input.ContinueTahsin)
		} else {
			result, err = tx.ExecContext(ctx, "INSERT INTO wali_santri(nama_bapak,no_hp,alamat,kategori,tempat_tahsin,ustadz_luar) VALUES(?,?,?,?,?,?)", input.Name, input.Phone, input.Address, input.Category, input.TahsinPlace, input.OutsideTeacher)
		}
		if err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Peserta belum dapat dibuat.")
			return
		}
		if waliID, err = result.LastInsertId(); err != nil || waliID < 1 {
			writeAPIError(w, http.StatusServiceUnavailable, "ID peserta baru belum dapat dibaca.")
			return
		}
		for _, child := range input.Children {
			if _, err = tx.ExecContext(ctx, "INSERT INTO santri_detail(wali_santri_id,nama_anak,kelas) VALUES(?,?,?)", waliID, child.Name, child.Class); err != nil {
				writeAPIError(w, http.StatusServiceUnavailable, "Data anak belum dapat disimpan. Tidak ada perubahan parsial yang diterapkan.")
				return
			}
		}
	}
	if err = setParticipantGroup(ctx, tx, waliID, input.GroupID); err != nil {
		var validation *participantWriteError
		if errors.As(err, &validation) {
			writeAPIError(w, validation.Status, validation.Message)
		} else {
			writeAPIError(w, http.StatusServiceUnavailable, "Kelompok peserta belum dapat diperbarui.")
		}
		return
	}
	archivedAsAlumni := false
	if archivedAsAlumni, err = archiveEligibleParticipant(ctx, tx, waliID); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Status alumni belum dapat diperiksa; transaksi peserta dibatalkan.")
		return
	}
	auth, _ := currentAuth(r)
	action := "UPDATE_PESERTA"
	if id == 0 {
		action = "CREATE_PESERTA"
	}
	description := fmt.Sprintf("wali_santri_id=%d; identitas_terkunci=%t", waliID, identityLocked)
	if _, err = tx.ExecContext(ctx, "INSERT INTO activity_logs(user_id,username,action,description,ip_address) VALUES(?,?,?,?,?)", auth.User.ID, auth.User.Username, action, description, clientIP(r)); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Log perubahan peserta belum dapat disimpan.")
		return
	}
	if err = tx.Commit(); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Perubahan peserta belum dapat diselesaikan.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"id": waliID, "archived_as_alumni": archivedAsAlumni}})
}

type participantWriteError struct {
	Status  int
	Message string
}

func (e *participantWriteError) Error() string { return e.Message }

func validateParticipantInput(input participantInput, creating bool) (participantInput, error) {
	input.Name = strings.TrimSpace(input.Name)
	input.Phone = strings.TrimSpace(input.Phone)
	input.Address = strings.TrimSpace(input.Address)
	input.TahsinPlace = strings.TrimSpace(input.TahsinPlace)
	input.OutsideTeacher = strings.TrimSpace(input.OutsideTeacher)
	if input.Name == "" || !validParticipantText(input.Name, 100, false) {
		return input, errors.New("nama orang tua wajib diisi, maksimal 100 karakter")
	}
	if !validParticipantText(input.Phone, 20, false) || !validParticipantText(input.Address, 10000, true) || !validParticipantText(input.TahsinPlace, 255, false) || !validParticipantText(input.OutsideTeacher, 100, false) {
		return input, errors.New("panjang atau format data peserta tidak valid")
	}
	if input.Category == "" {
		input.Category = "reguler"
	}
	if input.Category != "reguler" && input.Category != "tahsin_luar" && input.Category != "askar" {
		return input, errors.New("kategori peserta tidak valid")
	}
	if input.GroupID < 0 {
		return input, errors.New("kelompok peserta tidak valid")
	}
	if input.Children == nil {
		if creating {
			return input, errors.New("minimal satu data anak wajib diisi")
		}
		return input, nil
	}
	if len(input.Children) > 20 {
		return input, errors.New("maksimal 20 data anak per wali")
	}
	clean := make([]participantChild, 0, len(input.Children))
	seen := map[int64]bool{}
	for _, child := range input.Children {
		child.Name = strings.TrimSpace(child.Name)
		child.Class = strings.TrimSpace(child.Class)
		if child.ID < 0 || child.ID > 0 && seen[child.ID] {
			return input, errors.New("ID anak tidak valid atau ganda")
		}
		if child.Name == "" && child.Class == "" && child.ID == 0 {
			continue
		}
		if child.Name == "" || !validParticipantText(child.Name, 100, false) || !validParticipantText(child.Class, 20, false) {
			return input, errors.New("nama anak wajib diisi dan kelas maksimal 20 karakter")
		}
		if child.ID > 0 {
			seen[child.ID] = true
		}
		clean = append(clean, child)
	}
	if creating && len(clean) == 0 {
		return input, errors.New("minimal satu data anak wajib diisi")
	}
	input.Children = clean
	return input, nil
}

func validParticipantText(value string, limit int, multiline bool) bool {
	if !utf8.ValidString(value) || utf8.RuneCountInString(value) > limit {
		return false
	}
	for _, r := range value {
		if r == 0 || r == '\r' || r == '\n' || r == '\t' {
			if multiline && (r == '\n' || r == '\t') {
				continue
			}
			return false
		}
		if r < 0x20 || r == 0x7f {
			return false
		}
	}
	return true
}

func updateParticipantLocalSettings(ctx context.Context, tx *sql.Tx, id int64, input participantInput) error {
	hasContinue, err := columnExists(ctx, tx, "wali_santri", "lanjut_tahsin")
	if err != nil {
		return err
	}
	query := "UPDATE wali_santri SET kategori=?,tempat_tahsin=?,ustadz_luar=? WHERE id=?"
	args := []any{input.Category, input.TahsinPlace, input.OutsideTeacher, id}
	if hasContinue {
		query = "UPDATE wali_santri SET kategori=?,tempat_tahsin=?,ustadz_luar=?,lanjut_tahsin=? WHERE id=?"
		args = []any{input.Category, input.TahsinPlace, input.OutsideTeacher, input.ContinueTahsin, id}
	}
	_, err = tx.ExecContext(ctx, query, args...)
	return err
}

func updateParticipantIdentity(ctx context.Context, tx *sql.Tx, id int64, input participantInput) error {
	hasContinue, err := columnExists(ctx, tx, "wali_santri", "lanjut_tahsin")
	if err != nil {
		return err
	}
	query := "UPDATE wali_santri SET nama_bapak=?,no_hp=?,alamat=?,kategori=?,tempat_tahsin=?,ustadz_luar=? WHERE id=?"
	args := []any{input.Name, input.Phone, input.Address, input.Category, input.TahsinPlace, input.OutsideTeacher, id}
	if hasContinue {
		query = "UPDATE wali_santri SET nama_bapak=?,no_hp=?,alamat=?,kategori=?,tempat_tahsin=?,ustadz_luar=?,lanjut_tahsin=? WHERE id=?"
		args = []any{input.Name, input.Phone, input.Address, input.Category, input.TahsinPlace, input.OutsideTeacher, input.ContinueTahsin, id}
	}
	if _, err = tx.ExecContext(ctx, query, args...); err != nil {
		return err
	}
	if input.Children == nil {
		return nil
	}
	childTable, err := familyChildSource(ctx, tx)
	if err != nil {
		return err
	}
	if childTable != "santri_detail" {
		return &participantWriteError{Status: http.StatusConflict, Message: "Data anak dikelola oleh tampilan master keluarga. Perbarui anak melalui modul Keluarga."}
	}
	for _, child := range input.Children {
		if child.ID == 0 {
			if _, err = tx.ExecContext(ctx, "INSERT INTO santri_detail(wali_santri_id,nama_anak,kelas) VALUES(?,?,?)", id, child.Name, child.Class); err != nil {
				return err
			}
			continue
		}
		var exists int
		if err = tx.QueryRowContext(ctx, "SELECT COUNT(*) FROM santri_detail WHERE id=? AND wali_santri_id=?", child.ID, id).Scan(&exists); err != nil {
			return err
		}
		if exists != 1 {
			return &participantWriteError{Status: http.StatusConflict, Message: "Data anak berubah sejak formulir dibuka. Muat ulang sebelum menyimpan."}
		}
		mappedColumn, e := columnExists(ctx, tx, "santri_detail", "data_induk_student_id")
		if e != nil {
			return e
		}
		if mappedColumn {
			var mapped sql.NullString
			if err = tx.QueryRowContext(ctx, "SELECT data_induk_student_id FROM santri_detail WHERE id=? AND wali_santri_id=? FOR UPDATE", child.ID, id).Scan(&mapped); err != nil {
				return err
			}
			if mapped.Valid && strings.TrimSpace(mapped.String) != "" {
				return &participantWriteError{Status: http.StatusConflict, Message: "Identitas anak ini dikelola Data Induk dan tidak dapat diubah dari Presensi."}
			}
		}
		if _, err = tx.ExecContext(ctx, "UPDATE santri_detail SET nama_anak=?,kelas=? WHERE id=? AND wali_santri_id=?", child.Name, child.Class, child.ID, id); err != nil {
			return err
		}
	}
	return nil
}

func setParticipantGroup(ctx context.Context, tx *sql.Tx, waliID, groupID int64) error {
	if groupID > 0 {
		var active int
		if err := tx.QueryRowContext(ctx, "SELECT status_aktif FROM wali_santri WHERE id=?", waliID).Scan(&active); err != nil {
			return err
		}
		var groupExists int
		if err := tx.QueryRowContext(ctx, "SELECT COUNT(*) FROM halaqoh WHERE id=?", groupID).Scan(&groupExists); err != nil {
			return err
		}
		if groupExists != 1 {
			return &participantWriteError{Status: http.StatusUnprocessableEntity, Message: "Kelompok yang dipilih tidak ditemukan."}
		}
		if active != 1 {
			return &participantWriteError{Status: http.StatusConflict, Message: "Peserta yang sudah diarsipkan tidak dapat ditempatkan ke kelompok aktif."}
		}
		rows, err := tx.QueryContext(ctx, "SELECT id FROM halaqoh_members WHERE wali_santri_id=? AND archived_at IS NULL FOR UPDATE", waliID)
		if err != nil {
			return err
		}
		for rows.Next() {
			var membershipID int64
			if err = rows.Scan(&membershipID); err != nil {
				rows.Close()
				return err
			}
		}
		if err = rows.Err(); err != nil {
			rows.Close()
			return err
		}
		rows.Close()
		if _, err = tx.ExecContext(ctx, "UPDATE halaqoh_members SET archived_at=CURRENT_TIMESTAMP,archive_reason='MANUAL_REASSIGN' WHERE wali_santri_id=? AND archived_at IS NULL AND halaqoh_id<>?", waliID, groupID); err != nil {
			return err
		}
		var membershipID int64
		err = tx.QueryRowContext(ctx, "SELECT id FROM halaqoh_members WHERE halaqoh_id=? AND wali_santri_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE", groupID, waliID).Scan(&membershipID)
		if err == nil {
			_, err = tx.ExecContext(ctx, "UPDATE halaqoh_members SET archived_at=NULL,archive_reason=NULL WHERE id=?", membershipID)
		} else if errors.Is(err, sql.ErrNoRows) {
			_, err = tx.ExecContext(ctx, "INSERT INTO halaqoh_members(halaqoh_id,wali_santri_id) VALUES(?,?)", groupID, waliID)
		}
		return err
	}
	_, err := tx.ExecContext(ctx, "UPDATE halaqoh_members SET archived_at=CURRENT_TIMESTAMP,archive_reason='MANUAL_REASSIGN' WHERE wali_santri_id=? AND archived_at IS NULL", waliID)
	return err
}

func archiveEligibleParticipant(ctx context.Context, tx *sql.Tx, waliID int64) (bool, error) {
	childTable, err := familyChildSource(ctx, tx)
	if err != nil {
		return false, err
	}
	otherThanGraduated := "LOWER(TRIM(COALESCE(kelas,'')))<>'lulus'"
	if childTable == "family_children" {
		otherThanGraduated = "school_status<>'graduated'"
	}
	var eligible int
	err = tx.QueryRowContext(ctx, "SELECT CASE WHEN COUNT(*)>0 AND SUM(CASE WHEN "+otherThanGraduated+" THEN 1 ELSE 0 END)=0 THEN 1 ELSE 0 END FROM "+childTable+" WHERE wali_santri_id=?", waliID).Scan(&eligible)
	if err != nil {
		return false, err
	}
	if eligible != 1 {
		return false, nil
	}
	result, err := tx.ExecContext(ctx, "UPDATE wali_santri SET status_aktif=0 WHERE id=? AND status_aktif=1", waliID)
	if err != nil {
		return false, err
	}
	changed, err := result.RowsAffected()
	if err != nil {
		return false, err
	}
	_, err = tx.ExecContext(ctx, "UPDATE halaqoh_members SET archived_at=CURRENT_TIMESTAMP,archive_reason='AUTO_LULUS' WHERE wali_santri_id=? AND archived_at IS NULL", waliID)
	return changed > 0, err
}

func (s *Server) archiveParticipant(w http.ResponseWriter, r *http.Request, id int64) {
	tx, err := s.DB.BeginTx(r.Context(), nil)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Arsip peserta belum dapat dimulai.")
		return
	}
	defer tx.Rollback()
	var lockedID int64
	if err = tx.QueryRowContext(r.Context(), "SELECT id FROM wali_santri WHERE id=? FOR UPDATE", id).Scan(&lockedID); errors.Is(err, sql.ErrNoRows) {
		writeAPIError(w, http.StatusNotFound, "Peserta tidak ditemukan.")
		return
	} else if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Peserta belum dapat diperiksa.")
		return
	}
	mappedColumn, err := columnExists(r.Context(), tx, "wali_santri", "data_induk_guardian_id")
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Identitas peserta belum dapat diperiksa.")
		return
	}
	if mappedColumn {
		var mapped sql.NullString
		if err = tx.QueryRowContext(r.Context(), "SELECT data_induk_guardian_id FROM wali_santri WHERE id=?", id).Scan(&mapped); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Identitas peserta belum dapat diperiksa.")
			return
		}
		if mapped.Valid && strings.TrimSpace(mapped.String) != "" {
			writeAPIError(w, http.StatusConflict, "Arsip identitas terhubung harus dilakukan melalui Data Induk.")
			return
		}
	}
	if managed, e := guardianIsManaged(r.Context(), tx, id); e != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Kunci keluarga belum dapat diperiksa.")
		return
	} else if managed {
		writeAPIError(w, http.StatusConflict, "Arsip identitas keluarga dilakukan di master keluarga/Data Induk.")
		return
	}
	if _, err = tx.ExecContext(r.Context(), "UPDATE wali_santri SET status_aktif=0 WHERE id=?", id); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Peserta belum dapat diarsipkan.")
		return
	}
	if _, err = tx.ExecContext(r.Context(), "UPDATE halaqoh_members SET archived_at=CURRENT_TIMESTAMP,archive_reason='MANUAL_ARCHIVE' WHERE wali_santri_id=? AND archived_at IS NULL", id); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Keanggotaan kelompok belum dapat diarsipkan.")
		return
	}
	auth, _ := currentAuth(r)
	description := fmt.Sprintf("wali_santri_id=%d; arsip lunak, histori dipertahankan", id)
	if _, err = tx.ExecContext(r.Context(), "INSERT INTO activity_logs(user_id,username,action,description,ip_address) VALUES(?,?,?,?,?)", auth.User.ID, auth.User.Username, "ARCHIVE_PESERTA", description, clientIP(r)); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Log arsip peserta belum dapat disimpan.")
		return
	}
	if err = tx.Commit(); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Arsip peserta belum dapat diselesaikan.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"id": id, "archived": true, "history_preserved": true}})
}

func tableExists(ctx context.Context, db interface {
	QueryRowContext(context.Context, string, ...any) *sql.Row
}, name string) (bool, error) {
	var count int
	err := db.QueryRowContext(ctx, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?", name).Scan(&count)
	return count == 1, err
}

func columnExists(ctx context.Context, db interface {
	QueryRowContext(context.Context, string, ...any) *sql.Row
}, table, column string) (bool, error) {
	var count int
	err := db.QueryRowContext(ctx, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?", table, column).Scan(&count)
	return count == 1, err
}

func guardianIsManaged(ctx context.Context, db interface {
	QueryRowContext(context.Context, string, ...any) *sql.Row
}, waliID int64) (bool, error) {
	exists, err := tableExists(ctx, db, "family_guardians")
	if err != nil || !exists {
		return false, err
	}
	var count int
	err = db.QueryRowContext(ctx, "SELECT COUNT(*) FROM family_guardians WHERE wali_santri_id=?", waliID).Scan(&count)
	return count > 0, err
}

func dataIndukIdentityModeConfigured() bool {
	enabled := strings.EqualFold(strings.TrimSpace(os.Getenv("DATA_INDUK_ENABLED")), "true") || strings.TrimSpace(os.Getenv("DATA_INDUK_ENABLED")) == "1"
	return enabled && strings.TrimSpace(os.Getenv("DATA_INDUK_BASE_URL")) != "" && strings.TrimSpace(os.Getenv("DATA_INDUK_API_KEY")) != "" && strings.TrimSpace(os.Getenv("DATA_INDUK_UNIT_ID")) != ""
}
