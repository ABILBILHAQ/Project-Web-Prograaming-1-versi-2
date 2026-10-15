<?php
/**
 * Halaman Manajemen Periode PPKMB, Pengaturan Privasi, & Manajemen Pengguna (admin/periods.php)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role(['admin', 'panitia']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $act = (string)($_POST['action'] ?? '');

    if ($act === 'create_period') {
        $year   = trim((string)($_POST['academic_year'] ?? ''));
        $name   = trim((string)($_POST['period_name'] ?? ''));
        $theme  = trim((string)($_POST['theme'] ?? ''));
        $sDate  = trim((string)($_POST['start_date'] ?? ''));
        $eDate  = trim((string)($_POST['end_date'] ?? ''));
        $target = max(1, (int)($_POST['target_participants'] ?? 1000));
        $active = isset($_POST['is_active']) ? 1 : 0;
        $anon   = isset($_POST['allow_anonymous']) ? 1 : 0;
        $summ   = isset($_POST['allow_student_summary']) ? 1 : 0;
        $desc   = trim((string)($_POST['description'] ?? ''));

        if ($year !== '' && $name !== '' && $sDate !== '' && $eDate !== '') {
            if ($active === 1) {
                $pdo->exec("UPDATE periods SET is_active = 0");
            }
            $ins = $pdo->prepare(
                "INSERT INTO periods (academic_year, period_name, theme, start_date, end_date, target_participants, is_active, allow_anonymous, allow_student_summary, description)
                 VALUES (:yr, :nm, :th, :sd, :ed, :tg, :ac, :an, :sm, :ds)"
            );
            $ins->execute([
                ':yr' => $year, ':nm' => $name, ':th' => $theme, ':sd' => $sDate, ':ed' => $eDate,
                ':tg' => $target, ':ac' => $active, ':an' => $anon, ':sm' => $summ, ':ds' => $desc,
            ]);
            write_audit_log($pdo, 'CREATE_PERIOD', 'periods', (string)$pdo->lastInsertId(), "Membuat periode PPKMB baru: {$year} - {$name}");
            set_flash('success', "Periode {$name} ({$year}) berhasil ditambahkan.");
        } else {
            set_flash('danger', 'Mohon lengkapi seluruh kolom wajib periode PPKMB.');
        }
        header('Location: ' . base_url('admin/periods.php'));
        exit;
    }

    if ($act === 'toggle_period') {
        $pid = (int)($_POST['period_id'] ?? 0);
        $field = (string)($_POST['field'] ?? '');
        if (in_array($field, ['is_active', 'allow_anonymous', 'allow_student_summary'], true)) {
            if ($field === 'is_active') {
                $pdo->exec("UPDATE periods SET is_active = 0");
                $upd = $pdo->prepare("UPDATE periods SET is_active = 1 WHERE id = :id");
                $upd->execute([':id' => $pid]);
            } else {
                $upd = $pdo->prepare("UPDATE periods SET {$field} = 1 - {$field} WHERE id = :id");
                $upd->execute([':id' => $pid]);
            }
            write_audit_log($pdo, 'UPDATE_PERIOD', 'periods', (string)$pid, "Mengubah pengaturan {$field} pada periode #{$pid}.");
            set_flash('success', 'Pengaturan periode berhasil diperbarui.');
        }
        header('Location: ' . base_url('admin/periods.php'));
        exit;
    }

    // Manajemen Pengguna (Hanya Super Admin)
    if ($act === 'create_user' && is_super_admin()) {
        $uname = trim((string)($_POST['username'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $fname = trim((string)($_POST['full_name'] ?? ''));
        $role  = trim((string)($_POST['role'] ?? 'panitia'));
        $pass  = (string)($_POST['password'] ?? '');
        $fac   = trim((string)($_POST['faculty'] ?? ''));
        $prodi = trim((string)($_POST['study_program'] ?? ''));

        if ($uname !== '' && $email !== '' && $fname !== '' && strlen($pass) >= 8 && in_array($role, ['admin', 'panitia', 'mahasiswa'], true)) {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            try {
                $insU = $pdo->prepare(
                    "INSERT INTO users (username, email, password_hash, full_name, role, faculty, study_program, is_active)
                     VALUES (:u, :e, :p, :fn, :r, :f, :pr, 1)"
                );
                $insU->execute([
                    ':u' => $uname, ':e' => $email, ':p' => $hash, ':fn' => $fname,
                    ':r' => $role, ':f' => $fac, ':pr' => $prodi,
                ]);
                write_audit_log($pdo, 'CREATE_USER', 'users', (string)$pdo->lastInsertId(), "Menambahkan pengguna {$uname} dengan role {$role}.");
                set_flash('success', "Akun pengguna {$uname} ({$role}) berhasil dibuat.");
            } catch (Throwable $e) {
                set_flash('danger', 'Gagal membuat akun: Username atau Email sudah digunakan.');
            }
        } else {
            set_flash('danger', 'Data pengguna tidak lengkap atau password kurang dari 8 karakter.');
        }
        header('Location: ' . base_url('admin/periods.php#usersSection'));
        exit;
    }

    if ($act === 'toggle_user_active' && is_super_admin()) {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid !== (int)($_SESSION['user_id'] ?? 0)) {
            $upd = $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id = :id");
            $upd->execute([':id' => $uid]);
            write_audit_log($pdo, 'TOGGLE_USER', 'users', (string)$uid, "Mengubah status aktif akun user #{$uid}.");
            set_flash('info', "Status aktif pengguna #{$uid} diperbarui.");
        }
        header('Location: ' . base_url('admin/periods.php#usersSection'));
        exit;
    }
}

$periods = $pdo->query(
    "SELECT p.*,
            (SELECT COUNT(*) FROM activities a WHERE a.period_id = p.id) AS total_activities,
            (SELECT COUNT(*) FROM evaluations e WHERE e.period_id = p.id) AS total_evaluations
     FROM periods p
     ORDER BY p.id DESC"
)->fetchAll();

$usersList = $pdo->query("SELECT id, username, email, full_name, role, identifier_masked, faculty, study_program, is_active, last_login_at FROM users ORDER BY id ASC")->fetchAll();
$auditLogs = $pdo->query("SELECT * FROM audit_logs ORDER BY id DESC LIMIT 12")->fetchAll();

$pageTitle = 'Manajemen Periode & Pengguna';
$showSidebar = true;
$activeMenu = 'periods';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="app-wrapper">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1" style="color: #0d254c;">Manajemen Periode PPKMB, Privasi &amp; Hak Akses Pengguna</h4>
                <p class="text-secondary small mb-0">
                    Kelola tahun akademik pelaksanaan PPKMB, opsi evaluasi anonim, visibilitas ringkasan publik, dan akun pengguna.
                </p>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <!-- Form Tambah Periode -->
            <div class="col-lg-5">
                <div class="card-modern p-4 h-100">
                    <h6 class="fw-bold mb-3" style="color: #0d254c;"><i class="bi bi-calendar-plus me-2 text-primary"></i>Tambah Periode PPKMB Baru</h6>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_period">
                        <div class="row g-2 mb-2">
                            <div class="col-md-5">
                                <label class="form-label small fw-semibold">Tahun Akademik</label>
                                <input type="text" name="academic_year" class="form-control form-control-sm" placeholder="2027/2028" required>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label small fw-semibold">Nama Periode</label>
                                <input type="text" name="period_name" class="form-control form-control-sm" placeholder="PPKMB Universitas 2027" required>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">Tema Kegiatan</label>
                            <input type="text" name="theme" class="form-control form-control-sm" placeholder="Tema PPKMB tahun ini...">
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Tanggal Mulai</label>
                                <input type="date" name="start_date" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Tanggal Selesai</label>
                                <input type="date" name="end_date" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Target Maba</label>
                                <input type="number" name="target_participants" class="form-control form-control-sm" value="1500" min="1" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Deskripsi Singkat</label>
                            <textarea name="description" class="form-control form-control-sm" rows="2"></textarea>
                        </div>
                        <div class="d-flex flex-wrap gap-3 mb-3 small">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="chkActive" checked>
                                <label class="form-check-label" for="chkActive">Set sebagai Periode Aktif</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="allow_anonymous" id="chkAnon" checked>
                                <label class="form-check-label" for="chkAnon">Izinkan Mode Anonim</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="allow_student_summary" id="chkSumm" checked>
                                <label class="form-check-label" for="chkSumm">Buka Ringkasan Publik Mahasiswa</label>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-sm btn-navy w-100">Simpan Periode Baru</button>
                    </form>
                </div>
            </div>

            <!-- Daftar Periode -->
            <div class="col-lg-7">
                <div class="card-modern h-100 overflow-hidden">
                    <div class="card-header">Daftar Periode Pelaksanaan PPKMB</div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle small mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Periode</th>
                                    <th>Rentang Tanggal</th>
                                    <th class="text-center">Sesi / Evaluasi</th>
                                    <th class="text-center">Status Aktif</th>
                                    <th class="text-center">Ringkasan Publik</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($periods as $p): ?>
                                    <tr>
                                        <td class="ps-3">
                                            <div class="fw-bold text-dark"><?= e($p['academic_year']) ?></div>
                                            <div class="text-muted"><?= e($p['period_name']) ?></div>
                                        </td>
                                        <td><?= e($p['start_date'] . ' s/d ' . $p['end_date']) ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-light text-dark border"><?= (int)$p['total_activities'] ?> Sesi</span>
                                            <span class="badge bg-primary-subtle text-primary"><?= (int)$p['total_evaluations'] ?> Evaluasi</span>
                                        </td>
                                        <td class="text-center">
                                            <form method="post" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_period">
                                                <input type="hidden" name="period_id" value="<?= (int)$p['id'] ?>">
                                                <input type="hidden" name="field" value="is_active">
                                                <button type="submit" class="btn btn-sm <?= (int)$p['is_active'] === 1 ? 'btn-success' : 'btn-outline-secondary' ?>">
                                                    <?= (int)$p['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td class="text-center">
                                            <form method="post" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_period">
                                                <input type="hidden" name="period_id" value="<?= (int)$p['id'] ?>">
                                                <input type="hidden" name="field" value="allow_student_summary">
                                                <button type="submit" class="btn btn-sm <?= (int)$p['allow_student_summary'] === 1 ? 'btn-info text-white' : 'btn-outline-secondary' ?>">
                                                    <?= (int)$p['allow_student_summary'] === 1 ? 'Diizinkan' : 'Ditutup' ?>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bagian Pengelolaan Data Pengguna Sesuai Hak Akses -->
        <div class="row g-4" id="usersSection">
            <div class="col-lg-7">
                <div class="card-modern overflow-hidden">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-people-fill me-2 text-primary"></i>Data Pengguna Sistem (Role-Based Access Control)</span>
                        <?php if (is_super_admin()): ?>
                            <button class="btn btn-sm btn-navy" data-bs-toggle="collapse" data-bs-target="#addUserCollapse">
                                <i class="bi bi-person-plus me-1"></i>Tambah Pengguna
                            </button>
                        <?php endif; ?>
                    </div>

                    <?php if (is_super_admin()): ?>
                        <div class="collapse border-bottom p-3 bg-light" id="addUserCollapse">
                            <form method="post" class="row g-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="create_user">
                                <div class="col-md-4">
                                    <input type="text" name="username" class="form-control form-control-sm" placeholder="Username" required>
                                </div>
                                <div class="col-md-4">
                                    <input type="email" name="email" class="form-control form-control-sm" placeholder="Email Kampus" required>
                                </div>
                                <div class="col-md-4">
                                    <input type="password" name="password" class="form-control form-control-sm" placeholder="Password (min 8 kar)" required>
                                </div>
                                <div class="col-md-4">
                                    <input type="text" name="full_name" class="form-control form-control-sm" placeholder="Nama Lengkap" required>
                                </div>
                                <div class="col-md-3">
                                    <select name="role" class="form-select form-select-sm">
                                        <option value="panitia">Panitia</option>
                                        <option value="mahasiswa">Mahasiswa</option>
                                        <option value="admin">Admin</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <input type="text" name="faculty" class="form-control form-control-sm" placeholder="Fakultas">
                                </div>
                                <div class="col-md-2 d-grid">
                                    <button type="submit" class="btn btn-sm btn-sky">Simpan</button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle small mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Pengguna</th>
                                    <th>Role</th>
                                    <th>Unit / Fakultas</th>
                                    <th>Login Terakhir</th>
                                    <th class="text-end pe-3">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($usersList as $u): ?>
                                    <tr>
                                        <td class="ps-3">
                                            <div class="fw-bold text-dark"><?= e($u['full_name']) ?></div>
                                            <div class="text-muted">@<?= e($u['username']) ?> &bull; <?= e($u['identifier_masked'] ?? '-') ?></div>
                                        </td>
                                        <td><span class="badge bg-primary-subtle text-primary text-uppercase"><?= e($u['role']) ?></span></td>
                                        <td><?= e($u['faculty'] ?: '-') ?></td>
                                        <td class="text-muted"><?= e($u['last_login_at'] ?: 'Belum pernah') ?></td>
                                        <td class="text-end pe-3">
                                            <?php if (is_super_admin() && (int)$u['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
                                                <form method="post" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="toggle_user_active">
                                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                    <button type="submit" class="btn btn-sm <?= (int)$u['is_active'] === 1 ? 'btn-outline-success' : 'btn-outline-danger' ?>">
                                                        <?= (int)$u['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="badge bg-success">Aktif</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Audit Log Aktivitas Admin -->
            <div class="col-lg-5">
                <div class="card-modern overflow-hidden h-100">
                    <div class="card-header"><i class="bi bi-journal-code me-2 text-primary"></i>Audit Log Aktivitas Administrator</div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle small mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Waktu</th>
                                    <th>Aksi</th>
                                    <th>Keterangan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($auditLogs as $al): ?>
                                    <tr>
                                        <td class="ps-3 text-nowrap text-muted" style="font-size:0.72rem;"><?= e($al['created_at']) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= e($al['action_type']) ?></span></td>
                                        <td style="font-size:0.76rem;"><?= e($al['description']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
