-- ============================================================
--  Migrasi: Log Aktivitas (siapa melakukan apa)
--           + kolom tarif_id / tarif_nama di penjualan (perbaikan
--             error "Unknown column 'tarif_id'" saat checkout)
--  CLI: mysql -u root -p apotek_sehat < migrations/log_aktivitas.sql
-- ============================================================

USE `apotek_sehat`;

-- 1) Kolom tarif pada transaksi penjualan (kalau belum ada)
ALTER TABLE `penjualan`
  ADD COLUMN IF NOT EXISTS `tarif_id`   INT NULL AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `tarif_nama` VARCHAR(100) NULL AFTER `tarif_id`;

-- 2) Tabel log aktivitas
CREATE TABLE IF NOT EXISTS `log_aktivitas` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT NULL,
  `modul`      VARCHAR(50) NOT NULL,   -- obat, kategori, supplier, pembelian, tarif, harga_jual, penjualan, transaksi, user, pengaturan, auth
  `aksi`       VARCHAR(50) NOT NULL,   -- tambah, edit, hapus, checkout, recompute, login, login_gagal, logout
  `deskripsi`  TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_log_user` (`user_id`),
  INDEX `idx_log_modul` (`modul`),
  INDEX `idx_log_created` (`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
