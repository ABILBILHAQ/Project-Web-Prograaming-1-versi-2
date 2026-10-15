<?php
/**
 * Halaman Manajemen Model Analisis Sentimen & Evaluasi Metrik ML (admin/sentiment.php)
 * Fitur:
 * - Menampilkan metrik aktual Accuracy, Precision, Recall, F1-Score, dan Confusion Matrix 3x3.
 * - Simulator uji prediksi kalimat Bahasa Indonesia secara langsung.
 * - Fitur Analisis Ulang Batch seluruh komentar apabila model diperbarui.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role(['admin', 'panitia']);

$testResult = null;
$testInputText = '';
$testInputCrit = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'test_predict') {
        $testInputText = trim((string)($_POST['test_text'] ?? ''));
        $testInputCrit = trim((string)($_POST['test_critique'] ?? ''));
        $testResult = call_python_sentiment_predictor($testInputText, $testInputCrit);
    } elseif ($act === 'reanalyze_all') {
        $rows = $pdo->query("SELECT id, comment_experience, comment_critique, rating_keseluruhan FROM evaluations ORDER BY id ASC")->fetchAll();
        $cnt = 0;
        foreach ($rows as $r) {
            $cleanCrit = trim(str_replace('[DATA DUMMY PENGUJIAN]', '', (string)($r['comment_critique'] ?? '')));
            analyze_and_save_evaluation($pdo, (int)$r['id'], (string)$r['comment_experience'], $cleanCrit, (int)$r['rating_keseluruhan']);
            $cnt++;
        }
        write_audit_log($pdo, 'REANALYZE_ALL', 'sentiment_results', 'all', "Menganalisis ulang {$cnt} komentar evaluasi dengan model terbaru.");
        set_flash('success', "Berhasil menganalisis ulang seluruh {$cnt} komentar evaluasi di database menggunakan model terbaru.");
        header('Location: ' . base_url('admin/sentiment.php'));
        exit;
    }
}

$latestMetric = $pdo->query("SELECT * FROM model_metrics ORDER BY evaluated_at DESC, id DESC LIMIT 1")->fetch();
$metricDetails = [];
if ($latestMetric && !empty($latestMetric['metrics_detail_json'])) {
    $metricDetails = json_decode((string)$latestMetric['metrics_detail_json'], true) ?: [];
} elseif (is_file($APP_ROOT . '/python/models/metrics.json')) {
    $metricDetails = json_decode((string)file_get_contents($APP_ROOT . '/python/models/metrics.json'), true) ?: [];
}

$metricsHistory = $pdo->query("SELECT * FROM model_metrics ORDER BY evaluated_at DESC, id DESC LIMIT 10")->fetchAll();

$pageTitle = 'Model Analisis Sentimen & Metrik ML';
$showSidebar = true;
$activeMenu = 'sentiment';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="app-wrapper">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1" style="color: #0d254c;">Performa Model Machine Learning &amp; Uji Sentimen</h4>
                <p class="text-secondary small mb-0">
                    Evaluasi metrik klasifikasi TF-IDF + Multinomial Logistic Regression &amp; Naive Bayes Bahasa Indonesia.
                </p>
            </div>
            <div class="d-flex gap-2">
                <form method="post" data-loading="Menganalisis ulang seluruh komentar evaluasi di database...">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reanalyze_all">
                    <button type="submit" class="btn btn-sm btn-navy">
                        <i class="bi bi-arrow-repeat me-1"></i>Analisis Ulang Seluruh Komentar
                    </button>
                </form>
                <a href="<?= e(base_url('admin/datasets.php')) ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-database-gear me-1"></i>Kelola Dataset &amp; Latih Model
                </a>
            </div>
        </div>

        <!-- 4 Kartu Metrik Performa Model Aktual -->
        <?php if ($latestMetric): ?>
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card p-3">
                        <div class="small text-secondary fw-semibold">Accuracy (5-Fold CV)</div>
                        <div class="fs-2 fw-bold text-primary"><?= number_format((float)$latestMetric['accuracy'] * 100, 2) ?>%</div>
                        <div class="small text-muted">Versi: <code><?= e($latestMetric['model_version']) ?></code></div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card p-3">
                        <div class="small text-secondary fw-semibold">Macro Precision</div>
                        <div class="fs-2 fw-bold text-dark"><?= number_format((float)$latestMetric['precision_score'] * 100, 2) ?>%</div>
                        <div class="small text-muted">Ketepatan prediksi kelas</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card p-3">
                        <div class="small text-secondary fw-semibold">Macro Recall</div>
                        <div class="fs-2 fw-bold text-dark"><?= number_format((float)$latestMetric['recall_score'] * 100, 2) ?>%</div>
                        <div class="small text-muted">Sensitivitas deteksi</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card p-3">
                        <div class="small text-secondary fw-semibold">Macro F1-Score</div>
                        <div class="fs-2 fw-bold text-success"><?= number_format((float)$latestMetric['f1_score'] * 100, 2) ?>%</div>
                        <div class="small text-muted">Sampel latih: <?= (int)$latestMetric['dataset_size'] ?> teks</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <!-- Simulator Uji Prediksi Langsung -->
            <div class="col-lg-6">
                <div class="card-modern h-100">
                    <div class="card-header">
                        <i class="bi bi-terminal-fill me-2 text-primary"></i>Uji Coba Prediksi &amp; Preprocessing Bahasa Indonesia
                    </div>
                    <div class="card-body p-4">
                        <form method="post" data-loading="Menjalankan preprocessing &amp; inferensi model ML...">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="test_predict">
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Komentar Uji (Bahasa Indonesia / Slang / Negasi / PII)</label>
                                <textarea name="test_text" class="form-control" rows="3" required
                                          placeholder="Contoh: Pematerinya keren bgt dan gak membosankan, tapi AC ruangan agak panas. Hubungi saya di 081234567890."><?= e($testInputText) ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Kritik / Saran Tambahan (Opsional)</label>
                                <input type="text" name="test_critique" class="form-control"
                                       value="<?= e($testInputCrit) ?>" placeholder="Contoh: Mohon AC diperbaiki tahun depan.">
                            </div>
                            <button type="submit" class="btn btn-navy">
                                <i class="bi bi-play-circle me-1"></i>Jalankan Analisis Sentimen
                            </button>
                        </form>

                        <?php if ($testResult): ?>
                            <?php
                            $tLbl = $testResult['label'] ?? 'netral';
                            $tCls = $tLbl === 'positif' ? 'badge-positif' : ($tLbl === 'negatif' ? 'badge-negatif' : 'badge-netral');
                            ?>
                            <div class="mt-4 p-3 rounded-3 bg-light border small">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-dark">Hasil Klasifikasi:</span>
                                    <span class="badge <?= e($tCls) ?> px-3 py-1 text-uppercase fs-6"><?= e($tLbl) ?></span>
                                </div>
                                <div class="mb-1"><strong>Skor Keyakinan:</strong> <?= number_format((float)($testResult['confidence_score'] ?? 0) * 100, 2) ?>%</div>
                                <div class="mb-1">
                                    <strong>Distribusi Probabilitas:</strong>
                                    Positif: <?= number_format((float)($testResult['probabilities']['positif'] ?? 0) * 100, 1) ?>% |
                                    Negatif: <?= number_format((float)($testResult['probabilities']['negatif'] ?? 0) * 100, 1) ?>% |
                                    Netral: <?= number_format((float)($testResult['probabilities']['netral'] ?? 0) * 100, 1) ?>%
                                </div>
                                <div class="mb-1"><strong>Teks Tersanitasi PII:</strong> <code><?= e($testResult['masked_text'] ?? '') ?></code></div>
                                <div class="mb-1"><strong>Hasil Preprocessing NLP:</strong> <code><?= e($testResult['cleaned_text'] ?? '') ?></code></div>
                                <div class="text-muted mt-2"><?= e($testResult['status_message'] ?? '') ?> (Versi: <code><?= e($testResult['model_version'] ?? '') ?></code>)</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tabel Metrik Per Kelas & Confusion Matrix -->
            <div class="col-lg-6">
                <div class="card-modern h-100">
                    <div class="card-header">
                        <i class="bi bi-grid-3x3-gap-fill me-2 text-primary"></i>Rincian Per Kelas &amp; Confusion Matrix (5-Fold CV)
                    </div>
                    <div class="card-body p-4">
                        <?php if (!empty($metricDetails['per_class'])): ?>
                            <h6 class="small fw-bold text-secondary mb-2">Metrik Evaluasi per Kelas Sentimen</h6>
                            <div class="table-responsive mb-3">
                                <table class="table table-sm table-bordered text-center small mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Kelas</th>
                                            <th>Precision</th>
                                            <th>Recall</th>
                                            <th>F1-Score</th>
                                            <th>Support</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($metricDetails['per_class'] as $cName => $cVal): ?>
                                            <tr>
                                                <td class="fw-bold text-uppercase"><?= e($cName) ?></td>
                                                <td><?= number_format((float)$cVal['precision'] * 100, 2) ?>%</td>
                                                <td><?= number_format((float)$cVal['recall'] * 100, 2) ?>%</td>
                                                <td class="fw-bold text-primary"><?= number_format((float)$cVal['f1_score'] * 100, 2) ?>%</td>
                                                <td><?= (int)$cVal['support'] ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($metricDetails['confusion_matrix']['matrix'])): ?>
                            <?php
                            $cmLabels = $metricDetails['confusion_matrix']['labels'];
                            $cmMatrix = $metricDetails['confusion_matrix']['matrix'];
                            ?>
                            <h6 class="small fw-bold text-secondary mb-2">Confusion Matrix (Baris = Aktual, Kolom = Prediksi)</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered text-center small mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Aktual \ Prediksi</th>
                                            <?php foreach ($cmLabels as $cl): ?>
                                                <th class="text-uppercase"><?= e($cl) ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cmMatrix as $rIdx => $rowVals): ?>
                                            <tr>
                                                <th class="table-light text-uppercase"><?= e($cmLabels[$rIdx]) ?></th>
                                                <?php foreach ($rowVals as $cIdx => $val): ?>
                                                    <td class="<?= $rIdx === $cIdx ? 'bg-success-subtle fw-bold text-success' : '' ?>">
                                                        <?= (int)$val ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
