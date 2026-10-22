package main

import (
	"context"
	"encoding/json"
	"flag"
	"log"
	"os"
	"time"

	"tahsin.local/next/internal/app"
)

func main() {
	scope := flag.String("scope", "changes", "Data Induk sync scope: all, references, roster, teachers, or changes")
	flag.Parse()
	if flag.NArg() > 0 {
		*scope = flag.Arg(0)
	}
	if !validScope(*scope) {
		log.Fatal("invalid scope: choose all, references, roster, teachers, or changes")
	}
	config, err := app.LoadConfig()
	if err != nil {
		log.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Minute)
	defer cancel()
	db, err := app.OpenDatabase(ctx, config)
	if err != nil {
		log.Fatal("database connection failed")
	}
	defer db.Close()
	stats, err := app.SyncDataInduk(ctx, db, *scope)
	if err != nil {
		log.Fatal(err)
	}
	if err = json.NewEncoder(os.Stdout).Encode(map[string]any{"ok": true, "scope": *scope, "stats": stats}); err != nil {
		log.Fatal("result could not be written")
	}
}

func validScope(scope string) bool {
	switch scope {
	case "all", "references", "roster", "teachers", "changes":
		return true
	default:
		return false
	}
}
