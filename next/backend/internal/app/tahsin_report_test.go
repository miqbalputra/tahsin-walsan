package app

import (
	"reflect"
	"strings"
	"testing"
	"time"
)

func TestTahsinSessionScheduleHonorsFirstSundayAndHolidays(t *testing.T) {
	got := tahsinSessions("2026-01-01", "2026-01-31", map[string]string{"2026-01-18": "Libur"})
	want := []string{"2026-01-11", "2026-01-25"}
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("sessions = %#v, want %#v", got, want)
	}
	if session, err := tahsinSessionDate("2026-01-14"); err != nil || session != "2026-01-11" {
		t.Fatalf("sessionDate = %q, err=%v", session, err)
	}
}

func TestTahsinReportExcelExportPreservesConflictSourcesAndEscapesCells(t *testing.T) {
	report := tahsinReportResult{
		Filters: tahsinReportFilters{StartDate: "2026-01-01", EndDate: "2026-01-31"},
		Rows: []tahsinReportRow{{
			ID: 1, Name: "=HYPERLINK(\"https://example.test\")", ChildrenText: "<script>alert(1)</script>",
			Category: "reguler", Active: true, QualityLabel: "Perlu verifikasi",
			Details: []tahsinCell{{
				SessionDate: "2026-01-11", State: "conflict", Records: []tahsinRecord{
					{ID: 9, Date: "2026-01-11", Status: "H", Material: "Iqro", Level: "3", Page: "4-5", Result: "Lulus"},
					{ID: 10, Date: "2026-01-11", Status: "H", Material: "Iqro", Level: "3", Page: "6", Result: "Ulang"},
				},
			}},
		}},
	}
	got := renderTahsinReportExcel(report)
	for _, want := range []string{"&#39;=HYPERLINK", "&lt;script&gt;", "Perlu verifikasi", "#9 2026-01-11 Hadir", "#10 2026-01-11 Hadir", "Alfa tercatat"} {
		if !strings.Contains(got, want) {
			t.Errorf("export does not contain escaped/preserved value %q", want)
		}
	}
	if strings.Contains(got, "<script>") || strings.Contains(got, "<script>alert") {
		t.Fatal("untrusted report values were emitted as active HTML")
	}
}

func TestParseTahsinReportFiltersBoundsAndValidates(t *testing.T) {
	now := time.Date(2026, 2, 18, 9, 0, 0, 0, mustLocation("Asia/Jakarta"))
	filters, err := parseTahsinReportFilters(map[string][]string{"start_date": {"2026-01-01"}, "end_date": {"2026-02-18"}, "halaqoh_id": {"12"}, "kategori": {"askar"}, "aktif": {"1"}}, now)
	if err != nil || filters.GroupID != 12 || filters.Category != "askar" || filters.Active != "1" {
		t.Fatalf("parsed filters = %#v, err=%v", filters, err)
	}
	for _, query := range []map[string][]string{
		{"start_date": {"2026-02-19"}, "end_date": {"2026-02-18"}},
		{"halaqoh_id": {"01"}},
		{"kategori": {"other"}},
		{"aktif": {"2"}},
		{"kelengkapan": {"unknown"}},
		{"risk": {"unknown"}},
		{"search": {"a", "b"}},
	} {
		if _, err := parseTahsinReportFilters(query, now); err == nil {
			t.Errorf("accepted invalid filters: %#v", query)
		}
	}
}

func TestTahsinMissingFieldsAndResolution(t *testing.T) {
	base := tahsinRecord{ID: 1, WaliID: 5, GroupID: 2, Date: "2026-01-11", Status: "H", Material: "Iqro", Level: "3", Page: "8-9", Result: "Lulus"}
	if missing := tahsinMissingFields(base); len(missing) != 0 {
		t.Fatalf("complete Iqro record missing fields = %#v", missing)
	}
	incomplete := base
	incomplete.Result = ""
	if got := tahsinMissingFields(incomplete); !reflect.DeepEqual(got, []string{"hasil talaqqi"}) {
		t.Fatalf("missing fields = %#v", got)
	}
	sick := tahsinRecord{ID: 2, WaliID: 5, GroupID: 2, Date: "2026-01-11", Status: "S"}
	if got := tahsinMissingFields(sick); !reflect.DeepEqual(got, []string{"alasan/keterangan"}) {
		t.Fatalf("sick missing fields = %#v", got)
	}
	absent := tahsinRecord{ID: 3, WaliID: 5, GroupID: 2, Date: "2026-01-11", Status: "A"}
	if got := tahsinMissingFields(absent); len(got) != 0 {
		t.Fatalf("Alfa should be a complete status record, got %#v", got)
	}

	older := base
	older.ID, older.Page = 10, "7"
	newer := base
	newer.ID, newer.Page = 11, "8-9"
	resolved := resolveTahsinSession([]tahsinRecord{older, newer}, nil, nil, nil)
	if resolved.State != "conflict" || resolved.Record != nil {
		t.Fatalf("different records resolved as %#v", resolved)
	}
	resolved = resolveTahsinSession([]tahsinRecord{base, base}, nil, nil, nil)
	if resolved.State != "complete" || resolved.Record == nil || resolved.Record.ID != 1 {
		t.Fatalf("duplicate equal records resolved as %#v", resolved)
	}
	resolved = resolveTahsinSession([]tahsinRecord{base}, nil, nil, map[string]string{base.Date: "Libur"})
	if resolved.State != "invalid_date" {
		t.Fatalf("holiday record state = %q", resolved.State)
	}
	baseline := tahsinVerification{Fingerprint: tahsinFingerprint([]tahsinRecord{base}, false), SelectedID: 0}
	resolved = resolveTahsinSession([]tahsinRecord{base}, &baseline, nil, nil)
	if resolved.State != "baseline" || resolved.Record != nil {
		t.Fatalf("baseline verification = %#v", resolved)
	}
}

func TestTahsinReportNeverInventsAlfaAndUsesTrackedRoster(t *testing.T) {
	now := time.Date(2026, time.January, 25, 12, 0, 0, 0, mustLocation("Asia/Jakarta"))
	data := &tahsinReportDataset{
		Walis:         []tahsinReportWali{{ID: 1, Name: "Ibu Aminah", Category: "reguler", Active: true}},
		Children:      []tahsinReportChild{{WaliID: 1, Name: "Ahmad", Class: "2A"}},
		Groups:        map[int64]tahsinOwner{7: {GroupID: 7, GroupName: "Kelompok A", TeacherID: 12, Teacher: "Ustadzah"}},
		Current:       []tahsinOwner{{WaliID: 1, GroupID: 7, GroupName: "Kelompok A", TeacherID: 12, Teacher: "Ustadzah", Category: "reguler"}},
		History:       []tahsinOwner{{WaliID: 1, GroupID: 7, GroupName: "Kelompok A", TeacherID: 12, Teacher: "Ustadzah", Category: "reguler", ValidFrom: "2026-01-01 00:00:00.000000"}},
		Verifications: map[string]tahsinVerification{}, Baselines: map[int64]tahsinRecord{}, Holidays: map[string]string{}, Suspect: map[int64]bool{},
		TrackedFrom: "2026-01-01 00:00:00.000000", SchemaReady: true,
	}
	filters := tahsinReportFilters{StartDate: "2026-01-01", EndDate: "2026-01-25"}
	report := assembleTahsinReport(data, filters, "admin", 1, now)
	if len(report.Rows) != 1 || report.Summary.Expected != 3 || report.Summary.MissingPresensi != 3 || report.Summary.Alpha != 0 || report.Summary.TotalPresensi != 0 {
		t.Fatalf("empty tracked roster report = %#v", report.Summary)
	}
	if !report.Summary.Provisional || report.Rows[0].Quality != "missing" {
		t.Fatalf("incomplete report was not marked provisional/missing: %#v", report.Rows[0])
	}

	data.Records = []tahsinRecord{{ID: 99, WaliID: 1, GroupID: 7, Date: "2026-01-11", Status: "H", Material: "Iqro", Level: "3", Page: "8-9", Result: "Lulus"}}
	report = assembleTahsinReport(data, filters, "admin", 1, now)
	if report.Summary.Hadir != 1 || report.Summary.CompleteInput != 1 || report.Summary.MissingPresensi != 2 || report.Summary.Alpha != 0 {
		t.Fatalf("recorded report totals = %#v", report.Summary)
	}
}

func TestTahsinReportTeacherScopeAndUnverifiedOldRoster(t *testing.T) {
	now := time.Date(2026, time.January, 25, 12, 0, 0, 0, mustLocation("Asia/Jakarta"))
	data := &tahsinReportDataset{
		Walis:         []tahsinReportWali{{ID: 1, Name: "Wali", Category: "reguler", Active: true}},
		Groups:        map[int64]tahsinOwner{7: {GroupID: 7, GroupName: "A", TeacherID: 12, Teacher: "Guru A"}, 8: {GroupID: 8, GroupName: "B", TeacherID: 15, Teacher: "Guru B"}},
		Current:       []tahsinOwner{{WaliID: 1, GroupID: 8, GroupName: "B", TeacherID: 15, Teacher: "Guru B", Category: "reguler"}},
		History:       []tahsinOwner{{WaliID: 1, GroupID: 7, GroupName: "A", TeacherID: 12, Teacher: "Guru A", Category: "reguler", ValidFrom: "2026-01-01 00:00:00.000000", ValidUntil: "2026-01-18 00:00:00.000000"}},
		Records:       []tahsinRecord{{ID: 5, WaliID: 1, GroupID: 7, Date: "2026-01-11", Status: "A"}},
		Verifications: map[string]tahsinVerification{}, Baselines: map[int64]tahsinRecord{}, Holidays: map[string]string{}, Suspect: map[int64]bool{},
		TrackedFrom: "2026-01-15 00:00:00.000000", SchemaReady: true,
	}
	filters := tahsinReportFilters{StartDate: "2026-01-01", EndDate: "2026-01-25"}
	report := assembleTahsinReport(data, filters, "ustadz", 12, now)
	if len(report.Rows) != 1 || report.Rows[0].Alpha != 1 || report.Rows[0].Unverified != 1 {
		t.Fatalf("teacher history scope/unverified report = %#v", report.Rows)
	}
	if len(report.Rows[0].Details) != 1 || report.Rows[0].Details[0].Owner.TeacherID != 12 {
		t.Fatalf("teacher report leaked/attributed another teacher: %#v", report.Rows[0].Details)
	}
}
