<?php
require_once __DIR__ . '/config/config.php';
require_login();

$page_title = 'Supplier';
$page_crumb = 'Daftar pemasok obat';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id     = (int) ($_POST['id'] ?? 0);
        $nama   = trim($_POST['nama_supplier'] ?? '');
        $alamat = trim($_POST['alamat'] ?? '');
        $telp   = trim($_POST['telepon'] ?? '');
        $email  = trim($_POST['email'] ?? '');

        if ($nama === '') {
            set_flash('danger', 'Nama supplier wajib diisi.');
        } elseif ($id > 0) {
            $stmt = $conn->prepare('UPDATE supplier SET nama_supplier=?, alamat=?, telepon=?, email=? WHERE id=?');
            $stmt->bind_param('ssssi', $nama, $alamat, $telp, $email, $id);
            $stmt->execute();
            set_flash('success', 'Data supplier diperbarui.');
            log_aktivitas($conn, 'supplier', 'edit', "Edit supplier \"$nama\"");
        } else {
            $stmt = $conn->prepare('INSERT INTO supplier (nama_supplier, alamat, telepon, email) VALUES (?,?,?,?)');
            $stmt->bind_param('ssss', $nama, $alamat, $telp, $email);
            $stmt->execute();
            set_flash('success', 'Supplier baru ditambahkan.');
            log_aktivitas($conn, 'supplier', 'tambah', "Tambah supplier \"$nama\"");
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $old = $conn->query("SELECT nama_supplier FROM supplier WHERE id=$id")->fetch_assoc();
        $stmt = $conn->prepare('DELETE FROM supplier WHERE id=?');
        $stmt->bind_param('i', $id); $stmt->execute();
        set_flash('success', 'Supplier dihapus.');
        if ($old) log_aktivitas($conn, 'supplier', 'hapus', "Hapus supplier \"{$old['nama_supplier']}\"");
    }
    redirect('supplier.php');
}

require_once __DIR__ . '/includes/header.php';

$rows = $conn->query("SELECT * FROM supplier ORDER BY nama_supplier");
?>

<div class="card-app">
  <div class="card-app__head">
    <div class="search-box">
      <i class="bi bi-search"></i>
      <input type="text" class="form-control" placeholder="Cari supplier…" data-table-search="#tblSupplier">
    </div>
    <button class="btn btn-teal btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#modalSupplier" onclick="resetForm()">
      <i class="bi bi-plus-lg me-1"></i>Tambah Supplier
    </button>
  </div>

  <div class="table-responsive">
    <table class="table-app" id="tblSupplier">
      <thead><tr><th>Supplier</th><th>Alamat</th><th>Kontak</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
      <?php if ($rows->num_rows): while ($r = $rows->fetch_assoc()): ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="d-grid" style="width:36px;height:36px;place-items:center;border-radius:10px;background:var(--teal-50);color:var(--teal-700)">
                <i class="bi bi-building"></i>
              </div>
              <span class="cell-strong"><?= e($r['nama_supplier']) ?></span>
            </div>
          </td>
          <td class="cell-muted"><i class="bi bi-geo-alt me-1"></i><?= e($r['alamat'] ?: '—') ?></td>
          <td>
            <?php if ($r['telepon']): ?><div class="cell-muted"><i class="bi bi-telephone me-1"></i><?= e($r['telepon']) ?></div><?php endif; ?>
            <?php if ($r['email']): ?><div class="cell-muted"><i class="bi bi-envelope me-1"></i><?= e($r['email']) ?></div><?php endif; ?>
            <?php if (!$r['telepon'] && !$r['email']): ?><span class="cell-muted">—</span><?php endif; ?>
          </td>
          <td class="text-end">
            <button class="btn btn-soft btn-sm btn-icon" onclick='editData(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i></button>
            <form method="post" class="d-inline js-confirm-delete" data-name="supplier <?= e($r['nama_supplier']) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-icon" style="background:var(--rose-bg);color:var(--rose)"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endwhile; else: ?>
        <tr><td colspan="4"><div class="empty-state"><i class="bi bi-truck"></i>Belum ada supplier.</div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="modalSupplier" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title" id="modalTitle">Tambah Supplier</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="s_id">
        <div class="mb-3">
          <label class="form-label">Nama Supplier <span class="text-danger">*</span></label>
          <input type="text" name="nama_supplier" id="s_nama" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Alamat</label>
          <input type="text" name="alamat" id="s_alamat" class="form-control">
        </div>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Telepon</label>
            <input type="text" name="telepon" id="s_telp" class="form-control"></div>
          <div class="col-md-6"><label class="form-label">Email</label>
            <input type="email" name="email" id="s_email" class="form-control"></div>
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
  document.getElementById('modalTitle').textContent='Tambah Supplier';
  ['s_id','s_nama','s_alamat','s_telp','s_email'].forEach(id=>document.getElementById(id).value='');
}
function editData(d){
  document.getElementById('modalTitle').textContent='Edit Supplier';
  document.getElementById('s_id').value=d.id;
  document.getElementById('s_nama').value=d.nama_supplier;
  document.getElementById('s_alamat').value=d.alamat||'';
  document.getElementById('s_telp').value=d.telepon||'';
  document.getElementById('s_email').value=d.email||'';
  new bootstrap.Modal('#modalSupplier').show();
}
</script>
JS;
require_once __DIR__ . '/includes/footer.php';
