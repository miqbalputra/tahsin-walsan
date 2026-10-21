package preview

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"os"
	"reflect"
	"strings"
	"testing"
	"time"

	"tahsin.local/next/internal/sessionread"
)

type teacherStub struct{ err error }

func (s teacherStub) Read(_ context.Context, _, _ int64, _ string, _ time.Time) (sessionread.State, error) {
	return sessionread.State{Entries: []sessionread.Entry{}}, s.err
}
func teacherRequest(method, path string) *http.Request {
	r := signed(method, path)
	r.Header.Set("X-Tahsin-Role", "ustadz")
	r.Header.Set("X-Tahsin-Signature", Signature([]byte(testSecret), method, r.URL.Path, r.URL.RawQuery, "7", "ustadz", r.Header.Get("X-Tahsin-Time")))
	return r
}
func TestTeacherReadBoundaries(t *testing.T) {
	for _, tc := range []struct {
		name   string
		mutate func(*Server, *http.Request)
		code   int
	}{
		{"signed teacher", func(*Server, *http.Request) {}, 200},
		{"feature off", func(s *Server, _ *http.Request) { s.TeacherEnabled = false }, 404},
		{"global off", func(s *Server, _ *http.Request) { s.Enabled = false }, 404},
		{"demo denied", func(s *Server, _ *http.Request) { s.Mode = "demo" }, 404},
		{"group outside pilot", func(s *Server, _ *http.Request) { s.TeacherGroups = map[int64]bool{} }, 404},
		{"signature missing", func(_ *Server, r *http.Request) { r.Header.Del("X-Tahsin-Signature") }, 401},
		{"identity changed", func(_ *Server, r *http.Request) { r.Header.Set("X-Tahsin-User", "9") }, 401},
		{"query changed", func(_ *Server, r *http.Request) { r.URL.RawQuery = "halaqoh_id=4&tanggal=2026-10-18" }, 401},
		{"wrong assignment", func(s *Server, _ *http.Request) {
			s.TeacherReader = teacherStub{&sessionread.Error{Status: 403, Message: "Kelompok bukan penugasan Anda."}}
		}, 403},
		{"sql failure", func(s *Server, _ *http.Request) {
			s.TeacherReader = teacherStub{errors.New("private-password-and-sql")}
		}, 503},
	} {
		t.Run(tc.name, func(t *testing.T) {
			s := testServer()
			s.Mode = "mysql"
			s.TeacherEnabled = true
			s.TeacherGroups = map[int64]bool{3: true}
			s.TeacherReader = teacherStub{}
			r := teacherRequest("GET", "/api/v1/teacher-session?halaqoh_id=3&tanggal=2026-10-18")
			tc.mutate(&s, r)
			w := response(s, r)
			if w.Code != tc.code || strings.Contains(w.Body.String(), "private-password") {
				t.Fatalf("bad response %d %s", w.Code, w.Body.String())
			}
			if w.Header().Get("Cache-Control") != "no-store, private" {
				t.Fatal("session response cacheable")
			}
		})
	}
	s := testServer()
	s.Mode = "mysql"
	s.TeacherEnabled = true
	s.TeacherGroups = map[int64]bool{3: true}
	s.TeacherReader = teacherStub{}
	for _, method := range []string{"POST", "PUT", "PATCH", "DELETE", "HEAD", "OPTIONS"} {
		if response(s, teacherRequest(method, "/api/v1/teacher-session")).Code != 405 {
			t.Fatal("write method accepted")
		}
	}
	if response(s, signed("GET", "/api/v1/teacher-session?halaqoh_id=3&tanggal=2026-10-18")).Code != 401 {
		t.Fatal("admin signature accepted on teacher API")
	}
	for _, q := range []string{"halaqoh_id=03&tanggal=2026-10-18", "halaqoh_id=3&tanggal=2026-02-30", "halaqoh_id=3&tanggal=2026-10-18&user_id=9", "halaqoh_id=3&halaqoh_id=4&tanggal=2026-10-18"} {
		if response(s, teacherRequest("GET", "/api/v1/teacher-session?"+q)).Code != 422 {
			t.Fatal("ambiguous query accepted")
		}
	}
}

func TestTeacherPHPContractIntegration(t *testing.T) {
	if os.Getenv("TAHSIN_SESSION_MYSQL_TEST") != "1" {
		t.Skip("requires guarded local synthetic PHP contract fixture")
	}
	if os.Getenv("TAHSIN_NEXT_DB_HOST") != "127.0.0.1" || os.Getenv("TAHSIN_NEXT_DB_PORT") != "13319" || !strings.HasPrefix(os.Getenv("TAHSIN_NEXT_DB_DATABASE"), "tahsin_test_autosave_") {
		t.Fatal("owned local synthetic fixture required")
	}
	b, e := os.ReadFile(os.Getenv("TAHSIN_SESSION_EXPECTED_FILE"))
	if e != nil {
		t.Fatal(e)
	}
	var fixture struct {
		Now   string `json:"now"`
		Cases []struct {
			Name   string          `json:"name"`
			User   int64           `json:"user"`
			Group  int64           `json:"group"`
			Date   string          `json:"date"`
			Status int             `json:"status"`
			Data   json.RawMessage `json:"data"`
		} `json:"cases"`
	}
	if e = json.Unmarshal(b, &fixture); e != nil {
		t.Fatal(e)
	}
	now, e := time.Parse(time.RFC3339, fixture.Now)
	if e != nil {
		t.Fatal(e)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	store, e := OpenMySQL(ctx)
	if e != nil {
		t.Fatal(e)
	}
	defer store.DB.Close()
	reader := sessionread.Reader{DB: store.DB}
	var timezone string
	if e = store.DB.QueryRowContext(ctx, "SELECT @@session.time_zone").Scan(&timezone); e != nil || timezone != "+07:00" {
		t.Fatal("PDO-compatible timestamp timezone missing", e)
	}
	for _, c := range fixture.Cases {
		t.Run(c.Name, func(t *testing.T) {
			actual, err := reader.Read(ctx, c.User, c.Group, c.Date, now)
			if c.Status != 200 {
				var domain *sessionread.Error
				if !errors.As(err, &domain) || domain.Status != c.Status {
					t.Fatalf("want %d got %v", c.Status, err)
				}
				return
			}
			if err != nil {
				t.Fatal(err)
			}
			ab, err := json.Marshal(actual)
			if err != nil {
				t.Fatal(err)
			}
			var want, got any
			json.Unmarshal(c.Data, &want)
			json.Unmarshal(ab, &got)
			if !reflect.DeepEqual(want, got) {
				t.Fatalf("PHP/Go contract differs\nPHP %s\nGO  %s", c.Data, ab)
			}
		})
	}
}
