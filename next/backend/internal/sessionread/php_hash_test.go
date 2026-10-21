package sessionread

import "testing"

func TestPHPAutosaveOperationHashIgnoresRotatingCSRFAndPreservesFieldOrder(t *testing.T) {
	body := []byte(`{"action":"save","operation_id":"1234567890abcdef","halaqoh_id":3,"tanggal":"2026-10-18","program":"bapak","wali_id":10,"payload":{"status":"S","alasan":"Sakit <ibu> / 🙂","jenis_materi":"","jilid":"","nama_surat_dari":"","nama_surat_sampai":"","halaman_dari":"","halaman_sampai":"","hasil_talaqqi":""},"context_revision":"abc","official_revision":"def","draft_revision":2,"csrf_token":"token-satu"}`)
	rotated := []byte(`{"action":"save","operation_id":"1234567890abcdef","halaqoh_id":3,"tanggal":"2026-10-18","program":"bapak","wali_id":10,"payload":{"status":"S","alasan":"Sakit <ibu> / 🙂","jenis_materi":"","jilid":"","nama_surat_dari":"","nama_surat_sampai":"","halaman_dari":"","halaman_sampai":"","hasil_talaqqi":""},"context_revision":"abc","official_revision":"def","draft_revision":2,"csrf_token":"token-dua"}`)
	h1, err := HashPHPAutosaveRequest(3, "2026-10-18", body)
	if err != nil {
		t.Fatal(err)
	}
	if h1 != "426c8ad36542c5eacf3ae1ce3a09decd767896db47314ad9d7bed9e4b834a928" {
		t.Fatalf("hash does not match PHP json_encode contract: %s", h1)
	}
	h2, err := HashPHPAutosaveRequest(3, "2026-10-18", rotated)
	if err != nil || h1 != h2 {
		t.Fatalf("rotating CSRF changed receipt hash: %s %s %v", h1, h2, err)
	}
	reordered := []byte(`{"operation_id":"1234567890abcdef","action":"save","halaqoh_id":3,"tanggal":"2026-10-18","program":"bapak","wali_id":10,"payload":{"status":"S","alasan":"Sakit <ibu> / 🙂","jenis_materi":"","jilid":"","nama_surat_dari":"","nama_surat_sampai":"","halaman_dari":"","halaman_sampai":"","hasil_talaqqi":""},"context_revision":"abc","official_revision":"def","draft_revision":2}`)
	h3, err := HashPHPAutosaveRequest(3, "2026-10-18", reordered)
	if err != nil || h1 == h3 {
		t.Fatal("request field order was not preserved for PHP-compatible receipts")
	}
}
