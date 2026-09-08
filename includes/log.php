<?php
/**
 * Catat satu baris aktivitas user ke tabel log_aktivitas.
 * Dipanggil dari halaman manapun yang punya aksi tambah/edit/hapus/dll,
 * supaya ada jejak "siapa melakukan apa, kapan".
 *
 * @param mysqli   $conn
 * @param string   $modul     nama modul, mis. 'obat', 'tarif', 'harga_jual', 'penjualan', 'user', 'auth'
 * @param string   $aksi      nama aksi, mis. 'tambah', 'edit', 'hapus', 'checkout', 'login', 'login_gagal'
 * @param string   $deskripsi detail human-readable tentang apa yang terjadi/berubah
 * @param int|null $user_id   override user (mis. saat logout, sesudah session dibersihkan).
 *                             Default: ambil dari current_user() yang sedang login.
 */
function log_aktivitas(mysqli $conn, string $modul, string $aksi, string $deskripsi = '', ?int $user_id = null): void
{
    if ($user_id === null && function_exists('current_user')) {
        $cu = current_user();
        if ($cu && isset($cu['id'])) $user_id = (int) $cu['id'];
    }
    $stmt = $conn->prepare('INSERT INTO log_aktivitas (user_id, modul, aksi, deskripsi) VALUES (?,?,?,?)');
    $stmt->bind_param('isss', $user_id, $modul, $aksi, $deskripsi);
    $stmt->execute();
}
