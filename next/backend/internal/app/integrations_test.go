package app

import (
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
	"time"
)

func TestHermesKeyAndLikeHelpers(t *testing.T) {
	if !integrationKeyEqual("secret", "secret") || integrationKeyEqual("secret", "secreT") || integrationKeyEqual("", "secret") {
		t.Fatal("Hermes API key comparison accepted an invalid key")
	}
	if got := hermesLike("50%_!x"); got != "%50!%!_!!x%" {
		t.Fatalf("escaped search = %q", got)
	}
}

func TestHermesPaginationAndDateRangeValidation(t *testing.T) {
	page, limit, offset, err := hermesPage(url.Values{"page": {"3"}, "limit": {"150"}})
	if err != nil || page != 3 || limit != 100 || offset != 200 {
		t.Fatalf("pagination = %d,%d,%d err=%v", page, limit, offset, err)
	}
	for _, query := range []url.Values{{"page": {"0"}}, {"limit": {"1", "2"}}, {"page": {"01"}}} {
		if _, _, _, err := hermesPage(query); err == nil {
			t.Errorf("accepted invalid pagination %#v", query)
		}
	}
	start, end, err := hermesDateRange(url.Values{"start": {"2026-01-01"}, "start_date": {"2026-01-02"}, "end": {"2026-01-31"}}, "Asia/Jakarta")
	if err != nil || start != "2026-01-01" || end != "2026-01-31" {
		t.Fatalf("date aliases priority = %q,%q err=%v", start, end, err)
	}
	for _, query := range []url.Values{{"start": {"2026-02-01"}, "end": {"2026-01-01"}}, {"start": {"2026-02-30"}}, {"start": {"2026-01-01", "2026-01-02"}}} {
		if _, _, err := hermesDateRange(query, "Asia/Jakarta"); err == nil {
			t.Errorf("accepted invalid Hermes date range %#v", query)
		}
	}
}

func TestReminderEndpointsKeepKeyAndDayGuards(t *testing.T) {
	server := NewServer(Config{Environment: "development", N8NAPIKey: "n8n-test-key"}, nil)
	handler := server.Handler()
	unauthorized := httptest.NewRecorder()
	handler.ServeHTTP(unauthorized, httptest.NewRequest("GET", "/api/daily-reminder.php", nil))
	if unauthorized.Code != 401 || !strings.Contains(unauthorized.Body.String(), "Unauthorized") {
		t.Fatalf("unauthorized reminder = %d %s", unauthorized.Code, unauthorized.Body.String())
	}

	now := time.Now().In(mustLocation("Asia/Jakarta"))
	weekday := now.Weekday()
	for _, test := range []struct {
		path string
		skip bool
	}{
		{path: "/api/notifications-pending.php", skip: weekday != time.Sunday},
		{path: "/api/daily-reminder.php", skip: weekday == time.Sunday},
	} {
		req := httptest.NewRequest("GET", test.path, nil)
		req.Header.Set("X-API-Key", "n8n-test-key")
		recorder := httptest.NewRecorder()
		handler.ServeHTTP(recorder, req)
		if test.skip {
			if recorder.Code != 200 || !strings.Contains(recorder.Body.String(), `"status":"skip"`) {
				t.Errorf("schedule guard for %s = %d %s", test.path, recorder.Code, recorder.Body.String())
			}
		} else if recorder.Code != 503 || !strings.Contains(recorder.Body.String(), "Database belum siap") {
			t.Errorf("authorized reminder should reach database readiness for %s = %d %s", test.path, recorder.Code, recorder.Body.String())
		}
	}
}

func TestReminderAssemblerUsesCurrentRosterOnlyForCurrentSession(t *testing.T) {
	now := time.Date(2026, time.January, 25, 12, 0, 0, 0, mustLocation("Asia/Jakarta"))
	data := &tahsinReportDataset{
		Walis:   []tahsinReportWali{{ID: 1, Name: "Ibu Aminah", Category: "reguler", Active: true}},
		Groups:  map[int64]tahsinOwner{7: {GroupID: 7, GroupName: "Kelompok A", TeacherID: 12, Teacher: "Ustadzah", Phone: "08123456789"}},
		Current: []tahsinOwner{{WaliID: 1, GroupID: 7, GroupName: "Kelompok A", TeacherID: 12, Teacher: "Ustadzah", Phone: "08123456789", Category: "reguler"}},
		History: []tahsinOwner{}, Records: []tahsinRecord{}, Verifications: map[string]tahsinVerification{}, Baselines: map[int64]tahsinRecord{}, Holidays: map[string]string{}, Suspect: map[int64]bool{},
	}
	filters := tahsinReportFilters{StartDate: "2026-01-01", EndDate: "2026-01-25"}
	withoutFallback := assembleTahsinReport(data, filters, "admin", 0, now)
	if len(withoutFallback.Rows) != 1 || len(withoutFallback.Rows[0].Details) != 0 || withoutFallback.Summary.Expected != 0 {
		t.Fatalf("ordinary historical report inferred a current roster: %#v", withoutFallback)
	}
	withFallback := assembleTahsinReportWithReminderSession(data, filters, "admin", 0, now, "2026-01-25")
	if len(withFallback.Rows) != 1 || len(withFallback.Rows[0].Details) != 1 {
		t.Fatalf("current session roster fallback was not included: %#v", withFallback.Rows)
	}
	cell := withFallback.Rows[0].Details[0]
	if !cell.Expected || cell.VerifiedRoster || cell.RosterSource != "Roster aktif untuk pekan berjalan" {
		t.Fatalf("current roster fallback was mislabeled: %#v", cell)
	}
	groups := reminderGroupsFromReport(withFallback, now, "2026-01-25")
	if len(groups) != 1 || groups[0]["roster_basis"] != "current_roster" || groups[0]["belum_diisi"].(int) != 1 {
		t.Fatalf("reminder group = %#v", groups)
	}
}
