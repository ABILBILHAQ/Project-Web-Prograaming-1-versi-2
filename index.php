<?php
/**
 * Halaman Utama (Landing Page)
 * Sistem Evaluasi Kegiatan Mahasiswa terhadap PPKMB Berbasis Analisis Sentimen
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Ambil periode aktif dan statistik ringkas
$activePeriod = $pdo->query("SELECT * FROM periods WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetch();
if (!$activePeriod) {
    $activePeriod = $pdo->query("SELECT * FROM periods ORDER BY id DESC LIMIT 1")->fetch();
}

$statsStmt = $pdo->query(
    "SELECT
        COUNT(e.id) AS total_eval,
        ROUND(AVG(e.rating_keseluruhan), 2) AS avg_rating,
        SUM(CASE WHEN s.sentiment_label = 'positif' THEN 1 ELSE 0 END) AS total_pos,
        SUM(CASE WHEN s.sentiment_label = 'negatif' THEN 1 ELSE 0 END) AS total_neg,
        SUM(CASE WHEN s.sentiment_label = 'netral'  THEN 1 ELSE 0 END) AS total_neu
     FROM evaluations e
     LEFT JOIN sentiment_results s ON s.evaluation_id = e.id"
);
$quickStats = $statsStmt->fetch() ?: ['total_eval' => 0, 'avg_rating' => 0, 'total_pos' => 0, 'total_neg' => 0, 'total_neu' => 0];

$latestMetric = $pdo->query("SELECT * FROM model_metrics ORDER BY evaluated_at DESC, id DESC LIMIT 1")->fetch();

$pageTitle = 'Beranda Sistem Evaluasi PPKMB';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Hero Section -->
<section class="py-5 text-white" style="background: linear-gradient(135deg, #071733 0%, #0d254c 60%, #163a70 100%); border-bottom: 4px solid #38bdf8;">
    <div class="container py-3">
        <div class="row align-items-center g-4">
            <div class="col-lg-7">
                <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill mb-3" style="background: rgba(56, 189, 248, 0.16); border: 1px solid rgba(56, 189, 248, 0.4);">
                    <i class="bi bi-stars text-info"></i>
                    <span class="small fw-semibold text-info">
                        <?= $activePeriod ? e($activePeriod['period_name']) : 'Evaluasi PPKMB Kampus' ?>
                    </span>
                </div>
                <h1 class="display-6 fw-bold mb-3">
                    Sistem Evaluasi Kegiatan Mahasiswa terhadap PPKMB Berbasis Analisis Sentimen
                </h1>
                <p class="lead text-white-50 mb-4" style="font-size: 1.05rem;">
                    Sampaikan penilaian, pengalaman, kritik, dan saran Anda terhadap pelaksanaan PPKMB secara aman dan anonim. Setiap masukan dianalisis secara otomatis menggunakan Natural Language Processing (NLP) Bahasa Indonesia untuk meningkatkan kualitas kegiatan tahun berikutnya.
                </p>
                <div class="d-flex flex-wrap gap-3">
                    <a href="<?= e(base_url('student/evaluation-form.php')) ?>" class="btn btn-sky btn-lg px-4 shadow">
                        <i class="bi bi-pencil-square me-2"></i>Isi Formulir Evaluasi Sekarang
                    </a>
                    <a href="<?= e(base_url('student/evaluation-history.php')) ?>" class="btn btn-outline-light btn-lg px-4">
                        <i class="bi bi-pie-chart me-2"></i>Lihat Ringkasan Evaluasi
                    </a>
                </div>
                <div class="d-flex flex-wrap gap-4 mt-4 pt-2 text-white-50 small">
                    <div><i class="bi bi-shield-check text-info me-1"></i>Mendukung Mode Anonim Penuh</div>
                    <div><i class="bi bi-eye-slash text-info me-1"></i>Penyensoran Identitas Pribadi (PII) Otomatis</div>
                    <div><i class="bi bi-cpu text-info me-1"></i>TF-IDF &amp; Klasifikasi Machine Learning</div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card border-0 shadow-lg rounded-4 p-4 text-dark">
                    <div class="d-flex align-items-center justify-content-between border-bottom pb-3 mb-3">
                        <div>
                            <span class="badge bg-primary-subtle text-primary fw-semibold mb-1">Status Sistem Aktif</span>
                            <h5 class="fw-bold mb-0" style="color: #0d254c;">Ringkasan Partisipasi &amp; Model</h5>
                        </div>
                        <i class="bi bi-bar-chart-line-fill fs-2 text-info"></i>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <div class="p-3 rounded-3 bg-light border">
                                <div class="text-muted small">Total Evaluasi Masuk</div>
                                <div class="fs-3 fw-bold text-dark"><?= (int)$quickStats['total_eval'] ?></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 rounded-3 bg-light border">
                                <div class="text-muted small">Rata-rata Rating</div>
                                <div class="fs-3 fw-bold text-primary">
                                    <?= number_format((float)$quickStats['avg_rating'], 2) ?> <small class="fs-6 text-muted">/ 5.0</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php if ($latestMetric): ?>
                        <div class="p-3 rounded-3 mb-3" style="background-color: #f0f9ff; border: 1px solid #bae6fd;">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="small fw-bold text-dark"><i class="bi bi-cpu-fill text-primary me-1"></i>Model ML Aktif</span>
                                <span class="badge bg-primary"><?= e($latestMetric['model_version']) ?></span>
                            </div>
                            <div class="row text-center mt-2 g-2">
                                <div class="col-3">
                                    <div class="small text-muted" style="font-size:0.7rem;">Accuracy</div>
                                    <div class="fw-bold text-dark"><?= number_format((float)$latestMetric['accuracy'] * 100, 1) ?>%</div>
                                </div>
                                <div class="col-3">
                                    <div class="small text-muted" style="font-size:0.7rem;">Precision</div>
                                    <div class="fw-bold text-dark"><?= number_format((float)$latestMetric['precision_score'] * 100, 1) ?>%</div>
                                </div>
                                <div class="col-3">
                                    <div class="small text-muted" style="font-size:0.7rem;">Recall</div>
                                    <div class="fw-bold text-dark"><?= number_format((float)$latestMetric['recall_score'] * 100, 1) ?>%</div>
                                </div>
                                <div class="col-3">
                                    <div class="small text-muted" style="font-size:0.7rem;">F1-Score</div>
                                    <div class="fw-bold text-success"><?= number_format((float)$latestMetric['f1_score'] * 100, 1) ?>%</div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="d-grid gap-2">
                        <a href="<?= e(base_url('login.php')) ?>" class="btn btn-navy">
                            <i class="bi bi-lock me-1"></i>Masuk sebagai Admin / Panitia / Mahasiswa
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Fitur Utama -->
<section class="py-5">
    <div class="container">
        <div class="text-center max-w-700 mx-auto mb-5">
            <h6 class="text-uppercase fw-bold text-primary mb-2">Arsitektur Evaluasi Terpadu</h6>
            <h3 class="fw-bold" style="color: #0d254c;">Mengapa Evaluasi PPKMB Ini Berbeda?</h3>
            <p class="text-secondary mb-0">Menggabungkan penilaian kuantitatif 6 aspek dengan analisis sentimen kualitatif berbasis pembelajaran mesin.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card-modern h-100 p-4">
                    <div class="stat-icon stat-navy mb-3"><i class="bi bi-incognito"></i></div>
                    <h5 class="fw-bold">Privasi &amp; Anonimitas Mahasiswa</h5>
                    <p class="text-secondary small mb-0">
                        Mahasiswa dapat mengirim evaluasi tanpa login (mode anonim) maupun dengan akun. Identitas mahasiswa dipisahkan dari tabel evaluasi dan nomor NIM/telepon/email disamarkan secara otomatis sebelum dianalisis.
                    </p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card-modern h-100 p-4">
                    <div class="stat-icon stat-sky mb-3"><i class="bi bi-chat-heart"></i></div>
                    <h5 class="fw-bold">NLP Bahasa Indonesia</h5>
                    <p class="text-secondary small mb-0">
                        Dilengkapi normalisasi singkatan Bahasa Indonesia (<em>yg, gak, bgt, maba, narsum</em>), penanganan kata negasi (<em>tidak jelas, kurang ramah</em>), serta pemisahan indikator rating angka dari sentimen teks.
                    </p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card-modern h-100 p-4">
                    <div class="stat-icon stat-pos mb-3"><i class="bi bi-graph-up-arrow"></i></div>
                    <h5 class="fw-bold">Keputusan Berbasis Data</h5>
                    <p class="text-secondary small mb-0">
                        Panitia dan pihak kampus mendapatkan dashboard visualisasi interaktif, prioritas tinjauan masukan negatif, perbandingan antarperiode, serta ekspor laporan ke CSV, Excel, dan cetak PDF/Print.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
