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

	"tahsin.local/next/internal/app"
)

func main() {
	config, err := app.LoadConfig()
	if err != nil {
		log.Fatal(err)
	}
	if err = os.MkdirAll(config.DataDir, 0750); err != nil {
		log.Fatal("APP_DATA_DIR is not writable")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 8*time.Second)
	db, err := app.OpenDatabase(ctx, config)
	cancel()
	if err != nil {
		log.Fatal(err)
	}
	defer db.Close()
	service := app.NewServer(config, db)
	server := &http.Server{
		Addr:              config.Address,
		Handler:           service.Handler(),
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       20 * time.Second,
		WriteTimeout:      30 * time.Second,
		IdleTimeout:       60 * time.Second,
		MaxHeaderBytes:    32 << 10,
	}
	stop := make(chan os.Signal, 1)
	signal.Notify(stop, os.Interrupt, syscall.SIGTERM)
	go func() {
		<-stop
		ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
		defer cancel()
		_ = server.Shutdown(ctx)
	}()
	log.Printf("tahsin Go+Vue app listening on %s", config.Address)
	if err = server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		log.Fatal("app server stopped unexpectedly")
	}
}
