<?php
require_once __DIR__ . '/config/config.php';

// Jika sudah login, langsung ke dashboard
if (current_user()) redirect('index.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        $stmt = $conn->prepare('SELECT id, nama, username, password, role FROM users WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ($row && password_verify($password, $row['password'])) {
            $_SESSION['user'] = [
                'id'   => (int) $row['id'],
                'nama' => $row['nama'],
                'username' => $row['username'],
                'role' => $row['role'],
            ];
            log_aktivitas($conn, 'auth', 'login', "Login sebagai {$row['nama']} ({$row['username']})", (int) $row['id']);
            redirect('index.php');
        } else {
            $error = 'Username atau password salah.';
            log_aktivitas($conn, 'auth', 'login_gagal', "Percobaan login gagal untuk username \"$username\"", null);
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Masuk &middot; <?= APP_NAME ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="auth-wrap">
  <!-- Sisi kiri: branding -->
  <div class="auth-side">
    <div class="d-flex align-items-center gap-3">
      <div class="auth-logo"><i class="bi bi-capsule-pill"></i></div>
      <div>
        <div style="font-family:'Plus Jakarta Sans';font-weight:800;font-size:22px;line-height:1"><?= APP_NAME ?></div>
        <div style="color:#a7e0d8;font-size:13px"><?= APP_TAGLINE ?></div>
      </div>
    </div>

    <div>
      <h2 style="font-family:'Plus Jakarta Sans';font-weight:800;font-size:32px;line-height:1.2;max-width:420px">
        Kelola apotek Anda dengan lebih rapi & terukur.
      </h2>
      <p style="color:#bfe6e0;max-width:380px;margin-top:14px">
        Stok, penjualan, dan laporan dalam satu dasbor yang ringan dan mudah dipahami.
      </p>
      <div class="mt-4">
        <div class="auth-feature"><i class="bi bi-box-seam"></i><div>Pantau stok & peringatan obat menipis secara otomatis</div></div>
        <div class="auth-feature"><i class="bi bi-lightning-charge"></i><div>Kasir cepat dengan perhitungan kembalian instan</div></div>
        <div class="auth-feature"><i class="bi bi-bar-chart-line"></i><div>Laporan penjualan harian siap dianalisis</div></div>
      </div>
    </div>

    <div style="color:#8fd3cb;font-size:12.5px">&copy; <?= date('Y') ?> <?= APP_NAME ?>. Dibuat dengan PHP & Bootstrap 5.</div>
  </div>

  <!-- Sisi kanan: form -->
  <div class="auth-form-col">
    <div class="auth-card">
      <div class="card-app">
        <div class="card-app__body p-4 p-md-5">
          <h1 class="display-font" style="font-weight:800;font-size:24px;margin-bottom:4px">Selamat datang 👋</h1>
          <p class="cell-muted mb-4">Masuk untuk melanjutkan ke dasbor.</p>

          <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-center" style="border-radius:11px;border:none">
              <i class="bi bi-exclamation-circle-fill me-2"></i><?= e($error) ?>
            </div>
          <?php endif; ?>

          <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <div class="mb-3">
              <label class="form-label">Username</label>
              <div class="position-relative">
                <i class="bi bi-person position-absolute" style="left:13px;top:11px;color:var(--muted)"></i>
                <input type="text" name="username" class="form-control" style="padding-left:38px"
                       placeholder="Masukkan username" value="<?= e($_POST['username'] ?? '') ?>" autofocus required>
              </div>
            </div>
            <div class="mb-4">
              <label class="form-label">Password</label>
              <div class="position-relative">
                <i class="bi bi-lock position-absolute" style="left:13px;top:11px;color:var(--muted)"></i>
                <input type="password" name="password" id="pw" class="form-control" style="padding-left:38px;padding-right:42px"
                       placeholder="Masukkan password" required>
                <button type="button" id="togglePw" class="btn position-absolute end-0 top-0" style="border:none;color:var(--muted)">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>
            <button class="btn btn-teal w-100 py-2"><i class="bi bi-box-arrow-in-right me-2"></i>Masuk</button>
          </form>

          <!--<div class="mt-4 p-3" style="background:var(--teal-50);border-radius:11px;font-size:13px;color:var(--teal-800)">-->
          <!--  <b><i class="bi bi-info-circle me-1"></i>Akun demo</b><br>-->
          <!--  admin / apoteker / kasir &mdash; password: <b>admin123</b>-->
          <!--</div>-->
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  const t = document.getElementById('togglePw'), pw = document.getElementById('pw');
  t.addEventListener('click', () => {
    const show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    t.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
  });
</script>
</body>
</html>
