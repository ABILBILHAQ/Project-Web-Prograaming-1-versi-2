<?php
/**
 * Halaman Laporan Evaluasi PPKMB, Rekomendasi Perbaikan & Ekspor Data (admin/reports.php)
 * Fitur:
 * - Ringkasan pelaksanaan PPKMB & Statistik partisipasi evaluasi.
 * - Distribusi sentimen & Hasil evaluasi setiap aspek (Materi, Pemateri, Panitia, Fasilitas, Konsumsi, Jadwal, Keseluruhan).
 * - Ekstraksi topik yang sering mendapat apresiasi & topik yang sering dikeluhkan dari data aktual.
 * - Rekomendasi perbaikan otomatis berbasis temuan data (ditandai sebagai temuan sementara yang perlu diverifikasi manusia).
 * - Perbandingan hasil antarperiode PPKMB.
 * - Ekspor CSV, Ekspor Excel (.xls SpreadsheetML), Cetak Laporan (Print-ready), & Filter Rentang Tanggal.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role(['admin', 'panitia']);

$periods = $pdo->query("SELECT * FROM periods ORDER BY id DESC")->fetchAll();
$defaultPeriodId = !empty($periods) ? (int)$periods[0]['id'] : 0;

$periodId  = (int)($_GET['period_id'] ?? $defaultPeriodId);
$dateFrom  = trim((string)($_GET['date_from'] ?? ''));
$dateTo    = trim((string)($_GET['date_to'] ?? ''));
$dummyMode = trim((string)($_GET['dummy_mode'] ?? 'all'));
$exportFmt = trim((string)($_GET['export'] ?? ''));

$where = ["1=1"];
$params = [];

if ($periodId > 0) {
    $where[] = "e.period_id = :pid";
    $params[':pid'] = $periodId;
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

// Query detail untuk ekspor CSV / Excel maupun analisis topik
$detailSql = "
    SELECT
        e.id, e.evaluation_code, e.submitted_at, p.academic_year, p.period_name,
        a.activity_code, a.activity_name, a.category,
        e.faculty, e.study_program,
        e.rating_materi, e.rating_pemateri, e.rating_panitia, e.rating_fasilitas,
        e.rating_konsumsi, e.rating_jadwal, e.rating_keseluruhan, e.average_rating,
        e.comment_experience, e.comment_critique, e.is_dummy,
        s.sentiment_label, s.confidence_score, s.analysis_status, s.is_ml, s.model_version
    FROM evaluations e
    JOIN periods p ON p.id = e.period_id
    JOIN activities a ON a.id = e.activity_id
    LEFT JOIN sentiment_results s ON s.evaluation_id = e.id
    WHERE {$whereSql}
    ORDER BY e.submitted_at DESC, e.id DESC
";
$stmtDet = $pdo->prepare($detailSql);
$stmtDet->execute($params);
$allRows = $stmtDet->fetchAll();

// Tangani Ekspor CSV
if ($exportFmt === 'csv') {
    write_audit_log($pdo, 'EXPORT_CSV', 'reports', (string)$periodId, 'Mengekspor laporan evaluasi ke format CSV.');
    $filename = 'Laporan_Evaluasi_PPKMB_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM agar karakter Indonesia terbaca rapi di Microsoft Excel Windows
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'ID', 'Kode Evaluasi', 'Tanggal Pengisian', 'Tahun Akademik', 'Nama Periode',
        'Kode Sesi', 'Nama Sesi Kegiatan', 'Kategori Kegiatan', 'Fakultas', 'Program Studi',
        'Rating Materi', 'Rating Pemateri', 'Rating Panitia', 'Rating Fasilitas',
        'Rating Konsumsi', 'Rating Jadwal', 'Rating Keseluruhan', 'Rata-rata 7 Aspek',
        'Label Sentimen', 'Skor Keyakinan', 'Status Analisis', 'Versi Model',
        'Komentar Pengalaman', 'Kritik dan Saran', 'Penanda Data'
    ]);
    foreach ($allRows as $r) {
        fputcsv($out, [
            $r['id'],
            $r['evaluation_code'],
            $r['submitted_at'],
            $r['academic_year'],
            $r['period_name'],
            $r['activity_code'],
            $r['activity_name'],
            $r['category'],
            $r['faculty'],
            $r['study_program'],
            $r['rating_materi'],
            $r['rating_pemateri'],
            $r['rating_panitia'],
            $r['rating_fasilitas'],
            $r['rating_konsumsi'],
            $r['rating_jadwal'],
            $r['rating_keseluruhan'],
            $r['average_rating'],
            strtoupper((string)($r['sentiment_label'] ?? 'NETRAL')),
            $r['confidence_score'],
            $r['analysis_status'],
            $r['model_version'],
            $r['comment_experience'],
            $r['comment_critique'],
            (int)$r['is_dummy'] === 1 ? 'DATA DUMMY PENGUJIAN' : 'DATA EVALUASI NYATA',
        ]);
    }
    fclose($out);
    exit;
}

// Tangani Ekspor Excel (.xls Spreadsheet HTML/XML kompatibel Microsoft Excel)
if ($exportFmt === 'excel') {
    write_audit_log($pdo, 'EXPORT_EXCEL', 'reports', (string)$periodId, 'Mengekspor laporan evaluasi ke format Excel.');
    $filename = 'Laporan_Evaluasi_PPKMB_' . date('Ymd_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    ?>
    <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
    <head><meta charset="UTF-8"></head>
    <body>
        <h3>LAPORAN EVALUASI KEGIATAN MAHASISWA TERHADAP PPKMB BERBASIS ANALISIS SENTIMEN</h3>
        <p>Tanggal Ekspor: <?= e(date('d/m/Y H:i:s')) ?> | Total Data: <?= count($allRows) ?></p>
        <table border="1" cellpadding="5" cellspacing="0">
            <thead style="background-color:#0d254c;color:#ffffff;font-weight:bold;">
                <tr>
                    <th>ID</th><th>Kode Evaluasi</th><th>Tanggal</th><th>Tahun Akademik</th><th>Kategori Sesi</th>
                    <th>Nama Kegiatan</th><th>Fakultas</th><th>Program Studi</th>
                    <th>Materi</th><th>Pemateri</th><th>Panitia</th><th>Fasilitas</th><th>Konsumsi</th><th>Jadwal</th><th>Keseluruhan</th><th>Rata-rata</th>
                    <th>Sentimen</th><th>Keyakinan (%)</th><th>Status Analisis</th>
                    <th>Komentar Pengalaman</th><th>Kritik &amp; Saran</th><th>Jenis Data</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allRows as $r): ?>
                    <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td><?= e($r['evaluation_code']) ?></td>
                        <td><?= e($r['submitted_at']) ?></td>
                        <td><?= e($r['academic_year']) ?></td>
                        <td><?= e($r['category']) ?></td>
                        <td><?= e($r['activity_name']) ?></td>
                        <td><?= e($r['faculty']) ?></td>
                        <td><?= e($r['study_program']) ?></td>
                        <td><?= (int)$r['rating_materi'] ?></td>
                        <td><?= (int)$r['rating_pemateri'] ?></td>
                        <td><?= (int)$r['rating_panitia'] ?></td>
                        <td><?= (int)$r['rating_fasilitas'] ?></td>
                        <td><?= (int)$r['rating_konsumsi'] ?></td>
                        <td><?= (int)$r['rating_jadwal'] ?></td>
                        <td><?= (int)$r['rating_keseluruhan'] ?></td>
                        <td><?= e($r['average_rating']) ?></td>
                        <td><?= e(strtoupper((string)$r['sentiment_label'])) ?></td>
                        <td><?= number_format((float)$r['confidence_score'] * 100, 1) ?>%</td>
                        <td><?= e($r['analysis_status']) ?></td>
                        <td><?= e($r['comment_experience']) ?></td>
                        <td><?= e($r['comment_critique']) ?></td>
                        <td><?= (int)$r['is_dummy'] === 1 ? 'DUMMY PENGUJIAN' : 'DATA NYATA' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

// Ambil informasi periode yang dipilih
$selectedPeriod = null;
foreach ($periods as $p) {
    if ((int)$p['id'] === $periodId) {
        $selectedPeriod = $p;
        break;
    }
}

// Hitung statistik agregat dari $allRows
$totalResp = count($allRows);
$posCount = 0;
$negCount = 0;
$neuCount = 0;
$sumAspects = [
    'materi'      => 0,
    'pemateri'    => 0,
    'panitia'     => 0,
    'fasilitas'   => 0,
    'konsumsi'    => 0,
    'jadwal'      => 0,
    'keseluruhan' => 0,
];

// Kamus ekstraksi topik terstruktur berbasis kata kunci domain PPKMB
$topicKeywords = [
    'Materi & Kurikulum SIAKAD' => ['materi', 'siakad', 'krs', 'kurikulum', 'akademik', 'panduan', 'beasiswa', 'sks'],
    'Kualitas Pemateri & Narasumber' => ['pemateri', 'narasumber', 'pembicara', 'interaktif', 'komunikatif', 'moderator', 'talkshow', 'slide'],
    'Keramahan & Koordinasi Panitia' => ['panitia', 'ramah', 'sabar', 'pendamping', 'gugus', 'sigap', 'mentor', 'membentak', 'kompak', 'atribut'],
    'Fasilitas Aula, AC & Tata Suara' => ['fasilitas', 'aula', 'ruangan', 'ac', 'panas', 'pengap', 'mikrofon', 'sound', 'proyektor', 'toilet', 'wifi', 'internet', 'tenda'],
    'Kualitas & Distribusi Konsumsi' => ['konsumsi', 'makan', 'nasi', 'snack', 'minum', 'air', 'porsi', 'dingin', 'basi', 'higienis', 'alergi'],
    'Ketepatan Jadwal & Waktu Istirahat' => ['jadwal', 'waktu', 'istirahat', 'ishoma', 'sholat', 'molor', 'terlambat', 'padat', 'rundown', 'tepat'],
];

$appreciatedTopics = array_fill_keys(array_keys($topicKeywords), 0);
$complainedTopics  = array_fill_keys(array_keys($topicKeywords), 0);

foreach ($allRows as $r) {
    $lbl = $r['sentiment_label'] ?? 'netral';
    if ($lbl === 'positif') $posCount++;
    elseif ($lbl === 'negatif') $negCount++;
    else $neuCount++;

    $sumAspects['materi']      += (int)$r['rating_materi'];
    $sumAspects['pemateri']    += (int)$r['rating_pemateri'];
    $sumAspects['panitia']     += (int)$r['rating_panitia'];
    $sumAspects['fasilitas']   += (int)$r['rating_fasilitas'];
    $sumAspects['konsumsi']    += (int)$r['rating_konsumsi'];
    $sumAspects['jadwal']      += (int)$r['rating_jadwal'];
    $sumAspects['keseluruhan'] += (int)$r['rating_keseluruhan'];

    $combinedLower = strtolower($r['comment_experience'] . ' ' . $r['comment_critique']);
    foreach ($topicKeywords as $tName => $kwList) {
        foreach ($kwList as $kw) {
            if (str_contains($combinedLower, $kw)) {
                if ($lbl === 'positif') {
                    $appreciatedTopics[$tName]++;
                } elseif ($lbl === 'negatif') {
                    $complainedTopics[$tName]++;
                }
                break;
            }
        }
    }
}

arsort($appreciatedTopics);
arsort($complainedTopics);

$avgAspects = [];
foreach ($sumAspects as $k => $v) {
    $avgAspects[$k] = $totalResp > 0 ? round($v / $totalResp, 2) : 0.0;
}

// Bangun Rekomendasi Perbaikan Otomatis Berbasis Data Aktual
$recommendations = [];
if ($totalResp > 0) {
    if ($avgAspects['jadwal'] < 3.8 || $complainedTopics['Ketepatan Jadwal & Waktu Istirahat'] > 0) {
        $recommendations[] = [
            'aspect' => 'Manajemen Jadwal & Durasi Istirahat',
            'priority' => $avgAspects['jadwal'] < 3.5 ? 'Tinggi' : 'Sedang',
            'finding' => "Skor rata-rata ketepatan jadwal adalah {$avgAspects['jadwal']}/5.00 dengan {$complainedTopics['Ketepatan Jadwal & Waktu Istirahat']} penyebutan pada komentar negatif.",
            'action' => 'Evaluasi kembali kepadatan rundown acara, pastikan jeda ISHOMA minimal 45–60 menit, dan kunci pengumuman penugasan maksimal pukul 20.00 WIB.',
        ];
    }
    if ($avgAspects['fasilitas'] < 3.8 || $complainedTopics['Fasilitas Aula, AC & Tata Suara'] > 0) {
        $recommendations[] = [
            'aspect' => 'Fasilitas Gedung, Tata Suara & Pendingin Ruangan',
            'priority' => $avgAspects['fasilitas'] < 3.5 ? 'Tinggi' : 'Sedang',
            'finding' => "Skor rata-rata fasilitas adalah {$avgAspects['fasilitas']}/5.00 dengan {$complainedTopics['Fasilitas Aula, AC & Tata Suara']} keluhan terkait tata suara/suhu ruangan/WiFi.",
            'action' => 'Lakukan uji fungsi mikrofon/repeater audio di barisan belakang H-1, siapkan kipas tambahan/ventilasi pada area tenda outdoor, serta tingkatkan kapasitas AP WiFi saat sesi presensi.',
        ];
    }
    if ($avgAspects['konsumsi'] < 3.8 || $complainedTopics['Kualitas & Distribusi Konsumsi'] > 0) {
        $recommendations[] = [
            'aspect' => 'Distribusi Konsumsi & Ketersediaan Air Minum',
            'priority' => $avgAspects['konsumsi'] < 3.5 ? 'Tinggi' : 'Sedang',
            'finding' => "Skor rata-rata konsumsi adalah {$avgAspects['konsumsi']}/5.00 dengan {$complainedTopics['Kualitas & Distribusi Konsumsi']} masukan terkait keterlambatan atau suhu makanan.",
            'action' => 'Perbanyak titik distribusi konsumsi per gugus agar antrean tidak menumpuk dan sediakan dispenser air minum isi ulang tambahan saat sesi lapangan.',
        ];
    }
    if ($avgAspects['panitia'] >= 4.0 || $appreciatedTopics['Keramahan & Koordinasi Panitia'] > 0) {
        $recommendations[] = [
            'aspect' => 'Penguatan Standar Pendampingan Panitia Humanis',
            'priority' => 'Pemeliharaan & Standardisasi',
            'finding' => "Skor panitia mencapai {$avgAspects['panitia']}/5.00 dan mendapat {$appreciatedTopics['Keramahan & Koordinasi Panitia']} apresiasi positif, namun tetap perlu penyelarasan komunikasi antar-divisi.",
            'action' => 'Pertahankan pendekatan PPKMB humanis bebas perpeloncoan serta selenggarakan briefing satu pintu antara panitia inti dan pendamping gugus.',
        ];
    }
}

// Query Perbandingan Antarperiode PPKMB
$periodComparison = $pdo->query(
    "SELECT
        p.id, p.academic_year, p.period_name, p.target_participants,
        COUNT(e.id) AS total_eval,
        ROUND(AVG(e.rating_materi), 2) AS avg_materi,
        ROUND(AVG(e.rating_pemateri), 2) AS avg_pemateri,
        ROUND(AVG(e.rating_panitia), 2) AS avg_panitia,
        ROUND(AVG(e.rating_fasilitas), 2) AS avg_fasilitas,
        ROUND(AVG(e.rating_konsumsi), 2) AS avg_konsumsi,
        ROUND(AVG(e.rating_jadwal), 2) AS avg_jadwal,
        ROUND(AVG(e.rating_keseluruhan), 2) AS avg_keseluruhan,
        SUM(CASE WHEN s.sentiment_label = 'positif' THEN 1 ELSE 0 END) AS pos_cnt,
        SUM(CASE WHEN s.sentiment_label = 'negatif' THEN 1 ELSE 0 END) AS neg_cnt,
        SUM(CASE WHEN s.sentiment_label = 'netral'  THEN 1 ELSE 0 END) AS neu_cnt
     FROM periods p
     LEFT JOIN evaluations e ON e.period_id = p.id
     LEFT JOIN sentiment_results s ON s.evaluation_id = e.id
     GROUP BY p.id, p.academic_year, p.period_name, p.target_participants
     ORDER BY p.id ASC"
)->fetchAll();

$exportQueryBase = http_build_query([
    'period_id'  => $periodId,
    'date_from'  => $dateFrom,
    'date_to'    => $dateTo,
    'dummy_mode' => $dummyMode,
]);

$pageTitle = 'Laporan Evaluasi & Rekomendasi PPKMB';
$showSidebar = true;
$activeMenu = 'reports';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="app-wrapper">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="main-content">
        <!-- Header & Tombol Ekspor/Cetak -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 no-print">
            <div>
                <h4 class="fw-bold mb-1" style="color: #0d254c;">Laporan Evaluasi Pelaksanaan PPKMB</h4>
                <p class="text-secondary small mb-0">
                    Rekapitulasi komprehensif, ekstraksi topik apresiasi &amp; keluhan, rekomendasi perbaikan, serta perbandingan antarperiode.
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= e(base_url('admin/reports.php?' . $exportQueryBase . '&export=csv')) ?>" class="btn btn-sm btn-outline-success">
                    <i class="bi bi-filetype-csv me-1"></i>Ekspor CSV
                </a>
                <a href="<?= e(base_url('admin/reports.php?' . $exportQueryBase . '&export=excel')) ?>" class="btn btn-sm btn-success">
                    <i class="bi bi-file-earmark-excel me-1"></i>Ekspor Excel (.xls)
                </a>
                <button type="button" onclick="window.print()" class="btn btn-sm btn-navy">
                    <i class="bi bi-printer me-1"></i>Cetak Laporan
                </button>
            </div>
        </div>

        <!-- Filter Rentang Tanggal & Periode Laporan -->
        <div class="card-modern p-3 mb-4 no-print">
            <form method="get" action="<?= e(base_url('admin/reports.php')) ?>" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold mb-1">Periode PPKMB</label>
                    <select name="period_id" class="form-select form-select-sm">
                        <option value="0" <?= $periodId === 0 ? 'selected' : '' ?>>Semua Periode Gabungan</option>
                        <?php foreach ($periods as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= $periodId === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= e($p['academic_year'] . ' - ' . $p['period_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold mb-1">Pilih Rentang Tanggal Laporan</label>
                    <div class="input-group input-group-sm">
                        <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
                        <span class="input-group-text">s/d</span>
                        <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Mode Data</label>
                    <select name="dummy_mode" class="form-select form-select-sm">
                        <option value="all" <?= $dummyMode === 'all' ? 'selected' : '' ?>>Semua Data</option>
                        <option value="real_only" <?= $dummyMode === 'real_only' ? 'selected' : '' ?>>Hanya Data Nyata</option>
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-sm btn-navy">Tampilkan Laporan</button>
                </div>
            </form>
        </div>

        <!-- Dokumen Laporan Resmi -->
        <div class="card-modern p-4 p-md-5 mb-4">
            <div class="border-bottom pb-3 mb-4 d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <div class="text-uppercase small fw-bold text-primary">Laporan Resmi Evaluasi Kegiatan Kemahasiswaan</div>
                    <h3 class="fw-bold mb-1" style="color: #0d254c;">
                        <?= $selectedPeriod ? e($selectedPeriod['period_name']) : 'Laporan Gabungan Seluruh Periode PPKMB' ?>
                    </h3>
                    <div class="small text-secondary">
                        Tema: <em><?= e($selectedPeriod['theme'] ?? 'Evaluasi Penyelenggaraan PPKMB') ?></em>
                    </div>
                </div>
                <div class="text-md-end small text-muted">
                    <div><strong>Tanggal Cetak:</strong> <?= e(date('d F Y H:i')) ?> WIB</div>
                    <div><strong>Rentang Filter:</strong> <?= ($dateFrom || $dateTo) ? e(($dateFrom ?: 'Awal') . ' s/d ' . ($dateTo ?: 'Akhir')) : 'Seluruh Tanggal Periode' ?></div>
                </div>
            </div>

            <!-- 1. Ringkasan Pelaksanaan & Statistik Partisipasi -->
            <h6 class="fw-bold text-uppercase text-primary mb-3">1. Ringkasan Pelaksanaan &amp; Statistik Partisipasi Evaluasi</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="p-3 border rounded-3 bg-light">
                        <div class="small text-muted">Total Evaluasi Masuk</div>
                        <div class="fs-4 fw-bold text-dark"><?= $totalResp ?> Responden</div>
                        <?php if ($selectedPeriod): ?>
                            <div class="small text-secondary">Target Peserta: <?= (int)$selectedPeriod['target_participants'] ?> Maba</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 border rounded-3 bg-success-subtle border-success-subtle">
                        <div class="small text-success-emphasis">Sentimen Positif</div>
                        <div class="fs-4 fw-bold text-success">
                            <?= $posCount ?> (<?= $totalResp > 0 ? round(($posCount / $totalResp) * 100, 1) : 0 ?>%)
                        </div>
                        <div class="small text-muted">Apresiasi &amp; Kepuasan</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 border rounded-3 bg-danger-subtle border-danger-subtle">
                        <div class="small text-danger-emphasis">Sentimen Negatif</div>
                        <div class="fs-4 fw-bold text-danger">
                            <?= $negCount ?> (<?= $totalResp > 0 ? round(($negCount / $totalResp) * 100, 1) : 0 ?>%)
                        </div>
                        <div class="small text-muted">Keluhan &amp; Kritik Perbaikan</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 border rounded-3 bg-light">
                        <div class="small text-secondary">Sentimen Netral</div>
                        <div class="fs-4 fw-bold text-secondary">
                            <?= $neuCount ?> (<?= $totalResp > 0 ? round(($neuCount / $totalResp) * 100, 1) : 0 ?>%)
                        </div>
                        <div class="small text-muted">Komentar Informatif</div>
                    </div>
                </div>
            </div>

            <!-- 2. Hasil Evaluasi Setiap Aspek PPKMB -->
            <h6 class="fw-bold text-uppercase text-primary mb-3">2. Hasil Evaluasi Kuantitatif Setiap Aspek PPKMB</h6>
            <div class="table-responsive mb-4">
                <table class="table table-bordered align-middle small">
                    <thead class="table-light">
                        <tr>
                            <th>Indikator Aspek Evaluasi</th>
                            <th class="text-center">Rata-rata Skor (Skala 1&ndash;5)</th>
                            <th class="text-center">Persentase Indeks</th>
                            <th class="text-center">Predikat Mutu</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $aspectNames = [
                            'materi'      => 'Materi Kegiatan PPKMB',
                            'pemateri'    => 'Pemateri / Narasumber',
                            'panitia'     => 'Sikap & Koordinasi Panitia',
                            'fasilitas'   => 'Fasilitas & Sarana Ruangan',
                            'konsumsi'    => 'Kualitas & Distribusi Konsumsi',
                            'jadwal'      => 'Ketepatan Jadwal & Waktu Istirahat',
                            'keseluruhan' => 'Pelaksanaan Kegiatan Secara Keseluruhan',
                        ];
                        foreach ($aspectNames as $aKey => $aLabel):
                            $sc = $avgAspects[$aKey];
                            $pct = round(($sc / 5.0) * 100, 1);
                            if ($sc >= 4.2) {
                                $predikat = '<span class="badge bg-success">Sangat Baik</span>';
                            } elseif ($sc >= 3.5) {
                                $predikat = '<span class="badge bg-primary">Baik</span>';
                            } elseif ($sc >= 2.8) {
                                $predikat = '<span class="badge bg-warning text-dark">Cukup (Perlu Peningkatan)</span>';
                            } else {
                                $predikat = '<span class="badge bg-danger">Perlu Evaluasi Prioritas</span>';
                            }
                        ?>
                            <tr>
                                <td class="fw-semibold"><?= e($aLabel) ?></td>
                                <td class="text-center fw-bold"><?= number_format($sc, 2) ?> / 5.00</td>
                                <td class="text-center"><?= $pct ?>%</td>
                                <td class="text-center"><?= $predikat ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- 3. Topik yang Sering Mendapat Apresiasi & Topik yang Sering Dikeluhkan -->
            <h6 class="fw-bold text-uppercase text-primary mb-2">3. Ekstraksi Topik Apresiasi &amp; Keluhan Mahasiswa</h6>
            <p class="small text-muted mb-3">
                <i class="bi bi-info-circle me-1"></i><em>Catatan Verifikasi:</em> Pengelompokan topik di bawah ini diekstraksi secara otomatis berdasarkan frekuensi kemunculan kata kunci pada komentar positif dan negatif yang tersedia di database, dan disajikan sebagai <strong>temuan sementara yang perlu diverifikasi manusia (panitia/pengelola)</strong>.
            </p>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="p-3 border rounded-3 h-100" style="background-color:#f0fdf4;">
                        <div class="fw-bold text-success mb-2"><i class="bi bi-hand-thumbs-up-fill me-1"></i>Topik yang Sering Mendapat Apresiasi (Sentimen Positif)</div>
                        <ul class="small mb-0">
                            <?php foreach ($appreciatedTopics as $tName => $cnt): ?>
                                <?php if ($cnt > 0): ?>
                                    <li class="mb-1"><strong><?= e($tName) ?>:</strong> ditemukan pada <?= $cnt ?> komentar apresiasi positif.</li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="p-3 border rounded-3 h-100" style="background-color:#fef2f2;">
                        <div class="fw-bold text-danger mb-2"><i class="bi bi-exclamation-Octagon-fill me-1"></i>Topik yang Sering Dikeluhkan (Sentimen Negatif)</div>
                        <ul class="small mb-0">
                            <?php foreach ($complainedTopics as $tName => $cnt): ?>
                                <?php if ($cnt > 0): ?>
                                    <li class="mb-1"><strong><?= e($tName) ?>:</strong> disebutkan pada <?= $cnt ?> masukan/keluhan negatif.</li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- 4. Rekomendasi Perbaikan Berdasarkan Temuan Data -->
            <h6 class="fw-bold text-uppercase text-primary mb-2">4. Rekomendasi Perbaikan Pelaksanaan PPKMB Tahun Berikutnya</h6>
            <div class="alert alert-secondary border small py-2 mb-3">
                <i class="bi bi-person-check-fill me-1"></i>
                <strong>Status Rekomendasi:</strong> Rekomendasi ini disusun otomatis dari korelasi skor aspek terendah dan frekuensi keluhan sentimen pada data aktual. Panitia wajib melakukan peninjauan lapangan sebelum menetapkan kebijakan akhir.
            </div>
            <div class="table-responsive mb-4">
                <table class="table table-bordered align-middle small">
                    <thead class="table-light">
                        <tr>
                            <th>Bidang / Aspek</th>
                            <th class="text-center">Prioritas</th>
                            <th>Temuan Data Aktual</th>
                            <th>Rekomendasi Tindak Lanjut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recommendations)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-3">Belum cukup data untuk menyusun rekomendasi otomatis.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recommendations as $rec): ?>
                                <tr>
                                    <td class="fw-bold"><?= e($rec['aspect']) ?></td>
                                    <td class="text-center">
                                        <span class="badge <?= $rec['priority'] === 'Tinggi' ? 'bg-danger' : ($rec['priority'] === 'Sedang' ? 'bg-warning text-dark' : 'bg-info text-dark') ?>">
                                            <?= e($rec['priority']) ?>
                                        </span>
                                    </td>
                                    <td><?= e($rec['finding']) ?></td>
                                    <td><?= e($rec['action']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- 5. Perbandingan Hasil Antarperiode PPKMB -->
            <h6 class="fw-bold text-uppercase text-primary mb-3">5. Perbandingan Hasil Evaluasi Antarperiode PPKMB</h6>
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle small mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Periode Akademik</th>
                            <th class="text-center">Responden</th>
                            <th class="text-center">Materi</th>
                            <th class="text-center">Pemateri</th>
                            <th class="text-center">Panitia</th>
                            <th class="text-center">Fasilitas</th>
                            <th class="text-center">Konsumsi</th>
                            <th class="text-center">Jadwal</th>
                            <th class="text-center">Keseluruhan</th>
                            <th class="text-center">Sentimen (Pos / Neg / Net)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($periodComparison as $pc): ?>
                            <tr>
                                <td class="fw-bold"><?= e($pc['academic_year'] . ' - ' . $pc['period_name']) ?></td>
                                <td class="text-center fw-semibold"><?= (int)$pc['total_eval'] ?></td>
                                <td class="text-center"><?= number_format((float)$pc['avg_materi'], 2) ?></td>
                                <td class="text-center"><?= number_format((float)$pc['avg_pemateri'], 2) ?></td>
                                <td class="text-center"><?= number_format((float)$pc['avg_panitia'], 2) ?></td>
                                <td class="text-center"><?= number_format((float)$pc['avg_fasilitas'], 2) ?></td>
                                <td class="text-center"><?= number_format((float)$pc['avg_konsumsi'], 2) ?></td>
                                <td class="text-center"><?= number_format((float)$pc['avg_jadwal'], 2) ?></td>
                                <td class="text-center fw-bold text-primary"><?= number_format((float)$pc['avg_keseluruhan'], 2) ?></td>
                                <td class="text-center">
                                    <span class="badge badge-positif"><?= (int)$pc['pos_cnt'] ?></span>
                                    <span class="badge badge-negatif"><?= (int)$pc['neg_cnt'] ?></span>
                                    <span class="badge badge-netral"><?= (int)$pc['neu_cnt'] ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
