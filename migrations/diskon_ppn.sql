-- ============================================================
--  Migrasi: Diskon per item + PPN manual pada Pembelian
--  CLI: mysql -u root -p apotek_sehat < migrations/diskon_ppn.sql
--  (sintaks IF NOT EXISTS didukung MariaDB / XAMPP / Laragon)
-- ============================================================

USE `apotek_sehat`;

-- Header pembelian: subtotal (sebelum PPN), persen & nominal PPN
ALTER TABLE `pembelian`
  ADD COLUMN IF NOT EXISTS `subtotal`    INT NOT NULL DEFAULT 0     AFTER `total`,
  ADD COLUMN IF NOT EXISTS `ppn_persen`  DECIMAL(6,2) NOT NULL DEFAULT 11 AFTER `subtotal`,
  ADD COLUMN IF NOT EXISTS `ppn_nominal` INT NOT NULL DEFAULT 0     AFTER `ppn_persen`;

-- Detail: diskon per item (persen). Kolom subtotal sudah ada = nilai net setelah diskon.
ALTER TABLE `pembelian_detail`
  ADD COLUMN IF NOT EXISTS `diskon_persen` DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER `harga_beli`;
