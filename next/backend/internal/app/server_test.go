package app

import (
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestConfigRequiresExistingDatabaseAndSecureProductionPassword(t *testing.T) {
	base := map[string]string{"APP_ENV": "production", "DB_DATABASE": "db_tahsinbapak", "APP_EXPECTED_DB_DATABASE": "db_tahsinbapak", "DB_USERNAME": "tahsin_app", "DB_PASSWORD": "secret", "APP_STATIC_DIR": "/app/web", "APP_DATA_DIR": "/var/lib/tahsin"}
	load := func(overrides map[string]string) error {
		t.Helper()
		_, returnErr := LoadConfigFrom(func(key string) string {
			if value, ok := overrides[key]; ok {
				return value
			}
			return base[key]
		})
		return returnErr
	}
	if err := load(nil); err != nil {
		t.Fatal(err)
	}
	config, err := LoadConfigFrom(func(key string) string { return base[key] })
	if err != nil || config.ProductionReady {
		t.Fatal("production release gate must default closed")
	}
	if err := load(map[string]string{"DB_PASSWORD": ""}); err == nil {
		t.Fatal("production accepted an empty database password")
	}
	if err := load(map[string]string{"DB_DATABASE": "db-tahsinbpk"}); err == nil {
		t.Fatal("database name was not constrained to an identifier")
	}
	if err := load(map[string]string{"DB_DATABASE": "tahsin_test"}); err == nil {
		t.Fatal("production accepted a database different from the approved legacy schema")
	}
	if err := load(map[string]string{"APP_EXPECTED_DB_DATABASE": ""}); err == nil {
		t.Fatal("production accepted a missing expected database name")
	}
}

func TestLegacyIntegrationPathDoesNotFallThroughToSPA(t *testing.T) {
	server := NewServer(Config{StaticDir: t.TempDir(), Environment: "development", CookieName: "tahsin_session", SessionDays: 30}, nil)
	handler := server.Handler()
	recorder := httptest.NewRecorder()
	handler.ServeHTTP(recorder, httptest.NewRequest("GET", "/api/hermes.php?action=status", nil))
	if recorder.Code != 503 || !contains(recorder.Body.String(), "API belum dikonfigurasi") {
		t.Fatalf("legacy API returned %d %s", recorder.Code, recorder.Body.String())
	}
	preflight := httptest.NewRecorder()
	handler.ServeHTTP(preflight, httptest.NewRequest("OPTIONS", "/api/hermes.php", nil))
	if preflight.Code != 204 {
		t.Fatalf("CORS preflight status = %d, want 204", preflight.Code)
	}
}

func TestHermesIntegrationAuthenticatesBeforeDatabaseAccess(t *testing.T) {
	server := NewServer(Config{Environment: "development", HermesAPIKey: "hermes-test-key", N8NAPIKey: "n8n-test-key"}, nil)
	handler := server.Handler()
	for _, test := range []struct {
		name   string
		key    string
		status int
	}{
		{name: "missing key", status: 401},
		{name: "wrong key", key: "wrong", status: 401},
		{name: "correct key reaches readiness", key: "hermes-test-key", status: 503},
	} {
		t.Run(test.name, func(t *testing.T) {
			req := httptest.NewRequest("GET", "/api/hermes.php?action=status", nil)
			if test.key != "" {
				req.Header.Set("X-API-Key", test.key)
			}
			recorder := httptest.NewRecorder()
			handler.ServeHTTP(recorder, req)
			if recorder.Code != test.status {
				t.Fatalf("status = %d, want %d; body=%s", recorder.Code, test.status, recorder.Body.String())
			}
		})
	}
}

func TestStaticRouterServesLegacyPageFallbackButNotAPIsOrMissingAssets(t *testing.T) {
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "index.html"), []byte("portal-index"), 0600); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Join(dir, "assets"), 0700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "assets", "app.js"), []byte("bundle"), 0600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "assets", "app-a1b2c3d4.js"), []byte("hashed-bundle"), 0600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "app.webmanifest"), []byte(`{"name":"Tahsin"}`), 0600); err != nil {
		t.Fatal(err)
	}
	cfg := Config{StaticDir: dir, Environment: "development", CookieName: "tahsin_session", SessionDays: 30}
	server := NewServer(cfg, nil)
	handler := server.Handler()
	for _, test := range []struct {
		path string
		code int
		body string
	}{{"/", 200, "portal-index"}, {"/dashboard.php", 200, "portal-index"}, {"/assets/app.js", 200, "bundle"}, {"/assets/app-a1b2c3d4.js", 200, "hashed-bundle"}, {"/app.webmanifest", 200, `{"name":"Tahsin"}`}, {"/assets/missing.js", 404, ""}, {"/api/v1/missing", 404, "Endpoint API tidak ditemukan."}} {
		t.Run(test.path, func(t *testing.T) {
			recorder := httptest.NewRecorder()
			handler.ServeHTTP(recorder, httptest.NewRequest("GET", test.path, nil))
			if recorder.Code != test.code {
				t.Fatalf("status = %d, want %d", recorder.Code, test.code)
			}
			if test.body != "" && !contains(recorder.Body.String(), test.body) {
				t.Fatalf("body %q does not contain %q", recorder.Body.String(), test.body)
			}
			if test.path == "/assets/app-a1b2c3d4.js" && !contains(recorder.Header().Get("Cache-Control"), "immutable") {
				t.Fatal("hashed asset was not marked immutable")
			}
			if test.path == "/assets/app.js" && !contains(recorder.Header().Get("Cache-Control"), "no-store") {
				t.Fatal("unhashed asset was cached indefinitely")
			}
			if test.path == "/app.webmanifest" && recorder.Header().Get("Content-Type") != "application/manifest+json; charset=utf-8" {
				t.Fatalf("web manifest content type = %q", recorder.Header().Get("Content-Type"))
			}
		})
	}
}

func TestProductionReleaseGateBlocksAPIsUntilExplicitlyOpened(t *testing.T) {
	config := Config{Environment: "production", ProductionReady: false, StaticDir: t.TempDir(), CookieName: "tahsin_session", SessionDays: 30}
	server := NewServer(config, nil)
	req := httptest.NewRequest("POST", "/api/v1/attendance/snapshot", nil)
	recorder := httptest.NewRecorder()
	server.Handler().ServeHTTP(recorder, req)
	if recorder.Code != 503 || !contains(recorder.Body.String(), "API Go+Vue belum dibuka") {
		t.Fatalf("closed release gate returned %d %s", recorder.Code, recorder.Body.String())
	}
}

func TestParticipantEndpointsRequireAuthentication(t *testing.T) {
	server := NewServer(Config{Environment: "development", CookieName: "tahsin_session", SessionDays: 30}, nil)
	for _, test := range []struct{ method, path string }{
		{method: "GET", path: "/api/v1/participants"},
		{method: "POST", path: "/api/v1/participants"},
		{method: "PATCH", path: "/api/v1/participants/12"},
		{method: "DELETE", path: "/api/v1/participants/12"},
	} {
		t.Run(test.method+" "+test.path, func(t *testing.T) {
			recorder := httptest.NewRecorder()
			server.Handler().ServeHTTP(recorder, httptest.NewRequest(test.method, test.path, nil))
			if recorder.Code != 401 {
				t.Fatalf("status = %d, want 401; body=%s", recorder.Code, recorder.Body.String())
			}
		})
	}
}

func TestUserAdministrationEndpointsRequireAuthentication(t *testing.T) {
	server := NewServer(Config{Environment: "development", CookieName: "tahsin_session", SessionDays: 30}, nil)
	for _, test := range []struct{ method, path string }{
		{method: "GET", path: "/api/v1/users"},
		{method: "POST", path: "/api/v1/users"},
		{method: "PATCH", path: "/api/v1/users/12"},
	} {
		t.Run(test.method+" "+test.path, func(t *testing.T) {
			recorder := httptest.NewRecorder()
			server.Handler().ServeHTTP(recorder, httptest.NewRequest(test.method, test.path, nil))
			if recorder.Code != 401 {
				t.Fatalf("status = %d, want 401; body=%s", recorder.Code, recorder.Body.String())
			}
		})
	}
}

func TestAdministrationReadEndpointsRequireAuthentication(t *testing.T) {
	server := NewServer(Config{Environment: "development", CookieName: "tahsin_session", SessionDays: 30}, nil)
	for _, path := range []string{"/api/v1/dashboard", "/api/v1/logs", "/api/v1/settings/report", "/api/v1/reports/tahsin", "/api/v1/capaian", "/api/v1/data-induk", "/api/v1/backups"} {
		t.Run(path, func(t *testing.T) {
			recorder := httptest.NewRecorder()
			server.Handler().ServeHTTP(recorder, httptest.NewRequest("GET", path, nil))
			if recorder.Code != 401 {
				t.Fatalf("status = %d, want 401; body=%s", recorder.Code, recorder.Body.String())
			}
		})
	}
	for _, target := range []struct{ method, path string }{
		{method: "PATCH", path: "/api/v1/settings/report"},
		{method: "POST", path: "/api/v1/backups"},
		{method: "POST", path: "/api/v1/backups/restore-preview"},
		{method: "POST", path: "/api/v1/backups/restore"},
		{method: "POST", path: "/api/v1/capaian"},
		{method: "POST", path: "/api/v1/data-induk/sync"},
		{method: "POST", path: "/api/v1/data-induk/conflicts/1"},
		{method: "GET", path: "/api/v1/backups/backup_20261022.zip"},
	} {
		recorder := httptest.NewRecorder()
		server.Handler().ServeHTTP(recorder, httptest.NewRequest(target.method, target.path, nil))
		if recorder.Code != 401 {
			t.Fatalf("%s %s status = %d, want 401; body=%s", target.method, target.path, recorder.Code, recorder.Body.String())
		}
	}
	recorder := httptest.NewRecorder()
	server.Handler().ServeHTTP(recorder, httptest.NewRequest("GET", "/backup-restore.php?export=1", nil))
	if recorder.Code != 401 {
		t.Fatalf("legacy backup export status = %d, want 401", recorder.Code)
	}
	recorder = httptest.NewRecorder()
	server.Handler().ServeHTTP(recorder, httptest.NewRequest("GET", "/backup-restore.php", nil))
	if recorder.Code != http.StatusSeeOther || recorder.Header().Get("Location") != "/backup" {
		t.Fatalf("legacy backup page status=%d location=%q", recorder.Code, recorder.Header().Get("Location"))
	}
	recorder = httptest.NewRecorder()
	server.Handler().ServeHTTP(recorder, httptest.NewRequest("POST", "/api/v1/reports/tahsin/verifications", strings.NewReader(`{}`)))
	if recorder.Code != 401 {
		t.Fatalf("Tahsin verification status = %d, want 401; body=%s", recorder.Code, recorder.Body.String())
	}
	recorder = httptest.NewRecorder()
	server.Handler().ServeHTTP(recorder, httptest.NewRequest("GET", "/export-excel.php", nil))
	if recorder.Code != 401 {
		t.Fatalf("spreadsheet export status = %d, want 401; body=%s", recorder.Code, recorder.Body.String())
	}
}

func TestOperationsEndpointsRequireAuthentication(t *testing.T) {
	server := NewServer(Config{Environment: "development", CookieName: "tahsin_session", SessionDays: 30}, nil)
	for _, test := range []struct{ method, path string }{
		{method: "GET", path: "/api/v1/holidays"},
		{method: "POST", path: "/api/v1/holidays"},
		{method: "DELETE", path: "/api/v1/holidays/5"},
		{method: "GET", path: "/api/v1/announcements"},
		{method: "GET", path: "/api/v1/announcements/active"},
		{method: "POST", path: "/api/v1/announcements"},
		{method: "PATCH", path: "/api/v1/announcements/5"},
	} {
		t.Run(test.method+" "+test.path, func(t *testing.T) {
			recorder := httptest.NewRecorder()
			server.Handler().ServeHTTP(recorder, httptest.NewRequest(test.method, test.path, nil))
			if recorder.Code != 401 {
				t.Fatalf("status = %d, want 401; body=%s", recorder.Code, recorder.Body.String())
			}
		})
	}
}

func contains(text, part string) bool {
	for i := 0; i+len(part) <= len(text); i++ {
		if text[i:i+len(part)] == part {
			return true
		}
	}
	return false
}
