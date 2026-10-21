package main

import (
	"context"
	"database/sql"
	"flag"
	"fmt"
	"log"
	"os"
	"time"

	"tahsin.local/next/internal/app"
)

const sessionMigration = `CREATE TABLE IF NOT EXISTS tahsin_app_sessions (
  session_hash BINARY(32) NOT NULL,
  user_id INT NOT NULL,
  csrf_token CHAR(43) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  last_seen_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  user_agent VARCHAR(255) NOT NULL,
  PRIMARY KEY (session_hash),
  KEY idx_tahsin_app_sessions_user_expiry (user_id, expires_at),
  KEY idx_tahsin_app_sessions_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`

func main() {
	checkOnly := flag.Bool("check", false, "verify the additive Go session table without changing the database")
	flag.Parse()
	if os.Getenv("MIGRATION_DB_USERNAME") == "" || os.Getenv("MIGRATION_DB_PASSWORD") == "" {
		log.Fatal("set MIGRATION_DB_USERNAME and MIGRATION_DB_PASSWORD to a temporary schema-migration account")
	}
	config, err := app.LoadConfigFrom(func(key string) string {
		switch key {
		case "DB_USERNAME":
			return os.Getenv("MIGRATION_DB_USERNAME")
		case "DB_PASSWORD":
			return os.Getenv("MIGRATION_DB_PASSWORD")
		default:
			return os.Getenv(key)
		}
	})
	if err != nil {
		log.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	db, err := app.OpenDatabase(ctx, config)
	if err != nil {
		log.Fatal(err)
	}
	defer db.Close()
	var database string
	if err = db.QueryRowContext(ctx, "SELECT DATABASE()").Scan(&database); err != nil || database != config.DatabaseName {
		log.Fatal("migration connection database does not match DB_DATABASE")
	}
	if !*checkOnly {
		if _, err = db.ExecContext(ctx, sessionMigration); err != nil {
			log.Fatal("additive session migration failed; existing Tahsin data was not modified")
		}
	}
	if err = verifySessionTable(ctx, db); err != nil {
		log.Fatal(err)
	}
	fmt.Printf("OK tahsin_app_sessions present in %s; existing application tables and IDs were not changed\n", database)
}

func verifySessionTable(ctx context.Context, db *sql.DB) error {
	var count int
	if err := db.QueryRowContext(ctx, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='tahsin_app_sessions' AND column_name IN ('session_hash','user_id','csrf_token','created_at','last_seen_at','expires_at','ip_address','user_agent')").Scan(&count); err != nil || count != 8 {
		return fmt.Errorf("tahsin_app_sessions does not match the expected additive schema")
	}
	return nil
}
