# 📖 Presensi Tahsin Bapak — Griya Qur'an

Sistem pencatatan kehadiran dan perkembangan materi **Tahsin Al-Qur'an** untuk para **Bapak/Wali Santri** di **Griya Qur'an**. Dibangun dengan PHP 8.2 + MySQL, Tailwind CSS, Alpine.js, dan PWA support.

---

## 📋 Daftar Isi

- [Tentang Aplikasi](#tentang-aplikasi)
- [Fitur Utama](#fitur-utama)
- [Tech Stack](#tech-stack)
- [Cara Install & Deploy](#cara-install--deploy)
- [Role Pengguna](#role-pengguna)
- [Integrasi dengan Hermes Agent](#integrasi-dengan-hermes-agent)
  - [Apa itu Hermes API?](#apa-itu-hermes-api)
  - [Autentikasi](#autentikasi)
  - [Daftar Endpoint](#daftar-endpoint)
  - [Cara Panggil dari Hermes Agent](#cara-panggil-dari-hermes-agent)
  - [Contoh Skenario](#contoh-skenario)
  - [Contoh Kode](#contoh-kode)
- [API Documentation](#api-documentation)
- [Struktur Database](#struktur-database)
- [Deployment](#deployment)

---

## 🎯 Tentang Aplikasi

Aplikasi ini digunakan oleh **Ustadz** untuk mencatat kehadiran dan perkembangan bacaan Al-Qur'an para wali santri setiap pekan (hari Ahad). Setiap sesi mencatat:

- **Status kehadiran**: Hadir (H), Sakit (S), Izin (I), Alpha (A)
- **Materi yang dipelajari**: Iqro (Jilid 1-6) atau Al-Qur'an (Surat & Ayat)
- **Hasil talaqqi**: Lulus atau Ulang

Admin/PJ Tahfidz dapat memantau progress, mencetak rapor, dan mendapatkan peringatan dini jika ada peserta dengan alpha tinggi.

---

## ✨ Fitur Utama

| Fitur | Deskripsi |
|-------|-----------|
| 📝 **Input Presensi** | Form interaktif dengan auto-save per peserta |
| 📊 **Dashboard Admin** | Statistik, grafik tren, peringatan dini |
| 👥 **Manajemen Peserta** | CRUD wali santri + data anak + import CSV |
| 🏫 **Manajemen Halaqoh** | Kelompok belajar dengan ustadz pembimbing |
| 📈 **Progress Wali** | Pantau kehadiran & capaian per peserta |
| ⚠️ **Peringatan Dini** | Deteksi otomatis alpha >= 3x |
| 📄 **Cetak Rapor** | Template rapor kustom dari database |
| 📅 **Manajemen Libur** | Cegah input Alfa di hari libur |
| 📢 **Broadcast** | Kirim pengumuman ke dashboard ustadz |
| 📱 **PWA** | Install ke perangkat sebagai aplikasi |
| 🤖 **Hermes API** | REST API untuk AI agent & sistem eksternal |

---

## 🛠 Tech Stack

| Komponen | Teknologi |
|----------|-----------|
| **Backend** | PHP 8.2 (Native, PDO) |
| **Database** | MySQL / MariaDB |
| **Frontend** | Tailwind CSS 3, Alpine.js 3, Chart.js 4 |
| **PWA** | Service Worker + Web Manifest |
| **Container** | Docker (php:8.2-apache) |
| **Otomasi** | n8n workflows |
| **Deploy** | Coolify |

---

## 🚀 Cara Install & Deploy

### Prasyarat

- PHP 8.2+
- MySQL 8.0+
- Composer / npm
- Docker (opsional)

### Instalasi Manual

```bash
# 1. Clone repo
git clone https://github.com/miqbalputra/tahsin-walsan.git
cd tahsin-walsan

# 2. Copy environment
cp .env.example .env
# Edit .env sesuai database Anda

# 3. Build frontend assets
npm install
npm run build

# 4. Import database
mysql -u root -p < database.sql

# 5. Jalankan
php -S localhost:8000
```

### Docker / Coolify

```bash
# Build & run
docker build -t tahsin-walsan .
docker run -p 80:80 tahsin-walsan
```

Lihat [COOLIFY_DEPLOY.md](COOLIFY_DEPLOY.md) untuk panduan deploy ke Coolify.

---

## 👥 Role Pengguna

| Role | Akses |
|------|-------|
| **admin** | Full akses semua fitur |
| **pj_tahfidz** | Sama seperti admin |
| **kepsek** | View-only: Dashboard, Progress, Rekap, Laporan |
| **ustadz** | Input presensi halaqoh sendiri, lihat riwayat & capaian |

---

## 🤖 Integrasi dengan Hermes Agent

### Apa itu Hermes API?

**Hermes API** adalah REST API khusus yang memungkinkan **AI Agent** (seperti Hermes, Claude, GPT, atau sistem lain) untuk mengakses data Presensi Tahsin secara terprogram. API ini menyediakan **15 endpoint** yang mencakup seluruh data aplikasi.

Dengan API ini, Hermes Agent bisa:

- ✅ **Bertanya** — "Siapa saja peserta yang alpha minggu ini?"
- ✅ **Menganalisis** — "Halaqoh mana yang paling tinggi kehadirannya?"
- ✅ **Memantau** — "Apakah ada peserta yang perlu difollow-up?"
- ✅ **Mencari** — "Cari data Pak Ahmad"
- ✅ **Membuat Laporan** — "Buat ringkasan presensi bulan ini"

### Autentikasi

Semua request **wajib** menyertakan API key. Dua cara:

**Via Header (recommended):**
```
X-API-Key: <your-secret-key>
```

**Via Query Parameter:**
```
?api_key=<your-secret-key>
```

**Setup API Key:**
Set environment variable di server/Coolify:
```
HERMES_API_KEY=buat_string_acak_yang_panjang_dan_aman
```

> **Fallback:** Jika `HERMES_API_KEY` tidak diset, API akan menggunakan `N8N_API_KEY`.

### Daftar Endpoint

| Action | Method | Deskripsi |
|--------|--------|-----------|
| `status` | GET | Info API & ringkasan database |
| `peserta` | GET | Data wali santri (filter: search, kategori, halaqoh, kelas) |
| `halaqoh` | GET | Data halaqoh + anggota + statistik |
| `presensi` | GET | Data kehadiran (filter: tanggal, status, wali, halaqoh) |
| `stats` | GET | Statistik dashboard (ringkasan, per-halaqoh, tren) |
| `progress` | GET | Progress per wali santri dengan risk level |
| `peringatan` | GET | Peringatan dini alpha >= 3 |
| `ustadz` | GET | Daftar ustadz + halaqoh yang diampu |
| `libur` | GET | Hari libur kajian |
| `pengumuman` | GET | Broadcast pengumuman |
| `capaian` | GET | Capaian materi terakhir per peserta |
| `search` | GET | Pencarian global (wali, santri, halaqoh, user) |
| `schema` | GET | Skema database lengkap |
| `logs` | GET | Log aktivitas |
| `users` | GET | Daftar user |

### Cara Panggil dari Hermes Agent

**Base URL:**
```
https://domain-anda.com/api/hermes.php
```

**Contoh Request:**
```bash
curl -H "X-API-Key: rahasia123" \
  "https://tahsin.domain.com/api/hermes.php?action=status"
```

**Response:**
```json
{
  "status": "ok",
  "data": {
    "api_name": "Hermes API — Presensi Tahsin Bapak",
    "api_version": "1.0.0",
    "database": {
      "total_peserta": 120,
      "total_halaqoh": 8,
      "total_ustadz": 8,
      "total_presensi": 2450,
      "distribusi_status": [
        {"status": "H", "total": 1800},
        {"status": "A", "total": 350},
        {"status": "S", "total": 200},
        {"status": "I", "total": 100}
      ]
    }
  }
}
```

### Contoh Skenario

Berikut skenario tipikal yang bisa dijalankan Hermes Agent:

#### 🎯 **Skenario 1: Cek Kesehatan Data**

Hermes Agent ingin tahu kondisi terkini:

```
Q: "Cek status database Presensi Tahsin"
→ GET /api/hermes.php?action=status
→ Agent dapat info: total peserta, halaqoh, presensi, distribusi status
```

#### 🎯 **Skenario 2: Cari Peserta Bermasalah**

```
Q: "Siapa saja yang perlu difollow-up karena alpha?"
→ GET /api/hermes.php?action=peringatan
→ Agent dapat daftar peserta dengan alpha >= 3x
→ Agent bisa rekomendasikan tindakan
```

#### 🎯 **Skenario 3: Analisis Progress Halaqoh**

```
Q: "Bandingkan performa semua halaqoh bulan ini"
→ GET /api/hermes.php?action=stats&start=2026-06-01&end=2026-06-30
→ Agent dapat data per-halaqoh: total input, hadir, alpha
→ Agent bisa urutkan dari yang terbaik ke terendah
```

#### 🎯 **Skenario 4: Cari Data Spesifik**

```
Q: "Cari data Pak Ahmad di halaqoh Abu Bakar"
→ GET /api/hermes.php?action=search&q=ahmad
→ Agent dapat hasil dari semua tabel
→ Agent bisa lanjut detail: GET /api/hermes.php?action=peserta&id=5
```

#### 🎯 **Skenario 5: Laporan Bulanan Otomatis**

```
Q: "Buat ringkasan presensi bulan Juni 2026"
→ GET /api/hermes.php?action=stats&start=2026-06-01&end=2026-06-30
→ Agent dapat: total hadir, alpha, tren, peringatan
→ Agent bisa generate narasi laporan
```

### Contoh Kode

#### Python (Hermes Agent / LangChain)

```python
import requests
import json

class TahsinAPI:
    """Client untuk Hermes API Presensi Tahsin"""
    
    def __init__(self, base_url, api_key):
        self.base_url = base_url.rstrip('/')
        self.headers = {"X-API-Key": api_key}
    
    def _get(self, action, params=None):
        url = f"{self.base_url}/api/hermes.php"
        query = {"action": action}
        if params:
            query.update(params)
        resp = requests.get(url, params=query, headers=self.headers)
        resp.raise_for_status()
        return resp.json()
    
    def status(self):
        """Info API & ringkasan database"""
        return self._get("status")
    
    def peserta(self, search=None, halaqoh_id=None, page=1, limit=25):
        """Cari wali santri"""
        params = {"page": page, "limit": limit}
        if search: params["search"] = search
        if halaqoh_id: params["halaqoh_id"] = halaqoh_id
        return self._get("peserta", params)
    
    def presensi(self, start=None, end=None, halaqoh_id=None, status=None, page=1, limit=50):
        """Data kehadiran"""
        params = {"page": page, "limit": limit}
        if start: params["start"] = start
        if end: params["end"] = end
        if halaqoh_id: params["halaqoh_id"] = halaqoh_id
        if status: params["status"] = status
        return self._get("presensi", params)
    
    def stats(self, start=None, end=None, halaqoh_id=None):
        """Statistik dashboard"""
        params = {}
        if start: params["start"] = start
        if end: params["end"] = end
        if halaqoh_id: params["halaqoh_id"] = halaqoh_id
        return self._get("stats", params)
    
    def peringatan(self, halaqoh_id=None):
        """Peringatan dini alpha >= 3"""
        params = {}
        if halaqoh_id: params["halaqoh_id"] = halaqoh_id
        return self._get("peringatan", params)
    
    def progress(self, halaqoh_id=None, risk=None, search=None, page=1, limit=25):
        """Progress per wali santri"""
        params = {"page": page, "limit": limit}
        if halaqoh_id: params["halaqoh_id"] = halaqoh_id
        if risk: params["risk"] = risk
        if search: params["search"] = search
        return self._get("progress", params)
    
    def search(self, query, page=1, limit=25):
        """Pencarian global"""
        return self._get("search", {"q": query, "page": page, "limit": limit})


# ============================================
# CONTOH PENGGUNAAN
# ============================================

# Inisialisasi client
tahsin = TahsinAPI(
    base_url="https://tahsin.domain.com",
    api_key="rahasia123"
)

# 1. Cek status
status = tahsin.status()
print(f"✅ Total peserta: {status['data']['database']['total_peserta']}")
print(f"✅ Total presensi: {status['data']['database']['total_presensi']}")

# 2. Cek peringatan dini
warnings = tahsin.peringatan()
if warnings['data']['total_peringatan'] > 0:
    print(f"⚠️ {warnings['data']['total_peringatan']} peserta perlu follow-up!")
    for w in warnings['data']['data']:
        print(f"   - {w['nama_bapak']} ({w['total_alpha']}x alpha) di {w['nama_halaqoh']}")

# 3. Analisis statistik bulan ini
stats = tahsin.stats(start="2026-06-01", end="2026-06-30")
ringkasan = stats['data']['ringkasan']
print(f"\n📊 Statistik Juni 2026:")
print(f"   Hadir: {ringkasan['hadir']}")
print(f"   Alpha: {ringkasan['alpha']}")
print(f"   Lulus: {ringkasan['lulus']}")
print(f"   Ulang: {ringkasan['ulang']}")

# 4. Cari peserta
hasil = tahsin.search("ahmad")
print(f"\n🔍 Hasil pencarian 'ahmad': {hasil['data']['total']} ditemukan")
for r in hasil['data']['results']:
    print(f"   - {r['nama']} ({r['tipe']})")

# 5. Progress halaqoh tertentu
progress = tahsin.progress(halaqoh_id=2, risk="rawan")
print(f"\n📈 Peserta butuh follow-up di halaqoh #2: {len(progress['data'])} orang")
```

#### JavaScript / Node.js

```javascript
class TahsinAPI {
  constructor(baseUrl, apiKey) {
    this.baseUrl = baseUrl.replace(/\/$/, '');
    this.apiKey = apiKey;
  }

  async request(action, params = {}) {
    const url = new URL(`${this.baseUrl}/api/hermes.php`);
    url.searchParams.set('action', action);
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') {
        url.searchParams.set(k, v);
      }
    });

    const res = await fetch(url, {
      headers: { 'X-API-Key': this.apiKey }
    });
    return res.json();
  }

  status()           { return this.request('status'); }
  peserta(opts = {}) { return this.request('peserta', opts); }
  presensi(opts = {}){ return this.request('presensi', opts); }
  stats(opts = {})   { return this.request('stats', opts); }
  peringatan(opts)   { return this.request('peringatan', opts); }
  search(q)          { return this.request('search', { q }); }
}

// Contoh
const api = new TahsinAPI('https://tahsin.domain.com', 'rahasia123');
const data = await api.stats({ start: '2026-06-01', end: '2026-06-30' });
console.log(data);
```

#### cURL (testing cepat)

```bash
# 1. Status
curl -s -H "X-API-Key: rahasia123" \
  "https://tahsin.domain.com/api/hermes.php?action=status" | jq .

# 2. Cari peserta
curl -s "https://tahsin.domain.com/api/hermes.php?action=peserta&search=ahmad&api_key=rahasia123" | jq .

# 3. Peringatan dini
curl -s -H "X-API-Key: rahasia123" \
  "https://tahsin.domain.com/api/hermes.php?action=peringatan" | jq .

# 4. Statistik bulan ini
curl -s -H "X-API-Key: rahasia123" \
  "https://tahsin.domain.com/api/hermes.php?action=stats&start=2026-06-01&end=2026-06-30" | jq .

# 5. Semua data presensi dengan pagination
curl -s -H "X-API-Key: rahasia123" \
  "https://tahsin.domain.com/api/hermes.php?action=presensi&limit=5&page=1" | jq .
```

#### Hermes Agent Prompt Template

Template prompt untuk Hermes Agent agar bisa menggunakan API ini:

```
Kamu adalah asisten yang membantu mengelola data Presensi Tahsin Bapak.
Kamu memiliki akses ke REST API di: https://tahsin.domain.com/api/hermes.php
API Key: rahasia123

Gunakan API ini untuk menjawab pertanyaan tentang:
- Data peserta, halaqoh, ustadz
- Kehadiran dan alpha
- Statistik dan progress
- Peringatan dini

Format panggilan API:
GET /api/hermes.php?action=<action>&<params>
Header: X-API-Key: rahasia123

Contoh:
- Cek status: action=status
- Cari peserta: action=peserta&search=ahmad
- Cek peringatan: action=peringatan
- Statistik: action=stats&start=2026-01-01&end=2026-06-30
```

---

## 📖 API Documentation

Dokumentasi lengkap API tersedia di:

➡️ **[api/HERMES_API.md](api/HERMES_API.md)**

Berisi detail setiap endpoint, parameter, contoh request/response, dan panduan troubleshooting.

---

## 🗄 Struktur Database

| Tabel | Deskripsi |
|-------|-----------|
| `users` | Akun pengguna (admin, pj_tahfidz, kepsek, ustadz) |
| `wali_santri` | Data wali santri (nama, no HP, alamat, kategori) |
| `santri_detail` | Data anak-anak wali santri |
| `halaqoh` | Kelompok belajar |
| `halaqoh_members` | Mapping wali santri ke halaqoh |
| `presensi` | Data kehadiran & capaian materi |
| `login_attempts` | Log percobaan login (rate limiting) |
| `activity_logs` | Log aktivitas pengguna |
| `holidays` | Hari libur kajian |
| `pengumuman` | Broadcast pengumuman |
| `settings` | Pengaturan (template rapor, dll) |

---

## 🐳 Deployment

| Metode | File Panduan |
|--------|-------------|
| **Coolify** | [COOLIFY_DEPLOY.md](COOLIFY_DEPLOY.md) |
| **cPanel** | [DEPLOYMENT_CPANEL.md](DEPLOYMENT_CPANEL.md) |
| **Info Deploy** | [DEPLOYMENT_INFO.md](DEPLOYMENT_INFO.md) |
| **Docker** | [Dockerfile](Dockerfile) |

---

## 📄 Lisensi

Hak cipta © 2026 Griya Qur'an — Developed by [SistemFlow](https://sistemflow.com)
