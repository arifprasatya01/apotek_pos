<?php
require_once __DIR__ . '/config/config.php';
$u = current_user();
if ($u) log_aktivitas($conn, 'auth', 'logout', "Logout dari akun {$u['nama']} ({$u['username']})", (int) $u['id']);
$_SESSION = [];
session_destroy();
redirect('login.php');
