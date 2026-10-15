<?php
/**
 * Halaman Login Pengguna (Mahasiswa, Panitia, dan Administrator)
 * Dilengkapi proteksi CSRF, Rate Limiting, dan password_verify()
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    if (is_admin_or_panitia()) {
        header('Location: ' . base_url('admin/dashboard.php'));
    } else {
        header('Location: ' . base_url('student/evaluation-form.php'));
    }
    exit;
}

$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $errorMsg = 'Token keamanan CSRF tidak valid. Silakan muat ulang halaman.';
    } elseif (!check_rate_limit('login_attempt', 10, 60)) {
        $errorMsg = 'Terlalu banyak percobaan login. Silakan tunggu 1 menit sebelum mencoba kembali.';
    } else {
        $identity = trim((string)($_POST['identity'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($identity === '' || $password === '') {
            $errorMsg = 'Username/Email dan Password wajib diisi.';
        } else {
            $stmt = $pdo->prepare(
                "SELECT * FROM users WHERE (username = :u OR email = :e) AND is_active = 1 LIMIT 1"
            );
            $stmt->execute([':u' => $identity, ':e' => $identity]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, (string)$user['password_hash'])) {
                login_user($user);
                $upd = $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id");
                $upd->execute([':id' => $user['id']]);

                write_audit_log($pdo, 'USER_LOGIN', 'users', (string)$user['id'], "Pengguna {$user['username']} ({$user['role']}) berhasil login.");
                set_flash('success', 'Selamat datang kembali, ' . $user['full_name'] . '!');

                if (in_array($user['role'], ['admin', 'panitia'], true)) {
                    header('Location: ' . base_url('admin/dashboard.php'));
                } else {
                    header('Location: ' . base_url('student/evaluation-form.php'));
                }
                exit;
            } else {
                $errorMsg = 'Username/Email atau Password yang Anda masukkan salah, atau akun tidak aktif.';
            }
        }
    }
}

$pageTitle = 'Login Akun';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-5">
            <div class="card-modern shadow border-0 overflow-hidden">
                <div class="p-4 text-white text-center" style="background: linear-gradient(135deg, #071733 0%, #0d254c 100%); border-bottom: 3px solid #38bdf8;">
                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-info bg-opacity-25 text-info mb-2" style="width: 54px; height: 54px;">
                        <i class="bi bi-shield-lock-fill fs-3"></i>
                    </div>
                    <h4 class="fw-bold mb-1">Login Sistem Evaluasi PPKMB</h4>
                    <p class="small text-white-50 mb-0">Masuk sebagai Admin, Panitia, atau Mahasiswa</p>
                </div>

                <div class="card-body p-4 p-md-4">
                    <?php if ($errorMsg !== ''): ?>
                        <div class="alert alert-danger small d-flex align-items-center gap-2">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <div><?= e($errorMsg) ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?= e(base_url('login.php')) ?>" data-loading="Memverifikasi kredensial akun...">
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label for="identity" class="form-label fw-semibold small">Username atau Email Kampus</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-person"></i></span>
                                <input type="text" class="form-control" id="identity" name="identity"
                                       value="<?= e($_POST['identity'] ?? '') ?>"
                                       placeholder="Masukkan username atau email" required autofocus>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="password" class="form-label fw-semibold small">Password</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-key"></i></span>
                                <input type="password" class="form-control" id="password" name="password"
                                       placeholder="Masukkan password" required>
                                <button type="button" class="input-group-text bg-light border-start-0 px-3"
                                        id="togglePassword"
                                        onclick="(function(){
                                            var inp = document.getElementById('password');
                                            var icon = document.getElementById('togglePasswordIcon');
                                            if (inp.type === 'password') {
                                                inp.type = 'text';
                                                icon.classList.replace('bi-eye', 'bi-eye-slash');
                                            } else {
                                                inp.type = 'password';
                                                icon.classList.replace('bi-eye-slash', 'bi-eye');
                                            }
                                        })()"
                                        title="Tampilkan / Sembunyikan Password"
                                        style="cursor:pointer;">
                                    <i class="bi bi-eye" id="togglePasswordIcon"></i>
                                </button>
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-navy py-2">
                                <i class="bi bi-box-arrow-in-right me-1"></i>Masuk ke Sistem
                            </button>
                            <a href="<?= e(base_url('student/evaluation-form.php')) ?>" class="btn btn-outline-secondary py-2 small">
                                <i class="bi bi-incognito me-1"></i>Isi Evaluasi Secara Anonim (Tanpa Login)
                            </a>
                        </div>
                    </form>

                    <!-- Informasi Akun Pengujian (Hanya untuk kemudahan demonstrasi) -->
                    <div class="mt-4 pt-3 border-top">
                        <div class="small fw-bold text-secondary mb-2">
                            <i class="bi bi-info-circle me-1 text-primary"></i>Akun Pengujian (Seed Demo):
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered small mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Role</th>
                                        <th>Username</th>
                                        <th>Password</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><span class="badge bg-primary">Admin</span></td>
                                        <td><code>admin</code></td>
                                        <td><code>AdminPPKMB2026!</code></td>
                                    </tr>
                                    <tr>
                                        <td><span class="badge bg-info text-dark">Panitia</span></td>
                                        <td><code>panitia</code></td>
                                        <td><code>PanitiaPPKMB2026!</code></td>
                                    </tr>
                                    <tr>
                                        <td><span class="badge bg-secondary">Mahasiswa</span></td>
                                        <td><code>maba01</code></td>
                                        <td><code>Mahasiswa2026!</code></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
