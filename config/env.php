<?php
/**
 * Pemuat variabel lingkungan (.env) sederhana — tanpa Composer.
 * Membaca file .env di root project dan mengisinya ke getenv()/$_ENV.
 */

// Cegah akses langsung ke file ini
if (isset($_SERVER['SCRIPT_FILENAME']) &&
    realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(403);
    exit('403 Forbidden');
}

/** Muat file .env (sekali saja) */
function load_env(string $path): void
{
    static $loaded = false;
    if ($loaded || !is_readable($path)) {
        $loaded = true;
        return;
    }
    $loaded = true;

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        // Lewati komentar & baris tanpa '='
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);

        // Hapus tanda kutip pembungkus jika ada
        $len = strlen($val);
        if ($len >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[$len - 1] === $val[0]) {
            $val = substr($val, 1, -1);
        }

        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$val");
            $_ENV[$key]    = $val;
            $_SERVER[$key] = $val;
        }
    }
}

/** Ambil nilai env dengan fallback */
function env(string $key, $default = null)
{
    $val = getenv($key);
    if ($val === false) {
        $val = $_ENV[$key] ?? $default;
    }
    // Normalisasi boolean & null umum
    return match (strtolower((string) $val)) {
        'true'  => true,
        'false' => false,
        'null'  => null,
        default => $val,
    };
}
