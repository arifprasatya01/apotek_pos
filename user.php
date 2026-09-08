<?php
require_once __DIR__ . '/config/config.php';
require_login();

$page_title = 'Pengguna';
$page_crumb = 'Kelola akun & hak akses';

// Hanya admin
if (!has_role('admin')) {
    set_flash('danger', 'Anda tidak memiliki akses ke halaman ini.');
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id    = (int) ($_POST['id'] ?? 0);
        $nama  = trim($_POST['nama'] ?? '');
        $uname = trim($_POST['username'] ?? '');
        $role  = in_array($_POST['role'] ?? '', ['admin','apoteker','kasir'], true) ? $_POST['role'] : 'kasir';
        $pass  = $_POST['password'] ?? '';

        if ($nama === '' || $uname === '') {
            set_flash('danger', 'Nama dan username wajib diisi.');
        } elseif ($id > 0) {
            $old = $conn->query("SELECT nama, username, role FROM users WHERE id=$id")->fetch_assoc();
            if ($pass !== '') {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $conn->prepare('UPDATE users SET nama=?, username=?, role=?, password=? WHERE id=?');
                $stmt->bind_param('ssssi', $nama, $uname, $role, $hash, $id);
            } else {
                $stmt = $conn->prepare('UPDATE users SET nama=?, username=?, role=? WHERE id=?');
                $stmt->bind_param('sssi', $nama, $uname, $role, $id);
            }
            try {
                $stmt->execute();
                set_flash('success','Pengguna diperbarui.');
                $ubah = [];
                if ($old) {
                    if ($old['nama'] !== $nama)         $ubah[] = "nama: \"{$old['nama']}\" → \"$nama\"";
                    if ($old['username'] !== $uname)    $ubah[] = "username: {$old['username']} → $uname";
                    if ($old['role'] !== $role)          $ubah[] = "role: {$old['role']} → $role";
                }
                if ($pass !== '') $ubah[] = 'password diganti';
                log_aktivitas($conn, 'user', 'edit', "Edit pengguna \"$nama\"" . ($ubah ? ': ' . implode(', ', $ubah) : ''));
            }
            catch (mysqli_sql_exception $e){ set_flash('danger','Username sudah dipakai.'); }
        } else {
            if ($pass === '') { set_flash('danger','Password wajib diisi untuk pengguna baru.'); redirect('user.php'); }
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('INSERT INTO users (nama,username,role,password) VALUES (?,?,?,?)');
            $stmt->bind_param('ssss', $nama, $uname, $role, $hash);
            try {
                $stmt->execute();
                set_flash('success','Pengguna baru ditambahkan.');
                log_aktivitas($conn, 'user', 'tambah', "Tambah pengguna \"$nama\" ($uname), role $role");
            }
            catch (mysqli_sql_exception $e){ set_flash('danger','Username sudah dipakai.'); }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === current_user()['id']) {
            set_flash('danger', 'Tidak bisa menghapus akun sendiri.');
        } else {
            $old = $conn->query("SELECT nama, username FROM users WHERE id=$id")->fetch_assoc();
            $stmt = $conn->prepare('DELETE FROM users WHERE id=?');
            $stmt->bind_param('i', $id); $stmt->execute();
            set_flash('success', 'Pengguna dihapus.');
            if ($old) log_aktivitas($conn, 'user', 'hapus', "Hapus pengguna \"{$old['nama']}\" ({$old['username']})");
        }
    }
    redirect('user.php');
}

require_once __DIR__ . '/includes/header.php';

$rows = $conn->query("SELECT id,nama,username,role,created_at FROM users ORDER BY id");
$role_color = ['admin'=>'pill-rose','apoteker'=>'pill-sky','kasir'=>'pill-teal'];
?>

<div class="card-app">
  <div class="card-app__head">
    <div class="search-box"><i class="bi bi-search"></i>
      <input type="text" class="form-control" placeholder="Cari pengguna…" data-table-search="#tblUser"></div>
    <button class="btn btn-teal btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#modalUser" onclick="resetForm()">
      <i class="bi bi-person-plus me-1"></i>Tambah Pengguna
    </button>
  </div>
  <div class="table-responsive">
    <table class="table-app" id="tblUser">
      <thead><tr><th>Nama</th><th>Username</th><th>Role</th><th>Dibuat</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
      <?php while ($r = $rows->fetch_assoc()): ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="sidebar__avatar" style="width:34px;height:34px;font-size:14px"><?= strtoupper(substr($r['nama'],0,1)) ?></div>
              <span class="cell-strong"><?= e($r['nama']) ?></span>
              <?php if ($r['id']==current_user()['id']): ?><span class="pill pill-gray">Anda</span><?php endif; ?>
            </div>
          </td>
          <td class="cell-muted">@<?= e($r['username']) ?></td>
          <td><span class="pill <?= $role_color[$r['role']] ?? 'pill-gray' ?>" style="text-transform:capitalize"><?= e($r['role']) ?></span></td>
          <td class="cell-muted"><?= tgl_indo($r['created_at']) ?></td>
          <td class="text-end">
            <button class="btn btn-soft btn-sm btn-icon" onclick='editData(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i></button>
            <?php if ($r['id']!=current_user()['id']): ?>
            <form method="post" class="d-inline js-confirm-delete" data-name="pengguna <?= e($r['nama']) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-icon" style="background:var(--rose-bg);color:var(--rose)"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="modalUser" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header"><h5 class="modal-title" id="modalTitle">Tambah Pengguna</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="u_id">
        <div class="mb-3"><label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
          <input type="text" name="nama" id="u_nama" class="form-control" required></div>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Username <span class="text-danger">*</span></label>
            <input type="text" name="username" id="u_uname" class="form-control" required></div>
          <div class="col-md-6"><label class="form-label">Role</label>
            <select name="role" id="u_role" class="form-select">
              <option value="kasir">Kasir</option><option value="apoteker">Apoteker</option><option value="admin">Admin</option>
            </select></div>
        </div>
        <div class="mt-3"><label class="form-label">Password <span id="u_pass_hint" class="text-danger">*</span></label>
          <input type="password" name="password" id="u_pass" class="form-control" placeholder="Minimal 6 karakter">
          <small class="cell-muted" id="u_pass_note"></small></div>
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
  document.getElementById('modalTitle').textContent='Tambah Pengguna';
  ['u_id','u_nama','u_uname','u_pass'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('u_role').value='kasir';
  document.getElementById('u_pass_hint').style.display='inline';
  document.getElementById('u_pass_note').textContent='';
}
function editData(d){
  document.getElementById('modalTitle').textContent='Edit Pengguna';
  document.getElementById('u_id').value=d.id;
  document.getElementById('u_nama').value=d.nama;
  document.getElementById('u_uname').value=d.username;
  document.getElementById('u_role').value=d.role;
  document.getElementById('u_pass').value='';
  document.getElementById('u_pass_hint').style.display='none';
  document.getElementById('u_pass_note').textContent='Kosongkan jika tidak ingin mengubah password.';
  new bootstrap.Modal('#modalUser').show();
}
</script>
JS;
require_once __DIR__ . '/includes/footer.php';
