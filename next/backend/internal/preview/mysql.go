package preview

import (
	"context"
	"database/sql"
	"errors"
	"net"
	"os"
	"regexp"
	"strings"
	"time"

	"github.com/go-sql-driver/mysql"
)

type MySQLStore struct{ DB *sql.DB }

// Refuse broad grants, grant options, and roles: deployments must use a dedicated SELECT-only account.
func ValidateGrants(grants []string) error {
	if len(grants) == 0 {
		return errors.New("akun baca khusus diperlukan")
	}
	for _, grant := range grants {
		g := strings.ToUpper(grant)
		if strings.Contains(g, "WITH GRANT OPTION") || !strings.HasPrefix(g, "GRANT ") {
			return errors.New("akun baca khusus diperlukan")
		}
		parts := strings.SplitN(strings.TrimPrefix(g, "GRANT "), " ON ", 2)
		if len(parts) != 2 {
			return errors.New("role database tidak diizinkan untuk pratinjau")
		}
		for _, privilege := range strings.Split(parts[0], ",") {
			p := strings.TrimSpace(privilege)
			if p != "SELECT" && p != "USAGE" && p != "SHOW VIEW" {
				return errors.New("akun database memiliki hak selain baca")
			}
		}
	}
	return nil
}
func OpenMySQL(ctx context.Context) (*MySQLStore, error) {
	name, user := os.Getenv("TAHSIN_NEXT_DB_DATABASE"), os.Getenv("TAHSIN_NEXT_DB_USERNAME")
	if !regexp.MustCompile(`^[a-zA-Z0-9_]+$`).MatchString(name) || user == "" {
		return nil, errors.New("konfigurasi database pratinjau belum lengkap")
	}
	cfg := mysql.NewConfig()
	cfg.User = user
	cfg.Passwd = os.Getenv("TAHSIN_NEXT_DB_PASSWORD")
	cfg.DBName = name
	cfg.Net = "tcp"
	host := os.Getenv("TAHSIN_NEXT_DB_HOST")
	if host == "" {
		host = "127.0.0.1"
	}
	port := os.Getenv("TAHSIN_NEXT_DB_PORT")
	if port == "" {
		port = "3306"
	}
	cfg.Addr = net.JoinHostPort(host, port)
	cfg.Timeout = 3 * time.Second
	cfg.ReadTimeout = 4 * time.Second
	cfg.WriteTimeout = 4 * time.Second
	cfg.TLSConfig = os.Getenv("TAHSIN_NEXT_DB_TLS")
	if cfg.TLSConfig != "" && cfg.TLSConfig != "true" && cfg.TLSConfig != "false" {
		return nil, errors.New("TLS harus true atau false; sertifikat tidak boleh dilewati")
	}
	db, err := sql.Open("mysql", cfg.FormatDSN())
	if err != nil {
		return nil, errors.New("konfigurasi koneksi tidak valid")
	}
	db.SetMaxOpenConns(4)
	db.SetMaxIdleConns(2)
	db.SetConnMaxLifetime(3 * time.Minute)
	failed := true
	defer func() {
		if failed {
			_ = db.Close()
		}
	}()
	if err = db.PingContext(ctx); err != nil {
		return nil, errors.New("database pratinjau tidak dapat dihubungi")
	}
	r, err := db.QueryContext(ctx, "SHOW GRANTS")
	if err != nil {
		return nil, errors.New("hak akun baca tidak dapat diverifikasi")
	}
	grants := []string{}
	for r.Next() {
		var g string
		if err = r.Scan(&g); err != nil {
			r.Close()
			return nil, errors.New("hak akun baca tidak dapat diverifikasi")
		}
		grants = append(grants, g)
	}
	err = r.Err()
	r.Close()
	if err != nil {
		return nil, errors.New("hak akun baca tidak dapat diverifikasi")
	}
	if err = ValidateGrants(grants); err != nil {
		return nil, err
	}
	failed = false
	return &MySQLStore{DB: db}, nil
}

func (s *MySQLStore) List(ctx context.Context, f Filters) (Page, error) {
	tx, err := s.DB.BeginTx(ctx, &sql.TxOptions{ReadOnly: true, Isolation: sql.LevelRepeatableRead})
	if err != nil {
		return Page{}, err
	}
	defer tx.Rollback()
	var tables int
	err = tx.QueryRowContext(ctx, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('family_students','family_guardians','family_relations')").Scan(&tables)
	if err != nil {
		return Page{}, err
	}
	canonical := false
	if tables == 3 {
		var n int
		if err = tx.QueryRowContext(ctx, "SELECT COUNT(*) FROM family_students").Scan(&n); err != nil {
			return Page{}, err
		}
		canonical = n > 0
	}
	page := Page{Students: []Student{}, Schema: "legacy"}
	if canonical {
		page.Schema = "family"
		err = s.listFamily(ctx, tx, f, &page)
	} else {
		err = s.listLegacy(ctx, tx, f, &page)
	}
	if err != nil {
		return Page{}, err
	}
	if err = tx.Commit(); err != nil {
		return Page{}, err
	}
	return page, nil
}
func (s *MySQLStore) listFamily(ctx context.Context, tx *sql.Tx, f Filters, page *Page) error {
	where := []string{"1=1"}
	args := []any{}
	if f.Scope == "active" {
		where = append(where, "s.school_status='active' AND TRIM(s.kelas)<>''")
	}
	if f.Search != "" {
		where = append(where, "(s.nama_anak LIKE ? OR s.nis LIKE ? OR EXISTS(SELECT 1 FROM family_relations r JOIN family_guardians g ON g.id=r.guardian_id WHERE r.student_id=s.id AND r.is_current=1 AND g.source_name LIKE ?))")
		term := "%" + f.Search + "%"
		args = append(args, term, term, term)
	}
	clause := strings.Join(where, " AND ")
	if err := tx.QueryRowContext(ctx, "SELECT COUNT(*) FROM family_students s WHERE "+clause, args...).Scan(&page.Total); err != nil {
		return err
	}
	args = append(args, f.Limit, (f.Page-1)*f.Limit)
	rows, err := tx.QueryContext(ctx, "SELECT s.id,s.source_namespace,s.source_student_id,s.nis,s.nama_anak,s.kelas,s.school_status,s.snapshot_date FROM family_students s WHERE "+clause+" ORDER BY s.nama_anak,s.id LIMIT ? OFFSET ?", args...)
	if err != nil {
		return err
	}
	for rows.Next() {
		v := Student{Guardians: []Guardian{}}
		if err = rows.Scan(&v.ID, &v.SourceNamespace, &v.SourceStudentID, &v.NIS, &v.Name, &v.Class, &v.Status, &v.SnapshotDate); err != nil {
			rows.Close()
			return err
		}
		page.Students = append(page.Students, v)
	}
	err = rows.Err()
	rows.Close()
	if err != nil || len(page.Students) == 0 {
		return err
	}
	ids := []any{}
	slots := []string{}
	byID := map[int64]int{}
	for i, v := range page.Students {
		ids = append(ids, v.ID)
		slots = append(slots, "?")
		byID[v.ID] = i
	}
	parents, err := tx.QueryContext(ctx, "SELECT r.student_id,g.source_namespace,g.source_parent_id,g.wali_santri_id,g.source_name,g.guardian_type FROM family_relations r JOIN family_guardians g ON g.id=r.guardian_id WHERE r.is_current=1 AND r.student_id IN ("+strings.Join(slots, ",")+") ORDER BY g.guardian_type,g.source_name", ids...)
	if err != nil {
		return err
	}
	defer parents.Close()
	for parents.Next() {
		var id int64
		var g Guardian
		if err = parents.Scan(&id, &g.SourceNamespace, &g.SourceParentID, &g.WaliID, &g.Name, &g.Type); err != nil {
			return err
		}
		i := byID[id]
		page.Students[i].Guardians = append(page.Students[i].Guardians, g)
	}
	return parents.Err()
}
func (s *MySQLStore) listLegacy(ctx context.Context, tx *sql.Tx, f Filters, page *Page) error {
	where := "1=1"
	args := []any{}
	if f.Scope == "active" {
		where = "TRIM(COALESCE(s.kelas,''))<>'' AND LOWER(TRIM(s.kelas))<>'lulus'"
	}
	if f.Search != "" {
		where += " AND (s.nama_anak LIKE ? OR w.nama_bapak LIKE ?)"
		term := "%" + f.Search + "%"
		args = append(args, term, term)
	}
	from := " FROM santri_detail s JOIN wali_santri w ON w.id=s.wali_santri_id WHERE " + where
	if err := tx.QueryRowContext(ctx, "SELECT COUNT(*)"+from, args...).Scan(&page.Total); err != nil {
		return err
	}
	args = append(args, f.Limit, (f.Page-1)*f.Limit)
	rows, err := tx.QueryContext(ctx, "SELECT s.id,s.nama_anak,COALESCE(s.kelas,''),w.id,w.nama_bapak"+from+" ORDER BY s.nama_anak,s.id LIMIT ? OFFSET ?", args...)
	if err != nil {
		return err
	}
	defer rows.Close()
	for rows.Next() {
		v := Student{NIS: "", Guardians: []Guardian{}}
		g := Guardian{Type: "guardian"}
		if err = rows.Scan(&v.ID, &v.Name, &v.Class, &g.WaliID, &g.Name); err != nil {
			return err
		}
		id := v.ID
		v.LegacyStudentID = &id
		v.Status = "unknown"
		if strings.EqualFold(strings.TrimSpace(v.Class), "lulus") {
			v.Status = "graduated"
		} else if strings.TrimSpace(v.Class) != "" {
			v.Status = "active"
		}
		v.Guardians = append(v.Guardians, g)
		page.Students = append(page.Students, v)
	}
	return rows.Err()
}

var _ Store = (*MySQLStore)(nil)
