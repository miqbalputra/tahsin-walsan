package app

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"database/sql"
	"errors"
	"net"
	"os"
	"time"

	"github.com/go-sql-driver/mysql"
)

func OpenDatabase(ctx context.Context, c Config) (*sql.DB, error) {
	loc, err := time.LoadLocation(c.Timezone)
	if err != nil {
		return nil, errors.New("APP_TIMEZONE is invalid")
	}
	cfg := mysql.NewConfig()
	cfg.User = c.DatabaseUser
	cfg.Passwd = c.DatabasePass
	cfg.Net = "tcp"
	cfg.Addr = net.JoinHostPort(c.DatabaseHost, c.DatabasePort)
	cfg.DBName = c.DatabaseName
	cfg.ParseTime = true
	cfg.Loc = loc
	cfg.Params = map[string]string{"time_zone": "'+07:00'", "charset": c.DatabaseCharset}
	cfg.Timeout = 5 * time.Second
	cfg.ReadTimeout = 15 * time.Second
	cfg.WriteTimeout = 15 * time.Second
	if c.DatabaseTLSCA != "" {
		pem, readErr := os.ReadFile(c.DatabaseTLSCA)
		if readErr != nil {
			return nil, errors.New("DB_TLS_CA_FILE cannot be read")
		}
		roots := x509.NewCertPool()
		if !roots.AppendCertsFromPEM(pem) {
			return nil, errors.New("DB_TLS_CA_FILE contains no valid certificates")
		}
		if err = mysql.RegisterTLSConfig("tahsin-db", &tls.Config{MinVersion: tls.VersionTLS12, RootCAs: roots, ServerName: c.DatabaseHost}); err != nil {
			return nil, errors.New("database TLS configuration is invalid")
		}
		cfg.TLSConfig = "tahsin-db"
	}
	db, err := sql.Open("mysql", cfg.FormatDSN())
	if err != nil {
		return nil, errors.New("database configuration is invalid")
	}
	db.SetMaxOpenConns(20)
	db.SetMaxIdleConns(8)
	db.SetConnMaxLifetime(3 * time.Minute)
	db.SetConnMaxIdleTime(90 * time.Second)
	if err = db.PingContext(ctx); err != nil {
		_ = db.Close()
		return nil, errors.New("database is not reachable")
	}
	return db, nil
}
