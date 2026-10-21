package app

import (
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/go-sql-driver/mysql"
	"golang.org/x/crypto/bcrypt"
)

type managedUser struct {
	ID             int64  `json:"id"`
	Username       string `json:"username"`
	Name           string `json:"nama_lengkap"`
	Role           string `json:"role"`
	Phone          string `json:"no_hp"`
	IdentityLocked bool   `json:"identity_locked"`
}

type userInput struct {
	Username string `json:"username"`
	Name     string `json:"nama_lengkap"`
	Role     string `json:"role"`
	Phone    string `json:"no_hp"`
	Password string `json:"password"`
}

func (s *Server) handleUsers(w http.ResponseWriter, r *http.Request) {
	switch r.Method {
	case http.MethodGet:
		s.listUsers(w, r)
	case http.MethodPost:
		s.saveUser(w, r, 0)
	default:
		methodNotAllowed(w, "GET, POST")
	}
}

func (s *Server) handleUser(w http.ResponseWriter, r *http.Request) {
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil || id < 1 {
		writeAPIError(w, http.StatusUnprocessableEntity, "ID user tidak valid.")
		return
	}
	if r.Method != http.MethodPatch {
		methodNotAllowed(w, "PATCH")
		return
	}
	s.saveUser(w, r, id)
}

func (s *Server) listUsers(w http.ResponseWriter, r *http.Request) {
	mappingColumn, err := columnExists(r.Context(), s.DB, "users", "data_induk_teacher_id")
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Status identitas guru belum dapat diperiksa.")
		return
	}
	mapping := "''"
	if mappingColumn {
		mapping = "data_induk_teacher_id"
	}
	rows, err := s.DB.QueryContext(r.Context(), "SELECT id,username,nama_lengkap,role,COALESCE(no_hp,''),COALESCE("+mapping+",'') FROM users ORDER BY role,nama_lengkap,id")
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Daftar user belum dapat dibaca.")
		return
	}
	defer rows.Close()
	users := make([]managedUser, 0)
	for rows.Next() {
		var item managedUser
		var mapped string
		if err = rows.Scan(&item.ID, &item.Username, &item.Name, &item.Role, &item.Phone, &mapped); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Daftar user belum dapat dibaca.")
			return
		}
		item.IdentityLocked = strings.TrimSpace(mapped) != ""
		users = append(users, item)
	}
	if err = rows.Err(); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Daftar user belum dapat dibaca.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": users})
}

func (s *Server) saveUser(w http.ResponseWriter, r *http.Request, id int64) {
	r.Body = http.MaxBytesReader(w, r.Body, 16<<10)
	decoder := json.NewDecoder(r.Body)
	decoder.DisallowUnknownFields()
	var input userInput
	if err := decoder.Decode(&input); err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, "Isian user tidak valid.")
		return
	}
	var trailing any
	if err := decoder.Decode(&trailing); !errors.Is(err, io.EOF) {
		writeAPIError(w, http.StatusUnprocessableEntity, "Permintaan user harus berisi satu objek JSON.")
		return
	}
	input, err := validateUserInput(input, id == 0)
	if err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, err.Error()+".")
		return
	}
	var passwordHash string
	if input.Password != "" {
		hash, hashErr := bcrypt.GenerateFromPassword([]byte(input.Password), bcrypt.DefaultCost)
		if hashErr != nil {
			writeAPIError(w, http.StatusUnprocessableEntity, "Password belum dapat diproses.")
			return
		}
		passwordHash = string(hash)
	}
	tx, err := s.DB.BeginTx(r.Context(), nil)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Perubahan user belum dapat dimulai.")
		return
	}
	defer tx.Rollback()
	auth, _ := currentAuth(r)
	action := "CREATE_USER"
	var userID int64
	if id == 0 {
		result, e := tx.ExecContext(r.Context(), "INSERT INTO users(username,password,nama_lengkap,role,no_hp) VALUES(?,?,?,?,?)", input.Username, passwordHash, input.Name, input.Role, input.Phone)
		if e != nil {
			writeUserWriteError(w, e)
			return
		}
		if userID, err = result.LastInsertId(); err != nil || userID < 1 {
			writeAPIError(w, http.StatusServiceUnavailable, "ID user belum dapat dibaca.")
			return
		}
	} else {
		action = "UPDATE_USER"
		var oldRole string
		var mapped sql.NullString
		mappingColumn, e := columnExists(r.Context(), tx, "users", "data_induk_teacher_id")
		if e != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Status identitas guru belum dapat diperiksa.")
			return
		}
		mappingSelect := "''"
		if mappingColumn {
			mappingSelect = "data_induk_teacher_id"
		}
		if err = tx.QueryRowContext(r.Context(), "SELECT id,role,"+mappingSelect+" FROM users WHERE id=? FOR UPDATE", id).Scan(&userID, &oldRole, &mapped); errors.Is(err, sql.ErrNoRows) {
			writeAPIError(w, http.StatusNotFound, "User tidak ditemukan.")
			return
		} else if err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "User belum dapat diperiksa.")
			return
		}
		if oldRole == "admin" && input.Role != "admin" {
			adminRows, lockErr := tx.QueryContext(r.Context(), "SELECT id FROM users WHERE role='admin' ORDER BY id FOR UPDATE")
			if lockErr != nil {
				writeAPIError(w, http.StatusServiceUnavailable, "Role administrator belum dapat diperiksa.")
				return
			}
			admins := 0
			for adminRows.Next() {
				var adminID int64
				if err = adminRows.Scan(&adminID); err != nil {
					adminRows.Close()
					writeAPIError(w, http.StatusServiceUnavailable, "Role administrator belum dapat diperiksa.")
					return
				}
				admins++
			}
			if err = adminRows.Err(); err != nil {
				adminRows.Close()
				writeAPIError(w, http.StatusServiceUnavailable, "Role administrator belum dapat diperiksa.")
				return
			}
			adminRows.Close()
			if admins <= 1 {
				writeAPIError(w, http.StatusConflict, "Role administrator terakhir tidak dapat diturunkan.")
				return
			}
		}
		identityLocked := mapped.Valid && strings.TrimSpace(mapped.String) != ""
		query := "UPDATE users SET username=?,role=? WHERE id=?"
		args := []any{input.Username, input.Role, id}
		if !identityLocked {
			query = "UPDATE users SET username=?,nama_lengkap=?,role=?,no_hp=? WHERE id=?"
			args = []any{input.Username, input.Name, input.Role, input.Phone, id}
		}
		if passwordHash != "" {
			query = strings.Replace(query, " SET ", " SET password=?,", 1)
			args = append([]any{passwordHash}, args...)
		}
		if _, err = tx.ExecContext(r.Context(), query, args...); err != nil {
			writeUserWriteError(w, err)
			return
		}
	}
	description := fmt.Sprintf("user_id=%d; role=%s", userID, input.Role)
	if _, err = tx.ExecContext(r.Context(), "INSERT INTO activity_logs(user_id,username,action,description,ip_address) VALUES(?,?,?,?,?)", auth.User.ID, auth.User.Username, action, description, clientIP(r)); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Log perubahan user belum dapat disimpan.")
		return
	}
	if err = tx.Commit(); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Perubahan user belum dapat diselesaikan.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"id": userID}})
}

func validateUserInput(input userInput, creating bool) (userInput, error) {
	input.Username = strings.TrimSpace(input.Username)
	input.Name = strings.TrimSpace(input.Name)
	input.Phone = strings.TrimSpace(input.Phone)
	if input.Username == "" || len(input.Username) > 50 || !validParticipantText(input.Username, 50, false) {
		return input, errors.New("username wajib diisi, maksimal 50 karakter")
	}
	if !validRole(input.Role) {
		return input, errors.New("role user tidak valid")
	}
	if !validParticipantText(input.Phone, 20, false) {
		return input, errors.New("nomor HP maksimal 20 karakter")
	}
	if input.Name == "" || !validParticipantText(input.Name, 100, false) {
		return input, errors.New("nama lengkap wajib diisi, maksimal 100 karakter")
	}
	if creating && input.Password == "" {
		return input, errors.New("password wajib diisi untuk user baru")
	}
	if input.Password != "" && (len(input.Password) < 8 || len(input.Password) > 72) {
		return input, errors.New("password harus berisi 8 sampai 72 byte")
	}
	if !utf8.ValidString(input.Password) {
		return input, errors.New("password harus berupa teks UTF-8 yang valid")
	}
	return input, nil
}

func writeUserWriteError(w http.ResponseWriter, err error) {
	var mysqlErr *mysql.MySQLError
	if errors.As(err, &mysqlErr) && mysqlErr.Number == 1062 {
		writeAPIError(w, http.StatusConflict, "Username sudah digunakan. Pilih username lain.")
		return
	}
	writeAPIError(w, http.StatusServiceUnavailable, "Perubahan user belum dapat disimpan.")
}
