package sessionread

import (
	"bytes"
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"
)

type WriteError struct {
	Status  int
	Message string
	Entry   *Entry
}

func (e *WriteError) Error() string { return e.Message }

type Writer struct{ DB *sql.DB }
type WriteRequest struct {
	Action, Operation, Program, Context, Official string
	Group, Wali, Draft                            int64
	Date                                          string
	Payload                                       Payload
	Revisions                                     map[string]string
}

var operationPattern = regexp.MustCompile(`^[a-zA-Z0-9_-]{16,80}$`)

func rawString(raw json.RawMessage) string {
	var v any
	d := json.NewDecoder(bytes.NewReader(raw))
	d.UseNumber()
	if d.Decode(&v) != nil {
		return ""
	}
	switch value := v.(type) {
	case string:
		return value
	case bool:
		if value {
			return "1"
		}
		return ""
	case json.Number:
		return string(value)
	default:
		return ""
	}
}
func scalarPayload(input map[string]json.RawMessage) (Payload, error) {
	values := []string{}
	for _, k := range []string{"status", "alasan", "jenis_materi", "jilid", "nama_surat_dari", "nama_surat_sampai", "halaman_dari", "halaman_sampai", "hasil_talaqqi"} {
		raw := bytes.TrimSpace(input[k])
		if len(raw) > 0 && (raw[0] == '{' || raw[0] == '[') {
			return Payload{}, &WriteError{422, "Format isian tidak valid.", nil}
		}
		v := rawString(raw)
		limit := 30
		if k == "alasan" {
			limit = 500
		} else if strings.HasPrefix(k, "nama_surat") {
			limit = 100
		}
		if !utf8.ValidString(v) || utf8.RuneCountInString(v) > limit {
			return Payload{}, &WriteError{422, "Isian terlalu panjang atau tidak valid.", nil}
		}
		values = append(values, v)
	}
	p := Payload{values[0], values[1], values[2], values[3], values[4], values[5], values[6], values[7], values[8]}
	if p.Status != "" && p.Status != "H" && p.Status != "S" && p.Status != "I" && p.Status != "A" {
		return p, &WriteError{422, "Pilihan isian tidak valid.", nil}
	}
	if p.Material != "" && p.Material != "Iqro" && p.Material != "Al Quran" || p.Result != "" && p.Result != "Lulus" && p.Result != "Ulang" {
		return p, &WriteError{422, "Pilihan isian tidak valid.", nil}
	}
	return p, nil
}
func ParseWrite(body []byte, group int64, requested string) (WriteRequest, error) {
	r := WriteRequest{Group: group, Date: requested}
	var input map[string]json.RawMessage
	if !utf8.Valid(body) || json.Unmarshal(body, &input) != nil || input == nil {
		return r, &WriteError{422, "Format permintaan tidak valid.", nil}
	}
	r.Action = rawString(input["action"])
	if r.Action == "" {
		r.Action = "save"
	}
	if number(rawString(input["halaqoh_id"])) != group || rawString(input["tanggal"]) != requested {
		return r, &WriteError{422, "Kelompok atau tanggal tidak valid.", nil}
	}
	r.Context = rawString(input["context_revision"])
	if r.Action == "finalize" {
		if json.Unmarshal(input["revisions"], &r.Revisions) != nil {
			return r, &WriteError{422, "Revisi finalisasi tidak valid.", nil}
		}
		return r, nil
	}
	if r.Action != "save" {
		return r, &WriteError{422, "Operasi tidak dikenal.", nil}
	}
	r.Operation = rawString(input["operation_id"])
	if !operationPattern.MatchString(r.Operation) {
		return r, &WriteError{422, "ID operasi tidak valid.", nil}
	}
	r.Wali = number(rawString(input["wali_id"]))
	if r.Wali < 1 {
		return r, &WriteError{422, "Data peserta tidak valid.", nil}
	}
	r.Program = rawString(input["program"])
	r.Official = rawString(input["official_revision"])
	r.Draft = -1
	if v, ok := input["draft_revision"]; ok {
		r.Draft = number(rawString(v))
	}
	var payload map[string]json.RawMessage
	if json.Unmarshal(input["payload"], &payload) != nil || payload == nil {
		return r, &WriteError{422, "Data peserta tidak valid.", nil}
	}
	var err error
	r.Payload, err = scalarPayload(payload)
	return r, err
}
func entryFor(state State, id int64) (Entry, bool) {
	for _, entry := range state.Entries {
		if entry.WaliID == id {
			return entry, true
		}
	}
	return Entry{}, false
}
func lockPolicy(ctx context.Context, tx *sql.Tx, generation int64) error {
	var current int64
	if err := tx.QueryRowContext(ctx, "SELECT generation FROM tahsin_writer_policy WHERE id=1 AND schema_version=1 LOCK IN SHARE MODE").Scan(&current); err != nil {
		return err
	}
	if current != generation {
		return &WriteError{503, "Pengalihan penyimpanan berubah. Pengiriman akan dicoba lagi.", nil}
	}
	return nil
}
func lockScope(ctx context.Context, tx *sql.Tx, id int64, session string) error {
	// MySQL requires a write privilege for FOR UPDATE. Only the inert mutex
	// column is writable; ownership and generation remain SELECT-only.
	var mutex int
	if err := tx.QueryRowContext(ctx, "SELECT mutex FROM tahsin_writer_locks WHERE wali_id=? AND session_date=? FOR UPDATE", id, session).Scan(&mutex); err != nil {
		return &WriteError{503, "Kunci catatan belum siap. Draft dipertahankan.", nil}
	}
	var owner string
	if err := tx.QueryRowContext(ctx, "SELECT owner FROM tahsin_writer_fences WHERE wali_id=? AND session_date=? LOCK IN SHARE MODE", id, session).Scan(&owner); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return &WriteError{503, "Catatan belum dialihkan ke Go. Draft dipertahankan.", nil}
		}
		return err
	}
	if owner != "go" {
		return &WriteError{503, "Penyimpanan sedang beralih atau dijeda. Draft dipertahankan.", nil}
	}
	return nil
}
func (w Writer) Execute(ctx context.Context, user, generation int64, request WriteRequest, requestHash string, now time.Time) (any, error) {
	day, err := date(request.Date)
	if err != nil || request.Date > now.In(jakarta).Format("2006-01-02") {
		return nil, &Error{422, "Tanggal sesi tidak valid."}
	}
	tx, err := w.DB.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	if err = lockPolicy(ctx, tx, generation); err != nil {
		return nil, err
	}
	var groupID int64
	if err = tx.QueryRowContext(ctx, "SELECT id FROM halaqoh WHERE id=? LOCK IN SHARE MODE", request.Group).Scan(&groupID); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, &Error{403, "Kelompok bukan penugasan Anda."}
		}
		return nil, err
	}
	canonical := session(day)
	if request.Action == "save" {
		if err = lockScope(ctx, tx, request.Wali, canonical); err != nil {
			return nil, err
		}
	}
	state, err := readStateTx(ctx, tx, user, request.Group, request.Date, now)
	if err != nil {
		return nil, err
	}
	if request.Action == "finalize" {
		if !state.Active || request.Context != state.Revision {
			return nil, &WriteError{409, "Sesi atau roster berubah.", nil}
		}
		if len(state.Entries) == 0 {
			return nil, &WriteError{422, "Belum ada peserta dalam sesi ini.", nil}
		}
		ids := []int64{}
		for _, e := range state.Entries {
			ids = append(ids, e.WaliID)
		}
		sort.Slice(ids, func(i, j int) bool { return ids[i] < ids[j] })
		for _, id := range ids {
			if err = lockScope(ctx, tx, id, canonical); err != nil {
				return nil, err
			}
		}
		state, err = readStateTx(ctx, tx, user, request.Group, request.Date, now)
		if err != nil {
			return nil, err
		}
		officials := []string{}
		for _, entry := range state.Entries {
			if entry.State != "official" || !entry.OfficialComplete || request.Revisions[strconv.FormatInt(entry.WaliID, 10)] != entry.OfficialRevision {
				return nil, &WriteError{409, "Masih ada peserta belum lengkap, perubahan belum terkirim, atau konflik.", nil}
			}
			officials = append(officials, entry.OfficialRevision)
		}
		_, err = tx.ExecContext(ctx, "INSERT INTO tahsin_autosave_sessions(halaqoh_id,session_date,completed_by,completed_at,fingerprint) VALUES(?,?,?,NOW(6),?) ON DUPLICATE KEY UPDATE completed_by=VALUES(completed_by),completed_at=NOW(6),fingerprint=VALUES(fingerprint)", request.Group, canonical, user, hash([]any{state.Revision, officials}))
		if err != nil {
			return nil, err
		}
		if err = tx.Commit(); err != nil {
			return nil, err
		}
		return map[string]any{"completed": true, "message": "Sesi selesai. Semua presensi dan capaian tersimpan lengkap."}, nil
	}
	entry, found := entryFor(state, request.Wali)
	if !found {
		return nil, &WriteError{409, "Peserta atau roster berubah. Draft tetap tersedia.", nil}
	}
	var oldHash, response string
	err = tx.QueryRowContext(ctx, "SELECT request_hash,response_json FROM tahsin_autosave_operations WHERE user_id=? AND operation_id=?", user, request.Operation).Scan(&oldHash, &response)
	if err == nil {
		if oldHash != requestHash {
			return nil, &WriteError{409, "ID operasi sudah dipakai untuk data lain.", nil}
		}
		var receipt any
		if err = json.Unmarshal([]byte(response), &receipt); err != nil {
			return nil, err
		}
		if err = tx.Commit(); err != nil {
			return nil, err
		}
		return receipt, nil
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return nil, err
	}
	if !state.Active {
		return nil, &WriteError{409, "Sesi ini libur. Draft di HP dipertahankan.", nil}
	}
	if request.Program != state.Program || request.Context != state.Revision {
		return nil, &WriteError{409, "Jadwal, program, atau roster berubah. Tinjau sesi kembali.", nil}
	}
	if entry.Blocked {
		return nil, &WriteError{409, "Catatan resmi memerlukan verifikasi admin.", &entry}
	}
	if request.Official != entry.OfficialRevision || request.Draft != entry.DraftRevision {
		return nil, &WriteError{409, "Data sudah berubah di perangkat lain. Tinjau kedua versi.", &entry}
	}
	newState := "draft"
	if len(missing(request.Payload)) == 0 {
		newState = "official"
		if err = saveOfficial(ctx, tx, request, canonical); err != nil {
			return nil, err
		}
	}
	fresh, err := readStateTx(ctx, tx, user, request.Group, request.Date, now)
	if err != nil {
		return nil, err
	}
	official, _ := entryFor(fresh, request.Wali)
	payload, err := phpJSON(request.Payload)
	if err != nil {
		return nil, err
	}
	_, err = tx.ExecContext(ctx, "INSERT INTO tahsin_autosave_drafts(user_id,halaqoh_id,session_date,wali_id,revision,payload_json,official_revision,context_revision,state,updated_at) VALUES(?,?,?,?,?,?,?,?,?,NOW(6)) ON DUPLICATE KEY UPDATE revision=VALUES(revision),payload_json=VALUES(payload_json),official_revision=VALUES(official_revision),context_revision=VALUES(context_revision),state=VALUES(state),updated_at=NOW(6)", user, request.Group, canonical, request.Wali, entry.DraftRevision+1, string(payload), official.OfficialRevision, state.Revision, newState)
	if err != nil {
		return nil, err
	}
	final, err := readStateTx(ctx, tx, user, request.Group, request.Date, now)
	if err != nil {
		return nil, err
	}
	result, _ := entryFor(final, request.Wali)
	encoded, err := phpJSON(result)
	if err != nil {
		return nil, err
	}
	_, err = tx.ExecContext(ctx, "INSERT INTO tahsin_autosave_operations(user_id,operation_id,request_hash,response_json) VALUES(?,?,?,?)", user, request.Operation, requestHash, string(encoded))
	if err != nil {
		return nil, err
	}
	_, err = tx.ExecContext(ctx, "INSERT INTO activity_logs(user_id,username,action,description,ip_address) SELECT id,username,'GO_AUTOSAVE',?,'internal-gateway' FROM users WHERE id=?", fmt.Sprintf("wali_id=%d session=%s operation=%s state=%s", request.Wali, canonical, request.Operation, newState), user)
	if err != nil {
		return nil, err
	}
	if err = tx.Commit(); err != nil {
		return nil, err
	}
	return result, nil
}
func combine(a, b, delimiter string) string {
	a = trim(a)
	b = trim(b)
	if a == "" || b == "" || a == b {
		return a
	}
	return a + delimiter + b
}
func saveOfficial(ctx context.Context, tx *sql.Tx, request WriteRequest, canonical string) error {
	day, _ := date(canonical)
	end := day.AddDate(0, 0, 6).Format("2006-01-02")
	records, _, err := readRows(ctx, tx, "SELECT * FROM presensi WHERE wali_santri_id=? AND tanggal BETWEEN ? AND ? ORDER BY id FOR UPDATE", request.Wali, canonical, end)
	if err != nil {
		return err
	}
	p := request.Payload
	var reason, material, level, surah, page, result any
	if p.Status == "S" || p.Status == "I" {
		reason = trim(p.Reason)
	}
	if p.Status == "H" {
		material = trim(p.Material)
		page = combine(p.PageFrom, p.PageTo, "-")
		result = trim(p.Result)
		if p.Material == "Iqro" {
			level = number(p.Level)
		} else {
			surah = combine(p.SurahFrom, p.SurahTo, " s/d ")
		}
	}
	if len(records) == 0 {
		_, err = tx.ExecContext(ctx, "INSERT INTO presensi(halaqoh_id,wali_santri_id,tanggal,status,alasan,jenis_materi,jilid,nama_surat,halaman,hasil_talaqqi) VALUES(?,?,?,?,?,?,?,?,?,?)", request.Group, request.Wali, request.Date, p.Status, reason, material, level, surah, page, result)
		return err
	}
	var selected sql.NullInt64
	err = tx.QueryRowContext(ctx, "SELECT selected_presensi_id FROM tahsin_verifications WHERE wali_santri_id=? AND session_date=? ORDER BY id DESC LIMIT 1", request.Wali, canonical).Scan(&selected)
	verified := err == nil
	if err != nil && !errors.Is(err, sql.ErrNoRows) {
		return err
	}
	for _, record := range records {
		if number(record["halaqoh_id"]) != request.Group || verified && number(record["id"]) != selected.Int64 {
			continue
		}
		_, err = tx.ExecContext(ctx, "UPDATE presensi SET status=?,alasan=?,jenis_materi=?,jilid=?,nama_surat=?,halaman=?,hasil_talaqqi=? WHERE id=?", p.Status, reason, material, level, surah, page, result, number(record["id"]))
		if err != nil {
			return err
		}
	}
	return nil
}
