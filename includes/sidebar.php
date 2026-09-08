<?php
/** Sidebar navigasi — $user & $stok_menipis tersedia dari header.php */
if (!defined('APP_RUNNING')) { http_response_code(403); exit('403 Forbidden'); }
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar__brand">
    <div class="sidebar__logo"><i class="bi bi-capsule-pill"></i></div>
    <div>
      <div class="sidebar__title"><?= APP_NAME ?></div>
      <div class="sidebar__subtitle">Apotek POS</div>
    </div>
  </div>

  <nav class="sidebar__nav">
    <div class="sidebar__section">Menu Utama</div>
    <a class="nav-link-app <?= is_active('index.php') ?>" href="/">
      <i class="bi bi-grid-1x2"></i> Dashboard
    </a>
    <a class="nav-link-app <?= is_active('penjualan.php') ?>" href="penjualan">
      <i class="bi bi-cart-plus"></i> Kasir / Penjualan
    </a>
    <a class="nav-link-app <?= is_active('transaksi.php') ?>" href="transaksi">
      <i class="bi bi-receipt"></i> Riwayat Transaksi
    </a>

    <div class="sidebar__section">Inventaris</div>
    <a class="nav-link-app <?= is_active('obat.php') ?>" href="obat">
      <i class="bi bi-capsule"></i> Data Obat
      <?php if ($stok_menipis > 0): ?>
        <span class="badge rounded-pill" style="background:var(--amber)"><?= $stok_menipis ?></span>
      <?php endif; ?>
    </a>
    <a class="nav-link-app <?= is_active('pembelian.php') ?>" href="pembelian">
      <i class="bi bi-cart-plus"></i> Pembelian Obat
    </a>
    <?php if (has_role('admin', 'apoteker')): ?>
    <a class="nav-link-app <?= is_active('stock_opname.php') ?>" href="stock_opname">
      <i class="bi bi-clipboard2-pulse"></i> Stock Opname
    </a>
    <?php endif; ?>
    <a class="nav-link-app <?= is_active('kategori.php') ?>" href="kategori">
      <i class="bi bi-tags"></i> Kategori
    </a>
    <a class="nav-link-app <?= is_active('supplier.php') ?>" href="supplier">
      <i class="bi bi-truck"></i> Supplier
    </a>

    <div class="sidebar__section">Laporan</div>
    <a class="nav-link-app <?= is_active('laporan.php') ?>" href="laporan">
      <i class="bi bi-graph-up-arrow"></i> Laporan Penjualan
    </a>

    <?php if (has_role('admin', 'apoteker')): ?>
      <div class="sidebar__section">Penetapan Harga</div>
      <a class="nav-link-app <?= is_active('harga_jual.php') ?>" href="harga_jual">
        <i class="bi bi-cash-coin"></i> Harga Jual
      </a>
      <a class="nav-link-app <?= is_active('tarif.php') ?>" href="tarif">
        <i class="bi bi-layers"></i> Tarif &amp; PPN
      </a>
    <?php endif; ?>

    <?php if (has_role('admin')): ?>
      <div class="sidebar__section">Pengaturan</div>
      <a class="nav-link-app <?= is_active('user.php') ?>" href="user">
        <i class="bi bi-people"></i> Pengguna
      </a>
      <a class="nav-link-app <?= is_active('pengaturan.php') ?>" href="pengaturan">
        <i class="bi bi-shop"></i> Profil Apotek
      </a>
      <a class="nav-link-app <?= is_active('log_aktivitas.php') ?>" href="log_aktivitas">
        <i class="bi bi-journal-text"></i> Log Aktivitas
      </a>
    <?php endif; ?>
  </nav>

  <div class="sidebar__foot">
    <div class="sidebar__user">
      <div class="sidebar__avatar"><?= strtoupper(substr($user['nama'], 0, 1)) ?></div>
      <div style="min-width:0">
        <div class="text-truncate" style="color:#fff;font-weight:600;font-size:13.5px"><?= e($user['nama']) ?></div>
        <div style="font-size:11.5px;color:#8fd3cb;text-transform:capitalize"><?= e($user['role']) ?></div>
      </div>
      <a href="logout" class="ms-auto text-white-50" title="Keluar" style="font-size:18px"><i class="bi bi-box-arrow-right"></i></a>
    </div>
  </div>
</aside>