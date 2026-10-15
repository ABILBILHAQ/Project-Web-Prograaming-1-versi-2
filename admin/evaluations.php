<?php
/**
 * Halaman Data Evaluasi Mahasiswa (admin/evaluations.php)
 * Fitur:
 * - Tabel lengkap dengan ID evaluasi, tanggal, periode, kategori, rating keseluruhan,
 *   ringkasan komentar, label sentimen, skor keyakinan, dan status analisis.
 * - Filter sentimen (termasuk mode khusus Prioritas Tinjauan Komentar Negatif),
 *   filter tanggal, periode, kategori, fakultas, pencarian kata kunci, dan pagination.
 * - Modal Detail Evaluasi lengkap + Analisis Ulang komentar individual.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role(['admin', 'panitia']);

// Tangani permintaan POST untuk Analisis Ulang komentar atau Hapus Data Dummy
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $postAction = (string)($_POST['action'] ?? '');
    if ($postAction === 'reanalyze_one') {
        $evalId = (int)($_POST['evaluation_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM evaluations WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $evalId]);
        $evRow = $stmt->fetch();
        if ($evRow) {
            $cleanCrit = trim(str_replace('[DATA DUMMY PENGUJIAN]', '', (string)($evRow['comment_critique'] ?? '')));
            $pred = analyze_and_save_evaluation(
                $pdo,
                (int)$evRow['id'],
                (string)$evRow['comment_experience'],
                $cleanCrit,
                (int)$evRow['rating_keseluruhan']
            );
            write_audit_log($pdo, 'REANALYZE_EVAL', 'evaluations', (string)$evalId, "Analisis ulang evaluasi #{$evalId}: {$pred['label']}");
            set_flash('success', "Evaluasi #{$evalId} berhasil dianalisis ulang: Sentimen " . strtoupper($pred['label']) . " (" . number_format((float)$pred['confidence_score'] * 100, 1) . "%).");
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

$periods = $pdo->query("SELECT id, academic_year, period_name FROM periods ORDER BY id DESC")->fetchAll();
$categories = $pdo->query("SELECT DISTINCT category FROM activities ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);
$faculties = $pdo->query("SELECT DISTINCT faculty FROM evaluations ORDER BY faculty ASC")->fetchAll(PDO::FETCH_COLUMN);

// Ambil filter dari GET
$search     = trim((string)($_GET['q'] ?? ''));
$sentiment  = trim((string)($_GET['sentiment'] ?? ''));
$statusFlt  = trim((string)($_GET['status'] ?? ''));
$periodId   = (int)($_GET['period_id'] ?? 0);
$category   = trim((string)($_GET['category'] ?? ''));
$faculty    = trim((string)($_GET['faculty'] ?? ''));
$dateFrom   = trim((string)($_GET['date_from'] ?? ''));
$dateTo     = trim((string)($_GET['date_to'] ?? ''));
$dummyMode  = trim((string)($_GET['dummy_mode'] ?? 'all'));
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 10;

$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = "(e.comment_experience LIKE :q OR e.comment_critique LIKE :q2 OR e.evaluation_code LIKE :q3 OR a.activity_name LIKE :q4)";
    $params[':q']  = '%' . $search . '%';
    $params[':q2'] = '%' . $search . '%';
    $params[':q3'] = '%' . $search . '%';
    $params[':q4'] = '%' . $search . '%';
}
if (in_array($sentiment, ['positif', 'negatif', 'netral'], true)) {
    $where[] = "s.sentiment_label = :sent";
    $params[':sent'] = $sentiment;
}
if ($statusFlt !== '') {
    $where[] = "s.analysis_status = :st";
    $params[':st'] = $statusFlt;
}
if ($periodId > 0) {
    $where[] = "e.period_id = :pid";
    $params[':pid'] = $periodId;
}
if ($category !== '') {
    $where[] = "a.category = :cat";
    $params[':cat'] = $category;
}
if ($faculty !== '') {
    $where[] = "e.faculty = :fac";
    $params[':fac'] = $faculty;
}
if ($dateFrom !== '') {
    $where[] = "DATE(e.submitted_at) >= :dfrom";
    $params[':dfrom'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = "DATE(e.submitted_at) <= :dto";
    $params[':dto'] = $dateTo;
}
if ($dummyMode === 'real_only') {
    $where[] = "e.is_dummy = 0";
} elseif ($dummyMode === 'dummy_only') {
    $where[] = "e.is_dummy = 1";
}

$whereSql = implode(' AND ', $where);

// Hitung total baris untuk pagination
$countSql = "
    SELECT COUNT(e.id)
    FROM evaluations e
    JOIN activities a ON a.id = e.activity_id
    JOIN periods p ON p.id = e.period_id
    LEFT JOIN sentiment_results s ON s.evaluation_id = e.id
    WHERE {$whereSql}
";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

// Ambil data evaluasi
$dataSql = "
    SELECT
        e.*,
        a.activity_name, a.category, a.activity_code,
        p.academic_year, p.period_name,
        s.sentiment_label, s.confidence_score, s.prob_positif, s.prob_negatif, s.prob_netral,
        s.analysis_status, s.is_ml, s.model_version, s.cleaned_text, s.rating_sentiment_discrepancy, s.analyzed_at
    FROM evaluations e
    JOIN activities a ON a.id = e.activity_id
    JOIN periods p ON p.id = e.period_id
    LEFT JOIN sentiment_results s ON s.evaluation_id = e.id
    WHERE {$whereSql}
    ORDER BY e.submitted_at DESC, e.id DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmtData = $pdo->prepare($dataSql);
$stmtData->execute($params);
$evaluations = $stmtData->fetchAll();

$pageTitle = 'Data Evaluasi Mahasiswa';
$showSidebar = true;
$activeMenu = ($sentiment === 'negatif') ? 'evaluations_neg' : 'evaluations';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="app-wrapper">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
            <div>
                <h4 class="fw-bold mb-1" style="color: #0d254c;">
                    <?= $sentiment === 'negatif' ? 'Prioritas Tinjauan Masukan Bersentimen Negatif' : 'Data Evaluasi &amp; Analisis Sentimen Mahasiswa' ?>
                </h4>
                <p class="text-secondary small mb-0">
                    Menampilkan <?= count($evaluations) ?> dari total <?= $totalRows ?> data evaluasi (identitas pribadi mahasiswa disembunyikan demi privasi).
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= e(base_url('admin/evaluations.php?sentiment=negatif')) ?>"
                   class="btn btn-sm <?= $sentiment === 'negatif' ? 'btn-danger' : 'btn-outline-danger' ?>">
                    <i class="bi bi-funnel-fill me-1"></i>Filter Khusus Komentar Negatif
                </a>
                <a href="<?= e(base_url('admin/evaluations.php')) ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Reset Filter
                </a>
            </div>
        </div>

        <!-- Catatan Etika Evaluasi Komentar Negatif -->
        <div class="alert alert-warning border-0 shadow-sm small d-flex align-items-start gap-2 mb-4">
            <i class="bi bi-lightbulb-fill fs-5 text-warning-emphasis mt-1"></i>
            <div>
                <strong>Pedoman Interpretasi Evaluasi:</strong>
                Komentar bersentimen negatif ditampilkan sebagai <em>masukan evaluasi konstruktif</em> yang perlu ditinjau panitia untuk memprioritaskan perbaikan (seperti fasilitas, ketepatan waktu, atau konsumsi), <strong>bukan sebagai bukti mutlak bahwa kegiatan gagal</strong>. Bandingkan selalu sentimen teks dengan skor rating setiap aspek.
            </div>
        </div>

        <!-- Form Pencarian & Filter Lengkap -->
        <div class="card-modern p-3 mb-4">
            <form method="get" action="<?= e(base_url('admin/evaluations.php')) ?>" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Pencarian Kata Kunci / Kode</label>
                    <input type="text" name="q" class="form-control form-control-sm"
                           value="<?= e($search) ?>" placeholder="Cari komentar, kritik, kode...">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Label Sentimen</label>
                    <select name="sentiment" class="form-select form-select-sm">
                        <option value="">Semua Sentimen</option>
                        <option value="positif" <?= $sentiment === 'positif' ? 'selected' : '' ?>>Positif</option>
                        <option value="negatif" <?= $sentiment === 'negatif' ? 'selected' : '' ?>>Negatif (Prioritas)</option>
                        <option value="netral"  <?= $sentiment === 'netral'  ? 'selected' : '' ?>>Netral</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Periode PPKMB</label>
                    <select name="period_id" class="form-select form-select-sm">
                        <option value="0">Semua Periode</option>
                        <?php foreach ($periods as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= $periodId === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= e($p['academic_year']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Kategori Kegiatan</label>
                    <select name="category" class="form-select form-select-sm">
                        <option value="">Semua Kategori</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= e($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Rentang Tanggal Pengisian</label>
                    <div class="input-group input-group-sm">
                        <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
                        <span class="input-group-text">s/d</span>
                        <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Fakultas</label>
                    <select name="faculty" class="form-select form-select-sm">
                        <option value="">Semua Fakultas</option>
                        <?php foreach ($faculties as $fac): ?>
                            <option value="<?= e($fac) ?>" <?= $faculty === $fac ? 'selected' : '' ?>><?= e($fac) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Status Analisis</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Semua Status</option>
                        <option value="analyzed" <?= $statusFlt === 'analyzed' ? 'selected' : '' ?>>Analyzed (ML Normal)</option>
                        <option value="low_confidence" <?= $statusFlt === 'low_confidence' ? 'selected' : '' ?>>Keyakinan Rendah</option>
                        <option value="fallback_rule" <?= $statusFlt === 'fallback_rule' ? 'selected' : '' ?>>Fallback Rule</option>
                        <option value="too_short" <?= $statusFlt === 'too_short' ? 'selected' : '' ?>>Teks Terlalu Pendek</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Filter Data Pengujian</label>
                    <select name="dummy_mode" class="form-select form-select-sm">
                        <option value="all" <?= $dummyMode === 'all' ? 'selected' : '' ?>>Semua Data</option>
                        <option value="real_only" <?= $dummyMode === 'real_only' ? 'selected' : '' ?>>Hanya Evaluasi Nyata</option>
                        <option value="dummy_only" <?= $dummyMode === 'dummy_only' ? 'selected' : '' ?>>Hanya Data Dummy</option>
                    </select>
                </div>
                <div class="col-md-3 d-grid">
                    <button type="submit" class="btn btn-sm btn-navy">
                        <i class="bi bi-search me-1"></i>Terapkan Filter
                    </button>
                </div>
            </form>
        </div>

        <!-- Tabel Data Evaluasi -->
        <div class="card-modern overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle small mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">ID Evaluasi</th>
                            <th>Tanggal</th>
                            <th>Periode</th>
                            <th>Kategori Kegiatan</th>
                            <th class="text-center">Rating</th>
                            <th>Ringkasan Komentar Mahasiswa</th>
                            <th class="text-center">Sentimen</th>
                            <th class="text-center">Keyakinan</th>
                            <th class="text-center">Status Analisis</th>
                            <th class="text-end pe-3">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($evaluations)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                    Tidak ditemukan data evaluasi yang sesuai dengan filter pencarian.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($evaluations as $ev): ?>
                                <?php
                                $lbl = $ev['sentiment_label'] ?? 'netral';
                                $bCls = $lbl === 'positif' ? 'badge-positif' : ($lbl === 'negatif' ? 'badge-negatif' : 'badge-netral');
                                ?>
                                <tr>
                                    <td class="ps-3">
                                        <div class="fw-bold text-dark">#<?= (int)$ev['id'] ?></div>
                                        <code><?= e($ev['evaluation_code']) ?></code>
                                        <?php if ((int)$ev['is_dummy'] === 1): ?>
                                            <div><span class="badge bg-warning-subtle text-warning-emphasis border" style="font-size:0.62rem;">DATA DUMMY</span></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap"><?= e(date('d/m/Y H:i', strtotime((string)$ev['submitted_at']))) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= e($ev['academic_year']) ?></span></td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= e($ev['category']) ?></div>
                                        <div class="text-muted" style="font-size:0.74rem;"><?= e($ev['activity_name']) ?></div>
                                    </td>
                                    <td class="text-center">
                                        <div class="fw-bold text-primary fs-6"><?= (int)$ev['rating_keseluruhan'] ?>/5</div>
                                        <div class="text-muted" style="font-size:0.7rem;">Rata2: <?= e($ev['average_rating']) ?></div>
                                    </td>
                                    <td style="max-width: 290px;">
                                        <div class="text-dark mb-1"><?= e(mb_strimwidth((string)$ev['comment_experience'], 0, 110, '...')) ?></div>
                                        <?php if (!empty($ev['comment_critique'])): ?>
                                            <div class="text-muted fst-italic" style="font-size:0.74rem;">
                                                Saran: <?= e(mb_strimwidth((string)$ev['comment_critique'], 0, 85, '...')) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= e($bCls) ?> text-uppercase px-2 py-1"><?= e($lbl) ?></span>
                                        <?php if ((int)($ev['rating_sentiment_discrepancy'] ?? 0) === 1): ?>
                                            <div class="mt-1">
                                                <span class="badge bg-warning text-dark" style="font-size:0.64rem;" title="Rating angka bertolak belakang dengan sentimen komentar teks">
                                                    <i class="bi bi-exclamation-triangle me-1"></i>Diskrepansi
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center fw-semibold">
                                        <?= number_format((float)($ev['confidence_score'] ?? 0) * 100, 1) ?>%
                                    </td>
                                    <td class="text-center">
                                        <?php if (($ev['analysis_status'] ?? '') === 'analyzed' && (int)($ev['is_ml'] ?? 1) === 1): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">ML Terverifikasi</span>
                                        <?php elseif (($ev['analysis_status'] ?? '') === 'low_confidence'): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border">Keyakinan Rendah</span>
                                        <?php elseif (($ev['analysis_status'] ?? '') === 'fallback_rule'): ?>
                                            <span class="badge bg-secondary-subtle text-secondary border">Fallback Kata</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border"><?= e($ev['analysis_status'] ?? 'pending') ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3 text-nowrap">
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="modal" data-bs-target="#detailModal<?= (int)$ev['id'] ?>">
                                            <i class="bi bi-eye"></i> Detail
                                        </button>
                                        <form method="post" class="d-inline" data-loading="Menganalisis ulang komentar...">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="reanalyze_one">
                                            <input type="hidden" name="evaluation_id" value="<?= (int)$ev['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Analisis Ulang dengan Model Terbaru">
                                                <i class="bi bi-arrow-repeat"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>

                                <!-- Modal Detail Evaluasi -->
                                <div class="modal fade" id="detailModal<?= (int)$ev['id'] ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-lg modal-dialog-centered">
                                        <div class="modal-content border-0 shadow">
                                            <div class="modal-header text-white" style="background-color: #0d254c;">
                                                <h6 class="modal-title fw-bold">
                                                    Detail Evaluasi #<?= (int)$ev['id'] ?> (<code><?= e($ev['evaluation_code']) ?></code>)
                                                </h6>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body p-4 small">
                                                <div class="row g-3 mb-3">
                                                    <div class="col-md-6">
                                                        <div><strong>Periode:</strong> <?= e($ev['academic_year'] . ' - ' . $ev['period_name']) ?></div>
                                                        <div><strong>Sesi Kegiatan:</strong> <?= e($ev['activity_name']) ?></div>
                                                        <div><strong>Kategori:</strong> <?= e($ev['category']) ?></div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div><strong>Fakultas / Prodi:</strong> <?= e($ev['faculty'] . ' / ' . $ev['study_program']) ?></div>
                                                        <div><strong>Waktu Pengisian:</strong> <?= e($ev['submitted_at']) ?></div>
                                                        <div><strong>Status Data:</strong> <?= (int)$ev['is_dummy'] === 1 ? 'Data Pengujian (Dummy)' : 'Data Evaluasi Nyata' ?></div>
                                                    </div>
                                                </div>

                                                <h6 class="fw-bold border-bottom pb-1 mb-2">Rincian Penilaian 7 Aspek (Skala 1&ndash;5)</h6>
                                                <div class="row g-2 text-center mb-3">
                                                    <div class="col"><div class="p-2 border rounded bg-light"><div class="text-muted">Materi</div><div class="fw-bold"><?= (int)$ev['rating_materi'] ?>/5</div></div></div>
                                                    <div class="col"><div class="p-2 border rounded bg-light"><div class="text-muted">Pemateri</div><div class="fw-bold"><?= (int)$ev['rating_pemateri'] ?>/5</div></div></div>
                                                    <div class="col"><div class="p-2 border rounded bg-light"><div class="text-muted">Panitia</div><div class="fw-bold"><?= (int)$ev['rating_panitia'] ?>/5</div></div></div>
                                                    <div class="col"><div class="p-2 border rounded bg-light"><div class="text-muted">Fasilitas</div><div class="fw-bold"><?= (int)$ev['rating_fasilitas'] ?>/5</div></div></div>
                                                    <div class="col"><div class="p-2 border rounded bg-light"><div class="text-muted">Konsumsi</div><div class="fw-bold"><?= (int)$ev['rating_konsumsi'] ?>/5</div></div></div>
                                                    <div class="col"><div class="p-2 border rounded bg-light"><div class="text-muted">Jadwal</div><div class="fw-bold"><?= (int)$ev['rating_jadwal'] ?>/5</div></div></div>
                                                    <div class="col"><div class="p-2 border rounded bg-primary-subtle"><div class="text-primary">Keseluruhan</div><div class="fw-bold text-primary"><?= (int)$ev['rating_keseluruhan'] ?>/5</div></div></div>
                                                </div>

                                                <h6 class="fw-bold border-bottom pb-1 mb-2">Komentar &amp; Masukan Mahasiswa (Telah Disanitasi PII)</h6>
                                                <div class="p-3 rounded bg-light border mb-2">
                                                    <div class="fw-semibold text-secondary mb-1">Pengalaman Mahasiswa:</div>
                                                    <div class="text-dark"><?= nl2br(e($ev['comment_experience'])) ?></div>
                                                </div>
                                                <div class="p-3 rounded bg-light border mb-3">
                                                    <div class="fw-semibold text-secondary mb-1">Kritik &amp; Saran:</div>
                                                    <div class="text-dark"><?= nl2br(e($ev['comment_critique'] ?: '-')) ?></div>
                                                </div>

                                                <h6 class="fw-bold border-bottom pb-1 mb-2">Detail Analisis Sentimen NLP</h6>
                                                <div class="row g-2">
                                                    <div class="col-md-4">
                                                        <div class="p-2 border rounded">
                                                            <div class="text-muted">Label &amp; Keyakinan</div>
                                                            <span class="badge <?= e($bCls) ?> text-uppercase"><?= e($lbl) ?></span>
                                                            <strong class="ms-1"><?= number_format((float)$ev['confidence_score'] * 100, 2) ?>%</strong>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="p-2 border rounded">
                                                            <div class="text-muted">Probabilitas Kelas</div>
                                                            <div>Pos: <?= number_format((float)$ev['prob_positif'] * 100, 1) ?>% | Neg: <?= number_format((float)$ev['prob_negatif'] * 100, 1) ?>% | Net: <?= number_format((float)$ev['prob_netral'] * 100, 1) ?>%</div>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="p-2 border rounded">
                                                            <div class="text-muted">Versi Model &amp; Waktu</div>
                                                            <div><code><?= e($ev['model_version']) ?></code></div>
                                                            <div class="text-muted" style="font-size:0.7rem;"><?= e($ev['analyzed_at']) ?></div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="mt-2 text-muted" style="font-size:0.75rem;">
                                                    <strong>Hasil Preprocessing NLP:</strong> <code><?= e($ev['cleaned_text'] ?: '-') ?></code>
                                                </div>
                                            </div>
                                            <div class="modal-footer bg-light">
                                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white d-flex flex-wrap align-items-center justify-content-between py-3 px-3">
                    <div class="small text-muted">Halaman <?= $page ?> dari <?= $totalPages ?></div>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php
                            $queryParams = $_GET;
                            for ($p = 1; $p <= $totalPages; $p++):
                                $queryParams['page'] = $p;
                                $url = base_url('admin/evaluations.php?' . http_build_query($queryParams));
                            ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= e($url) ?>"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
