package app

import (
	"errors"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"strconv"
	"strings"
)

type Config struct {
	Environment     string
	ProductionReady bool
	Address         string
	StaticDir       string
	DataDir         string
	DatabaseHost    string
	DatabasePort    string
	DatabaseName    string
	DatabaseUser    string
	DatabasePass    string
	DatabaseTLSCA   string
	DatabaseCharset string
	Timezone        string
	CookieName      string
	SessionDays     int
	MaxUploadMB     int64
}

func LoadConfig() (Config, error) { return LoadConfigFrom(os.Getenv) }

func LoadConfigFrom(getenv func(string) string) (Config, error) {
	c := Config{
		Environment:     value(getenv, "APP_ENV", "production"),
		ProductionReady: strings.TrimSpace(getenv("APP_PRODUCTION_READY")) == "1",
		Address:         value(getenv, "APP_HTTP_ADDR", "0.0.0.0:8080"),
		StaticDir:       value(getenv, "APP_STATIC_DIR", "/app/web"),
		DataDir:         value(getenv, "APP_DATA_DIR", "/var/lib/tahsin"),
		DatabaseHost:    value(getenv, "DB_HOST", "mysql"),
		DatabasePort:    value(getenv, "DB_PORT", "3306"),
		DatabaseName:    strings.TrimSpace(getenv("DB_DATABASE")),
		DatabaseUser:    strings.TrimSpace(getenv("DB_USERNAME")),
		DatabasePass:    getenv("DB_PASSWORD"),
		DatabaseTLSCA:   strings.TrimSpace(getenv("DB_TLS_CA_FILE")),
		DatabaseCharset: value(getenv, "DB_CHARSET", "utf8mb4"),
		Timezone:        value(getenv, "APP_TIMEZONE", "Asia/Jakarta"),
		CookieName:      value(getenv, "AUTH_COOKIE_NAME", "tahsin_session"),
		SessionDays:     30,
		MaxUploadMB:     512,
	}
	if raw := strings.TrimSpace(getenv("AUTH_SESSION_DAYS")); raw != "" {
		n, err := strconv.Atoi(raw)
		if err != nil || n < 1 || n > 90 {
			return Config{}, errors.New("AUTH_SESSION_DAYS must be between 1 and 90")
		}
		c.SessionDays = n
	}
	if raw := strings.TrimSpace(getenv("BACKUP_MAX_UPLOAD_MB")); raw != "" {
		n, err := strconv.ParseInt(raw, 10, 64)
		if err != nil || n < 1 || n > 2048 {
			return Config{}, errors.New("BACKUP_MAX_UPLOAD_MB must be between 1 and 2048")
		}
		c.MaxUploadMB = n
	}
	if c.DatabaseName == "" || !safeIdentifier(c.DatabaseName) || c.DatabaseUser == "" {
		return Config{}, errors.New("DB_DATABASE and DB_USERNAME are required")
	}
	if c.Environment == "production" && c.DatabasePass == "" {
		return Config{}, errors.New("DB_PASSWORD is required in production")
	}
	if c.DatabaseCharset != "utf8mb4" && c.DatabaseCharset != "utf8" {
		return Config{}, errors.New("DB_CHARSET must be utf8mb4 or utf8")
	}
	if c.DatabaseHost == "" || net.ParseIP(c.DatabaseHost) == nil && strings.ContainsAny(c.DatabaseHost, "/\\ \t\r\n") {
		return Config{}, errors.New("DB_HOST is invalid")
	}
	if port, err := strconv.Atoi(c.DatabasePort); err != nil || port < 1 || port > 65535 {
		return Config{}, errors.New("DB_PORT is invalid")
	}
	if c.Environment != "production" && c.Environment != "staging" && c.Environment != "development" {
		return Config{}, fmt.Errorf("APP_ENV must be production, staging, or development")
	}
	if c.CookieName == "" || strings.ContainsAny(c.CookieName, " ;\t\r\n") {
		return Config{}, errors.New("AUTH_COOKIE_NAME is invalid")
	}
	if !absolutePath(c.DataDir) {
		return Config{}, errors.New("APP_DATA_DIR must be an absolute path")
	}
	if !absolutePath(c.StaticDir) {
		return Config{}, errors.New("APP_STATIC_DIR must be an absolute path")
	}
	return c, nil
}

func absolutePath(value string) bool {
	return filepath.IsAbs(value) || strings.HasPrefix(value, "/")
}

func value(getenv func(string) string, key, fallback string) string {
	if v := strings.TrimSpace(getenv(key)); v != "" {
		return v
	}
	return fallback
}

func safeIdentifier(value string) bool {
	if value == "" {
		return false
	}
	for _, r := range value {
		if !(r >= 'a' && r <= 'z' || r >= 'A' && r <= 'Z' || r >= '0' && r <= '9' || r == '_') {
			return false
		}
	}
	return true
}
