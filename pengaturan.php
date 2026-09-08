<?php
require_once __DIR__ . '/config/config.php';
require_login();

$page_title = 'Profil Apotek';
$page_crumb = 'Identitas, struk & pengaturan printer';

if (!has_role('admin')) {
    set_flash('danger', 'Hanya admin yang dapat mengubah pengaturan.');
    redirect('index.php');
}

$fields = ['apotek_nama', 'apotek_alamat', 'apotek_telp', 'apotek_wa', 'struk_footer', 'printer_name'];

/* ---------- Simpan pengaturan ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $ubah = [];
    foreach ($fields as $f) {
        $val = trim($_POST[$f] ?? '');
        if ($f === 'apotek_wa') {
            $val = preg_replace('/[^0-9]/', '', $val);
            if ($val !== '' && $val[0] === '0') $val = '62' . substr($val, 1);
        }
        $lama = get_setting($conn, $f, '');
        if ($lama !== $val) $ubah[] = $f;
        set_setting($conn, $f, $val);
    }
    if ($ubah) log_aktivitas($conn, 'pengaturan', 'edit', 'Update profil apotek: ' . implode(', ', $ubah));

    // Upload logo
    if (!empty($_FILES['logo']['tmp_name'])) {
        $allowed = ['image/png','image/jpeg','image/gif','image/webp'];
        $mime    = mime_content_type($_FILES['logo']['tmp_name']);
        if (!in_array($mime, $allowed)) {
            set_flash('danger', 'Format logo harus PNG, JPG, GIF, atau WebP.');
            redirect('pengaturan.php');
        }
        $ext  = ['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp'][$mime];
        $dir  = __DIR__ . '/assets/img/';
        // Hapus logo lama
        foreach (glob($dir . 'logo_apotek.*') as $old) @unlink($old);
        $dest = $dir . 'logo_apotek.' . $ext;
        if (!move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
            set_flash('danger', 'Gagal menyimpan logo. Pastikan folder assets/img/ dapat ditulis.');
            redirect('pengaturan.php');
        }
        set_setting($conn, 'logo_file', 'assets/img/logo_apotek.' . $ext);
        log_aktivitas($conn, 'pengaturan', 'edit', 'Upload logo apotek baru');
    }

    // Hapus logo
    if (isset($_POST['hapus_logo'])) {
        $logo = get_setting($conn, 'logo_file', '');
        if ($logo && file_exists(__DIR__ . '/' . $logo)) @unlink(__DIR__ . '/' . $logo);
        set_setting($conn, 'logo_file', '');
        log_aktivitas($conn, 'pengaturan', 'edit', 'Hapus logo apotek');
    }

    set_flash('success', 'Pengaturan tersimpan.');
    redirect('pengaturan.php');
}

require_once __DIR__ . '/includes/header.php';

$cfg = [];
foreach ($fields as $f) $cfg[$f] = get_setting($conn, $f, '');
$cfg['logo_file'] = get_setting($conn, 'logo_file', '');
?>

<div class="row g-3">

  <!-- ===== KOLOM KIRI ===== -->
  <div class="col-lg-7">
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">

      <!-- Identitas -->
      <div class="card-app mb-3">
        <div class="card-app__head"><h2 class="card-app__title"><i class="bi bi-shop me-2"></i>Identitas Apotek</h2></div>
        <div class="card-app__body">

          <!-- Logo -->
          <div class="mb-3">
            <label class="form-label">Logo Apotek</label>
            <div class="d-flex align-items-center gap-3 flex-wrap">
              <?php if ($cfg['logo_file'] && file_exists(__DIR__ . '/' . $cfg['logo_file'])): ?>
                <img src="<?= e($cfg['logo_file']) ?>?v=<?= time() ?>" alt="Logo"
                     style="height:64px;border-radius:10px;border:1px solid var(--line);object-fit:contain;background:#fafafa;padding:4px">
                <button type="submit" name="hapus_logo" value="1"
                        class="btn btn-sm" style="background:var(--rose-bg);color:var(--rose)"
                        onclick="return confirm('Hapus logo?')">
                  <i class="bi bi-trash me-1"></i>Hapus Logo
                </button>
              <?php else: ?>
                <div style="width:64px;height:64px;border-radius:10px;border:2px dashed var(--line);
                            display:grid;place-items:center;color:var(--muted);font-size:28px">
                  <i class="bi bi-image"></i>
                </div>
              <?php endif; ?>
              <div class="flex-grow-1">
                <input type="file" name="logo" class="form-control" accept="image/*">
                <div class="cell-muted mt-1" style="font-size:12px">PNG/JPG/WebP, maks 2MB. Tampil di header struk cetak.</div>
              </div>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Nama Apotek</label>
            <input type="text" name="apotek_nama" class="form-control"
                   value="<?= e($cfg['apotek_nama']) ?>" placeholder="Apotek Sehat">
          </div>
          <div class="mb-3">
            <label class="form-label">Alamat</label>
            <textarea name="apotek_alamat" class="form-control" rows="2"
                      placeholder="Jl. Kesehatan No. 1, Karawang"><?= e($cfg['apotek_alamat']) ?></textarea>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Telepon</label>
              <input type="text" name="apotek_telp" class="form-control"
                     value="<?= e($cfg['apotek_telp']) ?>" placeholder="(0267) 123456">
            </div>
            <div class="col-md-6">
              <label class="form-label">No. WhatsApp Apotek</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-whatsapp" style="color:#25D366"></i></span>
                <input type="tel" name="apotek_wa" class="form-control"
                       value="<?= e($cfg['apotek_wa']) ?>" placeholder="08xxxxxxxxxx">
              </div>
            </div>
          </div>
          <div class="mt-3">
            <label class="form-label">Slogan / Catatan Kaki Struk</label>
            <input type="text" name="struk_footer" class="form-control"
                   value="<?= e($cfg['struk_footer']) ?>"
                   placeholder="Terima kasih atas kunjungan Anda 🙏">
            <div class="cell-muted mt-1" style="font-size:12px">Muncul di bagian bawah struk. Kosongkan untuk pakai teks default.</div>
          </div>
        </div>
      </div>

      <!-- Printer -->
      <div class="card-app mb-3">
        <div class="card-app__head"><h2 class="card-app__title"><i class="bi bi-printer me-2"></i>Pengaturan Printer Thermal</h2></div>
        <div class="card-app__body">
          <p class="cell-muted" style="font-size:13px">
            Nama printer dipakai saat <b>cetak dari PC/laptop</b>. Pastikan printer sudah ter-pair via Bluetooth dan terinstal di Windows.
            Untuk <b>Android</b>, gunakan tombol <span class="pill pill-sky" style="font-size:11px"><i class="bi bi-bluetooth"></i>Cetak BT</span> di halaman kasir.
          </p>
          <div class="mb-3">
            <label class="form-label">Nama Printer (sesuai di Windows)</label>
            <input type="text" name="printer_name" id="inputPrinterName" class="form-control"
                   value="<?= e($cfg['printer_name']) ?>" placeholder="Mis: ALGO RP02N">
            <div class="cell-muted mt-1" style="font-size:12px">
              Cek di Windows: <b>Settings → Bluetooth & devices → Printers & scanners</b>
            </div>
          </div>
          <button type="button" class="btn btn-soft btn-sm" onclick="testPrint()">
            <i class="bi bi-printer me-1"></i>Test Print
          </button>
        </div>
      </div>

      <button class="btn btn-teal px-4"><i class="bi bi-save me-1"></i>Simpan Pengaturan</button>
    </form>
  </div>

  <!-- ===== KOLOM KANAN: Pratinjau struk ===== -->
  <div class="col-lg-5">
    <div class="card-app" style="position:sticky;top:90px">
      <div class="card-app__head"><h2 class="card-app__title"><i class="bi bi-receipt me-2"></i>Pratinjau Struk (58mm)</h2></div>
      <div class="card-app__body d-flex justify-content-center">
        <div id="previewStruk" class="struk-preview">
          <?php if ($cfg['logo_file'] && file_exists(__DIR__ . '/' . $cfg['logo_file'])): ?>
            <img src="<?= e($cfg['logo_file']) ?>?v=<?= time() ?>" class="struk-logo" alt="Logo">
          <?php endif; ?>
          <div class="struk-nama"><?= e($cfg['apotek_nama'] ?: 'Nama Apotek') ?></div>
          <?php if ($cfg['apotek_alamat']): ?>
            <div class="struk-sub"><?= e($cfg['apotek_alamat']) ?></div>
          <?php endif; ?>
          <?php if ($cfg['apotek_telp']): ?>
            <div class="struk-sub">Telp: <?= e($cfg['apotek_telp']) ?></div>
          <?php endif; ?>
          <div class="struk-garis"></div>
          <div class="struk-row"><span>Contoh Obat A</span><span>Rp 12.000</span></div>
          <div class="struk-row-sub">1 × Rp 12.000</div>
          <div class="struk-row"><span>Contoh Obat B</span><span>Rp 8.000</span></div>
          <div class="struk-row-sub">1 × Rp 8.000</div>
          <div class="struk-garis"></div>
          <div class="struk-row struk-total"><span>Total</span><span>Rp 20.000</span></div>
          <div class="struk-row struk-bayar"><span>Bayar</span><span>Rp 20.000</span></div>
          <div class="struk-row struk-bayar"><span>Kembali</span><span>Rp 0</span></div>
          <div class="struk-garis"></div>
          <div class="struk-footer"><?= e($cfg['struk_footer'] ?: 'Terima kasih atas kunjungan Anda 🙏') ?></div>
        </div>
      </div>
    </div>
  </div>

</div>

<?php $extra_js = <<<'JS'
<script>
function testPrint() {
  const nama = document.getElementById('inputPrinterName').value.trim();
  if (!nama) { alert('Isi nama printer dulu sebelum test print.'); return; }
  // Buka jendela print kecil
  const w = window.open('', '_blank', 'width=300,height=400');
  w.document.write(`<!DOCTYPE html><html><head>
    <style>
      body{font-family:monospace;font-size:12px;width:58mm;margin:0;padding:4mm}
      .c{text-align:center} hr{border:none;border-top:1px dashed #000;margin:4px 0}
    </style>
  </head><body>
    <div class="c"><b>TEST PRINT</b></div>
    <div class="c">Printer: ${nama}</div>
    <hr>
    <div class="c">Apotek Sehat POS</div>
    <div class="c">Struk thermal 58mm</div>
    <hr>
    <div class="c">OK ✓</div>
  </body></html>`);
  w.document.close();
  w.focus();
  w.print();
  setTimeout(() => w.close(), 1000);
}
</script>
JS;
require_once __DIR__ . '/includes/footer.php';