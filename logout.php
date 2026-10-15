<?php
/**
 * Logout Pengguna & Hapus Session dengan Aman
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    write_audit_log($pdo, 'USER_LOGOUT', 'users', (string)$_SESSION['user_id'], 'Pengguna melakukan logout.');
    logout_user();
}

session_start();
set_flash('info', 'Anda telah berhasil keluar dari sistem.');
header('Location: ' . base_url('login.php'));
exit;
