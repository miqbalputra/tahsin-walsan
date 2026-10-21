package app

import (
	"net/url"
	"testing"
	"time"
)

func TestParseAttendanceReportFiltersDefaultsAndBounds(t *testing.T) {
	now := time.Date(2026, 10, 22, 8, 0, 0, 0, time.FixedZone("UTC+7", 7*60*60))
	f, err := parseAttendanceReportFilters(url.Values{}, now)
	if err != nil {
		t.Fatal(err)
	}
	if f.StartDate != "2026-10-01" || f.EndDate != "2026-10-22" || f.Page != 1 || f.Limit != 50 {
		t.Fatalf("unexpected defaults: %+v", f)
	}
	f, err = parseAttendanceReportFilters(url.Values{"start_date": {"2026-01-01"}, "end_date": {"2026-01-31"}, "halaqoh_id": {"4"}, "page": {"2"}, "limit": {"150"}}, now)
	if err != nil {
		t.Fatal(err)
	}
	if f.GroupID != 4 || f.Page != 2 || f.Limit != 100 {
		t.Fatalf("unexpected parsed filters: %+v", f)
	}
}

func TestParseAttendanceReportFiltersRejectsInvalidOrRepeatedValues(t *testing.T) {
	now := time.Date(2026, 10, 22, 8, 0, 0, 0, time.FixedZone("UTC+7", 7*60*60))
	for _, values := range []url.Values{
		{"start_date": {"2026-01-02"}, "end_date": {"2026-01-01"}},
		{"end_date": {"2026-10-23"}},
		{"halaqoh_id": {"2", "3"}},
		{"page": {"0"}},
		{"start_date": {"2026-01-01", "2026-01-02"}},
	} {
		if _, err := parseAttendanceReportFilters(values, now); err == nil {
			t.Fatalf("accepted invalid filter: %v", values)
		}
	}
}

func TestAttendanceReportWhereUsesBoundFiltersAndEscapedTablePlaceholder(t *testing.T) {
	where, args := attendanceReportWhere(AttendanceReportFilters{StartDate: "2026-01-01", EndDate: "2026-01-31", GroupID: 8, WaliID: 9, Class: "2A", Search: "Amin"})
	if len(args) != 7 || args[0] != "2026-01-01" || args[2] != int64(8) || args[3] != int64(9) || args[4] != "2A" || args[5] != "%Amin%" || args[6] != "%Amin%" {
		t.Fatalf("unexpected query args: %#v", args)
	}
	if where == "" || len([]rune(where)) == 0 {
		t.Fatal("empty report query predicate")
	}
}
