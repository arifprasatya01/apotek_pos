-- ============================================================
--  Migrasi: Kirim struk via WhatsApp + profil apotek
--  CLI: mysql -u root -p apotek_sehat < migrations/wa_struk.sql
-- ============================================================

USE `apotek_sehat`;

-- Data pelanggan & nomor WA pada transaksi penjualan
ALTER TABLE `penjualan`
  ADD COLUMN IF NOT EXISTS `pelanggan` VARCHAR(100) DEFAULT NULL AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `no_wa`     VARCHAR(25)  DEFAULT NULL AFTER `pelanggan`;

-- Profil apotek (dipakai di header struk & sumber nomor WA)
INSERT IGNORE INTO `pengaturan` (`nama`,`nilai`) VALUES
  ('apotek_nama',   'Apotek Sehat'),
  ('apotek_alamat', 'Jl. Kesehatan No. 1, Karawang'),
  ('apotek_telp',   '(0267) 123456'),
  ('apotek_wa',     '6281234567890'),
  ('struk_footer',  'Terima kasih atas kunjungan Anda 🙏');
