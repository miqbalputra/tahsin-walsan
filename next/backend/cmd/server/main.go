package main

import (
	"context"
	"errors"
	"log"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"syscall"
	"time"

	"tahsin.local/next/internal/preview"
	"tahsin.local/next/internal/sessionread"
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
	teacherEnabled := os.Getenv("TAHSIN_NEXT_TEACHER_READ_ENABLED") == "1"
	teacherGroups := map[int64]bool{}
	var teacherReader *sessionread.Reader
	if teacherEnabled {
		if !enabled || mode != "mysql" {
			log.Fatal("Baca sesi guru memerlukan layanan MySQL aktif")
		}
		for _, value := range strings.Split(os.Getenv("TAHSIN_NEXT_TEACHER_READ_HALAQOH_IDS"), ",") {
			v := strings.TrimSpace(value)
			id, err := strconv.ParseInt(v, 10, 64)
			if err != nil || id < 1 || strconv.FormatInt(id, 10) != v {
				log.Fatal("Daftar kelompok baca guru wajib valid dan tidak kosong")
			}
			teacherGroups[id] = true
		}
	}
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
		if teacherEnabled {
			teacherReader = &sessionread.Reader{DB: db.DB}
		}
	}
	writeEnabled := os.Getenv("TAHSIN_NEXT_WRITE_ENABLED") == "1"
	writeSecret := os.Getenv("TAHSIN_NEXT_WRITE_SHARED_SECRET")
	writeGroups := map[int64]bool{}
	var writer *sessionread.Writer
	if writeEnabled {
		if !enabled || mode != "mysql" || len(writeSecret) < 32 || writeSecret == secret {
			log.Fatal("Writer staging memerlukan MySQL dan kunci tulis terpisah minimal 32 karakter")
		}
		for _, v := range strings.Split(os.Getenv("TAHSIN_NEXT_WRITE_HALAQOH_IDS"), ",") {
			id, err := strconv.ParseInt(strings.TrimSpace(v), 10, 64)
			if err != nil || id < 1 || strconv.FormatInt(id, 10) != strings.TrimSpace(v) {
				log.Fatal("Kelompok writer staging wajib eksplisit")
			}
			writeGroups[id] = true
		}
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		var err error
		writer, err = sessionread.OpenWriter(ctx)
		cancel()
		if err != nil {
			log.Fatal(err)
		}
		defer writer.DB.Close()
	}
	addr := os.Getenv("TAHSIN_NEXT_LISTEN")
	if addr == "" {
		addr = "127.0.0.1:18090"
	}
	srv := &http.Server{Addr: addr, Handler: (preview.Server{Enabled: enabled, Secret: []byte(secret), Store: store, Mode: mode, TeacherEnabled: teacherEnabled, TeacherGroups: teacherGroups, TeacherReader: teacherReader, WriterEnabled: writeEnabled, WriteSecret: []byte(writeSecret), WriteGroups: writeGroups, Writer: writer}).Handler(), ReadHeaderTimeout: 3 * time.Second, ReadTimeout: 6 * time.Second, WriteTimeout: 7 * time.Second, IdleTimeout: 30 * time.Second, MaxHeaderBytes: 16 << 10}
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
