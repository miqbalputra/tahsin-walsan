package app

import (
	"archive/zip"
	"os"
	"path/filepath"
	"testing"
)

func makeZipForTest(t *testing.T, names ...string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "test.zip")
	file, err := os.Create(path)
	if err != nil {
		t.Fatal(err)
	}
	writer := zip.NewWriter(file)
	for _, name := range names {
		entry, err := writer.Create(name)
		if err != nil {
			t.Fatal(err)
		}
		if _, err := entry.Write([]byte("fixture")); err != nil {
			t.Fatal(err)
		}
	}
	if err := writer.Close(); err != nil {
		t.Fatal(err)
	}
	if err := file.Close(); err != nil {
		t.Fatal(err)
	}
	return path
}

func makeZipContent(t *testing.T, name, content string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "content.zip")
	file, err := os.Create(path)
	if err != nil {
		t.Fatal(err)
	}
	writer := zip.NewWriter(file)
	entry, err := writer.Create(name)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := entry.Write([]byte(content)); err != nil {
		t.Fatal(err)
	}
	if err := writer.Close(); err != nil {
		t.Fatal(err)
	}
	if err := file.Close(); err != nil {
		t.Fatal(err)
	}
	return path
}

func TestZipEntryMapRejectsTraversalAndAcceptsRootEntries(t *testing.T) {
	good, err := zip.OpenReader(makeZipForTest(t, "manifest.json", "users.csv"))
	if err != nil {
		t.Fatal(err)
	}
	if entries, err := zipEntryMap(good, 1024); err != nil || len(entries) != 2 {
		t.Fatalf("root entries = %d, err = %v", len(entries), err)
	}
	_ = good.Close()

	bad, err := zip.OpenReader(makeZipForTest(t, "../outside.csv"))
	if err != nil {
		t.Fatal(err)
	}
	defer bad.Close()
	if _, err := zipEntryMap(bad, 1024); err == nil {
		t.Fatal("path traversal entry was accepted")
	}
}

func TestZipEntryMapEnforcesExpandedSizeAndDuplicateNames(t *testing.T) {
	reader, err := zip.OpenReader(makeZipForTest(t, "manifest.json"))
	if err != nil {
		t.Fatal(err)
	}
	if _, err := zipEntryMap(reader, 2); err == nil {
		t.Fatal("oversized expanded ZIP was accepted")
	}
	_ = reader.Close()

	path := filepath.Join(t.TempDir(), "duplicate.zip")
	file, err := os.Create(path)
	if err != nil {
		t.Fatal(err)
	}
	writer := zip.NewWriter(file)
	for range 2 {
		entry, createErr := writer.Create("manifest.json")
		if createErr != nil {
			t.Fatal(createErr)
		}
		_, _ = entry.Write([]byte("x"))
	}
	if err := writer.Close(); err != nil {
		t.Fatal(err)
	}
	_ = file.Close()
	reader, err = zip.OpenReader(path)
	if err != nil {
		t.Fatal(err)
	}
	defer reader.Close()
	if _, err := zipEntryMap(reader, 1024); err == nil {
		t.Fatal("duplicate ZIP entry was accepted")
	}
}

func TestBackupFilenameAndExpandedLimit(t *testing.T) {
	for _, name := range []string{"backup_20261022.zip", "backup_pre_restore_20261022.zip", "restore_abcdefghijklmnopqrstuvwx.zip"} {
		if !backupFilename.MatchString(name) {
			t.Errorf("expected valid archive name %q", name)
		}
	}
	for _, name := range []string{"../backup_1.zip", "backup_1.zip/extra", "backup space.zip", ".backup_1.zip"} {
		if backupFilename.MatchString(name) {
			t.Errorf("accepted invalid archive name %q", name)
		}
	}
	if got := maxBackupExpandedBytes(512); got != 2<<30 {
		t.Fatalf("expanded limit for 512MB upload = %d", got)
	}
	if got := maxBackupExpandedBytes(2048); got != 2<<30 {
		t.Fatalf("expanded limit ceiling = %d", got)
	}
}

func TestInspectBackupCSVStreamsRowsAndEnforcesLimit(t *testing.T) {
	content := "id,nama\n1,Aminah\n2,\"Fatimah, Binti\"\n"
	path := makeZipContent(t, "wali_santri.csv", content)
	archive, err := zip.OpenReader(path)
	if err != nil {
		t.Fatal(err)
	}
	defer archive.Close()
	header, rows, checksum, err := inspectBackupCSV(archive.File[0], 1024)
	if err != nil {
		t.Fatal(err)
	}
	if len(header) != 2 || header[0] != "id" || rows != 2 || checksum == "" {
		t.Fatalf("header=%v rows=%d checksum=%q", header, rows, checksum)
	}
	if _, _, _, err := inspectBackupCSV(archive.File[0], 8); err == nil {
		t.Fatal("CSV exceeding the streaming limit was accepted")
	}
}
