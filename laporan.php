<?php
require_once __DIR__ . '/config/config.php';
require_login();

$page_title = 'Laporan Penjualan';
$page_crumb = 'Analisis penjualan per periode';
require_once __DIR__ . '/includes/header.php';

$dari   = $_GET['dari'] ?? date('Y-m-01');
$sampai = $_GET['sampai'] ?? date('Y-m-d');

// Rekap harian
$harian = $conn->prepare(
    "SELECT DATE(tanggal) tgl, COUNT(*) jml, SUM(total) total
     FROM penjualan WHERE DATE(tanggal) BETWEEN ? AND ?
     GROUP BY DATE(tanggal) ORDER BY tgl"
);
$harian->bind_param('ss', $dari, $sampai); $harian->execute();
$rekap = $harian->get_result();

$labels=[]; $values=[]; $rekap_rows=[]; $grand=0; $grand_trx=0;
while ($r = $rekap->fetch_assoc()) {
    $labels[] = date('j/n', strtotime($r['tgl']));
    $values[] = (int) $r['total'];
    $rekap_rows[] = $r;
    $grand += (int) $r['total'];
    $grand_trx += (int) $r['jml'];
}

// Produk terlaris periode
$tl = $conn->prepare(
    "SELECT pd.nama_obat, SUM(pd.jumlah) qty, SUM(pd.subtotal) total
     FROM penjualan_detail pd JOIN penjualan p ON p.id=pd.penjualan_id
     WHERE DATE(p.tanggal) BETWEEN ? AND ?
     GROUP BY pd.nama_obat ORDER BY qty DESC LIMIT 8"
);
$tl->bind_param('ss', $dari, $sampai); $tl->execute();
$terlaris = $tl->get_result();
?>

<div class="card-app mb-4">
  <div class="card-app__body py-3">
    <form method="get" class="d-flex align-items-end gap-3 flex-wrap">
      <div><label class="form-label mb-1">Dari Tanggal</label><input type="date" name="dari" class="form-control" value="<?= e($dari) ?>"></div>
      <div><label class="form-label mb-1">Sampai Tanggal</label><input type="date" name="sampai" class="form-control" value="<?= e($sampai) ?>"></div>
      <button class="btn btn-teal"><i class="bi bi-funnel me-1"></i>Tampilkan</button>
      <button type="button" class="btn btn-soft" onclick="window.print()"><i class="bi bi-printer me-1"></i>Cetak / PDF</button>
      <div class="ms-auto text-end">
        <div class="cell-muted">Total Omzet Periode</div>
        <div class="display-font" style="font-weight:800;font-size:22px;color:var(--teal-700)"><?= rupiah($grand) ?></div>
      </div>
    </form>
  </div>
</div>

<div class="row g-3 g-xl-4">
  <div class="col-lg-8">
    <div class="card-app h-100">
      <div class="card-app__head"><h2 class="card-app__title">Grafik Omzet Harian</h2>
        <span class="pill pill-teal ms-auto"><?= count($labels) ?> hari</span></div>
      <div class="card-app__body">
        <?php if ($labels): ?><canvas id="chartLaporan" height="120"></canvas>
        <?php else: ?><div class="empty-state"><i class="bi bi-bar-chart"></i>Tidak ada data pada periode ini.</div><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card-app h-100">
      <div class="card-app__head"><h2 class="card-app__title">Obat Terlaris</h2></div>
      <div class="table-responsive">
        <table class="table-app">
          <thead><tr><th>Obat</th><th class="text-end">Qty</th></tr></thead>
          <tbody>
          <?php if ($terlaris->num_rows): while ($t=$terlaris->fetch_assoc()): ?>
            <tr><td class="cell-strong" style="font-size:13px"><?= e($t['nama_obat']) ?><div class="cell-muted"><?= rupiah($t['total']) ?></div></td>
                <td class="text-end"><span class="pill pill-teal"><?= (int)$t['qty'] ?></span></td></tr>
          <?php endwhile; else: ?>
            <tr><td colspan="2"><div class="empty-state"><i class="bi bi-inbox"></i>Belum ada</div></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card-app">
      <div class="card-app__head"><h2 class="card-app__title">Rekap Harian</h2>
        <span class="cell-muted ms-auto"><?= tgl_indo($dari) ?> &ndash; <?= tgl_indo($sampai) ?></span></div>
      <div class="table-responsive">
        <table class="table-app">
          <thead><tr><th>Tanggal</th><th class="text-end">Jumlah Transaksi</th><th class="text-end">Omzet</th></tr></thead>
          <tbody>
          <?php if ($rekap_rows): foreach ($rekap_rows as $r): ?>
            <tr><td class="cell-strong"><?= tgl_indo($r['tgl']) ?></td>
                <td class="text-end"><?= (int)$r['jml'] ?></td>
                <td class="text-end cell-strong"><?= rupiah($r['total']) ?></td></tr>
          <?php endforeach; else: ?>
            <tr><td colspan="3"><div class="empty-state"><i class="bi bi-calendar-x"></i>Tidak ada data.</div></td></tr>
          <?php endif; ?>
          </tbody>
          <?php if ($rekap_rows): ?>
          <tfoot><tr style="background:var(--teal-50)">
            <td class="cell-strong">TOTAL</td>
            <td class="text-end cell-strong"><?= $grand_trx ?></td>
            <td class="text-end cell-strong" style="color:var(--teal-700)"><?= rupiah($grand) ?></td>
          </tr></tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>
  </div>
</div>

<?php
if ($labels) {
  $extra_js = "
  <script src='https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js'></script>
  <script>
  new Chart(document.getElementById('chartLaporan'),{
    type:'bar',
    data:{ labels: ".json_encode($labels).",
      datasets:[{ label:'Omzet', data: ".json_encode($values).",
        backgroundColor:'#0d9488', borderRadius:6, maxBarThickness:34 }]},
    options:{ responsive:true, plugins:{legend:{display:false},
      tooltip:{backgroundColor:'#0f172a',padding:12,cornerRadius:10,displayColors:false,
        callbacks:{label:c=>'Rp '+c.parsed.y.toLocaleString('id-ID')}}},
      scales:{ y:{beginAtZero:true,grid:{color:'#eef2f1'},border:{display:false},
        ticks:{color:'#94a3b8',callback:v=>v>=1000?(v/1000)+'rb':v}},
        x:{grid:{display:false},border:{display:false},ticks:{color:'#94a3b8'}}}}
  });
  </script>";
}
require_once __DIR__ . '/includes/footer.php';
