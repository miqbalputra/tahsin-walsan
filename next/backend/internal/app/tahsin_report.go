package app

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"sort"
	"strconv"
	"strings"
	"time"
)

type tahsinReportFilters struct {
	StartDate string `json:"start_date"`
	EndDate   string `json:"end_date"`
	GroupID   int64  `json:"halaqoh_id"`
	TeacherID int64  `json:"ustadz_id"`
	WaliID    int64  `json:"wali_santri_id"`
	Class     string `json:"kelas"`
	Search    string `json:"search"`
	Category  string `json:"kategori"`
	Active    string `json:"aktif"`
	Quality   string `json:"kelengkapan"`
	Risk      string `json:"risk"`
}

type tahsinRecord struct {
	ID        int64  `json:"id"`
	WaliID    int64  `json:"wali_santri_id"`
	GroupID   int64  `json:"halaqoh_id"`
	Date      string `json:"tanggal"`
	Status    string `json:"status"`
	Reason    string `json:"alasan"`
	Material  string `json:"jenis_materi"`
	Level     string `json:"jilid"`
	Surah     string `json:"nama_surat"`
	Page      string `json:"halaman"`
	Result    string `json:"hasil_talaqqi"`
	CreatedAt string `json:"created_at,omitempty"`
	OnHoliday bool   `json:"on_holiday,omitempty"`
}

type tahsinPayload struct {
	Status   string `json:"status"`
	Reason   string `json:"alasan"`
	Material string `json:"jenis_materi"`
	Level    string `json:"jilid"`
	Surah    string `json:"nama_surat"`
	Page     string `json:"halaman"`
	Result   string `json:"hasil_talaqqi"`
}

type tahsinVerification struct {
	Fingerprint string
	SelectedID  int64
}

type tahsinOwner struct {
	WaliID     int64  `json:"wali_santri_id"`
	GroupID    int64  `json:"halaqoh_id"`
	TeacherID  int64  `json:"ustadz_id"`
	Category   string `json:"kategori"`
	GroupName  string `json:"nama_halaqoh"`
	Teacher    string `json:"nama_ustadz"`
	Phone      string `json:"ustadz_phone,omitempty"`
	ValidFrom  string `json:"valid_from,omitempty"`
	ValidUntil string `json:"valid_until,omitempty"`
}

type tahsinCell struct {
	SessionDate       string         `json:"session_date"`
	State             string         `json:"state"`
	Complete          bool           `json:"complete"`
	MissingFields     []string       `json:"missing_fields"`
	Records           []tahsinRecord `json:"records"`
	Record            *tahsinRecord  `json:"record"`
	Owner             tahsinOwner    `json:"owner"`
	Expected          bool           `json:"expected"`
	InSchedule        bool           `json:"in_schedule"`
	VerifiedRoster    bool           `json:"verified_roster"`
	DeadlineAt        string         `json:"deadline_at"`
	RosterSource      string         `json:"roster_source"`
	Fingerprint       string         `json:"fingerprint"`
	VerificationStale bool           `json:"verification_stale,omitempty"`
}

type tahsinTotals struct {
	Hadir           int64    `json:"hadir"`
	Sakit           int64    `json:"sakit"`
	Izin            int64    `json:"izin"`
	Alpha           int64    `json:"alpha"`
	Lulus           int64    `json:"lulus"`
	Ulang           int64    `json:"ulang"`
	TotalPresensi   int64    `json:"total_presensi"`
	Expected        int64    `json:"expected"`
	CompleteInput   int64    `json:"complete_input"`
	MissingPresensi int64    `json:"missing_presensi"`
	MissingCapaian  int64    `json:"missing_capaian"`
	MissingReason   int64    `json:"missing_keterangan"`
	Conflicts       int64    `json:"conflicts"`
	Unverified      int64    `json:"unverified"`
	HadirPercent    *float64 `json:"hadir_percent"`
	SakitPercent    *float64 `json:"sakit_percent"`
	IzinPercent     *float64 `json:"izin_percent"`
	AlphaPercent    *float64 `json:"alpha_percent"`
	CoveragePercent *float64 `json:"coverage_percent"`
	Provisional     bool     `json:"provisional"`
}

type tahsinReportRow struct {
	tahsinTotals
	ID             int64         `json:"id"`
	Name           string        `json:"nama_bapak"`
	Phone          string        `json:"no_hp"`
	Category       string        `json:"kategori"`
	Active         bool          `json:"status_aktif"`
	Place          string        `json:"tempat_tahsin"`
	OutsideTeacher string        `json:"ustadz_luar"`
	ChildrenText   string        `json:"anak"`
	Children       []reportChild `json:"children"`
	GroupID        int64         `json:"halaqoh_id"`
	TeacherID      int64         `json:"ustadz_id"`
	GroupName      string        `json:"nama_halaqoh"`
	TeacherName    string        `json:"nama_ustadz"`
	LastAttendance string        `json:"terakhir_hadir"`
	LatestMaterial string        `json:"materi_terakhir"`
	Quality        string        `json:"quality"`
	QualityLabel   string        `json:"quality_label"`
	Risk           string        `json:"risk_level"`
	RiskLabel      string        `json:"risk_label"`
	Details        []tahsinCell  `json:"details"`
	CurrentGroupID int64         `json:"current_halaqoh_id"`
	ContinueTahsin bool          `json:"lanjut_tahsin"`
}

type reportChild struct {
	Name  string `json:"nama_anak"`
	Class string `json:"kelas"`
}

type tahsinReportGroup struct {
	tahsinTotals
	ID           int64  `json:"id"`
	Name         string `json:"nama"`
	TeacherID    int64  `json:"ustadz_id"`
	TeacherName  string `json:"nama_ustadz"`
	TotalMembers int64  `json:"total_anggota"`
}

type tahsinTrend struct {
	tahsinTotals
	Date           string `json:"tanggal"`
	RosterVerified bool   `json:"roster_verified"`
	RosterCount    *int64 `json:"peserta_roster"`
	SessionCount   int64  `json:"peserta_sesi"`
}

type tahsinReportResult struct {
	Rows        []tahsinReportRow   `json:"rows"`
	Summary     tahsinReportSummary `json:"summary"`
	Groups      []tahsinReportGroup `json:"halaqoh"`
	Teachers    []tahsinReportGroup `json:"guru"`
	Trend       []tahsinTrend       `json:"trend"`
	Sessions    []string            `json:"sessions"`
	Filters     tahsinReportFilters `json:"filters"`
	SchemaReady bool                `json:"schema_ready"`
	TrackedFrom string              `json:"tracked_from,omitempty"`
}

type tahsinReportSummary struct {
	tahsinTotals
	Participants           int64 `json:"peserta"`
	ParticipantsInPeriod   int64 `json:"peserta_periode"`
	ParticipantsInSessions int64 `json:"peserta_sesi"`
	TotalGroups            int64 `json:"total_halaqoh"`
	AtRisk                 int64 `json:"rawan"`
}

func parseTahsinReportFilters(values map[string][]string, now time.Time) (tahsinReportFilters, error) {
	loc := mustLocation("Asia/Jakarta")
	today := now.In(loc).Format("2006-01-02")
	f := tahsinReportFilters{StartDate: now.In(loc).Format("2006-01") + "-01", EndDate: today}
	readText := func(key, alias string) (string, error) {
		got := values[key]
		if len(got) == 0 && alias != "" {
			got = values[alias]
		}
		if len(got) > 1 {
			return "", fmt.Errorf("parameter %s berulang", key)
		}
		if len(got) == 0 {
			return "", nil
		}
		return strings.TrimSpace(got[0]), nil
	}
	if v, err := readText("start_date", "start"); err != nil {
		return f, err
	} else if v != "" {
		f.StartDate = v
	}
	if v, err := readText("end_date", "end"); err != nil {
		return f, err
	} else if v != "" {
		f.EndDate = v
	}
	for _, value := range []string{f.StartDate, f.EndDate} {
		parsed, err := time.Parse("2006-01-02", value)
		if err != nil || parsed.Format("2006-01-02") != value {
			return f, errors.New("tanggal periode Tahsin tidak valid")
		}
	}
	if f.StartDate > f.EndDate || f.EndDate > today {
		return f, errors.New("rentang periode Tahsin tidak valid")
	}
	start, _ := time.Parse("2006-01-02", f.StartDate)
	end, _ := time.Parse("2006-01-02", f.EndDate)
	if end.Sub(start) > 3660*24*time.Hour {
		return f, errors.New("periode Tahsin maksimal sepuluh tahun")
	}
	f.EndDate = min(f.EndDate, today)
	for key, target := range map[string]*int64{"halaqoh_id": &f.GroupID, "ustadz_id": &f.TeacherID, "wali_santri_id": &f.WaliID} {
		if len(values[key]) > 1 {
			return f, fmt.Errorf("parameter %s berulang", key)
		}
		if len(values[key]) == 1 && strings.TrimSpace(values[key][0]) != "" {
			raw := strings.TrimSpace(values[key][0])
			n, err := strconv.ParseInt(raw, 10, 64)
			if err != nil || n < 1 || strconv.FormatInt(n, 10) != raw {
				return f, fmt.Errorf("parameter %s tidak valid", key)
			}
			*target = n
		}
	}
	for key, target := range map[string]*string{"kelas": &f.Class, "search": &f.Search, "kategori": &f.Category, "aktif": &f.Active, "kelengkapan": &f.Quality, "risk": &f.Risk} {
		value, err := readText(key, "")
		if err != nil {
			return f, err
		}
		if len([]rune(value)) > 100 {
			return f, fmt.Errorf("parameter %s terlalu panjang", key)
		}
		*target = value
	}
	if f.Category != "" && f.Category != "reguler" && f.Category != "askar" && f.Category != "tahsin_luar" {
		return f, errors.New("kategori Tahsin tidak valid")
	}
	if f.Active != "" && f.Active != "0" && f.Active != "1" {
		return f, errors.New("filter status aktif tidak valid")
	}
	if f.Quality != "" && !oneOf(f.Quality, "complete", "missing", "partial", "capaian", "conflict", "unverified", "external", "unassigned", "archived", "no_data") {
		return f, errors.New("filter kualitas data tidak valid")
	}
	if f.Risk != "" && !oneOf(f.Risk, "rawan", "pantau", "stabil", "belum_data") {
		return f, errors.New("filter pemantauan tidak valid")
	}
	return f, nil
}

func oneOf(value string, choices ...string) bool {
	for _, choice := range choices {
		if value == choice {
			return true
		}
	}
	return false
}

func tahsinSessionDate(date string) (string, error) {
	parsed, err := time.Parse("2006-01-02", date)
	if err != nil || parsed.Format("2006-01-02") != date {
		return "", errors.New("tanggal sesi tidak valid")
	}
	weekday := int(parsed.Weekday())
	return parsed.AddDate(0, 0, -weekday).Format("2006-01-02"), nil
}

func tahsinSessionIsActive(session string, holidays map[string]string) bool {
	date, err := time.Parse("2006-01-02", session)
	if err != nil || date.Weekday() != time.Sunday || date.Day() <= 7 {
		return false
	}
	_, holiday := holidays[session]
	return !holiday
}

func tahsinSessions(start, end string, holidays map[string]string) []string {
	first, err := tahsinSessionDate(start)
	if err != nil {
		return nil
	}
	if first < start {
		date, _ := time.Parse("2006-01-02", first)
		first = date.AddDate(0, 0, 7).Format("2006-01-02")
	}
	var sessions []string
	for day := first; day <= end; {
		if tahsinSessionIsActive(day, holidays) {
			sessions = append(sessions, day)
		}
		parsed, _ := time.Parse("2006-01-02", day)
		day = parsed.AddDate(0, 0, 7).Format("2006-01-02")
	}
	return sessions
}

func tahsinRecordPayload(record tahsinRecord) tahsinPayload {
	payload := tahsinPayload{Status: record.Status}
	if record.Status == "S" || record.Status == "I" {
		payload.Reason = strings.TrimSpace(record.Reason)
	}
	if record.Status == "H" {
		payload.Material = strings.TrimSpace(record.Material)
		payload.Level = strings.TrimSpace(record.Level)
		payload.Surah = strings.TrimSpace(record.Surah)
		payload.Page = strings.TrimSpace(record.Page)
		payload.Result = strings.TrimSpace(record.Result)
		if payload.Material == "Iqro" {
			level, _ := strconv.Atoi(payload.Level)
			payload.Level = strconv.Itoa(level)
			payload.Surah = ""
		}
		if payload.Material == "Al Quran" {
			payload.Level = ""
		}
	}
	return payload
}

func tahsinMissingFields(record tahsinRecord) []string {
	p := tahsinRecordPayload(record)
	if !oneOf(p.Status, "H", "S", "I", "A") {
		return []string{"status kehadiran"}
	}
	if p.Status == "S" || p.Status == "I" {
		if p.Reason == "" {
			return []string{"alasan/keterangan"}
		}
		return nil
	}
	if p.Status != "H" {
		return nil
	}
	var missing []string
	if !oneOf(p.Material, "Iqro", "Al Quran") {
		missing = append(missing, "jenis materi")
	}
	if p.Material == "Iqro" {
		level, _ := strconv.Atoi(p.Level)
		if level < 1 || level > 6 {
			missing = append(missing, "jilid Iqro")
		}
	}
	if p.Material == "Al Quran" && p.Surah == "" {
		missing = append(missing, "surat")
	}
	if p.Page == "" {
		missing = append(missing, "halaman/ayat")
	}
	if !oneOf(p.Result, "Lulus", "Ulang") {
		missing = append(missing, "hasil talaqqi")
	}
	return missing
}

func tahsinFingerprint(records []tahsinRecord, groupAsString bool) string {
	sorted := append([]tahsinRecord(nil), records...)
	sort.Slice(sorted, func(i, j int) bool { return sorted[i].ID < sorted[j].ID })
	type fpRecord struct {
		ID        int64         `json:"id"`
		WaliID    int64         `json:"wali"`
		Group     any           `json:"halaqoh"`
		Date      string        `json:"tanggal"`
		Payload   tahsinPayload `json:"payload"`
		CreatedAt any           `json:"created_at"`
	}
	items := make([]fpRecord, 0, len(sorted))
	for _, item := range sorted {
		var group any = item.GroupID
		if groupAsString {
			group = strconv.FormatInt(item.GroupID, 10)
		}
		var created any
		if item.CreatedAt != "" {
			created = item.CreatedAt
		}
		items = append(items, fpRecord{ID: item.ID, WaliID: item.WaliID, Group: group, Date: item.Date, Payload: tahsinRecordPayload(item), CreatedAt: created})
	}
	var buffer bytes.Buffer
	encoder := json.NewEncoder(&buffer)
	encoder.SetEscapeHTML(false)
	if err := encoder.Encode(items); err != nil {
		return ""
	}
	raw := bytes.TrimSuffix(buffer.Bytes(), []byte("\n"))
	hash := sha256.Sum256(raw)
	return hex.EncodeToString(hash[:])
}

func tahsinFingerprintMatches(records []tahsinRecord, fingerprint string) bool {
	return fingerprint != "" && (fingerprint == tahsinFingerprint(records, true) || fingerprint == tahsinFingerprint(records, false))
}

type tahsinResolvedCell struct {
	Records     []tahsinRecord
	Record      *tahsinRecord
	State       string
	Complete    bool
	Missing     []string
	Fingerprint string
	Stale       bool
}

func resolveTahsinSession(records []tahsinRecord, verification *tahsinVerification, suspect map[int64]bool, holidays map[string]string) tahsinResolvedCell {
	fingerprint := tahsinFingerprint(records, true)
	cell := tahsinResolvedCell{Records: records, State: "missing", Missing: []string{"presensi dan capaian"}, Fingerprint: fingerprint}
	if len(records) == 0 {
		return cell
	}
	currentVerification := verification != nil && tahsinFingerprintMatches(records, verification.Fingerprint)
	eligible := make([]tahsinRecord, 0, len(records))
	for _, record := range records {
		if _, holiday := holidays[record.Date]; !holiday {
			eligible = append(eligible, record)
		}
	}
	if verification != nil && currentVerification {
		if verification.SelectedID == 0 {
			cell.State = "baseline"
			cell.Missing = []string{"catatan ini hanya capaian awal"}
			return cell
		}
		for i := range records {
			if records[i].ID == verification.SelectedID {
				if _, holiday := holidays[records[i].Date]; holiday {
					cell.State = "invalid_date"
					cell.Missing = []string{"tanggal pelaksanaan jatuh pada libur kajian; isi catatan pada tanggal pelaksanaan yang benar"}
					return cell
				}
				record := records[i]
				cell.Record = &record
				break
			}
		}
		if cell.Record == nil {
			cell.Stale = true
		}
	} else if verification != nil {
		cell.Stale = true
	}
	if cell.Record == nil {
		if len(eligible) == 0 {
			cell.State = "invalid_date"
			cell.Missing = []string{"tanggal pelaksanaan jatuh pada libur kajian; isi catatan pada tanggal pelaksanaan yang benar"}
			return cell
		}
		payloads := map[string]bool{}
		isSuspect := false
		for _, record := range eligible {
			payloads[tahsinPayloadComparisonJSON(tahsinRecordPayload(record))] = true
			isSuspect = isSuspect || suspect[record.ID]
		}
		if len(payloads) > 1 || isSuspect || cell.Stale {
			cell.State = "conflict"
			if isSuspect {
				cell.Missing = []string{"verifikasi dugaan capaian awal"}
			} else {
				cell.Missing = []string{"verifikasi catatan berbeda/keputusan kedaluwarsa"}
			}
			return cell
		}
		sort.Slice(eligible, func(i, j int) bool {
			if eligible[i].Date != eligible[j].Date {
				return eligible[i].Date > eligible[j].Date
			}
			return eligible[i].ID > eligible[j].ID
		})
		record := eligible[0]
		cell.Record = &record
	}
	cell.Missing = tahsinMissingFields(*cell.Record)
	cell.Complete = len(cell.Missing) == 0
	if cell.Complete {
		cell.State = "complete"
	} else if cell.Record.Status == "H" {
		cell.State = "capaian"
	} else {
		cell.State = "presensi_incomplete"
	}
	return cell
}

func jsonString(value any) string {
	var b bytes.Buffer
	e := json.NewEncoder(&b)
	e.SetEscapeHTML(false)
	_ = e.Encode(value)
	return strings.TrimSuffix(b.String(), "\n")
}

func tahsinPayloadComparisonJSON(payload tahsinPayload) string {
	return strings.ReplaceAll(jsonString(payload), "/", `\/`)
}

func reportOwnerMatches(owner tahsinOwner, filters tahsinReportFilters, role string, userID int64) bool {
	return (role != "ustadz" || owner.TeacherID == userID) && (filters.GroupID == 0 || owner.GroupID == filters.GroupID) && (filters.TeacherID == 0 || owner.TeacherID == filters.TeacherID)
}

func emptyTahsinTotals() tahsinTotals { return tahsinTotals{} }

func addTahsinCellTotals(totals *tahsinTotals, cell tahsinCell, resolved tahsinResolvedCell) {
	if cell.InSchedule && resolved.Record != nil {
		switch resolved.Record.Status {
		case "H":
			totals.Hadir++
		case "S":
			totals.Sakit++
		case "I":
			totals.Izin++
		case "A":
			totals.Alpha++
		}
		totals.TotalPresensi++
		if resolved.Record.Status == "H" && resolved.Complete {
			if resolved.Record.Result == "Lulus" {
				totals.Lulus++
			} else if resolved.Record.Result == "Ulang" {
				totals.Ulang++
			}
		}
	}
	if cell.Expected {
		totals.Expected++
		if resolved.Complete && cell.InSchedule {
			totals.CompleteInput++
		} else if resolved.State == "missing" || resolved.State == "baseline" {
			totals.MissingPresensi++
		}
	}
	if cell.InSchedule && resolved.State == "capaian" {
		totals.MissingCapaian++
	}
	if cell.InSchedule && resolved.State == "presensi_incomplete" {
		totals.MissingReason++
	}
	if cell.InSchedule && (resolved.State == "conflict" || resolved.State == "invalid_date") {
		totals.Conflicts++
	}
}

func finalizeTahsinTotals(totals tahsinTotals) tahsinTotals {
	percent := func(count int64) *float64 {
		if totals.TotalPresensi == 0 {
			return nil
		}
		value := mathRoundOne(100 * float64(count) / float64(totals.TotalPresensi))
		return &value
	}
	totals.HadirPercent = percent(totals.Hadir)
	totals.SakitPercent = percent(totals.Sakit)
	totals.IzinPercent = percent(totals.Izin)
	totals.AlphaPercent = percent(totals.Alpha)
	if totals.Unverified == 0 && totals.Expected > 0 {
		value := mathRoundOne(100 * float64(totals.CompleteInput) / float64(totals.Expected))
		totals.CoveragePercent = &value
	}
	totals.Provisional = totals.Unverified > 0 || totals.Conflicts > 0 || totals.MissingCapaian > 0 || totals.MissingReason > 0 || totals.CompleteInput < totals.Expected
	return totals
}

func mathRoundOne(value float64) float64 { return float64(int64(value*10+0.5)) / 10 }

func tahsinCellStateLabel(state string, status string) string {
	labels := map[string]string{"conflict": "Perlu Verifikasi", "invalid_date": "Tanggal Pelaksanaan Perlu Diperbaiki", "missing": "Belum Diinput", "baseline": "Capaian Awal; bukan kehadiran", "capaian": "Capaian Belum Lengkap", "presensi_incomplete": "Keterangan Presensi Belum Lengkap"}
	if label, ok := labels[state]; ok {
		return label
	}
	return map[string]string{"H": "Hadir", "S": "Sakit", "I": "Izin", "A": "Alfa"}[status]
}

func tahsinMaterial(record *tahsinRecord) string {
	if record == nil || record.Material == "" {
		return "—"
	}
	if record.Material == "Iqro" {
		return strings.TrimSpace("Iqro Jilid " + record.Level + " Hal. " + record.Page)
	}
	return strings.TrimSpace(record.Material + " " + record.Surah + " Ayat " + record.Page)
}

func tahsinQuality(row tahsinReportRow) (string, string) {
	if row.Conflicts > 0 {
		return "conflict", "Perlu Verifikasi"
	}
	if row.MissingCapaian > 0 {
		return "capaian", "Capaian Belum Lengkap"
	}
	if row.MissingPresensi > 0 || row.MissingReason > 0 {
		if row.TotalPresensi > 0 {
			return "partial", "Sebagian Diinput"
		}
		return "missing", "Belum Diinput"
	}
	if row.Unverified > 0 {
		return "unverified", "Belum Terverifikasi"
	}
	if row.TotalPresensi > 0 || row.Expected > 0 {
		return "complete", "Lengkap"
	}
	if !row.Active {
		return "archived", "Arsip; Tidak Ada Data dalam Periode"
	}
	if row.Category == "tahsin_luar" {
		return "external", "Data Tahsin Luar Tidak Tersedia"
	}
	if row.CurrentGroupID == 0 {
		return "unassigned", "Belum Punya Halaqoh"
	}
	return "no_data", "Tidak Ada Sesi dalam Periode"
}
