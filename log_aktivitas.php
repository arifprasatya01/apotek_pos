<?php
require_once __DIR__ . '/config/config.php';
require_login();

$page_title = 'Log Aktivitas';
$page_crumb = 'Jejak siapa melakukan apa di sistem';

// Hanya admin
if (!has_role('admin')) {
    set_flash('danger', 'Anda tidak memiliki akses ke halaman ini.');
    redirect('index.php');
}

require_once __DIR__ . '/includes/header.php';

/* ---------- Filter ---------- */
$dari    = $_GET['dari'] ?? date('Y-m-01');
$sampai  = $_GET['sampai'] ?? date('Y-m-d');
$f_modul = trim($_GET['modul'] ?? '');
$f_user  = (int) ($_GET['user'] ?? 0);

$where = ['DATE(l.created_at) BETWEEN ? AND ?'];
$types = 'ss';
$params = [$dari, $sampai];

if ($f_modul !== '') {
    $where[] = 'l.modul = ?';
    $types  .= 's';
    $params[] = $f_modul;
}
if ($f_user > 0) {
    $where[] = 'l.user_id = ?';
    $types  .= 'i';
    $params[] = $f_user;
}

$sql = "SELECT l.*, u.nama AS user_nama
        FROM log_aktivitas l
        LEFT JOIN users u ON u.id = l.user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY l.created_at DESC
        LIMIT 500";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result();

// Daftar modul (untuk dropdown filter) & user (untuk dropdown filter)
$modulList = $conn->query("SELECT DISTINCT modul FROM log_aktivitas ORDER BY modul");
$userList  = $conn->query("SELECT id, nama FROM users ORDER BY nama");

// Warna pill per aksi
$aksi_color = [
    'tambah'      => 'pill-teal',
    'edit'        => 'pill-sky',
    'edit_ppn'    => 'pill-sky',
    'hapus'       => 'pill-rose',
    'checkout'    => 'pill-teal',
    'recompute'   => 'pill-amber',
    'login'       => 'pill-teal',
    'login_gagal' => 'pill-rose',
    'logout'      => 'pill-gray',
];
function warna_aksi(string $aksi, array $map): string { return $map[$aksi] ?? 'pill-gray'; }
?>

<div class="card-app">
  <div class="card-app__head flex-wrap gap-2">
    <form method="get" class="d-flex align-items-end gap-2 flex-wrap">
      <div><label class="form-label mb-1">Dari</label><input type="date" name="dari" class="form-control form-control-sm" value="<?= e($dari) ?>"></div>
      <div><label class="form-label mb-1">Sampai</label><input type="date" name="sampai" class="form-control form-control-sm" value="<?= e($sampai) ?>"></div>
      <div>
        <label class="form-label mb-1">Modul</label>
        <select name="modul" class="form-select form-select-sm">
          <option value="">Semua modul</option>
          <?php while ($m = $modulList->fetch_assoc()): ?>
            <option value="<?= e($m['modul']) ?>" <?= $f_modul===$m['modul']?'selected':'' ?>><?= e(ucfirst(str_replace('_',' ',$m['modul']))) ?></option>
          <?php endwhile; ?>
        </select>
      </div>
      <div>
        <label class="form-label mb-1">Pengguna</label>
        <select name="user" class="form-select form-select-sm">
          <option value="0">Semua pengguna</option>
          <?php while ($u = $userList->fetch_assoc()): ?>
            <option value="<?= (int) $u['id'] ?>" <?= $f_user===(int)$u['id']?'selected':'' ?>><?= e($u['nama']) ?></option>
          <?php endwhile; ?>
        </select>
      </div>
      <button class="btn btn-teal btn-sm"><i class="bi bi-funnel me-1"></i>Terapkan</button>
    </form>
    <div class="search-box ms-auto">
      <i class="bi bi-search"></i>
      <input type="text" class="form-control" placeholder="Cari deskripsi…" data-table-search="#tblLog">
    </div>
  </div>

  <div class="px-3 pt-2 cell-muted" style="font-size:12.5px">
    <i class="bi bi-info-circle me-1"></i>Menampilkan maksimal 500 entri terbaru sesuai filter.
  </div>

  <div class="table-responsive">
    <table class="table-app" id="tblLog">
      <thead><tr><th>Waktu</th><th>Pengguna</th><th>Modul</th><th>Aksi</th><th>Deskripsi</th></tr></thead>
      <tbody>
      <?php if ($rows->num_rows): while ($r = $rows->fetch_assoc()): ?>
        <tr>
          <td class="cell-muted text-nowrap"><?= tgl_indo($r['created_at'], true) ?></td>
          <td><span class="pill pill-gray"><i class="bi bi-person"></i><?= e($r['user_nama'] ?? 'Sistem') ?></span></td>
          <td class="cell-muted"><?= e(ucfirst(str_replace('_',' ',$r['modul']))) ?></td>
          <td><span class="pill <?= warna_aksi($r['aksi'], $aksi_color) ?>"><?= e(str_replace('_',' ',$r['aksi'])) ?></span></td>
          <td style="max-width:520px"><?= e($r['deskripsi']) ?></td>
        </tr>
      <?php endwhile; else: ?>
        <tr><td colspan="5"><div class="empty-state"><i class="bi bi-journal-text"></i>Belum ada log pada periode/filter ini.</div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
