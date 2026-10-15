<?php
/**
 * Dashboard Statistik & Visualisasi Evaluasi PPKMB (admin/dashboard.php)
 * Menampilkan 6 Kartu Statistik Utama & 5 Grafik Interaktif Chart.js yang
 * seluruhnya bersumber dari data aktual di database MySQL/MariaDB.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role(['admin', 'panitia']);

// Ambil opsi filter dari database
$periods = $pdo->query("SELECT id, academic_year, period_name, is_active FROM periods ORDER BY id DESC")->fetchAll();
$categories = $pdo->query("SELECT DISTINCT category FROM activities ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);
$faculties = $pdo->query("SELECT DISTINCT faculty FROM evaluations ORDER BY faculty ASC")->fetchAll(PDO::FETCH_COLUMN);
$prodis = $pdo->query("SELECT DISTINCT study_program FROM evaluations ORDER BY study_program ASC")->fetchAll(PDO::FETCH_COLUMN);

// Parameter Filter GET
$filterPeriod   = (int)($_GET['period_id'] ?? 0);
$filterCategory = trim((string)($_GET['category'] ?? ''));
$filterFaculty  = trim((string)($_GET['faculty'] ?? ''));
$filterProdi    = trim((string)($_GET['study_program'] ?? ''));
$dummyMode      = trim((string)($_GET['dummy_mode'] ?? 'all')); // all | real_only | dummy_only

$whereClauses = ["1=1"];
$params = [];

if ($filterPeriod > 0) {
    $whereClauses[] = "e.period_id = :pid";
    $params[':pid'] = $filterPeriod;
}
if ($filterCategory !== '') {
    $whereClauses[] = "a.category = :cat";
    $params[':cat'] = $filterCategory;
}
if ($filterFaculty !== '') {
    $whereClauses[] = "e.faculty = :fac";
    $params[':fac'] = $filterFaculty;
}
if ($filterProdi !== '') {
    $whereClauses[] = "e.study_program = :prodi";
    $params[':prodi'] = $filterProdi;
}
if ($dummyMode === 'real_only') {
    $whereClauses[] = "e.is_dummy = 0";
} elseif ($dummyMode === 'dummy_only') {
    $whereClauses[] = "e.is_dummy = 1";
}

$whereSql = implode(' AND ', $whereClauses);

// 1. Query Kartu Statistik Utama (6 Kartu) & Rata-rata 7 Aspek
$statSql = "
    SELECT
        COUNT(e.id) AS total_evaluasi,
        SUM(CASE WHEN s.id IS NOT NULL AND s.analysis_status NOT IN ('empty','pending') THEN 1 ELSE 0 END) AS total_analyzed,
        SUM(CASE WHEN s.sentiment_label = 'positif' THEN 1 ELSE 0 END) AS count_positif,
        SUM(CASE WHEN s.sentiment_label = 'negatif' THEN 1 ELSE 0 END) AS count_negatif,
        SUM(CASE WHEN s.sentiment_label = 'netral'  THEN 1 ELSE 0 END) AS count_netral,
        ROUND(AVG(e.rating_keseluruhan), 2) AS avg_keseluruhan,
        ROUND(AVG(e.average_rating), 2) AS avg_all_aspects,
        ROUND(AVG(e.rating_materi), 2) AS avg_materi,
        ROUND(AVG(e.rating_pemateri), 2) AS avg_pemateri,
        ROUND(AVG(e.rating_panitia), 2) AS avg_panitia,
        ROUND(AVG(e.rating_fasilitas), 2) AS avg_fasilitas,
        ROUND(AVG(e.rating_konsumsi), 2) AS avg_konsumsi,
        ROUND(AVG(e.rating_jadwal), 2) AS avg_jadwal,
        SUM(CASE WHEN e.is_dummy = 1 THEN 1 ELSE 0 END) AS dummy_count
    FROM evaluations e
    JOIN activities a ON a.id = e.activity_id
    LEFT JOIN sentiment_results s ON s.evaluation_id = e.id
    WHERE {$whereSql}
";
$stmtStat = $pdo->prepare($statSql);
$stmtStat->execute($params);
$stats = $stmtStat->fetch() ?: [];

$totalEval     = (int)($stats['total_evaluasi'] ?? 0);
$totalAnalyzed = (int)($stats['total_analyzed'] ?? 0);
$cntPos        = (int)($stats['count_positif'] ?? 0);
$cntNeg        = (int)($stats['count_negatif'] ?? 0);
$cntNeu        = (int)($stats['count_netral'] ?? 0);
$avgRating     = (float)($stats['avg_keseluruhan'] ?? 0.0);
$dummyCount    = (int)($stats['dummy_count'] ?? 0);

$pctPos = $totalEval > 0 ? round(($cntPos / $totalEval) * 100, 1) : 0.0;
$pctNeg = $totalEval > 0 ? round(($cntNeg / $totalEval) * 100, 1) : 0.0;
$pctNeu = $totalEval > 0 ? round(($cntNeu / $totalEval) * 100, 1) : 0.0;

// 2. Query Grafik Batang: Perbandingan Sentimen Berdasarkan Kategori Kegiatan
$catSql = "
    SELECT
        a.category,
        SUM(CASE WHEN s.sentiment_label = 'positif' THEN 1 ELSE 0 END) AS pos,
        SUM(CASE WHEN s.sentiment_label = 'negatif' THEN 1 ELSE 0 END) AS neg,
        SUM(CASE WHEN s.sentiment_label = 'netral'  THEN 1 ELSE 0 END) AS neu
    FROM evaluations e
    JOIN activities a ON a.id = e.activity_id
    LEFT JOIN sentiment_results s ON s.evaluation_id = e.id
    WHERE {$whereSql}
    GROUP BY a.category
    ORDER BY a.category ASC
";
$stmtCat = $pdo->prepare($catSql);
$stmtCat->execute($params);
$catRows = $stmtCat->fetchAll();

// 3. Query Grafik Garis: Tren Sentimen Berdasarkan Tanggal Pengisian
$trendSql = "
    SELECT
        DATE(e.submitted_at) AS eval_date,
        SUM(CASE WHEN s.sentiment_label = 'positif' THEN 1 ELSE 0 END) AS pos,
        SUM(CASE WHEN s.sentiment_label = 'negatif' THEN 1 ELSE 0 END) AS neg,
        SUM(CASE WHEN s.sentiment_label = 'netral'  THEN 1 ELSE 0 END) AS neu
    FROM evaluations e
    JOIN activities a ON a.id = e.activity_id
    LEFT JOIN sentiment_results s ON s.evaluation_id = e.id
    WHERE {$whereSql}
    GROUP BY DATE(e.submitted_at)
    ORDER BY eval_date ASC
";
$stmtTrend = $pdo->prepare($trendSql);
$stmtTrend->execute($params);
$trendRows = $stmtTrend->fetchAll();

// 4. Query Grafik Distribusi Rating Keseluruhan Mahasiswa (Skala 1 - 5)
$distSql = "
    SELECT
        e.rating_keseluruhan AS score,
        COUNT(e.id) AS total
    FROM evaluations e
    JOIN activities a ON a.id = e.activity_id
    WHERE {$whereSql}
    GROUP BY e.rating_keseluruhan
    ORDER BY e.rating_keseluruhan ASC
";
$stmtDist = $pdo->prepare($distSql);
$stmtDist->execute($params);
$distMap = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
foreach ($stmtDist->fetchAll() as $dr) {
    $sc = (int)$dr['score'];
    if (isset($distMap[$sc])) {
        $distMap[$sc] = (int)$dr['total'];
    }
}

// 5. Query 6 Komentar Evaluasi Terbaru (Bebas Identitas Mahasiswa)
$recentSql = "
    SELECT
        e.id, e.evaluation_code, e.submitted_at, e.rating_keseluruhan, e.average_rating,
        e.comment_experience, e.is_dummy,
        a.activity_name, a.category,
        s.sentiment_label, s.confidence_score, s.analysis_status, s.is_ml, s.rating_sentiment_discrepancy
    FROM evaluations e
    JOIN activities a ON a.id = e.activity_id
    LEFT JOIN sentiment_results s ON s.evaluation_id = e.id
    WHERE {$whereSql}
    ORDER BY e.submitted_at DESC, e.id DESC
    LIMIT 6
";
$stmtRecent = $pdo->prepare($recentSql);
$stmtRecent->execute($params);
$recentEvals = $stmtRecent->fetchAll();

$pageTitle = 'Dashboard Admin Evaluasi PPKMB';
$showSidebar = true;
$activeMenu = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="app-wrapper">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="main-content">
        <!-- Header Dashboard -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1" style="color: #0d254c;">Dashboard Analitik Evaluasi PPKMB</h4>
                <p class="text-secondary small mb-0">
                    Pemantauan real-time penilaian aspek kegiatan dan analisis sentimen komentar mahasiswa baru.
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= e(base_url('admin/reports.php')) ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i>Laporan &amp; Ekspor
                </a>
                <a href="<?= e(base_url('admin/evaluations.php?sentiment=negatif')) ?>" class="btn btn-sm btn-danger">
                    <i class="bi bi-exclamation-circle me-1"></i>Tinjau Komentar Negatif (<?= $cntNeg ?>)
                </a>
            </div>
        </div>

        <?php if ($dummyCount > 0): ?>
            <div class="alert alert-info border-0 shadow-sm small d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
                <div>
                    <i class="bi bi-info-circle-fill me-1"></i>
                    <strong>Informasi Data Pengujian:</strong> Saat ini terdapat <strong><?= $dummyCount ?> data dummy pengujian</strong> (berpenanda <code>is_dummy = 1</code>) yang disertakan untuk demonstrasi visualisasi.
                </div>
                <?php if ($dummyMode !== 'real_only'): ?>
                    <a href="<?= e(base_url('admin/dashboard.php?dummy_mode=real_only')) ?>" class="btn btn-sm btn-light border fw-semibold">
                        Tampilkan Hanya Data Evaluasi Nyata
                    </a>
                <?php else: ?>
                    <a href="<?= e(base_url('admin/dashboard.php?dummy_mode=all')) ?>" class="btn btn-sm btn-light border fw-semibold">
                        Sertakan Kembali Data Pengujian
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Panel Filter Multi-Dimensi -->
        <div class="card-modern p-3 mb-4">
            <form method="get" action="<?= e(base_url('admin/dashboard.php')) ?>" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Periode PPKMB</label>
                    <select name="period_id" class="form-select form-select-sm">
                        <option value="0">Semua Periode</option>
                        <?php foreach ($periods as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= $filterPeriod === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= e($p['academic_year'] . ' - ' . $p['period_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Kategori Kegiatan</label>
                    <select name="category" class="form-select form-select-sm">
                        <option value="">Semua Kategori</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= e($cat) ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Fakultas</label>
                    <select name="faculty" class="form-select form-select-sm">
                        <option value="">Semua Fakultas</option>
                        <?php foreach ($faculties as $fac): ?>
                            <option value="<?= e($fac) ?>" <?= $filterFaculty === $fac ? 'selected' : '' ?>><?= e($fac) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Program Studi</label>
                    <select name="study_program" class="form-select form-select-sm">
                        <option value="">Semua Prodi</option>
                        <?php foreach ($prodis as $pr): ?>
                            <option value="<?= e($pr) ?>" <?= $filterProdi === $pr ? 'selected' : '' ?>><?= e($pr) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Mode Data</label>
                    <select name="dummy_mode" class="form-select form-select-sm">
                        <option value="all" <?= $dummyMode === 'all' ? 'selected' : '' ?>>Semua (Termasuk Dummy)</option>
                        <option value="real_only" <?= $dummyMode === 'real_only' ? 'selected' : '' ?>>Hanya Data Nyata</option>
                        <option value="dummy_only" <?= $dummyMode === 'dummy_only' ? 'selected' : '' ?>>Hanya Data Dummy</option>
                    </select>
                </div>
                <div class="col-md-1 d-grid">
                    <button type="submit" class="btn btn-sm btn-navy"><i class="bi bi-funnel me-1"></i>Filter</button>
                </div>
            </form>
        </div>

        <!-- 6 Kartu Statistik Utama -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-2">
                <div class="stat-card p-3 h-100">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="small text-secondary fw-semibold">Total Evaluasi</span>
                        <div class="stat-icon stat-navy"><i class="bi bi-people-fill"></i></div>
                    </div>
                    <div class="fs-3 fw-bold text-dark"><?= number_format($totalEval) ?></div>
                    <div class="small text-muted">Mahasiswa berpartisipasi</div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-2">
                <div class="stat-card p-3 h-100">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="small text-secondary fw-semibold">Komentar Dianalisis</span>
                        <div class="stat-icon stat-sky"><i class="bi bi-cpu-fill"></i></div>
                    </div>
                    <div class="fs-3 fw-bold text-dark"><?= number_format($totalAnalyzed) ?></div>
                    <div class="small text-muted">Terproses modul NLP</div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-2">
                <div class="stat-card p-3 h-100">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="small text-secondary fw-semibold">Sentimen Positif</span>
                        <div class="stat-icon stat-pos"><i class="bi bi-emoji-smile-fill"></i></div>
                    </div>
                    <div class="fs-3 fw-bold text-success"><?= number_format($cntPos) ?></div>
                    <div class="small text-success fw-semibold"><?= $pctPos ?>% dari total</div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-2">
                <div class="stat-card p-3 h-100">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="small text-secondary fw-semibold">Sentimen Negatif</span>
                        <div class="stat-icon stat-neg"><i class="bi bi-emoji-frown-fill"></i></div>
                    </div>
                    <div class="fs-3 fw-bold text-danger"><?= number_format($cntNeg) ?></div>
                    <div class="small text-danger fw-semibold"><?= $pctNeg ?>% perlu tinjauan</div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-2">
                <div class="stat-card p-3 h-100">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="small text-secondary fw-semibold">Sentimen Netral</span>
                        <div class="stat-icon stat-neu"><i class="bi bi-emoji-neutral-fill"></i></div>
                    </div>
                    <div class="fs-3 fw-bold text-secondary"><?= number_format($cntNeu) ?></div>
                    <div class="small text-muted"><?= $pctNeu ?>% informatif</div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-2">
                <div class="stat-card p-3 h-100">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="small text-secondary fw-semibold">Rata-rata Rating</span>
                        <div class="stat-icon stat-navy"><i class="bi bi-star-fill text-warning"></i></div>
                    </div>
                    <div class="fs-3 fw-bold text-primary"><?= number_format($avgRating, 2) ?></div>
                    <div class="small text-muted">Indeks 7 Aspek: <?= number_format((float)($stats['avg_all_aspects'] ?? 0), 2) ?>/5</div>
                </div>
            </div>
        </div>

        <!-- Baris Grafik 1: Donat Sentimen & Batang Sentimen per Kategori -->
        <div class="row g-4 mb-4">
            <div class="col-lg-4">
                <div class="card-modern h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span><i class="bi bi-pie-chart-fill me-2 text-primary"></i>Proporsi Sentimen Komentar</span>
                    </div>
                    <div class="card-body d-flex flex-column align-items-center justify-content-center p-4">
                        <div style="width: 100%; max-width: 260px;">
                            <canvas id="chartDonutSentiment"></canvas>
                        </div>
                        <div class="d-flex justify-content-center gap-3 mt-3 small">
                            <span><i class="bi bi-circle-fill text-success me-1"></i>Positif (<?= $pctPos ?>%)</span>
                            <span><i class="bi bi-circle-fill text-danger me-1"></i>Negatif (<?= $pctNeg ?>%)</span>
                            <span><i class="bi bi-circle-fill text-secondary me-1"></i>Netral (<?= $pctNeu ?>%)</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card-modern h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span><i class="bi bi-bar-chart-fill me-2 text-primary"></i>Perbandingan Sentimen Berdasarkan Kategori Kegiatan</span>
                    </div>
                    <div class="card-body p-4">
                        <canvas id="chartBarCategory" height="125"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Baris Grafik 2: Tren Sentimen per Tanggal & Rata-rata Rating Setiap Aspek -->
        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="card-modern h-100">
                    <div class="card-header">
                        <i class="bi bi-graph-up me-2 text-primary"></i>Tren Sentimen Mahasiswa Berdasarkan Tanggal
                    </div>
                    <div class="card-body p-4">
                        <canvas id="chartLineTrend" height="150"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card-modern h-100">
                    <div class="card-header">
                        <i class="bi bi-sliders2-vertical me-2 text-primary"></i>Perbandingan Rata-rata Rating Setiap Aspek PPKMB
                    </div>
                    <div class="card-body p-4">
                        <canvas id="chartAspectRatings" height="150"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Baris Grafik 3: Distribusi Rating (1-5) & Evaluasi Terbaru -->
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card-modern h-100">
                    <div class="card-header">
                        <i class="bi bi-bar-chart-steps me-2 text-primary"></i>Distribusi Rating Keseluruhan (Skala 1&ndash;5)
                    </div>
                    <div class="card-body p-4">
                        <canvas id="chartRatingDist" height="200"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card-modern h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span><i class="bi bi-chat-left-text-fill me-2 text-primary"></i>Masukan Evaluasi Terbaru (Tanpa Identitas Mahasiswa)</span>
                        <a href="<?= e(base_url('admin/evaluations.php')) ?>" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle small mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-3">Kode</th>
                                        <th>Kategori Sesi</th>
                                        <th>Ringkasan Komentar</th>
                                        <th class="text-center">Rating</th>
                                        <th class="text-center">Sentimen Teks</th>
                                        <th class="text-center pe-3">Keyakinan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentEvals)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4 text-muted">Belum ada data evaluasi untuk filter ini.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recentEvals as $re): ?>
                                            <?php
                                            $lbl = $re['sentiment_label'] ?? 'netral';
                                            $bCls = $lbl === 'positif' ? 'badge-positif' : ($lbl === 'negatif' ? 'badge-negatif' : 'badge-netral');
                                            ?>
                                            <tr>
                                                <td class="ps-3 fw-semibold">
                                                    <code><?= e($re['evaluation_code']) ?></code>
                                                    <?php if ((int)$re['is_dummy'] === 1): ?>
                                                        <span class="badge bg-warning-subtle text-warning-emphasis border" style="font-size:0.62rem;">DUMMY</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= e($re['category']) ?></td>
                                                <td style="max-width: 280px;" class="text-truncate" title="<?= e($re['comment_experience']) ?>">
                                                    <?= e($re['comment_experience']) ?>
                                                </td>
                                                <td class="text-center fw-bold text-primary"><?= (int)$re['rating_keseluruhan'] ?>/5</td>
                                                <td class="text-center">
                                                    <span class="badge <?= e($bCls) ?> text-uppercase"><?= e($lbl) ?></span>
                                                    <?php if ((int)($re['rating_sentiment_discrepancy'] ?? 0) === 1): ?>
                                                        <i class="bi bi-exclamation-diamond-fill text-warning ms-1" title="Diskrepansi: Skor rating angka berbeda arah dengan sentimen komentar teks"></i>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center pe-3">
                                                    <?= number_format((float)($re['confidence_score'] ?? 0) * 100, 1) ?>%
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;

    // 1. Diagram Donat Sentimen
    new Chart(document.getElementById('chartDonutSentiment'), {
        type: 'doughnut',
        data: {
            labels: ['Positif', 'Negatif', 'Netral'],
            datasets: [{
                data: [<?= $cntPos ?>, <?= $cntNeg ?>, <?= $cntNeu ?>],
                backgroundColor: ['#16a34a', '#dc2626', '#64748b'],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'bottom' } },
            cutout: '64%'
        }
    });

    // 2. Diagram Batang Sentimen Berdasarkan Kategori Kegiatan
    const catLabels = <?= json_encode(array_column($catRows, 'category'), JSON_UNESCAPED_UNICODE) ?>;
    const catPos    = <?= json_encode(array_map('intval', array_column($catRows, 'pos'))) ?>;
    const catNeg    = <?= json_encode(array_map('intval', array_column($catRows, 'neg'))) ?>;
    const catNeu    = <?= json_encode(array_map('intval', array_column($catRows, 'neu'))) ?>;

    new Chart(document.getElementById('chartBarCategory'), {
        type: 'bar',
        data: {
            labels: catLabels,
            datasets: [
                { label: 'Positif', data: catPos, backgroundColor: '#16a34a', borderRadius: 4 },
                { label: 'Negatif', data: catNeg, backgroundColor: '#dc2626', borderRadius: 4 },
                { label: 'Netral',  data: catNeu, backgroundColor: '#64748b', borderRadius: 4 }
            ]
        },
        options: {
            responsive: true,
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 } }
            },
            plugins: { legend: { position: 'top' } }
        }
    });

    // 3. Grafik Garis Tren Sentimen Berdasarkan Tanggal
    const trendDates = <?= json_encode(array_column($trendRows, 'eval_date')) ?>;
    const trendPos   = <?= json_encode(array_map('intval', array_column($trendRows, 'pos'))) ?>;
    const trendNeg   = <?= json_encode(array_map('intval', array_column($trendRows, 'neg'))) ?>;
    const trendNeu   = <?= json_encode(array_map('intval', array_column($trendRows, 'neu'))) ?>;

    new Chart(document.getElementById('chartLineTrend'), {
        type: 'line',
        data: {
            labels: trendDates,
            datasets: [
                { label: 'Positif', data: trendPos, borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,0.12)', fill: true, tension: 0.3 },
                { label: 'Negatif', data: trendNeg, borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,0.12)', fill: true, tension: 0.3 },
                { label: 'Netral',  data: trendNeu, borderColor: '#64748b', backgroundColor: 'rgba(100,116,139,0.10)', fill: true, tension: 0.3 }
            ]
        },
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });

    // 4. Grafik Perbandingan Rata-rata Rating Setiap Aspek PPKMB
    new Chart(document.getElementById('chartAspectRatings'), {
        type: 'bar',
        data: {
            labels: ['Materi', 'Pemateri', 'Panitia', 'Fasilitas', 'Konsumsi', 'Jadwal', 'Keseluruhan'],
            datasets: [{
                label: 'Rata-rata Skor (Skala 1-5)',
                data: [
                    <?= (float)($stats['avg_materi'] ?? 0) ?>,
                    <?= (float)($stats['avg_pemateri'] ?? 0) ?>,
                    <?= (float)($stats['avg_panitia'] ?? 0) ?>,
                    <?= (float)($stats['avg_fasilitas'] ?? 0) ?>,
                    <?= (float)($stats['avg_konsumsi'] ?? 0) ?>,
                    <?= (float)($stats['avg_jadwal'] ?? 0) ?>,
                    <?= (float)($stats['avg_keseluruhan'] ?? 0) ?>
                ],
                backgroundColor: ['#0d254c', '#163a70', '#0284c7', '#0ea5e9', '#38bdf8', '#2563eb', '#1d4ed8'],
                borderRadius: 6
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            scales: {
                x: { min: 0, max: 5 }
            },
            plugins: { legend: { display: false } }
        }
    });

    // 5. Grafik Distribusi Rating Mahasiswa (Skala 1-5)
    new Chart(document.getElementById('chartRatingDist'), {
        type: 'bar',
        data: {
            labels: ['Skor 1 (Sangat Kurang)', 'Skor 2 (Kurang)', 'Skor 3 (Cukup)', 'Skor 4 (Baik)', 'Skor 5 (Sangat Baik)'],
            datasets: [{
                label: 'Jumlah Mahasiswa',
                data: [<?= $distMap[1] ?>, <?= $distMap[2] ?>, <?= $distMap[3] ?>, <?= $distMap[4] ?>, <?= $distMap[5] ?>],
                backgroundColor: ['#dc2626', '#f97316', '#eab308', '#0ea5e9', '#16a34a'],
                borderRadius: 5
            }]
        },
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
            plugins: { legend: { display: false } }
        }
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
