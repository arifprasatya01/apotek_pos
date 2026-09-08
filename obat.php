<?php
require_once __DIR__ . '/config/config.php';
require_login();

// AJAX: generate kode otomatis
if (($_GET['action'] ?? '') === 'next_kode') {
    $res = $conn->query("SELECT kode_obat FROM obat WHERE kode_obat LIKE 'OBT-%' ORDER BY kode_obat DESC");
    $next = 1;
    while ($row = $res->fetch_assoc()) {
        $num = (int) substr($row['kode_obat'], 4); // ambil angka setelah "OBT-"
        if ($num >= $next) { $next = $num + 1; break; }
    }
    header('Content-Type: application/json');
    echo json_encode(['kode' => 'OBT-' . str_pad($next, 3, '0', STR_PAD_LEFT)]);
    exit;
}
$page_title = 'Data Obat';
$page_crumb = 'Kelola stok dan harga obat';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id    = (int) ($_POST['id'] ?? 0);
        $kode  = trim($_POST['kode_obat'] ?? '');
        $nama  = trim($_POST['nama_obat'] ?? '');
        $kat   = (int) ($_POST['kategori_id'] ?? 0) ?: null;
        $sup   = (int) ($_POST['supplier_id'] ?? 0) ?: null;
        $satuan= trim($_POST['satuan'] ?? 'Strip');
        $hbeli = (int) ($_POST['harga_beli'] ?? 0);
        $hjual = (int) ($_POST['harga_jual'] ?? 0);
        $stok  = (int) ($_POST['stok'] ?? 0);
        $smin  = (int) ($_POST['stok_minimal'] ?? 0);
        $exp   = $_POST['tanggal_kadaluarsa'] ?: null;

        if ($kode === '' || $nama === '') {
            set_flash('danger', 'Kode dan nama obat wajib diisi.');
        } elseif ($id > 0) {
            $old = $conn->query("SELECT * FROM obat WHERE id=$id")->fetch_assoc();
            $stmt = $conn->prepare('UPDATE obat SET kode_obat=?, nama_obat=?, kategori_id=?, supplier_id=?, satuan=?, harga_beli=?, harga_jual=?, stok=?, stok_minimal=?, tanggal_kadaluarsa=? WHERE id=?');
            $stmt->bind_param('ssiisiiiisi', $kode,$nama,$kat,$sup,$satuan,$hbeli,$hjual,$stok,$smin,$exp,$id);
            try {
                $stmt->execute();
                set_flash('success','Data obat diperbarui.');

                $ubah = [];
                if ($old) {
                    if ($old['nama_obat'] !== $nama)             $ubah[] = "nama: \"{$old['nama_obat']}\" → \"$nama\"";
                    if ($old['kode_obat'] !== $kode)             $ubah[] = "kode: {$old['kode_obat']} → $kode";
                    if ((int) $old['harga_beli'] !== $hbeli)     $ubah[] = 'harga beli: ' . rupiah($old['harga_beli']) . ' → ' . rupiah($hbeli);
                    if ((int) $old['harga_jual'] !== $hjual)     $ubah[] = 'harga jual: ' . rupiah($old['harga_jual']) . ' → ' . rupiah($hjual);
                    if ((int) $old['stok'] !== $stok)            $ubah[] = "stok: {$old['stok']} → $stok";
                    if ((int) $old['stok_minimal'] !== $smin)    $ubah[] = "stok minimal: {$old['stok_minimal']} → $smin";
                }
                $detail = "Edit obat \"$nama\" ($kode)" . ($ubah ? ': ' . implode(', ', $ubah) : ' (tidak ada perubahan nilai)');
                log_aktivitas($conn, 'obat', 'edit', $detail);
            }
            catch (mysqli_sql_exception $e){ set_flash('danger','Gagal: kode obat mungkin sudah dipakai.'); }
        } else {
            $stmt = $conn->prepare('INSERT INTO obat (kode_obat,nama_obat,kategori_id,supplier_id,satuan,harga_beli,harga_jual,stok,stok_minimal,tanggal_kadaluarsa) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stmt->bind_param('ssiisiiiis', $kode,$nama,$kat,$sup,$satuan,$hbeli,$hjual,$stok,$smin,$exp);
            try {
                $stmt->execute();
                set_flash('success','Obat baru ditambahkan.');
                log_aktivitas($conn, 'obat', 'tambah', "Tambah obat \"$nama\" ($kode), stok awal $stok, harga jual " . rupiah($hjual));
            }
            catch (mysqli_sql_exception $e){ set_flash('danger','Gagal: kode obat sudah ada.'); }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $old = $conn->query("SELECT nama_obat, kode_obat FROM obat WHERE id=$id")->fetch_assoc();
        $stmt = $conn->prepare('DELETE FROM obat WHERE id=?'); $stmt->bind_param('i',$id);
        try {
            $stmt->execute();
            set_flash('success','Obat dihapus.');
            if ($old) log_aktivitas($conn, 'obat', 'hapus', "Hapus obat \"{$old['nama_obat']}\" ({$old['kode_obat']})");
        }
        catch (mysqli_sql_exception $e){ set_flash('danger','Tidak bisa dihapus, obat sudah ada di transaksi.'); }
    }
    redirect('obat.php');
}

require_once __DIR__ . '/includes/header.php';

// Filter kategori
$filter_kat = (int) ($_GET['kategori'] ?? 0);
$where = $filter_kat ? "WHERE o.kategori_id = $filter_kat" : '';

$rows = $conn->query(
    "SELECT o.*, k.nama_kategori, s.nama_supplier
     FROM obat o
     LEFT JOIN kategori k ON k.id=o.kategori_id
     LEFT JOIN supplier s ON s.id=o.supplier_id
     $where ORDER BY o.nama_obat"
);
$kategori_opt = $conn->query("SELECT id,nama_kategori FROM kategori ORDER BY nama_kategori");
$supplier_opt = $conn->query("SELECT id,nama_supplier FROM supplier ORDER BY nama_supplier");
$kat_list = $conn->query("SELECT id,nama_kategori FROM kategori ORDER BY nama_kategori");

// Untuk dipakai ulang di JS modal (re-query)
$kategori_arr = []; while($k=$kategori_opt->fetch_assoc()) $kategori_arr[]=$k;
$supplier_arr = []; while($s=$supplier_opt->fetch_assoc()) $supplier_arr[]=$s;

function status_kadaluarsa($tgl){
    if(!$tgl) return ['pill-gray','—'];
    $days = (strtotime($tgl) - time())/86400;
    if($days < 0)  return ['pill-rose','Kadaluarsa'];
    if($days <= 90) return ['pill-amber', tgl_indo($tgl)];
    return ['pill-teal', tgl_indo($tgl)];
}
?>

<div class="card-app">
  <div class="card-app__head flex-wrap gap-2">
    <div class="search-box">
      <i class="bi bi-search"></i>
      <input type="text" class="form-control" placeholder="Cari nama / kode obat…" data-table-search="#tblObat">
    </div>
    <form method="get" class="d-flex align-items-center gap-2">
      <select name="kategori" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
        <option value="0">Semua kategori</option>
        <?php while($k=$kat_list->fetch_assoc()): ?>
          <option value="<?= $k['id'] ?>" <?= $filter_kat==$k['id']?'selected':'' ?>><?= e($k['nama_kategori']) ?></option>
        <?php endwhile; ?>
      </select>
    </form>
    <button class="btn btn-teal btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#modalObat" onclick="resetForm()">
      <i class="bi bi-plus-lg me-1"></i>Tambah Obat
    </button>
  </div>

  <div class="table-responsive">
    <table class="table-app" id="tblObat">
      <thead>
        <tr>
          <th>Obat</th><th>Kategori</th><th class="text-end">Harga Jual</th>
          <th class="text-end">Stok</th><th>Kadaluarsa</th><th class="text-end">Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($rows->num_rows): while ($r = $rows->fetch_assoc()):
        [$exp_cls,$exp_txt] = status_kadaluarsa($r['tanggal_kadaluarsa']);
        $stok_cls = $r['stok']==0 ? 'pill-rose' : ($r['stok']<=$r['stok_minimal'] ? 'pill-amber':'pill-teal');
      ?>
        <tr>
          <td>
            <div class="cell-strong"><?= e($r['nama_obat']) ?></div>
            <div class="cell-muted"><?= e($r['kode_obat']) ?> &middot; <?= e($r['satuan']) ?></div>
          </td>
          <td><span class="pill pill-gray"><?= e($r['nama_kategori'] ?? '-') ?></span></td>
          <td class="text-end">
            <div class="cell-strong"><?= rupiah($r['harga_jual']) ?></div>
            <div class="cell-muted">beli <?= rupiah($r['harga_beli']) ?></div>
          </td>
          <td class="text-end"><span class="pill <?= $stok_cls ?> pill-dot"><?= (int)$r['stok'] ?></span></td>
          <td><span class="pill <?= $exp_cls ?>"><?= $exp_txt ?></span></td>
          <td class="text-end">
            <button class="btn btn-soft btn-sm btn-icon" onclick='editData(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i></button>
            <form method="post" class="d-inline js-confirm-delete" data-name="<?= e($r['nama_obat']) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-icon" style="background:var(--rose-bg);color:var(--rose)"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endwhile; else: ?>
        <tr><td colspan="6"><div class="empty-state"><i class="bi bi-capsule"></i>Tidak ada obat ditemukan.</div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="modalObat" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title" id="modalTitle">Tambah Obat</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="o_id">
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label">Kode Obat <span class="text-danger">*</span></label>
            <input type="text" name="kode_obat" id="o_kode" class="form-control" placeholder="OBT-013" required></div>
          <div class="col-md-8"><label class="form-label">Nama Obat <span class="text-danger">*</span></label>
            <input type="text" name="nama_obat" id="o_nama" class="form-control" required></div>

          <div class="col-md-6"><label class="form-label">Kategori</label>
            <select name="kategori_id" id="o_kat" class="form-select">
              <option value="0">— Pilih —</option>
              <?php foreach($kategori_arr as $k): ?><option value="<?= $k['id'] ?>"><?= e($k['nama_kategori']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-md-6"><label class="form-label">Supplier</label>
            <select name="supplier_id" id="o_sup" class="form-select">
              <option value="0">— Pilih —</option>
              <?php foreach($supplier_arr as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['nama_supplier']) ?></option><?php endforeach; ?>
            </select></div>

          <div class="col-md-4"><label class="form-label">Satuan</label>
            <input type="text" name="satuan" id="o_satuan" class="form-control" value="Strip" list="satuanList">
            <datalist id="satuanList"><option>Strip</option><option>Tablet</option><option>Botol</option><option>Box</option><option>Pcs</option><option>Tube</option></datalist></div>
          <div class="col-md-4"><label class="form-label">Harga Beli</label>
            <div class="input-group"><span class="input-group-text">Rp</span>
              <input type="number" name="harga_beli" id="o_hbeli" class="form-control" value="0" min="0"></div></div>
          <div class="col-md-4"><label class="form-label">Harga Jual</label>
            <div class="input-group"><span class="input-group-text">Rp</span>
              <input type="number" name="harga_jual" id="o_hjual" class="form-control" value="0" min="0"></div></div>

          <div class="col-md-4"><label class="form-label">Stok</label>
            <input type="number" name="stok" id="o_stok" class="form-control" value="0" min="0"></div>
          <div class="col-md-4"><label class="form-label">Stok Minimal</label>
            <input type="number" name="stok_minimal" id="o_smin" class="form-control" value="10" min="0"></div>
          <div class="col-md-4"><label class="form-label">Tanggal Kadaluarsa</label>
            <input type="date" name="tanggal_kadaluarsa" id="o_exp" class="form-control"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Batal</button>
        <button class="btn btn-teal"><i class="bi bi-check-lg me-1"></i>Simpan</button>
      </div>
    </form>
  </div>
</div>

<?php $extra_js = <<<JS
<script>
function resetForm(){
  document.getElementById('modalTitle').textContent = 'Tambah Obat';
  document.getElementById('o_id').value = '';
  document.getElementById('o_kode').value = 'Memuat...';
  document.getElementById('o_nama').value = '';
  document.getElementById('o_kat').value = '0';
  document.getElementById('o_sup').value = '0';
  document.getElementById('o_satuan').value = 'Strip';
  document.getElementById('o_hbeli').value = '0';
  document.getElementById('o_hjual').value = '0';
  document.getElementById('o_stok').value = '0';
  document.getElementById('o_smin').value = '10';
  document.getElementById('o_exp').value = '';

  // Auto-generate kode
  fetch('obat.php?action=next_kode')
    .then(r => r.json())
    .then(data => {
      document.getElementById('o_kode').value = data.kode;
    })
    .catch(() => {
      document.getElementById('o_kode').value = 'OBT-001';
    });
}
function editData(d){
  document.getElementById('modalTitle').textContent='Edit Obat';
  document.getElementById('o_id').value=d.id;
  document.getElementById('o_kode').value=d.kode_obat;
  document.getElementById('o_nama').value=d.nama_obat;
  document.getElementById('o_kat').value=d.kategori_id||'0';
  document.getElementById('o_sup').value=d.supplier_id||'0';
  document.getElementById('o_satuan').value=d.satuan||'Strip';
  document.getElementById('o_hbeli').value=d.harga_beli;
  document.getElementById('o_hjual').value=d.harga_jual;
  document.getElementById('o_stok').value=d.stok;
  document.getElementById('o_smin').value=d.stok_minimal;
  document.getElementById('o_exp').value=d.tanggal_kadaluarsa||'';
  new bootstrap.Modal('#modalObat').show();
}
</script>
JS;
require_once __DIR__ . '/includes/footer.php';
