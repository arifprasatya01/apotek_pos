<?php
require_once __DIR__ . '/config/config.php';
require_login();

$page_title = 'Kategori Obat';
$page_crumb = 'Kelompokkan obat berdasarkan jenisnya';

/* ---------- Proses simpan / hapus ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id   = (int) ($_POST['id'] ?? 0);
        $nama = trim($_POST['nama_kategori'] ?? '');
        $ket  = trim($_POST['keterangan'] ?? '');

        if ($nama === '') {
            set_flash('danger', 'Nama kategori wajib diisi.');
        } elseif ($id > 0) {
            $old = $conn->query("SELECT nama_kategori FROM kategori WHERE id=$id")->fetch_assoc();
            $stmt = $conn->prepare('UPDATE kategori SET nama_kategori=?, keterangan=? WHERE id=?');
            $stmt->bind_param('ssi', $nama, $ket, $id);
            $stmt->execute();
            set_flash('success', 'Kategori berhasil diperbarui.');
            $ket_log = ($old && $old['nama_kategori'] !== $nama) ? " (sebelumnya: \"{$old['nama_kategori']}\")" : '';
            log_aktivitas($conn, 'kategori', 'edit', "Edit kategori \"$nama\"$ket_log");
        } else {
            $stmt = $conn->prepare('INSERT INTO kategori (nama_kategori, keterangan) VALUES (?,?)');
            $stmt->bind_param('ss', $nama, $ket);
            $stmt->execute();
            set_flash('success', 'Kategori baru ditambahkan.');
            log_aktivitas($conn, 'kategori', 'tambah', "Tambah kategori \"$nama\"");
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $old = $conn->query("SELECT nama_kategori FROM kategori WHERE id=$id")->fetch_assoc();
        $stmt = $conn->prepare('DELETE FROM kategori WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        set_flash('success', 'Kategori dihapus.');
        if ($old) log_aktivitas($conn, 'kategori', 'hapus', "Hapus kategori \"{$old['nama_kategori']}\"");
    }
    redirect('kategori.php');
}

require_once __DIR__ . '/includes/header.php';

$rows = $conn->query(
    "SELECT k.*, (SELECT COUNT(*) FROM obat o WHERE o.kategori_id=k.id) jml
     FROM kategori k ORDER BY k.nama_kategori"
);
?>

<div class="card-app">
  <div class="card-app__head">
    <div class="search-box">
      <i class="bi bi-search"></i>
      <input type="text" class="form-control" placeholder="Cari kategori…" data-table-search="#tblKategori">
    </div>
    <button class="btn btn-teal btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#modalKategori"
            onclick="resetForm()">
      <i class="bi bi-plus-lg me-1"></i>Tambah Kategori
    </button>
  </div>

  <div class="table-responsive">
    <table class="table-app" id="tblKategori">
      <thead><tr><th>Nama Kategori</th><th>Keterangan</th><th class="text-end">Jumlah Obat</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
      <?php if ($rows->num_rows): while ($r = $rows->fetch_assoc()): ?>
        <tr>
          <td><span class="pill pill-teal"><i class="bi bi-tag"></i><?= e($r['nama_kategori']) ?></span></td>
          <td class="cell-muted"><?= e($r['keterangan'] ?: '—') ?></td>
          <td class="text-end cell-strong"><?= (int)$r['jml'] ?></td>
          <td class="text-end">
            <button class="btn btn-soft btn-sm btn-icon" title="Edit"
              onclick='editKategori(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
              <i class="bi bi-pencil"></i>
            </button>
            <form method="post" class="d-inline js-confirm-delete" data-name="kategori <?= e($r['nama_kategori']) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-icon" style="background:var(--rose-bg);color:var(--rose)" title="Hapus">
                <i class="bi bi-trash"></i>
              </button>
            </form>
          </td>
        </tr>
      <?php endwhile; else: ?>
        <tr><td colspan="4"><div class="empty-state"><i class="bi bi-tags"></i>Belum ada kategori. Tambahkan yang pertama.</div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="modalKategori" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title" id="modalKategoriTitle">Tambah Kategori</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="k_id">
        <div class="mb-3">
          <label class="form-label">Nama Kategori <span class="text-danger">*</span></label>
          <input type="text" name="nama_kategori" id="k_nama" class="form-control" placeholder="Mis. Analgesik" required>
        </div>
        <div class="mb-1">
          <label class="form-label">Keterangan</label>
          <textarea name="keterangan" id="k_ket" class="form-control" rows="2" placeholder="Deskripsi singkat (opsional)"></textarea>
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
  document.getElementById('modalKategoriTitle').textContent='Tambah Kategori';
  document.getElementById('k_id').value='';
  document.getElementById('k_nama').value='';
  document.getElementById('k_ket').value='';
}
function editKategori(d){
  document.getElementById('modalKategoriTitle').textContent='Edit Kategori';
  document.getElementById('k_id').value=d.id;
  document.getElementById('k_nama').value=d.nama_kategori;
  document.getElementById('k_ket').value=d.keterangan||'';
  new bootstrap.Modal('#modalKategori').show();
}
</script>
JS;
require_once __DIR__ . '/includes/footer.php';
