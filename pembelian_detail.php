<?php
require_once __DIR__ . '/config/config.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$h = $conn->prepare(
    "SELECT p.*, s.nama_supplier, u.nama petugas
     FROM pembelian p
     LEFT JOIN supplier s ON s.id=p.supplier_id
     LEFT JOIN users u ON u.id=p.user_id
     WHERE p.id=?"
);
$h->bind_param('i', $id); $h->execute();
$beli = $h->get_result()->fetch_assoc();

if (!$beli) { echo '<div class="empty-state">Pembelian tidak ditemukan.</div>'; exit; }

$d = $conn->prepare("SELECT * FROM pembelian_detail WHERE pembelian_id=?");
$d->bind_param('i', $id); $d->execute();
$items = $d->get_result();

// Kompat: pembelian lama mungkin belum punya subtotal/ppn terpisah
$subtotal    = isset($beli['subtotal']) ? (int)$beli['subtotal'] : (int)$beli['total'];
$ppn_persen  = isset($beli['ppn_persen']) ? (float)$beli['ppn_persen'] : 0;
$ppn_nominal = isset($beli['ppn_nominal']) ? (int)$beli['ppn_nominal'] : 0;
?>
<div class="d-flex justify-content-between cell-muted"><span>No. Pembelian</span><span class="cell-strong"><?= e($beli['no_pembelian']) ?></span></div>
<div class="d-flex justify-content-between cell-muted"><span>No. Faktur</span><span><?= e($beli['no_faktur'] ?? '-') ?></span></div>
<div class="d-flex justify-content-between cell-muted"><span>Tanggal</span><span><?= tgl_indo($beli['tanggal'], true) ?></span></div>
<?php if (!empty($beli['tanggal_jatuh_tempo'])): ?>
<div class="d-flex justify-content-between cell-muted"><span>Jatuh Tempo</span><span><?= tgl_indo($beli['tanggal_jatuh_tempo']) ?></span></div>
<?php endif; ?>
<div class="d-flex justify-content-between cell-muted"><span>Supplier</span><span><?= e($beli['nama_supplier'] ?? '-') ?></span></div>
<div class="d-flex justify-content-between cell-muted mb-2"><span>Petugas</span><span><?= e($beli['petugas'] ?? '-') ?></span></div>
<hr class="divider-soft">
<table class="table-app">
  <thead><tr><th>Obat</th><th class="text-end">Qty</th><th class="text-end">Harga</th><th class="text-end">Disk</th><th class="text-end">Subtotal</th></tr></thead>
  <tbody>
  <?php while ($it = $items->fetch_assoc()):
      $disk = isset($it['diskon_persen']) ? (float)$it['diskon_persen'] : 0; ?>
    <tr>
      <td class="cell-strong" style="font-size:13.5px">
        <?= e($it['nama_obat']) ?>
        <?php if (!empty($it['tanggal_kadaluarsa'])): ?>
          <span class="cell-muted d-block" style="font-size:11.5px">ED: <?= tgl_indo($it['tanggal_kadaluarsa']) ?></span>
        <?php endif; ?>
      </td>
      <td class="text-end"><?= (int)$it['jumlah'] ?></td>
      <td class="text-end cell-muted"><?= rupiah($it['harga_beli']) ?></td>
      <td class="text-end cell-muted"><?= $disk > 0 ? rtrim(rtrim(number_format($disk,2),'0'),'.').'%' : '-' ?></td>
      <td class="text-end cell-strong"><?= rupiah($it['subtotal']) ?></td>
    </tr>
  <?php endwhile; ?>
  </tbody>
</table>
<hr class="divider-soft">
<div class="d-flex justify-content-between cell-muted"><span>Subtotal</span><span class="cell-strong"><?= rupiah($subtotal) ?></span></div>
<div class="d-flex justify-content-between cell-muted"><span>PPN (<?= rtrim(rtrim(number_format($ppn_persen,2),'0'),'.') ?>%)</span><span class="cell-strong"><?= rupiah($ppn_nominal) ?></span></div>
<div class="d-flex justify-content-between cell-strong mt-1" style="font-size:16px"><span>Total</span><span style="color:var(--teal-700)"><?= rupiah($beli['total']) ?></span></div>
<?php if (!empty($beli['catatan'])): ?>
<div class="cell-muted mt-2" style="font-size:13px"><i class="bi bi-sticky me-1"></i><?= e($beli['catatan']) ?></div>
<?php endif; ?>
