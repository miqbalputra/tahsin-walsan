package app

import (
	"archive/zip"
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/csv"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"hash"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"github.com/go-sql-driver/mysql"
)

const backupNullToken = `\N`

var backupIdentifier = regexp.MustCompile(`^[A-Za-z0-9_]+$`)

type backupTable struct {
	Name       string
	Engine     string
	Columns    []string
	PrimaryKey []string
}

type backupManifest struct {
	App       string            `json:"app"`
	Version   int               `json:"version"`
	Generated string            `json:"generated_at"`
	Database  string            `json:"database"`
	Tables    map[string]int64  `json:"tables"`
	Checksums map[string]string `json:"checksums"`
}

type backupTablePreview struct {
	Table        string   `json:"table"`
	ZipRows      *int64   `json:"zip_rows"`
	DatabaseRows int64    `json:"db_rows"`
	ColumnsOK    bool     `json:"cols_ok"`
	PrimaryKeyOK bool     `json:"primary_key_ok"`
	ChecksumOK   bool     `json:"checksum_ok"`
	Missing      []string `json:"missing"`
	Note         string   `json:"note"`
}

type backupPreview struct {
	Tables     []backupTablePreview `json:"rows"`
	TotalZIP   int64                `json:"total_zip"`
	TotalDB    int64                `json:"total_db"`
	Summary    string               `json:"summary"`
	CanRestore bool                 `json:"can_restore"`
}

func discoverBackupTables(ctx context.Context, db queryContext) ([]backupTable, error) {
	rows, err := db.QueryContext(ctx, `SELECT table_name,COALESCE(engine,'') FROM information_schema.tables
		WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name`)
	if err != nil {
		return nil, err
	}
	names := []string{}
	engines := map[string]string{}
	for rows.Next() {
		var name string
		var engine string
		if err := rows.Scan(&name, &engine); err != nil {
			rows.Close()
			return nil, err
		}
		if !backupIdentifier.MatchString(name) {
			rows.Close()
			return nil, errors.New("nama tabel database tidak valid")
		}
		names = append(names, name)
		engines[name] = engine
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()
	if len(names) == 0 {
		return nil, errors.New("tidak ada tabel database yang dapat dibackup")
	}

	known := make(map[string]bool, len(names))
	for _, name := range names {
		known[name] = true
	}
	dependencies := map[string]map[string]bool{}
	for _, name := range names {
		dependencies[name] = map[string]bool{}
	}
	fkRows, err := db.QueryContext(ctx, `SELECT table_name,referenced_table_name FROM information_schema.key_column_usage
		WHERE constraint_schema=DATABASE() AND referenced_table_name IS NOT NULL`)
	if err != nil {
		return nil, err
	}
	for fkRows.Next() {
		var child, parent string
		if err := fkRows.Scan(&child, &parent); err != nil {
			fkRows.Close()
			return nil, err
		}
		if known[child] && known[parent] {
			dependencies[child][parent] = true
		}
	}
	if err := fkRows.Err(); err != nil {
		fkRows.Close()
		return nil, err
	}
	fkRows.Close()

	ordered := []string{}
	remaining := make(map[string]bool, len(names))
	for _, name := range names {
		remaining[name] = true
	}
	for len(remaining) > 0 {
		progress := false
		for _, name := range names {
			if !remaining[name] {
				continue
			}
			parentsReady := true
			for parent := range dependencies[name] {
				if remaining[parent] {
					parentsReady = false
					break
				}
			}
			if parentsReady {
				ordered = append(ordered, name)
				delete(remaining, name)
				progress = true
			}
		}
		if !progress {
			for _, name := range names {
				if remaining[name] {
					ordered = append(ordered, name)
				}
			}
			break
		}
	}

	result := make([]backupTable, 0, len(ordered))
	for _, name := range ordered {
		table := backupTable{Name: name, Engine: engines[name], Columns: []string{}, PrimaryKey: []string{}}
		columnRows, err := db.QueryContext(ctx, `SELECT column_name FROM information_schema.columns
			WHERE table_schema=DATABASE() AND table_name=? ORDER BY ordinal_position`, name)
		if err != nil {
			return nil, err
		}
		for columnRows.Next() {
			var column string
			if err := columnRows.Scan(&column); err != nil {
				columnRows.Close()
				return nil, err
			}
			if !backupIdentifier.MatchString(column) {
				columnRows.Close()
				return nil, errors.New("nama kolom database tidak valid")
			}
			table.Columns = append(table.Columns, column)
		}
		if err := columnRows.Err(); err != nil {
			columnRows.Close()
			return nil, err
		}
		columnRows.Close()
		keyRows, err := db.QueryContext(ctx, `SELECT column_name FROM information_schema.key_column_usage
			WHERE constraint_schema=DATABASE() AND table_name=? AND constraint_name='PRIMARY' ORDER BY ordinal_position`, name)
		if err != nil {
			return nil, err
		}
		for keyRows.Next() {
			var column string
			if err := keyRows.Scan(&column); err != nil {
				keyRows.Close()
				return nil, err
			}
			table.PrimaryKey = append(table.PrimaryKey, column)
		}
		if err := keyRows.Err(); err != nil {
			keyRows.Close()
			return nil, err
		}
		keyRows.Close()
		result = append(result, table)
	}
	return result, nil
}

type queryContext interface {
	QueryContext(context.Context, string, ...any) (*sql.Rows, error)
	QueryRowContext(context.Context, string, ...any) *sql.Row
}

func backupDir(config Config) (string, error) {
	if !filepath.IsAbs(config.DataDir) {
		return "", errors.New("APP_DATA_DIR harus absolut")
	}
	dir := filepath.Join(config.DataDir, "backups")
	if err := os.MkdirAll(dir, 0700); err != nil {
		return "", err
	}
	info, err := os.Lstat(dir)
	if err != nil {
		return "", err
	}
	if !info.IsDir() || info.Mode()&os.ModeSymlink != 0 {
		return "", errors.New("direktori backup tidak valid")
	}
	if err := os.Chmod(dir, 0700); err != nil {
		return "", err
	}
	return dir, nil
}

func createBackupArchive(ctx context.Context, db *sql.DB, path string) (backupManifest, error) {
	var manifest backupManifest
	file, err := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0600)
	if err != nil {
		return manifest, err
	}
	keep := false
	defer func() {
		_ = file.Close()
		if !keep {
			_ = os.Remove(path)
		}
	}()
	conn, err := db.Conn(ctx)
	if err != nil {
		return manifest, err
	}
	defer conn.Close()
	if _, err = conn.ExecContext(ctx, "SET TRANSACTION ISOLATION LEVEL REPEATABLE READ"); err != nil {
		return manifest, err
	}
	tx, err := conn.BeginTx(ctx, nil)
	if err != nil {
		return manifest, err
	}
	committed := false
	defer func() {
		if !committed {
			_ = tx.Rollback()
		}
	}()
	tables, err := discoverBackupTables(ctx, tx)
	if err != nil {
		return manifest, err
	}
	if err := requireTransactionalBackupTables(tables); err != nil {
		return manifest, err
	}
	manifest = backupManifest{App: "tahsin-walsan", Version: 2, Generated: time.Now().Format(time.RFC3339), Tables: map[string]int64{}, Checksums: map[string]string{}}
	if err = tx.QueryRowContext(ctx, "SELECT DATABASE()").Scan(&manifest.Database); err != nil {
		return manifest, err
	}
	archive := zip.NewWriter(file)
	var schema strings.Builder
	for _, table := range tables {
		quoted := quotedColumns(table.Columns)
		order := ""
		if len(table.PrimaryKey) > 0 {
			order = " ORDER BY " + quotedColumns(table.PrimaryKey)
		}
		rows, err := tx.QueryContext(ctx, "SELECT "+quoted+" FROM `"+table.Name+"`"+order)
		if err != nil {
			_ = archive.Close()
			return manifest, err
		}
		entry, err := archive.Create(table.Name + ".csv")
		if err != nil {
			rows.Close()
			_ = archive.Close()
			return manifest, err
		}
		digest := sha256.New()
		writer := csv.NewWriter(io.MultiWriter(entry, digest))
		if err = writer.Write(table.Columns); err != nil {
			rows.Close()
			_ = archive.Close()
			return manifest, err
		}
		count := int64(0)
		for rows.Next() {
			values := make([]any, len(table.Columns))
			pointers := make([]any, len(values))
			for i := range values {
				pointers[i] = &values[i]
			}
			if err = rows.Scan(pointers...); err != nil {
				rows.Close()
				_ = archive.Close()
				return manifest, err
			}
			record := make([]string, len(values))
			for i, value := range values {
				if value == nil {
					record[i] = backupNullToken
					continue
				}
				switch v := value.(type) {
				case []byte:
					record[i] = string(v)
				case time.Time:
					record[i] = v.Format("2006-01-02 15:04:05.999999")
				default:
					record[i] = fmt.Sprint(v)
				}
			}
			if err = writer.Write(record); err != nil {
				rows.Close()
				_ = archive.Close()
				return manifest, err
			}
			count++
		}
		if err = rows.Err(); err != nil {
			rows.Close()
			_ = archive.Close()
			return manifest, err
		}
		rows.Close()
		writer.Flush()
		if err = writer.Error(); err != nil {
			_ = archive.Close()
			return manifest, err
		}
		manifest.Tables[table.Name] = count
		manifest.Checksums[table.Name] = hex.EncodeToString(digest.Sum(nil))
		var createName, ddl string
		if err = tx.QueryRowContext(ctx, "SHOW CREATE TABLE `"+table.Name+"`").Scan(&createName, &ddl); err != nil {
			_ = archive.Close()
			return manifest, err
		}
		schema.WriteString(ddl)
		schema.WriteString(";\n\n")
	}
	schemaEntry, err := archive.Create("schema.sql")
	if err != nil {
		_ = archive.Close()
		return manifest, err
	}
	if _, err = io.WriteString(schemaEntry, schema.String()); err != nil {
		_ = archive.Close()
		return manifest, err
	}
	manifestRaw, err := json.MarshalIndent(manifest, "", "  ")
	if err != nil {
		_ = archive.Close()
		return manifest, err
	}
	manifestEntry, err := archive.Create("manifest.json")
	if err != nil {
		_ = archive.Close()
		return manifest, err
	}
	if _, err = manifestEntry.Write(manifestRaw); err != nil {
		_ = archive.Close()
		return manifest, err
	}
	if err = archive.Close(); err != nil {
		return manifest, err
	}
	if err = tx.Commit(); err != nil {
		return manifest, err
	}
	committed = true
	if err = file.Sync(); err != nil {
		return manifest, err
	}
	if err = file.Close(); err != nil {
		return manifest, err
	}
	keep = true
	return manifest, nil
}

func quotedColumns(columns []string) string {
	quoted := make([]string, 0, len(columns))
	for _, column := range columns {
		if !backupIdentifier.MatchString(column) {
			return ""
		}
		quoted = append(quoted, "`"+column+"`")
	}
	return strings.Join(quoted, ", ")
}

func requireTransactionalBackupTables(tables []backupTable) error {
	for _, table := range tables {
		if !strings.EqualFold(table.Engine, "InnoDB") {
			return fmt.Errorf("tabel %s memakai engine %q; backup konsisten dan restore transaksional hanya mengizinkan InnoDB", table.Name, table.Engine)
		}
	}
	return nil
}

func zipEntryMap(reader *zip.ReadCloser, maxExpanded int64) (map[string]*zip.File, error) {
	entries := make(map[string]*zip.File, len(reader.File))
	var expanded int64
	for _, file := range reader.File {
		name := file.Name
		if name == "" || filepath.Base(name) != name || strings.ContainsAny(name, `/\`) || strings.HasPrefix(name, ".") || file.FileInfo().IsDir() {
			return nil, errors.New("struktur ZIP tidak valid")
		}
		if _, duplicate := entries[name]; duplicate {
			return nil, errors.New("ZIP mengandung nama file ganda")
		}
		if file.UncompressedSize64 > uint64(maxExpanded) || expanded > maxExpanded-int64(file.UncompressedSize64) {
			return nil, errors.New("ukuran hasil ekstrak ZIP terlalu besar")
		}
		expanded += int64(file.UncompressedSize64)
		entries[name] = file
	}
	return entries, nil
}

func readZipEntry(file *zip.File, limit int64) ([]byte, error) {
	reader, err := file.Open()
	if err != nil {
		return nil, err
	}
	defer reader.Close()
	data, err := io.ReadAll(io.LimitReader(reader, limit+1))
	if err != nil {
		return nil, err
	}
	if int64(len(data)) > limit {
		return nil, errors.New("berkas dalam ZIP terlalu besar")
	}
	return data, nil
}

type backupCSVReader struct {
	reader  *csv.Reader
	source  io.ReadCloser
	limited *io.LimitedReader
	digest  hash.Hash
}

func openBackupCSV(file *zip.File, maxBytes int64) (*backupCSVReader, error) {
	source, err := file.Open()
	if err != nil {
		return nil, err
	}
	limited := &io.LimitedReader{R: source, N: maxBytes + 1}
	digest := sha256.New()
	reader := csv.NewReader(io.TeeReader(limited, digest))
	reader.FieldsPerRecord = -1
	return &backupCSVReader{reader: reader, source: source, limited: limited, digest: digest}, nil
}

func inspectBackupCSV(file *zip.File, maxBytes int64) ([]string, int64, string, error) {
	result, err := openBackupCSV(file, maxBytes)
	if err != nil {
		return nil, 0, "", err
	}
	defer result.source.Close()
	header, err := result.reader.Read()
	if err != nil || len(header) == 0 {
		return nil, 0, "", errors.New("header CSV tidak terbaca")
	}
	seen := make(map[string]bool, len(header))
	for _, column := range header {
		if column == "" || seen[column] {
			return nil, 0, "", errors.New("header CSV tidak valid")
		}
		seen[column] = true
	}
	var count int64
	for {
		record, readErr := result.reader.Read()
		if errors.Is(readErr, io.EOF) {
			break
		}
		if readErr != nil || len(record) != len(header) {
			return nil, 0, "", errors.New("baris CSV tidak valid")
		}
		empty := true
		for _, value := range record {
			if value != "" {
				empty = false
				break
			}
		}
		if !empty {
			count++
		}
	}
	if result.limited.N == 0 {
		return nil, 0, "", errors.New("CSV backup terlalu besar")
	}
	checksum := hex.EncodeToString(result.digest.Sum(nil))
	return header, count, checksum, nil
}

func analyzeBackupArchive(ctx context.Context, db *sql.DB, path string, maxExpanded int64) (backupPreview, error) {
	preview := backupPreview{Tables: []backupTablePreview{}, CanRestore: true}
	archive, err := zip.OpenReader(path)
	if err != nil {
		return preview, errors.New("tidak bisa membuka file ZIP")
	}
	defer archive.Close()
	entries, err := zipEntryMap(archive, maxExpanded)
	if err != nil {
		return preview, err
	}
	manifestFile := entries["manifest.json"]
	if manifestFile == nil {
		return preview, errors.New("manifest.json tidak ditemukan di dalam ZIP")
	}
	manifestRaw, err := readZipEntry(manifestFile, 1<<20)
	if err != nil {
		return preview, err
	}
	var manifest backupManifest
	if err = json.Unmarshal(manifestRaw, &manifest); err != nil || manifest.App != "tahsin-walsan" {
		return preview, errors.New("ZIP bukan backup tahsin-walsan yang valid")
	}
	tables, err := discoverBackupTables(ctx, db)
	if err != nil {
		return preview, err
	}
	for _, table := range tables {
		rowCount, err := hermesCount(ctx, db, "SELECT COUNT(*) FROM `"+table.Name+"`")
		if err != nil {
			return preview, err
		}
		preview.TotalDB += rowCount
		item := backupTablePreview{Table: table.Name, DatabaseRows: rowCount, ColumnsOK: false, PrimaryKeyOK: true, ChecksumOK: false, Missing: []string{}}
		if !strings.EqualFold(table.Engine, "InnoDB") {
			item.Note = "Engine bukan InnoDB; operasi transaksional tidak dijamin."
			preview.CanRestore = false
		}
		if len(table.PrimaryKey) == 0 {
			if item.Note == "" {
				item.Note = "Tidak memiliki primary key; akan dilewati agar tidak membuat duplikasi."
			}
		}
		file := entries[table.Name+".csv"]
		if file == nil {
			item.Note = "CSV tidak ada di ZIP"
			item.ColumnsOK = false
			item.ChecksumOK = true
			preview.CanRestore = false
			preview.Tables = append(preview.Tables, item)
			continue
		}
		header, count, checksum, err := inspectBackupCSV(file, maxExpanded)
		if err != nil {
			return preview, fmt.Errorf("CSV %s tidak valid: %w", table.Name, err)
		}
		if expected, ok := manifest.Checksums[table.Name]; ok && expected != checksum {
			return preview, fmt.Errorf("checksum CSV %s tidak cocok", table.Name+".csv")
		}
		item.ChecksumOK = true
		seen := map[string]bool{}
		for _, column := range header {
			if column == "" || seen[column] {
				return preview, fmt.Errorf("header CSV %s tidak valid", table.Name)
			}
			seen[column] = true
		}
		dbColumns := map[string]bool{}
		for _, column := range table.Columns {
			dbColumns[column] = true
		}
		for _, column := range header {
			if !dbColumns[column] {
				item.Missing = append(item.Missing, column)
			}
		}
		item.ColumnsOK = len(item.Missing) == 0
		for _, primary := range table.PrimaryKey {
			if !seen[primary] {
				item.PrimaryKeyOK = false
				item.Missing = append(item.Missing, "PRIMARY KEY: "+primary)
			}
		}
		if expected, ok := manifest.Tables[table.Name]; ok && expected != count {
			return preview, fmt.Errorf("jumlah baris CSV %s tidak sesuai manifest", table.Name)
		}
		item.ZipRows = &count
		if !item.ColumnsOK || !item.PrimaryKeyOK {
			preview.CanRestore = false
		}
		preview.TotalZIP += count
		preview.Tables = append(preview.Tables, item)
	}
	preview.Summary = fmt.Sprintf("preview %d baris dari %s", preview.TotalZIP, filepath.Base(path))
	return preview, nil
}

func mergeRestoreArchive(ctx context.Context, db *sql.DB, path string, maxExpanded int64) (map[string]int64, map[string]int64, map[string]string, error) {
	inserted := map[string]int64{}
	conflicts := map[string]int64{}
	skipped := map[string]string{}
	archive, err := zip.OpenReader(path)
	if err != nil {
		return nil, nil, nil, errors.New("tidak bisa membuka file ZIP")
	}
	defer archive.Close()
	entries, err := zipEntryMap(archive, maxExpanded)
	if err != nil {
		return nil, nil, nil, err
	}
	manifestFile := entries["manifest.json"]
	if manifestFile == nil {
		return nil, nil, nil, errors.New("manifest.json tidak ditemukan")
	}
	manifestRaw, err := readZipEntry(manifestFile, 1<<20)
	if err != nil {
		return nil, nil, nil, err
	}
	var manifest backupManifest
	if err = json.Unmarshal(manifestRaw, &manifest); err != nil || manifest.App != "tahsin-walsan" {
		return nil, nil, nil, errors.New("manifest backup tidak valid")
	}
	tables, err := discoverBackupTables(ctx, db)
	if err != nil {
		return nil, nil, nil, err
	}
	if err := requireTransactionalBackupTables(tables); err != nil {
		return nil, nil, nil, err
	}
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return nil, nil, nil, err
	}
	defer tx.Rollback()
	for _, table := range tables {
		inserted[table.Name], conflicts[table.Name] = 0, 0
		entry := entries[table.Name+".csv"]
		if entry == nil {
			continue
		}
		var expectedChecksum string
		if value, ok := manifest.Checksums[table.Name]; ok {
			expectedChecksum = value
		}
		var expectedRows *int64
		if value, ok := manifest.Tables[table.Name]; ok {
			expectedRows = &value
		}
		inserted[table.Name], conflicts[table.Name], skipped[table.Name], err = mergeRestoreTable(ctx, tx, table, entry, maxExpanded, expectedChecksum, expectedRows)
		if err != nil {
			return nil, nil, nil, err
		}
	}
	if err = tx.Commit(); err != nil {
		return nil, nil, nil, err
	}
	return inserted, conflicts, skipped, nil
}

func mergeRestoreTable(ctx context.Context, tx *sql.Tx, table backupTable, entry *zip.File, maxBytes int64, expectedChecksum string, expectedRows *int64) (int64, int64, string, error) {
	result, err := openBackupCSV(entry, maxBytes)
	if err != nil {
		return 0, 0, "", err
	}
	defer result.source.Close()
	header, err := result.reader.Read()
	if err != nil || len(header) == 0 {
		return 0, 0, "", fmt.Errorf("header CSV %s tidak terbaca", table.Name)
	}
	indexes := make(map[string]int, len(header))
	for i, column := range header {
		if column == "" {
			return 0, 0, "", fmt.Errorf("header CSV %s tidak valid", table.Name)
		}
		if _, duplicate := indexes[column]; duplicate {
			return 0, 0, "", fmt.Errorf("header CSV %s memiliki kolom ganda", table.Name)
		}
		indexes[column] = i
	}
	for _, column := range header {
		if !backupContains(table.Columns, column) {
			return 0, 0, "", fmt.Errorf("backup %s memiliki kolom yang tidak dikenal: %s", table.Name, column)
		}
	}
	var count, inserted, conflicts int64
	if len(table.PrimaryKey) == 0 {
		for {
			record, readErr := result.reader.Read()
			if errors.Is(readErr, io.EOF) {
				break
			}
			if readErr != nil || len(record) != len(header) {
				return 0, 0, "", fmt.Errorf("CSV %s tidak valid", table.Name)
			}
			if !emptyCSVRecord(record) {
				count++
			}
		}
		if result.limited.N == 0 {
			return 0, 0, "", errors.New("CSV backup terlalu besar")
		}
		checksum := hex.EncodeToString(result.digest.Sum(nil))
		if expectedChecksum != "" && expectedChecksum != checksum {
			return 0, 0, "", fmt.Errorf("checksum CSV %s tidak cocok", table.Name)
		}
		if expectedRows != nil && *expectedRows != count {
			return 0, 0, "", fmt.Errorf("jumlah baris CSV %s tidak sesuai manifest", table.Name)
		}
		return 0, 0, "Tidak memiliki primary key; dilewati agar tidak membuat duplikasi.", nil
	}
	columns := append([]string(nil), header...)
	for _, key := range table.PrimaryKey {
		if _, ok := indexes[key]; !ok {
			return 0, 0, "", fmt.Errorf("backup %s tidak memiliki primary key %s", table.Name, key)
		}
	}
	pkWhere := make([]string, len(table.PrimaryKey))
	pkIndexes := make([]int, len(table.PrimaryKey))
	for i, key := range table.PrimaryKey {
		pkWhere[i] = "`" + key + "`=?"
		pkIndexes[i] = indexes[key]
	}
	existsQuery := "SELECT 1 FROM `" + table.Name + "` WHERE " + strings.Join(pkWhere, " AND ") + " LIMIT 1 FOR UPDATE"
	insertQuery := "INSERT INTO `" + table.Name + "` (" + quotedColumns(columns) + ") VALUES (" + placeholders(len(columns)) + ")"
	for {
		record, readErr := result.reader.Read()
		if errors.Is(readErr, io.EOF) {
			break
		}
		if readErr != nil || len(record) != len(header) {
			return 0, 0, "", fmt.Errorf("CSV %s tidak valid", table.Name)
		}
		if emptyCSVRecord(record) {
			continue
		}
		count++
		values := make([]any, len(record))
		for i, value := range record {
			if value == backupNullToken {
				values[i] = nil
			} else {
				values[i] = value
			}
		}
		keys := make([]any, len(pkIndexes))
		for i, index := range pkIndexes {
			value := record[index]
			if value == backupNullToken {
				return 0, 0, "", fmt.Errorf("primary key CSV %s bernilai NULL", table.Name)
			}
			keys[i] = value
		}
		var found int
		err = tx.QueryRowContext(ctx, existsQuery, keys...).Scan(&found)
		if err == nil {
			conflicts++
			continue
		}
		if !errors.Is(err, sql.ErrNoRows) {
			return 0, 0, "", err
		}
		if _, err = tx.ExecContext(ctx, insertQuery, values...); err != nil {
			if isDuplicateKey(err) {
				conflicts++
				continue
			}
			return 0, 0, "", err
		}
		inserted++
	}
	if result.limited.N == 0 {
		return 0, 0, "", errors.New("CSV backup terlalu besar")
	}
	checksum := hex.EncodeToString(result.digest.Sum(nil))
	if expectedChecksum != "" && expectedChecksum != checksum {
		return 0, 0, "", fmt.Errorf("checksum CSV %s tidak cocok", table.Name)
	}
	if expectedRows != nil && *expectedRows != count {
		return 0, 0, "", fmt.Errorf("jumlah baris CSV %s tidak sesuai manifest", table.Name)
	}
	return inserted, conflicts, "", nil
}

func emptyCSVRecord(record []string) bool {
	for _, value := range record {
		if value != "" {
			return false
		}
	}
	return true
}

func placeholders(count int) string {
	return strings.TrimRight(strings.Repeat("?,", count), ",")
}

func backupContains(values []string, target string) bool {
	for _, value := range values {
		if value == target {
			return true
		}
	}
	return false
}

func isDuplicateKey(err error) bool {
	var mysqlErr *mysql.MySQLError
	return errors.As(err, &mysqlErr) && mysqlErr.Number == 1062
}
