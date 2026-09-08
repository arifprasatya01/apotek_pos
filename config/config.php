<?php
ob_start();
/**
 * Konfigurasi Aplikasi & Fungsi Bantu (Helper)
 */

// Cegah akses langsung ke file ini
if (isset($_SERVER['SCRIPT_FILENAME']) &&
    realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(403);
    exit('403 Forbidden');
}

// Penanda bahwa aplikasi berjalan melalui entry point yang sah
define('APP_RUNNING', true);

// Identitas aplikasi
define('APP_NAME', 'Apotek Healoka');
define('APP_TAGLINE', 'Sistem Manajemen Apotek');

// Muat koneksi DB (sekaligus memuat .env)
require_once __DIR__ . '/database.php';

// Helper log_aktivitas() — dipakai di semua halaman untuk mencatat jejak aksi user
require_once __DIR__ . '/../includes/log.php';

// Deteksi HTTPS (termasuk di belakang proxy/load balancer)
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

// Konfigurasi cookie session yang aman sebelum session_start()
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,   // hanya dikirim lewat HTTPS
        'httponly' => true,      // tidak bisa dibaca JavaScript (anti-XSS)
        'samesite' => 'Lax',     // mitigasi CSRF
    ]);
    session_start();
}

/* ---------------------------------------------------------------------
 |  Helper umum
 * ------------------------------------------------------------------- */

/** Escape output HTML untuk mencegah XSS */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Format angka ke Rupiah */
function rupiah($angka): string
{
    return 'Rp ' . number_format((float) $angka, 0, ',', '.');
}

/** Format angka ringkas (1.2 jt, 950 rb) untuk kartu statistik */
function rupiah_singkat($angka): string
{
    $angka = (float) $angka;
    if ($angka >= 1_000_000_000) return 'Rp ' . rtrim(rtrim(number_format($angka / 1_000_000_000, 1, ',', '.'), '0'), ',') . ' M';
    if ($angka >= 1_000_000)     return 'Rp ' . rtrim(rtrim(number_format($angka / 1_000_000, 1, ',', '.'), '0'), ',') . ' jt';
    if ($angka >= 1_000)         return 'Rp ' . round($angka / 1_000) . ' rb';
    return rupiah($angka);
}

/** Format tanggal Indonesia */
function tgl_indo($tanggal, bool $with_time = false): string
{
    if (!$tanggal || $tanggal === '0000-00-00') return '-';
    $bulan = [1=>'Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    $ts = strtotime($tanggal);
    $out = date('j', $ts) . ' ' . $bulan[(int) date('n', $ts)] . ' ' . date('Y', $ts);
    if ($with_time) $out .= ', ' . date('H:i', $ts);
    return $out;
}

/** Redirect ke URL lalu hentikan eksekusi (otomatis sembunyikan .php utk URL internal) */
function redirect(string $url): void
{
    if (!preg_match('#^https?://#i', $url)) {
        $url = preg_replace('/\.php(?=$|[?#])/i', '', $url);
        // Halaman utama (dashboard): arahkan ke root "/", bukan "/index"
        if ($url === 'index') $url = '/';
    }
    header('Location: ' . $url);
    exit;
}

/** Set flash message (alert satu kali tampil) */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/** Ambil & hapus flash message */
function get_flash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/** Cek apakah menu sedang aktif (untuk sidebar) */
function is_active(string $page): string
{
    $current = basename($_SERVER['PHP_SELF']);
    return $current === $page ? 'active' : '';
}

/* ---------------------------------------------------------------------
 |  Proteksi CSRF
 * ------------------------------------------------------------------- */

/** Ambil (atau buat) token CSRF untuk sesi saat ini */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Cetak input hidden CSRF — taruh di dalam SETIAP <form method="post"> */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Verifikasi token CSRF pada request POST. Hentikan eksekusi kalau tidak valid/cocok. */
function csrf_verify(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        set_flash('danger', 'Sesi formulir sudah tidak valid/kedaluwarsa. Silakan coba lagi.');
        $back = $_SERVER['HTTP_REFERER'] ?? '/';
        // Hanya izinkan redirect ke path milik domain sendiri (cegah open redirect)
        $self = (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('#^https?://#i', $back) || str_starts_with($back, $self)) {
            redirect($back);
        }
        redirect('/');
    }
}

// Verifikasi CSRF otomatis untuk SETIAP request POST di seluruh aplikasi —
// dijalankan di sini (sekali, di config.php) supaya tidak perlu dipanggil
// manual satu-satu di setiap halaman.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
}

/* ---------------------------------------------------------------------
 |  Pengaturan & Harga
 * ------------------------------------------------------------------- */

/** Ambil nilai pengaturan dari tabel `pengaturan` */
function get_setting(mysqli $conn, string $nama, $default = null)
{
    $s = $conn->prepare('SELECT nilai FROM pengaturan WHERE nama=?');
    $s->bind_param('s', $nama);
    $s->execute();
    $r = $s->get_result()->fetch_assoc();
    return $r ? $r['nilai'] : $default;
}

/** Simpan/Perbarui nilai pengaturan */
function set_setting(mysqli $conn, string $nama, string $nilai): void
{
    $s = $conn->prepare('INSERT INTO pengaturan (nama,nilai) VALUES (?,?)
                         ON DUPLICATE KEY UPDATE nilai=VALUES(nilai)');
    $s->bind_param('ss', $nama, $nilai);
    $s->execute();
}

/** Hitung harga jual: (harga beli + PPN) lalu ditambah margin% */
function hitung_harga_jual(float $harga_beli, float $ppn_persen, float $margin_persen): int
{
    $dasar = $harga_beli + ($harga_beli * $ppn_persen / 100);
    return (int) round($dasar * (1 + $margin_persen / 100));
}

/* ---------------------------------------------------------------------
 |  Autentikasi
 * ------------------------------------------------------------------- */

/** Wajib login: panggil di awal halaman yang butuh autentikasi */
function require_login(): void
{
    if (empty($_SESSION['user'])) {
        redirect('login.php');
    }
}

/** Data user yang sedang login */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/** Cek role tertentu */
function has_role(string ...$roles): bool
{
    $user = current_user();
    return $user && in_array($user['role'], $roles, true);
}
