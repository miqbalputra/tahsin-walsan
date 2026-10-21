package main

import (
	"context"
	"errors"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"tahsin.local/next/internal/preview"
)

func main() {
	enabled := os.Getenv("TAHSIN_NEXT_ENABLED") == "1"
	secret := os.Getenv("TAHSIN_NEXT_SHARED_SECRET")
	if enabled && len(secret) < 32 {
		log.Fatal("TAHSIN_NEXT_SHARED_SECRET memerlukan minimal 32 karakter")
	}
	mode := os.Getenv("TAHSIN_NEXT_DATA_SOURCE")
	if mode == "" {
		mode = "demo"
	}
	var store preview.Store = preview.DemoStore{}
	if mode != "demo" && mode != "mysql" {
		log.Fatal("TAHSIN_NEXT_DATA_SOURCE harus demo atau mysql")
	}
	if enabled && mode == "mysql" {
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		db, err := preview.OpenMySQL(ctx)
		cancel()
		if err != nil {
			log.Fatal(err)
		}
		defer db.DB.Close()
		store = db
	}
	addr := os.Getenv("TAHSIN_NEXT_LISTEN")
	if addr == "" {
		addr = "127.0.0.1:18090"
	}
	srv := &http.Server{Addr: addr, Handler: (preview.Server{Enabled: enabled, Secret: []byte(secret), Store: store, Mode: mode}).Handler(), ReadHeaderTimeout: 3 * time.Second, ReadTimeout: 5 * time.Second, WriteTimeout: 6 * time.Second, IdleTimeout: 30 * time.Second, MaxHeaderBytes: 16 << 10}
	stop := make(chan os.Signal, 1)
	signal.Notify(stop, os.Interrupt, syscall.SIGTERM)
	go func() {
		<-stop
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		_ = srv.Shutdown(ctx)
	}()
	log.Printf("tahsin-next listening; enabled=%t source=%s", enabled, mode)
	if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		log.Fatal("layanan pratinjau tidak dapat dijalankan")
	}
}
