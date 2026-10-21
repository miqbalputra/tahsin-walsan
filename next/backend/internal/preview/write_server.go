package preview

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"
	"tahsin.local/next/internal/sessionread"
	"time"
)

func WriteSignature(secret []byte, method, path, query, user, role, stamp, generation, requestHash string, body []byte) string {
	digest := sha256.Sum256(body)
	mac := hmac.New(sha256.New, secret)
	fmt.Fprint(mac, strings.Join([]string{"v2", method, path, query, user, role, stamp, generation, requestHash, hex.EncodeToString(digest[:])}, "\n"))
	return hex.EncodeToString(mac.Sum(nil))
}
func (s Server) handleWrite(w http.ResponseWriter, r *http.Request) {
	if !s.Enabled || !s.WriterEnabled || s.Writer == nil || s.Mode != "mysql" {
		apiError(w, 404, "Tidak ditemukan.")
		return
	}
	if r.Method != "POST" {
		w.Header().Set("Allow", "POST")
		apiError(w, 405, "Metode tidak didukung.")
		return
	}
	body, err := io.ReadAll(io.LimitReader(r.Body, 65537))
	if err != nil {
		apiError(w, 422, "Format permintaan tidak valid.")
		return
	}
	if len(body) > 65536 {
		apiError(w, 413, "Permintaan terlalu besar.")
		return
	}
	user, role, stamp, epoch, receiptHash := r.Header.Get("X-Tahsin-User"), r.Header.Get("X-Tahsin-Role"), r.Header.Get("X-Tahsin-Time"), r.Header.Get("X-Tahsin-Writer-Generation"), r.Header.Get("X-Tahsin-Request-Hash")
	uid, ue := strconv.ParseInt(user, 10, 64)
	generation, ge := strconv.ParseInt(epoch, 10, 64)
	seconds, se := strconv.ParseInt(stamp, 10, 64)
	decodedHash, he := hex.DecodeString(receiptHash)
	now := time.Now()
	if s.Now != nil {
		now = s.Now()
	}
	if ue != nil || uid < 1 || strconv.FormatInt(uid, 10) != user || role != "ustadz" || ge != nil || generation < 1 || strconv.FormatInt(generation, 10) != epoch || se != nil || seconds > now.Unix()+5 || seconds < now.Unix()-30 || he != nil || len(decodedHash) != 32 || len(s.WriteSecret) < 32 {
		apiError(w, 401, "Akses penyimpanan tidak valid.")
		return
	}
	expected := WriteSignature(s.WriteSecret, r.Method, r.URL.Path, r.URL.RawQuery, user, role, stamp, epoch, receiptHash, body)
	if !hmac.Equal([]byte(expected), []byte(r.Header.Get("X-Tahsin-Write-Signature"))) {
		apiError(w, 401, "Akses penyimpanan tidak valid.")
		return
	}
	group, date, err := sessionread.ParseQuery(r.URL.Query())
	if err != nil {
		apiError(w, 422, "Kelompok atau tanggal tidak valid.")
		return
	}
	if !s.WriteGroups[group] {
		apiError(w, 404, "Tidak ditemukan.")
		return
	}
	request, err := sessionread.ParseWrite(body, group, date)
	if err == nil {
		ctx, cancel := context.WithTimeout(r.Context(), 5*time.Second)
		defer cancel()
		var result any
		result, err = s.Writer.Execute(ctx, uid, generation, request, receiptHash, now)
		if err == nil {
			writeJSON(w, 200, map[string]any{"status": "success", "data": result})
			return
		}
	}
	status, message, details := 503, "Server belum dapat menyimpan. Draft dipertahankan dan pengiriman akan dicoba lagi.", map[string]any{}
	var domain *sessionread.Error
	var writeError *sessionread.WriteError
	if errors.As(err, &domain) {
		status, message = domain.Status, domain.Message
	} else if errors.As(err, &writeError) {
		status, message = writeError.Status, writeError.Message
		if writeError.Entry != nil {
			details["entry"] = writeError.Entry
		}
	}
	writeJSON(w, status, map[string]any{"status": "error", "message": message, "details": details})
}
