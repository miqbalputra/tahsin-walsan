package app

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"net/url"
	"testing"
)

func TestLoadDataIndukConfigRequiresAllFields(t *testing.T) {
	values := map[string]string{
		"DATA_INDUK_ENABLED": "true", "DATA_INDUK_BASE_URL": "https://data.example.test/",
		"DATA_INDUK_API_KEY": "unit-test-key", "DATA_INDUK_UNIT_ID": "unit-1",
	}
	config, err := loadDataIndukConfig(func(key string) string { return values[key] })
	if err != nil || !config.configured() || config.StaleAfter.Minutes() != 60 {
		t.Fatalf("valid config was rejected or default stale time changed: %#v, %v", config, err)
	}
	values["DATA_INDUK_API_KEY"] = ""
	config, err = loadDataIndukConfig(func(key string) string { return values[key] })
	if err != nil || config.configured() {
		t.Fatalf("configuration without API key should be disabled: %#v, %v", config, err)
	}
	values["DATA_INDUK_API_KEY"] = "unit-test-key"
	values["DATA_INDUK_BASE_URL"] = "https://user:secret@data.example.test"
	if _, err = loadDataIndukConfig(func(key string) string { return values[key] }); err == nil {
		t.Fatal("URL user information must be rejected")
	}
}

func TestDataIndukFetchAllFollowsCursorAndPreservesAuth(t *testing.T) {
	requests := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requests++
		if r.URL.Path != "/api/v1/integrations/students" || r.Header.Get("X-API-Key") != "unit-test-key" || r.URL.Query().Get("unitId") != "unit-1" || r.URL.Query().Get("limit") != "200" {
			t.Errorf("unexpected Data Induk request: %s, headers=%v", r.URL.String(), r.Header)
		}
		w.Header().Set("Content-Type", "application/json")
		if r.URL.Query().Get("cursor") == "" {
			fmt.Fprint(w, `{"data":{"items":[{"id":"student-1"}],"nextCursor":"next"}}`)
			return
		}
		if r.URL.Query().Get("cursor") != "next" {
			t.Errorf("cursor = %q, want next", r.URL.Query().Get("cursor"))
		}
		fmt.Fprint(w, `{"data":{"items":[{"id":"student-2"}],"nextCursor":""}}`)
	}))
	defer server.Close()
	parsed, err := url.Parse(server.URL)
	if err != nil {
		t.Fatal(err)
	}
	config := dataIndukConfig{Enabled: true, BaseURL: parsed, APIKey: "unit-test-key", UnitID: "unit-1"}
	items, err := dataIndukFetchAll(context.Background(), server.Client(), config, "students", url.Values{"unitId": []string{"unit-1"}})
	if err != nil {
		t.Fatal(err)
	}
	if requests != 2 || len(items) != 2 || dataIndukField(items[1], "id") != "student-2" {
		t.Fatalf("unexpected paginated result: requests=%d items=%v", requests, items)
	}
}

func TestDataIndukNormalizeAndScope(t *testing.T) {
	if got := dataIndukNormalize(" Ahmad Fauzi-0812 "); got != "ahmadfauzi0812" {
		t.Fatalf("normalized value = %q", got)
	}
	for _, scope := range []string{"all", "references", "roster", "teachers", "changes"} {
		if !validDataIndukScope(scope) {
			t.Fatalf("scope %q was rejected", scope)
		}
	}
	if validDataIndukScope("unsafe") {
		t.Fatal("unknown scope must be rejected")
	}
}

func TestDataIndukCandidateIndexesFollowUpdatedSnapshot(t *testing.T) {
	indexes := dataIndukRosterIndexes{
		guardianCandidates:    map[guardianMatchKey][]int64{},
		guardianCandidateKeys: map[int64]guardianMatchKey{},
		studentCandidates:     map[studentMatchKey][]int64{},
		studentCandidateKeys:  map[int64]studentMatchKey{},
	}
	oldGuardian := guardianMatchKey{name: "aminah", phone: "0812"}
	newGuardian := guardianMatchKey{name: "aminahbaru", phone: "0812"}
	updateGuardianCandidateIndex(&indexes, 7, oldGuardian)
	updateGuardianCandidateIndex(&indexes, 7, oldGuardian)
	if got := len(indexes.guardianCandidates[oldGuardian]); got != 1 {
		t.Fatalf("same guardian snapshot duplicated candidate: %d", got)
	}
	updateGuardianCandidateIndex(&indexes, 7, newGuardian)
	if len(indexes.guardianCandidates[oldGuardian]) != 0 || len(indexes.guardianCandidates[newGuardian]) != 1 {
		t.Fatalf("guardian candidate index retained stale profile: %#v", indexes.guardianCandidates)
	}

	oldStudent := studentMatchKey{guardianID: 3, name: "ahmad"}
	newStudent := studentMatchKey{guardianID: 4, name: "ahmad"}
	updateStudentCandidateIndex(&indexes, 9, oldStudent)
	updateStudentCandidateIndex(&indexes, 9, newStudent)
	if len(indexes.studentCandidates[oldStudent]) != 0 || len(indexes.studentCandidates[newStudent]) != 1 {
		t.Fatalf("student candidate index retained stale guardian: %#v", indexes.studentCandidates)
	}

	teachers := dataIndukTeacherIndexes{candidates: map[string][]int64{}, candidateKeys: map[int64]string{}}
	updateTeacherCandidateIndex(&teachers, 12, "ustadzlama")
	updateTeacherCandidateIndex(&teachers, 12, "ustadzbaru")
	if len(teachers.candidates["ustadzlama"]) != 0 || len(teachers.candidates["ustadzbaru"]) != 1 {
		t.Fatalf("teacher candidate index retained stale name: %#v", teachers.candidates)
	}
}
