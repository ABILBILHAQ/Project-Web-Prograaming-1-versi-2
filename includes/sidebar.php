<?php
/**
 * Sidebar Navigasi Dashboard Admin & Panitia
 */
$activeMenu = $activeMenu ?? 'dashboard';
$currRole = $_SESSION['user_role'] ?? 'panitia';
?>
<aside class="sidebar-ppkmb py-3" id="appSidebar">
    <div class="px-3 mb-2">
        <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1);">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-shield-check text-info fs-4"></i>
                <div>
                    <div class="small fw-bold text-white lh-sm">Panel Evaluasi PPKMB</div>
                    <div class="text-white-50" style="font-size: 0.72rem;">Akses: <?= e(strtoupper($currRole)) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="nav-section-title">Analitik Utama</div>
    <nav class="nav flex-column">
        <a class="nav-link <?= $activeMenu === 'dashboard' ? 'active' : '' ?>" href="<?= e(base_url('admin/dashboard.php')) ?>">
            <i class="bi bi-speedometer2"></i>
            <span>Dashboard Statistik</span>
        </a>
        <a class="nav-link <?= $activeMenu === 'evaluations' ? 'active' : '' ?>" href="<?= e(base_url('admin/evaluations.php')) ?>">
            <i class="bi bi-chat-square-quote"></i>
            <span>Data Evaluasi Mahasiswa</span>
        </a>
        <a class="nav-link <?= $activeMenu === 'evaluations_neg' ? 'active' : '' ?>" href="<?= e(base_url('admin/evaluations.php?sentiment=negatif')) ?>">
            <i class="bi bi-exclamation-triangle"></i>
            <span>Prioritas Masukan Negatif</span>
        </a>
        <a class="nav-link <?= $activeMenu === 'reports' ? 'active' : '' ?>" href="<?= e(base_url('admin/reports.php')) ?>">
            <i class="bi bi-file-earmark-bar-graph"></i>
            <span>Laporan &amp; Rekomendasi</span>
        </a>
    </nav>

    <div class="nav-section-title">Machine Learning NLP</div>
    <nav class="nav flex-column">
        <a class="nav-link <?= $activeMenu === 'sentiment' ? 'active' : '' ?>" href="<?= e(base_url('admin/sentiment.php')) ?>">
            <i class="bi bi-cpu"></i>
            <span>Performa &amp; Uji Model ML</span>
        </a>
        <a class="nav-link <?= $activeMenu === 'datasets' ? 'active' : '' ?>" href="<?= e(base_url('admin/datasets.php')) ?>">
            <i class="bi bi-database-check"></i>
            <span>Dataset Pelatihan</span>
        </a>
    </nav>

    <div class="nav-section-title">Manajemen Master</div>
    <nav class="nav flex-column">
        <a class="nav-link <?= $activeMenu === 'periods' ? 'active' : '' ?>" href="<?= e(base_url('admin/periods.php')) ?>">
            <i class="bi bi-calendar2-range"></i>
            <span>Periode &amp; Pengaturan</span>
        </a>
        <a class="nav-link <?= $activeMenu === 'activities' ? 'active' : '' ?>" href="<?= e(base_url('admin/activities.php')) ?>">
            <i class="bi bi-collection"></i>
            <span>Kegiatan &amp; Sesi PPKMB</span>
        </a>
    </nav>

    <div class="px-3 mt-4">
        <div class="p-3 rounded-3 small" style="background: rgba(14, 165, 233, 0.12); border: 1px solid rgba(56, 189, 248, 0.25);">
            <div class="fw-bold text-info mb-1"><i class="bi bi-lock-fill me-1"></i>Privasi Terlindungi</div>
            <p class="mb-0 text-white-50" style="font-size: 0.73rem;">
                Identitas mahasiswa dipisahkan secara kriptografis dari komentar evaluasi dan disamarkan otomatis (PII Masking).
            </p>
        </div>
    </div>
</aside>
