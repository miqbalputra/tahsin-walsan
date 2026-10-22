package app

import (
	"sort"
	"strings"
	"time"
)

func assembleTahsinReport(data *tahsinReportDataset, filters tahsinReportFilters, role string, userID int64, now time.Time) tahsinReportResult {
	return assembleTahsinReportWithReminderSession(data, filters, role, userID, now, "")
}

func assembleTahsinReportWithReminderSession(data *tahsinReportDataset, filters tahsinReportFilters, role string, userID int64, now time.Time, reminderSession string) tahsinReportResult {
	loc := mustLocation("Asia/Jakarta")
	now = now.In(loc)
	end := min(filters.EndDate, now.Format("2006-01-02"))
	sessions := []string{}
	if filters.StartDate <= end {
		sessions = tahsinSessions(filters.StartDate, end, data.Holidays)
	}
	childrenByWali := map[int64][]reportChild{}
	for _, child := range data.Children {
		childrenByWali[child.WaliID] = append(childrenByWali[child.WaliID], reportChild{Name: child.Name, Class: child.Class})
	}
	currentByWali := map[int64][]tahsinOwner{}
	historyByWali := map[int64][]tahsinOwner{}
	recordsByWali := map[int64]map[string][]tahsinRecord{}
	for _, owner := range data.Current {
		currentByWali[owner.WaliID] = append(currentByWali[owner.WaliID], owner)
	}
	for _, owner := range data.History {
		historyByWali[owner.WaliID] = append(historyByWali[owner.WaliID], owner)
	}
	for _, record := range data.Records {
		session, err := tahsinSessionDate(record.Date)
		if err != nil || session > end || record.Date > now.Format("2006-01-02") {
			continue
		}
		if recordsByWali[record.WaliID] == nil {
			recordsByWali[record.WaliID] = map[string][]tahsinRecord{}
		}
		recordsByWali[record.WaliID][session] = append(recordsByWali[record.WaliID][session], record)
	}
	rows := make([]tahsinReportRow, 0)
	allSessions := map[string]bool{}
	for _, session := range sessions {
		allSessions[session] = true
	}
	for waliID := range recordsByWali {
		for session := range recordsByWali[waliID] {
			allSessions[session] = true
		}
	}
	sessionKeys := make([]string, 0, len(allSessions))
	for session := range allSessions {
		sessionKeys = append(sessionKeys, session)
	}
	sort.Strings(sessionKeys)

	for _, wali := range data.Walis {
		if filters.WaliID > 0 && wali.ID != filters.WaliID {
			continue
		}
		if filters.Category != "" && wali.Category != filters.Category {
			continue
		}
		if filters.Active != "" && strconvBool(wali.Active) != filters.Active {
			continue
		}
		children := childrenByWali[wali.ID]
		if filters.Class != "" {
			hasClass := false
			for _, child := range children {
				if child.Class == filters.Class {
					hasClass = true
					break
				}
			}
			if !hasClass {
				continue
			}
		}
		current := currentByWali[wali.ID]
		history := historyByWali[wali.ID]
		matchingCurrent := []tahsinOwner{}
		for _, owner := range current {
			if reportOwnerMatches(owner, filters, role, userID) {
				matchingCurrent = append(matchingCurrent, owner)
			}
		}
		currentOwner := tahsinOwner{}
		if len(matchingCurrent) > 0 {
			currentOwner = matchingCurrent[0]
		} else if len(current) > 0 {
			currentOwner = current[0]
		}
		childLabels := make([]string, 0, len(children))
		searchText := wali.Name
		for _, child := range children {
			label := child.Name
			if child.Class != "" {
				label += " [" + child.Class + "]"
			}
			childLabels = append(childLabels, label)
			searchText += " " + label
		}
		for _, owner := range current {
			searchText += " " + owner.GroupName
		}
		for _, owner := range history {
			searchText += " " + owner.GroupName
		}
		if filters.Search != "" && !strings.Contains(strings.ToLower(searchText), strings.ToLower(filters.Search)) {
			continue
		}
		row := tahsinReportRow{ID: wali.ID, Name: wali.Name, Phone: wali.Phone, Category: wali.Category, Active: wali.Active, Place: wali.Place, OutsideTeacher: wali.OutsideTeacher,
			ChildrenText: strings.Join(childLabels, ", "), Children: children, GroupID: currentOwner.GroupID, TeacherID: currentOwner.TeacherID,
			GroupName: defaultString(currentOwner.GroupName, "—"), TeacherName: defaultString(currentOwner.Teacher, "—"), CurrentGroupID: currentOwner.GroupID,
			LatestMaterial: "—", Details: []tahsinCell{}, ContinueTahsin: wali.Continue}
		relevant := reportOwnerMatches(currentOwner, filters, role, userID)
		for _, session := range sessionKeys {
			inPeriod := session >= filters.StartDate && session <= end
			inSchedule := tahsinSessionIsActive(session, data.Holidays)
			boundary := session + " 00:00:00.000000"
			known := data.TrackedFrom != "" && boundary >= data.TrackedFrom
			owners := []tahsinOwner{}
			if known {
				for _, owner := range history {
					if owner.ValidFrom <= boundary && (owner.ValidUntil == "" || owner.ValidUntil > boundary) {
						owners = appendUniqueOwner(owners, owner)
					}
				}
			}
			currentRosterFallback := false
			if len(owners) == 0 && reminderSession != "" && session == reminderSession {
				for _, owner := range current {
					owners = appendUniqueOwner(owners, owner)
				}
				currentRosterFallback = len(owners) > 0
			}
			raw := recordsByWali[wali.ID][session]
			if len(owners) == 0 && len(raw) == 0 {
				continue
			}
			verificationValue, hasVerification := data.Verifications[tahsinSessionKey(wali.ID, session)]
			var verification *tahsinVerification
			if hasVerification {
				verification = &verificationValue
			}
			resolved := resolveTahsinSession(raw, verification, data.Suspect, data.Holidays)
			recordCopies := append([]tahsinRecord(nil), resolved.Records...)
			for i := range recordCopies {
				_, recordCopies[i].OnHoliday = data.Holidays[recordCopies[i].Date]
			}
			resolved.Records = recordCopies
			owner := tahsinOwner{}
			if len(owners) > 0 {
				owner = owners[0]
			}
			if len(owners) > 1 {
				for _, candidate := range owners {
					if reportOwnerMatches(candidate, filters, role, userID) {
						owner = candidate
						break
					}
				}
			}
			if len(owners) > 1 && resolved.Record != nil && verification != nil && !resolved.Stale {
				selected := []tahsinOwner{}
				for _, candidate := range owners {
					if candidate.GroupID == resolved.Record.GroupID {
						selected = append(selected, candidate)
					}
				}
				if len(selected) == 1 {
					owners = selected
					owner = selected[0]
				}
			}
			if owner.GroupID == 0 && len(raw) > 0 {
				candidate := raw[0]
				if resolved.Record != nil {
					candidate = *resolved.Record
				}
				owner = data.Groups[candidate.GroupID]
				owner.WaliID = wali.ID
			}
			ownerMatches := reportOwnerMatches(owner, filters, role, userID)
			if len(owners) > 1 {
				resolved.State = "conflict"
				resolved.Record = nil
				resolved.Complete = false
				resolved.Missing = []string{"verifikasi penempatan ganda pada sesi ini"}
			}
			if role == "ustadz" {
				visible := make([]tahsinRecord, 0, len(resolved.Records))
				for _, record := range resolved.Records {
					if data.Groups[record.GroupID].TeacherID == userID {
						visible = append(visible, record)
						continue
					}
					for _, o := range owners {
						if o.TeacherID == userID && o.GroupID == record.GroupID {
							visible = append(visible, record)
							break
						}
					}
				}
				resolved.Records = visible
			}
			if inPeriod && ownerMatches && (len(owners) > 0 || len(raw) > 0) {
				relevant = true
			}
			if !ownerMatches {
				continue
			}
			verifiedSource := "Histori belum tersedia"
			if known {
				verifiedSource = "Histori roster pada sesi"
			} else if currentRosterFallback {
				verifiedSource = "Roster aktif untuk pekan berjalan"
			}
			cell := tahsinCell{SessionDate: session, State: resolved.State, Complete: resolved.Complete, MissingFields: resolved.Missing, Records: resolved.Records, Record: resolved.Record,
				Owner: owner, Expected: inSchedule && len(owners) >= 1, InSchedule: inSchedule, VerifiedRoster: known, DeadlineAt: tahsinDeadlineString(session), RosterSource: verifiedSource,
				Fingerprint: resolved.Fingerprint, VerificationStale: resolved.Stale}
			if inSchedule && resolved.Record != nil && resolved.Record.Status == "H" {
				if row.LastAttendance == "" || resolved.Record.Date > row.LastAttendance {
					row.LastAttendance = resolved.Record.Date
				}
				if resolved.Complete {
					row.LatestMaterial = tahsinMaterial(resolved.Record)
					latest := *resolved.Record
					row.LatestRecord = &latest
				}
			}
			if inPeriod {
				if owner.GroupID > 0 && (cell.Expected || len(cell.Records) > 0) {
					row.GroupID = owner.GroupID
					row.TeacherID = owner.TeacherID
					row.GroupName = defaultString(owner.GroupName, row.GroupName)
					row.TeacherName = defaultString(owner.Teacher, row.TeacherName)
				}
				addTahsinCellTotals(&row.tahsinTotals, cell, resolved)
				row.Details = append(row.Details, cell)
			}
		}
		for _, session := range sessions {
			if (!data.SchemaReady || session+" 00:00:00.000000" < data.TrackedFrom) && ((len(current) > 0 && reportOwnerMatches(currentOwner, filters, role, userID)) || row.TotalPresensi > 0 || row.Conflicts > 0) {
				row.Unverified = 1
				break
			}
		}
		if !relevant {
			continue
		}
		if row.LatestMaterial == "—" {
			if baseline, ok := data.Baselines[wali.ID]; ok && baseline.CreatedAt != "" && baseline.CreatedAt[:min(len(baseline.CreatedAt), 10)] <= end {
				row.LatestMaterial = "Capaian awal: " + tahsinMaterial(&baseline)
				initial := baseline
				row.BaselineRecord = &initial
			}
		}
		row.tahsinTotals = finalizeTahsinTotals(row.tahsinTotals)
		row.Quality, row.QualityLabel = tahsinQuality(row)
		if row.Alpha >= 3 {
			row.Risk = "rawan"
			row.RiskLabel = "Butuh Follow-up (Alfa tercatat)"
		} else if row.Alpha > 0 {
			row.Risk = "pantau"
			row.RiskLabel = "Pantau (Alfa tercatat)"
		} else if row.TotalPresensi > 0 {
			row.Risk = "stabil"
			row.RiskLabel = "Tanpa Alfa Tercatat"
		} else {
			row.Risk = "belum_data"
			row.RiskLabel = "Belum Ada Data Kehadiran"
		}
		if filters.Quality != "" && row.Quality != filters.Quality {
			continue
		}
		if filters.Risk != "" && row.Risk != filters.Risk {
			continue
		}
		rows = append(rows, row)
	}

	summary := tahsinReportSummary{}
	groupAcc := map[int64]*groupAccumulator{}
	teacherAcc := map[int64]*groupAccumulator{}
	trend := map[string]*tahsinTrend{}
	for _, session := range sessions {
		known := data.SchemaReady && session+" 00:00:00.000000" >= data.TrackedFrom
		trend[session] = &tahsinTrend{Date: session, RosterVerified: known}
	}
	periodParticipants := map[int64]bool{}
	for _, row := range rows {
		addTotals(&summary.tahsinTotals, row.tahsinTotals)
		for _, cell := range row.Details {
			if !cell.InSchedule {
				continue
			}
			if cell.Expected || len(cell.Records) > 0 {
				periodParticipants[row.ID] = true
			}
			placementConflict := containsString(cell.MissingFields, "verifikasi penempatan ganda pada sesi ini")
			groupID, teacherID := cell.Owner.GroupID, cell.Owner.TeacherID
			groupName, teacherName := cell.Owner.GroupName, cell.Owner.Teacher
			if placementConflict {
				groupID = 0
				teacherID = 0
				groupName = "Perlu Verifikasi Penempatan"
				teacherName = "Belum ditentukan"
			}
			group := ensureGroup(groupAcc, groupID, groupName, teacherID, teacherName)
			group.members[row.ID] = true
			group.tahsinTotals.Unverified = max(group.tahsinTotals.Unverified, row.Unverified)
			resolved := resolvedFromReportCell(cell)
			addTahsinCellTotals(&group.tahsinTotals, cell, resolved)
			teacherGroup := ensureGroup(teacherAcc, teacherID, teacherName, groupID, groupName)
			teacherGroup.members[row.ID] = true
			teacherGroup.tahsinTotals.Unverified = max(teacherGroup.tahsinTotals.Unverified, row.Unverified)
			addTahsinCellTotals(&teacherGroup.tahsinTotals, cell, resolved)
			if target := trend[cell.SessionDate]; target != nil {
				addTahsinCellTotals(&target.tahsinTotals, cell, resolved)
			}
		}
	}
	summary.Participants = int64(len(rows))
	summary.ParticipantsInPeriod = int64(len(periodParticipants))
	summary.ParticipantsInSessions = summary.Expected
	for _, row := range rows {
		if row.Risk == "rawan" {
			summary.AtRisk++
		}
	}
	groups := finalizeGroupAccumulators(groupAcc)
	teachers := finalizeGroupAccumulators(teacherAcc)
	for _, item := range groups {
		if item.ID != 0 {
			summary.TotalGroups++
		}
	}
	trendRows := make([]tahsinTrend, 0, len(sessions))
	for _, session := range sessions {
		item := trend[session]
		item.SessionCount = item.Expected
		if item.RosterVerified {
			count := item.Expected
			item.RosterCount = &count
		} else {
			item.Unverified = 1
		}
		item.Provisional = !item.RosterVerified || item.Conflicts > 0 || item.MissingCapaian > 0 || item.MissingReason > 0 || item.CompleteInput < item.Expected
		item.tahsinTotals = finalizeTahsinTotals(item.tahsinTotals)
		trendRows = append(trendRows, *item)
	}
	summary.tahsinTotals = finalizeTahsinTotals(summary.tahsinTotals)
	return tahsinReportResult{Rows: rows, Summary: summary, Groups: groups, Teachers: teachers, Trend: trendRows, Sessions: sessions, Filters: filters, SchemaReady: data.SchemaReady, TrackedFrom: data.TrackedFrom}
}

type groupAccumulator struct {
	tahsinTotals
	id, teacherID int64
	name, teacher string
	members       map[int64]bool
}

func ensureGroup(target map[int64]*groupAccumulator, id int64, name string, teacherID int64, teacher string) *groupAccumulator {
	if target[id] == nil {
		target[id] = &groupAccumulator{id: id, name: name, teacherID: teacherID, teacher: teacher, members: map[int64]bool{}}
	}
	return target[id]
}
func finalizeGroupAccumulators(source map[int64]*groupAccumulator) []tahsinReportGroup {
	ids := make([]int64, 0, len(source))
	for id := range source {
		ids = append(ids, id)
	}
	sort.Slice(ids, func(i, j int) bool {
		if source[ids[i]].name != source[ids[j]].name {
			return source[ids[i]].name < source[ids[j]].name
		}
		return ids[i] < ids[j]
	})
	result := make([]tahsinReportGroup, 0, len(ids))
	for _, id := range ids {
		item := source[id]
		result = append(result, tahsinReportGroup{tahsinTotals: finalizeTahsinTotals(item.tahsinTotals), ID: item.id, Name: item.name, TeacherID: item.teacherID, TeacherName: item.teacher, TotalMembers: int64(len(item.members))})
	}
	return result
}
func appendUniqueOwner(owners []tahsinOwner, owner tahsinOwner) []tahsinOwner {
	for _, item := range owners {
		if item.GroupID == owner.GroupID && item.TeacherID == owner.TeacherID {
			return owners
		}
	}
	return append(owners, owner)
}
func tahsinDeadlineString(session string) string {
	date, err := time.Parse("2006-01-02", session)
	if err != nil {
		return ""
	}
	return date.AddDate(0, 0, 1).Format("2006-01-02") + " 10:00:00"
}
func strconvBool(value bool) string {
	if value {
		return "1"
	}
	return "0"
}
func containsString(items []string, value string) bool {
	for _, item := range items {
		if item == value {
			return true
		}
	}
	return false
}
func resolvedFromReportCell(cell tahsinCell) tahsinResolvedCell {
	return tahsinResolvedCell{Records: cell.Records, Record: cell.Record, State: cell.State, Complete: cell.Complete, Missing: cell.MissingFields, Fingerprint: cell.Fingerprint, Stale: cell.VerificationStale}
}
func addTotals(target *tahsinTotals, source tahsinTotals) {
	target.Hadir += source.Hadir
	target.Sakit += source.Sakit
	target.Izin += source.Izin
	target.Alpha += source.Alpha
	target.Lulus += source.Lulus
	target.Ulang += source.Ulang
	target.TotalPresensi += source.TotalPresensi
	target.Expected += source.Expected
	target.CompleteInput += source.CompleteInput
	target.MissingPresensi += source.MissingPresensi
	target.MissingCapaian += source.MissingCapaian
	target.MissingReason += source.MissingReason
	target.Conflicts += source.Conflicts
	target.Unverified += source.Unverified
}
