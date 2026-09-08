<?php
require_once __DIR__ . '/config/config.php';
require_login();

$page_title = 'Riwayat Transaksi';
$page_crumb = 'Semua transaksi penjualan';

// Hapus transaksi (admin) — kembalikan stok
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && has_role('admin')) {
    $id = (int) $_POST['id'];
    $old = $conn->query("SELECT no_transaksi, total FROM penjualan WHERE id=$id")->fetch_assoc();
    $conn->begin_transaction();
    try {
        $d = $conn->query("SELECT obat_id, jumlah FROM penjualan_detail WHERE penjualan_id=$id");
        while ($row = $d->fetch_assoc()) {
            if ($row['obat_id']) {
                $conn->query("UPDATE obat SET stok = stok + {$row['jumlah']} WHERE id={$row['obat_id']}");
            }
        }
        $conn->query("DELETE FROM penjualan WHERE id=$id");
        $conn->commit();
        set_flash('success', 'Transaksi dihapus & stok dikembalikan.');
        if ($old) log_aktivitas($conn, 'transaksi', 'hapus', "Hapus transaksi {$old['no_transaksi']} (total " . rupiah($old['total']) . ') & stok dikembalikan');
    } catch (Exception $e) {
        $conn->rollback();
        set_flash('danger', 'Gagal menghapus transaksi.');
    }
    redirect('transaksi.php');
}

require_once __DIR__ . '/includes/header.php';

// Filter tanggal
$dari = $_GET['dari'] ?? date('Y-m-01');
$sampai = $_GET['sampai'] ?? date('Y-m-d');

$stmt = $conn->prepare(
    "SELECT p.*, u.nama kasir, COUNT(pd.id) jml_item
     FROM penjualan p
     LEFT JOIN users u ON u.id=p.user_id
     LEFT JOIN penjualan_detail pd ON pd.penjualan_id=p.id
     WHERE DATE(p.tanggal) BETWEEN ? AND ?
     GROUP BY p.id ORDER BY p.tanggal DESC"
);
$stmt->bind_param('ss', $dari, $sampai);
$stmt->execute();
$rows = $stmt->get_result();

// Ringkasan periode
$sum = $conn->prepare("SELECT COALESCE(SUM(total),0) t, COUNT(*) c FROM penjualan WHERE DATE(tanggal) BETWEEN ? AND ?");
$sum->bind_param('ss', $dari, $sampai); $sum->execute();
$ringkas = $sum->get_result()->fetch_assoc();
?>

<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="stat">
    <div class="stat__icon ic-teal"><i class="bi bi-cash-stack"></i></div>
    <p class="stat__label">Total Omzet Periode</p>
    <p class="stat__value"><?= rupiah_singkat($ringkas['t']) ?></p>
  </div></div>
  <div class="col-md-4"><div class="stat">
    <div class="stat__icon ic-sky"><i class="bi bi-receipt"></i></div>
    <p class="stat__label">Jumlah Transaksi</p>
    <p class="stat__value"><?= number_format($ringkas['c'],0,',','.') ?></p>
  </div></div>
  <div class="col-md-4"><div class="stat">
    <div class="stat__icon ic-violet"><i class="bi bi-graph-up"></i></div>
    <p class="stat__label">Rata-rata / Transaksi</p>
    <p class="stat__value"><?= rupiah_singkat($ringkas['c'] ? $ringkas['t']/$ringkas['c'] : 0) ?></p>
  </div></div>
</div>

<div class="card-app">
  <div class="card-app__head flex-wrap gap-2">
    <form method="get" class="d-flex align-items-end gap-2 flex-wrap">
      <div><label class="form-label mb-1">Dari</label><input type="date" name="dari" class="form-control form-control-sm" value="<?= e($dari) ?>"></div>
      <div><label class="form-label mb-1">Sampai</label><input type="date" name="sampai" class="form-control form-control-sm" value="<?= e($sampai) ?>"></div>
      <button class="btn btn-teal btn-sm"><i class="bi bi-funnel me-1"></i>Terapkan</button>
    </form>
    <div class="search-box ms-auto">
      <i class="bi bi-search"></i>
      <input type="text" class="form-control" placeholder="Cari no. transaksi…" data-table-search="#tblTrx">
    </div>
  </div>

  <div class="table-responsive">
    <table class="table-app" id="tblTrx">
      <thead><tr><th>No. Transaksi</th><th>Waktu</th><th>Kasir</th><th class="text-end">Item</th><th class="text-end">Total</th><th class="text-end">Aksi</th></tr></thead>
      <tbody>
      <?php if ($rows->num_rows): while ($r = $rows->fetch_assoc()): ?>
        <tr>
          <td class="cell-strong"><?= e($r['no_transaksi']) ?></td>
          <td class="cell-muted"><?= tgl_indo($r['tanggal'], true) ?></td>
          <td><span class="pill pill-gray"><i class="bi bi-person"></i><?= e($r['kasir'] ?? '-') ?></span></td>
          <td class="text-end cell-muted"><?= (int)$r['jml_item'] ?> item</td>
          <td class="text-end cell-strong"><?= rupiah($r['total']) ?></td>
          <td class="text-end">
            <button class="btn btn-soft btn-sm btn-icon" title="Detail" onclick="lihatDetail(<?= (int)$r['id'] ?>)"><i class="bi bi-eye"></i></button>
            <?php if (has_role('admin')): ?>
            <form method="post" class="d-inline js-confirm-delete" data-name="transaksi <?= e($r['no_transaksi']) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-icon" style="background:var(--rose-bg);color:var(--rose)"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endwhile; else: ?>
        <tr><td colspan="6"><div class="empty-state"><i class="bi bi-receipt"></i>Tidak ada transaksi pada periode ini.</div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal detail -->
<div class="modal fade" id="modalDetail" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Detail Transaksi</h5>
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
async function lihatDetail(id){
  const modal = new bootstrap.Modal('#modalDetail'); modal.show();
  const body = document.getElementById('detailBody');
  body.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-secondary"></div></div>';
  try{
    const res = await fetch('transaksi_detail?id='+id);
    body.innerHTML = await res.text();
  }catch(e){ body.innerHTML = '<div class="empty-state">Gagal memuat detail.</div>'; }
}
</script>
JS;
require_once __DIR__ . '/includes/footer.php';
