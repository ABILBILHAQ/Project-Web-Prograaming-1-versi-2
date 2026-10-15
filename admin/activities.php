<?php
/**
 * Halaman Manajemen Jenis & Sesi Kegiatan PPKMB (admin/activities.php)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role(['admin', 'panitia']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $act = (string)($_POST['action'] ?? '');

    if ($act === 'create_activity') {
        $periodId = (int)($_POST['period_id'] ?? 0);
        $code     = strtoupper(trim((string)($_POST['activity_code'] ?? '')));
        $name     = trim((string)($_POST['activity_name'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $speaker  = trim((string)($_POST['speaker_name'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $aDate    = trim((string)($_POST['activity_date'] ?? ''));
        $sTime    = trim((string)($_POST['start_time'] ?? '08:00'));
        $eTime    = trim((string)($_POST['end_time'] ?? '12:00'));
        $desc     = trim((string)($_POST['description'] ?? ''));

        if ($periodId > 0 && $code !== '' && $name !== '' && $category !== '' && $aDate !== '') {
            try {
                $ins = $pdo->prepare(
                    "INSERT INTO activities (period_id, activity_code, activity_name, category, speaker_name, location, activity_date, start_time, end_time, description, is_active)
                     VALUES (:pid, :cd, :nm, :cat, :sp, :loc, :dt, :st, :et, :ds, 1)"
                );
                $ins->execute([
                    ':pid' => $periodId, ':cd' => $code, ':nm' => $name, ':cat' => $category,
                    ':sp' => $speaker, ':loc' => $location, ':dt' => $aDate, ':st' => $sTime, ':et' => $eTime, ':ds' => $desc,
                ]);
                write_audit_log($pdo, 'CREATE_ACTIVITY', 'activities', (string)$pdo->lastInsertId(), "Menambahkan sesi kegiatan {$code}: {$name}");
                set_flash('success', "Sesi kegiatan {$name} berhasil ditambahkan.");
            } catch (Throwable $e) {
                set_flash('danger', 'Gagal menyimpan sesi kegiatan: Kode sesi sudah ada pada periode tersebut.');
            }
        } else {
            set_flash('danger', 'Mohon lengkapi seluruh kolom wajib sesi kegiatan.');
        }
        header('Location: ' . base_url('admin/activities.php'));
        exit;
    }

    if ($act === 'toggle_activity') {
        $aid = (int)($_POST['activity_id'] ?? 0);
        $upd = $pdo->prepare("UPDATE activities SET is_active = 1 - is_active WHERE id = :id");
        $upd->execute([':id' => $aid]);
        write_audit_log($pdo, 'TOGGLE_ACTIVITY', 'activities', (string)$aid, "Mengubah status aktif sesi kegiatan #{$aid}.");
        set_flash('info', 'Status aktif sesi kegiatan diperbarui.');
        header('Location: ' . base_url('admin/activities.php'));
        exit;
    }
}

$periods = $pdo->query("SELECT id, academic_year, period_name, is_active FROM periods ORDER BY id DESC")->fetchAll();
$activities = $pdo->query(
    "SELECT a.*, p.academic_year, p.period_name,
            (SELECT COUNT(*) FROM evaluations e WHERE e.activity_id = a.id) AS eval_count,
            (SELECT ROUND(AVG(e.rating_keseluruhan), 2) FROM evaluations e WHERE e.activity_id = a.id) AS avg_score
     FROM activities a
     JOIN periods p ON p.id = a.period_id
     ORDER BY a.period_id DESC, a.activity_date ASC, a.start_time ASC"
)->fetchAll();

$categoryOptions = [
    'Sesi Pleno Universitas',
    'Sesi Akademik & Fakultas',
    'Pembinaan Karakter & Kebangsaan',
    'Kemahasiswaan & UKM',
    'Fasilitas & Layanan Lapangan',
    'Inagurasi & Penutupan',
];

$pageTitle = 'Manajemen Sesi Kegiatan PPKMB';
$showSidebar = true;
$activeMenu = 'activities';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="app-wrapper">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1" style="color: #0d254c;">Manajemen Jenis &amp; Sesi Kegiatan PPKMB</h4>
                <p class="text-secondary small mb-0">
                    Kelola daftar sesi materi, pembicara, lokasi, dan kategori kegiatan yang dapat dievaluasi oleh mahasiswa baru.
                </p>
            </div>
            <button class="btn btn-navy btn-sm" data-bs-toggle="collapse" data-bs-target="#formAddActivity">
                <i class="bi bi-plus-circle me-1"></i>Tambah Sesi Kegiatan Baru
            </button>
        </div>

        <!-- Form Tambah Sesi Kegiatan -->
        <div class="collapse mb-4" id="formAddActivity">
            <div class="card-modern p-4">
                <h6 class="fw-bold mb-3" style="color: #0d254c;">Formulir Tambah Sesi Kegiatan PPKMB</h6>
                <form method="post" class="row g-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_activity">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Periode PPKMB</label>
                        <select name="period_id" class="form-select form-select-sm" required>
                            <?php foreach ($periods as $p): ?>
                                <option value="<?= (int)$p['id'] ?>"><?= e($p['academic_year'] . ' - ' . $p['period_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Kode Sesi</label>
                        <input type="text" name="activity_code" class="form-control form-control-sm" placeholder="PPKMB26-S7" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Nama Kegiatan / Sesi Materi</label>
                        <input type="text" name="activity_name" class="form-control form-control-sm" placeholder="Contoh: Sesi Literasi AI & Etika Riset" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Kategori Kegiatan</label>
                        <select name="category" class="form-select form-select-sm" required>
                            <?php foreach ($categoryOptions as $cOpt): ?>
                                <option value="<?= e($cOpt) ?>"><?= e($cOpt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Pemateri / Narasumber</label>
                        <input type="text" name="speaker_name" class="form-control form-control-sm" placeholder="Nama Pemateri / Tim">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Lokasi / Gedung</label>
                        <input type="text" name="location" class="form-control form-control-sm" placeholder="Auditorium Utama">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Tanggal Pelaksanaan</label>
                        <input type="date" name="activity_date" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Jam Mulai</label>
                        <input type="time" name="start_time" class="form-control form-control-sm" value="08:00">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Jam Selesai</label>
                        <input type="time" name="end_time" class="form-control form-control-sm" value="11:30">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">Deskripsi Sesi</label>
                        <input type="text" name="description" class="form-control form-control-sm" placeholder="Ringkasan topik sesi...">
                    </div>
                    <div class="col-12 text-end">
                        <button type="submit" class="btn btn-sm btn-navy px-4">Simpan Sesi Kegiatan</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tabel Daftar Kegiatan -->
        <div class="card-modern overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle small mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Kode &amp; Periode</th>
                            <th>Nama Sesi Kegiatan</th>
                            <th>Kategori</th>
                            <th>Pemateri &amp; Lokasi</th>
                            <th>Jadwal</th>
                            <th class="text-center">Responden / Skor</th>
                            <th class="text-end pe-3">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activities as $a): ?>
                            <tr>
                                <td class="ps-3">
                                    <code><?= e($a['activity_code']) ?></code>
                                    <div class="text-muted" style="font-size:0.72rem;"><?= e($a['academic_year']) ?></div>
                                </td>
                                <td class="fw-semibold text-dark" style="max-width:260px;">
                                    <?= e($a['activity_name']) ?>
                                </td>
                                <td><span class="badge bg-light text-dark border"><?= e($a['category']) ?></span></td>
                                <td>
                                    <div><?= e($a['speaker_name'] ?: '-') ?></div>
                                    <div class="text-muted" style="font-size:0.73rem;"><i class="bi bi-geo-alt me-1"></i><?= e($a['location'] ?: '-') ?></div>
                                </td>
                                <td class="text-nowrap">
                                    <div><?= e($a['activity_date']) ?></div>
                                    <div class="text-muted" style="font-size:0.73rem;"><?= e(substr((string)$a['start_time'], 0, 5) . ' - ' . substr((string)$a['end_time'], 0, 5)) ?></div>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-primary-subtle text-primary"><?= (int)$a['eval_count'] ?> Evaluasi</span>
                                    <?php if ($a['avg_score']): ?>
                                        <div class="fw-bold text-dark mt-1"><?= number_format((float)$a['avg_score'], 2) ?> / 5.0</div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3">
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_activity">
                                        <input type="hidden" name="activity_id" value="<?= (int)$a['id'] ?>">
                                        <button type="submit" class="btn btn-sm <?= (int)$a['is_active'] === 1 ? 'btn-success' : 'btn-outline-secondary' ?>">
                                            <?= (int)$a['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?>
                                        </button>
                                    </form>
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
