# Hermes API — Presensi Tahsin Bapak

REST API untuk mengakses data **Presensi Tahsin Bapak** secara terprogram. Didesain untuk dikonsumsi oleh **Hermes Agent** (AI agent) maupun sistem eksternal lainnya.

---

## 📡 Endpoint

```
GET /api/hermes.php?action=<action>&<params>
```

## 🔐 Autentikasi

Semua request **wajib** menyertakan API key. Dua cara:

**Header:**
```
X-API-Key: <your-secret-key>
```

**Query Parameter:**
```
?api_key=<your-secret-key>
```

**Konfigurasi:** Set environment variable `HERMES_API_KEY` di Coolify / server.  
Fallback: `N8N_API_KEY` jika `HERMES_API_KEY` tidak diset.

---

## 📋 Daftar Action

### 1. Status & Info

```
GET /api/hermes.php?action=status
```

Mengembalikan info API, versi, ringkasan database, dan daftar endpoint.

---

### 2. Peserta (Wali Santri)

```
GET /api/hermes.php?action=peserta
GET /api/hermes.php?action=peserta&id=5
GET /api/hermes.php?action=peserta&search=ahmad&kategori=reguler&halaqoh_id=2&kelas=3A&page=1&limit=25
```

**Parameter:**
| Parameter | Deskripsi |
|-----------|-----------|
| `id` | ID spesifik peserta (detail lengkap + anak + riwayat) |
| `search` | Cari berdasarkan nama bapak atau no HP |
| `kategori` | Filter: `reguler`, `tahsin_luar`, `askar` |
| `halaqoh_id` | Filter berdasarkan halaqoh |
| `kelas` | Filter berdasarkan kelas anak |
| `status_aktif` | Filter: `1` (aktif) / `0` (non-aktif) |
| `page` | Halaman (default: 1) |
| `limit` | Per halaman (default: 25, max: 100) |

**Response (detail):**
```json
{
  "status": "ok",
  "data": {
    "id": 5,
    "nama_bapak": "Ahmad Fauzi",
    "no_hp": "08123456789",
    "alamat": "Perum Griya Asri",
    "kategori": "reguler",
    "status_aktif": 1,
    "nama_halaqoh": "Abu Bakar",
    "nama_ustadz": "Ust. Abdullah",
    "total_presensi": 12,
    "total_hadir": 10,
    "total_alpha": 1,
    "anak": [
      { "id": 1, "nama_anak": "Ali", "kelas": "3A" },
      { "id": 2, "nama_anak": "Fatimah", "kelas": "1B" }
    ],
    "riwayat_terbaru": [
      { "id": 45, "tanggal": "2026-06-21", "status": "H", "jenis_materi": "Al Quran", ... }
    ]
  }
}
```

---

### 3. Halaqoh

```
GET /api/hermes.php?action=halaqoh
GET /api/hermes.php?action=halaqoh&id=3
GET /api/hermes.php?action=halaqoh&ustadz_id=2
```

**Parameter:**
| Parameter | Deskripsi |
|-----------|-----------|
| `id` | Detail halaqoh (termasuk anggota + statistik) |
| `ustadz_id` | Filter berdasarkan ustadz |
| `page` / `limit` | Pagination |

---

### 4. Presensi (Kehadiran)

```
GET /api/hermes.php?action=presensi
GET /api/hermes.php?action=presensi&id=45
GET /api/hermes.php?action=presensi&wali_id=5
GET /api/hermes.php?action=presensi&halaqoh_id=2
GET /api/hermes.php?action=presensi&start=2026-01-01&end=2026-06-30&status=H
GET /api/hermes.php?action=presensi&search=ahmad
```

**Parameter:**
| Parameter | Deskripsi |
|-----------|-----------|
| `id` | Detail record presensi |
| `wali_id` / `wali_santri_id` | Filter berdasarkan wali santri |
| `halaqoh_id` | Filter berdasarkan halaqoh |
| `status` | Filter: `H`, `S`, `I`, `A` |
| `start` / `end` | Rentang tanggal (format: YYYY-MM-DD) |
| `search` | Cari nama bapak atau halaqoh |
| `page` / `limit` | Pagination |

---

### 5. Statistik

```
GET /api/hermes.php?action=stats
GET /api/hermes.php?action=stats&halaqoh_id=2
GET /api/hermes.php?action=stats&start=2026-01-01&end=2026-06-30
```

Mengembalikan ringkasan, per-halaqoh, tren harian, dan peringatan dini.

---

### 6. Progress Wali Santri

```
GET /api/hermes.php?action=progress
GET /api/hermes.php?action=progress&halaqoh_id=2&risk=rawan&search=ahmad
```

**Parameter:**
| Parameter | Deskripsi |
|-----------|-----------|
| `halaqoh_id` | Filter halaqoh |
| `search` | Cari nama |
| `risk` | Filter risiko: `rawan`, `pantau`, `stabil` |
| `start` / `end` | Rentang tanggal |
| `page` / `limit` | Pagination |

Setiap peserta memiliki `risk_level`: `rawan` (alpha >= 3 atau tidak hadir >= 21 hari), `pantau`, atau `stabil`.

---

### 7. Peringatan Dini

```
GET /api/hermes.php?action=peringatan
GET /api/hermes.php?action=peringatan&halaqoh_id=2
```

Peserta dengan **alpha >= 3 kali**. Butuh follow-up segera.

---

### 8. Ustadz

```
GET /api/hermes.php?action=ustadz
GET /api/hermes.php?action=ustadz&id=2
```

---

### 9. Hari Libur

```
GET /api/hermes.php?action=libur
GET /api/hermes.php?action=libur&start=2026-01-01&end=2026-12-31
```

---

### 10. Pengumuman

```
GET /api/hermes.php?action=pengumuman
GET /api/hermes.php?action=pengumuman&aktif_only=1
```

---

### 11. Capaian Peserta

```
GET /api/hermes.php?action=capaian
GET /api/hermes.php?action=capaian&halaqoh_id=2&search=ahmad
```

Capaian materi terakhir setiap peserta.

---

### 12. Pencarian Global

```
GET /api/hermes.php?action=search&q=ahmad
```

Mencari di semua tabel: wali santri, santri (anak), halaqoh, dan user.

---

### 13. Skema Database

```
GET /api/hermes.php?action=schema
```

Informasi struktur tabel, kolom, tipe data, dan jumlah baris.

---

### 14. Log Aktivitas

```
GET /api/hermes.php?action=logs
GET /api/hermes.php?action=logs&action=LOGIN&start=2026-01-01&end=2026-06-30
```

---

### 15. Users

```
GET /api/hermes.php?action=users
GET /api/hermes.php?action=users&role=ustadz
```

---

## 📦 Format Response

**Sukses:**
```json
{
  "status": "ok",
  "data": [ ... ],
  "meta": {
    "page": 1,
    "limit": 25,
    "total": 120,
    "total_pages": 5,
    "has_next": true,
    "has_prev": false
  }
}
```

**Error:**
```json
{
  "status": "error",
  "message": "Peserta tidak ditemukan."
}
```

**HTTP Status Codes:**
| Code | Arti |
|------|------|
| 200 | Sukses |
| 400 | Bad request (parameter salah) |
| 401 | Unauthorized (API key salah) |
| 404 | Data tidak ditemukan |
| 405 | Method tidak diizinkan |
| 500 | Server error |

---

## 🚀 Contoh Penggunaan

### cURL
```bash
# Status
curl -H "X-API-Key: rahasia123" "https://tahsin.domain.com/api/hermes.php?action=status"

# Peserta dengan filter
curl "https://tahsin.domain.com/api/hermes.php?action=peserta&halaqoh_id=2&page=1&limit=10&api_key=rahasia123"

# Presensi 30 hari terakhir
curl -H "X-API-Key: rahasia123" "https://tahsin.domain.com/api/hermes.php?action=presensi&start=2026-05-24&end=2026-06-24"

# Pencarian
curl "https://tahsin.domain.com/api/hermes.php?action=search&q=ahmad&api_key=rahasia123"
```

### Python (Hermes Agent)
```python
import requests

API_URL = "https://tahsin.domain.com/api/hermes.php"
API_KEY = "rahasia123"

headers = {"X-API-Key": API_KEY}

# Ambil semua peserta aktif
resp = requests.get(API_URL, params={"action": "peserta", "status_aktif": 1, "limit": 100}, headers=headers)
data = resp.json()
print(f"Total peserta: {data['meta']['total']}")

# Cek peringatan dini
resp = requests.get(API_URL, params={"action": "peringatan"}, headers=headers)
warnings = resp.json()["data"]
for w in warnings:
    print(f"⚠️ {w['nama_bapak']} - Alpha {w['total_alpha']}x di {w['nama_halaqoh']}")
```

### JavaScript / Node.js
```javascript
const API_URL = "https://tahsin.domain.com/api/hermes.php";
const API_KEY = "rahasia123";

async function getData(action, params = {}) {
  const url = new URL(API_URL);
  url.searchParams.set("action", action);
  Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));

  const res = await fetch(url, { headers: { "X-API-Key": API_KEY } });
  return res.json();
}

// Contoh: ambil statistik
const stats = await getData("stats", { start: "2026-01-01", end: "2026-06-24" });
console.log(stats.data.ringkasan);
```

---

## 🔧 Konfigurasi Deployment

Tambahkan environment variable di Coolify / server:

```
HERMES_API_KEY=buat_string_acak_yang_panjang_dan_aman
```

Atau gunakan `N8N_API_KEY` yang sudah ada sebagai fallback.

---

## 📝 Catatan

- Semua response dalam **UTF-8 JSON** dengan `JSON_UNESCAPED_UNICODE`
- Mendukung **CORS** (Access-Control-Allow-Origin: *)
- Method: **GET** (dan POST untuk kompatibilitas)
- Rate limiting: belum diimplementasikan (gunakan dengan bijak)
- Password user **tidak pernah** diekspos di response
