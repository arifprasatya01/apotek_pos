-- ============================================================
--  Migrasi: Modul Pembelian Obat (dari supplier)
--  Jalankan ini bila database `apotek_sehat` SUDAH ada
--  dan ingin menambahkan fitur pembelian.
--
--  phpMyAdmin: pilih database apotek_sehat > tab Import > pilih file ini
--  CLI       : mysql -u root -p apotek_sehat < migrations/pembelian.sql
-- ============================================================

USE `apotek_sehat`;

CREATE TABLE IF NOT EXISTS `pembelian` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `no_pembelian` VARCHAR(40) NOT NULL UNIQUE,
  `no_faktur`    VARCHAR(60) DEFAULT NULL,
  `tanggal`      DATETIME NOT NULL,
  `supplier_id`  INT DEFAULT NULL,
  `user_id`      INT DEFAULT NULL,
  `total`        INT NOT NULL DEFAULT 0,
  `catatan`      VARCHAR(255) DEFAULT NULL,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`supplier_id`) REFERENCES `supplier`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `pembelian_detail` (
  `id`                 INT AUTO_INCREMENT PRIMARY KEY,
  `pembelian_id`       INT NOT NULL,
  `obat_id`            INT DEFAULT NULL,
  `nama_obat`          VARCHAR(150) NOT NULL,
  `jumlah`             INT NOT NULL,
  `harga_beli`         INT NOT NULL,
  `subtotal`           INT NOT NULL,
  `tanggal_kadaluarsa` DATE DEFAULT NULL,
  FOREIGN KEY (`pembelian_id`) REFERENCES `pembelian`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`obat_id`)      REFERENCES `obat`(`id`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
