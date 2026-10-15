<?php
/**
 * Endpoint API Analisis Sentimen Bahasa Indonesia (api/analyze-sentiment.php)
 * Diamankan dengan Session Admin/Panitia + CSRF Token ATAU Header X-API-KEY rahasia.
 * Mendukung:
 * - action=predict_text      : Uji prediksi teks komentar langsung
 * - action=reanalyze_single  : Analisis ulang satu data evaluasi di database
 * - action=reanalyze_batch   : Analisis ulang seluruh/sebagian evaluasi dengan model terbaru
 * - action=train_model       : Ekspor dataset dari tabel sentiment_datasets & latih ulang model Python
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Metode HTTP harus POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Verifikasi keamanan: wajib memiliki X-API-KEY yang sah ATAU login sebagai Admin/Panitia dengan CSRF valid
$providedApiKey = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
$expectedApiKey = env_val('SENTIMENT_API_SECRET', 'ppkmb_internal_api_key_change_this_in_production_2026');
$isValidApiKey = ($providedApiKey !== '' && hash_equals($expectedApiKey, $providedApiKey));
$isValidAdminSession = (is_admin_or_panitia() && verify_csrf());

if (!$isValidApiKey && !$isValidAdminSession) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Akses API ditolak: Token autentikasi atau sesi administrator tidak valid.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!check_rate_limit('api_sentiment', (int)env_val('RATE_LIMIT_PER_MINUTE', '20'), 60)) {
    http_response_code(429);
    echo json_encode([
        'status' => 'error',
        'message' => 'Batas panggilan API terlampaui (Rate Limit). Silakan tunggu sejenak.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input');
$jsonBody = !empty($rawBody) ? json_decode($rawBody, true) : [];
$input = is_array($jsonBody) && !empty($jsonBody) ? $jsonBody : $_POST;

$action = (string)($input['action'] ?? 'predict_text');

try {
    if ($action === 'predict_text') {
        $text = substr(trim((string)($input['text'] ?? '')), 0, (int)env_val('MAX_COMMENT_LENGTH', '2000'));
        $critique = substr(trim((string)($input['critique'] ?? '')), 0, (int)env_val('MAX_COMMENT_LENGTH', '2000'));
        $pred = call_python_sentiment_predictor($text, $critique);
        echo json_encode(['status' => 'success', 'prediction' => $pred], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'reanalyze_single') {
        $evalId = (int)($input['evaluation_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM evaluations WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $evalId]);
        $ev = $stmt->fetch();
        if (!$ev) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Data evaluasi tidak ditemukan.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $cleanCrit = trim(str_replace('[DATA DUMMY PENGUJIAN]', '', (string)($ev['comment_critique'] ?? '')));
        $pred = analyze_and_save_evaluation(
            $pdo,
            (int)$ev['id'],
            (string)$ev['comment_experience'],
            $cleanCrit,
            (int)$ev['rating_keseluruhan']
        );
        write_audit_log($pdo, 'REANALYZE_SINGLE', 'evaluations', (string)$evalId, "Menganalisis ulang evaluasi #{$evalId} -> {$pred['label']} ({$pred['confidence_score']})");
        echo json_encode(['status' => 'success', 'evaluation_id' => $evalId, 'prediction' => $pred], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'reanalyze_batch') {
        $periodId = (int)($input['period_id'] ?? 0);
        $sql = "SELECT id, comment_experience, comment_critique, rating_keseluruhan FROM evaluations";
        $params = [];
        if ($periodId > 0) {
            $sql .= " WHERE period_id = :pid";
            $params[':pid'] = $periodId;
        }
        $sql .= " ORDER BY id ASC LIMIT 500";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $updated = 0;
        foreach ($rows as $r) {
            $cleanCrit = trim(str_replace('[DATA DUMMY PENGUJIAN]', '', (string)($r['comment_critique'] ?? '')));
            analyze_and_save_evaluation(
                $pdo,
                (int)$r['id'],
                (string)$r['comment_experience'],
                $cleanCrit,
                (int)$r['rating_keseluruhan']
            );
            $updated++;
        }
        write_audit_log($pdo, 'REANALYZE_BATCH', 'sentiment_results', (string)$periodId, "Menganalisis ulang {$updated} komentar evaluasi secara batch.");
        echo json_encode([
            'status' => 'success',
            'message' => "Berhasil menganalisis ulang {$updated} komentar evaluasi menggunakan model terbaru.",
            'updated_count' => $updated,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'train_model') {
        // Ekspor seluruh dataset dari tabel sentiment_datasets ke file CSV Python lalu jalankan train_model.py
        $stmt = $pdo->query("SELECT id, text_content, sentiment_label, aspect_category, source FROM sentiment_datasets ORDER BY id ASC");
        $dsRows = $stmt->fetchAll();
        if (count($dsRows) < 15) {
            echo json_encode(['status' => 'error', 'message' => 'Minimal diperlukan 15 data berlabel untuk melatih model.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $csvFile = $APP_ROOT . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'dataset_ppkmb_id.csv';
        $fp = fopen($csvFile, 'w');
        fputcsv($fp, ['id', 'text', 'label', 'aspect_category', 'source']);
        foreach ($dsRows as $dr) {
            fputcsv($fp, [
                $dr['id'],
                mask_pii_text((string)$dr['text_content']),
                $dr['sentiment_label'],
                $dr['aspect_category'],
                $dr['source'],
            ]);
        }
        fclose($fp);

        $pythonBin = env_val('PYTHON_BIN', 'python');
        $trainScript = $APP_ROOT . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'train_model.py';
        $candidates = array_unique([$pythonBin, 'python3', 'python']);
        $trainOutput = null;

        foreach ($candidates as $bin) {
            $cmd = escapeshellcmd($bin) . ' ' . escapeshellarg($trainScript) . ' --dataset ' . escapeshellarg($csvFile);
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = @proc_open($cmd, $descriptors, $pipes, $APP_ROOT . DIRECTORY_SEPARATOR . 'python');
            if (is_resource($proc)) {
                fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $code = proc_close($proc);
                if ($code === 0 && !empty($stdout)) {
                    $decoded = json_decode(trim($stdout), true);
                    if (is_array($decoded) && ($decoded['status'] ?? '') === 'success') {
                        $trainOutput = $decoded;
                        break;
                    }
                }
            }
        }

        if (!$trainOutput) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Gagal mengeksekusi python/train_model.py. Pastikan Python 3 dan numpy/scipy/scikit-learn telah terinstal di Laragon.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $m = $trainOutput['metrics'];
        $ins = $pdo->prepare(
            "INSERT INTO model_metrics
                (model_version, evaluation_method, dataset_size, vocabulary_size, accuracy, precision_score, recall_score, f1_score, metrics_detail_json, trained_by, evaluated_at)
             VALUES
                (:ver, :meth, :dsize, :vsize, :acc, :prec, :rec, :f1, :json, :uid, NOW())"
        );
        $ins->execute([
            ':ver'   => $trainOutput['model_version'],
            ':meth'  => $m['evaluation_method'],
            ':dsize' => (int)$trainOutput['training_samples'],
            ':vsize' => (int)$trainOutput['vocabulary_size'],
            ':acc'   => (float)$m['accuracy'],
            ':prec'  => (float)$m['precision'],
            ':rec'   => (float)$m['recall'],
            ':f1'    => (float)$m['f1_score'],
            ':json'  => json_encode($m, JSON_UNESCAPED_UNICODE),
            ':uid'   => $_SESSION['user_id'] ?? null,
        ]);

        write_audit_log($pdo, 'TRAIN_MODEL', 'model_metrics', (string)$pdo->lastInsertId(), "Melatih ulang model {$trainOutput['model_version']} dengan {$trainOutput['training_samples']} dataset.");
        echo json_encode($trainOutput, JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Action API tidak dikenali.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $err) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Terjadi kesalahan internal pada layanan analisis.'], JSON_UNESCAPED_UNICODE);
}
