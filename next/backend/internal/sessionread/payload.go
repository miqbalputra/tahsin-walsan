// Package sessionread shares the PHP autosave contract between the read-only
// session reader and the separately configured synthetic staging writer.
package sessionread

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"regexp"
	"strconv"
	"strings"
	"unicode/utf8"
)

// PHP hashes insertion-ordered objects, with Unicode and slashes unescaped.
type pair struct {
	key   string
	value any
}
type ordered []pair

func (o ordered) MarshalJSON() ([]byte, error) {
	var b bytes.Buffer
	b.WriteByte('{')
	for i, p := range o {
		if i > 0 {
			b.WriteByte(',')
		}
		k, e := phpJSON(p.key)
		if e != nil {
			return nil, e
		}
		v, e := phpJSON(p.value)
		if e != nil {
			return nil, e
		}
		b.Write(k)
		b.WriteByte(':')
		b.Write(v)
	}
	b.WriteByte('}')
	return b.Bytes(), nil
}
func phpJSON(v any) ([]byte, error) {
	var b bytes.Buffer
	e := json.NewEncoder(&b)
	e.SetEscapeHTML(false)
	if err := e.Encode(v); err != nil {
		return nil, err
	}
	return bytes.TrimSuffix(b.Bytes(), []byte{'\n'}), nil
}
func hash(v any) string {
	b, e := phpJSON(v)
	if e != nil {
		panic(e)
	}
	sum := sha256.Sum256(b)
	return hex.EncodeToString(sum[:])
}

// HashPHPAutosaveRequest preserves JSON object insertion order and excludes
// csrf_token exactly as the legacy PHP endpoint does. This lets operation IDs
// already queued by the existing IndexedDB client replay against Go without
// creating a second receipt or attendance row.
func HashPHPAutosaveRequest(group int64, date string, body []byte) (string, error) {
	d := json.NewDecoder(bytes.NewReader(body))
	d.UseNumber()
	value, err := decodeOrderedJSON(d)
	if err != nil {
		return "", err
	}
	if _, err = d.Token(); err != io.EOF {
		return "", fmt.Errorf("trailing JSON content")
	}
	obj, ok := value.(ordered)
	if !ok {
		return "", fmt.Errorf("autosave request must be a JSON object")
	}
	filtered := make(ordered, 0, len(obj))
	seen := map[string]bool{}
	for _, field := range obj {
		if seen[field.key] {
			return "", fmt.Errorf("duplicate JSON field")
		}
		seen[field.key] = true
		if field.key != "csrf_token" {
			filtered = append(filtered, field)
		}
	}
	return hash([]any{group, date, filtered}), nil
}

func decodeOrderedJSON(d *json.Decoder) (any, error) {
	token, err := d.Token()
	if err != nil {
		return nil, err
	}
	switch value := token.(type) {
	case json.Delim:
		switch value {
		case '{':
			result := ordered{}
			for d.More() {
				keyToken, e := d.Token()
				if e != nil {
					return nil, e
				}
				key, ok := keyToken.(string)
				if !ok {
					return nil, fmt.Errorf("invalid JSON object key")
				}
				child, e := decodeOrderedJSON(d)
				if e != nil {
					return nil, e
				}
				result = append(result, pair{key, child})
			}
			_, err = d.Token()
			return result, err
		case '[':
			result := []any{}
			for d.More() {
				child, e := decodeOrderedJSON(d)
				if e != nil {
					return nil, e
				}
				result = append(result, child)
			}
			_, err = d.Token()
			return result, err
		default:
			return nil, fmt.Errorf("unexpected JSON delimiter")
		}
	default:
		return value, nil
	}
}
func text(v any) string {
	if v == nil {
		return ""
	}
	return fmt.Sprint(v)
}
func number(v any) int64   { n, _ := strconv.ParseInt(text(v), 10, 64); return n }
func trim(v string) string { return strings.Trim(v, " \t\n\r\x00\v") }

type row map[string]any

type Payload struct {
	Status    string `json:"status"`
	Reason    string `json:"alasan"`
	Material  string `json:"jenis_materi"`
	Level     string `json:"jilid"`
	SurahFrom string `json:"nama_surat_dari"`
	SurahTo   string `json:"nama_surat_sampai"`
	PageFrom  string `json:"halaman_dari"`
	PageTo    string `json:"halaman_sampai"`
	Result    string `json:"hasil_talaqqi"`
}

func fromRecord(r row) Payload {
	p := Payload{Status: text(r["status"]), Reason: text(r["alasan"]), Material: text(r["jenis_materi"]), Level: text(r["jilid"]), Result: text(r["hasil_talaqqi"])}
	parts := strings.SplitN(text(r["nama_surat"]), " s/d ", 2)
	p.SurahFrom = trim(parts[0])
	p.SurahTo = p.SurahFrom
	if len(parts) > 1 {
		p.SurahTo = trim(parts[1])
	}
	parts = strings.SplitN(text(r["halaman"]), "-", 2)
	p.PageFrom = trim(parts[0])
	p.PageTo = p.PageFrom
	if len(parts) > 1 {
		p.PageTo = trim(parts[1])
	}
	return p
}
func normalized(r row) ordered {
	status := text(r["status"])
	reason := ""
	if status == "S" || status == "I" {
		reason = trim(text(r["alasan"]))
	}
	p := ordered{{"status", status}, {"alasan", reason}}
	for _, k := range []string{"jenis_materi", "jilid", "nama_surat", "halaman", "hasil_talaqqi"} {
		v := ""
		if status == "H" {
			v = trim(text(r[k]))
		}
		p = append(p, pair{k, v})
	}
	if p[2].value == "Iqro" {
		p[4].value = ""
		p[3].value = strconv.FormatInt(number(p[3].value), 10)
	} else if p[2].value == "Al Quran" {
		p[3].value = ""
	}
	return p
}
func recordComplete(r row) bool {
	p := normalized(r)
	s := p[0].value
	if s == "S" || s == "I" {
		return p[1].value != ""
	}
	if s == "A" {
		return true
	}
	if s != "H" {
		return false
	}
	m := p[2].value
	if m != "Iqro" && m != "Al Quran" {
		return false
	}
	if m == "Iqro" && (number(p[3].value) < 1 || number(p[3].value) > 6) {
		return false
	}
	if m == "Al Quran" && p[4].value == "" {
		return false
	}
	return p[5].value != "" && (p[6].value == "Lulus" || p[6].value == "Ulang")
}

var pagePattern = regexp.MustCompile(`^[1-9][0-9]{0,3}$`)

func missing(p Payload) []string {
	m := []string{}
	if p.Status == "" {
		return []string{"status kehadiran"}
	}
	if p.Status == "S" || p.Status == "I" {
		if trim(p.Reason) == "" {
			m = append(m, "alasan/keterangan")
		}
		return m
	}
	if p.Status != "H" {
		return m
	}
	if p.Material != "Iqro" && p.Material != "Al Quran" {
		m = append(m, "jenis materi")
	}
	if p.Material == "Iqro" && (len(p.Level) != 1 || p.Level < "1" || p.Level > "6") {
		m = append(m, "jilid 1–6")
	}
	if !pagePattern.MatchString(p.PageFrom) {
		m = append(m, "halaman dari")
	}
	if !pagePattern.MatchString(p.PageTo) {
		m = append(m, "halaman sampai")
	}
	if number(p.PageFrom) > number(p.PageTo) && (p.Material == "Iqro" || p.SurahFrom == p.SurahTo) {
		m = append(m, "rentang bacaan berurutan")
	}
	if p.Material == "Al Quran" {
		if trim(p.SurahFrom) == "" {
			m = append(m, "nama surat dari")
		}
		if trim(p.SurahTo) == "" {
			m = append(m, "nama surat sampai")
		}
		combined := trim(p.SurahFrom)
		if combined != "" && trim(p.SurahTo) != "" && trim(p.SurahTo) != combined {
			combined += " s/d " + trim(p.SurahTo)
		}
		if utf8.RuneCountInString(combined) > 100 {
			m = append(m, "nama surat lebih singkat")
		}
	}
	if p.Result != "Lulus" && p.Result != "Ulang" {
		m = append(m, "hasil Lulus/Ulang")
	}
	return m
}
func fingerprint(records []row) string {
	p := make([]ordered, 0, len(records))
	for _, r := range records {
		p = append(p, ordered{{"id", number(r["id"])}, {"wali", number(r["wali_santri_id"])}, {"halaqoh", r["halaqoh_id"]}, {"tanggal", r["tanggal"]}, {"payload", normalized(r)}, {"created_at", r["created_at"]}})
	}
	return hash(p)
}
func resolve(records []row, verification row, suspects map[int64]bool, holidays map[string]bool) (row, bool, bool) {
	if len(records) == 0 {
		return nil, false, false
	}
	eligible := []row{}
	for _, r := range records {
		if !holidays[text(r["tanggal"])] {
			eligible = append(eligible, r)
		}
	}
	stale := verification != nil
	if verification != nil && text(verification["fingerprint"]) == fingerprint(records) {
		if number(verification["selected_presensi_id"]) == 0 {
			return nil, false, true
		}
		for _, r := range records {
			if number(r["id"]) == number(verification["selected_presensi_id"]) {
				if holidays[text(r["tanggal"])] {
					return nil, false, true
				}
				return r, recordComplete(r), false
			}
		}
	}
	if len(eligible) == 0 {
		return nil, false, true
	}
	different := map[string]bool{}
	suspect := false
	var selected row
	for _, r := range eligible {
		b, _ := phpJSON(normalized(r))
		different[string(b)] = true
		suspect = suspect || suspects[number(r["id"])]
		if selected == nil || text(r["tanggal"]) > text(selected["tanggal"]) || (r["tanggal"] == selected["tanggal"] && number(r["id"]) > number(selected["id"])) {
			selected = r
		}
	}
	if len(different) > 1 || suspect || stale {
		return nil, false, true
	}
	return selected, recordComplete(selected), false
}
