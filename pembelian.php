<?php
require_once __DIR__ . '/config/config.php';
require_login();

$page_title = 'Pembelian Obat';
$page_crumb = 'Stok masuk dari supplier';

$ppn_default = (float) get_setting($conn, 'ppn_persen', 11);

/* ---------------------------------------------------------------
 |  Simpan pembelian baru (POS) — diskon per item + PPN manual
 * ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'simpan') {
    $supplier_id = (int) ($_POST['supplier_id'] ?? 0) ?: null;
    $no_faktur   = trim($_POST['no_faktur'] ?? '');
    $tanggal     = $_POST['tanggal'] ?? date('Y-m-d');
    $jatuh_tempo = trim($_POST['jatuh_tempo'] ?? '');
    $catatan     = trim($_POST['catatan'] ?? '');
    $ppn_persen  = (float) ($_POST['ppn_persen'] ?? $ppn_default);
    $items_raw   = json_decode($_POST['items'] ?? '[]', true);

    $items = [];
    if (is_array($items_raw)) {
        foreach ($items_raw as $it) {
            $oid = (int) ($it['id'] ?? 0);
            $qty = (int) ($it['qty'] ?? 0);
            $hrg = (int) ($it['harga'] ?? 0);
            $dsk = (float) ($it['diskon'] ?? 0);
            $exp = trim($it['exp'] ?? '');
            if ($dsk < 0) $dsk = 0; if ($dsk > 100) $dsk = 100;
            if ($oid > 0 && $qty > 0 && $hrg >= 0) {
                $items[] = ['obat_id'=>$oid,'jumlah'=>$qty,'harga'=>$hrg,'diskon'=>$dsk,'exp'=>$exp];
            }
        }
    }

    if (empty($items)) {
        set_flash('danger', 'Keranjang pembelian masih kosong.');
        redirect('pembelian.php');
    }
    if ($ppn_persen < 0) $ppn_persen = 0;

    $conn->begin_transaction();
    try {
        $getObat = $conn->prepare('SELECT id, nama_obat FROM obat WHERE id=? FOR UPDATE');
        $subtotal = 0;
        foreach ($items as $k => $it) {
            $getObat->bind_param('i', $it['obat_id']);
            $getObat->execute();
            $o = $getObat->get_result()->fetch_assoc();
            if (!$o) throw new Exception('Obat tidak ditemukan (id ' . $it['obat_id'] . ').');
            $items[$k]['nama']     = $o['nama_obat'];
            // subtotal item = harga × jumlah × (1 - diskon%)
            $items[$k]['subtotal'] = (int) round($it['harga'] * $it['jumlah'] * (1 - $it['diskon'] / 100));
            // harga beli net per satuan (setelah diskon, sebelum PPN) untuk dasar harga jual
            $items[$k]['hb_net']   = (int) round($it['harga'] * (1 - $it['diskon'] / 100));
            $subtotal += $items[$k]['subtotal'];
        }
        $ppn_nominal = (int) round($subtotal * $ppn_persen / 100);
        $total = $subtotal + $ppn_nominal;

        $no = 'BELI-' . date('Ymd') . '-' . str_pad((string) (
            (int) $conn->query("SELECT COUNT(*)+1 c FROM pembelian WHERE DATE(tanggal)=CURDATE()")->fetch_assoc()['c']
        ), 3, '0', STR_PAD_LEFT);

        $tgl_dt = $tanggal . ' ' . date('H:i:s');
        $uid    = current_user()['id'];
        $jt     = $jatuh_tempo !== '' ? $jatuh_tempo : null;
        $fk     = $no_faktur !== '' ? $no_faktur : null;
        $cat    = $catatan !== '' ? $catatan : null;

        $ins = $conn->prepare(
            'INSERT INTO pembelian (no_pembelian,no_faktur,tanggal,tanggal_jatuh_tempo,supplier_id,user_id,total,subtotal,ppn_persen,ppn_nominal,catatan)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $ins->bind_param('ssssiiiidis', $no, $fk, $tgl_dt, $jt, $supplier_id, $uid, $total, $subtotal, $ppn_persen, $ppn_nominal, $cat);
        $ins->execute();
        $pembelian_id = $conn->insert_id;

        $det = $conn->prepare(
            'INSERT INTO pembelian_detail (pembelian_id,obat_id,nama_obat,jumlah,harga_beli,diskon_persen,subtotal,tanggal_kadaluarsa)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $updStok = $conn->prepare('UPDATE obat SET stok = stok + ?, harga_beli = ? WHERE id=?');
        $updExp  = $conn->prepare('UPDATE obat SET tanggal_kadaluarsa = ? WHERE id=?');

        foreach ($items as $it) {
            $exp = $it['exp'] !== '' ? $it['exp'] : null;
            $det->bind_param('iisiidis', $pembelian_id, $it['obat_id'], $it['nama'], $it['jumlah'], $it['harga'], $it['diskon'], $it['subtotal'], $exp);
            $det->execute();
            // harga_beli obat diperbarui ke harga net (setelah diskon, belum PPN)
            $updStok->bind_param('iii', $it['jumlah'], $it['hb_net'], $it['obat_id']);
            $updStok->execute();
            if ($exp !== null) {
                $updExp->bind_param('si', $exp, $it['obat_id']);
                $updExp->execute();
            }
        }

        $conn->commit();
        log_aktivitas($conn, 'pembelian', 'tambah', "Pembelian $no dari supplier id $supplier_id, " . count($items) . ' item, total ' . rupiah($total));
        set_flash('success', "Pembelian $no tersimpan. Stok berhasil ditambahkan.");
    } catch (Exception $e) {
        $conn->rollback();
        set_flash('danger', 'Gagal menyimpan pembelian: ' . $e->getMessage());
    }
    redirect('pembelian.php');
}

/* ---------------------------------------------------------------
 |  Hapus pembelian (admin) — kurangi stok kembali
 * ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && has_role('admin')) {
    $id = (int) $_POST['id'];
    $old = $conn->query("SELECT no_pembelian FROM pembelian WHERE id=$id")->fetch_assoc();
    $conn->begin_transaction();
    try {
        $d = $conn->query("SELECT obat_id, jumlah FROM pembelian_detail WHERE pembelian_id=$id");
        while ($row = $d->fetch_assoc()) {
            if ($row['obat_id']) {
                $conn->query("UPDATE obat SET stok = GREATEST(0, stok - {$row['jumlah']}) WHERE id={$row['obat_id']}");
            }
        }
        $conn->query("DELETE FROM pembelian WHERE id=$id");
        $conn->commit();
        set_flash('success', 'Pembelian dihapus & stok disesuaikan.');
        if ($old) log_aktivitas($conn, 'pembelian', 'hapus', "Hapus pembelian {$old['no_pembelian']} & stok disesuaikan kembali");
    } catch (Exception $e) {
        $conn->rollback();
        set_flash('danger', 'Gagal menghapus pembelian.');
    }
    redirect('pembelian.php');
}

require_once __DIR__ . '/includes/header.php';

/* ---------------------------------------------------------------
 |  Data untuk form & daftar
 * ------------------------------------------------------------- */
$suppliers = $conn->query('SELECT id, nama_supplier FROM supplier ORDER BY nama_supplier');

$obatRes = $conn->query(
    "SELECT o.id,o.kode_obat,o.nama_obat,o.satuan,o.harga_beli,o.stok,k.nama_kategori
     FROM obat o LEFT JOIN kategori k ON k.id=o.kategori_id ORDER BY o.nama_obat"
);
$obatJs = [];
while ($o = $obatRes->fetch_assoc()) {
    $obatJs[] = [
        'id'=>(int)$o['id'],'kode'=>$o['kode_obat'],'nama'=>$o['nama_obat'],
        'satuan'=>$o['satuan'],'kategori'=>$o['nama_kategori'] ?? '-',
        'harga'=>(int)$o['harga_beli'],'stok'=>(int)$o['stok'],
    ];
}
$obatJson = json_encode($obatJs, JSON_UNESCAPED_UNICODE);

$dari   = $_GET['dari']   ?? date('Y-m-d');
$sampai = $_GET['sampai'] ?? date('Y-m-d');

$stmt = $conn->prepare(
    "SELECT p.*, s.nama_supplier, u.nama petugas, COUNT(pd.id) jml_item
     FROM pembelian p
     LEFT JOIN supplier s ON s.id=p.supplier_id
     LEFT JOIN users u ON u.id=p.user_id
     LEFT JOIN pembelian_detail pd ON pd.pembelian_id=p.id
     WHERE DATE(p.tanggal) BETWEEN ? AND ?
     GROUP BY p.id ORDER BY p.tanggal DESC, p.id DESC"
);
$stmt->bind_param('ss', $dari, $sampai);
$stmt->execute();
$rows = $stmt->get_result();

$sum = $conn->prepare("SELECT COALESCE(SUM(total),0) t, COUNT(*) c FROM pembelian WHERE DATE(tanggal) BETWEEN ? AND ?");
$sum->bind_param('ss', $dari, $sampai); $sum->execute();
$ringkas = $sum->get_result()->fetch_assoc();

$is_today = ($dari === date('Y-m-d') && $sampai === date('Y-m-d'));
?>

<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="stat">
    <div class="stat__icon ic-amber"><i class="bi bi-bag-check"></i></div>
    <p class="stat__label"><?= $is_today ? 'Belanja Hari Ini' : 'Total Belanja Periode' ?></p>
    <p class="stat__value"><?= rupiah_singkat($ringkas['t']) ?></p>
  </div></div>
  <div class="col-md-4"><div class="stat">
    <div class="stat__icon ic-sky"><i class="bi bi-clipboard-check"></i></div>
    <p class="stat__label">Jumlah Pembelian</p>
    <p class="stat__value"><?= number_format($ringkas['c'],0,',','.') ?></p>
  </div></div>
  <div class="col-md-4"><div class="stat">
    <div class="stat__icon ic-violet"><i class="bi bi-truck"></i></div>
    <p class="stat__label">Rata-rata / Pembelian</p>
    <p class="stat__value"><?= rupiah_singkat($ringkas['c'] ? $ringkas['t']/$ringkas['c'] : 0) ?></p>
  </div></div>
</div>

<!-- ====================== PANEL TAMBAH PEMBELIAN (POS) ====================== -->
<div class="card-app mb-4 d-none" id="panelBeli">
  <div class="card-app__head">
    <h2 class="card-app__title"><i class="bi bi-cart-plus me-2"></i>Tambah Pembelian</h2>
    <button class="btn btn-soft btn-sm ms-auto" type="button" id="btnTutup"><i class="bi bi-x-lg me-1"></i>Tutup</button>
  </div>
  <div class="card-app__body">
    <div class="row g-3 mb-3">
      <div class="col-md-3">
        <label class="form-label">Supplier</label>
        <select id="supplier_id" class="form-select">
          <option value="">— Pilih supplier —</option>
          <?php while ($s = $suppliers->fetch_assoc()): ?>
            <option value="<?= (int)$s['id'] ?>"><?= e($s['nama_supplier']) ?></option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">No. Faktur</label>
        <input type="text" id="no_faktur" class="form-control" placeholder="mis. INV-00123">
      </div>
      <div class="col-md-2">
        <label class="form-label">Tanggal Beli</label>
        <input type="date" id="tanggal" class="form-control" value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">Jatuh Tempo</label>
        <input type="date" id="jatuh_tempo" class="form-control">
      </div>
      <div class="col-md-2">
        <label class="form-label">PPN (%)</label>
        <input type="number" step="0.01" min="0" id="ppn_persen" class="form-control" value="<?= e($ppn_default) ?>" oninput="renderCart()">
      </div>
    </div>

    <div class="pos-grid">
      <div>
        <div class="search-box w-100 mb-3" style="max-width:none">
          <i class="bi bi-search"></i>
          <input type="text" id="searchObat" class="form-control" placeholder="Cari obat untuk dibeli…">
        </div>
        <div class="row g-3" id="gridObat" style="max-height:460px;overflow:auto"></div>
        <div id="emptyObat" class="empty-state d-none"><i class="bi bi-search"></i>Obat tidak ditemukan.</div>
      </div>

      <div class="card-app" style="position:sticky;top:90px">
        <div class="card-app__head">
          <h2 class="card-app__title"><i class="bi bi-basket me-2"></i>Keranjang</h2>
          <button class="btn btn-soft btn-sm ms-auto" type="button" onclick="clearCart()"><i class="bi bi-trash3 me-1"></i>Kosongkan</button>
        </div>
        <div class="card-app__body">
          <div id="cartItems"></div>
          <div id="cartEmpty" class="empty-state"><i class="bi bi-basket"></i>Klik obat untuk menambah</div>

          <hr class="divider-soft">
          <div class="d-flex justify-content-between mb-1">
            <span class="cell-muted">Subtotal</span><span class="cell-strong" id="sumSubtotal">Rp 0</span>
          </div>
          <div class="d-flex justify-content-between mb-1">
            <span class="cell-muted">PPN (<span id="sumPpnPersen">11</span>%)</span><span class="cell-strong" id="sumPpn">Rp 0</span>
          </div>
          <div class="d-flex justify-content-between align-items-center mb-3">
            <span class="cell-muted">Total</span>
            <span class="display-font" style="font-weight:800;font-size:22px;color:var(--teal-700)" id="grandTotal">Rp 0</span>
          </div>

          <label class="form-label">Catatan (opsional)</label>
          <input type="text" id="catatan" class="form-control mb-3" placeholder="Catatan pembelian…">

          <form method="post" id="formBeli">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="simpan">
            <input type="hidden" name="items" id="f_items">
            <input type="hidden" name="supplier_id" id="f_supplier">
            <input type="hidden" name="no_faktur" id="f_faktur">
            <input type="hidden" name="tanggal" id="f_tanggal">
            <input type="hidden" name="jatuh_tempo" id="f_jt">
            <input type="hidden" name="ppn_persen" id="f_ppn">
            <input type="hidden" name="catatan" id="f_catatan">
            <button type="button" class="btn btn-teal w-100 py-2" id="btnSimpan" onclick="simpanBeli()" disabled>
              <i class="bi bi-save me-2"></i>Simpan Pembelian
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ====================== DAFTAR PEMBELIAN ====================== -->
<div class="card-app">
  <div class="card-app__head flex-wrap gap-2">
    <h2 class="card-app__title">
      <i class="bi bi-clock-history me-2"></i>
      <?= $is_today ? 'Pembelian Hari Ini' : 'Daftar Pembelian' ?>
    </h2>
    <button class="btn btn-teal btn-sm" type="button" id="btnTambah"><i class="bi bi-plus-lg me-1"></i>Tambah Pembelian</button>
    <form method="get" class="d-flex align-items-end gap-2 flex-wrap ms-auto">
      <div><label class="form-label mb-1">Dari</label><input type="date" name="dari" class="form-control form-control-sm" value="<?= e($dari) ?>"></div>
      <div><label class="form-label mb-1">Sampai</label><input type="date" name="sampai" class="form-control form-control-sm" value="<?= e($sampai) ?>"></div>
      <button class="btn btn-soft btn-sm"><i class="bi bi-funnel me-1"></i>Filter</button>
    </form>
  </div>

  <div class="table-responsive">
    <table class="table-app" id="tblBeli">
      <thead><tr>
        <th>No. Pembelian</th><th>Faktur</th><th>Tanggal</th><th>Jatuh Tempo</th><th>Supplier</th>
        <th class="text-end">Item</th><th class="text-end">Total</th><th class="text-end">Aksi</th>
      </tr></thead>
      <tbody>
      <?php if ($rows->num_rows): while ($r = $rows->fetch_assoc()):
        $jt = $r['tanggal_jatuh_tempo'] ?? null;
        $jt_pill = 'pill-gray';
        if ($jt) {
            $hari = (strtotime($jt) - strtotime(date('Y-m-d'))) / 86400;
            $jt_pill = $hari < 0 ? 'pill-rose' : ($hari <= 7 ? 'pill-amber' : 'pill-teal');
        }
      ?>
        <tr>
          <td class="cell-strong"><?= e($r['no_pembelian']) ?></td>
          <td class="cell-muted"><?= e($r['no_faktur'] ?? '-') ?></td>
          <td class="cell-muted"><?= tgl_indo($r['tanggal'], true) ?></td>
          <td><?php if ($jt): ?><span class="pill <?= $jt_pill ?>"><i class="bi bi-calendar-event"></i><?= tgl_indo($jt) ?></span><?php else: ?><span class="cell-muted">-</span><?php endif; ?></td>
          <td><span class="pill pill-gray"><i class="bi bi-truck"></i><?= e($r['nama_supplier'] ?? '-') ?></span></td>
          <td class="text-end cell-muted"><?= (int)$r['jml_item'] ?> item</td>
          <td class="text-end cell-strong"><?= rupiah($r['total']) ?></td>
          <td class="text-end">
            <button class="btn btn-soft btn-sm btn-icon" title="Detail" onclick="lihatDetail(<?= (int)$r['id'] ?>)"><i class="bi bi-eye"></i></button>
            <?php if (has_role('admin')): ?>
            <form method="post" class="d-inline js-confirm-delete" data-name="pembelian <?= e($r['no_pembelian']) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-icon" style="background:var(--rose-bg);color:var(--rose)"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endwhile; else: ?>
        <tr><td colspan="8"><div class="empty-state"><i class="bi bi-cart"></i>Belum ada pembelian pada periode ini.</div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Popup input item -->
<div class="modal fade" id="modalItem" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="itemNama">Obat</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="itemId">
        <div class="mb-2">
          <label class="form-label">Harga Beli (per satuan)</label>
          <div class="input-group">
            <span class="input-group-text">Rp</span>
            <input type="number" id="itemHarga" class="form-control" min="0" value="0" oninput="hitungPopup()">
          </div>
        </div>
        <div class="row g-2">
          <div class="col-6 mb-2">
            <label class="form-label">Jumlah</label>
            <input type="number" id="itemQty" class="form-control" min="1" value="1" oninput="hitungPopup()">
          </div>
          <div class="col-6 mb-2">
            <label class="form-label">Diskon (%)</label>
            <input type="number" id="itemDiskon" class="form-control" min="0" max="100" step="0.01" value="0" oninput="hitungPopup()">
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label">Tanggal Kadaluarsa</label>
          <input type="date" id="itemExp" class="form-control">
        </div>
        <div class="d-flex justify-content-between p-2" style="background:var(--teal-50);border-radius:10px">
          <span class="cell-muted">Subtotal item</span>
          <span class="cell-strong" id="itemSubtotal" style="color:var(--teal-700)">Rp 0</span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-teal" id="btnAddItem"><i class="bi bi-plus-lg me-1"></i>Tambah</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal detail -->
<div class="modal fade" id="modalDetail" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Detail Pembelian</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="detailBody">
        <div class="text-center py-4"><div class="spinner-border text-secondary"></div></div>
      </div>
    </div>
  </div>
</div>

<?php $extra_js = <<<JS
<script>
const OBAT = $obatJson;
let cart = {};
let modalItem;

function rupiah(n){ return 'Rp ' + Math.round(n||0).toLocaleString('id-ID'); }
function netItem(it){ return Math.round(it.harga * it.qty * (1 - it.diskon/100)); }

/* ---------- Toggle panel ---------- */
document.getElementById('btnTambah').addEventListener('click', () => {
  document.getElementById('panelBeli').classList.remove('d-none');
  document.getElementById('panelBeli').scrollIntoView({behavior:'smooth'});
});
document.getElementById('btnTutup').addEventListener('click', () => {
  document.getElementById('panelBeli').classList.add('d-none');
});

/* ---------- Grid ---------- */
function renderGrid(filter=''){
  const grid = document.getElementById('gridObat');
  const q = filter.toLowerCase();
  const list = OBAT.filter(p => (p.nama+' '+p.kode).toLowerCase().includes(q));
  document.getElementById('emptyObat').classList.toggle('d-none', list.length>0);
  grid.innerHTML = list.map(p => {
    const dipilih = cart[p.id] ? '<div class="cell-muted" style="font-size:11px;color:var(--teal-700)">✓ di keranjang ('+cart[p.id].qty+')</div>' : '';
    return `<div class="col-6 col-xl-4">
      <div class="product-tile" onclick="bukaPopup(\${p.id})">
        <div class="product-tile__name mb-1">\${p.nama}</div>
        <div class="cell-muted mb-2" style="font-size:11.5px">\${p.kategori} · stok \${p.stok} \${p.satuan}</div>
        <div class="product-tile__price">Beli: Rp \${p.harga.toLocaleString('id-ID')}</div>
        \${dipilih}
      </div></div>`;
  }).join('');
}

/* ---------- Popup ---------- */
function hitungPopup(){
  const hrg = parseFloat(document.getElementById('itemHarga').value)||0;
  const qty = parseInt(document.getElementById('itemQty').value)||0;
  const dsk = parseFloat(document.getElementById('itemDiskon').value)||0;
  document.getElementById('itemSubtotal').textContent = rupiah(hrg*qty*(1-dsk/100));
}
function bukaPopup(id){
  const p = OBAT.find(x=>x.id==id); if(!p) return;
  const ada = cart[id];
  document.getElementById('itemId').value = id;
  document.getElementById('itemNama').textContent = p.nama;
  document.getElementById('itemHarga').value  = ada ? ada.harga  : p.harga;
  document.getElementById('itemQty').value    = ada ? ada.qty    : 1;
  document.getElementById('itemDiskon').value = ada ? ada.diskon : 0;
  document.getElementById('itemExp').value    = ada ? ada.exp    : '';
  hitungPopup();
  modalItem.show();
}
document.getElementById('btnAddItem').addEventListener('click', () => {
  const id  = +document.getElementById('itemId').value;
  const p   = OBAT.find(x=>x.id==id); if(!p) return;
  const hrg = parseInt(document.getElementById('itemHarga').value) || 0;
  const qty = parseInt(document.getElementById('itemQty').value) || 0;
  let   dsk = parseFloat(document.getElementById('itemDiskon').value) || 0;
  const exp = document.getElementById('itemExp').value || '';
  if(qty < 1){ alert('Jumlah minimal 1.'); return; }
  if(dsk < 0) dsk = 0; if(dsk > 100) dsk = 100;
  cart[id] = { id:p.id, nama:p.nama, harga:hrg, qty:qty, diskon:dsk, exp:exp };
  modalItem.hide();
  renderCart(); renderGrid(document.getElementById('searchObat').value);
});

/* ---------- Keranjang ---------- */
function hapusItem(id){ delete cart[id]; renderCart(); renderGrid(document.getElementById('searchObat').value); }
function clearCart(){ cart={}; renderCart(); renderGrid(document.getElementById('searchObat').value); }

function renderCart(){
  const box = document.getElementById('cartItems');
  const items = Object.values(cart);
  document.getElementById('cartEmpty').style.display = items.length? 'none':'block';
  box.innerHTML = items.map(it=>{
    const net = netItem(it);
    const badgeDsk = it.diskon>0 ? ' <span class="pill pill-amber" style="font-size:10px">-'+it.diskon+'%</span>' : '';
    return `<div class="cart-line">
      <div class="flex-grow-1" style="min-width:0">
        <div class="cell-strong text-truncate" style="font-size:13.5px">\${it.nama}\${badgeDsk}</div>
        <div class="cell-muted">\${it.qty} × Rp \${it.harga.toLocaleString('id-ID')} = <b>Rp \${net.toLocaleString('id-ID')}</b></div>
        \${it.exp ? '<div class="cell-muted" style="font-size:11px">ED: '+it.exp+'</div>' : ''}
      </div>
      <div class="d-flex align-items-center gap-1">
        <button class="qty-btn" type="button" onclick="bukaPopup(\${it.id})" title="Ubah"><i class="bi bi-pencil"></i></button>
        <button class="qty-btn" type="button" onclick="hapusItem(\${it.id})" title="Hapus"><i class="bi bi-x"></i></button>
      </div>
    </div>`;
  }).join('');

  const subtotal = items.reduce((a,b)=>a+netItem(b),0);
  const ppnP = parseFloat(document.getElementById('ppn_persen').value)||0;
  const ppn  = Math.round(subtotal * ppnP/100);
  const total = subtotal + ppn;
  document.getElementById('sumSubtotal').textContent = rupiah(subtotal);
  document.getElementById('sumPpnPersen').textContent = ppnP;
  document.getElementById('sumPpn').textContent = rupiah(ppn);
  document.getElementById('grandTotal').textContent = rupiah(total);
  document.getElementById('btnSimpan').disabled = items.length===0;
}

/* ---------- Simpan ---------- */
function simpanBeli(){
  const items = Object.values(cart).map(x=>({id:x.id, qty:x.qty, harga:x.harga, diskon:x.diskon, exp:x.exp}));
  if(items.length===0){ alert('Keranjang masih kosong.'); return; }
  document.getElementById('f_items').value    = JSON.stringify(items);
  document.getElementById('f_supplier').value = document.getElementById('supplier_id').value;
  document.getElementById('f_faktur').value   = document.getElementById('no_faktur').value;
  document.getElementById('f_tanggal').value  = document.getElementById('tanggal').value;
  document.getElementById('f_jt').value       = document.getElementById('jatuh_tempo').value;
  document.getElementById('f_ppn').value      = document.getElementById('ppn_persen').value;
  document.getElementById('f_catatan').value  = document.getElementById('catatan').value;
  document.getElementById('formBeli').submit();
}

/* ---------- Detail ---------- */
async function lihatDetail(id){
  const modal = new bootstrap.Modal('#modalDetail'); modal.show();
  const body = document.getElementById('detailBody');
  body.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-secondary"></div></div>';
  try{ const res = await fetch('pembelian_detail?id='+id); body.innerHTML = await res.text(); }
  catch(e){ body.innerHTML = '<div class="empty-state">Gagal memuat detail.</div>'; }
}

document.getElementById('searchObat').addEventListener('input', e=>renderGrid(e.target.value));
document.addEventListener('DOMContentLoaded', () => {
  modalItem = new bootstrap.Modal('#modalItem');
  renderGrid(); renderCart();
});
</script>
JS;
require_once __DIR__ . '/includes/footer.php';
