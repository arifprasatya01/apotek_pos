-- =====================================================================
--  APOTEK SEHAT - Database Schema
--  PHP Native + MySQLi + Bootstrap 5
--  Cara pakai: cukup import file ini (database dibuat otomatis).
--    phpMyAdmin : tab Import > pilih file ini
--    CLI        : mysql -u root -p < database.sql
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `apotek_sehat`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `apotek_sehat`;

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Tabel: users
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `nama`       VARCHAR(100) NOT NULL,
  `username`   VARCHAR(50)  NOT NULL UNIQUE,
  `password`   VARCHAR(255) NOT NULL,
  `role`       ENUM('admin','apoteker','kasir') NOT NULL DEFAULT 'kasir',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Password default untuk semua akun di bawah: "admin123"
-- (hash dihasilkan dengan password_hash('admin123', PASSWORD_DEFAULT))
INSERT INTO `users` (`nama`, `username`, `password`, `role`) VALUES
('Administrator', 'admin',     '$2y$10$m.YrIE9AIEjDAi5OP9ISiuXKhxgxk.IFeNH2RMxascz9UBLfQJmj.', 'admin'),
('Apoteker Sehat','apoteker',  '$2y$10$m.YrIE9AIEjDAi5OP9ISiuXKhxgxk.IFeNH2RMxascz9UBLfQJmj.', 'apoteker'),
('Kasir Depan',   'kasir',     '$2y$10$m.YrIE9AIEjDAi5OP9ISiuXKhxgxk.IFeNH2RMxascz9UBLfQJmj.', 'kasir');

-- ---------------------------------------------------------------------
-- Tabel: kategori
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `kategori`;
CREATE TABLE `kategori` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `nama_kategori` VARCHAR(100) NOT NULL,
  `keterangan`    VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `kategori` (`nama_kategori`, `keterangan`) VALUES
('Analgesik',     'Obat pereda nyeri dan penurun panas'),
('Antibiotik',    'Obat untuk infeksi bakteri'),
('Vitamin & Suplemen', 'Vitamin dan suplemen kesehatan'),
('Antasida',      'Obat lambung dan pencernaan'),
('Obat Batuk & Flu', 'Obat untuk gejala batuk, pilek, dan flu'),
('Alat Kesehatan','Masker, perban, termometer, dll');

-- ---------------------------------------------------------------------
-- Tabel: supplier
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `supplier`;
CREATE TABLE `supplier` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `nama_supplier` VARCHAR(120) NOT NULL,
  `alamat`        VARCHAR(255) DEFAULT NULL,
  `telepon`       VARCHAR(30)  DEFAULT NULL,
  `email`         VARCHAR(120) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `supplier` (`nama_supplier`, `alamat`, `telepon`, `email`) VALUES
('PT Kimia Farma',     'Jakarta Pusat',  '021-3847123', 'order@kimiafarma.co.id'),
('PT Kalbe Farma',     'Bekasi, Jawa Barat', '021-8451234', 'sales@kalbe.co.id'),
('PT Sanbe Farma',     'Bandung, Jawa Barat', '022-6034567', 'cs@sanbe.co.id'),
('PT Dexa Medica',     'Tangerang, Banten', '021-5970888', 'info@dexa-medica.com');

-- ---------------------------------------------------------------------
-- Tabel: obat
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `obat`;
CREATE TABLE `obat` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `kode_obat`         VARCHAR(30)  NOT NULL UNIQUE,
  `nama_obat`         VARCHAR(150) NOT NULL,
  `kategori_id`       INT DEFAULT NULL,
  `supplier_id`       INT DEFAULT NULL,
  `satuan`            VARCHAR(30)  DEFAULT 'Strip',
  `harga_beli`        INT NOT NULL DEFAULT 0,
  `harga_jual`        INT NOT NULL DEFAULT 0,
  `stok`              INT NOT NULL DEFAULT 0,
  `stok_minimal`      INT NOT NULL DEFAULT 10,
  `tanggal_kadaluarsa` DATE DEFAULT NULL,
  `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`kategori_id`) REFERENCES `kategori`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`supplier_id`) REFERENCES `supplier`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `obat`
(`kode_obat`,`nama_obat`,`kategori_id`,`supplier_id`,`satuan`,`harga_beli`,`harga_jual`,`stok`,`stok_minimal`,`tanggal_kadaluarsa`) VALUES
('OBT-001','Paracetamol 500mg',        1, 1, 'Strip', 8000,  12000, 120, 20, '2027-06-01'),
('OBT-002','Amoxicillin 500mg',        2, 2, 'Strip', 15000, 22000, 8,   15, '2026-12-01'),
('OBT-003','Vitamin C 1000mg',         3, 2, 'Tablet',25000, 38000, 60,  15, '2027-03-15'),
('OBT-004','Antasida Doen',            4, 3, 'Botol', 9000,  15000, 45,  10, '2026-09-20'),
('OBT-005','OBH Combi Batuk Flu',      5, 1, 'Botol', 12000, 19000, 30,  10, '2026-08-10'),
('OBT-006','Ibuprofen 400mg',          1, 3, 'Strip', 10000, 16000, 5,   12, '2027-01-25'),
('OBT-007','Cetirizine 10mg',          5, 4, 'Strip', 11000, 18000, 70,  15, '2027-04-30'),
('OBT-008','Masker Medis 3 Ply (50pcs)',6,4,'Box',   28000, 45000, 25,  10, '2028-01-01'),
('OBT-009','Vitamin B Complex',        3, 2, 'Tablet',18000, 27000, 3,   10, '2026-07-15'),
('OBT-010','Promag Tablet',            4, 3, 'Strip', 7000,  11000, 90,  20, '2027-02-28'),
('OBT-011','Betadine Antiseptik 60ml', 6, 1, 'Botol', 22000, 33000, 40,  10, '2027-11-01'),
('OBT-012','Bodrex Migra',             1, 1, 'Strip', 9000,  14000, 6,   12, '2026-10-05');

-- ---------------------------------------------------------------------
-- Tabel: penjualan (header transaksi)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `penjualan`;
CREATE TABLE `penjualan` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `no_transaksi`  VARCHAR(40) NOT NULL UNIQUE,
  `tanggal`       DATETIME NOT NULL,
  `user_id`       INT DEFAULT NULL,
  `tarif_id`      INT DEFAULT NULL,
  `tarif_nama`    VARCHAR(100) DEFAULT NULL,
  `pelanggan`     VARCHAR(100) DEFAULT NULL,
  `no_wa`         VARCHAR(25) DEFAULT NULL,
  `total`         INT NOT NULL DEFAULT 0,
  `bayar`         INT NOT NULL DEFAULT 0,
  `kembali`       INT NOT NULL DEFAULT 0,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Tabel: penjualan_detail
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `penjualan_detail`;
CREATE TABLE `penjualan_detail` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `penjualan_id` INT NOT NULL,
  `obat_id`      INT DEFAULT NULL,
  `nama_obat`    VARCHAR(150) NOT NULL,
  `jumlah`       INT NOT NULL,
  `harga`        INT NOT NULL,
  `subtotal`     INT NOT NULL,
  FOREIGN KEY (`penjualan_id`) REFERENCES `penjualan`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`obat_id`) REFERENCES `obat`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Contoh beberapa transaksi penjualan agar dashboard tidak kosong
INSERT INTO `penjualan` (`no_transaksi`,`tanggal`,`user_id`,`total`,`bayar`,`kembali`) VALUES
('TRX-20260610-001', CONCAT(CURDATE() - INTERVAL 6 DAY,' 09:15:00'), 3, 50000, 50000, 0),
('TRX-20260611-001', CONCAT(CURDATE() - INTERVAL 5 DAY,' 10:30:00'), 3, 76000, 80000, 4000),
('TRX-20260612-001', CONCAT(CURDATE() - INTERVAL 4 DAY,' 14:05:00'), 3, 38000, 40000, 2000),
('TRX-20260613-001', CONCAT(CURDATE() - INTERVAL 3 DAY,' 11:45:00'), 3, 60000, 60000, 0),
('TRX-20260614-001', CONCAT(CURDATE() - INTERVAL 2 DAY,' 16:20:00'), 3, 90000, 100000, 10000),
('TRX-20260615-001', CONCAT(CURDATE() - INTERVAL 1 DAY,' 13:10:00'), 3, 45000, 50000, 5000),
('TRX-20260616-001', CONCAT(CURDATE(),' 08:50:00'), 3, 110000, 110000, 0),
('TRX-20260616-002', CONCAT(CURDATE(),' 12:30:00'), 3, 33000, 35000, 2000);

INSERT INTO `penjualan_detail` (`penjualan_id`,`obat_id`,`nama_obat`,`jumlah`,`harga`,`subtotal`) VALUES
(1, 1, 'Paracetamol 500mg', 2, 12000, 24000),
(1, 4, 'Antasida Doen',     1, 15000, 15000),
(1, 10,'Promag Tablet',     1, 11000, 11000),
(2, 3, 'Vitamin C 1000mg',  2, 38000, 76000),
(3, 3, 'Vitamin C 1000mg',  1, 38000, 38000),
(4, 8, 'Masker Medis 3 Ply (50pcs)', 1, 45000, 45000),
(4, 1, 'Paracetamol 500mg', 1, 12000, 12000),
(5, 11,'Betadine Antiseptik 60ml', 2, 33000, 66000),
(5, 7, 'Cetirizine 10mg',   1, 18000, 18000),
(6, 5, 'OBH Combi Batuk Flu',1, 19000, 19000),
(6, 7, 'Cetirizine 10mg',   1, 18000, 18000),
(6, 10,'Promag Tablet',     1, 11000, 11000),
(7, 8, 'Masker Medis 3 Ply (50pcs)', 2, 45000, 90000),
(7, 6, 'Ibuprofen 400mg',   1, 16000, 16000),
(8, 11,'Betadine Antiseptik 60ml', 1, 33000, 33000);

-- ============================================================
--  Tabel Pembelian (stok masuk dari supplier)
-- ============================================================
CREATE TABLE `pembelian` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `no_pembelian` VARCHAR(40) NOT NULL UNIQUE,
  `no_faktur`    VARCHAR(60) DEFAULT NULL,
  `tanggal`      DATETIME NOT NULL,
  `tanggal_jatuh_tempo` DATE DEFAULT NULL,
  `supplier_id`  INT DEFAULT NULL,
  `user_id`      INT DEFAULT NULL,
  `total`        INT NOT NULL DEFAULT 0,
  `subtotal`     INT NOT NULL DEFAULT 0,
  `ppn_persen`   DECIMAL(6,2) NOT NULL DEFAULT 11,
  `ppn_nominal`  INT NOT NULL DEFAULT 0,
  `catatan`      VARCHAR(255) DEFAULT NULL,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`supplier_id`) REFERENCES `supplier`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `pembelian_detail` (
  `id`                 INT AUTO_INCREMENT PRIMARY KEY,
  `pembelian_id`       INT NOT NULL,
  `obat_id`            INT DEFAULT NULL,
  `nama_obat`          VARCHAR(150) NOT NULL,
  `jumlah`             INT NOT NULL,
  `harga_beli`         INT NOT NULL,
  `diskon_persen`      DECIMAL(5,2) NOT NULL DEFAULT 0,
  `subtotal`           INT NOT NULL,
  `tanggal_kadaluarsa` DATE DEFAULT NULL,
  FOREIGN KEY (`pembelian_id`) REFERENCES `pembelian`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`obat_id`)      REFERENCES `obat`(`id`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
--  Pengaturan umum (key-value), Master Tarif, Harga Jual
-- ============================================================
CREATE TABLE `pengaturan` (
  `nama`  VARCHAR(50) PRIMARY KEY,
  `nilai` VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `pengaturan` (`nama`,`nilai`) VALUES ('ppn_persen','11');
INSERT INTO `pengaturan` (`nama`,`nilai`) VALUES
  ('apotek_nama',   'Apotek Sehat'),
  ('apotek_alamat', 'Jl. Kesehatan No. 1, Karawang'),
  ('apotek_telp',   '(0267) 123456'),
  ('apotek_wa',     '6281234567890'),
  ('struk_footer',  'Terima kasih atas kunjungan Anda 🙏');

CREATE TABLE `tarif` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `nama_tarif`    VARCHAR(60) NOT NULL,
  `margin_persen` DECIMAL(6,2) NOT NULL DEFAULT 0,
  `is_default`    TINYINT(1) NOT NULL DEFAULT 0,
  `aktif`         TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `tarif` (`nama_tarif`,`margin_persen`,`is_default`) VALUES
  ('Eceran',   30, 1),
  ('Langsung', 20, 0),
  ('Grosir',   12, 0);

CREATE TABLE `obat_harga` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `obat_id`       INT NOT NULL,
  `tarif_id`      INT NOT NULL,
  `metode`        ENUM('margin','fixed') NOT NULL DEFAULT 'margin',
  `margin_persen` DECIMAL(6,2) DEFAULT NULL,
  `harga_jual`    INT NOT NULL DEFAULT 0,
  UNIQUE KEY `uniq_obat_tarif` (`obat_id`,`tarif_id`),
  FOREIGN KEY (`obat_id`)  REFERENCES `obat`(`id`)  ON DELETE CASCADE,
  FOREIGN KEY (`tarif_id`) REFERENCES `tarif`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Harga jual awal tiap obat untuk tiap tarif (harga_beli + PPN, lalu margin tarif)
INSERT INTO `obat_harga` (`obat_id`,`tarif_id`,`metode`,`margin_persen`,`harga_jual`)
SELECT o.id, t.id, 'margin', t.margin_persen,
       ROUND( (o.harga_beli + o.harga_beli * 11/100) * (1 + t.margin_persen/100) )
FROM `obat` o CROSS JOIN `tarif` t;

-- ============================================================
--  Log Aktivitas (jejak siapa melakukan apa)
-- ============================================================
CREATE TABLE `log_aktivitas` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT NULL,
  `modul`      VARCHAR(50) NOT NULL,
  `aksi`       VARCHAR(50) NOT NULL,
  `deskripsi`  TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_log_user` (`user_id`),
  INDEX `idx_log_modul` (`modul`),
  INDEX `idx_log_created` (`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
