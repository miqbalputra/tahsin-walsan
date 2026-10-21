package preview

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"strconv"
	"strings"
	"tahsin.local/next/internal/sessionread"
	"time"
	"unicode/utf8"
)

type Server struct {
	Enabled        bool
	Secret         []byte
	Store          Store
	Mode           string
	Now            func() time.Time
	TeacherEnabled bool
	TeacherGroups  map[int64]bool
	TeacherReader  interface {
		Read(context.Context, int64, int64, string, time.Time) (sessionread.State, error)
	}
}

func Signature(secret []byte, method, path, query, user, role, stamp string) string {
	mac := hmac.New(sha256.New, secret)
	fmt.Fprint(mac, strings.Join([]string{"v1", method, path, query, user, role, stamp}, "\n"))
	return hex.EncodeToString(mac.Sum(nil))
}
func (s Server) authorized(r *http.Request) bool {
	return s.authorizedRoles(r, "admin", "pj_tahfidz")
}
func (s Server) authorizedRoles(r *http.Request, roles ...string) bool {
	user, role, stamp := r.Header.Get("X-Tahsin-User"), r.Header.Get("X-Tahsin-Role"), r.Header.Get("X-Tahsin-Time")
	allowed := false
	for _, v := range roles {
		allowed = allowed || v == role
	}
	uid, err := strconv.ParseInt(user, 10, 64)
	if err != nil || uid < 1 || strconv.FormatInt(uid, 10) != user || !allowed || len(s.Secret) < 32 {
		return false
	}
	ts, err := strconv.ParseInt(stamp, 10, 64)
	if err != nil {
		return false
	}
	now := time.Now()
	if s.Now != nil {
		now = s.Now()
	}
	if ts > now.Unix()+5 || ts < now.Unix()-30 {
		return false
	}
	expected := Signature(s.Secret, r.Method, r.URL.Path, r.URL.RawQuery, user, role, stamp)
	return hmac.Equal([]byte(expected), []byte(r.Header.Get("X-Tahsin-Signature")))
}
func writeJSON(w http.ResponseWriter, status int, value any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store, private")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(value)
}
func apiError(w http.ResponseWriter, status int, message string) {
	writeJSON(w, status, map[string]any{"status": "error", "message": message})
}

func ParseFilters(r *http.Request) (Filters, error) {
	f := Filters{Page: 1, Limit: 25, Scope: "active"}
	q := r.URL.Query()
	for key, values := range q {
		if (key != "page" && key != "limit" && key != "search" && key != "scope") || len(values) != 1 {
			return f, fmt.Errorf("parameter tidak dikenal atau ganda")
		}
	}
	for key, target := range map[string]*int{"page": &f.Page, "limit": &f.Limit} {
		if value := q.Get(key); value != "" {
			n, err := strconv.Atoi(value)
			if err != nil || n < 1 {
				return f, fmt.Errorf("parameter angka tidak valid")
			}
			*target = n
		}
	}
	if f.Page > 10000 || f.Limit > 100 {
		return f, fmt.Errorf("batas halaman terlampaui")
	}
	if value := q.Get("scope"); value != "" {
		f.Scope = value
	}
	if f.Scope != "active" && f.Scope != "all" {
		return f, fmt.Errorf("cakupan tidak valid")
	}
	f.Search = strings.TrimSpace(q.Get("search"))
	if !utf8.ValidString(f.Search) || utf8.RuneCountInString(f.Search) > 100 {
		return f, fmt.Errorf("pencarian terlalu panjang")
	}
	return f, nil
}
func (s Server) Handler() http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/healthz" && r.Method == http.MethodGet {
			writeJSON(w, 200, map[string]any{"status": "ok", "service": "tahsin-next", "read_only": true, "enabled": s.Enabled})
			return
		}
		if r.URL.Path == "/api/v1/teacher-session" {
			if !s.Enabled || !s.TeacherEnabled || s.TeacherReader == nil || s.Mode != "mysql" {
				apiError(w, 404, "Tidak ditemukan.")
				return
			}
			if r.Method != "GET" {
				w.Header().Set("Allow", "GET")
				apiError(w, 405, "Pratinjau hanya menerima pembacaan data.")
				return
			}
			if !s.authorizedRoles(r, "ustadz") {
				apiError(w, 401, "Akses pratinjau tidak valid.")
				return
			}
			group, date, err := sessionread.ParseQuery(r.URL.Query())
			if err != nil {
				apiError(w, 422, "Kelompok atau tanggal tidak valid.")
				return
			}
			if !s.TeacherGroups[group] {
				apiError(w, 404, "Tidak ditemukan.")
				return
			}
			user, _ := strconv.ParseInt(r.Header.Get("X-Tahsin-User"), 10, 64)
			now := time.Now()
			if s.Now != nil {
				now = s.Now()
			}
			ctx, cancel := context.WithTimeout(r.Context(), 3*time.Second)
			defer cancel()
			state, err := s.TeacherReader.Read(ctx, user, group, date, now)
			if err != nil {
				var domain *sessionread.Error
				if errors.As(err, &domain) {
					apiError(w, domain.Status, domain.Message)
				} else {
					apiError(w, 503, "Data sesi belum dapat dibaca. Aplikasi utama tetap tersedia.")
				}
				return
			}
			writeJSON(w, 200, map[string]any{"status": "success", "data": state})
			return
		}
		if r.URL.Path != "/api/v1/families" || !s.Enabled {
			apiError(w, 404, "Tidak ditemukan.")
			return
		}
		if r.Method != http.MethodGet {
			w.Header().Set("Allow", "GET")
			apiError(w, 405, "Pratinjau hanya menerima pembacaan data.")
			return
		}
		if !s.authorized(r) {
			apiError(w, 401, "Akses pratinjau tidak valid.")
			return
		}
		filters, err := ParseFilters(r)
		if err != nil {
			apiError(w, 422, "Parameter pratinjau tidak valid.")
			return
		}
		ctx, cancel := context.WithTimeout(r.Context(), 3*time.Second)
		defer cancel()
		page, err := s.Store.List(ctx, filters)
		if err != nil {
			apiError(w, 503, "Data pratinjau belum dapat dibaca. Aplikasi utama tetap tersedia.")
			return
		}
		writeJSON(w, 200, map[string]any{"status": "ok", "data": page.Students, "meta": map[string]any{"page": filters.Page, "limit": filters.Limit, "total": page.Total, "scope": filters.Scope, "read_only": true, "data_source": s.Mode, "schema": page.Schema}})
	})
}
