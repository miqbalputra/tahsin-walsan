package app

import (
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
)

func (s *Server) handleTahsinReportExport(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		methodNotAllowed(w, http.MethodGet)
		return
	}
	now := time.Now().In(mustLocation(s.Config.Timezone))
	filters, err := parseTahsinReportFilters(r.URL.Query(), now)
	if err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, err.Error())
		return
	}
	auth, _ := currentAuth(r)
	if auth.User.Role == "ustadz" {
		filters.TeacherID = auth.User.ID
	}
	data, err := loadTahsinReportDataset(r.Context(), s.DB, filters.EndDate, now)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Ekspor rekap Tahsin belum dapat dibuat.")
		return
	}
	report := assembleTahsinReport(data, filters, auth.User.Role, auth.User.ID, now)
	rowCount := 0
	for _, row := range report.Rows {
		rowCount += max(1, len(row.Details))
		if rowCount > maxAttendanceExportRows {
			writeAPIError(w, http.StatusUnprocessableEntity, "Ekspor lebih dari 50.000 baris. Persempit periode atau pencarian.")
			return
		}
	}
	w.Header().Set("Content-Type", "application/vnd.ms-excel; charset=utf-8")
	w.Header().Set("Content-Disposition", fmt.Sprintf("attachment; filename=Rekap_Tahsin_%s_to_%s.xls", filters.StartDate, filters.EndDate))
	w.Header().Set("Cache-Control", "no-store, private")
	w.WriteHeader(http.StatusOK)
	writeTahsinReportExcel(w, report)
}

func renderTahsinReportExcel(report tahsinReportResult) string {
	var out strings.Builder
	writeTahsinReportExcel(&out, report)
	return out.String()
}

func writeTahsinReportExcel(out io.Writer, report tahsinReportResult) {
	_, _ = io.WriteString(out, "<!doctype html><html><head><meta charset=\"utf-8\"><title>Rekap Tahsin</title><style>body{font-family:Arial,sans-serif}table{border-collapse:collapse;margin:12px 0 24px}th,td{border:1px solid #888;padding:5px 7px;vertical-align:top}th{background:#e8edf4}.note{color:#555}</style></head><body>")
	_, _ = io.WriteString(out, "<h1>Rekap Tahsin</h1><p class=\"note\">Periode sesi ")
	_, _ = io.WriteString(out, excelText(report.Filters.StartDate+" s/d "+report.Filters.EndDate))
	_, _ = io.WriteString(out, ". Angka mengikuti rekap canonical; data kosong tidak dihitung sebagai Alfa. Sumber duplikat atau konflik ditampilkan pada kolom sumber.</p>")
	_, _ = io.WriteString(out, "<table><thead><tr><th>Wali dalam filter</th><th>Hadir</th><th>Sakit</th><th>Izin</th><th>Alfa tercatat</th><th>Sesi diharapkan</th><th>Input lengkap</th><th>Belum diinput</th><th>Capaian belum lengkap</th><th>Konflik</th><th>Belum terverifikasi</th></tr></thead><tbody><tr>")
	for _, value := range []string{
		fmt.Sprint(report.Summary.Participants), fmt.Sprint(report.Summary.Hadir), fmt.Sprint(report.Summary.Sakit),
		fmt.Sprint(report.Summary.Izin), fmt.Sprint(report.Summary.Alpha), fmt.Sprint(report.Summary.Expected),
		fmt.Sprint(report.Summary.CompleteInput), fmt.Sprint(report.Summary.MissingPresensi),
		fmt.Sprint(report.Summary.MissingCapaian), fmt.Sprint(report.Summary.Conflicts), fmt.Sprint(report.Summary.Unverified),
	} {
		_, _ = io.WriteString(out, "<td>"+excelText(value)+"</td>")
	}
	_, _ = io.WriteString(out, "</tr></tbody></table>")
	_, _ = io.WriteString(out, "<table><thead><tr><th>ID wali</th><th>Nama wali</th><th>Anak / kelas</th><th>Kategori</th><th>Status wali</th><th>Sesi</th><th>Kelompok</th><th>Guru</th><th>Status data</th><th>Presensi</th><th>Materi</th><th>Jilid / surat</th><th>Halaman / ayat</th><th>Hasil</th><th>Alasan</th><th>Sumber presensi</th><th>Roster diharapkan</th></tr></thead><tbody>")
	for _, row := range report.Rows {
		if len(row.Details) == 0 {
			writeTahsinExportRow(out, row, nil)
			continue
		}
		for i := range row.Details {
			writeTahsinExportRow(out, row, &row.Details[i])
		}
	}
	_, _ = io.WriteString(out, "</tbody></table></body></html>")
}

func writeTahsinExportRow(out io.Writer, row tahsinReportRow, cell *tahsinCell) {
	values := []string{
		fmt.Sprint(row.ID), row.Name, row.ChildrenText, row.Category,
		map[bool]string{true: "Aktif", false: "Arsip"}[row.Active],
		"", row.GroupName, row.TeacherName, row.QualityLabel,
		"", "", "", "", "", "", "", "",
	}
	if cell != nil {
		values[5] = cell.SessionDate
		values[6] = cell.Owner.GroupName
		values[7] = cell.Owner.Teacher
		values[8] = tahsinExportState(cell.State)
		values[16] = map[bool]string{true: "Ya", false: "Tidak"}[cell.Expected]
		if cell.Record != nil {
			values[9] = tahsinExportStatus(cell.Record.Status)
			values[10] = cell.Record.Material
			values[11] = tahsinExportLevelOrSurah(*cell.Record)
			values[12] = cell.Record.Page
			values[13] = cell.Record.Result
			values[14] = cell.Record.Reason
		}
		if len(cell.Records) > 0 {
			sources := make([]string, 0, len(cell.Records))
			for _, source := range cell.Records {
				sources = append(sources, fmt.Sprintf("#%d %s %s: %s", source.ID, source.Date, tahsinExportStatus(source.Status), tahsinExportRecordSummary(source)))
			}
			values[15] = strings.Join(sources, " | ")
		}
	}
	_, _ = io.WriteString(out, "<tr>")
	for _, value := range values {
		_, _ = io.WriteString(out, "<td>"+excelText(value)+"</td>")
	}
	_, _ = io.WriteString(out, "</tr>")
}

func tahsinExportState(value string) string {
	return map[string]string{
		"complete": "Lengkap", "missing": "Belum diinput", "baseline": "Capaian awal; bukan presensi",
		"conflict": "Perlu verifikasi", "invalid_date": "Tanggal pada hari libur",
		"capaian": "Capaian belum lengkap", "presensi_incomplete": "Keterangan belum lengkap",
	}[value]
}

func tahsinExportStatus(value string) string {
	return map[string]string{"H": "Hadir", "S": "Sakit", "I": "Izin", "A": "Alfa"}[value]
}

func tahsinExportLevelOrSurah(record tahsinRecord) string {
	if record.Material == "Iqro" {
		if record.Level == "" {
			return ""
		}
		return "Jilid " + record.Level
	}
	return record.Surah
}

func tahsinExportRecordSummary(record tahsinRecord) string {
	parts := []string{record.Material, tahsinExportLevelOrSurah(record), record.Page, record.Result, record.Reason}
	kept := parts[:0]
	for _, part := range parts {
		if strings.TrimSpace(part) != "" {
			kept = append(kept, part)
		}
	}
	return strings.Join(kept, ", ")
}
