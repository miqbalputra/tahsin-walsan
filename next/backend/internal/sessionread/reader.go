package sessionread

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"regexp"
	"strconv"
	"strings"
	"time"
	_ "time/tzdata"

	"github.com/go-sql-driver/mysql"
)

type Error struct {
	Status  int
	Message string
}

func (e *Error) Error() string { return e.Message }

type Entry struct {
	WaliID           int64    `json:"wali_id"`
	Payload          Payload  `json:"payload"`
	OfficialPayload  Payload  `json:"official_payload"`
	OfficialRevision string   `json:"official_revision"`
	DraftRevision    int64    `json:"draft_revision"`
	ContextRevision  string   `json:"context_revision"`
	State            string   `json:"state"`
	OfficialComplete bool     `json:"official_complete"`
	Missing          []string `json:"missing_fields"`
	UpdatedAt        any      `json:"updated_at"`
	Blocked          bool     `json:"blocked"`
	Stale            bool     `json:"stale_draft"`
}
type State struct {
	Program     string  `json:"program"`
	Session     string  `json:"session_date"`
	Revision    string  `json:"context_revision"`
	Active      bool    `json:"active"`
	Entries     []Entry `json:"entries"`
	CompletedAt any     `json:"completed_at"`
}
type Reader struct{ DB *sql.DB }

var jakarta, _ = time.LoadLocation("Asia/Jakarta")

func date(s string) (time.Time, error) {
	t, e := time.ParseInLocation("2006-01-02", s, jakarta)
	if e != nil || t.Format("2006-01-02") != s {
		return t, errors.New("invalid date")
	}
	return t, nil
}
func session(t time.Time) string { return t.AddDate(0, 0, -int(t.Weekday())).Format("2006-01-02") }
func active(s string, h map[string]bool) bool {
	t, e := date(s)
	return e == nil && t.Weekday() == time.Sunday && t.Day() > 7 && !h[s]
}
func latest(now time.Time, h map[string]bool) string {
	t, _ := date(session(now.In(jakarta)))
	for range 520 {
		s := t.Format("2006-01-02")
		if active(s, h) {
			return s
		}
		t = t.AddDate(0, 0, -7)
	}
	return ""
}
func readRows(ctx context.Context, tx *sql.Tx, q string, args ...any) ([]row, []string, error) {
	r, e := tx.QueryContext(ctx, q, args...)
	if e != nil {
		return nil, nil, e
	}
	defer r.Close()
	cols, e := r.Columns()
	if e != nil {
		return nil, nil, e
	}
	out := []row{}
	for r.Next() {
		values := make([]any, len(cols))
		dest := make([]any, len(cols))
		for i := range values {
			dest[i] = &values[i]
		}
		if e = r.Scan(dest...); e != nil {
			return nil, nil, e
		}
		v := row{}
		for i, c := range cols {
			if b, ok := values[i].([]byte); ok {
				values[i] = string(b)
			}
			v[c] = values[i]
		}
		out = append(out, v)
	}
	return out, cols, r.Err()
}
func orderedRow(r row, cols []string) any {
	if r == nil {
		return nil
	}
	o := ordered{}
	for _, c := range cols {
		o = append(o, pair{c, r[c]})
	}
	return o
}

func optionalMissing(err error) bool {
	var mysqlError *mysql.MySQLError
	return errors.As(err, &mysqlError) && mysqlError.Number == 1146
}

var suspectPattern = regexp.MustCompile(`Wali ID:\s*([0-9]+)`)

func (r Reader) Read(ctx context.Context, user, group int64, requested string, now time.Time) (State, error) {
	state := State{Entries: []Entry{}}
	day, e := date(requested)
	if e != nil || requested > now.In(jakarta).Format("2006-01-02") {
		return state, &Error{422, "Tanggal sesi tidak valid."}
	}
	tx, e := r.DB.BeginTx(ctx, &sql.TxOptions{ReadOnly: true, Isolation: sql.LevelRepeatableRead})
	if e != nil {
		return state, e
	}
	defer tx.Rollback()
	// Gateway checks trigger readiness with the existing PHP account. SELECT-only
	// readers cannot inspect MySQL triggers; never grant TRIGGER just for this API.
	tracking, _, e := readRows(ctx, tx, "SELECT tracked_from FROM tahsin_tracking WHERE id=1 AND schema_version=1")
	if e != nil {
		return state, e
	}
	if len(tracking) != 1 {
		return state, &Error{503, "Skema sesi belum siap."}
	}
	groups, _, e := readRows(ctx, tx, "SELECT id,ustadz_id,program_code FROM halaqoh WHERE id=?", group)
	if e != nil {
		return state, e
	}
	if len(groups) != 1 {
		return state, &Error{403, "Kelompok bukan penugasan Anda."}
	}
	g := groups[0]
	state.Program = text(g["program_code"])
	state.Session = session(day)
	boundary := state.Session + " 00:00:00.000000"
	tracked := boundary >= text(tracking[0]["tracked_from"])
	owned := number(g["ustadz_id"]) == user
	if tracked {
		owners, _, err := readRows(ctx, tx, "SELECT DISTINCT ustadz_id FROM tahsin_roster_history WHERE halaqoh_id=? AND valid_from<=? AND (valid_until IS NULL OR valid_until>?)", group, boundary, boundary)
		if err != nil {
			return state, err
		}
		if len(owners) > 0 {
			owned = false
			for _, owner := range owners {
				owned = owned || number(owner["ustadz_id"]) == user
			}
		}
	}
	if !owned {
		return state, &Error{403, "Kelompok bukan penugasan Anda."}
	}
	holidays := map[string]bool{}
	holidayRows, _, e := readRows(ctx, tx, "SELECT tanggal FROM holidays")
	if e != nil && !optionalMissing(e) {
		return state, e
	}
	for _, h := range holidayRows {
		holidays[text(h["tanggal"])] = true
	}
	state.Active = active(state.Session, holidays) && !holidays[requested]
	end := day.AddDate(0, 0, 6-int(day.Weekday())).Format("2006-01-02")
	var members []row
	if tracked {
		members, _, e = readRows(ctx, tx, "SELECT w.id FROM tahsin_roster_history rh JOIN wali_santri w ON w.id=rh.wali_santri_id WHERE rh.halaqoh_id=? AND rh.ustadz_id=? AND rh.valid_from<=? AND (rh.valid_until IS NULL OR rh.valid_until>?) GROUP BY w.id,w.nama_bapak,w.no_hp ORDER BY w.nama_bapak", group, user, boundary, boundary)
	} else if state.Session != latest(now, holidays) {
		members, _, e = readRows(ctx, tx, "SELECT w.id FROM presensi p JOIN wali_santri w ON w.id=p.wali_santri_id JOIN halaqoh h ON h.id=p.halaqoh_id WHERE h.id=? AND h.ustadz_id=? AND p.tanggal BETWEEN ? AND ? GROUP BY w.id,w.nama_bapak,w.no_hp ORDER BY w.nama_bapak", group, user, state.Session, end)
	} else {
		members, _, e = readRows(ctx, tx, "SELECT w.id FROM halaqoh h JOIN halaqoh_members hm ON hm.halaqoh_id=h.id JOIN wali_santri w ON w.id=hm.wali_santri_id WHERE h.id=? AND h.ustadz_id=? AND hm.archived_at IS NULL AND w.status_aktif=1 AND w.kategori IN ('reguler','askar') GROUP BY w.id,w.nama_bapak,w.no_hp ORDER BY w.nama_bapak", group, user)
	}
	if e != nil {
		return state, e
	}
	ids := make([]int64, 0, len(members))
	for _, m := range members {
		ids = append(ids, number(m["id"]))
	}
	state.Revision = hash([]any{state.Program, group, number(g["ustadz_id"]), state.Session, state.Active, ids})
	officials := []string{}
	for _, id := range ids {
		records, _, err := readRows(ctx, tx, "SELECT * FROM presensi WHERE wali_santri_id=? AND tanggal BETWEEN ? AND ? ORDER BY id", id, state.Session, end)
		if err != nil {
			return state, err
		}
		verifications, verificationCols, err := readRows(ctx, tx, "SELECT * FROM tahsin_verifications WHERE wali_santri_id=? AND session_date=? ORDER BY id DESC LIMIT 1", id, state.Session)
		if err != nil {
			return state, err
		}
		var verification row
		if len(verifications) > 0 {
			verification = verifications[0]
		}
		suspects := map[int64]bool{}
		if len(records) > 0 {
			first, last := text(records[0]["tanggal"]), text(records[0]["tanggal"])
			for _, v := range records {
				d := text(v["tanggal"])
				if d < first {
					first = d
				}
				if d > last {
					last = d
				}
			}
			logs, _, err := readRows(ctx, tx, "SELECT description,created_at FROM activity_logs WHERE action='INIT_CAPAIAN' AND created_at BETWEEN ? AND ?", first+" 00:00:00", last+" 23:59:59")
			if err != nil && !optionalMissing(err) {
				return state, err
			}
			for _, log := range logs {
				match := suspectPattern.FindStringSubmatch(text(log["description"]))
				if len(match) != 2 {
					continue
				}
				logDate := text(log["created_at"])
				for _, v := range records {
					created := text(v["created_at"])
					if len(logDate) < 19 || len(created) < 19 {
						continue
					}
					a, ea := time.ParseInLocation("2006-01-02 15:04:05", created[:19], jakarta)
					b, eb := time.ParseInLocation("2006-01-02 15:04:05", logDate[:19], jakarta)
					diff := a.Sub(b)
					if ea == nil && eb == nil && number(v["wali_santri_id"]) == number(match[1]) && v["status"] == "H" && v["tanggal"] == logDate[:10] && diff >= -30*time.Second && diff <= 30*time.Second {
						suspects[number(v["id"])] = true
					}
				}
			}
		}
		selected, complete, conflict := resolve(records, verification, suspects, holidays)
		wrongGroup := selected != nil && number(selected["halaqoh_id"]) != group
		conflict = conflict || wrongGroup
		revisions, _, err := readRows(ctx, tx, "SELECT revision FROM tahsin_autosave_revisions WHERE wali_id=? AND session_date=?", id, state.Session)
		if err != nil {
			return state, err
		}
		var revision int64
		if len(revisions) > 0 {
			revision = number(revisions[0]["revision"])
		}
		entry := Entry{WaliID: id, OfficialPayload: fromRecord(selected), ContextRevision: state.Revision, OfficialRevision: hash([]any{fingerprint(records), orderedRow(verification, verificationCols), revision}), State: "empty", OfficialComplete: complete && !conflict, Blocked: conflict}
		entry.Payload = entry.OfficialPayload
		if complete {
			entry.State = "official"
		}
		drafts, _, err := readRows(ctx, tx, "SELECT * FROM tahsin_autosave_drafts WHERE user_id=? AND halaqoh_id=? AND session_date=? AND wali_id=?", user, group, state.Session, id)
		if err != nil {
			return state, err
		}
		if len(drafts) > 0 {
			d := drafts[0]
			decoder := json.NewDecoder(strings.NewReader(text(d["payload_json"])))
			decoder.DisallowUnknownFields()
			if err = decoder.Decode(&entry.Payload); err != nil {
				return state, err
			}
			entry.DraftRevision = number(d["revision"])
			entry.State = text(d["state"])
			entry.UpdatedAt = d["updated_at"]
			entry.Stale = text(d["official_revision"]) != entry.OfficialRevision || text(d["context_revision"]) != state.Revision
		}
		if entry.Stale || conflict {
			entry.State = "conflict"
		}
		entry.Missing = missing(entry.Payload)
		state.Entries = append(state.Entries, entry)
		officials = append(officials, entry.OfficialRevision)
	}
	closed, _, e := readRows(ctx, tx, "SELECT * FROM tahsin_autosave_sessions WHERE halaqoh_id=? AND session_date=?", group, state.Session)
	if e != nil {
		return state, e
	}
	completed := len(closed) > 0 && text(closed[0]["fingerprint"]) == hash([]any{state.Revision, officials})
	for _, entry := range state.Entries {
		completed = completed && entry.State == "official"
	}
	if completed {
		state.CompletedAt = closed[0]["completed_at"]
	}
	return state, tx.Commit()
}

func ParseQuery(values map[string][]string) (int64, string, error) {
	if len(values) != 2 || len(values["halaqoh_id"]) != 1 || len(values["tanggal"]) != 1 {
		return 0, "", errors.New("invalid query")
	}
	id, e := strconv.ParseInt(values["halaqoh_id"][0], 10, 64)
	d := values["tanggal"][0]
	_, de := date(d)
	if e != nil || id < 1 || strconv.FormatInt(id, 10) != values["halaqoh_id"][0] || de != nil {
		return 0, "", errors.New("invalid query")
	}
	return id, d, nil
}
