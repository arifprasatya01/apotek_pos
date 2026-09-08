<?php
/**
 * Koneksi Database (MySQLi) — kredensial dibaca dari file .env
 * ---------------------------------------------------------------
 * Edit nilai DB_* di file .env (root project), BUKAN di sini.
 */

// Cegah akses langsung ke file ini
if (isset($_SERVER['SCRIPT_FILENAME']) &&
    realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(403);
    exit('403 Forbidden');
}

require_once __DIR__ . '/env.php';
load_env(__DIR__ . '/../.env');

define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_USER', env('DB_USER', 'u297738695_apotek_pos'));
define('DB_PASS', env('DB_PASS', 'RSUDk4r4w4ng'));
define('DB_NAME', env('DB_NAME', 'u297738695_apotek_pos'));

$APP_DEBUG = (bool) env('APP_DEBUG', false);

// Aktifkan pelaporan error mysqli sebagai exception
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    // Saat produksi: pesan umum saja, jangan bocorkan detail.
    if (!$APP_DEBUG) {
        die(
            '<div style="font-family:system-ui;max-width:520px;margin:80px auto;padding:28px;'
            . 'border:1px solid #e5e7eb;border-radius:14px;background:#f9fafb;color:#374151;text-align:center">'
            . '<h2 style="margin:0 0 8px">Layanan sedang tidak tersedia</h2>'
            . '<p style="margin:0">Mohon coba beberapa saat lagi atau hubungi administrator.</p></div>'
        );
    }
    // Saat development: tampilkan detail untuk debugging.
    die(
        '<div style="font-family:system-ui;max-width:560px;margin:80px auto;padding:28px;'
        . 'border:1px solid #fca5a5;border-radius:14px;background:#fef2f2;color:#7f1d1d">'
        . '<h2 style="margin:0 0 8px">Koneksi database gagal</h2>'
        . '<p style="margin:0 0 12px">Pastikan MySQL berjalan dan database <b>'
        . DB_NAME . '</b> sudah diimport dari <code>database.sql</code>.</p>'
        . '<pre style="white-space:pre-wrap;font-size:13px;background:#fff;padding:10px;border-radius:8px">'
        . htmlspecialchars($e->getMessage()) . '</pre></div>'
    );
}
