<?php
require_once __DIR__ . '/config/config.php';
require_login();

// Hanya admin & apoteker yang boleh akses
if (!has_role('admin', 'apoteker')) {
    set_flash('danger', 'Anda tidak memiliki akses ke halaman ini.');
    redirect('index.php');
}

$page_title = 'Stock Opname';
$page_crumb = 'Rekonsiliasi stok fisik vs sistem';

$user       = current_user();
$is_admin   = has_role('admin');
$success    = '';
$error      = '';

// ============================================================
// Buat Stock Opname Baru
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'buat') {
    $no_opname   = 'SO' . date('Ymd') . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
    $tgl_opname  = trim($_POST['tgl_opname'] ?? date('Y-m-d'));
    $keterangan  = trim($_POST['keterangan'] ?? '');

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO stock_opname (no_opname, tgl_opname, user_id, keterangan) VALUES (?,?,?,?)");
        $uid  = $user['id'];
        $stmt->bind_param('ssis', $no_opname, $tgl_opname, $uid, $keterangan);
        $stmt->execute();
        $opname_id = $conn->insert_id;
        $stmt->close();

        // Salin semua stok saat ini ke detail
        $res_obat = $conn->query("SELECT id, stok FROM obat ORDER BY nama_obat");
        $ins = $conn->prepare("INSERT INTO detail_stock_opname (opname_id, obat_id, stok_sistem) VALUES (?,?,?)");
        while ($ob = $res_obat->fetch_assoc()) {
            $ins->bind_param('iii', $opname_id, $ob['id'], $ob['stok']);
            $ins->execute();
        }
        $ins->close();

        $conn->commit();
        log_aktivitas($conn, 'stock_opname', 'tambah', "Buat stock opname $no_opname");
        set_flash('success', "Stock opname $no_opname berhasil dibuat.");
        redirect("stock_opname.php?id=$opname_id");
    } catch (Exception $e) {
        $conn->rollback();
        $error = 'Gagal membuat stock opname: ' . $e->getMessage();
    }
}

// ============================================================
// Simpan input stok fisik (petugas/apoteker — status draft)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $opname_id   = (int) ($_POST['opname_id'] ?? 0);
    $detail_ids  = $_POST['detail_id']   ?? [];
    $stok_fisiks = $_POST['stok_fisik']  ?? [];
    $keterangans = $_POST['keterangan']  ?? [];

    // Pastikan opname masih draft
    $cek = $conn->query("SELECT status FROM stock_opname WHERE id = $opname_id")->fetch_assoc();
    if (!$cek || $cek['status'] !== 'draft') {
        set_flash('danger', 'Stock opname sudah selesai, tidak bisa diubah.');
        redirect("stock_opname.php?id=$opname_id");
    }

    $stmt = $conn->prepare(
        "UPDATE detail_stock_opname SET stok_fisik=?, selisih=(?-stok_sistem), keterangan=? WHERE id=? AND opname_id=?"
    );
    for ($i = 0; $i < count($detail_ids); $i++) {
        $did   = (int) $detail_ids[$i];
        $stok  = (int) ($stok_fisiks[$i] ?? 0);
        $ket   = trim($keterangans[$i] ?? '');
        $stmt->bind_param('iiisi', $stok, $stok, $ket, $did, $opname_id);
        $stmt->execute();
    }
    $stmt->close();

    log_aktivitas($conn, 'stock_opname', 'edit', "Update stok fisik opname id $opname_id");
    set_flash('success', 'Stok fisik berhasil disimpan.');
    redirect("stock_opname.php?id=$opname_id");
}

// ============================================================
// Selesaikan Opname — terapkan ke stok obat (admin only)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'selesaikan' && $is_admin) {
    $opname_id = (int) ($_POST['opname_id'] ?? 0);

    // Cek apakah ada yang sudah diisi
    $cek = $conn->query(
        "SELECT COUNT(*) c FROM detail_stock_opname WHERE opname_id=$opname_id AND stok_fisik > 0"
    )->fetch_assoc();
    if ($cek['c'] == 0) {
        set_flash('danger', 'Belum ada stok fisik yang diisi.');
        redirect("stock_opname.php?id=$opname_id");
    }

    $conn->begin_transaction();
    try {
        $details = $conn->query(
            "SELECT obat_id, stok_fisik, selisih FROM detail_stock_opname WHERE opname_id=$opname_id"
        );
        while ($d = $details->fetch_assoc()) {
            // Update stok obat
            $sf = (int) $d['stok_fisik'];
            $oid = (int) $d['obat_id'];
            $conn->query("UPDATE obat SET stok=$sf WHERE id=$oid");

            // Log jika selisih != 0
            if ($d['selisih'] != 0) {
                $uid   = $user['id'];
                $sel   = (int) $d['selisih'];
                $ket   = "Stock Opname – " . ($sel > 0 ? "Kelebihan" : "Kekurangan") . " " . abs($sel);
                $jenis = $sel > 0 ? 'masuk' : 'keluar';
                $jml   = abs($sel);
                // Catat di log aktivitas (bisa extend ke tabel stok_log sendiri jika ada)
                log_aktivitas($conn, 'stock_opname', $jenis, "Obat id $oid: $ket");
            }
        }
        $conn->query("UPDATE stock_opname SET status='selesai' WHERE id=$opname_id");
        $conn->commit();

        log_aktivitas($conn, 'stock_opname', 'selesai', "Selesaikan opname id $opname_id");
        set_flash('success', 'Stock opname selesai. Stok obat telah diperbarui.');
        redirect("stock_opname.php?id=$opname_id");
    } catch (Exception $e) {
        $conn->rollback();
        set_flash('danger', 'Gagal menyelesaikan opname: ' . $e->getMessage());
        redirect("stock_opname.php?id=$opname_id");
    }
}

// ============================================================
// Ambil detail opname (jika buka ?id=)
// ============================================================
$opname_id      = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$current_opname = null;
$details        = [];

if ($opname_id) {
    $current_opname = $conn->query(
        "SELECT so.*, u.nama AS nama_user
         FROM stock_opname so
         JOIN users u ON so.user_id = u.id
         WHERE so.id = $opname_id"
    )->fetch_assoc();

    if ($current_opname) {
        $res = $conn->query(
            "SELECT dso.*, o.kode_obat, o.nama_obat, o.satuan
             FROM detail_stock_opname dso
             JOIN obat o ON dso.obat_id = o.id
             WHERE dso.opname_id = $opname_id
             ORDER BY o.nama_obat"
        );
        while ($r = $res->fetch_assoc()) {
            $details[] = $r;
        }
    }
}

// ============================================================
// Riwayat opname (halaman list)
// ============================================================
$riwayat = [];
$res_riw = $conn->query(
    "SELECT so.*, u.nama AS nama_user,
     (SELECT COUNT(*) FROM detail_stock_opname WHERE opname_id=so.id AND selisih!=0) AS jumlah_selisih
     FROM stock_opname so
     JOIN users u ON so.user_id = u.id
     ORDER BY so.created_at DESC
     LIMIT 30"
);
while ($r = $res_riw->fetch_assoc()) {
    $riwayat[] = $r;
}

// ============================================================
// Include header (wajib setelah semua variabel siap)
// ============================================================
include __DIR__ . '/includes/header.php';
?>

<?php if ($opname_id && $current_opname): ?>
<!-- ============================================================ -->
<!-- HALAMAN DETAIL OPNAME                                         -->
<!-- ============================================================ -->

<!-- Tombol atas -->
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <a href="stock_opname" class="btn btn-soft btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
    <button class="btn btn-soft btn-sm" onclick="window.print()">
        <i class="bi bi-printer me-1"></i>Cetak
    </button>
</div>

<!-- Info header opname -->
<div class="card-app mb-4">
    <div class="card-app__head d-flex justify-content-between align-items-center">
        <div>
            <div class="card-app__title"><i class="bi bi-clipboard2-pulse me-2"></i><?= e($current_opname['no_opname']) ?></div>
        </div>
        <span class="badge rounded-pill <?= $current_opname['status'] === 'selesai' ? 'bg-success' : 'bg-warning text-dark' ?>" style="font-size:13px;padding:6px 14px">
            <?= ucfirst($current_opname['status']) ?>
        </span>
    </div>
    <div class="card-app__body">
        <div class="row g-3">
            <div class="col-sm-6">
                <div class="cell-muted mb-1" style="font-size:12px">Tanggal Opname</div>
                <strong><?= tgl_indo($current_opname['tgl_opname']) ?></strong>
            </div>
            <div class="col-sm-6">
                <div class="cell-muted mb-1" style="font-size:12px">Dibuat Oleh</div>
                <strong><?= e($current_opname['nama_user']) ?></strong>
            </div>
            <?php if ($current_opname['keterangan']): ?>
            <div class="col-12">
                <div class="cell-muted mb-1" style="font-size:12px">Keterangan</div>
                <span><?= e($current_opname['keterangan']) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Form stok fisik -->
<div class="card-app">
    <div class="card-app__head">
        <div class="card-app__title"><i class="bi bi-table me-2"></i>Detail Stok</div>
    </div>
    <div class="card-app__body">

        <!-- Search (no-print) -->
        <?php if ($current_opname['status'] === 'draft'): ?>
        <div class="mb-3 no-print" style="max-width:380px;position:relative">
            <i class="bi bi-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--ink-soft)"></i>
            <input type="text" id="searchObat" class="form-control ps-4" placeholder="Cari nama / kode obat…">
        </div>
        <?php endif; ?>

        <form method="POST" id="formOpname">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="opname_id" value="<?= $opname_id ?>">
            <?= csrf_field() ?>

            <div class="table-responsive">
                <table class="table-app" id="tableOpname">
                    <thead>
                        <tr>
                            <th width="4%">No</th>
                            <th width="11%">Kode</th>
                            <th>Nama Obat</th>
                            <th width="11%" class="text-center">Stok Sistem</th>
                            <?php if ($current_opname['status'] === 'draft'): ?>
                            <th width="13%" class="text-center">Stok Fisik</th>
                            <?php else: ?>
                            <th width="11%" class="text-center">Stok Fisik</th>
                            <?php endif; ?>
                            <th width="10%" class="text-center">Selisih</th>
                            <th width="22%">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $no = 1; foreach ($details as $d):
                        $selisih = (int) $d['selisih'];
                        $row_bg  = '';
                        if ($selisih > 0) $row_bg = 'style="background:#f0fdf4"';
                        elseif ($selisih < 0) $row_bg = 'style="background:#fff1f2"';
                    ?>
                        <tr class="data-row" <?= $row_bg ?>
                            data-kode="<?= strtolower(e($d['kode_obat'])) ?>"
                            data-nama="<?= strtolower(e($d['nama_obat'])) ?>">
                            <td class="row-no"><?= $no++ ?></td>
                            <td><span class="badge" style="background:var(--teal-50);color:var(--teal-700);font-size:11px"><?= e($d['kode_obat']) ?></span></td>
                            <td>
                                <div style="font-weight:600"><?= e($d['nama_obat']) ?></div>
                                <div style="font-size:12px;color:var(--ink-soft)"><?= e($d['satuan']) ?></div>
                            </td>
                            <td class="text-center">
                                <strong><?= (int) $d['stok_sistem'] ?></strong>
                                <?php if ($current_opname['status'] === 'draft'): ?>
                                <input type="hidden" name="detail_id[]" value="<?= (int) $d['id'] ?>">
                                <?php endif; ?>
                            </td>
                            <?php if ($current_opname['status'] === 'draft'): ?>
                            <td class="text-center">
                                <input type="number"
                                       class="form-control form-control-sm text-center stok-fisik"
                                       name="stok_fisik[]"
                                       value="<?= (int) $d['stok_fisik'] ?>"
                                       min="0"
                                       data-sistem="<?= (int) $d['stok_sistem'] ?>"
                                       data-id="<?= (int) $d['id'] ?>"
                                       style="max-width:90px;margin:0 auto;font-weight:700">
                            </td>
                            <?php else: ?>
                            <td class="text-center"><strong><?= (int) $d['stok_fisik'] ?></strong></td>
                            <?php endif; ?>
                            <td class="text-center selisih-cell" data-id="<?= (int) $d['id'] ?>">
                                <?php if ($selisih > 0): ?>
                                    <span class="badge bg-success">+<?= $selisih ?></span>
                                <?php elseif ($selisih < 0): ?>
                                    <span class="badge bg-danger"><?= $selisih ?></span>
                                <?php else: ?>
                                    <span class="badge" style="background:var(--teal-50);color:var(--teal-700)">Sesuai</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($current_opname['status'] === 'draft'): ?>
                                <input type="text" class="form-control form-control-sm" name="keterangan[]"
                                       value="<?= e($d['keterangan'] ?? '') ?>"
                                       placeholder="Keterangan…">
                                <?php else: ?>
                                    <?= e($d['keterangan'] ?? '-') ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Info search -->
            <div id="searchInfo" class="mt-2 no-print" style="display:none;font-size:13px;color:var(--ink-soft)">
                Menampilkan <strong id="resultCount">0</strong> dari <?= count($details) ?> item
            </div>

            <!-- Tombol aksi -->
            <div class="d-flex justify-content-end gap-2 mt-4 no-print">
                <?php if ($current_opname['status'] === 'draft'): ?>
                    <!-- Semua user yg punya akses bisa simpan stok fisik -->
                    <button type="submit" name="save_fisik" class="btn btn-teal">
                        <i class="bi bi-floppy me-1"></i>Simpan Stok Fisik
                    </button>

                    <?php if ($is_admin): ?>
                    <!-- Hanya admin yang bisa selesaikan -->
                    <button type="button" class="btn btn-success" onclick="konfirmasiSelesai()">
                        <i class="bi bi-check2-circle me-1"></i>Selesaikan Opname
                    </button>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="text-success fw-semibold"><i class="bi bi-check-circle-fill me-1"></i>Opname sudah selesai</span>
                <?php endif; ?>
            </div>
        </form>

        <!-- Form selesaikan (hidden, submit via JS) -->
        <?php if ($is_admin && $current_opname['status'] === 'draft'): ?>
        <form method="POST" id="formSelesai" style="display:none">
            <input type="hidden" name="action" value="selesaikan">
            <input type="hidden" name="opname_id" value="<?= $opname_id ?>">
            <?= csrf_field() ?>
        </form>
        <?php endif; ?>

    </div>
</div>

<?php else: ?>
<!-- ============================================================ -->
<!-- HALAMAN LIST RIWAYAT                                          -->
<!-- ============================================================ -->

<div class="d-flex justify-content-between align-items-center mb-4">
    <div></div>
    <button class="btn btn-teal" data-bs-toggle="modal" data-bs-target="#modalBuat">
        <i class="bi bi-plus-lg me-1"></i>Buat Opname Baru
    </button>
</div>

<div class="card-app">
    <div class="card-app__head">
        <div class="card-app__title"><i class="bi bi-clock-history me-2"></i>Riwayat Stock Opname</div>
    </div>
    <div class="card-app__body">
        <?php if (empty($riwayat)): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-inbox" style="font-size:2.5rem"></i>
            <p class="mt-2">Belum ada riwayat stock opname</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table-app">
                <thead>
                    <tr>
                        <th>No. Opname</th>
                        <th>Tanggal</th>
                        <th>Dibuat Oleh</th>
                        <th class="text-center">Selisih</th>
                        <th class="text-center">Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($riwayat as $r): ?>
                <tr>
                    <td><strong><?= e($r['no_opname']) ?></strong></td>
                    <td><?= tgl_indo($r['tgl_opname']) ?></td>
                    <td><?= e($r['nama_user']) ?></td>
                    <td class="text-center">
                        <?php if ($r['jumlah_selisih'] > 0): ?>
                            <span class="badge" style="background:var(--amber-bg);color:var(--amber)"><?= $r['jumlah_selisih'] ?> item</span>
                        <?php else: ?>
                            <span class="badge bg-success">Sesuai</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge <?= $r['status'] === 'selesai' ? 'bg-success' : 'bg-warning text-dark' ?>">
                            <?= ucfirst($r['status']) ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <a href="stock_opname.php?id=<?= $r['id'] ?>" class="btn btn-soft btn-sm btn-icon" title="Detail">
                            <i class="bi bi-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Buat Opname -->
<div class="modal fade" id="modalBuat" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;border:none">
            <div class="modal-header" style="background:var(--teal-700);color:#fff;border-radius:16px 16px 0 0">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>Buat Stock Opname Baru</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="buat">
                <?= csrf_field() ?>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tanggal Opname <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="tgl_opname"
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Keterangan</label>
                        <textarea class="form-control" name="keterangan" rows="3"
                                  placeholder="Misal: Opname bulanan Juni 2026…"></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--line)">
                    <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-teal">
                        <i class="bi bi-check-lg me-1"></i>Buat Sekarang
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- ============================================================ -->
<!-- PRINT HEADER (muncul saat cetak saja)                         -->
<!-- ============================================================ -->
<?php if ($current_opname): ?>
<div class="print-only-header" style="display:none">
    <h2 style="margin:0;font-size:15px">STOCK OPNAME — <?= APP_NAME ?></h2>
    <div style="display:flex;justify-content:space-between;font-size:11px;margin-top:6px;border-top:1px solid #ccc;padding-top:4px">
        <span><b>No:</b> <?= e($current_opname['no_opname']) ?></span>
        <span><b>Tanggal:</b> <?= tgl_indo($current_opname['tgl_opname']) ?></span>
        <span><b>Oleh:</b> <?= e($current_opname['nama_user']) ?></span>
        <span><b>Status:</b> <?= ucfirst($current_opname['status']) ?></span>
    </div>
</div>
<?php endif; ?>

<style>
/* Print */
@media print {
    .no-print, .sidebar, .topbar, .btn-hamburger { display:none !important; }
    .main { margin-left:0 !important; padding:0 !important; }
    .card-app { box-shadow:none !important; border:1px solid #ddd !important; }
    body { background:#fff !important; }
    .print-only-header { display:block !important; margin-bottom:12px; }
    @page { margin-top:20px; margin-bottom:20px; }
}
/* Badge selisih di table */
.selisih-cell .badge { font-size:12px; min-width:52px }
</style>

<script>
// ===== SEARCH OBAT =====
const searchInput = document.getElementById('searchObat');
if (searchInput) {
    searchInput.addEventListener('keyup', function () {
        const q     = this.value.toLowerCase().trim();
        const rows  = document.querySelectorAll('.data-row');
        let visible = 0;

        rows.forEach(row => {
            const kode = row.dataset.kode || '';
            const nama = row.dataset.nama || '';
            const show = q === '' || kode.includes(q) || nama.includes(q);
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        const info = document.getElementById('searchInfo');
        if (q === '') {
            info.style.display = 'none';
            renumberRows();
        } else {
            document.getElementById('resultCount').textContent = visible;
            info.style.display = 'block';
        }
        renumberRows();
    });
}

function renumberRows() {
    let no = 1;
    document.querySelectorAll('.data-row:not([style*="display: none"])').forEach(r => {
        r.querySelector('.row-no').textContent = no++;
    });
}

// ===== UPDATE SELISIH REAL-TIME =====
document.querySelectorAll('.stok-fisik').forEach(input => {
    input.addEventListener('input', function () {
        const sistem   = parseInt(this.dataset.sistem) || 0;
        const fisik    = parseInt(this.value) || 0;
        const selisih  = fisik - sistem;
        const id       = this.dataset.id;
        const cell     = document.querySelector('.selisih-cell[data-id="' + id + '"]');
        const row      = this.closest('tr');

        // Update badge
        let html = '';
        if (selisih > 0) {
            html = '<span class="badge bg-success">+' + selisih + '</span>';
            row.style.background = '#f0fdf4';
        } else if (selisih < 0) {
            html = '<span class="badge bg-danger">' + selisih + '</span>';
            row.style.background = '#fff1f2';
        } else {
            html = '<span class="badge" style="background:var(--teal-50);color:var(--teal-700)">Sesuai</span>';
            row.style.background = '';
        }
        if (cell) cell.innerHTML = html;
    });
});

// ===== KONFIRMASI SELESAIKAN =====
function konfirmasiSelesai() {
    if (confirm(
        'PERHATIAN!\n\n' +
        'Setelah diselesaikan:\n' +
        '• Stok sistem akan diganti dengan stok fisik\n' +
        '• Data tidak dapat diubah lagi\n\n' +
        'Lanjutkan?'
    )) {
        document.getElementById('formSelesai').submit();
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>