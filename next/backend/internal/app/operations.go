package app

import (
	"context"
	"database/sql"
	"errors"
	"net/http"
	"strconv"
	"strings"
	"time"
)

type holidayRecord struct {
	ID          int64  `json:"id"`
	Date        string `json:"tanggal"`
	Description string `json:"keterangan"`
}

type announcementRecord struct {
	ID        int64  `json:"id"`
	Title     string `json:"judul"`
	Body      string `json:"isi"`
	Active    bool   `json:"is_aktif"`
	CreatedAt string `json:"created_at"`
}

func (s *Server) handleHolidays(w http.ResponseWriter, r *http.Request) {
	switch r.Method {
	case http.MethodGet:
		rows, err := s.DB.QueryContext(r.Context(), "SELECT id,DATE_FORMAT(tanggal,'%Y-%m-%d'),COALESCE(keterangan,'') FROM holidays ORDER BY tanggal DESC,id DESC LIMIT 1000")
		if err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Daftar hari libur belum dapat dibaca.")
			return
		}
		defer rows.Close()
		items := make([]holidayRecord, 0)
		for rows.Next() {
			var item holidayRecord
			if err = rows.Scan(&item.ID, &item.Date, &item.Description); err != nil {
				writeAPIError(w, http.StatusServiceUnavailable, "Daftar hari libur belum dapat dibaca.")
				return
			}
			items = append(items, item)
		}
		if err = rows.Err(); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Daftar hari libur belum dapat dibaca.")
			return
		}
		writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": items})
	case http.MethodPost:
		var input holidayRecord
		if err := decodeSmallJSON(w, r, 8<<10, &input); err != nil {
			writeAPIError(w, http.StatusUnprocessableEntity, "Isian hari libur tidak valid.")
			return
		}
		input, err := validateHolidayInput(input)
		if err != nil {
			writeAPIError(w, http.StatusUnprocessableEntity, err.Error()+".")
			return
		}
		tx, err := s.DB.BeginTx(r.Context(), nil)
		if err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Hari libur belum dapat disimpan.")
			return
		}
		defer tx.Rollback()
		if _, err = tx.ExecContext(r.Context(), "INSERT INTO holidays(tanggal,keterangan) VALUES(?,?) ON DUPLICATE KEY UPDATE keterangan=VALUES(keterangan)", input.Date, input.Description); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Hari libur belum dapat disimpan.")
			return
		}
		auth, _ := currentAuth(r)
		if err = logOperation(r.Context(), tx, auth.User.ID, auth.User.Username, "ADD_HOLIDAY", "tanggal="+input.Date, clientIP(r)); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Log hari libur belum dapat disimpan.")
			return
		}
		if err = tx.Commit(); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Hari libur belum dapat diselesaikan.")
			return
		}
		writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": input})
	default:
		methodNotAllowed(w, "GET, POST")
	}
}

func (s *Server) handleHoliday(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodDelete {
		methodNotAllowed(w, http.MethodDelete)
		return
	}
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil || id < 1 {
		writeAPIError(w, http.StatusUnprocessableEntity, "ID hari libur tidak valid.")
		return
	}
	tx, err := s.DB.BeginTx(r.Context(), nil)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Hari libur belum dapat diubah.")
		return
	}
	defer tx.Rollback()
	var date string
	err = tx.QueryRowContext(r.Context(), "SELECT DATE_FORMAT(tanggal,'%Y-%m-%d') FROM holidays WHERE id=? FOR UPDATE", id).Scan(&date)
	if errors.Is(err, sql.ErrNoRows) {
		writeAPIError(w, http.StatusNotFound, "Hari libur tidak ditemukan.")
		return
	} else if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Hari libur belum dapat dibaca.")
		return
	}
	if _, err = tx.ExecContext(r.Context(), "DELETE FROM holidays WHERE id=?", id); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Hari libur belum dapat dihapus.")
		return
	}
	auth, _ := currentAuth(r)
	if err = logOperation(r.Context(), tx, auth.User.ID, auth.User.Username, "DELETE_HOLIDAY", "tanggal="+date, clientIP(r)); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Log perubahan hari libur belum dapat disimpan.")
		return
	}
	if err = tx.Commit(); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Perubahan hari libur belum dapat diselesaikan.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"id": id}})
}

func validateHolidayInput(item holidayRecord) (holidayRecord, error) {
	date, err := time.Parse("2006-01-02", strings.TrimSpace(item.Date))
	if err != nil || date.Format("2006-01-02") != strings.TrimSpace(item.Date) {
		return item, errors.New("tanggal libur tidak valid")
	}
	item.Date = date.Format("2006-01-02")
	item.Description = strings.TrimSpace(item.Description)
	if !validParticipantText(item.Description, 255, false) {
		return item, errors.New("keterangan maksimal 255 karakter")
	}
	return item, nil
}

func (s *Server) handleAnnouncements(w http.ResponseWriter, r *http.Request) {
	switch r.Method {
	case http.MethodGet:
		rows, err := s.DB.QueryContext(r.Context(), "SELECT id,COALESCE(judul,''),isi,is_aktif,DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s') FROM pengumuman ORDER BY created_at DESC,id DESC LIMIT 200")
		if err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Riwayat pengumuman belum dapat dibaca.")
			return
		}
		defer rows.Close()
		items := make([]announcementRecord, 0)
		for rows.Next() {
			var item announcementRecord
			var active int
			if err = rows.Scan(&item.ID, &item.Title, &item.Body, &active, &item.CreatedAt); err != nil {
				writeAPIError(w, http.StatusServiceUnavailable, "Riwayat pengumuman belum dapat dibaca.")
				return
			}
			item.Active = active == 1
			items = append(items, item)
		}
		if err = rows.Err(); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Riwayat pengumuman belum dapat dibaca.")
			return
		}
		writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": items})
	case http.MethodPost:
		var input announcementRecord
		if err := decodeSmallJSON(w, r, 32<<10, &input); err != nil {
			writeAPIError(w, http.StatusUnprocessableEntity, "Isian pengumuman tidak valid.")
			return
		}
		input, err := validateAnnouncementInput(input)
		if err != nil {
			writeAPIError(w, http.StatusUnprocessableEntity, err.Error()+".")
			return
		}
		tx, err := s.DB.BeginTx(r.Context(), nil)
		if err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Pengumuman belum dapat dibuat.")
			return
		}
		defer tx.Rollback()
		result, err := tx.ExecContext(r.Context(), "INSERT INTO pengumuman(judul,isi,is_aktif) VALUES(?,?,1)", input.Title, input.Body)
		if err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Pengumuman belum dapat dibuat.")
			return
		}
		id, err := result.LastInsertId()
		if err != nil || id < 1 {
			writeAPIError(w, http.StatusServiceUnavailable, "ID pengumuman belum dapat dibaca.")
			return
		}
		auth, _ := currentAuth(r)
		if err = logOperation(r.Context(), tx, auth.User.ID, auth.User.Username, "CREATE_ANNOUNCEMENT", "id="+strconv.FormatInt(id, 10), clientIP(r)); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Log pengumuman belum dapat disimpan.")
			return
		}
		if err = tx.Commit(); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Pengumuman belum dapat diselesaikan.")
			return
		}
		writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"id": id}})
	default:
		methodNotAllowed(w, "GET, POST")
	}
}

func (s *Server) handleActiveAnnouncement(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		methodNotAllowed(w, http.MethodGet)
		return
	}
	var item announcementRecord
	var active int
	err := s.DB.QueryRowContext(r.Context(), "SELECT id,COALESCE(judul,''),isi,is_aktif,DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s') FROM pengumuman WHERE is_aktif=1 ORDER BY created_at DESC,id DESC LIMIT 1").Scan(&item.ID, &item.Title, &item.Body, &active, &item.CreatedAt)
	if errors.Is(err, sql.ErrNoRows) {
		writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": nil})
		return
	} else if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Pengumuman aktif belum dapat dibaca.")
		return
	}
	item.Active = active == 1
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": item})
}

func (s *Server) handleAnnouncement(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPatch {
		methodNotAllowed(w, http.MethodPatch)
		return
	}
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil || id < 1 {
		writeAPIError(w, http.StatusUnprocessableEntity, "ID pengumuman tidak valid.")
		return
	}
	var input struct {
		Active bool `json:"is_aktif"`
	}
	if err = decodeSmallJSON(w, r, 8<<10, &input); err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, "Status pengumuman tidak valid.")
		return
	}
	tx, err := s.DB.BeginTx(r.Context(), nil)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Status pengumuman belum dapat diubah.")
		return
	}
	defer tx.Rollback()
	var lockedID int64
	if err = tx.QueryRowContext(r.Context(), "SELECT id FROM pengumuman WHERE id=? FOR UPDATE", id).Scan(&lockedID); errors.Is(err, sql.ErrNoRows) {
		writeAPIError(w, http.StatusNotFound, "Pengumuman tidak ditemukan.")
		return
	} else if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Pengumuman belum dapat diperiksa.")
		return
	}
	if _, err = tx.ExecContext(r.Context(), "UPDATE pengumuman SET is_aktif=? WHERE id=?", input.Active, id); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Status pengumuman belum dapat diubah.")
		return
	}
	auth, _ := currentAuth(r)
	action := "ARCHIVE_ANNOUNCEMENT"
	if input.Active {
		action = "ACTIVATE_ANNOUNCEMENT"
	}
	if err = logOperation(r.Context(), tx, auth.User.ID, auth.User.Username, action, "id="+strconv.FormatInt(id, 10), clientIP(r)); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Log status pengumuman belum dapat disimpan.")
		return
	}
	if err = tx.Commit(); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Status pengumuman belum dapat diselesaikan.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"id": id, "is_aktif": input.Active}})
}

func validateAnnouncementInput(item announcementRecord) (announcementRecord, error) {
	item.Title = strings.TrimSpace(item.Title)
	item.Body = strings.TrimSpace(item.Body)
	if item.Title == "" || !validParticipantText(item.Title, 200, false) {
		return item, errors.New("judul wajib diisi, maksimal 200 karakter")
	}
	if item.Body == "" || !validParticipantText(item.Body, 10000, true) {
		return item, errors.New("isi pengumuman wajib diisi, maksimal 10000 karakter")
	}
	return item, nil
}

func logOperation(ctx context.Context, tx *sql.Tx, userID int64, username, action, description, ip string) error {
	_, err := tx.ExecContext(ctx, "INSERT INTO activity_logs(user_id,username,action,description,ip_address) VALUES(?,?,?,?,?)", userID, username, action, description, ip)
	return err
}
