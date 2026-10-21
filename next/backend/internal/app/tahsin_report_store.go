package app

import (
	"context"
	"database/sql"
	"errors"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/go-sql-driver/mysql"
)

type tahsinReportWali struct {
	ID                                           int64
	Name, Phone, Category, Place, OutsideTeacher string
	Active                                       bool
	Continue                                     bool
}

type tahsinReportChild struct {
	WaliID      int64
	Name, Class string
}

type tahsinReportDataset struct {
	Walis         []tahsinReportWali
	Children      []tahsinReportChild
	Groups        map[int64]tahsinOwner
	Current       []tahsinOwner
	History       []tahsinOwner
	Records       []tahsinRecord
	Verifications map[string]tahsinVerification
	Baselines     map[int64]tahsinRecord
	Holidays      map[string]string
	Suspect       map[int64]bool
	TrackedFrom   string
	SchemaReady   bool
}

func tahsinReportSchemaReady(ctx context.Context, db *sql.DB) (bool, string, error) {
	tables := []string{"tahsin_tracking", "tahsin_roster_history", "tahsin_verifications", "tahsin_capaian_awal"}
	for _, table := range tables {
		var exists int
		if err := db.QueryRowContext(ctx, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?", table).Scan(&exists); err != nil {
			return false, "", err
		}
		if exists != 1 {
			return false, "", nil
		}
	}
	var tracked string
	if err := db.QueryRowContext(ctx, "SELECT COALESCE(DATE_FORMAT(tracked_from,'%Y-%m-%d %H:%i:%s.%f'),'') FROM tahsin_tracking WHERE id=1 AND schema_version=1").Scan(&tracked); err != nil {
		if err == sql.ErrNoRows {
			return false, "", nil
		}
		return false, "", err
	}
	if tracked == "" {
		return false, "", nil
	}
	names := []string{"tahsin_hm_ai", "tahsin_hm_au", "tahsin_hm_ad", "tahsin_wali_au", "tahsin_wali_bd", "tahsin_halaqoh_au", "tahsin_halaqoh_bd"}
	marks := strings.TrimRight(strings.Repeat("?,", len(names)), ",")
	args := make([]any, len(names))
	for i := range names {
		args[i] = names[i]
	}
	var triggerCount int
	if err := db.QueryRowContext(ctx, "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name IN ("+marks+")", args...).Scan(&triggerCount); err != nil {
		return false, "", err
	}
	return triggerCount == len(names), tracked, nil
}

func loadTahsinReportDataset(ctx context.Context, db *sql.DB, endDate string, now time.Time) (*tahsinReportDataset, error) {
	endSession, err := tahsinSessionDate(endDate)
	if err != nil {
		return nil, err
	}
	endParsed, _ := time.Parse("2006-01-02", endSession)
	end := min(endParsed.AddDate(0, 0, 6).Format("2006-01-02"), now.In(mustLocation("Asia/Jakarta")).Format("2006-01-02"))
	data := &tahsinReportDataset{Groups: map[int64]tahsinOwner{}, Verifications: map[string]tahsinVerification{}, Baselines: map[int64]tahsinRecord{}, Holidays: map[string]string{}, Suspect: map[int64]bool{}}
	continueColumn, err := columnExists(ctx, db, "wali_santri", "lanjut_tahsin")
	if err != nil {
		return nil, err
	}
	continueSelect := "0"
	if continueColumn {
		continueSelect = "COALESCE(lanjut_tahsin,0)"
	}
	rows, err := db.QueryContext(ctx, "SELECT id,nama_bapak,COALESCE(no_hp,''),COALESCE(kategori,'reguler'),COALESCE(tempat_tahsin,''),COALESCE(ustadz_luar,''),COALESCE(status_aktif,0),"+continueSelect+" FROM wali_santri ORDER BY nama_bapak,id")
	if err != nil {
		return nil, err
	}
	for rows.Next() {
		var item tahsinReportWali
		var active, continuing int
		if err = rows.Scan(&item.ID, &item.Name, &item.Phone, &item.Category, &item.Place, &item.OutsideTeacher, &active, &continuing); err != nil {
			rows.Close()
			return nil, err
		}
		item.Active = active == 1
		item.Continue = continuing == 1
		data.Walis = append(data.Walis, item)
	}
	if err = rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()
	childTable, err := familyChildSource(ctx, db)
	if err != nil {
		return nil, err
	}
	rows, err = db.QueryContext(ctx, "SELECT wali_santri_id,COALESCE(nama_anak,''),COALESCE(kelas,'') FROM "+childTable+" ORDER BY nama_anak")
	if err != nil {
		return nil, err
	}
	for rows.Next() {
		var item tahsinReportChild
		if err = rows.Scan(&item.WaliID, &item.Name, &item.Class); err != nil {
			rows.Close()
			return nil, err
		}
		data.Children = append(data.Children, item)
	}
	if err = rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()
	rows, err = db.QueryContext(ctx, "SELECT h.id,h.nama_halaqoh,h.ustadz_id,COALESCE(u.nama_lengkap,''),COALESCE(u.no_hp,'') FROM halaqoh h LEFT JOIN users u ON u.id=h.ustadz_id ORDER BY h.nama_halaqoh,h.id")
	if err != nil {
		return nil, err
	}
	for rows.Next() {
		var item tahsinOwner
		if err = rows.Scan(&item.GroupID, &item.GroupName, &item.TeacherID, &item.Teacher, &item.Phone); err != nil {
			rows.Close()
			return nil, err
		}
		data.Groups[item.GroupID] = item
	}
	if err = rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()
	archivedColumn, err := columnExists(ctx, db, "halaqoh_members", "archived_at")
	if err != nil {
		return nil, err
	}
	archiveFilter := ""
	if archivedColumn {
		archiveFilter = " AND hm.archived_at IS NULL"
	}
	rows, err = db.QueryContext(ctx, "SELECT DISTINCT hm.wali_santri_id,hm.halaqoh_id,h.ustadz_id,w.kategori,h.nama_halaqoh,COALESCE(u.nama_lengkap,''),COALESCE(u.no_hp,'') FROM halaqoh_members hm JOIN wali_santri w ON w.id=hm.wali_santri_id JOIN halaqoh h ON h.id=hm.halaqoh_id LEFT JOIN users u ON u.id=h.ustadz_id WHERE w.status_aktif=1 AND w.kategori IN ('reguler','askar')"+archiveFilter+" ORDER BY hm.wali_santri_id,hm.halaqoh_id")
	if err != nil {
		return nil, err
	}
	for rows.Next() {
		var item tahsinOwner
		if err = rows.Scan(&item.WaliID, &item.GroupID, &item.TeacherID, &item.Category, &item.GroupName, &item.Teacher, &item.Phone); err != nil {
			rows.Close()
			return nil, err
		}
		data.Current = append(data.Current, item)
	}
	if err = rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()
	rows, err = db.QueryContext(ctx, "SELECT id,DATE_FORMAT(tanggal,'%Y-%m-%d'),COALESCE(keterangan,'') FROM holidays")
	if err == nil {
		for rows.Next() {
			var id int64
			var date, note string
			if err = rows.Scan(&id, &date, &note); err != nil {
				rows.Close()
				return nil, err
			}
			if note == "" {
				note = "Libur Kajian"
			}
			data.Holidays[date] = note
		}
		if err = rows.Err(); err != nil {
			rows.Close()
			return nil, err
		}
		rows.Close()
	} else if !missingTable(err) {
		return nil, err
	}
	query := "SELECT id,wali_santri_id,halaqoh_id,DATE_FORMAT(tanggal,'%Y-%m-%d'),COALESCE(status,''),COALESCE(alasan,''),COALESCE(jenis_materi,''),CAST(COALESCE(jilid,'') AS CHAR),COALESCE(nama_surat,''),COALESCE(halaman,''),COALESCE(hasil_talaqqi,''),COALESCE(CAST(created_at AS CHAR),'') FROM presensi WHERE tanggal<=? ORDER BY tanggal,id"
	rows, err = db.QueryContext(ctx, query, end)
	if err != nil {
		return nil, err
	}
	for rows.Next() {
		var item tahsinRecord
		if err = rows.Scan(&item.ID, &item.WaliID, &item.GroupID, &item.Date, &item.Status, &item.Reason, &item.Material, &item.Level, &item.Surah, &item.Page, &item.Result, &item.CreatedAt); err != nil {
			rows.Close()
			return nil, err
		}
		data.Records = append(data.Records, item)
	}
	if err = rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()
	data.SchemaReady, data.TrackedFrom, err = tahsinReportSchemaReady(ctx, db)
	if err != nil {
		return nil, err
	}
	if data.SchemaReady {
		rows, err = db.QueryContext(ctx, "SELECT wali_santri_id,halaqoh_id,ustadz_id,kategori,nama_halaqoh,COALESCE(nama_ustadz,''),COALESCE(ustadz_phone,''),DATE_FORMAT(valid_from,'%Y-%m-%d %H:%i:%s.%f'),COALESCE(DATE_FORMAT(valid_until,'%Y-%m-%d %H:%i:%s.%f'),'') FROM tahsin_roster_history ORDER BY id")
		if err != nil {
			return nil, err
		}
		for rows.Next() {
			var item tahsinOwner
			if err = rows.Scan(&item.WaliID, &item.GroupID, &item.TeacherID, &item.Category, &item.GroupName, &item.Teacher, &item.Phone, &item.ValidFrom, &item.ValidUntil); err != nil {
				rows.Close()
				return nil, err
			}
			data.History = append(data.History, item)
		}
		if err = rows.Err(); err != nil {
			rows.Close()
			return nil, err
		}
		rows.Close()
		rows, err = db.QueryContext(ctx, "SELECT wali_santri_id,DATE_FORMAT(session_date,'%Y-%m-%d'),fingerprint,COALESCE(selected_presensi_id,0) FROM tahsin_verifications ORDER BY id")
		if err != nil {
			return nil, err
		}
		for rows.Next() {
			var wali int64
			var session string
			var item tahsinVerification
			if err = rows.Scan(&wali, &session, &item.Fingerprint, &item.SelectedID); err != nil {
				rows.Close()
				return nil, err
			}
			data.Verifications[tahsinSessionKey(wali, session)] = item
		}
		if err = rows.Err(); err != nil {
			rows.Close()
			return nil, err
		}
		rows.Close()
		rows, err = db.QueryContext(ctx, "SELECT wali_santri_id,COALESCE(jenis_materi,''),CAST(COALESCE(jilid,'') AS CHAR),COALESCE(nama_surat,''),COALESCE(halaman,''),COALESCE(DATE_FORMAT(updated_at,'%Y-%m-%d %H:%i:%s.%f'),'') FROM tahsin_capaian_awal")
		if err != nil {
			return nil, err
		}
		for rows.Next() {
			var wali int64
			var item tahsinRecord
			if err = rows.Scan(&wali, &item.Material, &item.Level, &item.Surah, &item.Page, &item.CreatedAt); err != nil {
				rows.Close()
				return nil, err
			}
			item.WaliID = wali
			data.Baselines[wali] = item
		}
		if err = rows.Err(); err != nil {
			rows.Close()
			return nil, err
		}
		rows.Close()
	}
	if len(data.Records) > 0 {
		if err = loadTahsinSuspects(ctx, db, data.Records, data.Suspect); err != nil {
			return nil, err
		}
	}
	return data, nil
}

var suspectWaliRE = regexp.MustCompile(`Wali ID:\s*(\d+)`)

func loadTahsinSuspects(ctx context.Context, db *sql.DB, records []tahsinRecord, suspects map[int64]bool) error {
	dates := make([]string, 0, len(records))
	for _, record := range records {
		dates = append(dates, record.Date)
	}
	sort.Strings(dates)
	if len(dates) == 0 {
		return nil
	}
	rows, err := db.QueryContext(ctx, "SELECT COALESCE(description,''),DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s') FROM activity_logs WHERE action='INIT_CAPAIAN' AND created_at BETWEEN ? AND ?", dates[0]+" 00:00:00", dates[len(dates)-1]+" 23:59:59")
	if err != nil {
		if missingTable(err) {
			return nil
		}
		return err
	}
	defer rows.Close()
	type logItem struct {
		WaliID int64
		At     string
	}
	logs := []logItem{}
	for rows.Next() {
		var desc, at string
		if err = rows.Scan(&desc, &at); err != nil {
			return err
		}
		match := suspectWaliRE.FindStringSubmatch(desc)
		if len(match) != 2 {
			continue
		}
		id, _ := strconv.ParseInt(match[1], 10, 64)
		logs = append(logs, logItem{id, at})
	}
	if err = rows.Err(); err != nil {
		return err
	}
	loc := mustLocation("Asia/Jakarta")
	for _, record := range records {
		if record.Status != "H" || record.CreatedAt == "" {
			continue
		}
		created := parseTahsinTime(record.CreatedAt, loc)
		for _, log := range logs {
			if log.WaliID != record.WaliID || !strings.HasPrefix(log.At, record.Date) {
				continue
			}
			logged := parseTahsinTime(log.At, loc)
			if !created.IsZero() && !logged.IsZero() && absDuration(created.Sub(logged)) <= 30*time.Second {
				suspects[record.ID] = true
				break
			}
		}
	}
	return nil
}

func parseTahsinTime(value string, loc *time.Location) time.Time {
	for _, layout := range []string{"2006-01-02 15:04:05.999999", "2006-01-02 15:04:05"} {
		if parsed, err := time.ParseInLocation(layout, value, loc); err == nil {
			return parsed
		}
	}
	return time.Time{}
}
func absDuration(value time.Duration) time.Duration {
	if value < 0 {
		return -value
	}
	return value
}
func tahsinSessionKey(waliID int64, session string) string {
	return strconv.FormatInt(waliID, 10) + ":" + session
}

func missingTable(err error) bool {
	var mysqlErr *mysql.MySQLError
	return errors.As(err, &mysqlErr) && mysqlErr.Number == 1146
}
