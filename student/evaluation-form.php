<?php
/**
 * Halaman Formulir Evaluasi Kegiatan Mahasiswa terhadap PPKMB
 * Fitur:
 * - Mendukung Mode Anonim penuh (tanpa wajib mengisi identitas pribadi) maupun akun mahasiswa.
 * - Penilaian skala 1-5 pada 7 indikator (Materi, Pemateri, Panitia, Fasilitas, Konsumsi, Jadwal, Keseluruhan).
 * - Validasi input server-side & client-side, CSRF token, Rate Limiting, dan PII Masking.
 * - Konfirmasi modal sebelum pengiriman & Analisis Sentimen Otomatis (Python ML / Fallback Transparan).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$currUser = current_user();

// Ambil daftar periode aktif dan seluruh kegiatan aktif
$periods = $pdo->query("SELECT * FROM periods ORDER BY is_active DESC, id DESC")->fetchAll();
$activities = $pdo->query(
    "SELECT a.*, p.period_name, p.academic_year
     FROM activities a
     JOIN periods p ON p.id = a.period_id
     WHERE a.is_active = 1
     ORDER BY a.period_id DESC, a.activity_date ASC, a.start_time ASC"
)->fetchAll();

$defaultPeriodId = 0;
foreach ($periods as $p) {
    if ((int)$p['is_active'] === 1) {
        $defaultPeriodId = (int)$p['id'];
        break;
    }
}
if ($defaultPeriodId === 0 && !empty($periods)) {
    $defaultPeriodId = (int)$periods[0]['id'];
}

$facultiesList = [
    'Umum / Tidak Disebutkan (Anonim)',
    'Fakultas Ilmu Komputer',
    'Fakultas Teknik',
    'Fakultas Ekonomi dan Bisnis',
    'Fakultas Hukum',
    'Fakultas Ilmu Sosial dan Politik',
    'Fakultas Kedokteran dan Kesehatan',
    'Fakultas Keguruan dan Ilmu Pendidikan',
    'Fakultas Pertanian dan Sains',
];

$submissionSuccess = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $errors[] = 'Token keamanan CSRF tidak valid. Silakan muat ulang halaman dan coba lagi.';
    } elseif (!check_rate_limit('submit_evaluation', (int)env_val('RATE_LIMIT_PER_MINUTE', '20'), 60)) {
        $errors[] = 'Anda terlalu sering mengirim formulir dalam waktu singkat. Silakan tunggu 1 menit.';
    } else {
        $periodId = (int)($_POST['period_id'] ?? 0);
        $activityId = (int)($_POST['activity_id'] ?? 0);
        $isAnonymous = isset($_POST['is_anonymous']) && (string)$_POST['is_anonymous'] === '1' ? 1 : 0;

        // Jika pengguna belum login, otomatis mode anonim
        if (!$currUser) {
            $isAnonymous = 1;
        }

        $faculty = trim((string)($_POST['faculty'] ?? ''));
        $studyProgram = trim((string)($_POST['study_program'] ?? ''));

        if ($isAnonymous === 1) {
            if ($faculty === '' || str_starts_with($faculty, 'Umum')) {
                $faculty = 'Umum / Tidak Disebutkan';
            }
            if ($studyProgram === '') {
                $studyProgram = 'Umum / Tidak Disebutkan';
            }
        } else {
            if ($faculty === '') {
                $faculty = $currUser['faculty'] ?: 'Umum / Tidak Disebutkan';
            }
            if ($studyProgram === '') {
                $studyProgram = $currUser['study_program'] ?: 'Umum / Tidak Disebutkan';
            }
        }

        $rMateri      = (int)($_POST['rating_materi'] ?? 0);
        $rPemateri    = (int)($_POST['rating_pemateri'] ?? 0);
        $rPanitia     = (int)($_POST['rating_panitia'] ?? 0);
        $rFasilitas   = (int)($_POST['rating_fasilitas'] ?? 0);
        $rKonsumsi    = (int)($_POST['rating_konsumsi'] ?? 0);
        $rJadwal      = (int)($_POST['rating_jadwal'] ?? 0);
        $rKeseluruhan = (int)($_POST['rating_keseluruhan'] ?? 0);

        $maxLen = (int)env_val('MAX_COMMENT_LENGTH', '2000');
        $rawExp = substr(trim((string)($_POST['comment_experience'] ?? '')), 0, $maxLen);
        $rawCrit = substr(trim((string)($_POST['comment_critique'] ?? '')), 0, $maxLen);

        // Validasi Periode & Kegiatan
        $chkAct = $pdo->prepare("SELECT id, period_id, activity_name FROM activities WHERE id = :aid AND period_id = :pid AND is_active = 1 LIMIT 1");
        $chkAct->execute([':aid' => $activityId, ':pid' => $periodId]);
        $validActivity = $chkAct->fetch();
        if (!$validActivity) {
            $errors[] = 'Pilih Periode PPKMB dan Sesi Kegiatan yang valid dan sesuai.';
        }

        // Validasi skala rating 1-5
        $ratingsToCheck = [
            'Materi Kegiatan' => $rMateri,
            'Pemateri / Narasumber' => $rPemateri,
            'Panitia Pelaksana' => $rPanitia,
            'Fasilitas & Ruangan' => $rFasilitas,
            'Konsumsi' => $rKonsumsi,
            'Ketepatan Jadwal' => $rJadwal,
            'Keseluruhan Kegiatan' => $rKeseluruhan,
        ];
        foreach ($ratingsToCheck as $aspectName => $val) {
            if ($val < 1 || $val > 5) {
                $errors[] = "Penilaian {$aspectName} wajib dipilih dengan skala 1 sampai 5.";
            }
        }

        if ($rawExp === '') {
            $errors[] = 'Kolom Komentar / Pengalaman Mahasiswa wajib diisi.';
        } elseif (strlen($rawExp) < 10) {
            $errors[] = 'Komentar pengalaman terlalu singkat (minimal 10 karakter atau 3 kata bermakna).';
        }

        // Cek apakah akun mahasiswa yang login (mode non-anonim) sudah pernah mengisi sesi ini
        if ($currUser && $isAnonymous === 0 && empty($errors)) {
            $chkPart = $pdo->prepare("SELECT id FROM user_participation_logs WHERE user_id = :uid AND activity_id = :aid LIMIT 1");
            $chkPart->execute([':uid' => $currUser['id'], ':aid' => $activityId]);
            if ($chkPart->fetch()) {
                $errors[] = 'Akun Anda tercatat sudah mengirimkan evaluasi untuk sesi kegiatan ini. Gunakan Mode Anonim bila ingin mengirim masukan tambahan.';
            }
        }

        if (empty($errors)) {
            // Samarkan PII dari komentar sebelum disimpan ke database
            $safeExp = mask_pii_text($rawExp);
            $safeCrit = mask_pii_text($rawCrit);
            $evalCode = 'EV-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));

            $pdo->beginTransaction();
            try {
                $insEval = $pdo->prepare(
                    "INSERT INTO evaluations
                        (evaluation_code, period_id, activity_id, faculty, study_program, is_anonymous,
                         rating_materi, rating_pemateri, rating_panitia, rating_fasilitas, rating_konsumsi,
                         rating_jadwal, rating_keseluruhan, comment_experience, comment_critique, is_dummy, submitted_at)
                     VALUES
                        (:code, :pid, :aid, :fac, :prodi, :anon,
                         :rmat, :rpem, :rpan, :rfas, :rkon, :rjad, :rkes, :cexp, :ccrit, 0, NOW())"
                );
                $insEval->execute([
                    ':code'  => $evalCode,
                    ':pid'   => $periodId,
                    ':aid'   => $activityId,
                    ':fac'   => $faculty,
                    ':prodi' => $studyProgram,
                    ':anon'  => $isAnonymous,
                    ':rmat'  => $rMateri,
                    ':rpem'  => $rPemateri,
                    ':rpan'  => $rPanitia,
                    ':rfas'  => $rFasilitas,
                    ':rkon'  => $rKonsumsi,
                    ':rjad'  => $rJadwal,
                    ':rkes'  => $rKeseluruhan,
                    ':cexp'  => $safeExp,
                    ':ccrit' => $safeCrit,
                ]);
                $newEvalId = (int)$pdo->lastInsertId();

                // Jika login dan tidak memilih mode anonim, catat partisipasi di tabel terpisah dengan hash satu arah
                if ($currUser && $isAnonymous === 0) {
                    $receiptHash = hash('sha256', $evalCode);
                    $insPart = $pdo->prepare(
                        "INSERT IGNORE INTO user_participation_logs (user_id, period_id, activity_id, receipt_token_hash, participated_at)
                         VALUES (:uid, :pid, :aid, :rhash, NOW())"
                    );
                    $insPart->execute([
                        ':uid'   => $currUser['id'],
                        ':pid'   => $periodId,
                        ':aid'   => $activityId,
                        ':rhash' => $receiptHash,
                    ]);
                }

                // Analisis sentimen komentar otomatis dan simpan ke sentiment_results
                $sentimentPred = analyze_and_save_evaluation($pdo, $newEvalId, $safeExp, $safeCrit, $rKeseluruhan);

                $pdo->commit();

                // Simpan kode bukti di session browser mahasiswa untuk kemudahan pengecekan
                $_SESSION['submitted_eval_codes'][] = $evalCode;

                $submissionSuccess = [
                    'evaluation_code' => $evalCode,
                    'activity_name'   => $validActivity['activity_name'],
                    'is_anonymous'    => $isAnonymous,
                    'sentiment'       => $sentimentPred,
                    'submitted_at'    => date('d M Y H:i:s'),
                ];
            } catch (Throwable $txErr) {
                $pdo->rollBack();
                $errors[] = 'Gagal menyimpan evaluasi: ' . $txErr->getMessage();
            }
        }
    }
}

$ratingLabels = [
    1 => 'Sangat Kurang',
    2 => 'Kurang',
    3 => 'Cukup',
    4 => 'Baik',
    5 => 'Sangat Baik',
];

$aspectsConfig = [
    'rating_materi'      => ['title' => '1. Materi Kegiatan', 'desc' => 'Kejelasan, kemanfaatan, dan relevansi materi PPKMB bagi mahasiswa baru.', 'icon' => 'bi-journal-text'],
    'rating_pemateri'    => ['title' => '2. Pemateri / Narasumber', 'desc' => 'Penguasaan materi, cara penyampaian, dan interaksi narasumber/moderator.', 'icon' => 'bi-person-video3'],
    'rating_panitia'     => ['title' => '3. Sikap & Koordinasi Panitia', 'desc' => 'Keramahan, kesigapan membantu, komunikasi, dan ketertiban panitia.', 'icon' => 'bi-people'],
    'rating_fasilitas'   => ['title' => '4. Fasilitas & Sarana Ruangan', 'desc' => 'Kenyamanan ruangan/aula, tata suara (sound system), proyektor, AC, dan toilet.', 'icon' => 'bi-building-check'],
    'rating_konsumsi'    => ['title' => '5. Konsumsi & Air Minum', 'desc' => 'Kelayakan menu makanan/snack, kebersihan, dan ketepatan distribusi konsumsi.', 'icon' => 'bi-cup-hot'],
    'rating_jadwal'      => ['title' => '6. Ketepatan Jadwal & Waktu Istirahat', 'desc' => 'Kesesuaian acara dengan rundown serta kecukupan waktu istirahat/sholat.', 'icon' => 'bi-clock-history'],
    'rating_keseluruhan' => ['title' => '7. Pelaksanaan Keseluruhan PPKMB', 'desc' => 'Penilaian umum Anda terhadap kualitas penyelenggaraan sesi kegiatan ini.', 'icon' => 'bi-award'],
];

$pageTitle = 'Formulir Evaluasi Kegiatan PPKMB';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-9">

            <?php if ($submissionSuccess): ?>
                <!-- Notifikasi & Kartu Bukti Pengiriman Evaluasi Berhasil -->
                <div class="card-modern border-0 shadow-sm p-4 p-md-5 mb-4" style="border-top: 5px solid #16a34a !important;">
                    <div class="text-center mb-4">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success bg-opacity-10 text-success mb-3" style="width: 68px; height: 68px;">
                            <i class="bi bi-check2-circle fs-1"></i>
                        </div>
                        <h3 class="fw-bold text-dark mb-1">Terima Kasih! Evaluasi Anda Berhasil Dikirim</h3>
                        <p class="text-secondary mb-0">
                            Masukan Anda telah tersimpan secara aman dan dianalisis secara otomatis tanpa menampilkan identitas pribadi Anda di dashboard.
                        </p>
                    </div>

                    <div class="row g-3 bg-light rounded-3 p-3 border mb-4">
                        <div class="col-md-4">
                            <div class="small text-muted">Kode Bukti Evaluasi</div>
                            <div class="fs-5 fw-bold text-primary"><code><?= e($submissionSuccess['evaluation_code']) ?></code></div>
                        </div>
                        <div class="col-md-5">
                            <div class="small text-muted">Sesi Kegiatan PPKMB</div>
                            <div class="fw-semibold text-dark small"><?= e($submissionSuccess['activity_name']) ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="small text-muted">Status Privasi</div>
                            <div>
                                <span class="badge bg-success-subtle text-success border border-success-subtle">
                                    <i class="bi bi-shield-lock me-1"></i>Anonim Terlindungi
                                </span>
                            </div>
                        </div>
                    </div>

                    <?php
                    $sPred = $submissionSuccess['sentiment'];
                    $sLbl = $sPred['label'] ?? 'netral';
                    $badgeCls = $sLbl === 'positif' ? 'badge-positif' : ($sLbl === 'negatif' ? 'badge-negatif' : 'badge-netral');
                    ?>
                    <div class="p-3 rounded-3 mb-4" style="background-color: #f8fafc; border: 1px dashed #cbd5e1;">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                            <span class="small fw-bold text-secondary">
                                <i class="bi bi-cpu me-1 text-primary"></i>Hasil Analisis Sentimen Otomatis:
                            </span>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge <?= e($badgeCls) ?> px-3 py-1 text-uppercase"><?= e($sLbl) ?></span>
                                <?php if (!empty($sPred['confidence_score'])): ?>
                                    <span class="badge bg-light text-dark border">
                                        Keyakinan: <?= number_format((float)$sPred['confidence_score'] * 100, 1) ?>%
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="small text-muted">
                            <?= e($sPred['status_message'] ?? '') ?>
                            <?php if (empty($sPred['is_ml']) && ($sPred['status'] ?? '') === 'fallback_rule'): ?>
                                <div class="text-warning-emphasis fw-semibold mt-1">
                                    <i class="bi bi-exclamation-circle me-1"></i>Catatan: Menggunakan mode fallback pencocokan kata sederhana karena model ML belum dimuat.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap justify-content-center gap-3">
                        <a href="<?= e(base_url('student/evaluation-form.php')) ?>" class="btn btn-navy px-4">
                            <i class="bi bi-plus-circle me-1"></i>Isi Evaluasi Sesi Lainnya
                        </a>
                        <a href="<?= e(base_url('student/evaluation-history.php')) ?>" class="btn btn-outline-primary px-4">
                            <i class="bi bi-bar-chart-line me-1"></i>Lihat Ringkasan Hasil Evaluasi Umum
                        </a>
                    </div>
                </div>
            <?php else: ?>

                <!-- Header Formulir -->
                <div class="card-modern border-0 shadow-sm mb-4 overflow-hidden">
                    <div class="p-4 text-white" style="background: linear-gradient(135deg, #071733 0%, #0d254c 100%); border-bottom: 3px solid #38bdf8;">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <div>
                                <span class="badge bg-info text-dark mb-2">Formulir Resmi Mahasiswa Baru</span>
                                <h4 class="fw-bold mb-1">Evaluasi Kegiatan PPKMB</h4>
                                <p class="small text-white-50 mb-0">
                                    Berikan penilaian objektif serta sampaikan pengalaman, kritik, dan saran Anda untuk kemajuan PPKMB.
                                </p>
                            </div>
                            <div class="text-md-end">
                                <span class="badge bg-white bg-opacity-10 text-white border border-light border-opacity-25 p-2">
                                    <i class="bi bi-incognito text-info me-1"></i>Identitas Tidak Ditampilkan di Dashboard
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="card-body p-4">
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger border-0 shadow-sm">
                                <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Mohon periksa kembali isian Anda:</div>
                                <ul class="mb-0 small">
                                    <?php foreach ($errors as $err): ?>
                                        <li><?= e($err) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <form id="evaluationForm" method="post" action="<?= e(base_url('student/evaluation-form.php')) ?>"
                              data-loading="Menyimpan evaluasi &amp; menjalankan analisis sentimen Bahasa Indonesia...">
                            <?= csrf_field() ?>

                            <!-- Bagian A: Pengaturan Privasi & Konteks Kegiatan -->
                            <h6 class="fw-bold text-uppercase text-primary mb-3 pb-2 border-bottom">
                                <i class="bi bi-sliders me-1"></i>A. Informasi Sesi &amp; Preferensi Privasi
                            </h6>

                            <div class="p-3 rounded-3 mb-4" style="background-color: #f0f9ff; border: 1px solid #bae6fd;">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="is_anonymous" name="is_anonymous" value="1"
                                           <?= (!isset($_POST['is_anonymous']) || $_POST['is_anonymous'] === '1' || !$currUser) ? 'checked' : '' ?>
                                           <?= !$currUser ? ' disabled' : '' ?>>
                                    <?php if (!$currUser): ?>
                                        <input type="hidden" name="is_anonymous" value="1">
                                    <?php endif; ?>
                                    <label class="form-check-label fw-bold text-dark" for="is_anonymous">
                                        Aktifkan Mode Evaluasi Anonim Penuh
                                    </label>
                                </div>
                                <div class="small text-secondary mt-1">
                                    Dalam mode anonim, Anda <strong>tidak wajib</strong> mengisi Fakultas/Program Studi dan sistem tidak menyimpan kaitan akun sama sekali. Sistem juga otomatis menyamarkan NIM, nomor telepon, atau alamat email jika tidak sengaja tertulis di kolom komentar.
                                </div>
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label for="period_id" class="form-label fw-semibold small">Periode / Tahun Pelaksanaan PPKMB <span class="text-danger">*</span></label>
                                    <select class="form-select" id="period_id" name="period_id" required>
                                        <?php foreach ($periods as $p): ?>
                                            <?php $sel = ((int)($_POST['period_id'] ?? $defaultPeriodId) === (int)$p['id']) ? 'selected' : ''; ?>
                                            <option value="<?= (int)$p['id'] ?>" <?= $sel ?>>
                                                <?= e($p['academic_year'] . ' - ' . $p['period_name']) ?>
                                                <?= (int)$p['is_active'] === 1 ? ' (Aktif)' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label for="activity_id" class="form-label fw-semibold small">Jenis Kegiatan / Sesi PPKMB <span class="text-danger">*</span></label>
                                    <select class="form-select" id="activity_id" name="activity_id" required>
                                        <option value="">-- Pilih Sesi Kegiatan PPKMB --</option>
                                        <?php foreach ($activities as $act): ?>
                                            <?php $selAct = ((int)($_POST['activity_id'] ?? 0) === (int)$act['id']) ? 'selected' : ''; ?>
                                            <option value="<?= (int)$act['id'] ?>" data-period="<?= (int)$act['period_id'] ?>" <?= $selAct ?>>
                                                [<?= e($act['category']) ?>] <?= e($act['activity_name']) ?> (<?= e($act['activity_date']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label for="faculty" class="form-label fw-semibold small">Fakultas <span class="text-muted fw-normal">(Opsional pada Mode Anonim)</span></label>
                                    <select class="form-select" id="faculty" name="faculty">
                                        <?php
                                        $defaultFac = $_POST['faculty'] ?? ($currUser['faculty'] ?? 'Umum / Tidak Disebutkan (Anonim)');
                                        foreach ($facultiesList as $facOpt):
                                            $selF = ($defaultFac === $facOpt) ? 'selected' : '';
                                        ?>
                                            <option value="<?= e($facOpt) ?>" <?= $selF ?>><?= e($facOpt) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label for="study_program" class="form-label fw-semibold small">Program Studi <span class="text-muted fw-normal">(Opsional pada Mode Anonim)</span></label>
                                    <input type="text" class="form-control" id="study_program" name="study_program"
                                           value="<?= e($_POST['study_program'] ?? ($currUser['study_program'] ?? '')) ?>"
                                           placeholder="Contoh: Teknik Informatika (Kosongkan jika ingin anonim)">
                                </div>
                            </div>

                            <!-- Bagian B: Penilaian Kuantitatif Skala 1 - 5 -->
                            <h6 class="fw-bold text-uppercase text-primary mb-2 pb-2 border-bottom">
                                <i class="bi bi-star-half me-1"></i>B. Penilaian Aspek Kegiatan (Skala 1 &ndash; 5)
                            </h6>
                            <p class="small text-secondary mb-3">
                                Keterangan Skala: <strong>1</strong> = Sangat Kurang, <strong>2</strong> = Kurang, <strong>3</strong> = Cukup, <strong>4</strong> = Baik, <strong>5</strong> = Sangat Baik.
                                <br><em class="text-muted">Catatan: Rating angka dan sentimen komentar teks dianalisis sebagai dua indikator terpisah.</em>
                            </p>

                            <div class="row g-3 mb-4">
                                <?php foreach ($aspectsConfig as $fieldKey => $cfg): ?>
                                    <?php $selectedVal = (int)($_POST[$fieldKey] ?? 0); ?>
                                    <div class="col-12">
                                        <div class="p-3 rounded-3 border bg-white d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                                            <div>
                                                <div class="fw-bold text-dark">
                                                    <i class="bi <?= e($cfg['icon']) ?> text-primary me-1"></i><?= e($cfg['title']) ?> <span class="text-danger">*</span>
                                                </div>
                                                <div class="small text-secondary"><?= e($cfg['desc']) ?></div>
                                            </div>
                                            <div class="rating-group flex-shrink-0">
                                                <?php for ($score = 1; $score <= 5; $score++): ?>
                                                    <div class="rating-option">
                                                        <input type="radio" id="<?= e($fieldKey . '_' . $score) ?>"
                                                               name="<?= e($fieldKey) ?>" value="<?= $score ?>"
                                                               <?= $selectedVal === $score ? 'checked' : '' ?> required>
                                                        <label for="<?= e($fieldKey . '_' . $score) ?>" title="<?= e($ratingLabels[$score]) ?>">
                                                            <span class="rate-num"><?= $score ?></span>
                                                            <span class="rate-lbl"><?= $score === 1 ? 'Kurang' : ($score === 3 ? 'Cukup' : ($score === 5 ? 'Unggul' : '')) ?></span>
                                                        </label>
                                                    </div>
                                                <?php endfor; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Bagian C: Komentar Pengalaman, Kritik, dan Saran (Analisis Sentimen NLP) -->
                            <h6 class="fw-bold text-uppercase text-primary mb-3 pb-2 border-bottom">
                                <i class="bi bi-chat-left-quote me-1"></i>C. Komentar Pengalaman, Kritik &amp; Saran Mahasiswa
                            </h6>

                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label for="comment_experience" class="form-label fw-semibold small mb-0">
                                        Komentar atau Pengalaman Selama Mengikuti Sesi PPKMB <span class="text-danger">*</span>
                                    </label>
                                    <span id="expCounter" class="badge bg-light text-secondary border">0 karakter (0 kata)</span>
                                </div>
                                <textarea class="form-control" id="comment_experience" name="comment_experience" rows="4"
                                          data-char-counter="expCounter" maxlength="2000" required
                                          placeholder="Tuliskan pengalaman jujur Anda mengenai materi, pemateri, sikap panitia, fasilitas, konsumsi, atau ketepatan jadwal..."><?= e($_POST['comment_experience'] ?? '') ?></textarea>
                                <div class="form-text small">
                                    Gunakan Bahasa Indonesia (minimal 3 kata). Demi privasi, jangan mencantumkan nama lengkap, NIM, atau nomor WA di dalam komentar.
                                </div>
                            </div>

                            <div class="mb-4">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label for="comment_critique" class="form-label fw-semibold small mb-0">
                                        Kritik Konstruktif &amp; Saran Perbaikan untuk PPKMB Tahun Berikutnya <span class="text-muted fw-normal">(Opsional namun Sangat Dianjurkan)</span>
                                    </label>
                                    <span id="critCounter" class="badge bg-light text-secondary border">0 karakter (0 kata)</span>
                                </div>
                                <textarea class="form-control" id="comment_critique" name="comment_critique" rows="3"
                                          data-char-counter="critCounter" maxlength="2000"
                                          placeholder="Contoh: Mohon durasi istirahat sholat ditambah dan tata suara di barisan belakang diperbaiki..."><?= e($_POST['comment_critique'] ?? '') ?></textarea>
                            </div>

                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 pt-3 border-top">
                                <div class="small text-secondary">
                                    <i class="bi bi-shield-fill-check text-success me-1"></i>Dilindungi enkripsi sesi, CSRF token, dan penyamaran identitas otomatis.
                                </div>
                                <button type="button" class="btn btn-navy btn-lg px-4" id="btnOpenConfirmModal">
                                    <i class="bi bi-send-check me-2"></i>Kirim Evaluasi
                                </button>
                            </div>

                            <!-- Modal Konfirmasi Pengiriman -->
                            <div class="modal fade" id="confirmSubmitModal" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content border-0 shadow">
                                        <div class="modal-header text-white" style="background-color: #0d254c;">
                                            <h6 class="modal-title fw-bold"><i class="bi bi-question-circle me-2 text-info"></i>Konfirmasi Pengiriman Evaluasi</h6>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body p-4">
                                            <p class="mb-2 fw-semibold text-dark">Apakah Anda yakin data penilaian dan komentar yang Anda isi sudah sesuai?</p>
                                            <ul class="small text-secondary mb-0">
                                                <li>Komentar Anda akan dianalisis secara otomatis oleh modul Analisis Sentimen Bahasa Indonesia.</li>
                                                <li>Identitas pribadi Anda <strong>tidak akan pernah ditampilkan</strong> pada dashboard admin maupun laporan evaluasi.</li>
                                            </ul>
                                        </div>
                                        <div class="modal-footer bg-light">
                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Periksa Kembali</button>
                                            <button type="submit" class="btn btn-navy px-4">
                                                <i class="bi bi-check2-circle me-1"></i>Ya, Kirim Sekarang
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('evaluationForm');
    const btnOpen = document.getElementById('btnOpenConfirmModal');
    const periodSelect = document.getElementById('period_id');
    const activitySelect = document.getElementById('activity_id');

    // Filter dropdown sesi kegiatan sesuai periode yang dipilih
    if (periodSelect && activitySelect) {
        const filterActivities = function () {
            const selectedPeriod = periodSelect.value;
            Array.from(activitySelect.options).forEach(function (opt) {
                if (!opt.value) return;
                const optPeriod = opt.getAttribute('data-period');
                opt.hidden = (optPeriod && optPeriod !== selectedPeriod);
            });
            const currentOpt = activitySelect.options[activitySelect.selectedIndex];
            if (currentOpt && currentOpt.hidden) {
                activitySelect.value = '';
            }
        };
        periodSelect.addEventListener('change', filterActivities);
        filterActivities();
    }

    // Validasi form sebelum menampilkan Modal Konfirmasi
    if (btnOpen && form) {
        btnOpen.addEventListener('click', function () {
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            const modalEl = document.getElementById('confirmSubmitModal');
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
