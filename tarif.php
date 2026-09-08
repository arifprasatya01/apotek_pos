<?php
require_once __DIR__ . '/config/config.php';
require_login();

$page_title = 'Tarif & PPN';
$page_crumb = 'Master tingkat harga jual dan pajak';

// Hanya admin & apoteker
if (!has_role('admin', 'apoteker')) {
    set_flash('danger', 'Anda tidak punya akses ke halaman ini.');
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_ppn') {
        $ppn_lama = (float) get_setting($conn, 'ppn_persen', 0);
        $ppn = (float) ($_POST['ppn_persen'] ?? 0);
        set_setting($conn, 'ppn_persen', (string) $ppn);
        log_aktivitas($conn, 'tarif', 'edit_ppn', "PPN diubah dari {$ppn_lama}% menjadi {$ppn}%");
        set_flash('success', 'PPN diperbarui menjadi ' . $ppn . '%. Klik "Terapkan ke Harga" agar harga jual ikut dihitung ulang.');
        redirect('tarif.php');

    } elseif ($action === 'save') {
        $id      = (int) ($_POST['id'] ?? 0);
        $nama    = trim($_POST['nama_tarif'] ?? '');
        $margin  = (float) ($_POST['margin_persen'] ?? 0);
        $aktif   = isset($_POST['aktif']) ? 1 : 0;
        $default = isset($_POST['is_default']) ? 1 : 0;

        if ($nama === '') {
            set_flash('danger', 'Nama tarif wajib diisi.');
            redirect('tarif.php');
        }

        // Tarif default wajib aktif, supaya tidak "hilang" dari pilihan di kasir
        if ($default) $aktif = 1;

        if ($default) $conn->query('UPDATE tarif SET is_default=0');

        if ($id > 0) {
            $old = $conn->query("SELECT * FROM tarif WHERE id=$id")->fetch_assoc();
            $s = $conn->prepare('UPDATE tarif SET nama_tarif=?, margin_persen=?, is_default=?, aktif=? WHERE id=?');
            $s->bind_param('sdiii', $nama, $margin, $default, $aktif, $id);
            $s->execute();

            // Sinkronkan margin baru ke semua obat metode "margin" di tarif ini,
            // jadi tidak perlu klik "Terapkan ke Harga" lagi setiap edit tarif.
            $ppn = (float) get_setting($conn, 'ppn_persen', 0);
            $conn->query("UPDATE obat_harga oh
                JOIN obat o ON o.id = oh.obat_id
                SET oh.margin_persen = $margin,
                    oh.harga_jual = ROUND((o.harga_beli + o.harga_beli*$ppn/100) * (1 + $margin/100))
                WHERE oh.tarif_id = $id AND oh.metode = 'margin'");

            if ($default) {
                $conn->query("UPDATE obat o JOIN obat_harga oh ON oh.obat_id=o.id
                              SET o.harga_jual=oh.harga_jual WHERE oh.tarif_id=$id");
            }

            $ubah = [];
            if ($old) {
                if ((float) $old['margin_persen'] !== $margin) $ubah[] = "margin: {$old['margin_persen']}% → {$margin}%";
                if ((int) $old['aktif'] !== $aktif)             $ubah[] = 'status: ' . ($aktif ? 'aktif' : 'nonaktif');
                if (!$old['is_default'] && $default)            $ubah[] = 'dijadikan default';
            }
            log_aktivitas($conn, 'tarif', 'edit', "Edit tarif \"$nama\"" . ($ubah ? ': ' . implode(', ', $ubah) : ''));
            set_flash('success', 'Tarif diperbarui. Harga obat metode "Margin" di tarif ini ikut diperbarui otomatis.');
        } else {
            $s = $conn->prepare('INSERT INTO tarif (nama_tarif,margin_persen,is_default,aktif) VALUES (?,?,?,?)');
            $s->bind_param('sdii', $nama, $margin, $default, $aktif);
            $s->execute();
            $newId = $conn->insert_id;
            // Buat baris harga untuk semua obat pada tarif baru
            $ppn = (float) get_setting($conn, 'ppn_persen', 0);
            $conn->query("INSERT IGNORE INTO obat_harga (obat_id,tarif_id,metode,margin_persen,harga_jual)
                SELECT o.id,$newId,'margin',$margin, ROUND((o.harga_beli + o.harga_beli*$ppn/100)*(1+$margin/100)) FROM obat o");
            log_aktivitas($conn, 'tarif', 'tambah', "Tambah tarif \"$nama\" (margin {$margin}%)" . ($default ? ', dijadikan default' : ''));
            set_flash('success', 'Tarif baru ditambahkan beserta harga untuk semua obat.');
        }
        redirect('tarif.php');

    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $cek = $conn->query("SELECT nama_tarif, is_default FROM tarif WHERE id=$id")->fetch_assoc();
        if ($cek && $cek['is_default']) {
            set_flash('danger', 'Tarif default tidak bisa dihapus. Jadikan tarif lain sebagai default dulu.');
        } else {
            $conn->query("DELETE FROM tarif WHERE id=$id");
            set_flash('success', 'Tarif dihapus.');
            if ($cek) log_aktivitas($conn, 'tarif', 'hapus', "Hapus tarif \"{$cek['nama_tarif']}\"");
        }
        redirect('tarif.php');

    } elseif ($action === 'recompute') {
        // Hitung ulang semua harga metode 'margin' memakai margin tarif saat ini,
        // lalu sinkron tarif default ke obat.harga_jual
        $ppn = (float) get_setting($conn, 'ppn_persen', 0);
        $conn->query("UPDATE obat_harga oh
            JOIN obat o ON o.id=oh.obat_id
            JOIN tarif t ON t.id=oh.tarif_id
            SET oh.margin_persen = t.margin_persen,
                oh.harga_jual = ROUND((o.harga_beli + o.harga_beli*$ppn/100) * (1 + t.margin_persen/100))
            WHERE oh.metode='margin'");
        $conn->query("UPDATE obat o
            JOIN obat_harga oh ON oh.obat_id=o.id
            JOIN tarif t ON t.id=oh.tarif_id
            SET o.harga_jual = oh.harga_jual
            WHERE t.is_default=1");
        log_aktivitas($conn, 'tarif', 'recompute', "Hitung ulang harga semua obat metode margin (PPN {$ppn}%) & sinkron tarif default ke kasir");
        set_flash('success', 'Harga jual dihitung ulang & tarif default diterapkan ke kasir.');
        redirect('tarif.php');
    }
}

require_once __DIR__ . '/includes/header.php';

$ppn = (float) get_setting($conn, 'ppn_persen', 0);
$rows = $conn->query("SELECT t.*, (SELECT COUNT(*) FROM obat_harga oh WHERE oh.tarif_id=t.id) jml FROM tarif t ORDER BY t.is_default DESC, t.nama_tarif");
?>

<div class="row g-3">
  <!-- PPN -->
  <div class="col-lg-4">
    <div class="card-app h-100">
      <div class="card-app__head"><h2 class="card-app__title"><i class="bi bi-percent me-2"></i>PPN</h2></div>
      <div class="card-app__body">
        <p class="cell-muted" style="font-size:13px">PPN ditambahkan ke harga beli sebelum margin keuntungan dihitung.</p>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_ppn">
          <label class="form-label">Persentase PPN</label>
          <div class="input-group mb-3">
            <input type="number" step="0.01" name="ppn_persen" class="form-control" value="<?= e($ppn) ?>">
            <span class="input-group-text">%</span>
          </div>
          <button class="btn btn-teal w-100"><i class="bi bi-check-lg me-1"></i>Simpan PPN</button>
        </form>
        <hr class="divider-soft">
        <form method="post" onsubmit="return confirm('Hitung ulang semua harga jual (metode margin) dan terapkan tarif default ke kasir?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="recompute">
          <button class="btn btn-soft w-100"><i class="bi bi-arrow-repeat me-1"></i>Terapkan ke Harga</button>
        </form>
        <p class="cell-muted mt-2" style="font-size:11.5px">Menghitung ulang harga semua obat yang memakai metode margin.</p>
      </div>
    </div>
  </div>

  <!-- Tarif -->
  <div class="col-lg-8">
    <div class="card-app h-100">
      <div class="card-app__head">
        <h2 class="card-app__title"><i class="bi bi-layers me-2"></i>Tingkat Tarif</h2>
        <button class="btn btn-teal btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#modalTarif" onclick="resetForm()">
          <i class="bi bi-plus-lg me-1"></i>Tambah Tarif
        </button>
      </div>
      <div class="table-responsive">
        <table class="table-app">
          <thead><tr><th>Nama Tarif</th><th class="text-end">Margin</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
          <tbody>
          <?php while ($r = $rows->fetch_assoc()): ?>
            <tr>
              <td class="cell-strong">
                <?= e($r['nama_tarif']) ?>
                <?php if ($r['is_default']): ?><span class="pill pill-teal ms-1"><i class="bi bi-star-fill"></i>Default</span><?php endif; ?>
              </td>
              <td class="text-end cell-strong"><?= rtrim(rtrim(number_format($r['margin_persen'],2,',','.'),'0'),',') ?>%</td>
              <td>
                <?php if ($r['aktif']): ?><span class="pill pill-teal">Aktif</span><?php else: ?><span class="pill pill-gray">Nonaktif</span><?php endif; ?>
              </td>
              <td class="text-end">
                <button class="btn btn-soft btn-sm btn-icon" title="Edit" onclick='editTarif(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i></button>
                <form method="post" class="d-inline js-confirm-delete" data-name="tarif <?= e($r['nama_tarif']) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-sm btn-icon" style="background:var(--rose-bg);color:var(--rose)"><i class="bi bi-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <p class="cell-muted px-3 pb-2" style="font-size:12px">Atur harga tiap obat per tarif di menu <b>Harga Jual</b>.</p>
    </div>
  </div>
</div>

<!-- Modal Tarif -->
<div class="modal fade" id="modalTarif" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <div class="modal-header">
        <h5 class="modal-title" id="modalTarifTitle">Tambah Tarif</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="t_id">
        <div class="mb-3">
          <label class="form-label">Nama Tarif <span class="text-danger">*</span></label>
          <input type="text" name="nama_tarif" id="t_nama" class="form-control" placeholder="Mis. Eceran / Grosir" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Margin Keuntungan (%)</label>
          <input type="number" step="0.01" name="margin_persen" id="t_margin" class="form-control" value="0">
        </div>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="is_default" id="t_default">
          <label class="form-check-label" for="t_default">Jadikan tarif default (dipakai di kasir)</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="aktif" id="t_aktif" checked>
          <label class="form-check-label" for="t_aktif">Aktif</label>
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
  document.getElementById('modalTarifTitle').textContent='Tambah Tarif';
  document.getElementById('t_id').value='';
  document.getElementById('t_nama').value='';
  document.getElementById('t_margin').value='0';
  document.getElementById('t_default').checked=false;
  document.getElementById('t_aktif').checked=true;
}
function editTarif(d){
  document.getElementById('modalTarifTitle').textContent='Edit Tarif';
  document.getElementById('t_id').value=d.id;
  document.getElementById('t_nama').value=d.nama_tarif;
  document.getElementById('t_margin').value=d.margin_persen;
  document.getElementById('t_default').checked = d.is_default==1;
  document.getElementById('t_aktif').checked = d.aktif==1;
  new bootstrap.Modal('#modalTarif').show();
}
</script>
JS;
require_once __DIR__ . '/includes/footer.php';