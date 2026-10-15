<?php
/**
 * Middleware Autentikasi & Otorisasi Berbasis Role (RBAC)
 * Role yang didukung: 'admin', 'panitia', 'mahasiswa'
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['user_role']);
}

function current_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id' => (int)$_SESSION['user_id'],
        'username' => (string)($_SESSION['username'] ?? ''),
        'full_name' => (string)($_SESSION['full_name'] ?? ''),
        'role' => (string)($_SESSION['user_role'] ?? ''),
        'faculty' => (string)($_SESSION['user_faculty'] ?? ''),
        'study_program' => (string)($_SESSION['user_prodi'] ?? ''),
    ];
}

function is_admin_or_panitia(): bool {
    return is_logged_in() && in_array($_SESSION['user_role'], ['admin', 'panitia'], true);
}

function is_super_admin(): bool {
    return is_logged_in() && $_SESSION['user_role'] === 'admin';
}

function require_login(): void {
    if (!is_logged_in()) {
        set_flash('warning', 'Silakan login terlebih dahulu untuk mengakses halaman tersebut.');
        header('Location: ' . base_url('login.php'));
        exit;
    }
}

function require_role(array $allowedRoles): void {
    require_login();
    $role = $_SESSION['user_role'] ?? '';
    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(403);
        set_flash('danger', 'Akses ditolak: Anda tidak memiliki izin untuk membuka halaman tersebut.');
        if ($role === 'mahasiswa') {
            header('Location: ' . base_url('student/evaluation-form.php'));
        } else {
            header('Location: ' . base_url('admin/dashboard.php'));
        }
        exit;
    }
}

function login_user(array $userRow): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$userRow['id'];
    $_SESSION['username'] = (string)$userRow['username'];
    $_SESSION['full_name'] = (string)$userRow['full_name'];
    $_SESSION['user_role'] = (string)$userRow['role'];
    $_SESSION['user_faculty'] = (string)($userRow['faculty'] ?? '');
    $_SESSION['user_prodi'] = (string)($userRow['study_program'] ?? '');
    $_SESSION['logged_in_at'] = time();
}

function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'] ?? '',
            (bool)$params['secure'],
            (bool)$params['httponly']
        );
    }
    session_destroy();
}
