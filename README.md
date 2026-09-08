# 💊 Apotek Sehat — Aplikasi Manajemen Apotek

Aplikasi web manajemen apotek dengan **PHP murni (vanilla)**, **MySQLi**, dan **Bootstrap 5.3**.
Dashboard yang bersih, profesional, dan mudah dipahami dengan tema *Clinical Teal*.

---

## ✨ Fitur

- **Dashboard** — ringkasan omzet harian & bulanan, jumlah jenis obat, grafik tren penjualan 7 hari (Chart.js), obat terlaris, stok menipis, dan transaksi terbaru.
- **Kasir (POS)** — antarmuka penjualan cepat: grid produk, keranjang, hitung kembalian otomatis, nomor transaksi otomatis, cetak struk, dan **kirim struk via WhatsApp** (nomor pelanggan disimpan di database, atau diketik saat akan kirim).
- **Profil Apotek** — atur nama, alamat, telepon, **nomor WA apotek**, dan catatan kaki struk (admin). Dipakai di header struk.
- **Pembelian Obat (gaya POS)** — pilih obat dari grid; saat diklik muncul popup untuk isi *harga beli, jumlah, **diskon per item** (default 0%), dan tanggal kadaluarsa*. Dilengkapi supplier, no. faktur, tanggal jatuh tempo, dan **PPN diketik manual** (default 11%). Subtotal, PPN, dan total dihitung otomatis. Stok & harga beli otomatis ter-update; hapus pembelian menyesuaikan stok kembali.
- **Harga Jual & Tarif** — master **tarif** berlapis (mis. Eceran, Langsung, Grosir), masing-masing punya margin sendiri. Harga jual bisa dihitung otomatis dari **(harga beli + PPN) + margin%**, atau diisi **tetap (manual)**. Setting **PPN** global, dengan tombol hitung ulang massal.
- **Data Obat** — CRUD lengkap, filter kategori, indikator status stok & kedaluwarsa.
- **Kategori & Supplier** — CRUD dengan modal & pencarian.
- **Riwayat Transaksi** — filter tanggal, ringkasan periode, detail transaksi, hapus (mengembalikan stok).
- **Laporan** — rentang tanggal, grafik omzet harian, rekap & obat terlaris, siap cetak/PDF.
- **Manajemen Pengguna** — khusus admin, dengan peran: admin, apoteker, kasir.
- **Keamanan** — `password_hash`/`password_verify`, *prepared statements*, transaksi DB dengan validasi stok (`FOR UPDATE`) & *rollback*.

---

## 🛠️ Kebutuhan

- PHP 7.4+ (disarankan 8.x) dengan ekstensi **mysqli**
- MySQL / MariaDB
- Server lokal: **XAMPP**, **Laragon**, atau sejenisnya

---

## 🚀 Cara Instalasi

1. **Salin folder** `apotek` ke direktori web server:
   - XAMPP → `C:\xampp\htdocs\apotek`
   - Laragon → `C:\laragon\www\apotek`

2. **Buat & impor database**
   - Buka **phpMyAdmin** (`http://localhost/phpmyadmin`)
   - Impor file `database.sql` — database `apotek_sehat` akan dibuat otomatis beserta data contoh.
   - *(Alternatif via terminal:)*
     ```bash
     mysql -u root -p < database.sql
     ```
   - **Sudah punya database lama (tanpa fitur pembelian)?** Jalankan migrasi tambahan:
     ```bash
     mysql -u root -p apotek_sehat < migrations/pembelian.sql
     mysql -u root -p apotek_sehat < migrations/tarif_harga.sql
     mysql -u root -p apotek_sehat < migrations/diskon_ppn.sql
     mysql -u root -p apotek_sehat < migrations/wa_struk.sql
     ```
     (aman dijalankan berulang — memakai `IF NOT EXISTS` & seed idempotent).

3. **Atur koneksi lewat `.env`** (bukan lagi di file PHP):
   - Salin `.env.example` menjadi `.env`
     ```bash
     cp .env.example .env
     ```
   - Edit `.env` sesuai server Anda:
     ```dotenv
     DB_HOST=localhost
     DB_USER=root
     DB_PASS=          # isi password MySQL Anda
     DB_NAME=apotek_sehat
     APP_ENV=production
     APP_DEBUG=false   # set true hanya saat development
     ```

4. **Jalankan** di browser:
   ```
   http://localhost/apotek/
   ```

---

## 🔑 Akun Demo

Semua akun memakai password: **`admin123`**

| Username   | Peran     | Akses                          |
|------------|-----------|--------------------------------|
| `admin`    | Admin     | Semua menu + manajemen pengguna |
| `apoteker` | Apoteker  | Operasional (tanpa pengguna)    |
| `kasir`    | Kasir     | Operasional (tanpa pengguna)    |

> ⚠️ **Ganti password default** sebelum dipakai di lingkungan nyata.

---

## 📁 Struktur Folder

```
apotek/
├── assets/
│   ├── css/style.css      # Tema kustom "Clinical Teal"
│   └── js/app.js          # Sidebar, konfirmasi hapus, pencarian tabel
├── config/
│   ├── config.php         # Konstanta, sesi, fungsi bantu (rupiah, tgl, dll)
│   └── database.php       # Koneksi MySQLi
├── includes/
│   ├── header.php         # Topbar + <head> (Bootstrap, ikon, font)
│   ├── sidebar.php        # Navigasi samping
│   └── footer.php         # Penutup layout + JS
├── database.sql           # Skema + data contoh
├── migrations/
│   ├── pembelian.sql      # Migrasi tabel pembelian (untuk DB lama)
│   └── tarif_harga.sql    # Migrasi tarif, harga jual, PPN, jatuh tempo
├── index.php              # Dashboard
├── login.php / logout.php # Autentikasi
├── penjualan.php          # Kasir (POS)
├── pembelian.php          # Pembelian obat (POS + popup harga/ED)
├── harga_jual.php         # Set harga jual per tarif
├── tarif.php              # Master tarif & PPN
├── transaksi.php          # Riwayat transaksi
├── obat.php               # Data obat
├── kategori.php           # Kategori
├── supplier.php           # Supplier
├── laporan.php            # Laporan
└── user.php               # Manajemen pengguna (admin)
```

---

## 🎨 Catatan Desain

- Palet **teal/emerald** medis dengan aksen amber & rose.
- Font **Plus Jakarta Sans** (judul) + **Inter** (teks).
- Komponen kustom: kartu statistik, *pill* status, tabel rapi, sidebar responsif (mobile overlay).

---

*Dibuat dengan PHP murni — tanpa framework, mudah dipelajari & dimodifikasi.* 💚

---

## 🔒 Keamanan (untuk publish)

Aplikasi sudah dikeraskan untuk lingkungan online:

- **Kredensial via `.env`** — DB user/password tidak lagi ditulis di kode. File `.env` diblokir dari akses web dan diabaikan Git (`.gitignore`).
- **`.htaccess` root** — mematikan directory listing, memblokir file sensitif (`.env`, `.sql`, `.md`, `.log`, dotfiles), menambah header keamanan (`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, dll), dan menyembunyikan versi PHP.
- **Folder terkunci** — `config/` dan `includes/` punya `.htaccess` *deny all*, jadi tidak bisa diakses langsung dari browser/curl.
- **Guard anti-akses-langsung** — tiap file di `config/` & `includes/` menolak (403) bila dipanggil langsung, **bahkan jika server bukan Apache** (mis. Nginx yang tak baca `.htaccess`).
- **Wajib login** — semua halaman & endpoint memanggil `require_login()`; curl tanpa sesi akan di-redirect ke login, tanpa membocorkan data.
- **Cookie session aman** — `HttpOnly`, `SameSite=Lax`, dan otomatis `Secure` saat diakses lewat HTTPS.
- **Anti SQL Injection & XSS** — seluruh query memakai *prepared statements*; output dibungkus `e()` (htmlspecialchars).
- **Error tersembunyi saat produksi** — set `APP_DEBUG=false` agar detail error tidak bocor ke pengunjung.

### ⚙️ Catatan untuk server Nginx
`.htaccess` hanya berlaku di Apache. Bila pakai Nginx, tambahkan di server block:
```nginx
# Blokir file & folder sensitif
location ~ /\.env            { deny all; }
location ~ /\.(?!well-known) { deny all; }
location ~* \.(sql|md|log|bak|old|ini)$ { deny all; }
location ~ ^/(config|includes)/ { deny all; }
```
(Guard PHP tetap melindungi meski aturan di atas terlewat.)

### ✅ Checklist sebelum online
1. `cp .env.example .env` lalu isi password DB yang kuat.
2. Set `APP_DEBUG=false`.
3. **Ganti semua password akun demo** (login → kelola pengguna).
4. Pasang **SSL/HTTPS**, lalu aktifkan baris `Strict-Transport-Security` & redirect HTTPS di `.htaccess`.
5. Pastikan user MySQL aplikasi **bukan root**, beri hak seperlunya saja.
