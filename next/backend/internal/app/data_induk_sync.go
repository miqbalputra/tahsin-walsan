package app

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"net/http"
	"net/url"
	"os"
	"strings"
	"time"
)

type dataIndukQuerier interface {
	ExecContext(context.Context, string, ...any) (sql.Result, error)
	QueryContext(context.Context, string, ...any) (*sql.Rows, error)
	QueryRowContext(context.Context, string, ...any) *sql.Row
}

// SyncDataInduk runs the existing Data Induk contract for a manual or scheduled operator task.
func SyncDataInduk(ctx context.Context, db *sql.DB, scope string) (map[string]any, error) {
	return (&Server{DB: db, getenv: os.Getenv}).syncDataInduk(ctx, scope)
}

func (s *Server) syncDataInduk(ctx context.Context, scope string) (map[string]any, error) {
	getenv := s.getenv
	if getenv == nil {
		getenv = os.Getenv
	}
	config, err := loadDataIndukConfig(getenv)
	if err != nil {
		return nil, err
	}
	if !config.configured() {
		return nil, &dataIndukError{Message: "DATA_INDUK_ENABLED atau konfigurasi Data Induk belum lengkap."}
	}
	if !validDataIndukScope(scope) {
		return nil, errors.New("scope sinkronisasi tidak valid")
	}
	ready, err := dataIndukSchemaReady(ctx, s.DB)
	if err != nil {
		return nil, errors.New("skema Data Induk belum dapat diperiksa")
	}
	if !ready {
		return nil, errors.New("migrasi Data Induk belum dijalankan")
	}
	client := s.dataIndukClient()
	conn, err := s.DB.Conn(ctx)
	if err != nil {
		return nil, errors.New("koneksi sinkronisasi belum tersedia")
	}
	defer conn.Close()
	var locked sql.NullInt64
	if err = conn.QueryRowContext(ctx, "SELECT GET_LOCK(?,0)", "presensi_tahsin_data_induk_sync").Scan(&locked); err != nil || !locked.Valid || locked.Int64 != 1 {
		return nil, errors.New("sinkronisasi Data Induk lain masih berjalan")
	}
	defer conn.ExecContext(context.Background(), "SELECT RELEASE_LOCK(?)", "presensi_tahsin_data_induk_sync")
	result, err := conn.ExecContext(ctx, "INSERT INTO data_induk_sync_runs(scope,status,started_at,stats_json) VALUES(?,'running',NOW(),'{}')", scope)
	if err != nil {
		return nil, errors.New("riwayat sinkronisasi belum dapat dimulai")
	}
	runID, err := result.LastInsertId()
	if err != nil || runID < 1 {
		return nil, errors.New("ID riwayat sinkronisasi belum dapat dibaca")
	}
	started := time.Now()
	stats := map[string]any{}
	var syncErr error
	switch scope {
	case "references":
		stats, syncErr = syncDataIndukReferences(ctx, conn, client, config)
	case "roster":
		stats, syncErr = syncDataIndukRoster(ctx, conn, client, config)
	case "teachers":
		stats, syncErr = syncDataIndukTeachers(ctx, conn, client, config)
	case "changes":
		stats, syncErr = syncDataIndukChanges(ctx, conn, client, config)
	case "all":
		for _, step := range []func(context.Context, dataIndukQuerier, *http.Client, dataIndukConfig) (map[string]any, error){syncDataIndukReferences, syncDataIndukRoster, syncDataIndukTeachers} {
			var part map[string]any
			part, syncErr = step(ctx, conn, client, config)
			if syncErr != nil {
				break
			}
			mergeDataIndukStats(stats, part)
		}
	}
	if syncErr == nil {
		stats["duration_ms"] = time.Since(started).Milliseconds()
		if _, err = conn.ExecContext(ctx, "UPDATE data_induk_sync_state SET last_success_at=NOW(),last_error=NULL WHERE id=1"); err != nil {
			syncErr = errors.New("status sinkronisasi belum dapat disimpan")
		}
	}
	if syncErr != nil {
		message := dataIndukSafeError(syncErr)
		_, _ = conn.ExecContext(ctx, "UPDATE data_induk_sync_state SET last_error=?,last_error_at=NOW() WHERE id=1", message)
		_, _ = conn.ExecContext(ctx, "UPDATE data_induk_sync_runs SET status='failed',finished_at=NOW(),stats_json='{}',error_message=? WHERE id=?", message, runID)
		return nil, syncErr
	}
	encoded, _ := json.Marshal(stats)
	if _, err = conn.ExecContext(ctx, "UPDATE data_induk_sync_runs SET status='success',finished_at=NOW(),stats_json=?,error_message=NULL WHERE id=?", string(encoded), runID); err != nil {
		return nil, errors.New("riwayat sinkronisasi belum dapat diselesaikan")
	}
	return stats, nil
}

func validDataIndukScope(scope string) bool {
	switch scope {
	case "all", "references", "roster", "teachers", "changes":
		return true
	default:
		return false
	}
}

func dataIndukSafeError(err error) string {
	var integrationError *dataIndukError
	if errors.As(err, &integrationError) {
		return integrationError.Error()
	}
	return "Sinkronisasi gagal; periksa log server."
}

func mergeDataIndukStats(target, source map[string]any) {
	for key, value := range source {
		if n, ok := value.(int); ok {
			if previous, ok := target[key].(int); ok {
				target[key] = previous + n
				continue
			}
		}
		target[key] = value
	}
}

func dataIndukSchemaReady(ctx context.Context, db *sql.DB) (bool, error) {
	for _, required := range []struct{ table, column string }{
		{"wali_santri", "data_induk_guardian_id"}, {"santri_detail", "data_induk_student_id"}, {"santri_detail", "data_induk_enrollment_id"}, {"santri_detail", "data_induk_class_id"},
		{"users", "data_induk_teacher_id"}, {"users", "data_induk_active"}, {"data_induk_references", "master_id"}, {"data_induk_sync_state", "change_cursor"},
		{"data_induk_sync_runs", "id"}, {"data_induk_sync_conflicts", "id"},
	} {
		var count int
		if err := db.QueryRowContext(ctx, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?", required.table, required.column).Scan(&count); err != nil {
			return false, err
		}
		if count != 1 {
			return false, nil
		}
	}
	return true, nil
}

func dataIndukQueryValues(values map[string]string) url.Values {
	query := url.Values{}
	for key, value := range values {
		if value != "" {
			query.Set(key, value)
		}
	}
	return query
}

func dataIndukTxn(ctx context.Context, queryer dataIndukQuerier, callback func(*sql.Tx) error) error {
	conn, ok := queryer.(*sql.Conn)
	if !ok {
		return errors.New("koneksi sinkronisasi tidak valid")
	}
	tx, err := conn.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	if err = callback(tx); err != nil {
		_ = tx.Rollback()
		return err
	}
	return tx.Commit()
}

func syncDataIndukReferences(ctx context.Context, queryer dataIndukQuerier, client *http.Client, config dataIndukConfig) (map[string]any, error) {
	references := []struct{ endpoint, kind string }{{"references/units", "unit"}, {"references/academic-years", "academic_year"}, {"references/grade-levels", "grade_level"}, {"references/classes", "class"}}
	stats := map[string]any{}
	for _, reference := range references {
		query := url.Values{}
		if reference.kind == "class" {
			query.Set("unitId", config.UnitID)
		}
		rows, err := dataIndukFetchAll(ctx, client, config, reference.endpoint, query)
		if err != nil {
			return nil, err
		}
		if err = dataIndukTxn(ctx, queryer, func(tx *sql.Tx) error {
			for _, row := range rows {
				id := dataIndukField(row, "id")
				if !validDataIndukID(id) {
					return errors.New("ID referensi Data Induk tidak valid")
				}
				payload, marshalErr := json.Marshal(row)
				if marshalErr != nil {
					return marshalErr
				}
				if _, execErr := tx.ExecContext(ctx, "INSERT INTO data_induk_references(master_id,reference_type,reference_code,reference_name,payload_json,archived_at,updated_at) VALUES(?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE reference_type=VALUES(reference_type),reference_code=VALUES(reference_code),reference_name=VALUES(reference_name),payload_json=VALUES(payload_json),archived_at=VALUES(archived_at),updated_at=NOW()", id, reference.kind, nullableDataIndukString(dataIndukField(row, "code")), nullableDataIndukString(dataIndukField(row, "name")), string(payload), nullableDataIndukString(dataIndukField(row, "archivedAt"))); execErr != nil {
					return execErr
				}
			}
			return nil
		}); err != nil {
			return nil, errors.New("referensi Data Induk belum dapat disimpan")
		}
		stats[reference.kind] = len(rows)
	}
	return stats, nil
}

func validDataIndukID(value string) bool {
	return value != "" && len(value) <= 36 && validParticipantText(value, 36, false)
}

func nullableDataIndukString(value string) any {
	if value == "" {
		return nil
	}
	return value
}

type guardianMatchKey struct{ name, phone string }
type studentMatchKey struct {
	guardianID int64
	name       string
}

type dataIndukRosterIndexes struct {
	guardianIDs           map[string]int64
	guardianCandidates    map[guardianMatchKey][]int64
	guardianCandidateKeys map[int64]guardianMatchKey
	studentIDs            map[string]int64
	studentCandidates     map[studentMatchKey][]int64
	studentCandidateKeys  map[int64]studentMatchKey
}

func loadDataIndukRosterIndexes(ctx context.Context, tx *sql.Tx) (dataIndukRosterIndexes, error) {
	indexes := dataIndukRosterIndexes{
		guardianIDs:           map[string]int64{},
		guardianCandidates:    map[guardianMatchKey][]int64{},
		guardianCandidateKeys: map[int64]guardianMatchKey{},
		studentIDs:            map[string]int64{},
		studentCandidates:     map[studentMatchKey][]int64{},
		studentCandidateKeys:  map[int64]studentMatchKey{},
	}
	for _, query := range []struct {
		sql string
		to  map[string]int64
	}{
		{"SELECT id,data_induk_guardian_id FROM wali_santri WHERE data_induk_guardian_id IS NOT NULL", indexes.guardianIDs},
		{"SELECT id,data_induk_student_id FROM santri_detail WHERE data_induk_student_id IS NOT NULL", indexes.studentIDs},
	} {
		rows, err := tx.QueryContext(ctx, query.sql)
		if err != nil {
			return indexes, err
		}
		for rows.Next() {
			var id int64
			var masterID string
			if err = rows.Scan(&id, &masterID); err != nil {
				rows.Close()
				return indexes, err
			}
			query.to[masterID] = id
		}
		err = rows.Err()
		rows.Close()
		if err != nil {
			return indexes, err
		}
	}
	guardianRows, err := tx.QueryContext(ctx, "SELECT id,nama_bapak,no_hp FROM wali_santri")
	if err != nil {
		return indexes, err
	}
	for guardianRows.Next() {
		var id int64
		var name string
		var phone sql.NullString
		if err = guardianRows.Scan(&id, &name, &phone); err != nil {
			guardianRows.Close()
			return indexes, err
		}
		key := guardianMatchKey{name: dataIndukNormalize(name)}
		if phone.Valid {
			key.phone = dataIndukNormalize(phone.String)
		}
		if key.name != "" && key.phone != "" {
			indexes.guardianCandidates[key] = append(indexes.guardianCandidates[key], id)
			indexes.guardianCandidateKeys[id] = key
		}
	}
	err = guardianRows.Err()
	guardianRows.Close()
	if err != nil {
		return indexes, err
	}
	studentRows, err := tx.QueryContext(ctx, "SELECT id,wali_santri_id,nama_anak FROM santri_detail")
	if err != nil {
		return indexes, err
	}
	for studentRows.Next() {
		var id int64
		var guardianID int64
		var name string
		if err = studentRows.Scan(&id, &guardianID, &name); err != nil {
			studentRows.Close()
			return indexes, err
		}
		key := studentMatchKey{guardianID: guardianID, name: dataIndukNormalize(name)}
		if key.name != "" {
			indexes.studentCandidates[key] = append(indexes.studentCandidates[key], id)
			indexes.studentCandidateKeys[id] = key
		}
	}
	err = studentRows.Err()
	studentRows.Close()
	return indexes, err
}

func updateGuardianCandidateIndex(indexes *dataIndukRosterIndexes, id int64, key guardianMatchKey) {
	if old, exists := indexes.guardianCandidateKeys[id]; exists {
		if old == key {
			return
		}
		indexes.guardianCandidates[old] = removeCandidateID(indexes.guardianCandidates[old], id)
		if len(indexes.guardianCandidates[old]) == 0 {
			delete(indexes.guardianCandidates, old)
		}
		delete(indexes.guardianCandidateKeys, id)
	}
	if key.name != "" && key.phone != "" {
		indexes.guardianCandidates[key] = append(indexes.guardianCandidates[key], id)
		indexes.guardianCandidateKeys[id] = key
	}
}

func updateStudentCandidateIndex(indexes *dataIndukRosterIndexes, id int64, key studentMatchKey) {
	if old, exists := indexes.studentCandidateKeys[id]; exists {
		if old == key {
			return
		}
		indexes.studentCandidates[old] = removeCandidateID(indexes.studentCandidates[old], id)
		if len(indexes.studentCandidates[old]) == 0 {
			delete(indexes.studentCandidates, old)
		}
		delete(indexes.studentCandidateKeys, id)
	}
	if key.name != "" {
		indexes.studentCandidates[key] = append(indexes.studentCandidates[key], id)
		indexes.studentCandidateKeys[id] = key
	}
}

func removeCandidateID(ids []int64, target int64) []int64 {
	for index, id := range ids {
		if id == target {
			return append(ids[:index], ids[index+1:]...)
		}
	}
	return ids
}

type dataIndukTeacherIndexes struct {
	mappedIDs     map[string]int64
	candidates    map[string][]int64
	candidateKeys map[int64]string
}

func loadDataIndukTeacherIndexes(ctx context.Context, tx *sql.Tx) (dataIndukTeacherIndexes, error) {
	indexes := dataIndukTeacherIndexes{mappedIDs: map[string]int64{}, candidates: map[string][]int64{}, candidateKeys: map[int64]string{}}
	rows, err := tx.QueryContext(ctx, "SELECT id,data_induk_teacher_id FROM users WHERE data_induk_teacher_id IS NOT NULL")
	if err != nil {
		return indexes, err
	}
	for rows.Next() {
		var id int64
		var masterID string
		if err = rows.Scan(&id, &masterID); err != nil {
			rows.Close()
			return indexes, err
		}
		indexes.mappedIDs[masterID] = id
	}
	err = rows.Err()
	rows.Close()
	if err != nil {
		return indexes, err
	}
	rows, err = tx.QueryContext(ctx, "SELECT id,nama_lengkap FROM users WHERE role='ustadz'")
	if err != nil {
		return indexes, err
	}
	for rows.Next() {
		var id int64
		var name string
		if err = rows.Scan(&id, &name); err != nil {
			rows.Close()
			return indexes, err
		}
		key := dataIndukNormalize(name)
		if key != "" {
			indexes.candidates[key] = append(indexes.candidates[key], id)
			indexes.candidateKeys[id] = key
		}
	}
	err = rows.Err()
	rows.Close()
	return indexes, err
}

func updateTeacherCandidateIndex(indexes *dataIndukTeacherIndexes, id int64, key string) {
	if old, exists := indexes.candidateKeys[id]; exists {
		if old == key {
			return
		}
		indexes.candidates[old] = removeCandidateID(indexes.candidates[old], id)
		if len(indexes.candidates[old]) == 0 {
			delete(indexes.candidates, old)
		}
		delete(indexes.candidateKeys, id)
	}
	if key != "" {
		indexes.candidates[key] = append(indexes.candidates[key], id)
		indexes.candidateKeys[id] = key
	}
}

func dataIndukAddConflict(ctx context.Context, tx *sql.Tx, kind, masterID string, localID *int64, reason string, payload map[string]json.RawMessage) error {
	var exists int
	if err := tx.QueryRowContext(ctx, "SELECT COUNT(*) FROM data_induk_sync_conflicts WHERE entity_type=? AND master_id=? AND status='pending'", kind, masterID).Scan(&exists); err != nil {
		return err
	}
	if exists > 0 {
		return nil
	}
	encoded, err := json.Marshal(payload)
	if err != nil {
		return err
	}
	_, err = tx.ExecContext(ctx, "INSERT INTO data_induk_sync_conflicts(entity_type,master_id,local_id,reason,payload_json,status,created_at) VALUES(?,?,?,?,?,'pending',NOW())", kind, masterID, localID, reason, string(encoded))
	return err
}

func syncDataIndukRoster(ctx context.Context, queryer dataIndukQuerier, client *http.Client, config dataIndukConfig) (map[string]any, error) {
	if familySchema, err := dataIndukFamilySchemaReady(ctx, queryer); err != nil {
		return nil, err
	} else if familySchema {
		var count int
		if err = queryer.QueryRowContext(ctx, "SELECT COUNT(*) FROM family_guardians").Scan(&count); err != nil {
			return nil, err
		}
		if count > 0 {
			return nil, &dataIndukError{Message: "Master keluarga sudah terhubung. Pemetaan ID ekspor sekolah ke UUID Data Induk diperlukan sebelum sinkronisasi roster diaktifkan."}
		}
	}
	query := url.Values{"unitId": []string{config.UnitID}}
	students, err := dataIndukFetchAll(ctx, client, config, "students", query)
	if err != nil {
		return nil, err
	}
	guardians, err := dataIndukFetchAll(ctx, client, config, "guardians", query)
	if err != nil {
		return nil, err
	}
	links, err := dataIndukFetchAll(ctx, client, config, "family-links", query)
	if err != nil {
		return nil, err
	}
	fatherByStudent, fatherGuardians := map[string]string{}, map[string]bool{}
	for _, link := range links {
		if dataIndukField(link, "relationship") == "father" && dataIndukField(link, "archivedAt") == "" {
			studentID, guardianID := dataIndukField(link, "studentId"), dataIndukField(link, "guardianId")
			if validDataIndukID(studentID) && validDataIndukID(guardianID) {
				fatherByStudent[studentID] = guardianID
				fatherGuardians[guardianID] = true
			}
		}
	}
	classes := map[string]string{}
	classRows, err := queryer.QueryContext(ctx, "SELECT master_id,reference_name FROM data_induk_references WHERE reference_type='class'")
	if err != nil {
		return nil, err
	}
	for classRows.Next() {
		var id string
		var name sql.NullString
		if err = classRows.Scan(&id, &name); err != nil {
			classRows.Close()
			return nil, err
		}
		if name.Valid {
			classes[id] = name.String
		}
	}
	if err = classRows.Err(); err != nil {
		classRows.Close()
		return nil, err
	}
	classRows.Close()
	stats := map[string]any{"students": len(students), "guardians": 0, "children": 0, "conflicts": 0}
	err = dataIndukTxn(ctx, queryer, func(tx *sql.Tx) error {
		indexes, indexErr := loadDataIndukRosterIndexes(ctx, tx)
		if indexErr != nil {
			return indexErr
		}
		guardianIDs := map[string]int64{}
		for _, guardian := range guardians {
			masterID := dataIndukField(guardian, "id")
			if !validDataIndukID(masterID) {
				return errors.New("ID wali Data Induk tidak valid")
			}
			if !fatherGuardians[masterID] {
				continue
			}
			localID, mapped := indexes.guardianIDs[masterID]
			mapped = mapped && localID > 0
			name := dataIndukField(guardian, "fullName")
			if name == "" {
				name = "Tanpa Nama"
			}
			phone, address := dataIndukField(guardian, "phone"), dataIndukField(guardian, "address")
			if len([]rune(name)) > 100 || len([]rune(phone)) > 20 || !validParticipantText(name, 100, false) || !validParticipantText(phone, 20, false) || !validParticipantText(address, 10000, true) {
				return errors.New("profil wali Data Induk melebihi batas kolom")
			}
			if !mapped {
				candidates := indexes.guardianCandidates[guardianMatchKey{name: dataIndukNormalize(name), phone: dataIndukNormalize(phone)}]
				if len(candidates) == 1 && dataIndukNormalize(phone) != "" {
					if err = dataIndukAddConflict(ctx, tx, "guardian", masterID, &candidates[0], "Kandidat nama dan nomor HP sama; persetujuan admin diperlukan.", guardian); err != nil {
						return err
					}
					stats["conflicts"] = stats["conflicts"].(int) + 1
					continue
				}
				result, insertErr := tx.ExecContext(ctx, "INSERT INTO wali_santri(nama_bapak,no_hp,alamat,kategori,status_aktif,data_induk_guardian_id) VALUES(?,?,?,'reguler',1,?)", name, nullableDataIndukString(phone), nullableDataIndukString(address), masterID)
				if insertErr != nil {
					return insertErr
				}
				localID, insertErr = result.LastInsertId()
				if insertErr != nil {
					return insertErr
				}
				indexes.guardianIDs[masterID] = localID
			} else {
				active := 1
				if dataIndukField(guardian, "archivedAt") != "" {
					active = 0
				}
				if _, err = tx.ExecContext(ctx, "UPDATE wali_santri SET nama_bapak=?,no_hp=?,alamat=?,status_aktif=? WHERE id=?", name, nullableDataIndukString(phone), nullableDataIndukString(address), active, localID); err != nil {
					return err
				}
			}
			updateGuardianCandidateIndex(&indexes, localID, guardianMatchKey{name: dataIndukNormalize(name), phone: dataIndukNormalize(phone)})
			guardianIDs[masterID] = localID
			stats["guardians"] = stats["guardians"].(int) + 1
		}
		for _, student := range students {
			masterID := dataIndukField(student, "id")
			if !validDataIndukID(masterID) {
				return errors.New("ID siswa Data Induk tidak valid")
			}
			guardianID := fatherByStudent[masterID]
			waliID := guardianIDs[guardianID]
			if waliID == 0 {
				continue
			}
			childID, mapped := indexes.studentIDs[masterID]
			mapped = mapped && childID > 0
			name := dataIndukField(student, "fullName")
			if name == "" {
				name = "Tanpa Nama"
			}
			if len([]rune(name)) > 100 || !validParticipantText(name, 100, false) {
				return errors.New("nama siswa Data Induk melebihi batas kolom")
			}
			classID := dataIndukField(student, "classId")
			className := classes[classID]
			status := "archived"
			if dataIndukField(student, "status") == "active" {
				status = "active"
			}
			if !mapped {
				key := studentMatchKey{guardianID: waliID, name: dataIndukNormalize(name)}
				candidates := indexes.studentCandidates[key]
				if len(candidates) == 1 {
					if err = dataIndukAddConflict(ctx, tx, "student", masterID, &candidates[0], "Kandidat anak dengan wali dan nama sama; persetujuan admin diperlukan.", student); err != nil {
						return err
					}
					stats["conflicts"] = stats["conflicts"].(int) + 1
					continue
				}
				result, insertErr := tx.ExecContext(ctx, "INSERT INTO santri_detail(wali_santri_id,nama_anak,kelas,data_induk_student_id,data_induk_enrollment_id,data_induk_class_id,status_roster) VALUES(?,?,?,?,?,?,?)", waliID, name, nullableDataIndukString(className), masterID, nullableDataIndukString(dataIndukField(student, "enrollmentId")), nullableDataIndukString(classID), status)
				if insertErr != nil {
					return insertErr
				}
				childID, insertErr = result.LastInsertId()
				if insertErr != nil {
					return insertErr
				}
				indexes.studentIDs[masterID] = childID
			} else if _, err = tx.ExecContext(ctx, "UPDATE santri_detail SET wali_santri_id=?,nama_anak=?,kelas=?,data_induk_enrollment_id=?,data_induk_class_id=?,status_roster=? WHERE id=?", waliID, name, nullableDataIndukString(className), nullableDataIndukString(dataIndukField(student, "enrollmentId")), nullableDataIndukString(classID), status, childID); err != nil {
				return err
			}
			updateStudentCandidateIndex(&indexes, childID, studentMatchKey{guardianID: waliID, name: dataIndukNormalize(name)})
			stats["children"] = stats["children"].(int) + 1
		}
		return nil
	})
	if err != nil {
		return nil, errors.New("snapshot keluarga Data Induk belum dapat diterapkan")
	}
	return stats, nil
}

func dataIndukFamilySchemaReady(ctx context.Context, queryer dataIndukQuerier) (bool, error) {
	var exists int
	if err := queryer.QueryRowContext(ctx, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='family_guardians'").Scan(&exists); err != nil {
		return false, err
	}
	return exists == 1, nil
}

func syncDataIndukTeachers(ctx context.Context, queryer dataIndukQuerier, client *http.Client, config dataIndukConfig) (map[string]any, error) {
	teachers, err := dataIndukFetchAll(ctx, client, config, "teachers", url.Values{"unitId": []string{config.UnitID}})
	if err != nil {
		return nil, err
	}
	stats := map[string]any{"teachers": 0, "conflicts": 0}
	err = dataIndukTxn(ctx, queryer, func(tx *sql.Tx) error {
		indexes, indexErr := loadDataIndukTeacherIndexes(ctx, tx)
		if indexErr != nil {
			return indexErr
		}
		for _, teacher := range teachers {
			masterID, name := dataIndukField(teacher, "id"), dataIndukField(teacher, "fullName")
			if !validDataIndukID(masterID) {
				return errors.New("ID ustadz Data Induk tidak valid")
			}
			localID, mapped := indexes.mappedIDs[masterID]
			mapped = mapped && localID > 0
			if !mapped {
				candidates := indexes.candidates[dataIndukNormalize(name)]
				if len(candidates) == 1 {
					if err = dataIndukAddConflict(ctx, tx, "teacher", masterID, &candidates[0], "Kandidat ustadz dengan nama sama; persetujuan admin diperlukan.", teacher); err != nil {
						return err
					}
					stats["conflicts"] = stats["conflicts"].(int) + 1
				}
				continue
			}
			phone := dataIndukField(teacher, "phone")
			if len([]rune(name)) > 100 || len([]rune(phone)) > 20 || !validParticipantText(name, 100, false) || !validParticipantText(phone, 20, false) {
				return errors.New("profil ustadz Data Induk melebihi batas kolom")
			}
			if _, err = tx.ExecContext(ctx, "UPDATE users SET nama_lengkap=?,no_hp=?,data_induk_active=1 WHERE id=?", name, nullableDataIndukString(phone), localID); err != nil {
				return err
			}
			updateTeacherCandidateIndex(&indexes, localID, dataIndukNormalize(name))
			stats["teachers"] = stats["teachers"].(int) + 1
		}
		return nil
	})
	if err != nil {
		return nil, errors.New("snapshot ustadz Data Induk belum dapat diterapkan")
	}
	return stats, nil
}

func syncDataIndukChanges(ctx context.Context, queryer dataIndukQuerier, client *http.Client, config dataIndukConfig) (map[string]any, error) {
	var cursor string
	if err := queryer.QueryRowContext(ctx, "SELECT change_cursor FROM data_induk_sync_state WHERE id=1").Scan(&cursor); err != nil {
		return nil, err
	}
	query := url.Values{"unitId": []string{config.UnitID}, "cursor": []string{cursor}, "limit": []string{"200"}}
	raw, err := dataIndukHTTPGet(ctx, client, config, "changes", query)
	if err != nil {
		return nil, err
	}
	var feed struct {
		Items      []map[string]json.RawMessage `json:"items"`
		NextCursor string                       `json:"nextCursor"`
	}
	if json.Unmarshal(raw, &feed) != nil {
		return nil, &dataIndukError{Message: "Respons perubahan Data Induk tidak valid."}
	}
	if feed.Items == nil {
		feed.Items = []map[string]json.RawMessage{}
	}
	for _, event := range feed.Items {
		if dataIndukField(event, "operation") != "archive" {
			continue
		}
		entityID := dataIndukField(event, "entityId")
		if !validDataIndukID(entityID) {
			return nil, errors.New("ID perubahan Data Induk tidak valid")
		}
		switch strings.ToLower(dataIndukField(event, "entityType")) {
		case "guardian":
			if _, err = queryer.ExecContext(ctx, "UPDATE wali_santri SET status_aktif=0 WHERE data_induk_guardian_id=?", entityID); err != nil {
				return nil, err
			}
		case "student":
			if _, err = queryer.ExecContext(ctx, "UPDATE santri_detail SET status_roster='archived' WHERE data_induk_student_id=?", entityID); err != nil {
				return nil, err
			}
		case "teacher":
			if _, err = queryer.ExecContext(ctx, "UPDATE users SET data_induk_active=0 WHERE data_induk_teacher_id=?", entityID); err != nil {
				return nil, err
			}
		}
	}
	stats := map[string]any{"events": len(feed.Items)}
	if len(feed.Items) > 0 {
		for _, step := range []func(context.Context, dataIndukQuerier, *http.Client, dataIndukConfig) (map[string]any, error){syncDataIndukReferences, syncDataIndukRoster, syncDataIndukTeachers} {
			part, stepErr := step(ctx, queryer, client, config)
			if stepErr != nil {
				return nil, stepErr
			}
			mergeDataIndukStats(stats, part)
		}
	}
	nextCursor := feed.NextCursor
	if nextCursor == "" {
		nextCursor = cursor
	}
	if _, err = queryer.ExecContext(ctx, "UPDATE data_induk_sync_state SET change_cursor=?,last_success_at=NOW(),last_error=NULL WHERE id=1", nextCursor); err != nil {
		return nil, err
	}
	return stats, nil
}

func (s *Server) dataIndukClient() *http.Client {
	return &http.Client{Timeout: 15 * time.Second, CheckRedirect: func(_ *http.Request, _ []*http.Request) error { return http.ErrUseLastResponse }}
}
