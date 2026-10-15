<?php
/**
 * Konfigurasi Database, Keamanan, Session, PII Masking & Integrasi Python ML
 * Sistem Evaluasi Kegiatan Mahasiswa terhadap PPKMB Berbasis Analisis Sentimen
 */

declare(strict_types=1);

// Muat konfigurasi dari .env (atau .env.example sebagai fallback default Laragon)
function load_env_file(string $baseDir): array {
    $env = [];
    $envPath = is_file($baseDir . '/.env') ? $baseDir . '/.env' : $baseDir . '/.env.example';
    if (is_file($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $val = trim($parts[1]);
                $val = trim($val, "\"'");
                $env[$key] = $val;
            }
        }
    }
    return $env;
}

$APP_ROOT = dirname(__DIR__);
$ENV_VARS = load_env_file($APP_ROOT);

function env_val(string $key, string $default = ''): string {
    global $ENV_VARS;
    if (isset($_ENV[$key])) {
        return (string)$_ENV[$key];
    }
    $g = getenv($key);
    if ($g !== false) {
        return (string)$g;
    }
    return $ENV_VARS[$key] ?? $default;
}

date_default_timezone_set(env_val('APP_TIMEZONE', 'Asia/Jakarta'));

// Konfigurasi Session yang Aman
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

/**
 * Deteksi otomatis Base URL agar berjalan mulus baik di:
 * - http://localhost/ppkmb-evaluation/
 * - http://ppkmb-evaluation.test/ (Virtual Host Laragon)
 */
function base_url(string $path = ''): string {
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $basePath = '';
    if (preg_match('#^(.*?/ppkmb-evaluation)(?:/|$)#i', $scriptName, $m)) {
        $basePath = $m[1];
    } else {
        // Jika dijalankan di root virtual host (misal ppkmb-evaluation.test)
        $dir = rtrim(dirname($scriptName), '/');
        if (preg_match('#/(admin|student|api|includes|config)$#', $dir)) {
            $basePath = dirname($dir);
            if ($basePath === '/' || $basePath === '\\' || $basePath === '.') {
                $basePath = '';
            }
        } else {
            $basePath = ($dir === '/' || $dir === '\\' || $dir === '.') ? '' : $dir;
        }
    }
    $cleanPath = ltrim($path, '/');
    return ($basePath === '' ? '' : $basePath) . ($cleanPath !== '' ? '/' . $cleanPath : '/');
}

/**
 * Sanitasi Output HTML (Anti-XSS)
 */
function e(mixed $value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Manajemen Token CSRF
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(?string $token = null): bool {
    $submitted = $token ?? ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($submitted === '' || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], (string)$submitted);
}

/**
 * Flash Notification Helper
 */
function set_flash(string $type, string $message): void {
    $_SESSION['flash_messages'][] = [
        'type' => $type, // success, danger, warning, info
        'message' => $message,
    ];
}

function get_flashes(): array {
    $msgs = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $msgs;
}

/**
 * Rate Limiting Berbasis Session & IP
 */
function check_rate_limit(string $actionKey, int $maxAttempts = 20, int $windowSeconds = 60): bool {
    $now = time();
    $bucketKey = 'rl_' . $actionKey . '_' . md5($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    if (!isset($_SESSION[$bucketKey]) || !is_array($_SESSION[$bucketKey])) {
        $_SESSION[$bucketKey] = [];
    }
    // Bersihkan timestamp yang sudah lewat jendela waktu
    $_SESSION[$bucketKey] = array_values(array_filter(
        $_SESSION[$bucketKey],
        static fn(int $ts): bool => ($now - $ts) < $windowSeconds
    ));
    if (count($_SESSION[$bucketKey]) >= $maxAttempts) {
        return false;
    }
    $_SESSION[$bucketKey][] = $now;
    return true;
}

/**
 * Penyamaran Informasi Pribadi (PII Masking) di Sisi PHP
 * Menjaga privasi mahasiswa sebelum disimpan ke database atau dikirim ke modul ML.
 */
function mask_pii_text(string $text): string {
    if (trim($text) === '') {
        return '';
    }
    // 1. Samarkan email
    $masked = preg_replace('/[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+/', '[EMAIL_DISEMBUNYIKAN]', $text);
    // 2. Samarkan nomor telepon Indonesia (+62 / 08xx)
    $masked = preg_replace('/(\+62|62|0)8[1-9][0-9]{6,11}\b/', '[TELP_DISEMBUNYIKAN]', (string)$masked);
    // 3. Samarkan NIM / NIK (8-16 digit angka berurutan)
    $masked = preg_replace('/\b\d{8,16}\b/', '[NIM_DISEMBUNYIKAN]', (string)$masked);
    // 4. Samarkan penyebutan nama eksplisit ("nama saya ...")
    $masked = preg_replace('/\b(nama\s+saya|perkenalkan\s+saya)\s+([A-Za-z]+(?:\s+[A-Za-z]+){0,2})/i', '$1 [NAMA_DISEMBUNYIKAN]', (string)$masked);
    return trim((string)$masked);
}

// -----------------------------------------------------------------------------
// Koneksi Database PDO MySQL / MariaDB (dengan Auto-Setup Opsional untuk Laragon)
// -----------------------------------------------------------------------------
$dbHost = env_val('DB_HOST', '127.0.0.1');
$dbPort = env_val('DB_PORT', '3306');
$dbName = env_val('DB_DATABASE', 'ppkmb_sentiment_db');
$dbUser = env_val('DB_USERNAME', 'root');
$dbPass = env_val('DB_PASSWORD', '');

$pdo = null;

try {
    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    // Jika database belum dibuat di Laragon, dukung Auto-Install 1-Klik pada mode lokal
    if (env_val('APP_ENV', 'local') === 'local' && isset($_POST['auto_initialize_db'])) {
        try {
            $rootPdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $schemaSql = file_get_contents($APP_ROOT . '/database/schema.sql');
            $seedSql = file_get_contents($APP_ROOT . '/database/seed.sql');
            if ($schemaSql !== false) {
                $rootPdo->exec($schemaSql);
            }
            if ($seedSql !== false) {
                $rootPdo->exec($seedSql);
            }
            header('Location: ' . base_url('index.php?setup=success'));
            exit;
        } catch (Throwable $setupErr) {
            $setupErrorMsg = $setupErr->getMessage();
        }
    }

    http_response_code(503);
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Koneksi Database Belum Siap - Sistem Evaluasi PPKMB</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light d-flex align-items-center min-vh-100 py-5">
        <div class="container">
            <div class="card shadow-sm border-0 mx-auto" style="max-width: 680px; border-top: 5px solid #0d254c !important;">
                <div class="card-body p-4 p-md-5">
                    <h4 class="fw-bold text-dark mb-3">Database MySQL / MariaDB Belum Terhubung</h4>
                    <p class="text-secondary mb-3">
                        Sistem belum dapat terhubung ke database <code><?= e($dbName) ?></code> pada host <code><?= e($dbHost) ?>:<?= e($dbPort) ?></code>.
                    </p>
                    <div class="alert alert-warning small">
                        <strong>Pesan Teknis:</strong> <?= e($setupErrorMsg ?? $e->getMessage()) ?>
                    </div>
                    <h6 class="fw-semibold mt-4">Langkah Cepat di Laragon (Windows):</h6>
                    <ol class="small text-secondary mb-4">
                        <li>Pastikan aplikasi <strong>Laragon</strong> sudah berjalan dan layanan <strong>MySQL / MariaDB</strong> berstatus <em>Running</em>.</li>
                        <li>Klik tombol <strong>Inisialisasi Database Otomatis</strong> di bawah ini, atau impor file <code>database/schema.sql</code> lalu <code>database/seed.sql</code> melalui HeidiSQL / phpMyAdmin.</li>
                    </ol>
                    <form method="post" class="d-inline">
                        <button type="submit" name="auto_initialize_db" value="1" class="btn btn-primary px-4" style="background-color:#0d254c;border-color:#0d254c;">
                            Inisialisasi Database &amp; Data Pengujian Otomatis
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Pencatatan Audit Log Administrator & Panitia
 */
function write_audit_log(
    PDO $pdo,
    string $actionType,
    string $targetEntity,
    ?string $targetId,
    string $description
): void {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs (user_id, role_snapshot, action_type, target_entity, target_id, description, ip_address)
             VALUES (:uid, :role, :act, :ent, :tid, :desc, :ip)"
        );
        $stmt->execute([
            ':uid'  => $_SESSION['user_id'] ?? null,
            ':role' => $_SESSION['user_role'] ?? 'guest',
            ':act'  => $actionType,
            ':ent'  => $targetEntity,
            ':tid'  => $targetId,
            ':desc' => $description,
            ':ip'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
    } catch (Throwable) {
        // Jangan gagalkan transaksi utama bila gagal mencatat log
    }
}

/**
 * Fungsi Pemanggilan Modul Python Analisis Sentimen dengan Fallback Transparan
 * Menggunakan proc_open() dan komunikasi JSON via STDIN (Aman dari Command Injection).
 */
function call_python_sentiment_predictor(string $commentExp, string $commentCrit = ''): array {
    global $APP_ROOT;
    $pythonBin = env_val('PYTHON_BIN', 'python');
    $scriptPath = $APP_ROOT . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'predict_sentiment.py';

    $payload = json_encode([
        'comment' => $commentExp,
        'critique' => $commentCrit,
    ], JSON_UNESCAPED_UNICODE);

    if (is_file($scriptPath) && function_exists('proc_open')) {
        $candidates = array_unique([$pythonBin, 'python3', 'python']);
        foreach ($candidates as $bin) {
            $cmd = escapeshellcmd($bin) . ' ' . escapeshellarg($scriptPath) . ' --stdin';
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = @proc_open($cmd, $descriptors, $pipes, $APP_ROOT . DIRECTORY_SEPARATOR . 'python');
            if (is_resource($process)) {
                fwrite($pipes[0], (string)$payload);
                fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                $exitCode = proc_close($process);

                if ($exitCode === 0 && !empty($stdout)) {
                    $decoded = json_decode(trim($stdout), true);
                    if (is_array($decoded) && isset($decoded['label'])) {
                        return $decoded;
                    }
                }
            }
        }
    }

    // FALLBACK TRANSPARAN: Jika Python tidak ditemukan di PATH atau model belum tersedia.
    // Sistem secara eksplisit menandai is_ml = false dan status = 'fallback_rule'
    // agar TIDAK PERNAH mengklaim analisis berbasis AI ketika menggunakan pencocokan kata sederhana.
    $combined = trim($commentExp . ' ' . $commentCrit);
    $masked = mask_pii_text($combined);
    $lower = strtolower(preg_replace('/[^a-zA-Z\s]/', ' ', $masked) ?? '');
    $words = array_values(array_filter(explode(' ', $lower), static fn($w) => strlen($w) >= 2));

    if (strlen(trim($combined)) === 0) {
        return [
            'label' => 'netral',
            'confidence_score' => 0.0,
            'probabilities' => ['positif' => 0.0, 'negatif' => 0.0, 'netral' => 1.0],
            'status' => 'empty',
            'status_message' => 'Komentar kosong.',
            'is_ml' => false,
            'method' => 'rule_validation',
            'model_version' => 'none',
            'cleaned_text' => '',
            'masked_text' => $masked,
            'analyzed_at' => date('Y-m-d H:i:s'),
        ];
    }

    if (count($words) < 3 || strlen(trim($combined)) < 10) {
        return [
            'label' => 'netral',
            'confidence_score' => 0.0,
            'probabilities' => ['positif' => 0.0, 'negatif' => 0.0, 'netral' => 1.0],
            'status' => 'too_short',
            'status_message' => 'Teks terlalu pendek (< 3 kata). Minimal 3 kata diperlukan untuk analisis.',
            'is_ml' => false,
            'method' => 'rule_validation',
            'model_version' => 'none',
            'cleaned_text' => implode(' ', $words),
            'masked_text' => $masked,
            'analyzed_at' => date('Y-m-d H:i:s'),
        ];
    }

    $posLex = ['bermanfaat','membantu','bagus','baik','ramah','nyaman','jelas','interaktif','puas','seru','edukatif','menginspirasi','motivasi','sabar','sigap','tepat','tertib','lancar','sukses','bersih','enak','layak','apresiasi','komunikatif','sopan','senang','bangga','meriah','kompak','profesional','higienis'];
    $negLex = ['padat','kurang','kelelahan','lelah','mati','berdengung','membentak','terlambat','molor','ngaret','dingin','keras','monoton','panas','pengap','kacau','sedikit','habis','kecewa','kotor','arogan','kasar','basi','rusak','semrawut','membingungkan','bingung','tegang','pingsan','lambat','buruk'];

    $posScore = 0;
    $negScore = 0;
    $n = count($words);
    for ($i = 0; $i < $n; $i++) {
        $w = $words[$i];
        $prevNeg = ($i > 0 && in_array($words[$i - 1], ['tidak', 'kurang', 'belum', 'bukan'], true));
        if (in_array($w, $posLex, true)) {
            if ($prevNeg) {
                $negScore += 2;
            } else {
                $posScore += 1;
            }
        } elseif (in_array($w, $negLex, true)) {
            if ($prevNeg && in_array($w, ['membosankan', 'terlambat', 'molor'], true)) {
                $posScore += 1;
            } else {
                $negScore += 1;
            }
        }
    }

    if ($posScore > $negScore) {
        $label = 'positif';
        $conf = min(0.82, 0.52 + 0.08 * ($posScore - $negScore));
    } elseif ($negScore > $posScore) {
        $label = 'negatif';
        $conf = min(0.82, 0.52 + 0.08 * ($negScore - $posScore));
    } else {
        $label = 'netral';
        $conf = 0.50;
    }

    $rem = round((1.0 - $conf) / 2, 4);
    return [
        'label' => $label,
        'confidence_score' => round($conf, 4),
        'probabilities' => [
            'positif' => $label === 'positif' ? round($conf, 4) : $rem,
            'negatif' => $label === 'negatif' ? round($conf, 4) : $rem,
            'netral'  => $label === 'netral'  ? round($conf, 4) : $rem,
        ],
        'status' => 'fallback_rule',
        'status_message' => 'PERINGATAN: Layanan Python ML belum aktif di host. Klasifikasi ini menggunakan Mekanisme Fallback Pencocokan Kata Sederhana (Bukan AI/Machine Learning).',
        'is_ml' => false,
        'method' => 'fallback_lexicon',
        'model_version' => 'php-fallback-lexicon-non-ml',
        'cleaned_text' => implode(' ', $words),
        'masked_text' => $masked,
        'analyzed_at' => date('Y-m-d H:i:s'),
    ];
}

/**
 * Jalankan analisis sentimen dan simpan hasilnya ke tabel sentiment_results
 */
function analyze_and_save_evaluation(
    PDO $pdo,
    int $evaluationId,
    string $commentExp,
    string $commentCrit,
    int $ratingKeseluruhan
): array {
    $pred = call_python_sentiment_predictor($commentExp, $commentCrit);
    $label = in_array($pred['label'] ?? '', ['positif', 'negatif', 'netral'], true) ? $pred['label'] : 'netral';
    $conf = (float)($pred['confidence_score'] ?? 0.0);
    $pPos = (float)($pred['probabilities']['positif'] ?? 0.0);
    $pNeg = (float)($pred['probabilities']['negatif'] ?? 0.0);
    $pNeu = (float)($pred['probabilities']['netral'] ?? 0.0);
    $status = $pred['status'] ?? 'analyzed';
    if (!in_array($status, ['analyzed', 'low_confidence', 'too_short', 'empty', 'fallback_rule', 'pending'], true)) {
        $status = 'analyzed';
    }
    $isMl = !empty($pred['is_ml']) ? 1 : 0;
    $modelVer = (string)($pred['model_version'] ?? 'unknown');
    $cleaned = (string)($pred['cleaned_text'] ?? '');

    // Indikator diskrepansi: Rating angka vs Sentimen teks dianalisis sebagai dua indikator berbeda
    $discrepancy = 0;
    if (($ratingKeseluruhan >= 4 && $label === 'negatif') || ($ratingKeseluruhan <= 2 && $label === 'positif')) {
        $discrepancy = 1;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO sentiment_results
            (evaluation_id, sentiment_label, confidence_score, prob_positif, prob_negatif, prob_netral,
             analysis_status, is_ml, model_version, cleaned_text, rating_sentiment_discrepancy, analyzed_at)
         VALUES
            (:ev_id, :lbl, :conf, :p_pos, :p_neg, :p_neu, :st, :is_ml, :m_ver, :cl_txt, :disc, NOW())
         ON DUPLICATE KEY UPDATE
            sentiment_label = VALUES(sentiment_label),
            confidence_score = VALUES(confidence_score),
            prob_positif = VALUES(prob_positif),
            prob_negatif = VALUES(prob_negatif),
            prob_netral = VALUES(prob_netral),
            analysis_status = VALUES(analysis_status),
            is_ml = VALUES(is_ml),
            model_version = VALUES(model_version),
            cleaned_text = VALUES(cleaned_text),
            rating_sentiment_discrepancy = VALUES(rating_sentiment_discrepancy),
            analyzed_at = NOW()"
    );
    $stmt->execute([
        ':ev_id'  => $evaluationId,
        ':lbl'    => $label,
        ':conf'   => $conf,
        ':p_pos'  => $pPos,
        ':p_neg'  => $pNeg,
        ':p_neu'  => $pNeu,
        ':st'     => $status,
        ':is_ml'  => $isMl,
        ':m_ver'  => $modelVer,
        ':cl_txt' => $cleaned,
        ':disc'   => $discrepancy,
    ]);

    $pred['rating_sentiment_discrepancy'] = $discrepancy;
    return $pred;
}
