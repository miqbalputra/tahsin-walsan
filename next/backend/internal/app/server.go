package app

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"tahsin.local/next/internal/preview"
	"tahsin.local/next/internal/sessionread"
)

type Server struct {
	Config Config
	DB     *sql.DB
	Reader sessionread.Reader
	Writer sessionread.Writer
}

var contentHashedAsset = regexp.MustCompile(`-[A-Za-z0-9_-]{8,}\.[A-Za-z0-9]+$`)

func NewServer(config Config, db *sql.DB) *Server {
	return &Server{Config: config, DB: db, Reader: sessionread.Reader{DB: db}, Writer: sessionread.Writer{DB: db}}
}

func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", s.handleHealth)
	mux.HandleFunc("GET /health.php", s.handleHealth)
	mux.HandleFunc("GET /readyz", s.handleReady)
	mux.HandleFunc("POST /api/v1/auth/login", s.handleLogin)
	mux.Handle("GET /api/v1/auth/me", s.authenticate(http.HandlerFunc(s.handleMe)))
	mux.Handle("POST /api/v1/auth/logout", s.authenticate(http.HandlerFunc(s.handleLogout)))
	mux.Handle("GET /api/v1/teacher/groups", s.requireRoles(http.HandlerFunc(s.handleGroups), "admin", "pj_tahfidz", "kepsek", "ustadz"))
	mux.Handle("GET /api/v1/groups", s.requireRoles(http.HandlerFunc(s.handleGroupManagement), "admin", "pj_tahfidz"))
	mux.Handle("POST /api/v1/groups", s.requireRoles(http.HandlerFunc(s.handleGroupManagement), "admin", "pj_tahfidz"))
	mux.Handle("PATCH /api/v1/groups/{id}", s.requireRoles(http.HandlerFunc(s.handleGroupManagement), "admin", "pj_tahfidz"))
	mux.Handle("GET /api/v1/groups/{id}/members", s.requireRoles(http.HandlerFunc(s.handleGroupMembers), "admin", "pj_tahfidz"))
	mux.Handle("POST /api/v1/groups/{id}/members", s.requireRoles(http.HandlerFunc(s.handleGroupMembers), "admin", "pj_tahfidz"))
	mux.Handle("DELETE /api/v1/groups/{id}/members/{waliID}", s.requireRoles(http.HandlerFunc(s.handleGroupMembers), "admin", "pj_tahfidz"))
	mux.Handle("GET /api/v1/participants", s.requireRoles(http.HandlerFunc(s.handleParticipants), "admin", "pj_tahfidz"))
	mux.Handle("POST /api/v1/participants", s.requireRoles(http.HandlerFunc(s.handleParticipants), "admin", "pj_tahfidz"))
	mux.Handle("PATCH /api/v1/participants/{id}", s.requireRoles(http.HandlerFunc(s.handleParticipant), "admin", "pj_tahfidz"))
	mux.Handle("DELETE /api/v1/participants/{id}", s.requireRoles(http.HandlerFunc(s.handleParticipant), "admin", "pj_tahfidz"))
	mux.Handle("GET /api/v1/users", s.requireRoles(http.HandlerFunc(s.handleUsers), "admin", "pj_tahfidz"))
	mux.Handle("POST /api/v1/users", s.requireRoles(http.HandlerFunc(s.handleUsers), "admin", "pj_tahfidz"))
	mux.Handle("PATCH /api/v1/users/{id}", s.requireRoles(http.HandlerFunc(s.handleUser), "admin", "pj_tahfidz"))
	mux.Handle("GET /api/v1/dashboard", s.requireRoles(http.HandlerFunc(s.handleDashboard), "admin", "pj_tahfidz", "kepsek"))
	mux.Handle("GET /api/v1/logs", s.requireRoles(http.HandlerFunc(s.handleActivityLogs), "admin"))
	mux.Handle("GET /api/v1/settings/report", s.requireRoles(http.HandlerFunc(s.handleReportSettings), "admin"))
	mux.Handle("PATCH /api/v1/settings/report", s.requireRoles(http.HandlerFunc(s.handleReportSettings), "admin"))
	mux.Handle("GET /api/v1/holidays", s.requireRoles(http.HandlerFunc(s.handleHolidays), "admin", "pj_tahfidz", "kepsek", "ustadz"))
	mux.Handle("POST /api/v1/holidays", s.requireRoles(http.HandlerFunc(s.handleHolidays), "admin", "pj_tahfidz"))
	mux.Handle("DELETE /api/v1/holidays/{id}", s.requireRoles(http.HandlerFunc(s.handleHoliday), "admin", "pj_tahfidz"))
	mux.Handle("GET /api/v1/announcements/active", s.requireRoles(http.HandlerFunc(s.handleActiveAnnouncement), "admin", "pj_tahfidz", "kepsek", "ustadz"))
	mux.Handle("GET /api/v1/announcements", s.requireRoles(http.HandlerFunc(s.handleAnnouncements), "admin", "pj_tahfidz"))
	mux.Handle("POST /api/v1/announcements", s.requireRoles(http.HandlerFunc(s.handleAnnouncements), "admin", "pj_tahfidz"))
	mux.Handle("PATCH /api/v1/announcements/{id}", s.requireRoles(http.HandlerFunc(s.handleAnnouncement), "admin", "pj_tahfidz"))
	mux.Handle("GET /api/v1/families", s.requireRoles(http.HandlerFunc(s.handleFamilies), "admin", "pj_tahfidz"))
	mux.Handle("GET /api/v1/reports/attendance", s.requireRoles(http.HandlerFunc(s.handleAttendanceReport), "admin", "pj_tahfidz", "kepsek", "ustadz"))
	mux.Handle("GET /api/v1/reports/attendance/options", s.requireRoles(http.HandlerFunc(s.handleAttendanceReportOptions), "admin", "pj_tahfidz", "kepsek", "ustadz"))
	mux.Handle("GET /api/v1/reports/tahsin", s.requireRoles(http.HandlerFunc(s.handleTahsinReport), "admin", "pj_tahfidz", "kepsek", "ustadz"))
	mux.Handle("POST /api/v1/reports/tahsin/verifications", s.requireRoles(http.HandlerFunc(s.handleTahsinVerification), "admin", "pj_tahfidz"))
	mux.Handle("GET /api/v1/backups", s.requireRoles(http.HandlerFunc(s.handleBackups), "admin", "pj_tahfidz"))
	mux.Handle("POST /api/v1/backups", s.requireRoles(http.HandlerFunc(s.handleBackups), "admin", "pj_tahfidz"))
	mux.Handle("GET /api/v1/backups/{name}", s.requireRoles(http.HandlerFunc(s.handleBackupDownloadRoute), "admin", "pj_tahfidz"))
	mux.Handle("POST /api/v1/backups/restore-preview", s.requireRoles(http.HandlerFunc(s.handleBackupRestorePreview), "admin", "pj_tahfidz"))
	mux.Handle("POST /api/v1/backups/restore", s.requireRoles(http.HandlerFunc(s.handleBackupRestore), "admin", "pj_tahfidz"))
	mux.HandleFunc("GET /backup-restore.php", s.handleLegacyBackupPage)
	mux.Handle("GET /export-excel.php", s.requireRoles(http.HandlerFunc(s.handleAttendanceExport), "admin", "pj_tahfidz", "kepsek", "ustadz"))
	mux.Handle("DELETE /api/v1/attendance/records/{id}", s.requireRoles(http.HandlerFunc(s.handleDeleteAttendance), "admin", "pj_tahfidz", "ustadz"))
	mux.Handle("GET /api/v1/attendance/state", s.requireRoles(http.HandlerFunc(s.handleAttendanceState), "ustadz"))
	mux.Handle("POST /api/v1/attendance/snapshot", s.requireRoles(http.HandlerFunc(s.handleAttendanceSnapshot), "ustadz"))
	mux.Handle("POST /api/v1/attendance/finalize", s.requireRoles(http.HandlerFunc(s.handleAttendanceSnapshot), "ustadz"))
	// Keep the old autosave URL alive so IndexedDB queues and already-open tabs
	// can replay their original payloads through the same Go session.
	mux.Handle("/ustadz/api-autosave.php", bodyCSRFHeader(s.requireRoles(http.HandlerFunc(s.handleLegacyAutosave), "ustadz")))
	mux.HandleFunc("/api/hermes.php", s.handleHermes)
	mux.HandleFunc("/api/notifications-pending.php", s.handleTahsinReminder(false))
	mux.HandleFunc("/api/daily-reminder.php", s.handleTahsinReminder(true))
	mux.HandleFunc("/api/", s.handleUnknownAPI)
	mux.Handle("/", s.staticOrSPA())
	return secureHeaders(s.releaseGate(mux))
}

// releaseGate keeps the Go API inert in production until the operator opens
// the explicit release switch after the migration and backup gates pass. This
// also protects against accidental proxy routing to an unhealthy service.
func (s *Server) releaseGate(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if s.Config.Environment == "production" && !s.Config.ProductionReady && (strings.HasPrefix(r.URL.Path, "/api/") || strings.HasPrefix(r.URL.Path, "/ustadz/")) {
			writeAPIError(w, http.StatusServiceUnavailable, "API Go+Vue belum dibuka; aplikasi PHP tetap menjadi layanan operasional.")
			return
		}
		next.ServeHTTP(w, r)
	})
}

func (s *Server) handleHealth(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "service": "tahsin-go-vue"})
}

func (s *Server) handleReady(w http.ResponseWriter, r *http.Request) {
	ctx, cancel := context.WithTimeout(r.Context(), 2*time.Second)
	defer cancel()
	if err := s.DB.PingContext(ctx); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Database belum siap.")
		return
	}
	var missing []string
	if _, err := backupDir(s.Config); err != nil {
		missing = append(missing, "APP_DATA_DIR/backups")
	}
	for _, table := range []string{"users", "halaqoh", "halaqoh_members", "wali_santri", "santri_detail", "presensi", "activity_logs", "login_attempts", "holidays", "pengumuman", "settings", "tahsin_tracking", "tahsin_roster_history", "tahsin_verifications", "tahsin_autosave_drafts", "tahsin_autosave_operations", "tahsin_autosave_revisions", "tahsin_autosave_sessions", "tahsin_app_sessions"} {
		var exists int
		if err := s.DB.QueryRowContext(ctx, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?", table).Scan(&exists); err != nil || exists != 1 {
			missing = append(missing, table)
		}
	}
	for _, required := range []struct{ table, column string }{{"halaqoh", "program_code"}, {"halaqoh_members", "archived_at"}} {
		var exists int
		if err := s.DB.QueryRowContext(ctx, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?", required.table, required.column).Scan(&exists); err != nil || exists != 1 {
			missing = append(missing, required.table+"."+required.column)
		}
	}
	if len(missing) != 0 {
		writeJSON(w, http.StatusServiceUnavailable, map[string]any{"status": "error", "message": "Migrasi aditif atau skema lama belum siap.", "missing": missing})
		return
	}
	if s.Config.Environment == "production" && !s.Config.ProductionReady {
		writeJSON(w, http.StatusServiceUnavailable, map[string]any{"status": "error", "message": "Gerbang rilis Go+Vue belum dibuka; aplikasi PHP tetap menjadi layanan operasional."})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "service": "tahsin-go-vue", "database": "ready"})
}

func (s *Server) handleGroups(w http.ResponseWriter, r *http.Request) {
	auth, _ := currentAuth(r)
	query := "SELECT h.id,h.nama_halaqoh,h.ustadz_id,u.nama_lengkap AS nama_ustadz,h.program_code FROM halaqoh h LEFT JOIN users u ON u.id=h.ustadz_id"
	args := []any{}
	if auth.User.Role == "ustadz" {
		query += " WHERE h.ustadz_id=?"
		args = append(args, auth.User.ID)
	}
	query += " ORDER BY h.nama_halaqoh,h.id"
	rows, err := s.DB.QueryContext(r.Context(), query, args...)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Daftar kelompok belum dapat dibaca.")
		return
	}
	defer rows.Close()
	type group struct {
		ID        int64  `json:"id"`
		Name      string `json:"nama_halaqoh"`
		TeacherID int64  `json:"ustadz_id"`
		Teacher   string `json:"nama_ustadz"`
		Program   string `json:"program_code"`
	}
	groups := []group{}
	for rows.Next() {
		var g group
		if err := rows.Scan(&g.ID, &g.Name, &g.TeacherID, &g.Teacher, &g.Program); err != nil {
			writeAPIError(w, http.StatusServiceUnavailable, "Daftar kelompok belum dapat dibaca.")
			return
		}
		groups = append(groups, g)
	}
	if err := rows.Err(); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Daftar kelompok belum dapat dibaca.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "success", "data": groups})
}

func (s *Server) handleFamilies(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		methodNotAllowed(w, http.MethodGet)
		return
	}
	filters, err := preview.ParseFilters(r)
	if err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, "Parameter pencarian keluarga tidak valid.")
		return
	}
	store := &preview.MySQLStore{DB: s.DB}
	page, err := store.List(r.Context(), filters)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Data keluarga belum dapat dibaca.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": page.Students, "meta": map[string]any{"page": filters.Page, "limit": filters.Limit, "total": page.Total, "scope": filters.Scope, "read_only": true, "data_source": "mysql", "schema": page.Schema}})
}

func requestedSession(r *http.Request) (int64, string, error) {
	q := r.URL.Query()
	groupText := q.Get("halaqoh_id")
	if groupText == "" {
		groupText = q.Get("group_id")
	}
	group, err := strconv.ParseInt(groupText, 10, 64)
	date := q.Get("tanggal")
	if date == "" {
		date = q.Get("date")
	}
	if err != nil || group < 1 || strconv.FormatInt(group, 10) != groupText || len(date) != 10 {
		return 0, "", errors.New("kelompok atau tanggal tidak valid")
	}
	if _, err = time.Parse("2006-01-02", date); err != nil {
		return 0, "", errors.New("kelompok atau tanggal tidak valid")
	}
	return group, date, nil
}

func (s *Server) handleAttendanceState(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		methodNotAllowed(w, http.MethodGet)
		return
	}
	auth, _ := currentAuth(r)
	group, date, err := requestedSession(r)
	if err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, err.Error())
		return
	}
	state, err := s.Reader.Read(r.Context(), auth.User.ID, group, date, time.Now().In(mustLocation(s.Config.Timezone)))
	if err != nil {
		respondReadError(w, err)
		return
	}
	members, err := s.rosterForState(r.Context(), group, state.Entries)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Daftar peserta belum dapat dibaca.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "success", "data": map[string]any{"state": state, "members": members}})
}

func (s *Server) handleAttendanceSnapshot(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		methodNotAllowed(w, http.MethodPost)
		return
	}
	auth, _ := currentAuth(r)
	r.Body = http.MaxBytesReader(w, r.Body, 64<<10)
	body, err := io.ReadAll(r.Body)
	if err != nil {
		writeAPIError(w, http.StatusRequestEntityTooLarge, "Permintaan terlalu besar.")
		return
	}
	group, date, err := requestedSnapshot(body)
	if err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, "Kelompok atau tanggal tidak valid.")
		return
	}
	request, err := sessionread.ParseWrite(body, group, date)
	if err != nil {
		respondWriteError(w, err)
		return
	}
	request.ActorIP = clientIP(r)
	requestHash, err := sessionread.HashPHPAutosaveRequest(group, date, body)
	if err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, "Format permintaan tidak valid.")
		return
	}
	result, err := s.Writer.Execute(r.Context(), auth.User.ID, 0, request, requestHash, time.Now().In(mustLocation(s.Config.Timezone)))
	if err != nil {
		respondWriteError(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "success", "data": result})
}

func requestedSnapshot(body []byte) (int64, string, error) {
	var input struct {
		Group int64  `json:"halaqoh_id"`
		Date  string `json:"tanggal"`
	}
	if err := json.Unmarshal(body, &input); err != nil || input.Group < 1 {
		return 0, "", errors.New("invalid snapshot")
	}
	if _, err := time.Parse("2006-01-02", input.Date); err != nil {
		return 0, "", errors.New("invalid date")
	}
	return input.Group, input.Date, nil
}

func (s *Server) handleLegacyAutosave(w http.ResponseWriter, r *http.Request) {
	if r.Method == http.MethodGet {
		group, date, err := requestedSession(r)
		if err != nil {
			writeJSON(w, http.StatusUnprocessableEntity, map[string]any{"status": "error", "message": err.Error()})
			return
		}
		auth, _ := currentAuth(r)
		state, err := s.Reader.Read(r.Context(), auth.User.ID, group, date, time.Now().In(mustLocation(s.Config.Timezone)))
		if err != nil {
			respondReadErrorLegacy(w, err)
			return
		}
		writeJSON(w, http.StatusOK, map[string]any{"status": "success", "data": state})
		return
	}
	if r.Method != http.MethodPost {
		methodNotAllowed(w, "GET, POST")
		return
	}
	s.handleAttendanceSnapshot(w, r)
}

func bodyCSRFHeader(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			next.ServeHTTP(w, r)
			return
		}
		r.Body = http.MaxBytesReader(w, r.Body, 64<<10)
		body, err := io.ReadAll(r.Body)
		if err != nil {
			writeAPIError(w, http.StatusRequestEntityTooLarge, "Permintaan terlalu besar.")
			return
		}
		var input struct {
			CSRF string `json:"csrf_token"`
		}
		if json.Unmarshal(body, &input) != nil || input.CSRF == "" {
			writeAPIError(w, http.StatusForbidden, "Token keamanan tidak valid. Muat ulang halaman.")
			return
		}
		r.Header.Set("X-CSRF-Token", input.CSRF)
		r.Body = io.NopCloser(strings.NewReader(string(body)))
		next.ServeHTTP(w, r)
	})
}

type rosterMember struct {
	ID        int64  `json:"id"`
	Name      string `json:"name"`
	Children  string `json:"children"`
	Reference string `json:"reference"`
}

func (s *Server) rosterForState(ctx context.Context, group int64, entries []sessionread.Entry) ([]rosterMember, error) {
	if len(entries) == 0 {
		return []rosterMember{}, nil
	}
	ids := make([]any, 0, len(entries))
	entryByID := make(map[int64]sessionread.Entry, len(entries))
	marks := make([]string, 0, len(entries))
	for _, entry := range entries {
		ids = append(ids, entry.WaliID)
		marks = append(marks, "?")
		entryByID[entry.WaliID] = entry
	}
	childTable := "santri_detail"
	var familyView int
	if err := s.DB.QueryRowContext(ctx, "SELECT COUNT(*) FROM information_schema.views WHERE table_schema=DATABASE() AND table_name='family_children'").Scan(&familyView); err != nil {
		return nil, err
	}
	if familyView == 1 {
		childTable = "family_children"
	}
	query := "SELECT w.id,w.nama_bapak,COALESCE(GROUP_CONCAT(DISTINCT CONCAT(sd.nama_anak,IF(COALESCE(sd.kelas,'')='', '', CONCAT(' [',sd.kelas,']'))) ORDER BY sd.nama_anak SEPARATOR '\\n'),'') AS children FROM wali_santri w LEFT JOIN " + childTable + " sd ON sd.wali_santri_id=w.id WHERE w.id IN (" + strings.Join(marks, ",") + ") GROUP BY w.id,w.nama_bapak ORDER BY w.nama_bapak"
	rows, err := s.DB.QueryContext(ctx, query, ids...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	members := []rosterMember{}
	for rows.Next() {
		var item rosterMember
		if err = rows.Scan(&item.ID, &item.Name, &item.Children); err != nil {
			return nil, err
		}
		entry := entryByID[item.ID]
		item.Reference = formatReference(entry.OfficialPayload)
		members = append(members, item)
	}
	return members, rows.Err()
}

func formatReference(p sessionread.Payload) string {
	if p.Status != "H" || p.Material == "" {
		return "Belum ada capaian sebelumnya"
	}
	if p.Material == "Iqro" {
		return "Iqro " + p.Level + ", hlm. " + strings.TrimSpace(p.PageFrom+"-"+p.PageTo)
	}
	return "Al-Qur’an " + strings.TrimSpace(p.SurahFrom+"–"+p.SurahTo) + ", hlm. " + strings.TrimSpace(p.PageFrom+"-"+p.PageTo)
}

func (s *Server) staticOrSPA() http.Handler {
	root := filepath.Clean(s.Config.StaticDir)
	files := http.FileServer(http.Dir(root))
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet && r.Method != http.MethodHead {
			methodNotAllowed(w, "GET, HEAD")
			return
		}
		path := strings.TrimPrefix(filepath.Clean("/"+r.URL.Path), string(filepath.Separator))
		if path == "." || path == "" {
			path = "index.html"
		}
		candidate := filepath.Join(root, filepath.FromSlash(path))
		rel, err := filepath.Rel(root, candidate)
		if err != nil || rel == ".." || strings.HasPrefix(rel, ".."+string(filepath.Separator)) {
			http.NotFound(w, r)
			return
		}
		if info, statErr := os.Stat(candidate); statErr == nil && !info.IsDir() {
			if filepath.Ext(candidate) == ".webmanifest" {
				w.Header().Set("Content-Type", "application/manifest+json; charset=utf-8")
			}
			assetName := r.URL.Path
			if slash := strings.LastIndex(assetName, "/"); slash >= 0 {
				assetName = assetName[slash+1:]
			}
			if strings.HasPrefix(r.URL.Path, "/assets/") && contentHashedAsset.MatchString(assetName) {
				w.Header().Set("Cache-Control", "public, max-age=31536000, immutable")
			} else {
				w.Header().Set("Cache-Control", "no-cache, no-store, must-revalidate")
			}
			http.StripPrefix("/", files).ServeHTTP(w, r)
			return
		}
		if strings.HasPrefix(r.URL.Path, "/api/") || strings.HasPrefix(r.URL.Path, "/assets/") || strings.HasPrefix(r.URL.Path, "/favicon") || strings.HasPrefix(r.URL.Path, "/manifest") || strings.HasPrefix(r.URL.Path, "/sw.") {
			http.NotFound(w, r)
			return
		}
		// Legacy page URLs are client-side routes during the transition. Missing
		// JavaScript, CSS, images and other asset-like paths remain real 404s.
		if filepath.Ext(r.URL.Path) != "" && filepath.Ext(r.URL.Path) != ".php" {
			http.NotFound(w, r)
			return
		}
		w.Header().Set("Cache-Control", "no-cache, no-store, must-revalidate")
		http.ServeFile(w, r, filepath.Join(root, "index.html"))
	})
}

func (s *Server) handleUnknownAPI(w http.ResponseWriter, r *http.Request) {
	writeAPIError(w, http.StatusNotFound, "Endpoint API tidak ditemukan.")
}

func (s *Server) handleUnportedIntegration(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Access-Control-Allow-Origin", "*")
	w.Header().Set("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
	w.Header().Set("Access-Control-Allow-Headers", "Content-Type, X-API-Key, Authorization")
	if r.Method == http.MethodOptions {
		w.WriteHeader(http.StatusNoContent)
		return
	}
	if r.Method != http.MethodGet && r.Method != http.MethodPost {
		methodNotAllowed(w, "GET, POST, OPTIONS")
		return
	}
	writeAPIError(w, http.StatusServiceUnavailable, "Integrasi ini belum dipindahkan; gerbang produksi Go+Vue tetap tertutup.")
}

func respondReadError(w http.ResponseWriter, err error) {
	var readErr *sessionread.Error
	if errors.As(err, &readErr) {
		writeAPIError(w, readErr.Status, readErr.Message)
		return
	}
	writeAPIError(w, http.StatusServiceUnavailable, "Sesi belum dapat dibaca. Draft lokal tetap aman.")
}

func respondReadErrorLegacy(w http.ResponseWriter, err error) {
	var readErr *sessionread.Error
	if errors.As(err, &readErr) {
		writeJSON(w, readErr.Status, map[string]any{"status": "error", "message": readErr.Message})
		return
	}
	writeJSON(w, http.StatusServiceUnavailable, map[string]any{"status": "error", "message": "Sesi belum dapat dibaca. Draft lokal tetap aman."})
}

func respondWriteError(w http.ResponseWriter, err error) {
	var writeErr *sessionread.WriteError
	if errors.As(err, &writeErr) {
		body := map[string]any{"status": "error", "message": writeErr.Message, "details": map[string]any{}}
		if writeErr.Entry != nil {
			body["details"] = map[string]any{"entry": writeErr.Entry}
		}
		writeJSON(w, writeErr.Status, body)
		return
	}
	var readErr *sessionread.Error
	if errors.As(err, &readErr) {
		writeAPIError(w, readErr.Status, readErr.Message)
		return
	}
	writeAPIError(w, http.StatusServiceUnavailable, "Server belum dapat menyimpan. Draft dipertahankan dan pengiriman akan dicoba lagi.")
}

func mustLocation(name string) *time.Location {
	location, err := time.LoadLocation(name)
	if err != nil {
		return time.FixedZone("UTC+7", 7*60*60)
	}
	return location
}

func secureHeaders(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("X-Content-Type-Options", "nosniff")
		w.Header().Set("X-Frame-Options", "DENY")
		w.Header().Set("Referrer-Policy", "strict-origin-when-cross-origin")
		w.Header().Set("Permissions-Policy", "camera=(), microphone=(), geolocation=()")
		next.ServeHTTP(w, r)
	})
}
