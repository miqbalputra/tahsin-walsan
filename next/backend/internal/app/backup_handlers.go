package app

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"time"
)

const restorePreviewTTL = 20 * time.Minute

var backupFilename = regexp.MustCompile(`^(backup_[A-Za-z0-9_-]+|backup_pre_restore_[A-Za-z0-9_-]+|restore_[A-Za-z0-9_-]+)\.zip$`)

type restorePreviewTicket struct {
	UserID    int64     `json:"user_id"`
	CreatedAt time.Time `json:"created_at"`
}

type backupFileInfo struct {
	Name      string    `json:"name"`
	Size      int64     `json:"size"`
	CreatedAt time.Time `json:"created_at"`
	Kind      string    `json:"kind"`
}

func maxBackupExpandedBytes(uploadMB int64) int64 {
	const ceiling = int64(2 << 30)
	if uploadMB < 1 {
		return ceiling
	}
	limit := uploadMB * 4 * (1 << 20)
	if limit > ceiling {
		return ceiling
	}
	return limit
}

func (s *Server) handleBackups(w http.ResponseWriter, r *http.Request) {
	switch r.Method {
	case http.MethodGet:
		if name := r.URL.Query().Get("download"); name != "" {
			s.serveBackupDownload(w, r, name)
			return
		}
		dir, err := backupDir(s.Config)
		if err != nil {
			writeAPIError(w, http.StatusInternalServerError, "Direktori backup belum siap.")
			return
		}
		_ = cleanupExpiredRestoreTickets(r.Context(), dir)
		files, err := listBackupFiles(dir)
		if err != nil {
			writeAPIError(w, http.StatusInternalServerError, "Daftar backup belum dapat dibaca.")
			return
		}
		writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": files})
	case http.MethodPost:
		s.createBackup(w, r)
	default:
		methodNotAllowed(w, "GET, POST")
	}
}

func (s *Server) createBackup(w http.ResponseWriter, r *http.Request) {
	if s.DB == nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Database belum tersedia.")
		return
	}
	dir, err := backupDir(s.Config)
	if err != nil {
		writeAPIError(w, http.StatusInternalServerError, "Direktori backup belum siap.")
		return
	}
	name, path, err := newBackupPath(dir, "backup_")
	if err != nil {
		writeAPIError(w, http.StatusInternalServerError, "Nama backup belum dapat dibuat.")
		return
	}
	manifest, err := createBackupArchive(r.Context(), s.DB, path)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Backup database belum berhasil dibuat.")
		return
	}
	auth, _ := currentAuth(r)
	_ = s.activity(r.Context(), &auth.User.ID, auth.User.Username, "BACKUP_EXPORT", "Membuat backup database: "+name, clientIP(r))
	info, _ := os.Stat(path)
	size := int64(0)
	if info != nil {
		size = info.Size()
	}
	writeJSON(w, http.StatusCreated, map[string]any{"status": "ok", "data": map[string]any{"name": name, "size": size, "generated_at": manifest.Generated, "tables": len(manifest.Tables)}})
}

func (s *Server) handleBackupRestorePreview(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		methodNotAllowed(w, http.MethodPost)
		return
	}
	if s.DB == nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Database belum tersedia.")
		return
	}
	maxBytes := s.Config.MaxUploadMB * (1 << 20)
	r.Body = http.MaxBytesReader(w, r.Body, maxBytes+(1<<20))
	if err := r.ParseMultipartForm(8 << 20); err != nil {
		writeAPIError(w, http.StatusRequestEntityTooLarge, "Upload backup gagal atau melewati batas ukuran.")
		return
	}
	if r.MultipartForm != nil {
		defer r.MultipartForm.RemoveAll()
	}
	file, header, err := r.FormFile("backup_file")
	if err != nil {
		file, header, err = r.FormFile("file")
	}
	if err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, "Pilih file ZIP backup terlebih dahulu.")
		return
	}
	defer file.Close()
	if strings.ToLower(filepath.Ext(header.Filename)) != ".zip" {
		writeAPIError(w, http.StatusUnprocessableEntity, "File restore harus berformat ZIP.")
		return
	}
	dir, err := backupDir(s.Config)
	if err != nil {
		writeAPIError(w, http.StatusInternalServerError, "Direktori backup belum siap.")
		return
	}
	token, err := randomToken()
	if err != nil {
		writeAPIError(w, http.StatusInternalServerError, "Token preview belum dapat dibuat.")
		return
	}
	name := "restore_" + token + ".zip"
	path := filepath.Join(dir, name)
	destination, err := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0600)
	if err != nil {
		writeAPIError(w, http.StatusInternalServerError, "File restore belum dapat disimpan.")
		return
	}
	keep := false
	defer func() {
		_ = destination.Close()
		if !keep {
			_ = os.Remove(path)
		}
	}()
	written, err := io.Copy(destination, io.LimitReader(file, maxBytes+1))
	if err != nil || written == 0 || written > maxBytes {
		writeAPIError(w, http.StatusRequestEntityTooLarge, "File ZIP kosong atau melebihi batas ukuran.")
		return
	}
	if err := destination.Sync(); err != nil {
		writeAPIError(w, http.StatusInternalServerError, "File restore belum tersimpan dengan aman.")
		return
	}
	if err := destination.Close(); err != nil {
		writeAPIError(w, http.StatusInternalServerError, "File restore belum tersimpan dengan aman.")
		return
	}
	preview, err := analyzeBackupArchive(r.Context(), s.DB, path, maxBackupExpandedBytes(s.Config.MaxUploadMB))
	if err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, err.Error())
		return
	}
	if !preview.CanRestore {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]any{"status": "error", "message": "Backup tidak memenuhi syarat merge restore; data belum diubah.", "data": preview})
		return
	}
	auth, _ := currentAuth(r)
	ticket := restorePreviewTicket{UserID: auth.User.ID, CreatedAt: time.Now().UTC()}
	metadata, err := json.Marshal(ticket)
	if err != nil {
		writeAPIError(w, http.StatusInternalServerError, "Token preview belum dapat disimpan.")
		return
	}
	metaPath := filepath.Join(dir, "restore_"+token+".json")
	if err := os.WriteFile(metaPath, metadata, 0600); err != nil {
		writeAPIError(w, http.StatusInternalServerError, "Token preview belum dapat disimpan.")
		return
	}
	keep = true
	_ = s.activity(r.Context(), &auth.User.ID, auth.User.Username, "RESTORE_PREVIEW", preview.Summary, clientIP(r))
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"token": token, "preview": preview, "expires_at": ticket.CreatedAt.Add(restorePreviewTTL)}})
}

func (s *Server) handleBackupRestore(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		methodNotAllowed(w, http.MethodPost)
		return
	}
	if s.DB == nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Database belum tersedia.")
		return
	}
	r.Body = http.MaxBytesReader(w, r.Body, 16<<10)
	decoder := json.NewDecoder(r.Body)
	decoder.DisallowUnknownFields()
	var input struct {
		Token       string `json:"restore_token"`
		ConfirmText string `json:"confirm_text"`
	}
	if err := decoder.Decode(&input); err != nil || input.Token == "" || input.ConfirmText != "RESTORE" {
		writeAPIError(w, http.StatusUnprocessableEntity, "Token restore atau konfirmasi RESTORE tidak valid.")
		return
	}
	if !regexp.MustCompile(`^[A-Za-z0-9_-]{32,64}$`).MatchString(input.Token) {
		writeAPIError(w, http.StatusUnprocessableEntity, "Token restore tidak valid.")
		return
	}
	dir, err := backupDir(s.Config)
	if err != nil {
		writeAPIError(w, http.StatusInternalServerError, "Direktori backup belum siap.")
		return
	}
	archivePath := filepath.Join(dir, "restore_"+input.Token+".zip")
	metaPath := filepath.Join(dir, "restore_"+input.Token+".json")
	ticketRaw, err := os.ReadFile(metaPath)
	if err != nil {
		writeAPIError(w, http.StatusConflict, "Preview restore kedaluwarsa; upload ulang file backup.")
		return
	}
	var ticket restorePreviewTicket
	auth, authenticated := currentAuth(r)
	if !authenticated || json.Unmarshal(ticketRaw, &ticket) != nil || ticket.CreatedAt.IsZero() || time.Since(ticket.CreatedAt) > restorePreviewTTL || time.Until(ticket.CreatedAt) > time.Minute || ticket.UserID != auth.User.ID {
		writeAPIError(w, http.StatusConflict, "Preview restore kedaluwarsa atau berasal dari akun lain.")
		return
	}
	fileInfo, err := os.Lstat(archivePath)
	if err != nil || !fileInfo.Mode().IsRegular() || fileInfo.Size() <= 0 {
		writeAPIError(w, http.StatusConflict, "File restore tidak ditemukan; upload ulang file backup.")
		return
	}
	preview, err := analyzeBackupArchive(r.Context(), s.DB, archivePath, maxBackupExpandedBytes(s.Config.MaxUploadMB))
	if err != nil || !preview.CanRestore {
		writeAPIError(w, http.StatusUnprocessableEntity, "Backup tidak lolos pemeriksaan ulang; database belum diubah.")
		return
	}
	safetyName, safetyPath, err := newBackupPath(dir, "backup_pre_restore_")
	if err != nil {
		writeAPIError(w, http.StatusInternalServerError, "Nama backup keselamatan belum dapat dibuat.")
		return
	}
	if _, err = createBackupArchive(r.Context(), s.DB, safetyPath); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Safety backup gagal; restore dibatalkan dan database belum diubah.")
		return
	}
	inserted, conflicts, skipped, err := mergeRestoreArchive(r.Context(), s.DB, archivePath, maxBackupExpandedBytes(s.Config.MaxUploadMB))
	if err != nil {
		writeAPIError(w, http.StatusConflict, "Merge restore gagal dan transaksinya dibatalkan; safety backup tersimpan.")
		return
	}
	_ = s.activity(r.Context(), &auth.User.ID, auth.User.Username, "RESTORE_BACKUP", fmt.Sprintf("safety=%s; %s", safetyName, preview.Summary), clientIP(r))
	_ = os.Remove(archivePath)
	_ = os.Remove(metaPath)
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"safety_backup": safetyName, "inserted": inserted, "conflicts": conflicts, "skipped": skipped, "merge_only": true}})
}

func newBackupPath(dir, prefix string) (string, string, error) {
	token, err := randomToken()
	if err != nil {
		return "", "", err
	}
	name := prefix + time.Now().In(time.Local).Format("20060102_150405") + "_" + token[:10] + ".zip"
	return name, filepath.Join(dir, name), nil
}

func listBackupFiles(dir string) ([]backupFileInfo, error) {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return nil, err
	}
	files := make([]backupFileInfo, 0)
	for _, entry := range entries {
		name := entry.Name()
		if !backupFilename.MatchString(name) || strings.HasPrefix(name, "restore_") || entry.IsDir() {
			continue
		}
		info, err := entry.Info()
		if err != nil || !info.Mode().IsRegular() {
			continue
		}
		kind := "backup"
		if strings.HasPrefix(name, "backup_pre_restore_") {
			kind = "safety"
		}
		files = append(files, backupFileInfo{Name: name, Size: info.Size(), CreatedAt: info.ModTime().UTC(), Kind: kind})
	}
	sort.Slice(files, func(i, j int) bool { return files[i].CreatedAt.After(files[j].CreatedAt) })
	if len(files) > 100 {
		files = files[:100]
	}
	return files, nil
}

func (s *Server) serveBackupDownload(w http.ResponseWriter, r *http.Request, name string) {
	if !backupFilename.MatchString(name) {
		writeAPIError(w, http.StatusNotFound, "File backup tidak ditemukan.")
		return
	}
	dir, err := backupDir(s.Config)
	if err != nil {
		writeAPIError(w, http.StatusInternalServerError, "Direktori backup belum siap.")
		return
	}
	path := filepath.Join(dir, name)
	info, err := os.Lstat(path)
	if err != nil || !info.Mode().IsRegular() || info.Mode()&os.ModeSymlink != 0 {
		writeAPIError(w, http.StatusNotFound, "File backup tidak ditemukan.")
		return
	}
	file, err := os.Open(path)
	if err != nil {
		writeAPIError(w, http.StatusNotFound, "File backup tidak dapat dibaca.")
		return
	}
	defer file.Close()
	w.Header().Set("Content-Type", "application/zip")
	w.Header().Set("Content-Disposition", `attachment; filename="`+name+`"`)
	w.Header().Set("Cache-Control", "no-store, private")
	w.Header().Set("Pragma", "no-cache")
	http.ServeContent(w, r, name, info.ModTime(), file)
}

func (s *Server) handleBackupDownloadRoute(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		methodNotAllowed(w, http.MethodGet)
		return
	}
	s.serveBackupDownload(w, r, r.PathValue("name"))
}

func (s *Server) handleLegacyBackupPage(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		methodNotAllowed(w, http.MethodGet)
		return
	}
	if r.URL.Query().Get("download") == "" && r.URL.Query().Get("export") != "1" {
		http.Redirect(w, r, "/backup", http.StatusSeeOther)
		return
	}
	s.authenticate(http.HandlerFunc(s.handleAuthenticatedLegacyBackupAction)).ServeHTTP(w, r)
}

func (s *Server) handleAuthenticatedLegacyBackupAction(w http.ResponseWriter, r *http.Request) {
	if download := r.URL.Query().Get("download"); download != "" {
		if !requestMayManageBackups(w, r) {
			return
		}
		s.serveBackupDownload(w, r, download)
		return
	}
	if r.URL.Query().Get("export") == "1" {
		if !requestMayManageBackups(w, r) {
			return
		}
		dir, err := backupDir(s.Config)
		if err != nil || s.DB == nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Backup belum tersedia.")
			return
		}
		name, path, err := newBackupPath(dir, "backup_")
		if err != nil {
			writeAPIError(w, http.StatusInternalServerError, "Nama backup belum dapat dibuat.")
			return
		}
		if _, err = createBackupArchive(r.Context(), s.DB, path); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Backup database belum berhasil dibuat.")
			return
		}
		if auth, ok := currentAuth(r); ok {
			_ = s.activity(r.Context(), &auth.User.ID, auth.User.Username, "BACKUP_EXPORT", "Membuat backup database: "+name, clientIP(r))
		}
		s.serveBackupDownload(w, r, name)
		return
	}
	writeAPIError(w, http.StatusBadRequest, "Aksi backup tidak dikenal.")
}

func requestMayManageBackups(w http.ResponseWriter, r *http.Request) bool {
	auth, ok := currentAuth(r)
	if !ok {
		writeAPIError(w, http.StatusUnauthorized, "Sesi login diperlukan.")
		return false
	}
	if auth.User.Role != "admin" && auth.User.Role != "pj_tahfidz" {
		writeAPIError(w, http.StatusForbidden, "Akses tidak diizinkan untuk akun ini.")
		return false
	}
	return true
}

func cleanupExpiredRestoreTickets(ctx context.Context, dir string) error {
	if err := ctx.Err(); err != nil {
		return err
	}
	entries, err := os.ReadDir(dir)
	if err != nil {
		return err
	}
	cutoff := time.Now().Add(-24 * time.Hour)
	for _, entry := range entries {
		if !strings.HasPrefix(entry.Name(), "restore_") || entry.IsDir() {
			continue
		}
		info, err := entry.Info()
		if err != nil || info.ModTime().After(cutoff) {
			continue
		}
		_ = os.Remove(filepath.Join(dir, entry.Name()))
	}
	return nil
}
