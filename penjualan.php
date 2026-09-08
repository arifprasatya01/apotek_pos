<?php
require_once __DIR__ . '/config/config.php';

require_login();

$page_title = 'Kasir / Penjualan';
$page_crumb = 'Buat transaksi penjualan baru';

/* ---------- Setting & Tarif aktif ---------- */
$ppn = (float) get_setting($conn, 'ppn_persen', 0);

$tarifRes = $conn->query("SELECT * FROM tarif WHERE aktif=1 ORDER BY is_default DESC, nama_tarif");
$tarifList = [];
while ($t = $tarifRes->fetch_assoc()) $tarifList[] = $t;

if (empty($tarifList)) {
    set_flash('danger', 'Belum ada tarif aktif. Buat / aktifkan tarif dulu di menu Tarif & PPN.');
}

$tarifDefaultId = 0;
foreach ($tarifList as $t) {
    if ($t['is_default']) { $tarifDefaultId = (int) $t['id']; break; }
}
if (!$tarifDefaultId && !empty($tarifList)) $tarifDefaultId = (int) $tarifList[0]['id'];

/**
 * Ambil harga jual obat untuk tarif tertentu.
 * Pakai harga yang sudah disimpan di obat_harga kalau ada,
 * kalau belum ada (obat baru / belum di-set), hitung dari margin tarif.
 */
function harga_untuk_tarif(mysqli $conn, int $obat_id, array $tarif, float $harga_beli, float $ppn): int {
    static $cache = [];
    $tid = (int) $tarif['id'];
    if (!isset($cache[$tid])) {
        $cache[$tid] = [];
        $r = $conn->query("SELECT obat_id, metode, margin_persen, harga_jual FROM obat_harga WHERE tarif_id=$tid");
        while ($row = $r->fetch_assoc()) $cache[$tid][(int)$row['obat_id']] = $row;
    }
    if (isset($cache[$tid][$obat_id])) {
        return (int) $cache[$tid][$obat_id]['harga_jual'];
    }
    // Fallback: belum ada baris obat_harga untuk obat ini -> hitung pakai margin tarif
    return hitung_harga_jual($harga_beli, $ppn, (float) $tarif['margin_persen']);
}

/* ---------- Proses checkout ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkout') {
    $items    = json_decode($_POST['items'] ?? '[]', true);
    $bayar    = (int) ($_POST['bayar'] ?? 0);
    $tarif_id = (int) ($_POST['tarif_id'] ?? 0);

    if (!is_array($items) || count($items) === 0) {
        set_flash('danger', 'Keranjang masih kosong.');
        redirect('penjualan.php');
    }

    // Validasi tarif yang dipilih harus salah satu tarif aktif
    $tarifTerpilih = null;
    foreach ($tarifList as $t) {
        if ((int) $t['id'] === $tarif_id) { $tarifTerpilih = $t; break; }
    }
    if (!$tarifTerpilih) {
        set_flash('danger', 'Jenis transaksi (tarif) tidak valid. Silakan pilih ulang.');
        redirect('penjualan.php');
    }

    $conn->begin_transaction();
    try {
        $total = 0; $valid = [];
        // Catatan: harga TIDAK diambil dari obat.harga_jual lagi, tapi dihitung
        // ulang di server berdasarkan tarif_id yang dipilih, supaya tidak bisa
        // dimanipulasi dari sisi browser.
        $get = $conn->prepare('SELECT id,nama_obat,harga_beli,stok FROM obat WHERE id=? FOR UPDATE');
        foreach ($items as $it) {
            $oid = (int) ($it['id'] ?? 0);
            $qty = max(1, (int) ($it['qty'] ?? 0));
            $get->bind_param('i', $oid); $get->execute();
            $o = $get->get_result()->fetch_assoc();
            if (!$o) throw new Exception('Obat tidak ditemukan.');
            if ($o['stok'] < $qty) throw new Exception('Stok ' . $o['nama_obat'] . ' tidak cukup (sisa ' . $o['stok'] . ').');

            $harga = harga_untuk_tarif($conn, $oid, $tarifTerpilih, (float) $o['harga_beli'], $ppn);
            $sub   = $harga * $qty;
            $total += $sub;
            $valid[] = ['id'=>$oid,'nama'=>$o['nama_obat'],'harga'=>$harga,'qty'=>$qty,'sub'=>$sub];
        }
        if ($bayar < $total) throw new Exception('Pembayaran kurang dari total belanja.');
        $kembali = $bayar - $total;

        $no = 'TRX-' . date('Ymd') . '-' . str_pad((string) (
            (int) $conn->query("SELECT COUNT(*)+1 c FROM penjualan WHERE DATE(tanggal)=CURDATE()")->fetch_assoc()['c']
        ), 3, '0', STR_PAD_LEFT);

        $uid = current_user()['id'];
        $now = date('Y-m-d H:i:s');
        $pelanggan = trim($_POST['pelanggan'] ?? '');
        $no_wa = preg_replace('/[^0-9]/', '', $_POST['no_wa'] ?? '');
        if ($no_wa !== '' && $no_wa[0] === '0') $no_wa = '62' . substr($no_wa, 1);
        $pel = $pelanggan !== '' ? $pelanggan : null;
        $wa  = $no_wa !== '' ? $no_wa : null;
        $tarif_nama = $tarifTerpilih['nama_tarif'];

        $stmt = $conn->prepare('INSERT INTO penjualan (no_transaksi,tanggal,user_id,tarif_id,tarif_nama,pelanggan,no_wa,total,bayar,kembali) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmt->bind_param('ssiissiiii', $no, $now, $uid, $tarif_id, $tarif_nama, $pel, $wa, $total, $bayar, $kembali);
        $stmt->execute();
        $pid = $conn->insert_id;

        $det = $conn->prepare('INSERT INTO penjualan_detail (penjualan_id,obat_id,nama_obat,jumlah,harga,subtotal) VALUES (?,?,?,?,?,?)');
        $upd = $conn->prepare('UPDATE obat SET stok = stok - ? WHERE id=?');
        foreach ($valid as $v) {
            $det->bind_param('iisiii', $pid, $v['id'], $v['nama'], $v['qty'], $v['harga'], $v['sub']);
            $det->execute();
            $upd->bind_param('ii', $v['qty'], $v['id']);
            $upd->execute();
        }

        $conn->commit();
        $jml_item = count($valid);
        log_aktivitas($conn, 'penjualan', 'checkout',
            "Transaksi $no, tarif \"$tarif_nama\", $jml_item item, total " . rupiah($total)
            . ($pel ? ", pelanggan $pel" : ''));
        set_flash('success', 'Transaksi ' . $no . ' berhasil disimpan.');
        redirect('penjualan.php?struk=' . $pid);
    } catch (Exception $e) {
        $conn->rollback();
        set_flash('danger', 'Gagal: ' . $e->getMessage());
        redirect('penjualan.php');
    }
}

/* ---------- Daftar kategori (untuk filter di katalog) ---------- */
$kategoriRes = $conn->query("SELECT id, nama_kategori FROM kategori ORDER BY nama_kategori");
$kategoriList = [];
while ($k = $kategoriRes->fetch_assoc()) $kategoriList[] = $k;

/* ---------- Daftar obat siap jual ---------- */
$produk = $conn->query(
    "SELECT o.id,o.nama_obat,o.kode_obat,o.harga_beli,o.stok,o.satuan,o.kategori_id,k.nama_kategori
     FROM obat o LEFT JOIN kategori k ON k.id=o.kategori_id
     WHERE o.stok > 0 ORDER BY o.nama_obat"
);
$produk_js = [];
while ($p = $produk->fetch_assoc()) $produk_js[] = $p;

/* ---------- Harga per tarif (untuk dropdown jenis transaksi) ---------- */
$hargaPerTarif = [];
foreach ($tarifList as $t) {
    $tid = (int) $t['id'];
    $hargaPerTarif[$tid] = [];
    foreach ($produk_js as $p) {
        $hargaPerTarif[$tid][(int) $p['id']] = harga_untuk_tarif($conn, (int) $p['id'], $t, (float) $p['harga_beli'], $ppn);
    }
}

/* ---------- Profil apotek ---------- */
$apotek = [
    'nama'   => get_setting($conn, 'apotek_nama', APP_NAME),
    'alamat' => get_setting($conn, 'apotek_alamat', ''),
    'telp'   => get_setting($conn, 'apotek_telp', ''),
    'wa'     => get_setting($conn, 'apotek_wa', ''),
    'footer' => get_setting($conn, 'struk_footer', 'Terima kasih atas kunjungan Anda 🙏'),
    'logo'   => get_setting($conn, 'logo_file', ''),
];

/* ---------- Data struk ---------- */
$struk = null;
$struk_text = '';
if (isset($_GET['struk'])) {
    $pid = (int) $_GET['struk'];
    $h = $conn->prepare("SELECT p.*, u.nama kasir FROM penjualan p LEFT JOIN users u ON u.id=p.user_id WHERE p.id=?");
    $h->bind_param('i', $pid); $h->execute();
    $struk = $h->get_result()->fetch_assoc();
    if ($struk) {
        $d = $conn->prepare("SELECT * FROM penjualan_detail WHERE penjualan_id=?");
        $d->bind_param('i', $pid); $d->execute();
        $struk['items'] = $d->get_result()->fetch_all(MYSQLI_ASSOC);

        $L = [];
        $L[] = '*' . $apotek['nama'] . '*';
        if ($apotek['alamat']) $L[] = $apotek['alamat'];
        if ($apotek['telp'])   $L[] = 'Telp: ' . $apotek['telp'];
        $L[] = '';
        $L[] = 'Struk : ' . $struk['no_transaksi'];
        $L[] = 'Tgl   : ' . tgl_indo($struk['tanggal'], true);
        $L[] = 'Kasir : ' . ($struk['kasir'] ?? '-');
        if (!empty($struk['tarif_nama'])) $L[] = 'Jenis : ' . $struk['tarif_nama'];
        if (!empty($struk['pelanggan'])) $L[] = 'Pelanggan : ' . $struk['pelanggan'];
        $L[] = '--------------------------------';
        foreach ($struk['items'] as $it) {
            $L[] = $it['nama_obat'];
            $L[] = '  ' . $it['jumlah'] . ' x ' . rupiah($it['harga']) . ' = ' . rupiah($it['subtotal']);
        }
        $L[] = '--------------------------------';
        $L[] = 'Total   : ' . rupiah($struk['total']);
        $L[] = 'Bayar   : ' . rupiah($struk['bayar']);
        $L[] = 'Kembali : ' . rupiah($struk['kembali']);
        $L[] = '';
        $L[] = $apotek['footer'];
        $struk_text = implode("\n", $L);
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($struk): ?>
<!-- ===== CSS STRUK — sembunyikan di layar, tampilkan saat print ===== -->
<style>
/* Sembunyikan printArea di layar biasa */
#printArea {
  position: absolute;
  left: -9999px;
  top: 0;
  width: 48mm;
}

@media print {
  /* Sembunyikan elemen UI lain secara TOTAL (display:none, bukan visibility) */
  .topbar,
  .sidebar,
  .sidebar-overlay,
  .modal-backdrop,
  .alert,
  #appArea,
  .modal {
    display: none !important;
  }

  #printArea {
    display: block !important;
    position: static !important;
    width: 48mm !important;
  }

  #printArea .struk-wrap {
    width: 48mm;
    margin: 0;
    padding: 0;
    font-family: 'Courier New', Courier, monospace;
    font-size: 9pt;
    color: #000;
  }
  #printArea *,
  .struk-logo      { font-weight: 700 !important; }
  .struk-logo      { max-width: 36mm; display: block; margin: 0 auto 3px; }
  .struk-nama      { font-size: 10pt; text-align: center; margin-bottom: 2px; }
  .struk-sub       { font-size: 8pt; text-align: center; margin-bottom: 1px; }
  .struk-garis     { border: none; border-top: 1px dashed #000; margin: 4px 0; }
  .struk-row       { display: flex; justify-content: space-between; font-size: 8.5pt; margin: 1px 0; }
  .struk-total     { font-size: 9.5pt; margin-top: 2px; }
  .struk-item-nama { font-size: 8.5pt; margin-top: 3px; }
  .struk-footer    { text-align: center; font-size: 8pt; margin-top: 5px; }

  @page {
    size: 58mm auto;
    margin: 2mm 1mm;
  }
}
</style>
<?php endif; ?>

<style>
/* Pill filter kategori - radio button bergaya tombol (selalu aktif, bukan cuma saat struk muncul) */
#filterKategori .btn-check:checked + label {
  background: var(--teal-700);
  color: #fff;
  border-color: var(--teal-700);
}
#filterKategori::-webkit-scrollbar { display: none; }
#filterKategori label.btn {
  flex: 0 0 auto;
  white-space: nowrap;
  width: auto;
}
</style>

<!-- Area struk untuk print — tersembunyi di layar, muncul saat print -->
<?php if ($struk): ?>
<div id="printArea">
  <div class="struk-wrap">
    <?php if ($apotek['logo'] && file_exists(__DIR__ . '/' . $apotek['logo'])): ?>
      <img src="<?= e($apotek['logo']) ?>" class="struk-logo" alt="Logo">
    <?php endif; ?>
    <div class="struk-nama"><?= e($apotek['nama']) ?></div>
    <?php if ($apotek['alamat']): ?><div class="struk-sub"><?= e($apotek['alamat']) ?></div><?php endif; ?>
    <?php if ($apotek['telp']): ?><div class="struk-sub">Telp: <?= e($apotek['telp']) ?></div><?php endif; ?>
    <div class="struk-garis"></div>
    <div class="struk-row"><span>No</span><span><?= e($struk['no_transaksi']) ?></span></div>
    <div class="struk-row"><span>Tanggal</span><span><?= tgl_indo($struk['tanggal'], true) ?></span></div>
    <div class="struk-row"><span>Kasir</span><span><?= e($struk['kasir'] ?? '-') ?></span></div>
    <?php if (!empty($struk['tarif_nama'])): ?>
      <div class="struk-row"><span>Jenis</span><span><?= e($struk['tarif_nama']) ?></span></div>
    <?php endif; ?>
    <?php if (!empty($struk['pelanggan'])): ?>
      <div class="struk-row"><span>Pelanggan</span><span><?= e($struk['pelanggan']) ?></span></div>
    <?php endif; ?>
    <div class="struk-garis"></div>
    <?php foreach ($struk['items'] as $it): ?>
      <div class="struk-item-nama"><?= e($it['nama_obat']) ?></div>
      <div class="struk-row">
        <span><?= $it['jumlah'] ?> × <?= rupiah($it['harga']) ?></span>
        <span><?= rupiah($it['subtotal']) ?></span>
      </div>
    <?php endforeach; ?>
    <div class="struk-garis"></div>
    <div class="struk-row struk-total"><span>TOTAL</span><span><?= rupiah($struk['total']) ?></span></div>
    <div class="struk-row"><span>Bayar</span><span><?= rupiah($struk['bayar']) ?></span></div>
    <div class="struk-row"><span>Kembali</span><span><?= rupiah($struk['kembali']) ?></span></div>
    <div class="struk-garis"></div>
    <div class="struk-footer"><?= e($apotek['footer'] ?: 'Terima kasih atas kunjungan Anda 🙏') ?></div>
    <div style="height:20mm"></div><!-- jarak potong kertas -->
  </div>
</div>
<?php endif; ?>

<!-- Halaman kasir normal -->
<div id="appArea">
<div class="pos-grid">
  <!-- Katalog produk -->
  <div class="card-app">
    <div class="card-app__head flex-wrap gap-2">
      <div class="search-box" style="max-width:none;flex:1 1 220px">
        <i class="bi bi-search"></i>
        <input type="text" id="searchProduk" class="form-control" placeholder="Cari obat untuk dijual…">
      </div>
      <div style="min-width:200px">
        <label class="form-label mb-1" style="font-size:12px">Jenis Transaksi</label>
        <select id="selectTarif" class="form-select form-select-sm">
          <?php foreach ($tarifList as $t): ?>
            <option value="<?= (int) $t['id'] ?>" <?= ((int) $t['id'] === $tarifDefaultId) ? 'selected' : '' ?>>
              <?= e($t['nama_tarif']) ?><?= $t['is_default'] ? ' (default)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="card-app__head flex-wrap gap-2" style="padding-top:0">
      <div class="d-flex gap-2" id="filterKategori" style="overflow-x:auto; flex-wrap:nowrap; white-space:nowrap; -webkit-overflow-scrolling:touch; scrollbar-width:none; padding-bottom:2px">
        <input type="radio" class="btn-check" name="filterKategori" id="kat_0" value="0" checked>
        <label class="btn btn-soft btn-sm" style="flex:0 0 auto" for="kat_0">Semua kategori</label>
        <?php foreach ($kategoriList as $k): ?>
          <input type="radio" class="btn-check" name="filterKategori" id="kat_<?= (int) $k['id'] ?>" value="<?= (int) $k['id'] ?>">
          <label class="btn btn-soft btn-sm" style="flex:0 0 auto" for="kat_<?= (int) $k['id'] ?>"><?= e($k['nama_kategori']) ?></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card-app__body">
      <div class="row g-3" id="gridProduk"></div>
      <div id="emptyProduk" class="empty-state d-none"><i class="bi bi-search"></i>Obat tidak ditemukan.</div>
    </div>
  </div>

  <!-- Keranjang -->
  <div class="card-app" style="position:sticky;top:90px">
    <div class="card-app__head">
      <h2 class="card-app__title"><i class="bi bi-cart3 me-2"></i>Keranjang</h2>
      <button class="btn btn-soft btn-sm ms-auto" onclick="clearCart()"><i class="bi bi-trash3 me-1"></i>Kosongkan</button>
    </div>
    <div class="card-app__body">
      <div id="cartItems"></div>
      <div id="cartEmpty" class="empty-state"><i class="bi bi-cart"></i>Klik obat untuk menambahkan</div>

      <hr class="divider-soft">
      <div class="d-flex justify-content-between mb-2">
        <span class="cell-muted">Jumlah item</span><span class="cell-strong" id="totalQty">0</span>
      </div>
      <div class="d-flex justify-content-between align-items-center mb-3">
        <span class="cell-muted">Total</span>
        <span class="display-font" style="font-weight:800;font-size:24px;color:var(--teal-700)" id="totalRp">Rp 0</span>
      </div>

      <label class="form-label">Uang Dibayar</label>
      <div class="input-group mb-2">
        <span class="input-group-text">Rp</span>
        <input type="number" id="inputBayar" class="form-control" placeholder="0" min="0" oninput="hitungKembali()">
      </div>
      <div class="d-flex gap-2 mb-3 flex-wrap">
        <?php foreach ([20000,50000,100000] as $n): ?>
          <button class="btn btn-soft btn-sm" onclick="setBayar(<?= $n ?>)">+<?= number_format($n/1000) ?>rb</button>
        <?php endforeach; ?>
        <button class="btn btn-soft btn-sm" onclick="bayarPas()">Uang pas</button>
      </div>
      <div class="d-flex justify-content-between mb-3 p-2" style="background:var(--teal-50);border-radius:10px">
        <span class="cell-muted">Kembalian</span>
        <span class="cell-strong" id="kembaliRp" style="color:var(--teal-700)">Rp 0</span>
      </div>

      <div class="row g-2 mb-3">
        <div class="col-12">
          <label class="form-label">Pelanggan <span class="cell-muted">(opsional)</span></label>
          <input type="text" id="inputPelanggan" class="form-control" placeholder="Nama pelanggan">
        </div>
        <div class="col-12">
          <label class="form-label">No. WhatsApp <span class="cell-muted">(untuk kirim struk)</span></label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-whatsapp" style="color:#25D366"></i></span>
            <input type="tel" id="inputWa" class="form-control" placeholder="08xxxxxxxxxx">
          </div>
        </div>
      </div>

      <form method="post" id="formCheckout">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="checkout">
        <input type="hidden" name="items" id="fieldItems">
        <input type="hidden" name="bayar" id="fieldBayar">
        <input type="hidden" name="tarif_id" id="fieldTarif">
        <input type="hidden" name="pelanggan" id="fieldPelanggan">
        <input type="hidden" name="no_wa" id="fieldWa">
        <button type="button" class="btn btn-teal w-100 py-2" id="btnBayar" onclick="checkout()" disabled>
          <i class="bi bi-bag-check me-2"></i>Proses Pembayaran
        </button>
      </form>
    </div>
  </div>
</div>
</div><!-- #appArea -->

<!-- Modal struk -->
<?php if ($struk): ?>
<div class="modal fade" id="modalStruk" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body p-0">
        <!-- Preview struk di dalam modal -->
        <div class="struk-wrap" style="margin:0 auto;padding:20px">
          <?php if ($apotek['logo'] && file_exists(__DIR__ . '/' . $apotek['logo'])): ?>
            <img src="<?= e($apotek['logo']) ?>" class="struk-logo" alt="Logo">
          <?php endif; ?>
          <div class="struk-nama"><?= e($apotek['nama']) ?></div>
          <?php if ($apotek['alamat']): ?><div class="struk-sub"><?= e($apotek['alamat']) ?></div><?php endif; ?>
          <?php if ($apotek['telp']): ?><div class="struk-sub">Telp: <?= e($apotek['telp']) ?></div><?php endif; ?>
          <div class="struk-garis"></div>
          <div class="struk-row"><span>No</span><span><?= e($struk['no_transaksi']) ?></span></div>
          <div class="struk-row"><span>Tanggal</span><span><?= tgl_indo($struk['tanggal'], true) ?></span></div>
          <div class="struk-row"><span>Kasir</span><span><?= e($struk['kasir'] ?? '-') ?></span></div>
          <?php if (!empty($struk['tarif_nama'])): ?>
            <div class="struk-row"><span>Jenis</span><span><?= e($struk['tarif_nama']) ?></span></div>
          <?php endif; ?>
          <?php if (!empty($struk['pelanggan'])): ?>
            <div class="struk-row"><span>Pelanggan</span><span><?= e($struk['pelanggan']) ?></span></div>
          <?php endif; ?>
          <div class="struk-garis"></div>
          <?php foreach ($struk['items'] as $it): ?>
            <div class="struk-item-nama"><?= e($it['nama_obat']) ?></div>
            <div class="struk-row">
              <span><?= $it['jumlah'] ?> × <?= rupiah($it['harga']) ?></span>
              <span><?= rupiah($it['subtotal']) ?></span>
            </div>
          <?php endforeach; ?>
          <div class="struk-garis"></div>
          <div class="struk-row struk-total"><span>TOTAL</span><span><?= rupiah($struk['total']) ?></span></div>
          <div class="struk-row"><span>Bayar</span><span><?= rupiah($struk['bayar']) ?></span></div>
          <div class="struk-row"><span>Kembali</span><span><?= rupiah($struk['kembali']) ?></span></div>
          <div class="struk-garis"></div>
          <div class="struk-footer"><?= e($apotek['footer'] ?: 'Terima kasih atas kunjungan Anda 🙏') ?></div>
        </div>
      </div>
      <div class="modal-footer">
        <a href="penjualan" class="btn btn-soft"><i class="bi bi-plus me-1"></i>Transaksi Baru</a>
        <button class="btn" style="background:#25D366;color:#fff" onclick="kirimWA()">
          <i class="bi bi-whatsapp me-1"></i>Kirim WA
        </button>
        <button class="btn btn-soft" onclick="cetakPC()" title="Cetak via dialog print Windows">
          <i class="bi bi-printer me-1"></i>Cetak PC
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$produk_json       = json_encode($produk_js);
$harga_tarif_json  = json_encode($hargaPerTarif);
$tarif_default_id  = $tarifDefaultId;
$auto_struk        = $struk ? "new bootstrap.Modal('#modalStruk').show();" : "";
$wa_text_json      = $struk ? json_encode($struk_text) : 'null';
$wa_num_json       = $struk ? json_encode($struk['no_wa']) : 'null';

$extra_js = <<<JS
<script>
const PRODUK      = {$produk_json};
const HARGA_TARIF = {$harga_tarif_json}; // { tarif_id: { obat_id: harga_jual } }
const WA_TEXT     = {$wa_text_json};
const WA_NUM      = {$wa_num_json};

let currentTarif = {$tarif_default_id};
let cart = {};

function hargaProduk(id){
  const map = HARGA_TARIF[currentTarif] || {};
  return map[id] !== undefined ? +map[id] : 0;
}

/* ===== Ganti jenis transaksi (tarif) ===== */
document.getElementById('selectTarif').addEventListener('change', function(){
  currentTarif = +this.value;
  // Sinkronkan ulang harga item yang sudah ada di keranjang ke tarif baru
  Object.keys(cart).forEach(id => {
    cart[id].harga = hargaProduk(+id);
  });
  renderGrid(document.getElementById('searchProduk').value);
  renderCart();
});

/* ===== Grid produk ===== */
function renderGrid(filter=''){
  const grid = document.getElementById('gridProduk');
  const q = filter.toLowerCase();
  const katChecked = document.querySelector('input[name="filterKategori"]:checked');
  const katId = katChecked ? +katChecked.value : 0;
  const list = PRODUK.filter(p => {
    const cocokNama = (p.nama_obat+' '+p.kode_obat).toLowerCase().includes(q);
    const cocokKategori = !katId || +p.kategori_id === katId;
    return cocokNama && cocokKategori;
  });
  document.getElementById('emptyProduk').classList.toggle('d-none', list.length>0);
  grid.innerHTML = list.map(p => {
    const inCart = cart[p.id] ? cart[p.id].qty : 0;
    const habis = inCart >= p.stok;
    const harga = hargaProduk(p.id);
    return `<div class="col-6 col-md-4 col-xl-3">
      <div class="product-tile \${habis?'disabled':''}" onclick="addToCart(\${p.id})">
        <div class="product-tile__name mb-1">\${p.nama_obat}</div>
        <div class="cell-muted mb-2" style="font-size:11.5px">\${p.nama_kategori||'-'} · stok \${p.stok}</div>
        <div class="product-tile__price">Rp \${harga.toLocaleString('id-ID')}</div>
      </div></div>`;
  }).join('');
}

function addToCart(id){
  const p = PRODUK.find(x=>x.id==id); if(!p) return;
  const cur = cart[id] ? cart[id].qty : 0;
  if(cur+1 > p.stok) return;
  cart[id] = {id:p.id,nama:p.nama_obat,harga:hargaProduk(id),qty:cur+1,stok:+p.stok};
  renderCart(); renderGrid(document.getElementById('searchProduk').value);
}
function changeQty(id,delta){
  if(!cart[id]) return;
  const next = cart[id].qty+delta;
  if(next<=0) delete cart[id];
  else if(next>cart[id].stok) return;
  else cart[id].qty=next;
  renderCart(); renderGrid(document.getElementById('searchProduk').value);
}
function clearCart(){ cart={}; renderCart(); renderGrid(document.getElementById('searchProduk').value); }

function renderCart(){
  const box=document.getElementById('cartItems');
  const items=Object.values(cart);
  document.getElementById('cartEmpty').style.display=items.length?'none':'block';
  box.innerHTML=items.map(it=>`
    <div class="cart-line">
      <div class="flex-grow-1" style="min-width:0">
        <div class="cell-strong text-truncate" style="font-size:13.5px">\${it.nama}</div>
        <div class="cell-muted">Rp \${it.harga.toLocaleString('id-ID')} × \${it.qty} = <b>Rp \${(it.harga*it.qty).toLocaleString('id-ID')}</b></div>
      </div>
      <div class="d-flex align-items-center gap-1">
        <button class="qty-btn" onclick="changeQty(\${it.id},-1)"><i class="bi bi-dash"></i></button>
        <span style="width:24px;text-align:center;font-weight:600">\${it.qty}</span>
        <button class="qty-btn" onclick="changeQty(\${it.id},1)"><i class="bi bi-plus"></i></button>
      </div>
    </div>`).join('');
  const totalQty=items.reduce((a,b)=>a+b.qty,0);
  const total=items.reduce((a,b)=>a+b.harga*b.qty,0);
  document.getElementById('totalQty').textContent=totalQty;
  document.getElementById('totalRp').textContent='Rp '+total.toLocaleString('id-ID');
  hitungKembali();
  document.getElementById('btnBayar').disabled=items.length===0;
}

function currentTotal(){ return Object.values(cart).reduce((a,b)=>a+b.harga*b.qty,0); }
function hitungKembali(){
  const bayar=+document.getElementById('inputBayar').value||0;
  const k=bayar-currentTotal();
  const el=document.getElementById('kembaliRp');
  el.textContent='Rp '+(k>=0?k:0).toLocaleString('id-ID');
  el.style.color=k<0?'var(--rose)':'var(--teal-700)';
}
function setBayar(n){ const i=document.getElementById('inputBayar'); i.value=(+i.value||0)+n; hitungKembali(); }
function bayarPas(){ document.getElementById('inputBayar').value=currentTotal(); hitungKembali(); }

function checkout(){
  const items=Object.values(cart).map(x=>({id:x.id,qty:x.qty}));
  if(!items.length) return;
  const bayar=+document.getElementById('inputBayar').value||0;
  if(bayar<currentTotal()){ alert('Pembayaran kurang dari total belanja.'); return; }
  document.getElementById('fieldItems').value=JSON.stringify(items);
  document.getElementById('fieldBayar').value=bayar;
  document.getElementById('fieldTarif').value=currentTarif;
  document.getElementById('fieldPelanggan').value=document.getElementById('inputPelanggan').value;
  document.getElementById('fieldWa').value=document.getElementById('inputWa').value;
  document.getElementById('formCheckout').submit();
}

/* ===== WhatsApp ===== */
function kirimWA(){
  let num=WA_NUM;
  if(!num){ num=prompt('Nomor WhatsApp tujuan:',''); if(!num) return; }
  num=(''+num).replace(/[^0-9]/g,'');
  if(num[0]==='0') num='62'+num.slice(1);
  if(num.length<8){ alert('Nomor tidak valid.'); return; }
  const url='https://wa.me/'+num+'?text='+encodeURIComponent(WA_TEXT);
  const a=document.createElement('a'); a.href=url; a.target='_blank'; a.rel='noopener';
  document.body.appendChild(a); a.click(); a.remove();
}

/* ===== CETAK PC — print langsung halaman ini ===== */
/* CSS @media print di atas sudah mengatur supaya saat print,    */
/* hanya #printArea (struk) yang tampil, sisanya disembunyikan.  */
/* Kalau Chrome dijalankan dengan flag --kiosk-printing dan      */
/* printer RPP02N sudah jadi default printer, ini akan langsung  */
/* cetak ke struk tanpa dialog/preview sama sekali.              */
function cetakPC(){
  window.print();
}

document.getElementById('searchProduk').addEventListener('input', e=>renderGrid(e.target.value));
document.querySelectorAll('input[name="filterKategori"]').forEach(r => {
  r.addEventListener('change', ()=>renderGrid(document.getElementById('searchProduk').value));
});
renderGrid(); renderCart();
{$auto_struk}
</script>
JS;
require_once __DIR__ . '/includes/footer.php';