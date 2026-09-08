<?php
require_once __DIR__ . '/config/config.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$h = $conn->prepare("SELECT p.*, u.nama kasir FROM penjualan p LEFT JOIN users u ON u.id=p.user_id WHERE p.id=?");
$h->bind_param('i', $id); $h->execute();
$trx = $h->get_result()->fetch_assoc();

if (!$trx) { echo '<div class="empty-state">Transaksi tidak ditemukan.</div>'; exit; }

$d = $conn->prepare("SELECT * FROM penjualan_detail WHERE penjualan_id=?");
$d->bind_param('i', $id); $d->execute();
$items = $d->get_result();

// Profil apotek + susun teks struk untuk WA
$apotek = [
    'nama'   => get_setting($conn, 'apotek_nama', APP_NAME),
    'alamat' => get_setting($conn, 'apotek_alamat', ''),
    'telp'   => get_setting($conn, 'apotek_telp', ''),
    'footer' => get_setting($conn, 'struk_footer', 'Terima kasih 🙏'),
];
$itemsArr = $items->fetch_all(MYSQLI_ASSOC);

$L = [];
$L[] = '*' . $apotek['nama'] . '*';
if ($apotek['alamat']) $L[] = $apotek['alamat'];
if ($apotek['telp'])   $L[] = 'Telp: ' . $apotek['telp'];
$L[] = '';
$L[] = 'Struk : ' . $trx['no_transaksi'];
$L[] = 'Tgl   : ' . tgl_indo($trx['tanggal'], true);
$L[] = 'Kasir : ' . ($trx['kasir'] ?? '-');
if (!empty($trx['pelanggan'])) $L[] = 'Pelanggan : ' . $trx['pelanggan'];
$L[] = '--------------------------------';
foreach ($itemsArr as $it) {
    $L[] = $it['nama_obat'];
    $L[] = '  ' . $it['jumlah'] . ' x ' . rupiah($it['harga']) . ' = ' . rupiah($it['subtotal']);
}
$L[] = '--------------------------------';
$L[] = 'Total   : ' . rupiah($trx['total']);
$L[] = 'Bayar   : ' . rupiah($trx['bayar']);
$L[] = 'Kembali : ' . rupiah($trx['kembali']);
$L[] = '';
$L[] = $apotek['footer'];
$struk_text = implode("\n", $L);
?>
<div class="d-flex justify-content-between cell-muted"><span>No. Transaksi</span><span class="cell-strong"><?= e($trx['no_transaksi']) ?></span></div>
<div class="d-flex justify-content-between cell-muted"><span>Tanggal</span><span><?= tgl_indo($trx['tanggal'], true) ?></span></div>
<div class="d-flex justify-content-between cell-muted"><span>Kasir</span><span><?= e($trx['kasir'] ?? '-') ?></span></div>
<?php if (!empty($trx['pelanggan'])): ?>
<div class="d-flex justify-content-between cell-muted"><span>Pelanggan</span><span><?= e($trx['pelanggan']) ?></span></div>
<?php endif; ?>
<?php if (!empty($trx['no_wa'])): ?>
<div class="d-flex justify-content-between cell-muted"><span>No. WhatsApp</span><span><?= e($trx['no_wa']) ?></span></div>
<?php endif; ?>
<hr class="divider-soft">
<table class="table-app">
  <thead><tr><th>Obat</th><th class="text-end">Qty</th><th class="text-end">Harga</th><th class="text-end">Subtotal</th></tr></thead>
  <tbody>
  <?php foreach ($itemsArr as $it): ?>
    <tr>
      <td class="cell-strong" style="font-size:13.5px"><?= e($it['nama_obat']) ?></td>
      <td class="text-end"><?= (int)$it['jumlah'] ?></td>
      <td class="text-end cell-muted"><?= rupiah($it['harga']) ?></td>
      <td class="text-end cell-strong"><?= rupiah($it['subtotal']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<hr class="divider-soft">
<div class="d-flex justify-content-between cell-strong" style="font-size:16px"><span>Total</span><span style="color:var(--teal-700)"><?= rupiah($trx['total']) ?></span></div>
<div class="d-flex justify-content-between cell-muted"><span>Bayar</span><span><?= rupiah($trx['bayar']) ?></span></div>
<div class="d-flex justify-content-between cell-muted"><span>Kembali</span><span><?= rupiah($trx['kembali']) ?></span></div>
<div class="mt-3 text-end">
  <button class="btn btn-sm" style="background:#25D366;color:#fff" onclick='kirimWaTrx(<?= json_encode($trx['no_wa']) ?>, <?= json_encode($struk_text) ?>)'>
    <i class="bi bi-whatsapp me-1"></i>Kirim Struk via WhatsApp
  </button>
</div>
<script>
function kirimWaTrx(num, text){
  if(!num){ num = prompt('Nomor WhatsApp tujuan (mis. 08123456789):',''); if(!num) return; }
  num = (''+num).replace(/[^0-9]/g,''); if(num[0]==='0') num='62'+num.slice(1);
  if(num.length < 8){ alert('Nomor WhatsApp tidak valid.'); return; }
  const url = 'https://wa.me/'+num+'?text='+encodeURIComponent(text);
  try { const a=document.createElement('a'); a.href=url; a.target='_blank'; a.rel='noopener'; document.body.appendChild(a); a.click(); a.remove(); return; } catch(e){}
  const w = window.open(url,'_blank'); if(!w){ window.location.href = url; }
}
</script>
