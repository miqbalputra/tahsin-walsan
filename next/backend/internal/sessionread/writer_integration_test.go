package sessionread

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"os"
	"os/exec"
	"reflect"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"
)

func phpBridge(t *testing.T, input map[string]any) (int, json.RawMessage) {
	t.Helper()
	body, _ := json.Marshal(input)
	args := phpCLIArgs()
	args = append(args, os.Getenv("TAHSIN_WRITE_BRIDGE_FILE"), os.Getenv("TAHSIN_NEXT_WRITE_DB_DATABASE"))
	cmd := exec.Command(os.Getenv("TAHSIN_TEST_PHP_BINARY"), args...)
	cmd.Stdin = bytes.NewReader(body)
	out, err := cmd.CombinedOutput()
	if err != nil {
		t.Fatalf("synthetic PHP companion: %v %s", err, out)
	}
	var result struct {
		Status int             `json:"status"`
		Data   json.RawMessage `json:"data"`
		Error  string          `json:"error"`
	}
	if json.Unmarshal(out, &result) != nil {
		t.Fatalf("invalid PHP companion output: %s", out)
	}
	if result.Status == 500 {
		t.Fatalf("synthetic PHP companion failed: %s", result.Error)
	}
	return result.Status, result.Data
}

func phpCLIArgs() []string {
	if extension := strings.TrimSpace(os.Getenv("TAHSIN_TEST_PHP_EXTENSION")); extension != "" {
		return []string{"-d", "extension=" + extension}
	}
	return nil
}

func TestWriterHandoffWaitsForLegacyTransaction(t *testing.T) {
	if os.Getenv("TAHSIN_WRITE_MYSQL_TEST") != "1" {
		t.Skip("requires owned writer fixture")
	}
	if os.Getenv("TAHSIN_NEXT_WRITE_DB_HOST") != "127.0.0.1" || os.Getenv("TAHSIN_NEXT_WRITE_DB_PORT") != "13319" || !strings.HasPrefix(os.Getenv("TAHSIN_NEXT_WRITE_DB_DATABASE"), "tahsin_test_autosave_") {
		t.Fatal("owned loopback fixture required")
	}
	status, _ := phpBridge(t, map[string]any{"action": "transfer", "owner": "php"})
	if status != 200 {
		t.Fatal("PHP ownership required")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()
	args := phpCLIArgs()
	args = append(args, os.Getenv("TAHSIN_WRITE_BRIDGE_FILE"), os.Getenv("TAHSIN_NEXT_WRITE_DB_DATABASE"))
	cmd := exec.CommandContext(ctx, os.Getenv("TAHSIN_TEST_PHP_BINARY"), args...)
	input, _ := cmd.StdinPipe()
	output, _ := cmd.StdoutPipe()
	var stderr bytes.Buffer
	cmd.Stderr = &stderr
	if err := cmd.Start(); err != nil {
		t.Fatal(err)
	}
	defer func() { input.Close(); cmd.Wait() }()
	input.Write([]byte("{\"action\":\"hold\"}\n"))
	line, err := bufio.NewReader(output).ReadString('\n')
	if err != nil || line != "LOCKED\n" {
		t.Fatalf("legacy transaction failed to acquire guard: %s %v %s", line, err, stderr.String())
	}
	code, data := phpBridge(t, map[string]any{"action": "probe_barrier"})
	if code != 200 || !decodeAs[map[string]bool](t, data)["blocked"] {
		t.Fatal("legacy DML did not retain the shared policy lock until commit")
	}
	input.Close()
	if err = cmd.Wait(); err != nil {
		t.Fatal(err)
	}
	code, _ = phpBridge(t, map[string]any{"action": "transfer", "owner": "go"})
	if code != 200 {
		t.Fatal("handoff cannot proceed after old transaction commits")
	}
	phpBridge(t, map[string]any{"action": "transfer", "owner": "php"})
	t.Log("Operator exclusive barrier waits for old PHP DML; ownership changes only after commit")
}
func decodeAs[T any](t *testing.T, b []byte) T {
	t.Helper()
	var v T
	if err := json.Unmarshal(b, &v); err != nil {
		t.Fatal(err)
	}
	return v
}
func equivalent(t *testing.T, a, b any) {
	t.Helper()
	ab, _ := json.Marshal(a)
	bb, _ := json.Marshal(b)
	var av, bv any
	json.Unmarshal(ab, &av)
	json.Unmarshal(bb, &bv)
	if !reflect.DeepEqual(av, bv) {
		t.Fatalf("contract mismatch\n%s\n%s", ab, bb)
	}
}
func TestWriterPHPInteroperability(t *testing.T) {
	if os.Getenv("TAHSIN_WRITE_MYSQL_TEST") != "1" {
		t.Skip("requires owned synthetic writer fixture")
	}
	if os.Getenv("TAHSIN_NEXT_WRITE_DB_HOST") != "127.0.0.1" || os.Getenv("TAHSIN_NEXT_WRITE_DB_PORT") != "13319" || !strings.HasPrefix(os.Getenv("TAHSIN_NEXT_WRITE_DB_DATABASE"), "tahsin_test_autosave_") {
		t.Fatal("owned loopback fixture required")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 90*time.Second)
	defer cancel()
	w, err := OpenWriter(ctx)
	if err != nil {
		t.Fatal(err)
	}
	defer w.DB.Close()
	status, raw := phpBridge(t, map[string]any{"action": "state"})
	if status != 200 {
		t.Fatal("PHP state unavailable")
	}
	initial := decodeAs[State](t, raw)
	date := initial.Session
	state := func() State {
		t.Helper()
		s, e := (Reader{DB: w.DB}).Read(ctx, 2, 3, date, time.Now())
		if e != nil {
			t.Fatal(e)
		}
		return s
	}
	request := func(s State, id int64, operation string, p Payload) map[string]any {
		e, ok := entryFor(s, id)
		if !ok {
			t.Fatal("fixture member missing")
		}
		return map[string]any{"action": "save", "operation_id": operation, "halaqoh_id": 3, "tanggal": date, "program": s.Program, "wali_id": id, "payload": p, "context_revision": s.Revision, "official_revision": e.OfficialRevision, "draft_revision": e.DraftRevision, "csrf_token": "rotating-fixture-token"}
	}
	canonical := func(input map[string]any) (WriteRequest, string) {
		t.Helper()
		code, b := phpBridge(t, map[string]any{"action": "canonical", "date": date, "request": input})
		if code != 200 {
			t.Fatal("canonical request failed")
		}
		var c struct {
			Hash string `json:"hash"`
			Wire string `json:"wire"`
		}
		json.Unmarshal(b, &c)
		r, e := ParseWrite([]byte(c.Wire), 3, date)
		if e != nil {
			t.Fatal(e)
		}
		return r, c.Hash
	}
	transfer := func(owner string) int64 {
		t.Helper()
		code, b := phpBridge(t, map[string]any{"action": "transfer", "owner": owner, "date": date})
		if code != 200 {
			t.Fatal("transfer failed")
		}
		var g struct {
			Generation int64 `json:"generation"`
		}
		json.Unmarshal(b, &g)
		return g.Generation
	}
	assertStatus := func(err error, status int) {
		t.Helper()
		var we *WriteError
		if !errors.As(err, &we) || we.Status != status {
			t.Fatalf("want %d, got %v", status, err)
		}
	}
	if _, err = w.DB.ExecContext(ctx, "UPDATE tahsin_writer_policy SET generation=generation+1 WHERE id=1"); err == nil {
		t.Fatal("writer account can change ownership")
	}
	e, _ := entryFor(initial, 10)
	p := e.Payload
	p.Status = "H"
	p.Material = "Iqro"
	p.Level = "3"
	phpRequest := request(initial, 10, "php_before_handoff_0001", p)
	code, phpReceipt := phpBridge(t, map[string]any{"action": "write", "date": date, "request": phpRequest})
	if code != 200 {
		t.Fatal("initial PHP draft failed")
	}
	generation := transfer("go")
	r, h := canonical(phpRequest)
	receipt, err := w.Execute(ctx, 2, generation, r, h, time.Now())
	if err != nil {
		t.Fatal(err)
	}
	equivalent(t, decodeAs[any](t, phpReceipt), receipt)
	t.Log("PHP receipt survives handoff to Go")
	s := state()
	e, _ = entryFor(s, 10)
	p = e.Payload
	p.PageFrom = "9"
	p.PageTo = "10"
	p.Result = "Lulus"
	goRequest := request(s, 10, "go_complete_retry_0001", p)
	r, h = canonical(goRequest)
	committed, err := w.Execute(ctx, 2, generation, r, h, time.Now())
	if err != nil {
		t.Fatal(err)
	}
	again, err := w.Execute(ctx, 2, generation, r, h, time.Now())
	if err != nil {
		t.Fatal(err)
	}
	equivalent(t, committed, again)
	goRequest["csrf_token"] = "new-login-token"
	rotated, rotatedHash := canonical(goRequest)
	if rotatedHash != h {
		t.Fatal("CSRF rotation changes operation identity")
	}
	again, err = w.Execute(ctx, 2, generation, rotated, rotatedHash, time.Now())
	if err != nil {
		t.Fatal(err)
	}
	equivalent(t, committed, again)
	code, raw = phpBridge(t, map[string]any{"action": "state", "date": date})
	if code != 200 {
		t.Fatal("PHP cannot read Go result")
	}
	equivalent(t, decodeAs[State](t, raw), state())
	var count int
	if err = w.DB.QueryRowContext(ctx, "SELECT COUNT(*) FROM presensi WHERE wali_santri_id=10").Scan(&count); err != nil || count != 1 {
		t.Fatal("retry duplicated attendance")
	}
	t.Log("Go official transaction, lost acknowledgement and rotated CSRF are idempotent; PHP reads identical state")
	s = state()
	e, _ = entryFor(s, 10)
	p = e.Payload
	p.PageTo = ""
	r, h = canonical(request(s, 10, "go_partial_edit_00001", p))
	_, err = w.Execute(ctx, 2, generation, r, h, time.Now())
	if err != nil {
		t.Fatal(err)
	}
	e, _ = entryFor(state(), 10)
	if e.State != "draft" || !e.OfficialComplete {
		t.Fatal("partial edit removed official version")
	}
	var page string
	w.DB.QueryRowContext(ctx, "SELECT halaman FROM presensi WHERE wali_santri_id=10").Scan(&page)
	if page != "9-10" {
		t.Fatal("partial edit damaged report source")
	}
	changed := map[string]any{}
	for k, v := range goRequest {
		changed[k] = v
	}
	changed["payload"] = Payload{Status: "A"}
	r, h = canonical(changed)
	_, err = w.Execute(ctx, 2, generation, r, h, time.Now())
	assertStatus(err, 409)
	code, _ = phpBridge(t, map[string]any{"action": "direct_sql", "date": date, "wali": 10})
	if code != 503 {
		t.Fatal("legacy PHP SQL bypasses Go ownership")
	}
	code, _ = phpBridge(t, map[string]any{"action": "metadata"})
	if code != 503 {
		t.Fatal("metadata can invalidate an active Go scope")
	}
	t.Log("Partial drafts preserve official material; operation reuse and legacy direct SQL are blocked")
	s = state()
	e, _ = entryFor(s, 11)
	p = e.Payload
	p.Status = "S"
	p.Reason = "Alasan <ibu> / keluarga 😀"
	a, ah := canonical(request(s, 11, "go_device_alpha_00001", p))
	b, bh := canonical(request(s, 11, "go_device_bravo_00001", p))
	var wg sync.WaitGroup
	results := make(chan error, 2)
	for _, v := range []struct {
		r WriteRequest
		h string
	}{{a, ah}, {b, bh}} {
		wg.Add(1)
		go func() { defer wg.Done(); _, err := w.Execute(ctx, 2, generation, v.r, v.h, time.Now()); results <- err }()
	}
	wg.Wait()
	close(results)
	ok, conflicts := 0, 0
	for err := range results {
		if err == nil {
			ok++
		} else {
			assertStatus(err, 409)
			conflicts++
		}
	}
	if ok != 1 || conflicts != 1 {
		t.Fatal("two devices overwrote each other")
	}
	t.Log("Concurrent devices produce one commit and one explicit conflict")
	s = state()
	before, _ := entryFor(s, 12)
	p = before.Payload
	p.Status = "A"
	r, h = canonical(request(s, 12, "force_receipt_rollback_001", p))
	phpBridge(t, map[string]any{"action": "fail_receipt"})
	_, err = w.Execute(ctx, 2, generation, r, h, time.Now())
	if err == nil {
		t.Fatal("receipt failure was ignored")
	}
	after, _ := entryFor(state(), 12)
	equivalent(t, before, after)
	phpBridge(t, map[string]any{"action": "clear_failure"})
	if err = w.DB.QueryRowContext(ctx, "SELECT COUNT(*) FROM tahsin_autosave_operations WHERE operation_id='force_receipt_rollback_001'").Scan(&count); err != nil || count != 0 {
		t.Fatal("failed transaction left receipt")
	}
	t.Log("Failure after attendance/draft writes rolls back the record, revisions and receipt")
	s = state()
	final := WriteRequest{Action: "finalize", Group: 3, Date: date, Context: s.Revision, Revisions: map[string]string{}}
	_, err = w.Execute(ctx, 2, generation, final, strings.Repeat("a", 64), time.Now())
	assertStatus(err, 409)
	for _, id := range []int64{10, 12, 13} {
		s = state()
		e, _ = entryFor(s, id)
		p = e.Payload
		if id == 10 {
			p.PageTo = "10"
		} else {
			p.Status = "A"
		}
		r, h = canonical(request(s, id, "go_final_"+strconv.FormatInt(id, 10)+"_operation_001", p))
		if _, err = w.Execute(ctx, 2, generation, r, h, time.Now()); err != nil {
			t.Fatal(err)
		}
	}
	s = state()
	final.Context = s.Revision
	for _, entry := range s.Entries {
		final.Revisions[strconv.FormatInt(entry.WaliID, 10)] = entry.OfficialRevision
	}
	if _, err = w.Execute(ctx, 2, generation, final, strings.Repeat("b", 64), time.Now()); err != nil {
		t.Fatal(err)
	}
	code, raw = phpBridge(t, map[string]any{"action": "state", "date": date})
	if code != 200 || decodeAs[State](t, raw).CompletedAt == nil {
		t.Fatal("PHP cannot recover Go finalization")
	}
	paused := transfer("paused")
	r, h = canonical(request(state(), 10, "paused_request_retry_01", p))
	_, err = w.Execute(ctx, 2, paused, r, h, time.Now())
	assertStatus(err, 503)
	resumed := transfer("go")
	_, err = w.Execute(ctx, 2, paused, r, h, time.Now())
	assertStatus(err, 503)
	if resumed <= paused {
		t.Fatal("generation failed to advance")
	}
	transfer("php")
	code, phpReplay := phpBridge(t, map[string]any{"action": "write", "date": date, "request": goRequest})
	if code != 200 {
		t.Fatal("PHP cannot replay Go receipt after rollback")
	}
	equivalent(t, committed, decodeAs[any](t, phpReplay))
	t.Log("Finalization survives PHP reads, pause/stale generations retry, rollback to PHP retains Go receipts")
}
