<?php
/**
 * Header & Navbar Utama Sistem Evaluasi PPKMB Berbasis Analisis Sentimen
 */
require_once __DIR__ . '/auth.php';
$currUser = current_user();
$pageTitle = $pageTitle ?? 'Sistem Evaluasi Kegiatan Mahasiswa terhadap PPKMB';
$flashes = get_flashes();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - PPKMB Sentiment Analytics</title>
    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap 5.3 CSS & Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Custom Theme CSS -->
    <link href="<?= e(base_url('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body>

<!-- Global Loading Overlay -->
<div id="globalLoadingOverlay" role="status" aria-live="polite">
    <div class="spinner-border text-info mb-3" style="width: 3.2rem; height: 3.2rem;" role="status"></div>
    <h6 class="fw-bold mb-1" id="globalLoadingText">Memproses Data &amp; Analisis Sentimen...</h6>
    <p class="small text-white-50 mb-0">Mohon tunggu sejenak, jangan menutup halaman ini.</p>
</div>

<!-- Navbar Utama -->
<nav class="navbar navbar-expand-lg navbar-dark navbar-ppkmb sticky-top py-2">
    <div class="container-fluid px-3 px-lg-4">
        <div class="d-flex align-items-center gap-2">
            <?php if (!empty($showSidebar)): ?>
                <button class="btn btn-sm btn-outline-light d-lg-none me-1" type="button" id="sidebarToggleBtn" aria-label="Toggle Menu">
                    <i class="bi bi-list fs-5"></i>
                </button>
            <?php endif; ?>
            <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e(base_url('index.php')) ?>">
                <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-info bg-opacity-25 text-info p-2">
                    <i class="bi bi-mortarboard-fill fs-5"></i>
                </span>
                <div>
                    <div class="lh-1 fs-6 fw-bold">EVALUASI PPKMB</div>
                    <small class="text-white-50 fw-normal" style="font-size: 0.72rem;">Sistem Analisis Sentimen Mahasiswa</small>
                </div>
            </a>
            <span class="brand-badge d-none d-md-inline-block ms-2">NLP Bahasa Indonesia</span>
        </div>

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#topNavbarMenu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="topNavbarMenu">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2 mt-2 mt-lg-0">
                <li class="nav-item">
                    <a class="nav-link text-white-50" href="<?= e(base_url('index.php')) ?>">
                        <i class="bi bi-house-door me-1"></i>Beranda
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-white-50" href="<?= e(base_url('student/evaluation-form.php')) ?>">
                        <i class="bi bi-pencil-square me-1"></i>Isi Evaluasi
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-white-50" href="<?= e(base_url('student/evaluation-history.php')) ?>">
                        <i class="bi bi-bar-chart-steps me-1"></i>Ringkasan Publik
                    </a>
                </li>

                <?php if ($currUser): ?>
                    <?php if (in_array($currUser['role'], ['admin', 'panitia'], true)): ?>
                        <li class="nav-item">
                            <a class="btn btn-sm btn-sky px-3" href="<?= e(base_url('admin/dashboard.php')) ?>">
                                <i class="bi bi-speedometer2 me-1"></i>Dashboard Admin
                            </a>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item dropdown ms-lg-2">
                        <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 text-white bg-white bg-opacity-10 rounded-pill px-3 py-1" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle text-info"></i>
                            <span class="small fw-semibold"><?= e($currUser['full_name']) ?></span>
                            <span class="badge bg-info text-dark text-uppercase" style="font-size: 0.65rem;"><?= e($currUser['role']) ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
                            <li class="px-3 py-2 border-bottom">
                                <div class="small fw-bold text-dark"><?= e($currUser['full_name']) ?></div>
                                <div class="text-muted" style="font-size: 0.75rem;">@<?= e($currUser['username']) ?> &bull; <?= e(ucfirst($currUser['role'])) ?></div>
                            </li>
                            <?php if (in_array($currUser['role'], ['admin', 'panitia'], true)): ?>
                                <li><a class="dropdown-item small py-2" href="<?= e(base_url('admin/dashboard.php')) ?>"><i class="bi bi-grid-1x2 me-2 text-primary"></i>Dashboard Statistik</a></li>
                                <li><a class="dropdown-item small py-2" href="<?= e(base_url('admin/evaluations.php')) ?>"><i class="bi bi-chat-left-text me-2 text-primary"></i>Data Evaluasi</a></li>
                            <?php else: ?>
                                <li><a class="dropdown-item small py-2" href="<?= e(base_url('student/evaluation-form.php')) ?>"><i class="bi bi-journal-check me-2 text-primary"></i>Formulir Evaluasi</a></li>
                                <li><a class="dropdown-item small py-2" href="<?= e(base_url('student/evaluation-history.php')) ?>"><i class="bi bi-clock-history me-2 text-primary"></i>Riwayat &amp; Ringkasan</a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item small py-2 text-danger fw-semibold" href="<?= e(base_url('logout.php')) ?>">
                                    <i class="bi bi-box-arrow-right me-2"></i>Keluar (Logout)
                                </a>
                            </li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li class="nav-item ms-lg-2">
                        <a class="btn btn-sm btn-outline-info rounded-pill px-3" href="<?= e(base_url('login.php')) ?>">
                            <i class="bi bi-box-arrow-in-right me-1"></i>Login Akun
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<?php if (!empty($flashes)): ?>
    <div class="container-fluid px-3 px-lg-4 mt-3 no-print">
        <?php foreach ($flashes as $f): ?>
            <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show shadow-sm border-0 d-flex align-items-center gap-2 mb-2" role="alert">
                <?php if ($f['type'] === 'success'): ?>
                    <i class="bi bi-check-circle-fill fs-5 text-success"></i>
                <?php elseif ($f['type'] === 'danger'): ?>
                    <i class="bi bi-exclamation-octagon-fill fs-5 text-danger"></i>
                <?php else: ?>
                    <i class="bi bi-info-circle-fill fs-5 text-primary"></i>
                <?php endif; ?>
                <div><?= e($f['message']) ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
