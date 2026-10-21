package app

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"html"
	"net/http"
	"strings"
	"time"
)

const maxAttendanceExportRows = 50000

func (s *Server) handleAttendanceExport(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		methodNotAllowed(w, http.MethodGet)
		return
	}
	filters, err := parseAttendanceReportFilters(r.URL.Query(), time.Now().In(mustLocation(s.Config.Timezone)))
	if err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, err.Error())
		return
	}
	auth, _ := currentAuth(r)
	if auth.User.Role == "ustadz" {
		filters.TeacherID = auth.User.ID
	}
	rows, err := fetchAttendanceExportRows(r.Context(), s.DB, filters)
	if errors.Is(err, errAttendanceExportTooLarge) {
		writeAPIError(w, http.StatusUnprocessableEntity, "Ekspor lebih dari 50.000 baris. Persempit tanggal, kelompok, atau pencarian.")
		return
	}
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Ekspor presensi belum dapat dibuat.")
		return
	}
	w.Header().Set("Content-Type", "application/vnd.ms-excel; charset=utf-8")
	w.Header().Set("Content-Disposition", fmt.Sprintf("attachment; filename=Rekap_Presensi_Tahsin_%s_to_%s.xls", filters.StartDate, filters.EndDate))
	w.Header().Set("Cache-Control", "no-store, private")
	w.WriteHeader(http.StatusOK)
	_, _ = fmt.Fprint(w, "<!doctype html><html><head><meta charset=\"utf-8\"><title>Rekap Presensi Tahsin</title></head><body><table border=\"1\"><thead><tr style=\"background:#f2f2f2;font-weight:bold\"><th>No</th><th>Tanggal</th><th>Halaqoh</th><th>Nama Wali Santri</th><th>Status</th><th>Materi</th><th>Keterangan/Surat/Jilid</th><th>Halaman/Ayat</th><th>Hasil Talaqqi</th><th>Alasan (Jika S/I)</th></tr></thead><tbody>")
	for index, row := range rows {
		date := row.Date
		if parsed, parseErr := time.Parse("2006-01-02", row.Date); parseErr == nil {
			date = parsed.Format("02/01/2006")
		}
		material, description, pageLabel := "-", "-", "-"
		switch row.Material {
		case "Iqro":
			material, description = "Iqro", "Jilid "+row.Level
			if row.Page != "" {
				pageLabel = "Hal " + row.Page
			}
		case "Al Quran":
			material, description = "Al Quran", row.Surah
			if description == "" {
				description = "-"
			}
			if row.Page != "" {
				pageLabel = "Ayat " + row.Page
			}
		}
		result, reason := row.Result, row.Reason
		if result == "" {
			result = "-"
		}
		if reason == "" {
			reason = "-"
		}
		_, _ = fmt.Fprintf(w, "<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>", index+1, excelText(date), excelText(row.GroupName), excelText(row.WaliName), excelText(row.Status), excelText(material), excelText(description), excelText(pageLabel), excelText(result), excelText(reason))
	}
	_, _ = fmt.Fprint(w, "</tbody></table></body></html>")
}

var errAttendanceExportTooLarge = errors.New("attendance export exceeds configured row limit")

func fetchAttendanceExportRows(ctx context.Context, db *sql.DB, filters AttendanceReportFilters) ([]AttendanceReportRow, error) {
	childTable, err := familyChildSource(ctx, db)
	if err != nil {
		return nil, err
	}
	where, args := attendanceReportWhere(filters)
	where = fmt.Sprintf(where, childTable)
	base := " FROM presensi p JOIN wali_santri w ON w.id=p.wali_santri_id JOIN halaqoh h ON h.id=p.halaqoh_id WHERE " + where
	query := "SELECT p.id,DATE_FORMAT(p.tanggal,'%Y-%m-%d'),p.halaqoh_id,p.wali_santri_id,COALESCE(p.status,''),COALESCE(p.jenis_materi,''),COALESCE(p.jilid,''),COALESCE(p.nama_surat,''),COALESCE(p.halaman,''),COALESCE(p.hasil_talaqqi,''),COALESCE(p.alasan,''),w.nama_bapak,h.nama_halaqoh,(SELECT GROUP_CONCAT(DISTINCT c.kelas ORDER BY c.kelas SEPARATOR ', ') FROM " + childTable + " c WHERE c.wali_santri_id=w.id AND c.kelas IS NOT NULL AND c.kelas<>'') AS kelas" + base + " ORDER BY p.tanggal DESC,h.nama_halaqoh,w.nama_bapak,p.id DESC LIMIT ?"
	args = append(args, maxAttendanceExportRows+1)
	rows, err := db.QueryContext(ctx, query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	result := make([]AttendanceReportRow, 0)
	for rows.Next() {
		var item AttendanceReportRow
		if err = rows.Scan(&item.ID, &item.Date, &item.GroupID, &item.WaliID, &item.Status, &item.Material, &item.Level, &item.Surah, &item.Page, &item.Result, &item.Reason, &item.WaliName, &item.GroupName, &item.Class); err != nil {
			return nil, err
		}
		result = append(result, item)
		if len(result) > maxAttendanceExportRows {
			return nil, errAttendanceExportTooLarge
		}
	}
	if err = rows.Err(); err != nil {
		return nil, err
	}
	return result, nil
}

func excelText(value string) string {
	value = strings.TrimSpace(value)
	if value != "" && strings.ContainsRune("=+-@\t\r", rune(value[0])) {
		value = "'" + value
	}
	return html.EscapeString(value)
}
