package app

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"net/http"
	"strconv"
	"strings"
	"time"
)

type dataIndukStateView struct {
	ChangeCursor string  `json:"change_cursor"`
	LastSuccess  *string `json:"last_success_at"`
	LastErrorAt  *string `json:"last_error_at"`
	LastError    string  `json:"last_error"`
	Stale        bool    `json:"stale"`
}

type dataIndukRunView struct {
	ID         int64          `json:"id"`
	Scope      string         `json:"scope"`
	Status     string         `json:"status"`
	StartedAt  string         `json:"started_at"`
	FinishedAt *string        `json:"finished_at"`
	Stats      map[string]any `json:"stats"`
	Error      string         `json:"error_message"`
}

type dataIndukConflictView struct {
	ID          int64  `json:"id"`
	EntityType  string `json:"entity_type"`
	MasterID    string `json:"master_id"`
	LocalID     *int64 `json:"local_id"`
	Reason      string `json:"reason"`
	DisplayName string `json:"display_name"`
	CreatedAt   string `json:"created_at"`
}

func (s *Server) handleDataInduk(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		methodNotAllowed(w, http.MethodGet)
		return
	}
	config, configErr := loadDataIndukConfig(s.getenv)
	if configErr != nil {
		writeAPIError(w, http.StatusServiceUnavailable, configErr.Error())
		return
	}
	ready, err := dataIndukSchemaReady(r.Context(), s.DB)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Status migrasi Data Induk belum dapat diperiksa.")
		return
	}
	data := map[string]any{"configured": config.configured(), "schema_ready": ready, "stale_after_minutes": int(config.StaleAfter.Minutes()), "state": dataIndukStateView{}, "runs": []dataIndukRunView{}, "conflicts": []dataIndukConflictView{}, "snapshot_counts": map[string]int{"wali": 0, "anak": 0, "ustadz": 0}}
	if !ready {
		writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": data})
		return
	}
	state, err := readDataIndukState(r.Context(), s.DB, config.StaleAfter)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Status sinkronisasi Data Induk belum dapat dibaca.")
		return
	}
	runs, err := readDataIndukRuns(r.Context(), s.DB)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Riwayat sinkronisasi Data Induk belum dapat dibaca.")
		return
	}
	conflicts, err := readDataIndukConflicts(r.Context(), s.DB)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Konflik pemetaan Data Induk belum dapat dibaca.")
		return
	}
	counts, err := readDataIndukCounts(r.Context(), s.DB)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Jumlah snapshot Data Induk belum dapat dibaca.")
		return
	}
	data["state"], data["runs"], data["conflicts"], data["snapshot_counts"] = state, runs, conflicts, counts
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": data})
}

func readDataIndukState(ctx context.Context, db *sql.DB, staleAfter time.Duration) (dataIndukStateView, error) {
	var state dataIndukStateView
	var lastSuccess, lastErrorAt sql.NullTime
	var lastError sql.NullString
	err := db.QueryRowContext(ctx, "SELECT change_cursor,last_success_at,last_error_at,last_error FROM data_induk_sync_state WHERE id=1").Scan(&state.ChangeCursor, &lastSuccess, &lastErrorAt, &lastError)
	if err != nil {
		return state, err
	}
	if lastSuccess.Valid {
		value := lastSuccess.Time.Format("2006-01-02 15:04:05")
		state.LastSuccess = &value
		state.Stale = time.Since(lastSuccess.Time) > staleAfter
	}
	if lastErrorAt.Valid {
		value := lastErrorAt.Time.Format("2006-01-02 15:04:05")
		state.LastErrorAt = &value
	}
	if lastError.Valid {
		state.LastError = lastError.String
	}
	return state, nil
}

func readDataIndukRuns(ctx context.Context, db *sql.DB) ([]dataIndukRunView, error) {
	rows, err := db.QueryContext(ctx, "SELECT id,scope,status,DATE_FORMAT(started_at,'%Y-%m-%d %H:%i:%s'),DATE_FORMAT(finished_at,'%Y-%m-%d %H:%i:%s'),stats_json,error_message FROM data_induk_sync_runs ORDER BY id DESC LIMIT 10")
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := []dataIndukRunView{}
	for rows.Next() {
		var item dataIndukRunView
		var finished, stats, failure sql.NullString
		if err = rows.Scan(&item.ID, &item.Scope, &item.Status, &item.StartedAt, &finished, &stats, &failure); err != nil {
			return nil, err
		}
		if finished.Valid {
			item.FinishedAt = &finished.String
		}
		if failure.Valid {
			item.Error = failure.String
		}
		item.Stats = map[string]any{}
		if stats.Valid {
			_ = json.Unmarshal([]byte(stats.String), &item.Stats)
		}
		items = append(items, item)
	}
	return items, rows.Err()
}

func readDataIndukConflicts(ctx context.Context, db *sql.DB) ([]dataIndukConflictView, error) {
	rows, err := db.QueryContext(ctx, "SELECT id,entity_type,master_id,local_id,reason,payload_json,DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s') FROM data_induk_sync_conflicts WHERE status='pending' ORDER BY created_at DESC LIMIT 50")
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := []dataIndukConflictView{}
	for rows.Next() {
		var item dataIndukConflictView
		var localID sql.NullInt64
		var payload sql.NullString
		if err = rows.Scan(&item.ID, &item.EntityType, &item.MasterID, &localID, &item.Reason, &payload, &item.CreatedAt); err != nil {
			return nil, err
		}
		if localID.Valid {
			item.LocalID = &localID.Int64
		}
		if payload.Valid {
			var preview struct {
				FullName string `json:"fullName"`
			}
			if json.Unmarshal([]byte(payload.String), &preview) == nil {
				item.DisplayName = preview.FullName
			}
		}
		items = append(items, item)
	}
	return items, rows.Err()
}

func readDataIndukCounts(ctx context.Context, db *sql.DB) (map[string]int, error) {
	counts := map[string]int{}
	for key, query := range map[string]string{
		"wali":   "SELECT COUNT(*) FROM wali_santri WHERE data_induk_guardian_id IS NOT NULL",
		"anak":   "SELECT COUNT(*) FROM santri_detail WHERE data_induk_student_id IS NOT NULL",
		"ustadz": "SELECT COUNT(*) FROM users WHERE data_induk_teacher_id IS NOT NULL",
	} {
		var count int
		if err := db.QueryRowContext(ctx, query).Scan(&count); err != nil {
			return nil, err
		}
		counts[key] = count
	}
	return counts, nil
}

func (s *Server) handleDataIndukSync(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		methodNotAllowed(w, http.MethodPost)
		return
	}
	var input struct {
		Scope string `json:"scope"`
	}
	if err := decodeSmallJSON(w, r, 4096, &input); err != nil {
		writeAPIError(w, http.StatusUnprocessableEntity, "Pilihan scope sinkronisasi tidak valid.")
		return
	}
	if input.Scope == "" {
		input.Scope = "all"
	}
	stats, err := s.syncDataInduk(r.Context(), input.Scope)
	if err != nil {
		status := http.StatusServiceUnavailable
		if strings.Contains(err.Error(), "migrasi") || strings.Contains(err.Error(), "scope") {
			status = http.StatusConflict
		}
		var integrationError *dataIndukError
		if errors.As(err, &integrationError) && integrationError.Status > 0 {
			status = http.StatusBadGateway
		}
		writeAPIError(w, status, err.Error())
		return
	}
	if auth, ok := currentAuth(r); ok {
		encoded, _ := json.Marshal(stats)
		_, _ = s.DB.ExecContext(r.Context(), "INSERT INTO activity_logs(user_id,username,action,description,ip_address) VALUES(?,?,?,?,?)", auth.User.ID, auth.User.Username, "DATA_INDUK_SYNC", truncateUTF8("Sinkronisasi Data Induk scope="+input.Scope+" stats="+string(encoded), 255), clientIP(r))
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"scope": input.Scope, "stats": stats}})
}

func (s *Server) handleDataIndukConflict(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		methodNotAllowed(w, http.MethodPost)
		return
	}
	conflictID, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil || conflictID < 1 || strconv.FormatInt(conflictID, 10) != r.PathValue("id") {
		writeAPIError(w, http.StatusUnprocessableEntity, "ID konflik tidak valid.")
		return
	}
	var input struct {
		Decision string `json:"decision"`
	}
	if err = decodeSmallJSON(w, r, 4096, &input); err != nil || (input.Decision != "approve" && input.Decision != "ignore") {
		writeAPIError(w, http.StatusUnprocessableEntity, "Keputusan konflik tidak valid.")
		return
	}
	auth, _ := currentAuth(r)
	tx, err := s.DB.BeginTx(r.Context(), nil)
	if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Konflik belum dapat diproses.")
		return
	}
	defer tx.Rollback()
	var kind, masterID, reason, status string
	var localID sql.NullInt64
	if err = tx.QueryRowContext(r.Context(), "SELECT entity_type,master_id,local_id,reason,status FROM data_induk_sync_conflicts WHERE id=? FOR UPDATE", conflictID).Scan(&kind, &masterID, &localID, &reason, &status); errors.Is(err, sql.ErrNoRows) {
		writeAPIError(w, http.StatusNotFound, "Konflik tidak ditemukan.")
		return
	} else if err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Konflik belum dapat dibaca.")
		return
	}
	if status != "pending" {
		writeAPIError(w, http.StatusConflict, "Konflik sudah diproses.")
		return
	}
	if input.Decision == "approve" {
		if !localID.Valid {
			writeAPIError(w, http.StatusConflict, "Konflik tidak memiliki kandidat lokal untuk disetujui.")
			return
		}
		table, column := dataIndukMappingColumn(kind)
		if table == "" {
			writeAPIError(w, http.StatusConflict, "Jenis konflik tidak didukung.")
			return
		}
		query := "UPDATE " + table + " SET " + column + "=? WHERE id=? AND (" + column + " IS NULL OR " + column + "=?)"
		result, updateErr := tx.ExecContext(r.Context(), query, masterID, localID.Int64, masterID)
		if updateErr != nil {
			writeAPIError(w, http.StatusConflict, "UUID pusat sudah dipakai atau pemetaan tidak dapat diperbarui.")
			return
		}
		affected, _ := result.RowsAffected()
		if affected != 1 {
			writeAPIError(w, http.StatusConflict, "Record lokal sudah berubah; muat ulang konflik.")
			return
		}
	}
	resolved := "ignored"
	if input.Decision == "approve" {
		resolved = "approved"
	}
	if _, err = tx.ExecContext(r.Context(), "UPDATE data_induk_sync_conflicts SET status=?,resolved_at=NOW(),resolved_by=? WHERE id=? AND status='pending'", resolved, auth.User.ID, conflictID); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Status konflik belum dapat disimpan.")
		return
	}
	if _, err = tx.ExecContext(r.Context(), "INSERT INTO activity_logs(user_id,username,action,description,ip_address) VALUES(?,?,?,?,?)", auth.User.ID, auth.User.Username, "DATA_INDUK_CONFLICT", truncateUTF8("Konflik Data Induk "+strconv.FormatInt(conflictID, 10)+" diproses: "+resolved+"; "+reason, 255), clientIP(r)); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Log perubahan konflik belum dapat disimpan.")
		return
	}
	if err = tx.Commit(); err != nil {
		writeAPIError(w, http.StatusServiceUnavailable, "Konflik belum dapat diselesaikan.")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "data": map[string]any{"conflict_id": conflictID, "status": resolved}})
}

func dataIndukMappingColumn(kind string) (string, string) {
	switch kind {
	case "guardian":
		return "wali_santri", "data_induk_guardian_id"
	case "student":
		return "santri_detail", "data_induk_student_id"
	case "teacher":
		return "users", "data_induk_teacher_id"
	default:
		return "", ""
	}
}
