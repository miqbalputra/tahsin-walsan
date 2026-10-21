package app

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/base64"
	"encoding/json"
	"net"
	"net/http"
	"strings"
	"time"
	"unicode/utf8"

	"golang.org/x/crypto/bcrypt"
)

type User struct {
	ID       int64  `json:"id"`
	Username string `json:"username"`
	Name     string `json:"nama_lengkap"`
	Role     string `json:"role"`
}

type authContextKey struct{}
type requestAuth struct {
	User      User
	TokenHash []byte
	CSRFToken string
}

func currentAuth(r *http.Request) (requestAuth, bool) {
	a, ok := r.Context().Value(authContextKey{}).(requestAuth)
	return a, ok
}

func compatiblePHPHash(hash string) string {
	// PHP's password_hash() uses $2y$ for bcrypt. Its encoding is compatible
	// with bcrypt's 2a variant; normalize only the prefix in memory for Go's
	// verifier and leave the stored database value untouched.
	if strings.HasPrefix(hash, "$2y$") {
		return "$2a$" + strings.TrimPrefix(hash, "$2y$")
	}
	if strings.HasPrefix(hash, "$2b$") {
		return "$2a$" + strings.TrimPrefix(hash, "$2b$")
	}
	return hash
}

func verifyPassword(password, hash string) bool {
	if len(password) == 0 || len(password) > 1024 || len(hash) != 60 {
		return false
	}
	return bcrypt.CompareHashAndPassword([]byte(compatiblePHPHash(hash)), []byte(password)) == nil
}

func randomToken() (string, error) {
	bytes := make([]byte, 32)
	if _, err := rand.Read(bytes); err != nil {
		return "", err
	}
	return base64.RawURLEncoding.EncodeToString(bytes), nil
}

func tokenHash(token string) []byte {
	sum := sha256.Sum256([]byte(token))
	return sum[:]
}

func (s *Server) handleLogin(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		methodNotAllowed(w, http.MethodPost)
		return
	}
	if !sameOrigin(r) {
		writeAPIError(w, http.StatusForbidden, "Permintaan login tidak valid.")
		return
	}
	r.Body = http.MaxBytesReader(w, r.Body, 16<<10)
	decoder := json.NewDecoder(r.Body)
	decoder.DisallowUnknownFields()
	var input struct {
		Username string `json:"username"`
		Password string `json:"password"`
	}
	if err := decoder.Decode(&input); err != nil || strings.TrimSpace(input.Username) == "" || input.Password == "" || len(input.Username) > 50 || len(input.Password) > 1024 {
		writeAPIError(w, http.StatusUnprocessableEntity, "Username atau password tidak valid.")
		return
	}
	ip := clientIP(r)
	var tries int
	err := s.DB.QueryRowContext(r.Context(), "SELECT COUNT(*) FROM login_attempts WHERE (ip_address=? OR username=?) AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)", ip, input.Username).Scan(&tries)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Login sementara belum tersedia.")
		return
	}
	if tries >= 5 {
		_ = s.activity(r.Context(), nil, input.Username, "LOGIN_BLOCKED", "Percobaan login mencapai batas.", ip)
		writeAPIError(w, http.StatusTooManyRequests, "Terlalu banyak percobaan login. Coba lagi dalam 15 menit.")
		return
	}
	var user User
	var passwordHash string
	err = s.DB.QueryRowContext(r.Context(), "SELECT id,username,nama_lengkap,role,password FROM users WHERE username=? LIMIT 1", input.Username).Scan(&user.ID, &user.Username, &user.Name, &user.Role, &passwordHash)
	if err != nil || !verifyPassword(input.Password, passwordHash) {
		_, _ = s.DB.ExecContext(r.Context(), "INSERT INTO login_attempts(ip_address,username,attempted_at) VALUES(?,?,NOW())", ip, input.Username)
		_ = s.activity(r.Context(), nil, input.Username, "LOGIN_FAILED", "Username atau password salah.", ip)
		writeAPIError(w, http.StatusUnauthorized, "Username atau password salah.")
		return
	}
	if user.ID < 1 || !validRole(user.Role) {
		writeAPIError(w, http.StatusUnauthorized, "Akun tidak dapat digunakan.")
		return
	}
	if _, err = s.DB.ExecContext(r.Context(), "DELETE FROM login_attempts WHERE ip_address=? OR username=?", ip, input.Username); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Login sementara belum tersedia.")
		return
	}
	token, err := randomToken()
	if err != nil {
		writeAPIError(w, http.StatusInternalServerError, "Login belum dapat diselesaikan.")
		return
	}
	csrf, err := randomToken()
	if err != nil {
		writeAPIError(w, http.StatusInternalServerError, "Login belum dapat diselesaikan.")
		return
	}
	now := time.Now().UTC()
	expires := now.Add(time.Duration(s.Config.SessionDays) * 24 * time.Hour)
	_, err = s.DB.ExecContext(r.Context(), "INSERT INTO tahsin_app_sessions(session_hash,user_id,csrf_token,created_at,last_seen_at,expires_at,ip_address,user_agent) VALUES(?,?,?,?,?,?,?,?)", tokenHash(token), user.ID, csrf, now, now, expires, ip, truncateUTF8(r.UserAgent(), 255))
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Sesi login belum dapat disimpan.")
		return
	}
	s.setSessionCookie(w, r, token, expires)
	_ = s.activity(r.Context(), &user.ID, user.Username, "LOGIN", "Login Go+Vue berhasil.", ip)
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"user": user, "csrf_token": csrf}})
}

func (s *Server) handleMe(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		methodNotAllowed(w, http.MethodGet)
		return
	}
	auth, ok := currentAuth(r)
	if !ok {
		writeAPIError(w, http.StatusUnauthorized, "Sesi login berakhir. Masuk kembali.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"user": auth.User, "csrf_token": auth.CSRFToken}})
}

func (s *Server) handleLogout(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		methodNotAllowed(w, http.MethodPost)
		return
	}
	auth, ok := currentAuth(r)
	if !ok {
		writeAPIError(w, http.StatusUnauthorized, "Sesi login berakhir.")
		return
	}
	if !validCSRF(auth.CSRFToken, r.Header.Get("X-CSRF-Token")) {
		writeAPIError(w, http.StatusForbidden, "Token keamanan tidak valid. Muat ulang halaman.")
		return
	}
	_, _ = s.DB.ExecContext(r.Context(), "DELETE FROM tahsin_app_sessions WHERE session_hash=?", auth.TokenHash)
	s.clearSessionCookie(w, r)
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok"})
}

func (s *Server) authenticate(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		cookie, err := r.Cookie(s.Config.CookieName)
		if err != nil || cookie.Value == "" {
			writeAPIError(w, http.StatusUnauthorized, "Sesi login berakhir. Masuk kembali.")
			return
		}
		var auth requestAuth
		var expiry time.Time
		err = s.DB.QueryRowContext(r.Context(), "SELECT u.id,u.username,u.nama_lengkap,u.role,s.csrf_token,s.expires_at FROM tahsin_app_sessions s JOIN users u ON u.id=s.user_id WHERE s.session_hash=? LIMIT 1", tokenHash(cookie.Value)).Scan(&auth.User.ID, &auth.User.Username, &auth.User.Name, &auth.User.Role, &auth.CSRFToken, &expiry)
		if err != nil || !expiry.After(time.Now()) || auth.User.ID < 1 || !validRole(auth.User.Role) {
			if err == nil {
				_, _ = s.DB.ExecContext(r.Context(), "DELETE FROM tahsin_app_sessions WHERE session_hash=?", tokenHash(cookie.Value))
			}
			s.clearSessionCookie(w, r)
			writeAPIError(w, http.StatusUnauthorized, "Sesi login berakhir. Masuk kembali.")
			return
		}
		auth.TokenHash = tokenHash(cookie.Value)
		if isMutation(r.Method) && !validCSRF(auth.CSRFToken, r.Header.Get("X-CSRF-Token")) {
			writeAPIError(w, http.StatusForbidden, "Token keamanan tidak valid. Muat ulang halaman.")
			return
		}
		ctx := context.WithValue(r.Context(), authContextKey{}, auth)
		next.ServeHTTP(w, r.WithContext(ctx))
	})
}

func (s *Server) requireRoles(next http.Handler, roles ...string) http.Handler {
	return s.authenticate(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		auth, _ := currentAuth(r)
		for _, role := range roles {
			if auth.User.Role == role {
				next.ServeHTTP(w, r)
				return
			}
		}
		writeAPIError(w, http.StatusForbidden, "Akses tidak diizinkan untuk akun ini.")
	}))
}

func (s *Server) sessionMiddleware(next http.Handler) http.Handler { return s.authenticate(next) }

func (s *Server) setSessionCookie(w http.ResponseWriter, r *http.Request, token string, expires time.Time) {
	secure := s.Config.Environment == "production" || strings.EqualFold(r.Header.Get("X-Forwarded-Proto"), "https") || r.TLS != nil
	http.SetCookie(w, &http.Cookie{Name: s.Config.CookieName, Value: token, Path: "/", Expires: expires, MaxAge: int(time.Until(expires).Seconds()), HttpOnly: true, Secure: secure, SameSite: http.SameSiteLaxMode})
}

func (s *Server) clearSessionCookie(w http.ResponseWriter, r *http.Request) {
	secure := s.Config.Environment == "production" || strings.EqualFold(r.Header.Get("X-Forwarded-Proto"), "https") || r.TLS != nil
	http.SetCookie(w, &http.Cookie{Name: s.Config.CookieName, Value: "", Path: "/", MaxAge: -1, Expires: time.Unix(1, 0), HttpOnly: true, Secure: secure, SameSite: http.SameSiteLaxMode})
}

func (s *Server) activity(ctx context.Context, userID *int64, username, action, description, ip string) error {
	var id any
	if userID != nil {
		id = *userID
	}
	_, err := s.DB.ExecContext(ctx, "INSERT INTO activity_logs(user_id,username,action,description,ip_address) VALUES(?,?,?,?,?)", id, username, action, description, ip)
	return err
}

func validRole(role string) bool {
	switch role {
	case "admin", "pj_tahfidz", "kepsek", "ustadz":
		return true
	default:
		return false
	}
}

func validCSRF(expected, provided string) bool {
	if len(expected) < 32 || len(provided) != len(expected) {
		return false
	}
	return subtle.ConstantTimeCompare([]byte(expected), []byte(provided)) == 1
}

func isMutation(method string) bool {
	switch method {
	case http.MethodGet, http.MethodHead, http.MethodOptions:
		return false
	default:
		return true
	}
}

func sameOrigin(r *http.Request) bool {
	origin := r.Header.Get("Origin")
	if origin == "" {
		return r.Header.Get("Sec-Fetch-Site") != "cross-site"
	}
	return strings.EqualFold(originHost(origin), r.Host)
}

func originHost(origin string) string {
	if i := strings.Index(origin, "://"); i >= 0 {
		return strings.SplitN(origin[i+3:], "/", 2)[0]
	}
	return ""
}

func clientIP(r *http.Request) string {
	if host, _, err := net.SplitHostPort(r.RemoteAddr); err == nil {
		return host
	}
	return truncateUTF8(r.RemoteAddr, 45)
}

func truncateUTF8(value string, max int) string {
	if len(value) <= max {
		return value
	}
	value = value[:max]
	for len(value) > 0 && !utf8.ValidString(value) {
		value = value[:len(value)-1]
	}
	return value
}

func methodNotAllowed(w http.ResponseWriter, allow string) {
	w.Header().Set("Allow", allow)
	writeAPIError(w, http.StatusMethodNotAllowed, "Metode permintaan tidak diizinkan.")
}

func writeAPIError(w http.ResponseWriter, status int, message string) {
	writeJSON(w, status, map[string]any{"status": "error", "message": message})
}

func writeJSON(w http.ResponseWriter, status int, value any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store, private")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(value)
}
