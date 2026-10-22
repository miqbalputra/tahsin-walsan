package app

import (
	"net/http/httptest"
	"testing"
)

func TestCapaianMaterialValidation(t *testing.T) {
	cases := []struct {
		name, material, level, surah, page string
		valid                              bool
	}{
		{name: "iqro", material: "Iqro", level: "3", page: "9-10", valid: true},
		{name: "iqro requires a supported level", material: "Iqro", level: "7", page: "9-10"},
		{name: "iqro cannot include a surah", material: "Iqro", level: "3", surah: "Al-Fatihah", page: "9"},
		{name: "quran", material: "Al Quran", surah: "Al-Fatihah", page: "1-7", valid: true},
		{name: "quran requires a surah", material: "Al Quran", page: "1-7"},
		{name: "page is required", material: "Al Quran", surah: "Al-Fatihah"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if got := validCapaianMaterial(tc.material, tc.level, tc.surah, tc.page); got != tc.valid {
				t.Fatalf("validCapaianMaterial() = %v, want %v", got, tc.valid)
			}
		})
	}
}

func TestCapaianFiltersBoundAndValidate(t *testing.T) {
	req := httptest.NewRequest("GET", "/api/v1/capaian?page=2&limit=500&search=Ibu", nil)
	filters, err := parseCapaianFilters(req)
	if err != nil {
		t.Fatal(err)
	}
	if filters.Page != 2 || filters.Limit != 100 || filters.Search != "Ibu" {
		t.Fatalf("unexpected filters: %#v", filters)
	}
	for _, target := range []string{
		"/api/v1/capaian?page=0",
		"/api/v1/capaian?halaqoh_id=01",
		"/api/v1/capaian?search=%00",
		"/api/v1/capaian?limit=1&limit=2",
	} {
		t.Run(target, func(t *testing.T) {
			if _, err := parseCapaianFilters(httptest.NewRequest("GET", target, nil)); err == nil {
				t.Fatal("expected invalid filters to be rejected")
			}
		})
	}
}
