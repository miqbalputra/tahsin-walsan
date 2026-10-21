package preview

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"
)

const testSecret = "0123456789abcdef0123456789abcdef"

var testNow = time.Unix(1700000000, 0)

func testServer() Server {
	return Server{Enabled: true, Secret: []byte(testSecret), Store: DemoStore{}, Mode: "demo", Now: func() time.Time { return testNow }}
}
func signed(method, path string) *http.Request {
	r := httptest.NewRequest(method, path, nil)
	stamp := strconv.FormatInt(testNow.Unix(), 10)
	r.Header.Set("X-Tahsin-User", "7")
	r.Header.Set("X-Tahsin-Role", "admin")
	r.Header.Set("X-Tahsin-Time", stamp)
	r.Header.Set("X-Tahsin-Signature", Signature([]byte(testSecret), method, r.URL.Path, r.URL.RawQuery, "7", "admin", stamp))
	return r
}
func response(s Server, r *http.Request) *httptest.ResponseRecorder {
	w := httptest.NewRecorder()
	s.Handler().ServeHTTP(w, r)
	return w
}

func TestAccessAndTampering(t *testing.T) {
	for _, tc := range []struct {
		name   string
		mutate func(*Server, *http.Request)
		code   int
	}{
		{"enabled", func(_ *Server, _ *http.Request) {}, 200},
		{"disabled", func(s *Server, _ *http.Request) { s.Enabled = false }, 404},
		{"missing signature", func(_ *Server, r *http.Request) { r.Header.Del("X-Tahsin-Signature") }, 401},
		{"changed user", func(_ *Server, r *http.Request) { r.Header.Set("X-Tahsin-User", "9") }, 401},
		{"changed role", func(_ *Server, r *http.Request) { r.Header.Set("X-Tahsin-Role", "pj_tahfidz") }, 401},
		{"teacher", func(_ *Server, r *http.Request) { r.Header.Set("X-Tahsin-Role", "ustadz") }, 401},
		{"expired", func(s *Server, _ *http.Request) { s.Now = func() time.Time { return testNow.Add(31 * time.Second) } }, 401},
		{"future", func(s *Server, _ *http.Request) { s.Now = func() time.Time { return testNow.Add(-6 * time.Second) } }, 401},
		{"changed query", func(_ *Server, r *http.Request) { r.URL.RawQuery = "scope=all" }, 401},
		{"short key", func(s *Server, _ *http.Request) { s.Secret = []byte("short") }, 401},
	} {
		t.Run(tc.name, func(t *testing.T) {
			s := testServer()
			r := signed("GET", "/api/v1/families?scope=active")
			tc.mutate(&s, r)
			w := response(s, r)
			if w.Code != tc.code {
				t.Fatalf("got %d want %d", w.Code, tc.code)
			}
			if w.Header().Get("Cache-Control") != "no-store, private" {
				t.Fatal("sensitive response cacheable")
			}
		})
	}
}
func TestOnlyReadsAllowed(t *testing.T) {
	for _, method := range []string{"POST", "PUT", "PATCH", "DELETE", "OPTIONS", "HEAD"} {
		t.Run(method, func(t *testing.T) {
			w := response(testServer(), signed(method, "/api/v1/families"))
			if w.Code != 405 || w.Header().Get("Allow") != "GET" {
				t.Fatal("write method not rejected")
			}
		})
	}
}
func TestFiltersAndIdentity(t *testing.T) {
	for _, query := range []string{"limit=101", "limit=-1", "page=10001", "page=x", "scope=unknown", "page=1&page=2", "unexpected=1", "search=" + strings.Repeat("a", 101)} {
		w := response(testServer(), signed("GET", "/api/v1/families?"+query))
		if w.Code != 422 {
			t.Fatalf("query %q returned %d", query, w.Code)
		}
	}
	for _, tc := range []struct {
		query string
		count int
		total int
	}{{"", 2, 2}, {"scope=all", 3, 3}, {"scope=all&search=Ibu%20Contoh", 2, 2}, {"scope=all&limit=1&page=2", 1, 3}, {"search=not-found", 0, 0}} {
		w := response(testServer(), signed("GET", "/api/v1/families?"+tc.query))
		var body struct {
			Data []Student `json:"data"`
			Meta struct {
				Total    int  `json:"total"`
				ReadOnly bool `json:"read_only"`
			} `json:"meta"`
		}
		if err := json.Unmarshal(w.Body.Bytes(), &body); err != nil {
			t.Fatal(err)
		}
		if w.Code != 200 || len(body.Data) != tc.count || body.Meta.Total != tc.total || !body.Meta.ReadOnly {
			t.Fatalf("bad data for %q: %s", tc.query, w.Body.String())
		}
	}
	w := response(testServer(), signed("GET", "/api/v1/families?search=000001"))
	if !strings.Contains(w.Body.String(), `"nis":"000001"`) || !strings.Contains(w.Body.String(), `"wali_santri_id":101`) {
		t.Fatal("stable IDs or leading zeroes lost")
	}
}

type failingStore struct{}

func (failingStore) List(context.Context, Filters) (Page, error) {
	return Page{}, errors.New("password-private-sql-error")
}
func TestFailureIsContained(t *testing.T) {
	s := testServer()
	s.Store = failingStore{}
	w := response(s, signed("GET", "/api/v1/families"))
	if w.Code != 503 || strings.Contains(w.Body.String(), "private-sql") {
		t.Fatal("backend failure leaked internals")
	}
	if response(s, signed("GET", "/healthz")).Code != 200 {
		t.Fatal("health unavailable")
	}
}
func TestConcurrentReads(t *testing.T) {
	var wg sync.WaitGroup
	for range 50 {
		wg.Go(func() {
			if response(testServer(), signed("GET", "/api/v1/families")).Code != 200 {
				t.Error("read failed")
			}
		})
	}
	wg.Wait()
}
func TestGrantBoundaries(t *testing.T) {
	for _, grants := range [][]string{{"GRANT USAGE ON *.* TO user", "GRANT SELECT ON demo.* TO user"}, {"GRANT SELECT, SHOW VIEW ON demo.* TO user"}} {
		if err := ValidateGrants(grants); err != nil {
			t.Fatal(err)
		}
	}
	for _, grants := range [][]string{nil, {"GRANT ALL PRIVILEGES ON *.* TO root"}, {"GRANT SELECT, UPDATE ON demo.* TO user"}, {"GRANT SELECT ON demo.* TO user WITH GRANT OPTION"}, {"GRANT `readonly_role` TO user"}, {"GRANT SELECT, FILE ON *.* TO user"}} {
		if ValidateGrants(grants) == nil {
			t.Fatalf("dangerous grants accepted: %v", grants)
		}
	}
}
