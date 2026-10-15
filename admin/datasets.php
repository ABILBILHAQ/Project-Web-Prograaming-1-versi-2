<?php
/**
 * Halaman Pengelolaan Dataset Pelatihan Sentimen (admin/datasets.php)
 * Fitur:
 * - Tambah data berlabel baru (otomatis melewati PII Masking).
 * - Unggah dataset CSV tervalidasi.
 * - Hapus item dataset.
 * - Latih ulang model Machine Learning langsung dari tabel sentiment_datasets.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role(['admin', 'panitia']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $act = (string)($_POST['action'] ?? '');

    if ($act === 'add_sample') {
        $rawText = substr(trim((string)($_POST['text_content'] ?? '')), 0, 1500);
        $label   = trim((string)($_POST['sentiment_label'] ?? ''));
        $aspect  = trim((string)($_POST['aspect_category'] ?? 'keseluruhan'));

        if ($rawText === '' || !in_array($label, ['positif', 'negatif', 'netral'], true)) {
            set_flash('danger', 'Teks komentar dan label sentimen wajib diisi dengan benar.');
        } else {
            $safeText = mask_pii_text($rawText);
            $ins = $pdo->prepare(
                "INSERT INTO sentiment_datasets (text_content, sentiment_label, aspect_category, source, is_verified, created_by)
                 VALUES (:txt, :lbl, :asp, 'manual_admin', 1, :uid)"
            );
            $ins->execute([
                ':txt' => $safeText,
                ':lbl' => $label,
                ':asp' => $aspect,
                ':uid' => $_SESSION['user_id'] ?? null,
            ]);
            write_audit_log($pdo, 'ADD_DATASET', 'sentiment_datasets', (string)$pdo->lastInsertId(), "Menambahkan data latih baru berlabel {$label}.");
            set_flash('success', 'Data latih baru berhasil ditambahkan (PII telah disanitasi).');
        }
        header('Location: ' . base_url('admin/datasets.php'));
        exit;
    }

    if ($act === 'delete_sample') {
        $dsId = (int)($_POST['dataset_id'] ?? 0);
        $del = $pdo->prepare("DELETE FROM sentiment_datasets WHERE id = :id");
        $del->execute([':id' => $dsId]);
        write_audit_log($pdo, 'DELETE_DATASET', 'sentiment_datasets', (string)$dsId, "Menghapus sampel dataset #{$dsId}.");
        set_flash('info', "Data latih #{$dsId} telah dihapus.");
        header('Location: ' . base_url('admin/datasets.php'));
        exit;
    }

    if ($act === 'import_csv' && isset($_FILES['csv_file'])) {
        $file = $_FILES['csv_file'];
        $maxBytes = (int)env_val('MAX_DATASET_UPLOAD_BYTES', '2097152');
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));

        if ($file['error'] !== UPLOAD_ERR_OK) {
            set_flash('danger', 'Gagal mengunggah file CSV.');
        } elseif ($file['size'] > $maxBytes) {
            set_flash('danger', 'Ukuran file CSV melebihi batas maksimum (2 MB).');
        } elseif ($ext !== 'csv') {
            set_flash('danger', 'Format file harus berekstensi .csv.');
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle !== false) {
                $header = fgetcsv($handle);
                $imported = 0;
                $ins = $pdo->prepare(
                    "INSERT INTO sentiment_datasets (text_content, sentiment_label, aspect_category, source, is_verified, created_by)
                     VALUES (:txt, :lbl, :asp, 'csv_import', 1, :uid)"
                );
                while (($row = fgetcsv($handle)) !== false) {
                    if (count($row) < 2) continue;
                    // Jika kolom pertama angka ID, ambil kolom ke-2 sebagai teks
                    if (is_numeric($row[0]) && isset($row[1], $row[2])) {
                        $txt = trim((string)$row[1]);
                        $lbl = strtolower(trim((string)$row[2]));
                        $asp = trim((string)($row[3] ?? 'keseluruhan'));
                    } else {
                        $txt = trim((string)$row[0]);
                        $lbl = strtolower(trim((string)$row[1]));
                        $asp = trim((string)($row[2] ?? 'keseluruhan'));
                    }
                    // Cegah formula injection dan samarkan PII
                    $txt = ltrim($txt, "=+-\t\r");
                    $txt = mask_pii_text($txt);
                    if ($txt !== '' && in_array($lbl, ['positif', 'negatif', 'netral'], true)) {
                        $ins->execute([
                            ':txt' => $txt,
                            ':lbl' => $lbl,
                            ':asp' => $asp ?: 'keseluruhan',
                            ':uid' => $_SESSION['user_id'] ?? null,
                        ]);
                        $imported++;
                    }
                }
                fclose($handle);
                write_audit_log($pdo, 'IMPORT_DATASET_CSV', 'sentiment_datasets', (string)$imported, "Mengimpor {$imported} baris dataset sentimen dari CSV.");
                set_flash('success', "Berhasil mengimpor {$imported} baris dataset berlabel dari file CSV.");
            }
        }
        header('Location: ' . base_url('admin/datasets.php'));
        exit;
    }

    if ($act === 'retrain_model') {
        $dsRows = $pdo->query("SELECT id, text_content, sentiment_label, aspect_category, source FROM sentiment_datasets ORDER BY id ASC")->fetchAll();
        $csvFile = $APP_ROOT . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'dataset_ppkmb_id.csv';
        $fp = fopen($csvFile, 'w');
        fputcsv($fp, ['id', 'text', 'label', 'aspect_category', 'source']);
        foreach ($dsRows as $dr) {
            fputcsv($fp, [$dr['id'], mask_pii_text((string)$dr['text_content']), $dr['sentiment_label'], $dr['aspect_category'], $dr['source']]);
        }
        fclose($fp);

        $pythonBin = env_val('PYTHON_BIN', 'python');
        $trainScript = $APP_ROOT . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'train_model.py';
        $candidates = array_unique([$pythonBin, 'python3', 'python']);
        $trainResult = null;

        foreach ($candidates as $bin) {
            $cmd = escapeshellcmd($bin) . ' ' . escapeshellarg($trainScript) . ' --dataset ' . escapeshellarg($csvFile);
            $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $APP_ROOT . '/python');
            if (is_resource($proc)) {
                fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                if (proc_close($proc) === 0 && !empty($stdout)) {
                    $dec = json_decode(trim($stdout), true);
                    if (is_array($dec) && ($dec['status'] ?? '') === 'success') {
                        $trainResult = $dec;
                        break;
                    }
                }
            }
        }

        if ($trainResult) {
            $m = $trainResult['metrics'];
            $insM = $pdo->prepare(
                "INSERT INTO model_metrics (model_version, evaluation_method, dataset_size, vocabulary_size, accuracy, precision_score, recall_score, f1_score, metrics_detail_json, trained_by, evaluated_at)
                 VALUES (:ver, :meth, :dsize, :vsize, :acc, :prec, :rec, :f1, :json, :uid, NOW())"
            );
            $insM->execute([
                ':ver'   => $trainResult['model_version'],
                ':meth'  => $m['evaluation_method'],
                ':dsize' => (int)$trainResult['training_samples'],
                ':vsize' => (int)$trainResult['vocabulary_size'],
                ':acc'   => (float)$m['accuracy'],
                ':prec'  => (float)$m['precision'],
                ':rec'   => (float)$m['recall'],
                ':f1'    => (float)$m['f1_score'],
                ':json'  => json_encode($m, JSON_UNESCAPED_UNICODE),
                ':uid'   => $_SESSION['user_id'] ?? null,
            ]);
            write_audit_log($pdo, 'RETRAIN_MODEL', 'model_metrics', (string)$pdo->lastInsertId(), "Melatih ulang model {$trainResult['model_version']} (Akurasi: {$m['accuracy']})");
            set_flash('success', "Model berhasil dilatih ulang ({$trainResult['model_version']}) dengan {$trainResult['training_samples']} sampel! Accuracy 5-Fold CV: " . number_format((float)$m['accuracy'] * 100, 2) . "%.");
        } else {
            set_flash('warning', 'File CSV dataset berhasil diperbarui, namun eksekusi Python CLI belum tersedia di PATH host.');
        }
        header('Location: ' . base_url('admin/datasets.php'));
        exit;
    }
}

// Statistik distribusi dataset
$dsCounts = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN sentiment_label = 'positif' THEN 1 ELSE 0 END) AS pos,
        SUM(CASE WHEN sentiment_label = 'negatif' THEN 1 ELSE 0 END) AS neg,
        SUM(CASE WHEN sentiment_label = 'netral'  THEN 1 ELSE 0 END) AS neu
     FROM sentiment_datasets"
)->fetch();

$filterLbl = trim((string)($_GET['label'] ?? ''));
$searchQ   = trim((string)($_GET['q'] ?? ''));
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 15;

$where = ["1=1"];
$params = [];
if (in_array($filterLbl, ['positif', 'negatif', 'netral'], true)) {
    $where[] = "sentiment_label = :lbl";
    $params[':lbl'] = $filterLbl;
}
if ($searchQ !== '') {
    $where[] = "text_content LIKE :q";
    $params[':q'] = '%' . $searchQ . '%';
}
$whereSql = implode(' AND ', $where);

$stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM sentiment_datasets WHERE {$whereSql}");
$stmtCnt->execute($params);
$totalRows = (int)$stmtCnt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$offset = ($page - 1) * $perPage;

$stmtList = $pdo->prepare("SELECT * FROM sentiment_datasets WHERE {$whereSql} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}");
$stmtList->execute($params);
$datasetRows = $stmtList->fetchAll();

$pageTitle = 'Dataset Pelatihan Sentimen';
$showSidebar = true;
$activeMenu = 'datasets';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="app-wrapper">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1" style="color: #0d254c;">Manajemen Dataset Pelatihan Sentimen Bahasa Indonesia</h4>
                <p class="text-secondary small mb-0">
                    Total <?= (int)$dsCounts['total'] ?> sampel berlabel (Positif: <?= (int)$dsCounts['pos'] ?>, Negatif: <?= (int)$dsCounts['neg'] ?>, Netral: <?= (int)$dsCounts['neu'] ?>).
                </p>
            </div>
            <form method="post" data-loading="Melatih ulang model TF-IDF + Klasifikasi dan menghitung Cross-Validation...">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="retrain_model">
                <button type="submit" class="btn btn-navy">
                    <i class="bi bi-cpu-fill me-1"></i>Latih Ulang &amp; Evaluasi Model Sekarang
                </button>
            </form>
        </div>

        <div class="row g-4 mb-4">
            <!-- Form Tambah Sampel Berlabel -->
            <div class="col-lg-7">
                <div class="card-modern h-100 p-4">
                    <h6 class="fw-bold mb-3" style="color: #0d254c;"><i class="bi bi-plus-circle me-2 text-primary"></i>Tambah Kalimat Berlabel Baru</h6>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_sample">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Teks Komentar Bahasa Indonesia (Otomatis Disanitasi PII)</label>
                            <textarea name="text_content" class="form-control form-control-sm" rows="2" required
                                      placeholder="Masukkan contoh kalimat komentar mahasiswa..."></textarea>
                        </div>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label small fw-semibold">Label Sentimen</label>
                                <select name="sentiment_label" class="form-select form-select-sm" required>
                                    <option value="positif">Positif</option>
                                    <option value="negatif">Negatif</option>
                                    <option value="netral">Netral</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Kategori Aspek</label>
                                <select name="aspect_category" class="form-select form-select-sm">
                                    <option value="materi">Materi</option>
                                    <option value="pemateri">Pemateri</option>
                                    <option value="panitia">Panitia</option>
                                    <option value="fasilitas">Fasilitas</option>
                                    <option value="konsumsi">Konsumsi</option>
                                    <option value="jadwal">Jadwal</option>
                                    <option value="keseluruhan">Keseluruhan</option>
                                </select>
                            </div>
                            <div class="col-md-3 d-grid">
                                <button type="submit" class="btn btn-sm btn-sky">Simpan Data</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Form Impor CSV Dataset -->
            <div class="col-lg-5">
                <div class="card-modern h-100 p-4">
                    <h6 class="fw-bold mb-3" style="color: #0d254c;"><i class="bi bi-file-earmark-arrow-up me-2 text-primary"></i>Unggah Dataset CSV</h6>
                    <form method="post" enctype="multipart/form-data" data-loading="Memvalidasi dan mengimpor dataset CSV...">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="import_csv">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Pilih File CSV (Maks. 2 MB)</label>
                            <input type="file" name="csv_file" class="form-control form-control-sm" accept=".csv" required>
                            <div class="form-text small">
                                Format kolom: <code>text,label,aspect_category</code> dengan label <code>positif</code>, <code>negatif</code>, atau <code>netral</code>.
                            </div>
                        </div>
                        <button type="submit" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-upload me-1"></i>Impor CSV Tervalidasi
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Tabel Dataset -->
        <div class="card-modern overflow-hidden">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span>Daftar Sampel Dataset Pelatihan</span>
                <form method="get" class="d-flex gap-2">
                    <select name="label" class="form-select form-select-sm" style="width:140px;">
                        <option value="">Semua Label</option>
                        <option value="positif" <?= $filterLbl === 'positif' ? 'selected' : '' ?>>Positif</option>
                        <option value="negatif" <?= $filterLbl === 'negatif' ? 'selected' : '' ?>>Negatif</option>
                        <option value="netral"  <?= $filterLbl === 'netral'  ? 'selected' : '' ?>>Netral</option>
                    </select>
                    <input type="text" name="q" class="form-control form-control-sm" value="<?= e($searchQ) ?>" placeholder="Cari teks...">
                    <button type="submit" class="btn btn-sm btn-navy">Cari</button>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle small mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">ID</th>
                            <th>Teks Berlabel (Bebas PII)</th>
                            <th class="text-center">Aspek</th>
                            <th class="text-center">Label Sentimen</th>
                            <th class="text-center">Sumber</th>
                            <th class="text-end pe-3">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($datasetRows as $dr): ?>
                            <?php
                            $lbl = $dr['sentiment_label'];
                            $bCls = $lbl === 'positif' ? 'badge-positif' : ($lbl === 'negatif' ? 'badge-negatif' : 'badge-netral');
                            ?>
                            <tr>
                                <td class="ps-3 fw-semibold">#<?= (int)$dr['id'] ?></td>
                                <td><?= e($dr['text_content']) ?></td>
                                <td class="text-center"><span class="badge bg-light text-dark border"><?= e($dr['aspect_category']) ?></span></td>
                                <td class="text-center"><span class="badge <?= e($bCls) ?> text-uppercase"><?= e($lbl) ?></span></td>
                                <td class="text-center text-muted"><?= e($dr['source']) ?></td>
                                <td class="text-end pe-3">
                                    <form method="post" class="d-inline" onsubmit="return confirm('Hapus sampel ini dari dataset pelatihan?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_sample">
                                        <input type="hidden" name="dataset_id" value="<?= (int)$dr['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2 px-3">
                    <span class="small text-muted">Halaman <?= $page ?> dari <?= $totalPages ?></span>
                    <ul class="pagination pagination-sm mb-0">
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= e(base_url('admin/datasets.php?page=' . $p . '&label=' . urlencode($filterLbl) . '&q=' . urlencode($searchQ))) ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
