package preview

import (
	"bytes"
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"tahsin.local/next/internal/sessionread"
	"testing"
	"time"
)

const writeTestSecret = "staging-writer-secret-0123456789abcdef"

type writerStub struct{ err error }

func (w writerStub) Execute(context.Context, int64, int64, sessionread.WriteRequest, string, time.Time) (any, error) {
	return map[string]any{"state": "official"}, w.err
}
func writeRequest() *http.Request {
	body := []byte(`{"action":"save","operation_id":"write_signature_test_001","halaqoh_id":3,"tanggal":"2026-10-18","wali_id":10,"payload":{"status":"A"},"draft_revision":0}`)
	r := httptest.NewRequest("POST", "/api/v1/autosave?halaqoh_id=3&tanggal=2026-10-18", bytes.NewReader(body))
	r.Header.Set("X-Tahsin-User", "7")
	r.Header.Set("X-Tahsin-Role", "ustadz")
	r.Header.Set("X-Tahsin-Time", "1700000000")
	r.Header.Set("X-Tahsin-Writer-Generation", "2")
	r.Header.Set("X-Tahsin-Request-Hash", strings.Repeat("a", 64))
	r.Header.Set("X-Tahsin-Write-Signature", WriteSignature([]byte(writeTestSecret), r.Method, r.URL.Path, r.URL.RawQuery, "7", "ustadz", "1700000000", "2", strings.Repeat("a", 64), body))
	return r
}
func TestWriterSignatureAndIsolation(t *testing.T) {
	for _, tc := range []struct {
		name   string
		mutate func(*Server, *http.Request)
		status int
	}{
		{"valid", func(*Server, *http.Request) {}, 200},
		{"disabled", func(s *Server, _ *http.Request) { s.WriterEnabled = false }, 404},
		{"global off", func(s *Server, _ *http.Request) { s.Enabled = false }, 404},
		{"demo", func(s *Server, _ *http.Request) { s.Mode = "demo" }, 404},
		{"outside pilot", func(s *Server, _ *http.Request) { s.WriteGroups = map[int64]bool{} }, 404},
		{"changed generation", func(_ *Server, r *http.Request) { r.Header.Set("X-Tahsin-Writer-Generation", "3") }, 401},
		{"changed receipt hash", func(_ *Server, r *http.Request) { r.Header.Set("X-Tahsin-Request-Hash", strings.Repeat("b", 64)) }, 401},
		{"changed actor", func(_ *Server, r *http.Request) { r.Header.Set("X-Tahsin-User", "8") }, 401},
		{"role escalation", func(_ *Server, r *http.Request) { r.Header.Set("X-Tahsin-Role", "admin") }, 401},
		{"changed body", func(_ *Server, r *http.Request) { r.Body = http.NoBody }, 401},
		{"read key cannot write", func(s *Server, _ *http.Request) { s.WriteSecret = []byte(testSecret) }, 401},
		{"expired", func(s *Server, _ *http.Request) { s.Now = func() time.Time { return testNow.Add(31 * time.Second) } }, 401},
		{"SQL error hidden", func(s *Server, _ *http.Request) { s.Writer = writerStub{errors.New("private-db-password")} }, 503},
	} {
		t.Run(tc.name, func(t *testing.T) {
			s := testServer()
			s.Mode = "mysql"
			s.WriterEnabled = true
			s.WriteSecret = []byte(writeTestSecret)
			s.WriteGroups = map[int64]bool{3: true}
			s.Writer = writerStub{}
			r := writeRequest()
			tc.mutate(&s, r)
			w := response(s, r)
			if w.Code != tc.status || strings.Contains(w.Body.String(), "private-db-password") {
				t.Fatalf("bad response %d %s", w.Code, w.Body.String())
			}
		})
	}
}
