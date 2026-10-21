package sessionread

import (
	"context"
	"testing"
)

func TestWriterGrantBoundary(t *testing.T) {
	db := "tahsin_test_autosave_grants"
	valid := []string{"GRANT USAGE ON *.* TO account", "GRANT SELECT ON `" + db + "`.* TO account", "GRANT INSERT, UPDATE ON `" + db + "`.`presensi` TO account", "GRANT UPDATE (`mutex`) ON `" + db + "`.`tahsin_writer_locks` TO account"}
	if err := ValidateWriterGrants(valid, db); err != nil {
		t.Fatal(err)
	}
	for _, grant := range []string{"GRANT ALL PRIVILEGES ON *.* TO root", "GRANT INSERT ON `" + db + "`.* TO account", "GRANT UPDATE ON `" + db + "`.`tahsin_writer_policy` TO account", "GRANT SELECT ON other.* TO account", "GRANT SELECT ON `" + db + "`.* TO account WITH GRANT OPTION", "GRANT `role` TO account", "GRANT DELETE ON `" + db + "`.`tahsin_autosave_operations` TO account"} {
		if ValidateWriterGrants([]string{grant}, db) == nil {
			t.Fatalf("unsafe writer grant accepted: %s", grant)
		}
	}
	t.Setenv("TAHSIN_NEXT_WRITE_DB_DATABASE", "db_tahsinbapak")
	if _, err := OpenWriter(context.Background()); err == nil {
		t.Fatal("production schema accepted by staging writer")
	}
}
func TestWriterRejectsMalformedPayload(t *testing.T) {
	for _, raw := range []string{`{"status":"H|S"}`, `{"status":[]}`, `{"status":{}}`, `{"jenis_materi":"Quran"}`} {
		_, err := ParseWrite([]byte(`{"action":"save","operation_id":"malformed_payload_001","halaqoh_id":3,"tanggal":"2026-10-18","wali_id":10,"payload":`+raw+`}`), 3, "2026-10-18")
		if err == nil {
			t.Fatal("invalid payload accepted")
		}
	}
}
