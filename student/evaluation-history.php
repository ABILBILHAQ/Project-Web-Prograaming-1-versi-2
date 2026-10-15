<?php
/**
 * Halaman Riwayat Pengisian & Ringkasan Hasil Evaluasi Umum PPKMB
 * - Menampilkan status evaluasi berdasarkan Kode Bukti (EV-YYYY-XXXX) tanpa membuka identitas.
 * - Menampilkan ringkasan hasil evaluasi umum apabila diizinkan oleh Administrator (allow_student_summary = 1).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$currUser = current_user();
$sessionCodes = $_SESSION['submitted_eval_codes'] ?? [];

// Cek pencarian berdasarkan kode bukti evaluasi
$lookupCode = trim((string)($_GET['code'] ?? ''));
$lookedUpEval = null;
if ($lookupCode !== '') {
    $stmtCode = $pdo->prepare(
        "SELECT e.evaluation_code, e.submitted_at, e.rating_keseluruhan, e.average_rating,
                a.activity_name, a.category, p.period_name,
                s.sentiment_label, s.analysis_status
         FROM evaluations e
         JOIN activities a ON a.id = e.activity_id
         JOIN periods p ON p.id = e.period_id
         LEFT JOIN sentiment_results s ON s.evaluation_id = e.id
         WHERE e.evaluation_code = :code LIMIT 1"
    );
    $stmtCode->execute([':code' => $lookupCode]);
    $lookedUpEval = $stmtCode->fetch();
}

// Ambil periode aktif untuk mengecek apakah ringkasan hasil evaluasi umum diizinkan admin
$activePeriod = $pdo->query("SELECT * FROM periods WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetch();
if (!$activePeriod) {
    $activePeriod = $pdo->query("SELECT * FROM periods ORDER BY id DESC LIMIT 1")->fetch();
}

$allowSummary = $activePeriod && (int)$activePeriod['allow_student_summary'] === 1;
$summaryStats = null;
$activitySummary = [];

if ($allowSummary && $activePeriod) {
    $pid = (int)$activePeriod['id'];
    $stStmt = $pdo->prepare(
        "SELECT
            COUNT(e.id) AS total_responses,
            ROUND(AVG(e.rating_materi), 2) AS avg_materi,
            ROUND(AVG(e.rating_pemateri), 2) AS avg_pemateri,
            ROUND(AVG(e.rating_panitia), 2) AS avg_panitia,
            ROUND(AVG(e.rating_fasilitas), 2) AS avg_fasilitas,
            ROUND(AVG(e.rating_konsumsi), 2) AS avg_konsumsi,
            ROUND(AVG(e.rating_jadwal), 2) AS avg_jadwal,
            ROUND(AVG(e.rating_keseluruhan), 2) AS avg_keseluruhan,
            SUM(CASE WHEN s.sentiment_label = 'positif' THEN 1 ELSE 0 END) AS pos_count,
            SUM(CASE WHEN s.sentiment_label = 'negatif' THEN 1 ELSE 0 END) AS neg_count,
            SUM(CASE WHEN s.sentiment_label = 'netral'  THEN 1 ELSE 0 END) AS neu_count
         FROM evaluations e
         LEFT JOIN sentiment_results s ON s.evaluation_id = e.id
         WHERE e.period_id = :pid"
    );
    $stStmt->execute([':pid' => $pid]);
    $summaryStats = $stStmt->fetch();

    $actStmt = $pdo->prepare(
        "SELECT
            a.activity_name,
            a.category,
            a.activity_date,
            COUNT(e.id) AS total_eval,
            ROUND(AVG(e.rating_keseluruhan), 2) AS avg_rating,
            SUM(CASE WHEN s.sentiment_label = 'positif' THEN 1 ELSE 0 END) AS pos_cnt,
            SUM(CASE WHEN s.sentiment_label = 'negatif' THEN 1 ELSE 0 END) AS neg_cnt,
            SUM(CASE WHEN s.sentiment_label = 'netral'  THEN 1 ELSE 0 END) AS neu_cnt
         FROM activities a
         LEFT JOIN evaluations e ON e.activity_id = a.id
         LEFT JOIN sentiment_results s ON s.evaluation_id = e.id
         WHERE a.period_id = :pid
         GROUP BY a.id, a.activity_name, a.category, a.activity_date
         ORDER BY a.activity_date ASC, a.id ASC"
    );
    $actStmt->execute([':pid' => $pid]);
    $activitySummary = $actStmt->fetchAll();
}

// Jika mahasiswa login, ambil daftar sesi kegiatan yang pernah diikuti dari user_participation_logs
$myParticipations = [];
if ($currUser) {
    $pStmt = $pdo->prepare(
        "SELECT upl.participated_at, a.activity_name, a.category, p.period_name
         FROM user_participation_logs upl
         JOIN activities a ON a.id = upl.activity_id
         JOIN periods p ON p.id = upl.period_id
         WHERE upl.user_id = :uid
         ORDER BY upl.participated_at DESC"
    );
    $pStmt->execute([':uid' => $currUser['id']]);
    $myParticipations = $pStmt->fetchAll();
}

$pageTitle = 'Riwayat & Ringkasan Evaluasi Umum';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container py-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1" style="color: #0d254c;">
                <i class="bi bi-bar-chart-steps text-primary me-2"></i>Verifikasi Bukti &amp; Ringkasan Hasil Evaluasi Umum
            </h4>
            <p class="text-secondary small mb-0">
                Cek status bukti pengiriman evaluasi Anda dan lihat ringkasan indeks kepuasan PPKMB secara agregat (tanpa identitas individu).
            </p>
        </div>
        <a href="<?= e(base_url('student/evaluation-form.php')) ?>" class="btn btn-navy">
            <i class="bi bi-pencil-square me-1"></i>Isi Evaluasi Baru
        </a>
    </div>

    <!-- Baris 1: Pencarian Kode Bukti Evaluasi & Riwayat Sesi Browser/Akun -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card-modern h-100 p-4">
                <h6 class="fw-bold mb-2" style="color: #0d254c;"><i class="bi bi-search me-2 text-primary"></i>Cek Status Kode Bukti Evaluasi</h6>
                <p class="small text-secondary mb-3">
                    Masukkan kode bukti pengiriman (contoh: <code>EV-2026-D001</code>) untuk memastikan evaluasi Anda telah tercatat dan dianalisis.
                </p>
                <form method="get" action="<?= e(base_url('student/evaluation-history.php')) ?>" class="d-flex gap-2 mb-3">
                    <input type="text" class="form-control" name="code" value="<?= e($lookupCode) ?>"
                           placeholder="Masukkan Kode Bukti (mis. EV-2026-D001)" required>
                    <button type="submit" class="btn btn-navy px-3">Cek</button>
                </form>

                <?php if ($lookupCode !== ''): ?>
                    <?php if ($lookedUpEval): ?>
                        <div class="p-3 rounded-3 bg-success-subtle border border-success-subtle small">
                            <div class="fw-bold text-success mb-1">
                                <i class="bi bi-patch-check-fill me-1"></i>Kode <?= e($lookedUpEval['evaluation_code']) ?> Terdaftar &amp; Valid
                            </div>
                            <div><strong>Kegiatan:</strong> <?= e($lookedUpEval['activity_name']) ?> (<?= e($lookedUpEval['period_name']) ?>)</div>
                            <div><strong>Waktu Kirim:</strong> <?= e($lookedUpEval['submitted_at']) ?></div>
                            <div><strong>Rata-rata Rating:</strong> <?= e($lookedUpEval['average_rating']) ?> / 5.00</div>
                            <div>
                                <strong>Status Analisis Sentimen:</strong>
                                <span class="badge bg-success text-uppercase"><?= e($lookedUpEval['sentiment_label'] ?? 'netral') ?></span>
                                (<?= e($lookedUpEval['analysis_status'] ?? 'analyzed') ?>)
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning small mb-0">
                            Kode bukti <code><?= e($lookupCode) ?></code> tidak ditemukan. Pastikan penulisan huruf dan tanda hubung sudah sesuai.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card-modern h-100 p-4">
                <h6 class="fw-bold mb-2" style="color: #0d254c;"><i class="bi bi-clock-history me-2 text-primary"></i>Riwayat Partisipasi Anda</h6>
                <?php if (!empty($sessionCodes)): ?>
                    <div class="mb-3">
                        <div class="small text-muted mb-1">Kode Bukti pada Sesi Browser Ini:</div>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($sessionCodes as $sc): ?>
                                <a href="<?= e(base_url('student/evaluation-history.php?code=' . urlencode((string)$sc))) ?>" class="badge bg-primary-subtle text-primary border border-primary-subtle text-decoration-none p-2">
                                    <i class="bi bi-receipt me-1"></i><?= e($sc) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($currUser && !empty($myParticipations)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover small mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Sesi Kegiatan</th>
                                    <th>Kategori</th>
                                    <th>Waktu Partisipasi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($myParticipations as $mp): ?>
                                    <tr>
                                        <td class="fw-semibold"><?= e($mp['activity_name']) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= e($mp['category']) ?></span></td>
                                        <td class="text-muted"><?= e($mp['participated_at']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif (!$currUser && empty($sessionCodes)): ?>
                    <p class="small text-secondary mb-0">
                        Anda sedang mengakses dalam mode pengunjung anonim. Ketika Anda mengirim evaluasi, kode bukti pengiriman akan tampil di sini selama sesi browser berlangsung.
                    </p>
                <?php else: ?>
                    <p class="small text-secondary mb-0">Belum ada riwayat evaluasi non-anonim yang tercatat pada akun ini.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Baris 2: Ringkasan Hasil Evaluasi Umum (Jika Diizinkan Administrator) -->
    <?php if ($allowSummary && $summaryStats): ?>
        <div class="card-modern p-4 mb-4">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 border-bottom pb-3 mb-4">
                <div>
                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle mb-1">Transparansi Evaluasi Kampus</span>
                    <h5 class="fw-bold mb-0" style="color: #0d254c;">
                        Ringkasan Hasil Evaluasi Umum: <?= e($activePeriod['period_name']) ?>
                    </h5>
                </div>
                <div class="small text-muted">
                    Total Partisipasi: <strong><?= (int)$summaryStats['total_responses'] ?> Evaluasi</strong>
                </div>
            </div>

            <div class="row g-4 align-items-center">
                <div class="col-lg-7">
                    <h6 class="fw-bold small text-uppercase text-secondary mb-3">Indeks Rata-rata Penilaian per Aspek (Skala 1.00 &ndash; 5.00)</h6>
                    <?php
                    $aspectBars = [
                        'Materi Kegiatan'          => (float)$summaryStats['avg_materi'],
                        'Pemateri / Narasumber'    => (float)$summaryStats['avg_pemateri'],
                        'Sikap & Koordinasi Panitia' => (float)$summaryStats['avg_panitia'],
                        'Fasilitas & Sarana'       => (float)$summaryStats['avg_fasilitas'],
                        'Konsumsi & Air Minum'     => (float)$summaryStats['avg_konsumsi'],
                        'Ketepatan Jadwal'         => (float)$summaryStats['avg_jadwal'],
                        'Pelaksanaan Keseluruhan'  => (float)$summaryStats['avg_keseluruhan'],
                    ];
                    foreach ($aspectBars as $lbl => $val):
                        $pct = min(100, max(0, ($val / 5.0) * 100));
                    ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="fw-semibold"><?= e($lbl) ?></span>
                                <span class="fw-bold text-primary"><?= number_format($val, 2) ?> / 5.00</span>
                            </div>
                            <div class="progress" style="height: 9px;">
                                <div class="progress-bar" role="progressbar" style="width: <?= $pct ?>%; background-color: #0d254c;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="col-lg-5">
                    <div class="p-3 rounded-3 bg-light border text-center">
                        <h6 class="fw-bold small text-uppercase text-secondary mb-3">Distribusi Sentimen Komentar Mahasiswa</h6>
                        <div style="max-width: 240px; margin: 0 auto;">
                            <canvas id="publicSentimentChart"></canvas>
                        </div>
                        <div class="d-flex justify-content-center gap-3 mt-3 small">
                            <span><span class="badge badge-positif">Positif: <?= (int)$summaryStats['pos_count'] ?></span></span>
                            <span><span class="badge badge-negatif">Negatif: <?= (int)$summaryStats['neg_count'] ?></span></span>
                            <span><span class="badge badge-netral">Netral: <?= (int)$summaryStats['neu_count'] ?></span></span>
                        </div>
                    </div>
                </div>
            </div>

            <hr class="my-4">
            <h6 class="fw-bold small text-uppercase text-secondary mb-3">Rekapitulasi per Sesi Kegiatan PPKMB</h6>
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle small mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Sesi Kegiatan PPKMB</th>
                            <th>Kategori</th>
                            <th>Tanggal</th>
                            <th class="text-center">Responden</th>
                            <th class="text-center">Rata-rata Rating</th>
                            <th class="text-center">Sentimen (Pos / Neg / Net)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activitySummary as $as): ?>
                            <tr>
                                <td class="fw-semibold"><?= e($as['activity_name']) ?></td>
                                <td><span class="badge bg-light text-dark border"><?= e($as['category']) ?></span></td>
                                <td><?= e($as['activity_date']) ?></td>
                                <td class="text-center fw-bold"><?= (int)$as['total_eval'] ?></td>
                                <td class="text-center text-primary fw-bold"><?= number_format((float)$as['avg_rating'], 2) ?></td>
                                <td class="text-center">
                                    <span class="badge badge-positif"><?= (int)$as['pos_cnt'] ?></span>
                                    <span class="badge badge-negatif"><?= (int)$as['neg_cnt'] ?></span>
                                    <span class="badge badge-netral"><?= (int)$as['neu_cnt'] ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const ctx = document.getElementById('publicSentimentChart');
            if (ctx && typeof Chart !== 'undefined') {
                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Positif', 'Negatif', 'Netral'],
                        datasets: [{
                            data: [
                                <?= (int)$summaryStats['pos_count'] ?>,
                                <?= (int)$summaryStats['neg_count'] ?>,
                                <?= (int)$summaryStats['neu_count'] ?>
                            ],
                            backgroundColor: ['#16a34a', '#dc2626', '#64748b'],
                            borderWidth: 2,
                            borderColor: '#ffffff'
                        }]
                    },
                    options: {
                        plugins: {
                            legend: { position: 'bottom' }
                        },
                        cutout: '62%'
                    }
                });
            }
        });
        </script>
    <?php else: ?>
        <div class="alert alert-info border-0 shadow-sm">
            <i class="bi bi-info-circle-fill me-2"></i>
            Ringkasan hasil evaluasi umum saat ini sedang ditutup sementara oleh Administrator hingga periode pengisian evaluasi selesai.
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
