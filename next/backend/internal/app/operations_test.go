package app

import (
	"strings"
	"testing"
)

func TestValidateHolidayInput(t *testing.T) {
	valid, err := validateHolidayInput(holidayRecord{Date: " 2026-12-25 ", Description: " Libur akhir tahun "})
	if err != nil || valid.Date != "2026-12-25" || valid.Description != "Libur akhir tahun" {
		t.Fatalf("valid holiday = %#v, err=%v", valid, err)
	}
	for _, input := range []holidayRecord{{Date: "2026-02-30"}, {Date: "2026/12/25"}, {Date: "2026-12-25", Description: strings.Repeat("x", 256)}} {
		if _, err = validateHolidayInput(input); err == nil {
			t.Fatalf("accepted invalid holiday: %#v", input)
		}
	}
}

func TestValidateAnnouncementInput(t *testing.T) {
	valid, err := validateAnnouncementInput(announcementRecord{Title: " Jadwal ", Body: "Pertemuan dimulai\npukul 08.00"})
	if err != nil || valid.Title != "Jadwal" || valid.Body != "Pertemuan dimulai\npukul 08.00" {
		t.Fatalf("valid announcement = %#v, err=%v", valid, err)
	}
	if _, err = validateAnnouncementInput(announcementRecord{Title: "", Body: "Isi"}); err == nil {
		t.Fatal("accepted announcement without a title")
	}
	if _, err = validateAnnouncementInput(announcementRecord{Title: "Judul", Body: "baris\r\nbreak"}); err == nil {
		t.Fatal("accepted unsupported control characters")
	}
}
