package app

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

type dataIndukConfig struct {
	Enabled    bool
	BaseURL    *url.URL
	APIKey     string
	UnitID     string
	StaleAfter time.Duration
}

type dataIndukError struct {
	Message string
	Status  int
}

func (e *dataIndukError) Error() string { return e.Message }

func loadDataIndukConfig(getenv func(string) string) (dataIndukConfig, error) {
	config := dataIndukConfig{
		Enabled:    strings.EqualFold(strings.TrimSpace(getenv("DATA_INDUK_ENABLED")), "true") || strings.TrimSpace(getenv("DATA_INDUK_ENABLED")) == "1",
		APIKey:     strings.TrimSpace(getenv("DATA_INDUK_API_KEY")),
		UnitID:     strings.TrimSpace(getenv("DATA_INDUK_UNIT_ID")),
		StaleAfter: 60 * time.Minute,
	}
	if minutes, err := strconv.Atoi(strings.TrimSpace(getenv("DATA_INDUK_SYNC_STALE_MINUTES"))); err == nil && minutes > 0 {
		if minutes < 15 {
			minutes = 15
		}
		if minutes > 525600 {
			minutes = 525600
		}
		config.StaleAfter = time.Duration(minutes) * time.Minute
	}
	rawURL := strings.TrimRight(strings.TrimSpace(getenv("DATA_INDUK_BASE_URL")), "/")
	if rawURL != "" {
		parsed, err := url.Parse(rawURL)
		if err != nil || (parsed.Scheme != "https" && parsed.Scheme != "http") || parsed.Host == "" || parsed.User != nil || parsed.RawQuery != "" || parsed.Fragment != "" {
			return dataIndukConfig{}, errors.New("DATA_INDUK_BASE_URL tidak valid")
		}
		config.BaseURL = parsed
	}
	if len(config.APIKey) > 4096 || !validParticipantText(config.APIKey, 4096, false) || len(config.UnitID) > 100 || !validParticipantText(config.UnitID, 100, false) {
		return dataIndukConfig{}, errors.New("konfigurasi kredensial Data Induk tidak valid")
	}
	return config, nil
}

func (c dataIndukConfig) configured() bool {
	return c.Enabled && c.BaseURL != nil && c.APIKey != "" && c.UnitID != ""
}

func dataIndukHTTPGet(ctx context.Context, client *http.Client, config dataIndukConfig, endpoint string, query url.Values) (json.RawMessage, error) {
	if !config.configured() {
		return nil, &dataIndukError{Message: "Integrasi Data Induk belum dikonfigurasi."}
	}
	base := *config.BaseURL
	base.Path = strings.TrimRight(base.Path, "/") + "/api/v1/integrations/" + strings.TrimLeft(endpoint, "/")
	base.RawPath = ""
	base.RawQuery = query.Encode()
	var lastStatus int
	for attempt := 0; attempt < 3; attempt++ {
		req, err := http.NewRequestWithContext(ctx, http.MethodGet, base.String(), nil)
		if err != nil {
			return nil, &dataIndukError{Message: "Alamat Data Induk tidak valid."}
		}
		req.Header.Set("Accept", "application/json")
		req.Header.Set("X-API-Key", config.APIKey)
		resp, requestErr := client.Do(req)
		if requestErr != nil {
			if attempt == 2 {
				return nil, &dataIndukError{Message: "Data Induk tidak dapat dijangkau."}
			}
			if err = waitDataIndukRetry(ctx, time.Duration(attempt+1)*time.Second); err != nil {
				return nil, err
			}
			continue
		}
		lastStatus = resp.StatusCode
		body, readErr := io.ReadAll(io.LimitReader(resp.Body, 8<<20))
		resp.Body.Close()
		if readErr != nil {
			if attempt == 2 {
				return nil, &dataIndukError{Message: "Respons Data Induk tidak dapat dibaca.", Status: lastStatus}
			}
			if err = waitDataIndukRetry(ctx, time.Duration(attempt+1)*time.Second); err != nil {
				return nil, err
			}
			continue
		}
		if lastStatus >= 200 && lastStatus < 300 {
			var envelope struct {
				Data json.RawMessage `json:"data"`
			}
			if json.Unmarshal(body, &envelope) != nil || len(envelope.Data) == 0 || string(envelope.Data) == "null" {
				return nil, &dataIndukError{Message: "Respons Data Induk tidak sesuai kontrak.", Status: lastStatus}
			}
			return envelope.Data, nil
		}
		retryable := lastStatus == 429 || lastStatus == 500 || lastStatus == 502 || lastStatus == 503 || lastStatus == 504
		if !retryable || attempt == 2 {
			return nil, &dataIndukError{Message: fmt.Sprintf("Permintaan Data Induk gagal (HTTP %d).", lastStatus), Status: lastStatus}
		}
		delay := time.Duration(attempt+1) * time.Second
		if seconds, parseErr := strconv.Atoi(strings.TrimSpace(resp.Header.Get("Retry-After"))); parseErr == nil && seconds > 0 {
			if seconds > 5 {
				seconds = 5
			}
			delay = time.Duration(seconds) * time.Second
		}
		if err = waitDataIndukRetry(ctx, delay); err != nil {
			return nil, err
		}
	}
	return nil, &dataIndukError{Message: "Permintaan Data Induk gagal.", Status: lastStatus}
}

func waitDataIndukRetry(ctx context.Context, delay time.Duration) error {
	timer := time.NewTimer(delay)
	defer timer.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-timer.C:
		return nil
	}
}

func dataIndukFetchAll(ctx context.Context, client *http.Client, config dataIndukConfig, endpoint string, query url.Values) ([]map[string]json.RawMessage, error) {
	items := []map[string]json.RawMessage{}
	cursor := ""
	seen := map[string]struct{}{}
	for pageNumber := 0; pageNumber < 10000; pageNumber++ {
		pageQuery := make(url.Values, len(query)+2)
		for key, values := range query {
			pageQuery[key] = append([]string(nil), values...)
		}
		pageQuery.Set("cursor", cursor)
		pageQuery.Set("limit", "200")
		raw, err := dataIndukHTTPGet(ctx, client, config, endpoint, pageQuery)
		if err != nil {
			return nil, err
		}
		var page struct {
			Items      []map[string]json.RawMessage `json:"items"`
			NextCursor string                       `json:"nextCursor"`
		}
		if json.Unmarshal(raw, &page) != nil || page.Items == nil {
			return nil, &dataIndukError{Message: "Respons koleksi Data Induk tidak valid."}
		}
		items = append(items, page.Items...)
		if page.NextCursor == "" {
			return items, nil
		}
		if page.NextCursor == cursor {
			return nil, &dataIndukError{Message: "Cursor Data Induk tidak maju."}
		}
		if _, exists := seen[page.NextCursor]; exists {
			return nil, &dataIndukError{Message: "Cursor Data Induk berulang."}
		}
		seen[page.NextCursor] = struct{}{}
		cursor = page.NextCursor
	}
	return nil, &dataIndukError{Message: "Data Induk mengembalikan terlalu banyak halaman."}
}

func dataIndukField(row map[string]json.RawMessage, key string) string {
	raw, ok := row[key]
	if !ok || string(raw) == "null" {
		return ""
	}
	var value string
	if json.Unmarshal(raw, &value) == nil {
		return value
	}
	var number json.Number
	if json.Unmarshal(raw, &number) == nil {
		return number.String()
	}
	return ""
}

func dataIndukNormalize(value string) string {
	value = strings.ToLower(strings.TrimSpace(value))
	var normalized strings.Builder
	for _, char := range value {
		if char >= 'a' && char <= 'z' || char >= '0' && char <= '9' {
			normalized.WriteRune(char)
		}
	}
	return normalized.String()
}
