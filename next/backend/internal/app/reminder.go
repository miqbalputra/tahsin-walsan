package app

import (
	"fmt"
	"net/http"
	"sort"
	"strings"
	"time"
)

func (s *Server) handleTahsinReminder(daily bool) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Cache-Control", "no-store")
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		if r.Method != http.MethodGet {
			integrationJSON(w, http.StatusMethodNotAllowed, map[string]any{"error": "Method not allowed."})
			return
		}
		key := s.Config.N8NAPIKey
		if key == "" {
			integrationJSON(w, http.StatusServiceUnavailable, map[string]any{"error": "API belum dikonfigurasi."})
			return
		}
		provided := strings.TrimSpace(r.Header.Get("X-API-Key"))
		if provided == "" {
			provided = strings.TrimSpace(r.URL.Query().Get("key"))
		}
		if !integrationKeyEqual(key, provided) {
			integrationJSON(w, http.StatusUnauthorized, map[string]any{"error": "Unauthorized"})
			return
		}
		now := time.Now().In(mustLocation(s.Config.Timezone))
		weekday := int(now.Weekday())
		if daily && weekday == 0 || !daily && weekday != 0 {
			reason := "Today is not Sunday"
			if daily {
				reason = "Hari Ahad, sesi sedang berlangsung."
			}
			integrationJSON(w, http.StatusOK, map[string]any{"status": "skip", "reason": reason})
			return
		}
		if s.DB == nil {
			integrationJSON(w, http.StatusServiceUnavailable, map[string]any{"error": "Database belum siap."})
			return
		}
		session, err := tahsinSessionDate(now.Format("2006-01-02"))
		if err != nil {
			integrationJSON(w, http.StatusInternalServerError, map[string]any{"error": "Tanggal sesi belum dapat diperiksa."})
			return
		}
		end := min(sessionAddDays(session, 6), now.Format("2006-01-02"))
		data, err := loadTahsinReportDataset(r.Context(), s.DB, end, now)
		if err != nil {
			integrationJSON(w, http.StatusInternalServerError, map[string]any{"error": "Terjadi kesalahan server."})
			return
		}
		if !tahsinSessionIsActive(session, data.Holidays) {
			reason := "Sesi Ahad pekan ini tercatat libur."
			if session[8:10] <= "07" {
				reason = "Ahad pekan pertama libur; tidak ada kewajiban presensi pekan ini."
			}
			integrationJSON(w, http.StatusOK, map[string]any{"status": "skip", "reason": reason, "tanggal_tahsin": session})
			return
		}
		deadline, _ := time.ParseInLocation("2006-01-02 15:04:05", session+" 07:30:00", mustLocation(s.Config.Timezone))
		if now.Before(deadline) {
			integrationJSON(w, http.StatusOK, map[string]any{"status": "skip", "reason": "Sesi tahsin masih berlangsung sampai sekitar 07.30 WIB.", "tanggal_tahsin": session})
			return
		}
		start := session
		allPending := r.URL.Query().Get("all_pending") == "1"
		if allPending && len(data.TrackedFrom) >= 10 && strings.Compare(data.TrackedFrom[:10], start) < 0 {
			start = data.TrackedFrom[:10]
		}
		filters := tahsinReportFilters{StartDate: start, EndDate: now.Format("2006-01-02")}
		report := assembleTahsinReportWithReminderSession(data, filters, "admin", 0, now, session)
		groups := reminderGroupsFromReport(report, now, session)
		pending := []map[string]any{}
		current := []map[string]any{}
		currentRosterGroups := 0
		counts := map[string]int64{"H": 0, "S": 0, "I": 0, "A": 0}
		checked, complete := int64(0), int64(0)
		for _, group := range groups {
			if group["belum_diisi"].(int64) > 0 {
				pending = append(pending, group)
			}
			if group["tanggal_tahsin"] == session {
				current = append(current, group)
				checked += group["total_anggota"].(int64)
				complete += group["complete_input"].(int64)
				for status, count := range group["attendance_counts"].(map[string]int64) {
					counts[status] += count
				}
				if group["roster_basis"] == "current_roster" {
					currentRosterGroups++
				}
			}
		}
		rawCounts := map[string]int64{"H": 0, "S": 0, "I": 0, "A": 0}
		for _, record := range data.Records {
			if record.Date >= session && record.Date <= now.Format("2006-01-02") {
				if _, ok := rawCounts[record.Status]; ok {
					rawCounts[record.Status]++
				}
			}
		}
		pendingTeachers := map[int64]bool{}
		var pendingCount, missingAttendance, missingAchievement, missingReason, conflicts int64
		for _, group := range pending {
			teacherID := group["ustadz_id"].(int64)
			if teacherID != 0 {
				pendingTeachers[teacherID] = true
			}
			pendingCount += group["belum_diisi"].(int64)
			missingAttendance += group["missing_presensi"].(int64)
			missingAchievement += group["missing_capaian"].(int64)
			missingReason += group["missing_keterangan"].(int64)
			conflicts += group["conflicts"].(int64)
		}
		dayNames := []string{"Minggu", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu"}
		response := map[string]any{"status": "success", "tanggal_tahsin": session, "count": len(pending), "data": pending, "inspection_complete": checked > 0,
			"summary": map[string]any{"checked_group_sessions": len(groups), "current_group_count": len(current), "checked_participant_sessions": checked, "complete_participant_sessions": complete,
				"pending_teachers": len(pendingTeachers), "pending_participant_sessions": pendingCount, "missing_presensi": missingAttendance, "missing_capaian": missingAchievement,
				"missing_keterangan": missingReason, "conflicts": conflicts, "attendance_counts": counts, "raw_status_counts": rawCounts,
				"roster_basis": reminderRosterBasis(current, currentRosterGroups), "history_ready": data.SchemaReady, "tracked_from": data.TrackedFrom, "backlog_requested": allPending},
			"warnings": []string{}}
		if currentRosterGroups > 0 {
			response["warnings"] = []string{"Pekan berjalan diperiksa memakai roster aktif yang juga dipakai formulir input. Histori sesi belum tersedia; kekurangan ini tidak digunakan untuk menebak kewajiban pekan lama."}
		}
		if daily {
			response["date"], response["date_today"] = now.Format("2006-01-02"), now.Format("2006-01-02")
			response["day"] = dayNames[weekday]
			response["tanggal_tahsin_formatted"] = "Ahad, " + formatIndonesianDate(session)
			response["server_timezone"], response["server_time"] = "Asia/Jakarta", now.Format("2006-01-02 15:04:05")
		}
		integrationJSON(w, http.StatusOK, response)
	}
}

func sessionAddDays(session string, days int) string {
	date, err := time.Parse("2006-01-02", session)
	if err != nil {
		return session
	}
	return date.AddDate(0, 0, days).Format("2006-01-02")
}

func reminderRosterBasis(groups []map[string]any, currentFallback int) string {
	if currentFallback > 0 {
		return "current_roster"
	}
	if len(groups) > 0 {
		return "session_history"
	}
	return "unavailable"
}

func reminderGroupsFromReport(report tahsinReportResult, now time.Time, currentSession string) []map[string]any {
	groups := map[string]map[string]any{}
	for _, row := range report.Rows {
		for _, cell := range row.Details {
			currentFallback := currentSession != "" && cell.SessionDate == currentSession && !cell.VerifiedRoster && cell.RosterSource == "Roster aktif untuk pekan berjalan"
			if !cell.InSchedule || !cell.Expected || (!cell.VerifiedRoster && !currentFallback) || cell.Owner.GroupID == 0 {
				continue
			}
			key := fmt.Sprintf("%s:%d:%d", cell.SessionDate, cell.Owner.GroupID, cell.Owner.TeacherID)
			group := groups[key]
			if group == nil {
				basis := "session_history"
				source := "Histori roster pada sesi"
				if currentFallback {
					basis, source = "current_roster", "Roster aktif untuk pekan berjalan"
				}
				group = map[string]any{"halaqoh_id": cell.Owner.GroupID, "nama_halaqoh": cell.Owner.GroupName, "ustadz_id": cell.Owner.TeacherID, "ustadz_name": cell.Owner.Teacher, "ustadz_phone": normalizeIDPhone(cell.Owner.Phone),
					"tanggal_tahsin": cell.SessionDate, "tanggal_tahsin_formatted": "Ahad, " + formatIndonesianDate(cell.SessionDate), "total_anggota": int64(0), "sudah_diisi": int64(0), "complete_input": int64(0),
					"missing_presensi": int64(0), "missing_capaian": int64(0), "missing_keterangan": int64(0), "conflicts": int64(0), "pending_participants": []map[string]any{}, "deadline_at": cell.DeadlineAt,
					"has_expected_roster": true, "expected_count": int64(0), "expected_complete": int64(0), "expected_recorded": int64(0), "roster_source": source, "data_verified": cell.VerifiedRoster, "roster_basis": basis,
					"attendance_counts": map[string]int64{"H": 0, "S": 0, "I": 0, "A": 0}}
				groups[key] = group
			}
			group["total_anggota"] = group["total_anggota"].(int64) + 1
			group["expected_count"] = group["expected_count"].(int64) + 1
			if cell.Record != nil {
				group["sudah_diisi"] = group["sudah_diisi"].(int64) + 1
				group["expected_recorded"] = group["expected_recorded"].(int64) + 1
				counts := group["attendance_counts"].(map[string]int64)
				if _, ok := counts[cell.Record.Status]; ok {
					counts[cell.Record.Status]++
				}
			}
			if cell.Complete {
				group["complete_input"] = group["complete_input"].(int64) + 1
				group["expected_complete"] = group["expected_complete"].(int64) + 1
			} else {
				field := "missing_presensi"
				if cell.State == "conflict" || cell.State == "invalid_date" {
					field = "conflicts"
				} else if cell.State == "capaian" {
					field = "missing_capaian"
				} else if cell.State == "presensi_incomplete" {
					field = "missing_keterangan"
				}
				group[field] = group[field].(int64) + 1
				pending := group["pending_participants"].([]map[string]any)
				group["pending_participants"] = append(pending, map[string]any{"wali_santri_id": row.ID, "nama_bapak": row.Name, "state": cell.State, "missing_fields": cell.MissingFields, "session_date": cell.SessionDate})
			}
		}
	}
	result := make([]map[string]any, 0, len(groups))
	for _, group := range groups {
		pending := group["pending_participants"].([]map[string]any)
		group["belum_diisi"] = len(pending)
		group["recorded_participants"] = group["total_anggota"]
		group["coverage_percent"] = nil
		if expected := group["expected_count"].(int64); expected > 0 {
			group["coverage_percent"] = float64(group["expected_complete"].(int64)) * 100 / float64(expected)
		}
		status := "lengkap"
		if group["conflicts"].(int64) > 0 {
			status = "perlu_verifikasi"
		} else if group["missing_capaian"].(int64) > 0 {
			status = "capaian_belum_lengkap"
		} else if len(pending) > 0 && group["sudah_diisi"].(int64) > 0 {
			status = "belum_lengkap"
		} else if len(pending) > 0 {
			status = "belum_sama_sekali"
		}
		labels := map[string]string{"perlu_verifikasi": "Perlu Verifikasi", "capaian_belum_lengkap": "Capaian Belum Lengkap", "lengkap": "Lengkap", "belum_lengkap": "Sebagian Diinput", "belum_sama_sekali": "Belum Diinput"}
		group["status_isi"], group["status_label"] = status, labels[status]
		group["keterangan"] = fmt.Sprintf("%s: lengkap %v dari %v peserta; kurang %d", labels[status], group["complete_input"], group["total_anggota"], len(pending))
		timeliness := "Lengkap"
		if len(pending) > 0 {
			timeliness = "Menunggu Input"
			if now.Format("2006-01-02 15:04:05") >= fmt.Sprint(group["deadline_at"]) {
				timeliness = "Terlambat"
			}
		}
		group["timeliness"] = timeliness
		if coverage, ok := group["coverage_percent"].(float64); ok {
			group["coverage_percent"] = mathRound1(coverage)
		}
		result = append(result, group)
	}
	sort.Slice(result, func(i, j int) bool {
		left, right := result[i], result[j]
		if left["ustadz_name"] != right["ustadz_name"] {
			return fmt.Sprint(left["ustadz_name"]) < fmt.Sprint(right["ustadz_name"])
		}
		if left["tanggal_tahsin"] != right["tanggal_tahsin"] {
			return fmt.Sprint(left["tanggal_tahsin"]) < fmt.Sprint(right["tanggal_tahsin"])
		}
		return fmt.Sprint(left["nama_halaqoh"]) < fmt.Sprint(right["nama_halaqoh"])
	})
	return result
}

func normalizeIDPhone(value string) string {
	digits := strings.Map(func(r rune) rune {
		if r >= '0' && r <= '9' {
			return r
		}
		return -1
	}, value)
	if strings.HasPrefix(digits, "0") {
		digits = "62" + digits[1:]
	}
	if len(digits) < 10 || len(digits) > 15 || !strings.HasPrefix(digits, "62") {
		return ""
	}
	return digits
}

func formatIndonesianDate(value string) string {
	date, err := time.Parse("2006-01-02", value)
	if err != nil {
		return value
	}
	return date.Format("02/01/2006")
}

func mathRound1(value float64) float64 { return float64(int(value*10+0.5)) / 10 }
