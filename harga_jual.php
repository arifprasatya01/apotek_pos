<?php
require_once __DIR__ . '/config/config.php';
require_login();

$page_title = 'Harga Jual';
$page_crumb = 'Tetapkan harga jual obat per tarif';

if (!has_role('admin', 'apoteker')) {
    set_flash('danger', 'Anda tidak punya akses ke halaman ini.');
    redirect('index.php');
}

$ppn = (float) get_setting($conn, 'ppn_persen', 0);

// Tarif aktif
$tarifAll = $conn->query("SELECT * FROM tarif ORDER BY is_default DESC, nama_tarif");
$tarifList = [];
while ($t = $tarifAll->fetch_assoc()) $tarifList[] = $t;
if (empty($tarifList)) {
    echo '<div class="empty-state"><i class="bi bi-layers"></i>Belum ada tarif. Buat dulu di menu Tarif & PPN.</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Tarif terpilih
$tarif_id = (int) ($_GET['tarif'] ?? $_POST['tarif_id'] ?? $tarifList[0]['id']);
$tarifAktif = null;
foreach ($tarifList as $t) if ((int)$t['id'] === $tarif_id) $tarifAktif = $t;
if (!$tarifAktif) { $tarifAktif = $tarifList[0]; $tarif_id = (int)$tarifAktif['id']; }

// Simpan harga
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $metode = $_POST['metode'] ?? [];
    $harga  = $_POST['harga']  ?? [];

    $up = $conn->prepare(
        'INSERT INTO obat_harga (obat_id,tarif_id,metode,margin_persen,harga_jual)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE metode=VALUES(metode), margin_persen=VALUES(margin_persen), harga_jual=VALUES(harga_jual)'
    );
    $hb = $conn->prepare('SELECT harga_beli FROM obat WHERE id=?');

    $jml_margin = 0; $jml_fixed = 0;
    foreach ($metode as $oid => $met) {
        $oid = (int) $oid;
        $met = $met === 'fixed' ? 'fixed' : 'margin';

        $hb->bind_param('i', $oid); $hb->execute();
        $row = $hb->get_result()->fetch_assoc();
        if (!$row) continue;

        if ($met === 'margin') {
            // Margin selalu ikut nilai tarif yang sedang dipilih, bukan input per obat
            $mg = (float) $tarifAktif['margin_persen'];
            $hj = hitung_harga_jual((float)$row['harga_beli'], $ppn, $mg);
            $jml_margin++;
        } else {
            $mg = 0;
            $hj = (int) ($harga[$oid] ?? 0);
            $jml_fixed++;
        }
        $up->bind_param('iisdi', $oid, $tarif_id, $met, $mg, $hj);
        $up->execute();
    }

    // Sinkron ke kasir bila tarif ini default
    if ($tarifAktif['is_default']) {
        $conn->query("UPDATE obat o JOIN obat_harga oh ON oh.obat_id=o.id
                      SET o.harga_jual=oh.harga_jual WHERE oh.tarif_id=$tarif_id");
    }
    log_aktivitas($conn, 'harga_jual', 'edit',
        "Update harga jual tarif \"{$tarifAktif['nama_tarif']}\": {$jml_margin} obat metode margin, {$jml_fixed} obat metode tetap"
        . ($tarifAktif['is_default'] ? ' (diterapkan ke kasir)' : ''));
    set_flash('success', 'Harga jual tarif "' . $tarifAktif['nama_tarif'] . '" disimpan.' . ($tarifAktif['is_default'] ? ' Diterapkan ke kasir.' : ''));
    redirect('harga_jual.php?tarif=' . $tarif_id);
}

require_once __DIR__ . '/includes/header.php';

$rows = $conn->query(
    "SELECT o.id,o.kode_obat,o.nama_obat,o.harga_beli,
            oh.metode, oh.margin_persen, oh.harga_jual
     FROM obat o
     LEFT JOIN obat_harga oh ON oh.obat_id=o.id AND oh.tarif_id=$tarif_id
     ORDER BY o.nama_obat"
);
?>

<div class="card-app">
  <div class="card-app__head flex-wrap gap-2">
    <h2 class="card-app__title"><i class="bi bi-cash-coin me-2"></i>Harga Jual per Tarif</h2>
    <form method="get" class="d-flex align-items-center gap-2 ms-auto">
      <label class="form-label mb-0">Tarif:</label>
      <select name="tarif" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
        <?php foreach ($tarifList as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= $t['id']==$tarif_id?'selected':'' ?>>
            <?= e($t['nama_tarif']) ?><?= $t['is_default']?' (default)':'' ?> — margin <?= rtrim(rtrim(number_format($t['margin_persen'],2),'0'),'.') ?>%
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <div class="px-3 pt-2 cell-muted" style="font-size:13px">
    <i class="bi bi-info-circle me-1"></i>PPN aktif <b><?= rtrim(rtrim(number_format($ppn,2),'0'),'.') ?>%</b>.
    Rumus margin: <b>(harga beli + PPN) + margin%</b>. Margin mengikuti persentase tarif "<b><?= e($tarifAktif['nama_tarif']) ?></b>" — ubah di menu Tarif & PPN.
    Pilih <b>Tetap</b> untuk mengisi harga manual per obat.
  </div>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="tarif_id" value="<?= $tarif_id ?>">
    <div class="table-responsive">
      <table class="table-app" id="tblHarga">
        <thead><tr>
          <th>Obat</th>
          <th class="text-end">Harga Beli</th>
          <th class="text-end">+ PPN</th>
          <th>Metode</th>
          <th class="text-end">Margin %</th>
          <th class="text-end">Harga Jual</th>
        </tr></thead>
        <tbody>
        <?php while ($r = $rows->fetch_assoc()):
          $met = $r['metode'] ?? 'margin';
          $mg  = $met === 'margin' ? (float) $tarifAktif['margin_persen'] : ($r['margin_persen'] !== null ? (float) $r['margin_persen'] : 0);
          $hb  = (int) $r['harga_beli'];
          $hjual = $met === 'margin' ? hitung_harga_jual($hb, $ppn, $mg) : ($r['harga_jual'] !== null ? (int) $r['harga_jual'] : 0);
          $oid = (int) $r['id'];
        ?>
          <tr data-hb="<?= $hb ?>">
            <td class="cell-strong" style="font-size:13.5px"><?= e($r['nama_obat']) ?><div class="cell-muted" style="font-size:11px"><?= e($r['kode_obat']) ?></div></td>
            <td class="text-end cell-muted"><?= rupiah($hb) ?></td>
            <td class="text-end cell-muted col-ppn"><?= rupiah(round($hb + $hb*$ppn/100)) ?></td>
            <td>
              <select name="metode[<?= $oid ?>]" class="form-select form-select-sm sel-metode" style="width:auto">
                <option value="margin" <?= $met=='margin'?'selected':'' ?>>Margin</option>
                <option value="fixed"  <?= $met=='fixed'?'selected':'' ?>>Tetap</option>
              </select>
            </td>
            <td class="text-end" style="width:110px">
              <input type="number" step="0.01" class="form-control form-control-sm text-end in-margin" value="<?= $mg ?>" <?= $met=='margin'?'readonly':'disabled' ?>>
            </td>
            <td class="text-end" style="width:150px">
              <input type="number" name="harga[<?= $oid ?>]" class="form-control form-control-sm text-end in-harga" value="<?= $hjual ?>" <?= $met=='margin'?'readonly':'' ?>>
            </td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
    <div class="p-3 d-flex justify-content-end">
      <button class="btn btn-teal"><i class="bi bi-save me-1"></i>Simpan Harga Tarif Ini</button>
    </div>
  </form>
</div>

<?php $extra_js = <<<JS
<script>
const PPN = $ppn;
const MARGIN_TARIF = {$tarifAktif['margin_persen']};

function hitungBaris(tr){
  const hb = parseFloat(tr.dataset.hb) || 0;
  const met = tr.querySelector('.sel-metode').value;
  const inMargin = tr.querySelector('.in-margin');
  const inHarga = tr.querySelector('.in-harga');
  if(met === 'margin'){
    inMargin.value = MARGIN_TARIF;
    inMargin.readOnly = true;
    inMargin.disabled = false;
    const harga = Math.round((hb + hb*PPN/100) * (1 + MARGIN_TARIF/100));
    inHarga.value = harga;
    inHarga.readOnly = true;
  } else {
    inMargin.disabled = true;
    inHarga.readOnly = false;
  }
}
document.querySelectorAll('#tblHarga tbody tr').forEach(tr => {
  const met = tr.querySelector('.sel-metode');
  met.addEventListener('change', ()=>hitungBaris(tr));
  hitungBaris(tr);
});
</script>
JS;
require_once __DIR__ . '/includes/footer.php';