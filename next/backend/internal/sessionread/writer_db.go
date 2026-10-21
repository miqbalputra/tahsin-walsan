package sessionread

import (
	"context"
	"database/sql"
	"errors"
	"github.com/go-sql-driver/mysql"
	"net"
	"os"
	"regexp"
	"strings"
	"time"
)

// Writes are limited to business tables and one inert mutex column.
// Ownership and generation tables stay SELECT-only.
func ValidateWriterGrants(grants []string, database string) error {
	allowed := map[string]map[string]bool{
		"presensi": {"INSERT": true, "UPDATE": true}, "tahsin_autosave_drafts": {"INSERT": true, "UPDATE": true},
		"tahsin_autosave_operations": {"INSERT": true}, "tahsin_autosave_sessions": {"INSERT": true, "UPDATE": true}, "activity_logs": {"INSERT": true},
		"tahsin_writer_locks": {"UPDATE(MUTEX)": true},
	}
	if len(grants) == 0 {
		return errors.New("dedicated writer grants required")
	}
	for _, grant := range grants {
		g := strings.ToUpper(grant)
		if !strings.HasPrefix(g, "GRANT ") || strings.Contains(g, "WITH GRANT OPTION") {
			return errors.New("unsafe writer grants")
		}
		parts := strings.SplitN(g[6:], " ON ", 2)
		if len(parts) != 2 {
			return errors.New("writer roles are not allowed")
		}
		target := strings.SplitN(parts[1], " TO ", 2)[0]
		target = strings.ReplaceAll(target, "`", "")
		scope := strings.Split(target, ".")
		if len(scope) != 2 {
			return errors.New("invalid writer grant scope")
		}
		for _, p := range strings.Split(parts[0], ",") {
			p = strings.TrimSpace(p)
			if p == "USAGE" && target == "*.*" {
				continue
			}
			if scope[0] != strings.ToUpper(database) {
				return errors.New("writer grants cross database boundary")
			}
			if p == "SELECT" || p == "SHOW VIEW" {
				continue
			}
			normalizedPrivilege := strings.ReplaceAll(strings.ReplaceAll(p, "`", ""), " ", "")
			if !allowed[strings.ToLower(scope[1])][normalizedPrivilege] {
				return errors.New("writer grants include DDL, control mutations or unrelated writes")
			}
		}
	}
	return nil
}
func OpenWriter(ctx context.Context) (*Writer, error) {
	name := os.Getenv("TAHSIN_NEXT_WRITE_DB_DATABASE")
	user := os.Getenv("TAHSIN_NEXT_WRITE_DB_USERNAME")
	if !regexp.MustCompile(`^tahsin_test_autosave_[a-z0-9_]+$`).MatchString(name) || user == "" {
		return nil, errors.New("Go writes are restricted to explicit synthetic staging databases")
	}
	host, port := os.Getenv("TAHSIN_NEXT_WRITE_DB_HOST"), os.Getenv("TAHSIN_NEXT_WRITE_DB_PORT")
	if host == "" || port == "" {
		return nil, errors.New("explicit staging writer address required")
	}
	cfg := mysql.NewConfig()
	cfg.User = user
	cfg.Passwd = os.Getenv("TAHSIN_NEXT_WRITE_DB_PASSWORD")
	cfg.DBName = name
	cfg.Net = "tcp"
	cfg.Addr = net.JoinHostPort(host, port)
	cfg.Params = map[string]string{"time_zone": "'+07:00'"}
	cfg.Timeout = 3 * time.Second
	cfg.ReadTimeout = 5 * time.Second
	cfg.WriteTimeout = 5 * time.Second
	cfg.TLSConfig = os.Getenv("TAHSIN_NEXT_WRITE_DB_TLS")
	if cfg.TLSConfig != "" && cfg.TLSConfig != "false" && cfg.TLSConfig != "true" {
		return nil, errors.New("verified writer TLS required")
	}
	db, err := sql.Open("mysql", cfg.FormatDSN())
	if err != nil {
		return nil, err
	}
	db.SetMaxOpenConns(4)
	db.SetMaxIdleConns(2)
	db.SetConnMaxLifetime(3 * time.Minute)
	failed := true
	defer func() {
		if failed {
			db.Close()
		}
	}()
	rows, err := db.QueryContext(ctx, "SHOW GRANTS")
	if err != nil {
		return nil, err
	}
	grants := []string{}
	for rows.Next() {
		var g string
		if err = rows.Scan(&g); err != nil {
			rows.Close()
			return nil, err
		}
		grants = append(grants, g)
	}
	err = rows.Err()
	rows.Close()
	if err != nil {
		return nil, err
	}
	if err = ValidateWriterGrants(grants, name); err != nil {
		return nil, err
	}
	var actor, expected string
	var version int
	err = db.QueryRowContext(ctx, "SELECT SUBSTRING_INDEX(USER(),'@',1),go_user,schema_version FROM tahsin_writer_policy WHERE id=1").Scan(&actor, &expected, &version)
	if err != nil || actor != expected || version != 1 {
		return nil, errors.New("writer identity or staging fence policy differs")
	}
	failed = false
	return &Writer{DB: db}, nil
}
