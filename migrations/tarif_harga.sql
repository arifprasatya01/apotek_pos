-- ============================================================
--  Migrasi: Tanggal Jatuh Tempo + Master Tarif + Harga Jual + PPN
--  Untuk database `apotek_sehat` yang SUDAH ada.
--
--  CLI: mysql -u root -p apotek_sehat < migrations/tarif_harga.sql
--
--  Catatan: sintaks "IF NOT EXISTS" pada ALTER didukung MariaDB
--  (XAMPP/Laragon). Jika pakai MySQL murni dan error, hapus
--  bagian "IF NOT EXISTS" pada baris ALTER di bawah.
-- ============================================================

USE `apotek_sehat`;

-- 1) Tanggal jatuh tempo pembayaran ke supplier (per pembelian)
ALTER TABLE `pembelian`
  ADD COLUMN IF NOT EXISTS `tanggal_jatuh_tempo` DATE DEFAULT NULL AFTER `tanggal`;

-- 2) Pengaturan umum (key-value) — mis. PPN
CREATE TABLE IF NOT EXISTS `pengaturan` (
  `nama`  VARCHAR(50) PRIMARY KEY,
  `nilai` VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO `pengaturan` (`nama`,`nilai`) VALUES ('ppn_persen','11');

-- 3) Master Tarif (tingkat harga jual) — mis. Eceran, Langsung, Grosir
CREATE TABLE IF NOT EXISTS `tarif` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `nama_tarif`    VARCHAR(60) NOT NULL,
  `margin_persen` DECIMAL(6,2) NOT NULL DEFAULT 0,
  `is_default`    TINYINT(1) NOT NULL DEFAULT 0,
  `aktif`         TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `tarif` (`nama_tarif`,`margin_persen`,`is_default`)
SELECT * FROM (
  SELECT 'Eceran'   AS n, 30 AS m, 1 AS d
  UNION ALL SELECT 'Langsung', 20, 0
  UNION ALL SELECT 'Grosir',   12, 0
) seed
WHERE NOT EXISTS (SELECT 1 FROM `tarif`);

-- 4) Harga jual per obat per tarif
CREATE TABLE IF NOT EXISTS `obat_harga` (
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

-- 5) Isi harga jual awal dari data obat yang ada (untuk tiap tarif),
--    memakai harga_jual lama sebagai patokan tarif default.
INSERT IGNORE INTO `obat_harga` (`obat_id`,`tarif_id`,`metode`,`margin_persen`,`harga_jual`)
SELECT o.id, t.id, 'margin', t.margin_persen,
       ROUND( (o.harga_beli + o.harga_beli * (SELECT nilai FROM pengaturan WHERE nama='ppn_persen')/100)
              * (1 + t.margin_persen/100) )
FROM `obat` o CROSS JOIN `tarif` t;
