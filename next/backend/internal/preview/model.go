package preview

import (
	"context"
	"strings"
)

type Guardian struct {
	SourceNamespace string `json:"source_namespace,omitempty"`
	SourceParentID  string `json:"source_parent_id,omitempty"`
	WaliID          int64  `json:"wali_santri_id"`
	Name            string `json:"source_name"`
	Type            string `json:"guardian_type"`
}
type Student struct {
	ID              int64      `json:"id"`
	SourceNamespace string     `json:"source_namespace,omitempty"`
	SourceStudentID string     `json:"source_student_id,omitempty"`
	LegacyStudentID *int64     `json:"legacy_student_id,omitempty"`
	NIS             string     `json:"nis"`
	Name            string     `json:"nama_anak"`
	Class           string     `json:"kelas"`
	Status          string     `json:"school_status"`
	SnapshotDate    string     `json:"snapshot_date,omitempty"`
	Guardians       []Guardian `json:"guardians"`
}
type Filters struct {
	Page, Limit   int
	Search, Scope string
}
type Page struct {
	Students []Student `json:"students"`
	Total    int       `json:"total"`
	Schema   string    `json:"schema"`
}
type Store interface {
	List(context.Context, Filters) (Page, error)
}

// Deliberately fictional; never populated from local family exports.
type DemoStore struct{}

func (DemoStore) List(_ context.Context, f Filters) (Page, error) {
	parents := []Guardian{{WaliID: 101, Name: "Bapak Contoh", Type: "father", SourceNamespace: "demo", SourceParentID: "101"}, {WaliID: 102, Name: "Ibu Contoh", Type: "mother", SourceNamespace: "demo", SourceParentID: "102"}}
	all := []Student{
		{ID: 1, SourceNamespace: "demo", SourceStudentID: "1", NIS: "000001", Name: "Anak Contoh Aktif", Class: "Mustawa 2 Ikhwan", Status: "active", Guardians: parents},
		{ID: 2, SourceNamespace: "demo", SourceStudentID: "2", NIS: "000002", Name: "Kakak Contoh Lulus", Status: "graduated", Guardians: parents},
		{ID: 3, SourceNamespace: "demo", SourceStudentID: "3", NIS: "000003", Name: "Anak Contoh Belum Terhubung", Class: "Mustawa 1 Akhwat", Status: "active", Guardians: []Guardian{}},
	}
	filtered := []Student{}
	for _, s := range all {
		if f.Scope == "active" && (s.Status != "active" || strings.TrimSpace(s.Class) == "") {
			continue
		}
		match := f.Search == "" || strings.Contains(strings.ToLower(s.Name+" "+s.NIS), strings.ToLower(f.Search))
		for _, g := range s.Guardians {
			match = match || strings.Contains(strings.ToLower(g.Name), strings.ToLower(f.Search))
		}
		if match {
			filtered = append(filtered, s)
		}
	}
	start := (f.Page - 1) * f.Limit
	end := min(start+f.Limit, len(filtered))
	start = min(start, len(filtered))
	return Page{Students: filtered[start:end], Total: len(filtered), Schema: "demo"}, nil
}
