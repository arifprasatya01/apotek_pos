<?php
/**
 * Header layout — dipanggil di awal setiap halaman terproteksi.
 * Variabel opsional sebelum include:
 *   $page_title   judul di topbar
 *   $page_crumb   teks breadcrumb kecil
 */

// Cegah akses langsung ke file layout ini
if (isset($_SERVER['SCRIPT_FILENAME']) &&
    realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(403);
    exit('403 Forbidden');
}

require_once __DIR__ . '/../config/config.php';
require_login();

$user  = current_user();
$title = $page_title ?? 'Dashboard';
$crumb = $page_crumb ?? null;
$flash = get_flash();

// Hitung obat menipis untuk badge sidebar
$stok_menipis = (int) ($conn->query(
    "SELECT COUNT(*) c FROM obat WHERE stok <= stok_minimal"
)->fetch_assoc()['c'] ?? 0);
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> &middot; <?= APP_NAME ?></title>

  <!-- Bootstrap 5.3 + Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <!-- Tipografi -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
  <!-- Tema kustom -->
  <link href="assets/css/style.css" rel="stylesheet">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><text y='14' font-size='14'>%F0%9F%92%8A</text></svg>">
</head>
<body>
<div class="layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <div class="main">
    <header class="topbar">
      <button class="btn-hamburger" id="btnHamburger" aria-label="Buka menu"><i class="bi bi-list"></i></button>
      <div>
        <h1 class="topbar__title"><?= e($title) ?></h1>
        <?php if ($crumb): ?><div class="topbar__crumb"><?= e($crumb) ?></div><?php endif; ?>
      </div>
      <div class="ms-auto d-flex align-items-center gap-3">
        <span class="d-none d-md-inline cell-muted">
          <i class="bi bi-calendar3 me-1"></i><?= tgl_indo(date('Y-m-d')) ?>
        </span>
        <div class="dropdown">
          <button class="btn btn-soft btn-sm dropdown-toggle" data-bs-toggle="dropdown">
            <i class="bi bi-person-circle me-1"></i><?= e($user['nama']) ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow">
            <li><span class="dropdown-item-text small text-muted">Masuk sebagai <b><?= e(ucfirst($user['role'])) ?></b></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout"><i class="bi bi-box-arrow-right me-2"></i>Keluar</a></li>
          </ul>
        </div>
      </div>
    </header>

    <main class="content">
      <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'danger' ? 'danger' : 'info') ?> d-flex align-items-center js-auto-dismiss"
             style="border-radius:12px;border:none">
          <i class="bi <?= $flash['type']==='success'?'bi-check-circle-fill':($flash['type']==='danger'?'bi-exclamation-triangle-fill':'bi-info-circle-fill') ?> me-2"></i>
          <?= e($flash['message']) ?>
        </div>
      <?php endif; ?>
