package app

import (
	"strings"
	"testing"
)

func TestValidateReportSettings(t *testing.T) {
	valid, err := validateReportSettings(reportSettings{
		Template: "<h1>Rapor</h1>\r\n<div>{{nama_bapak}}</div>",
		Name:     "  Ustazah Pengesah ",
		Title:    " PJ Tahfidz ",
	})
	if err != nil {
		t.Fatal(err)
	}
	if valid.Name != "Ustazah Pengesah" || valid.Title != "PJ Tahfidz" || strings.Contains(valid.Template, "\r") {
		t.Fatalf("settings were not normalized: %#v", valid)
	}
	for _, invalid := range []reportSettings{
		{Name: "", Title: "PJ", Template: "<p>ok</p>"},
		{Name: "Admin", Title: strings.Repeat("t", 101), Template: ""},
		{Name: "Admin", Title: "PJ", Template: strings.Repeat("x", 450<<10+1)},
		{Name: "Admin", Title: "PJ", Template: "bad\rvalue"},
	} {
		if _, err := validateReportSettings(invalid); err == nil {
			t.Fatalf("accepted invalid report settings: %#v", invalid)
		}
	}
}

func TestDefaultString(t *testing.T) {
	if got := defaultString(" ", "fallback"); got != "fallback" {
		t.Fatalf("defaultString blank = %q", got)
	}
	if got := defaultString("custom", "fallback"); got != "custom" {
		t.Fatalf("defaultString custom = %q", got)
	}
}

func TestExcelTextEscapesMarkupAndPreventsFormulaEvaluation(t *testing.T) {
	for input, expected := range map[string]string{
		"<script>alert(1)</script>": "&lt;script&gt;alert(1)&lt;/script&gt;",
		"=1+1":                      "&#39;=1+1",
		"+cmd":                      "&#39;+cmd",
		"Nama biasa":                "Nama biasa",
	} {
		if got := excelText(input); got != expected {
			t.Errorf("excelText(%q) = %q, want %q", input, got, expected)
		}
	}
}
