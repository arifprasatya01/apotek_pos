<?php
$page_title = 'Dashboard';
$page_crumb = 'Ringkasan aktivitas apotek hari ini';
require_once __DIR__ . '/includes/header.php';

/* ---------- Statistik ringkas ---------- */
$today = date('Y-m-d');

$total_obat   = (int) $conn->query("SELECT COUNT(*) c FROM obat")->fetch_assoc()['c'];
$nilai_stok   = (int) $conn->query("SELECT COALESCE(SUM(stok*harga_beli),0) s FROM obat")->fetch_assoc()['s'];

$stmt = $conn->prepare("SELECT COALESCE(SUM(total),0) t, COUNT(*) c FROM penjualan WHERE DATE(tanggal)=?");
$stmt->bind_param('s', $today); $stmt->execute();
$pj_today = $stmt->get_result()->fetch_assoc();
$omzet_today = (int) $pj_today['t'];
$trx_today   = (int) $pj_today['c'];

$bulan_ini = date('Y-m');
$omzet_bulan = (int) $conn->query(
    "SELECT COALESCE(SUM(total),0) t FROM penjualan WHERE DATE_FORMAT(tanggal,'%Y-%m')='$bulan_ini'"
)->fetch_assoc()['t'];

$obat_menipis = (int) $conn->query("SELECT COUNT(*) c FROM obat WHERE stok <= stok_minimal")->fetch_assoc()['c'];
$obat_kadaluarsa = (int) $conn->query(
    "SELECT COUNT(*) c FROM obat WHERE tanggal_kadaluarsa IS NOT NULL AND tanggal_kadaluarsa <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)"
)->fetch_assoc()['c'];

/* ---------- Grafik penjualan 7 hari ---------- */
$labels = []; $data_omzet = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $labels[] = tgl_indo($d);
    $r = $conn->query("SELECT COALESCE(SUM(total),0) t FROM penjualan WHERE DATE(tanggal)='$d'")->fetch_assoc();
    $data_omzet[] = (int) $r['t'];
}

/* ---------- Obat terlaris (30 hari) ---------- */
$terlaris = $conn->query(
    "SELECT pd.nama_obat, SUM(pd.jumlah) qty, SUM(pd.subtotal) total
     FROM penjualan_detail pd
     JOIN penjualan p ON p.id = pd.penjualan_id
     WHERE p.tanggal >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY pd.nama_obat ORDER BY qty DESC LIMIT 5"
);

/* ---------- Stok menipis ---------- */
$menipis_list = $conn->query(
    "SELECT o.nama_obat, o.kode_obat, o.stok, o.stok_minimal, k.nama_kategori
     FROM obat o LEFT JOIN kategori k ON k.id=o.kategori_id
     WHERE o.stok <= o.stok_minimal ORDER BY o.stok ASC LIMIT 6"
);

/* ---------- Transaksi terbaru ---------- */
$transaksi_baru = $conn->query(
    "SELECT p.no_transaksi, p.tanggal, p.total, u.nama AS kasir
     FROM penjualan p LEFT JOIN users u ON u.id=p.user_id
     ORDER BY p.tanggal DESC LIMIT 5"
);
?>

<!-- Kartu statistik -->
<div class="row g-3 g-xl-4 mb-4">
  <div class="col-6 col-xl-3">
    <div class="stat">
      <div class="stat__icon ic-teal"><i class="bi bi-cash-coin"></i></div>
      <p class="stat__label">Omzet Hari Ini</p>
      <p class="stat__value"><?= rupiah_singkat($omzet_today) ?></p>
      <p class="stat__hint"><i class="bi bi-receipt"></i> <?= $trx_today ?> transaksi</p>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat">
      <div class="stat__icon ic-violet"><i class="bi bi-calendar-month"></i></div>
      <p class="stat__label">Omzet Bulan Ini</p>
      <p class="stat__value"><?= rupiah_singkat($omzet_bulan) ?></p>
      <p class="stat__hint"><i class="bi bi-graph-up"></i> Periode <?= tgl_indo(date('Y-m-01')) ?></p>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat">
      <div class="stat__icon ic-sky"><i class="bi bi-capsule"></i></div>
      <p class="stat__label">Jenis Obat</p>
      <p class="stat__value"><?= number_format($total_obat,0,',','.') ?></p>
      <p class="stat__hint"><i class="bi bi-box-seam"></i> Nilai stok <?= rupiah_singkat($nilai_stok) ?></p>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat">
      <div class="stat__icon ic-amber"><i class="bi bi-exclamation-triangle"></i></div>
      <p class="stat__label">Perlu Perhatian</p>
      <p class="stat__value"><?= $obat_menipis ?></p>
      <p class="stat__hint"><i class="bi bi-clock-history"></i> <?= $obat_kadaluarsa ?> mendekati kadaluarsa</p>
    </div>
  </div>
</div>

<div class="row g-3 g-xl-4">
  <!-- Grafik penjualan -->
  <div class="col-lg-8">
    <div class="card-app h-100">
      <div class="card-app__head">
        <div>
          <h2 class="card-app__title">Tren Penjualan</h2>
          <span class="cell-muted">7 hari terakhir</span>
        </div>
        <span class="pill pill-teal ms-auto"><i class="bi bi-graph-up-arrow"></i> Omzet harian</span>
      </div>
      <div class="card-app__body">
        <canvas id="chartPenjualan" height="110"></canvas>
      </div>
    </div>
  </div>

  <!-- Obat terlaris -->
  <div class="col-lg-4">
    <div class="card-app h-100">
      <div class="card-app__head"><h2 class="card-app__title">Obat Terlaris</h2>
        <span class="cell-muted ms-auto">30 hari</span></div>
      <div class="card-app__body">
        <?php $rank = 0; if ($terlaris->num_rows): while ($t = $terlaris->fetch_assoc()): $rank++; ?>
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="d-grid" style="width:30px;height:30px;place-items:center;border-radius:9px;
                 background:var(--teal-50);color:var(--teal-700);font-weight:700;font-family:'Plus Jakarta Sans'">
              <?= $rank ?>
            </div>
            <div class="flex-grow-1" style="min-width:0">
              <div class="cell-strong text-truncate" style="font-size:13.5px"><?= e($t['nama_obat']) ?></div>
              <div class="cell-muted"><?= (int)$t['qty'] ?> terjual &middot; <?= rupiah_singkat($t['total']) ?></div>
            </div>
          </div>
        <?php endwhile; else: ?>
          <div class="empty-state"><i class="bi bi-inbox"></i>Belum ada penjualan</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Stok menipis -->
  <div class="col-lg-7">
    <div class="card-app h-100">
      <div class="card-app__head">
        <h2 class="card-app__title">Peringatan Stok Menipis</h2>
        <a href="obat" class="btn btn-soft btn-sm ms-auto">Kelola Obat <i class="bi bi-arrow-right ms-1"></i></a>
      </div>
      <div class="table-responsive">
        <table class="table-app">
          <thead><tr><th>Obat</th><th>Kategori</th><th class="text-end">Stok</th><th class="text-end">Min.</th></tr></thead>
          <tbody>
          <?php if ($menipis_list->num_rows): while ($m = $menipis_list->fetch_assoc()): ?>
            <tr>
              <td>
                <div class="cell-strong"><?= e($m['nama_obat']) ?></div>
                <div class="cell-muted"><?= e($m['kode_obat']) ?></div>
              </td>
              <td><span class="pill pill-gray"><?= e($m['nama_kategori'] ?? '-') ?></span></td>
              <td class="text-end">
                <span class="pill <?= $m['stok']==0?'pill-rose':'pill-amber' ?> pill-dot">
                  <?= (int)$m['stok'] ?>
                </span>
              </td>
              <td class="text-end cell-muted"><?= (int)$m['stok_minimal'] ?></td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="4"><div class="empty-state"><i class="bi bi-check2-circle"></i>Semua stok aman 👍</div></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Transaksi terbaru -->
  <div class="col-lg-5">
    <div class="card-app h-100">
      <div class="card-app__head">
        <h2 class="card-app__title">Transaksi Terbaru</h2>
        <a href="transaksi" class="btn btn-soft btn-sm ms-auto">Lihat Semua</a>
      </div>
      <div class="table-responsive">
        <table class="table-app">
          <thead><tr><th>No. Transaksi</th><th>Waktu</th><th class="text-end">Total</th></tr></thead>
          <tbody>
          <?php if ($transaksi_baru->num_rows): while ($trx = $transaksi_baru->fetch_assoc()): ?>
            <tr>
              <td>
                <div class="cell-strong" style="font-size:13px"><?= e($trx['no_transaksi']) ?></div>
                <div class="cell-muted"><i class="bi bi-person me-1"></i><?= e($trx['kasir'] ?? '-') ?></div>
              </td>
              <td class="cell-muted"><?= tgl_indo($trx['tanggal'], true) ?></td>
              <td class="text-end cell-strong"><?= rupiah($trx['total']) ?></td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="3"><div class="empty-state"><i class="bi bi-inbox"></i>Belum ada transaksi</div></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php
$extra_js = "
<script src='https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js'></script>
<script>
const ctx = document.getElementById('chartPenjualan');
const grad = ctx.getContext('2d').createLinearGradient(0,0,0,260);
grad.addColorStop(0,'rgba(13,148,136,.28)');
grad.addColorStop(1,'rgba(13,148,136,0)');
new Chart(ctx,{
  type:'line',
  data:{
    labels: " . json_encode($labels) . ",
    datasets:[{
      label:'Omzet', data: " . json_encode($data_omzet) . ",
      borderColor:'#0f766e', backgroundColor:grad, borderWidth:2.5,
      fill:true, tension:.38, pointBackgroundColor:'#0f766e',
      pointRadius:4, pointHoverRadius:6, pointBorderColor:'#fff', pointBorderWidth:2
    }]
  },
  options:{
    responsive:true, maintainAspectRatio:true,
    plugins:{ legend:{display:false},
      tooltip:{ backgroundColor:'#0f172a', padding:12, cornerRadius:10, displayColors:false,
        callbacks:{ label:(c)=> 'Rp ' + c.parsed.y.toLocaleString('id-ID') } } },
    scales:{
      y:{ beginAtZero:true, grid:{color:'#eef2f1'}, border:{display:false},
          ticks:{ color:'#94a3b8', callback:(v)=> v>=1000? (v/1000)+'rb' : v } },
      x:{ grid:{display:false}, border:{display:false}, ticks:{color:'#94a3b8', font:{size:11}} }
    }
  }
});
</script>";

require_once __DIR__ . '/includes/footer.php';
