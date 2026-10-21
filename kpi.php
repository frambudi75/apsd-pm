<?php
/**
 * Penilaian Kinerja & KPI Tim NOC
 * PT Angkasa Pura Sarana Digital & IASS SBU ICT KNO
 * 
 * Fitur:
 * - Kalkulasi metrik objektif sistem (60%): Kehadiran Shift, Logbook, Dokumentasi Foto, PM
 * - Review kualitatif atasan (40%): SOP Bandara, Problem Solving, Respon & Komunikasi, Inisiatif
 * - Kontrol rilis: Rapor teknisi hanya muncul jika admin klik 'Kirim ke Teknisi'
 * - Transparansi penuh: Penjelasan sumber data, rumus pembobotan, dan predikat resmi
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/audit.php';
require_login();

$pdo = get_db_connection();
$currentUser = current_user();
$isAdmin = has_role(['superadmin', 'admin']);

// Filter Periode Bulan
$currentMonth = date('Y-m');
$filterMonth = trim($_GET['month'] ?? $currentMonth);
if (!preg_match('/^\d{4}-\d{2}$/', $filterMonth)) {
    $filterMonth = $currentMonth;
}

$indoMonths = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
$monthTime = strtotime($filterMonth . '-01');
$mNum = (int)date('n', $monthTime);
$mYear = date('Y', $monthTime);
$periodLabel = ($indoMonths[$mNum] ?? date('F', $monthTime)) . ' ' . $mYear;

$feedbackMsg = '';
$feedbackType = '';

// ==========================================
// HANDLE ACTIONS (ADMIN & SUPERADMIN ONLY)
// ==========================================
if ($isAdmin && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. SIMPAN REVIEW PENILAIAN
    if ($action === 'save_review') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $sopScore = max(0, min(10, (float)($_POST['sop_score'] ?? 0)));
        $troubleScore = max(0, min(10, (float)($_POST['troubleshooting_score'] ?? 0)));
        $responseScore = max(0, min(10, (float)($_POST['response_score'] ?? 0)));
        $initiativeScore = max(0, min(10, (float)($_POST['initiative_score'] ?? 0)));
        $notes = trim($_POST['feedback_notes'] ?? '');
        $sendToUser = !empty($_POST['send_to_user']);

        $reviewScore = round($sopScore + $troubleScore + $responseScore + $initiativeScore, 2);

        // Ambil kalkulasi metrik sistem teknisi ini
        $systemMetrics = calculate_user_system_kpi($pdo, $targetUserId, $filterMonth);
        $systemScore = $systemMetrics['system_score'];
        $totalScore = round($systemScore + $reviewScore, 2);

        // Tentukan Predikat (Grade)
        if ($totalScore >= 90) $grade = 'A';
        elseif ($totalScore >= 80) $grade = 'B';
        elseif ($totalScore >= 70) $grade = 'C';
        else $grade = 'D';

        $status = $sendToUser ? 'published' : 'draft';
        $publishedAt = $sendToUser ? date('Y-m-d H:i:s') : null;
        $publishedBy = $sendToUser ? (int)$currentUser['id'] : null;

        $stmtUpsert = $pdo->prepare("
            INSERT INTO kpi_evaluations (
                user_id, period_month, shifts_count, completed_shifts, shift_score,
                activities_count, activity_score, photos_count, photo_score,
                pm_count, pm_score, system_score, sop_score, troubleshooting_score,
                response_score, initiative_score, review_score, total_score,
                grade, feedback_notes, status, published_at, published_by, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?, NOW()
            )
            ON DUPLICATE KEY UPDATE
                shifts_count = VALUES(shifts_count),
                completed_shifts = VALUES(completed_shifts),
                shift_score = VALUES(shift_score),
                activities_count = VALUES(activities_count),
                activity_score = VALUES(activity_score),
                photos_count = VALUES(photos_count),
                photo_score = VALUES(photo_score),
                pm_count = VALUES(pm_count),
                pm_score = VALUES(pm_score),
                system_score = VALUES(system_score),
                sop_score = VALUES(sop_score),
                troubleshooting_score = VALUES(troubleshooting_score),
                response_score = VALUES(response_score),
                initiative_score = VALUES(initiative_score),
                review_score = VALUES(review_score),
                total_score = VALUES(total_score),
                grade = VALUES(grade),
                feedback_notes = VALUES(feedback_notes),
                status = VALUES(status),
                published_at = IF(VALUES(status)='published', NOW(), published_at),
                published_by = IF(VALUES(status)='published', VALUES(published_by), published_by),
                updated_at = NOW()
        ");

        // Fallback untuk SQLite jika driver bukan MySQL
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmtUpsert = $pdo->prepare("
                INSERT INTO kpi_evaluations (
                    user_id, period_month, shifts_count, completed_shifts, shift_score,
                    activities_count, activity_score, photos_count, photo_score,
                    pm_count, pm_score, system_score, sop_score, troubleshooting_score,
                    response_score, initiative_score, review_score, total_score,
                    grade, feedback_notes, status, published_at, published_by, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, datetime('now', 'localtime')
                )
                ON CONFLICT(user_id, period_month) DO UPDATE SET
                    shifts_count = excluded.shifts_count,
                    completed_shifts = excluded.completed_shifts,
                    shift_score = excluded.shift_score,
                    activities_count = excluded.activities_count,
                    activity_score = excluded.activity_score,
                    photos_count = excluded.photos_count,
                    photo_score = excluded.photo_score,
                    pm_count = excluded.pm_count,
                    pm_score = excluded.pm_score,
                    system_score = excluded.system_score,
                    sop_score = excluded.sop_score,
                    troubleshooting_score = excluded.troubleshooting_score,
                    response_score = excluded.response_score,
                    initiative_score = excluded.initiative_score,
                    review_score = excluded.review_score,
                    total_score = excluded.total_score,
                    grade = excluded.grade,
                    feedback_notes = excluded.feedback_notes,
                    status = excluded.status,
                    published_at = CASE WHEN excluded.status='published' THEN datetime('now', 'localtime') ELSE kpi_evaluations.published_at END,
                    published_by = CASE WHEN excluded.status='published' THEN excluded.published_by ELSE kpi_evaluations.published_by END,
                    updated_at = datetime('now', 'localtime')
            ");
        }

        try {
            $stmtUpsert->execute([
                $targetUserId, $filterMonth,
                $systemMetrics['shifts_count'], $systemMetrics['completed_shifts'], $systemMetrics['shift_score'],
                $systemMetrics['activities_count'], $systemMetrics['activity_score'],
                $systemMetrics['photos_count'], $systemMetrics['photo_score'],
                $systemMetrics['pm_count'], $systemMetrics['pm_score'],
                $systemScore, $sopScore, $troubleScore, $responseScore, $initiativeScore,
                $reviewScore, $totalScore, $grade, $notes, $status, $publishedAt, $publishedBy
            ]);

            log_audit_event('KPI', 'SAVE_REVIEW', "Simpan review KPI user ID {$targetUserId} periode {$filterMonth} (Skor: {$totalScore}, Status: {$status})", 'user', $targetUserId);
            $feedbackMsg = $sendToUser ? 'Review KPI berhasil disimpan dan LANGSUNG DIKIRIM ke teknisi!' : 'Review KPI berhasil disimpan sebagai DRAFT (Belum dikirim ke teknisi).';
            $feedbackType = 'success';
        } catch (Exception $e) {
            $feedbackMsg = 'Gagal menyimpan review KPI: ' . $e->getMessage();
            $feedbackType = 'danger';
        }
    }

    // 2. TOGGLE STATUS KIRIM / TARIK RAPOR
    elseif ($action === 'toggle_publish') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $newStatus = ($_POST['new_status'] === 'published') ? 'published' : 'draft';

        $isPublished = ($newStatus === 'published');
        $pubAt = $isPublished ? date('Y-m-d H:i:s') : null;
        $pubBy = $isPublished ? (int)$currentUser['id'] : null;

        // Pastikan kalkulasi tersimpan sebelum dipublish jika belum pernah dinilai
        $systemMetrics = calculate_user_system_kpi($pdo, $targetUserId, $filterMonth);
        $systemScore = $systemMetrics['system_score'];

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmtToggle = $pdo->prepare("
                INSERT INTO kpi_evaluations (
                    user_id, period_month, shifts_count, completed_shifts, shift_score,
                    activities_count, activity_score, photos_count, photo_score,
                    pm_count, pm_score, system_score, total_score, grade, status, published_at, published_by, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, 'C', ?, ?, ?, datetime('now', 'localtime')
                )
                ON CONFLICT(user_id, period_month) DO UPDATE SET
                    status = excluded.status,
                    published_at = excluded.published_at,
                    published_by = excluded.published_by,
                    updated_at = datetime('now', 'localtime')
            ");
        } else {
            $stmtToggle = $pdo->prepare("
                INSERT INTO kpi_evaluations (
                    user_id, period_month, shifts_count, completed_shifts, shift_score,
                    activities_count, activity_score, photos_count, photo_score,
                    pm_count, pm_score, system_score, total_score, grade, status, published_at, published_by, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, 'C', ?, ?, ?, NOW()
                )
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    published_at = VALUES(published_at),
                    published_by = VALUES(published_by),
                    updated_at = NOW()
            ");
        }

        try {
            $stmtToggle->execute([
                $targetUserId, $filterMonth,
                $systemMetrics['shifts_count'], $systemMetrics['completed_shifts'], $systemMetrics['shift_score'],
                $systemMetrics['activities_count'], $systemMetrics['activity_score'],
                $systemMetrics['photos_count'], $systemMetrics['photo_score'],
                $systemMetrics['pm_count'], $systemMetrics['pm_score'],
                $systemScore, $systemScore, $newStatus, $pubAt, $pubBy
            ]);

            log_audit_event('KPI', 'TOGGLE_STATUS', "Ubah status rapor KPI user ID {$targetUserId} periode {$filterMonth} menjadi {$newStatus}", 'user', $targetUserId);
            $feedbackMsg = $isPublished ? 'Rapor KPI berhasil DIKIRIM ke akun teknisi!' : 'Rapor KPI berhasil DITARIK kembali (kini berstatus Draft).';
            $feedbackType = 'success';
        } catch (Exception $e) {
            $feedbackMsg = 'Gagal mengubah status rapor: ' . $e->getMessage();
            $feedbackType = 'danger';
        }
    }

    // 3. PUBLISH SEMUA RAPOR BULAN INI
    elseif ($action === 'publish_all') {
        try {
            $stmtPubAll = $pdo->prepare("
                UPDATE kpi_evaluations 
                SET status = 'published', published_at = NOW(), published_by = ?, updated_at = NOW()
                WHERE period_month = ?
            ");
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmtPubAll = $pdo->prepare("
                    UPDATE kpi_evaluations 
                    SET status = 'published', published_at = datetime('now', 'localtime'), published_by = ?, updated_at = datetime('now', 'localtime')
                    WHERE period_month = ?
                ");
            }
            $stmtPubAll->execute([(int)$currentUser['id'], $filterMonth]);
            log_audit_event('KPI', 'PUBLISH_ALL', "Rilis seluruh rapor KPI periode {$filterMonth} ke seluruh anggota tim", 'system', null);
            $feedbackMsg = 'Seluruh rapor yang telah dievaluasi pada periode ' . $periodLabel . ' berhasil dikirim ke teknisi!';
            $feedbackType = 'success';
        } catch (Exception $e) {
            $feedbackMsg = 'Gagal merilis semua rapor: ' . $e->getMessage();
            $feedbackType = 'danger';
        }
    }
}

// ==========================================
// FUNGSI KALKULASI METRIK SISTEM OTOMATIS
// ==========================================
if (!function_exists('calculate_user_system_kpi')) {
    function calculate_user_system_kpi(PDO $pdo, int $userId, string $month): array {
        // 1. Shift: kehadiran & ketuntasan
        $stmtShift = $pdo->prepare("
            SELECT 
                COUNT(id) AS total_shifts,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_shifts
            FROM daily_shifts
            WHERE user_id = ? AND shift_date LIKE ?
        ");
        $stmtShift->execute([$userId, $month . '%']);
        $shiftData = $stmtShift->fetch() ?: ['total_shifts' => 0, 'completed_shifts' => 0];

        $totShifts = (int)$shiftData['total_shifts'];
        $compShifts = (int)$shiftData['completed_shifts'];
        // Bobot Shift: 15 Poin. Rasio completed / total, jika total >= 1
        $shiftScore = ($totShifts > 0) ? round(($compShifts / $totShifts) * 15.0, 2) : 0.0;

        // 2. Logbook Harian: volume pekerjaan
        $stmtAct = $pdo->prepare("
            SELECT COUNT(a.id) 
            FROM daily_activities a
            INNER JOIN daily_shifts s ON a.shift_id = s.id
            WHERE s.user_id = ? AND s.shift_date LIKE ?
        ");
        $stmtAct->execute([$userId, $month . '%']);
        $actsCount = (int)$stmtAct->fetchColumn();
        // Bobot Logbook: 20 Poin. Benchmark: 25 kegiatan = 20 poin penuh (0.8 poin per kegiatan)
        $activityScore = min(20.0, round($actsCount * 0.8, 2));

        // 3. Dokumentasi Foto: rasio bukti fisik
        $stmtPhotos = $pdo->prepare("
            SELECT COUNT(p.id)
            FROM daily_activity_photos p
            INNER JOIN daily_activities a ON p.activity_id = a.id
            INNER JOIN daily_shifts s ON a.shift_id = s.id
            WHERE s.user_id = ? AND s.shift_date LIKE ?
        ");
        $stmtPhotos->execute([$userId, $month . '%']);
        $photosCount = (int)$stmtPhotos->fetchColumn();
        // Bobot Foto: 15 Poin. Rasio foto terhadap kegiatan
        $photoRatio = ($actsCount > 0) ? min(1.0, ($photosCount / $actsCount)) : 0.0;
        $photoScore = round($photoRatio * 15.0, 2);

        // 4. Partisipasi Inspeksi PM Bulanan
        $stmtUser = $pdo->prepare("SELECT username, full_name FROM users WHERE id = ?");
        $stmtUser->execute([$userId]);
        $uData = $stmtUser->fetch();
        $pmCount = 0;
        if ($uData) {
            $uName = $uData['username'];
            $fName = $uData['full_name'];
            $stmtPm = $pdo->prepare("
                SELECT COUNT(id) FROM pm_reports
                WHERE report_date LIKE ? AND (inspector_name LIKE ? OR inspector_name LIKE ?)
            ");
            $stmtPm->execute([$month . '%', '%' . $uName . '%', '%' . $fName . '%']);
            $pmCount = (int)$stmtPm->fetchColumn();
        }
        // Bobot PM: 10 Poin. 1 PM = 5 poin, 2 PM = 10 poin penuh
        $pmScore = min(10.0, round($pmCount * 5.0, 2));

        $systemScore = round($shiftScore + $activityScore + $photoScore + $pmScore, 2);

        return [
            'shifts_count' => $totShifts,
            'completed_shifts' => $compShifts,
            'shift_score' => $shiftScore,
            'activities_count' => $actsCount,
            'activity_score' => $activityScore,
            'photos_count' => $photosCount,
            'photo_score' => $photoScore,
            'pm_count' => $pmCount,
            'pm_score' => $pmScore,
            'system_score' => $systemScore
        ];
    }
}

// ==========================================
// LOAD DATA EVALUASI KPI
// ==========================================
if ($isAdmin) {
    // Admin melihat seluruh teknisi / anggota tim
    $stmtUsers = $pdo->query("
        SELECT id, username, full_name, role 
        FROM users 
        WHERE is_active = 1 
        ORDER BY CASE WHEN role = 'superadmin' THEN 1 WHEN role = 'admin' THEN 2 ELSE 3 END, full_name ASC
    ");
    $allUsers = $stmtUsers->fetchAll();

    // Ambil evaluasi tersimpan untuk periode ini
    $stmtSaved = $pdo->prepare("SELECT * FROM kpi_evaluations WHERE period_month = ?");
    $stmtSaved->execute([$filterMonth]);
    $savedEvals = [];
    while ($row = $stmtSaved->fetch()) {
        $savedEvals[(int)$row['user_id']] = $row;
    }

    // Bangun daftar lengkap skor KPI
    $kpiList = [];
    $totalScoresSum = 0;
    $gradeACount = 0;
    $publishedCount = 0;

    foreach ($allUsers as $u) {
        $uId = (int)$u['id'];
        $saved = $savedEvals[$uId] ?? null;
        $sysMetrics = calculate_user_system_kpi($pdo, $uId, $filterMonth);

        $sop = $saved ? (float)$saved['sop_score'] : 0.0;
        $trouble = $saved ? (float)$saved['troubleshooting_score'] : 0.0;
        $response = $saved ? (float)$saved['response_score'] : 0.0;
        $initiative = $saved ? (float)$saved['initiative_score'] : 0.0;
        $reviewScore = round($sop + $trouble + $response + $initiative, 2);

        $sysScore = $sysMetrics['system_score'];
        $totalScore = round($sysScore + $reviewScore, 2);

        if ($totalScore >= 90) $grade = 'A';
        elseif ($totalScore >= 80) $grade = 'B';
        elseif ($totalScore >= 70) $grade = 'C';
        else $grade = 'D';

        $status = $saved ? ($saved['status'] ?: 'draft') : 'draft';
        $publishedAt = $saved ? $saved['published_at'] : null;
        $notes = $saved ? ($saved['feedback_notes'] ?? '') : '';

        if ($status === 'published') $publishedCount++;
        if ($grade === 'A') $gradeACount++;
        $totalScoresSum += $totalScore;

        $kpiList[] = [
            'user' => $u,
            'system' => $sysMetrics,
            'review' => [
                'sop' => $sop,
                'troubleshooting' => $trouble,
                'response' => $response,
                'initiative' => $initiative,
                'total' => $reviewScore,
                'notes' => $notes
            ],
            'total_score' => $totalScore,
            'grade' => $grade,
            'status' => $status,
            'published_at' => $publishedAt
        ];
    }

    // Urutkan leaderboard berdasarkan total skor tertinggi
    usort($kpiList, function($a, $b) {
        return $b['total_score'] <=> $a['total_score'];
    });

    $avgScore = count($kpiList) > 0 ? round($totalScoresSum / count($kpiList), 1) : 0;

} else {
    // Teknisi biasa: Hanya melihat rapor miliknya sendiri
    $myId = (int)$currentUser['id'];
    $stmtMyEval = $pdo->prepare("SELECT * FROM kpi_evaluations WHERE user_id = ? AND period_month = ?");
    $stmtMyEval->execute([$myId, $filterMonth]);
    $mySaved = $stmtMyEval->fetch();

    $mySystemMetrics = calculate_user_system_kpi($pdo, $myId, $filterMonth);
    $myStatus = $mySaved ? ($mySaved['status'] ?: 'draft') : 'draft';
    $isPublishedToMe = ($myStatus === 'published');
}

$page_title = 'Penilaian Kinerja & KPI Tim - ' . $periodLabel;
include __DIR__ . '/includes/header.php';
?>

<div class="container py-4" style="max-width: 1200px;">

  <!-- Feedback Alert Banner -->
  <?php if (!empty($feedbackMsg)): ?>
    <div class="alert alert-<?= $feedbackType ?>" style="margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; gap: 10px; border-radius: var(--radius-md); padding: 0.85rem 1.25rem;">
      <div style="display: flex; align-items: center; gap: 8px;">
        <span><?= ($feedbackType === 'success') ? app_icon('check-circle', 18) : app_icon('alert-triangle', 18) ?></span>
        <span style="font-size: 0.9rem; font-weight: 600;"><?= htmlspecialchars($feedbackMsg) ?></span>
      </div>
      <button type="button" onclick="this.parentElement.style.display='none'" style="background: none; border: none; font-size: 1.2rem; cursor: pointer; color: inherit;">&times;</button>
    </div>
  <?php endif; ?>

  <!-- Header & Toolbar -->
  <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
    <div>
      <div style="display: flex; align-items: center; gap: 8px;">
        <span style="background: var(--primary-subtle); color: var(--primary); padding: 6px; border-radius: 8px; display: inline-flex;">
          <?= app_icon('award', 24) ?>
        </span>
        <div>
          <h1 style="font-size: 1.4rem; font-weight: 800; margin: 0; color: var(--text-main);">
            <?= $isAdmin ? 'Penilaian Kinerja &amp; KPI Tim NOC' : 'Rapor Kinerja KPI Saya' ?>
          </h1>
          <p style="font-size: 0.82rem; color: var(--text-subtle); margin: 2px 0 0 0;">
            Periode Evaluasi: <strong style="color: var(--primary);"><?= htmlspecialchars($periodLabel) ?></strong>
          </p>
        </div>
      </div>
    </div>

    <!-- Month Filter Form -->
    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
      <form method="GET" action="kpi" style="display: flex; align-items: center; gap: 6px;">
        <label for="month" style="font-size: 0.8rem; font-weight: 700; color: var(--text-subtle); margin: 0;">Periode:</label>
        <input type="month" id="month" name="month" class="form-control" value="<?= htmlspecialchars($filterMonth) ?>" onchange="this.form.submit()" style="min-height: 38px; width: 170px;">
      </form>

      <?php if ($isAdmin): ?>
        <form method="POST" action="" onsubmit="return confirm('Kirim seluruh rapor yang telah dievaluasi pada bulan ini ke akun masing-masing teknisi?');">
          <input type="hidden" name="action" value="publish_all">
          <button type="submit" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 700; min-height: 38px; font-size: 0.82rem;">
            <?= app_icon('send', 14) ?>
            <span>Kirim Semua Rapor Bulan Ini</span>
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Papan Ringkasan Eksekutif (Khusus Admin) -->
  <?php if ($isAdmin): ?>
    <div class="row g-3" style="display: flex; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1.5rem;">
      <div style="flex: 1; min-width: 200px; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <div style="font-size: 0.74rem; font-weight: 700; text-transform: uppercase; color: var(--text-subtle);">Rata-Rata Skor Tim</div>
        <div style="font-size: 1.6rem; font-weight: 800; color: var(--primary); margin-top: 4px;"><?= $avgScore ?> <span style="font-size: 0.85rem; font-weight: 600; color: var(--text-subtle);">/ 100</span></div>
        <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 2px;">Dari <?= count($kpiList) ?> personel aktif</div>
      </div>
      <div style="flex: 1; min-width: 200px; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <div style="font-size: 0.74rem; font-weight: 700; text-transform: uppercase; color: var(--text-subtle);">Predikat Unggul (A)</div>
        <div style="font-size: 1.6rem; font-weight: 800; color: #059669; margin-top: 4px;"><?= $gradeACount ?> Personel</div>
        <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 2px;">Skor 90 - 100 poin</div>
      </div>
      <div style="flex: 1; min-width: 200px; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <div style="font-size: 0.74rem; font-weight: 700; text-transform: uppercase; color: var(--text-subtle);">Status Rilis ke Teknisi</div>
        <div style="font-size: 1.6rem; font-weight: 800; color: #0284c7; margin-top: 4px;"><?= $publishedCount ?> <span style="font-size: 0.85rem; font-weight: 600; color: var(--text-subtle);">/ <?= count($kpiList) ?></span></div>
        <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 2px;">Rapor terkirim &amp; terbuka</div>
      </div>
      <div style="flex: 1; min-width: 200px; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <div style="font-size: 0.74rem; font-weight: 700; text-transform: uppercase; color: var(--text-subtle);">Komposisi Pembobotan</div>
        <div style="font-size: 1.15rem; font-weight: 800; color: var(--text-main); margin-top: 6px;">60% Sistem : 40% Review</div>
        <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 2px;">Hybrid objektif &amp; appraisal</div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Panel Transparansi: Sumber Data & Metodologi Penilaian (Bisa di-toggle / selalu transparan) -->
  <div class="card" style="margin-bottom: 1.5rem; border: 1px solid #cbd5e1; background: #ffffff;">
    <div class="card-header" style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1.25rem;">
      <div style="display: flex; align-items: center; gap: 8px;">
        <span style="color: var(--primary); display: inline-flex;"><?= app_icon('info', 18) ?></span>
        <h2 style="font-size: 0.95rem; font-weight: 800; color: var(--text-main); margin: 0; text-transform: uppercase; letter-spacing: 0.02em;">
          Transparansi &amp; Metodologi Penilaian KPI
        </h2>
      </div>
      <button type="button" class="btn btn-sm btn-secondary" onclick="toggleGuidePanel()" id="btnToggleGuide" style="font-size: 0.75rem; padding: 0.25rem 0.65rem; font-weight: 700;">
        Sembunyikan Panduan
      </button>
    </div>
    <div id="guidePanelContent" style="padding: 1.25rem;">
      <p style="font-size: 0.84rem; color: var(--text-subtle); margin-bottom: 1rem; line-height: 1.5;">
        Penilaian kinerja di lingkungan IASS SBU ICT Bandara Internasional Kualanamu menggunakan model <strong>Hybrid KPI Terpadu</strong>. Skor dihitung secara transparan dari data operasional riil di lapangan (bobot 60%) serta evaluasi kompetensi kualitatif oleh Supervisor / Team Leader NOC (bobot 40%).
      </p>

      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
        
        <!-- Pilar 1: Kehadiran Shift -->
        <div style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 0.85rem; background: #f8fafc;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
            <strong style="font-size: 0.82rem; color: var(--text-main);">1. Disiplin Kehadiran Shift</strong>
            <span style="background: rgba(14, 165, 233, 0.15); color: #0284c7; font-weight: 800; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px;">Bobot 15%</span>
          </div>
          <div style="font-size: 0.75rem; color: var(--text-subtle); line-height: 1.4;">
            <strong>Sumber:</strong> Tabel <code>daily_shifts</code><br>
            <strong>Rumus:</strong> <code>(Shift Selesai / Total Shift) &times; 15 Poin</code>.<br>
            Menilai kedisiplinan menyelesaikan shift tepat waktu dan ketertiban serah terima.
          </div>
        </div>

        <!-- Pilar 2: Logbook Harian -->
        <div style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 0.85rem; background: #f8fafc;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
            <strong style="font-size: 0.82rem; color: var(--text-main);">2. Volume Logbook Harian</strong>
            <span style="background: rgba(16, 185, 129, 0.15); color: #059669; font-weight: 800; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px;">Bobot 20%</span>
          </div>
          <div style="font-size: 0.75rem; color: var(--text-subtle); line-height: 1.4;">
            <strong>Sumber:</strong> Tabel <code>daily_activities</code><br>
            <strong>Rumus:</strong> <code>Min(20 Poin, Total Logbook &times; 0.8 Poin)</code>.<br>
            Benchmark 25 kegiatan logbook per bulan mencapai skor maksimal 20 poin.
          </div>
        </div>

        <!-- Pilar 3: Dokumentasi Foto -->
        <div style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 0.85rem; background: #f8fafc;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
            <strong style="font-size: 0.82rem; color: var(--text-main);">3. Bukti Fisik Lapangan</strong>
            <span style="background: rgba(139, 92, 246, 0.15); color: #7c3aed; font-weight: 800; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px;">Bobot 15%</span>
          </div>
          <div style="font-size: 0.75rem; color: var(--text-subtle); line-height: 1.4;">
            <strong>Sumber:</strong> Tabel <code>daily_activity_photos</code><br>
            <strong>Rumus:</strong> <code>(Total Foto / Total Kegiatan) &times; 15 Poin</code>.<br>
            Kepatuhan SOP fasilitas bandara yang mewajibkan dokumentasi visual pekerjaan.
          </div>
        </div>

        <!-- Pilar 4: Partisipasi PM -->
        <div style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 0.85rem; background: #f8fafc;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
            <strong style="font-size: 0.82rem; color: var(--text-main);">4. Partisipasi Inspeksi PM</strong>
            <span style="background: rgba(245, 158, 11, 0.15); color: #d97706; font-weight: 800; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px;">Bobot 10%</span>
          </div>
          <div style="font-size: 0.75rem; color: var(--text-subtle); line-height: 1.4;">
            <strong>Sumber:</strong> Tabel <code>pm_reports</code> (Indoor &amp; Outdoor)<br>
            <strong>Rumus:</strong> <code>Min(10 Poin, Dokumen PM &times; 5 Poin)</code>.<br>
            Inspeksi berkala pemeliharaan perangkat transmisi, radio, UPS, dan tower.
          </div>
        </div>

        <!-- Pilar 5: Review Leader -->
        <div style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 0.85rem; background: #f8fafc;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
            <strong style="font-size: 0.82rem; color: var(--text-main);">5. Review Leader / Supervisor</strong>
            <span style="background: rgba(239, 68, 68, 0.15); color: #dc2626; font-weight: 800; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px;">Bobot 40%</span>
          </div>
          <div style="font-size: 0.75rem; color: var(--text-subtle); line-height: 1.4;">
            <strong>Sumber:</strong> Evaluasi Kualitatif Bulanan (4 Aspek &times; 10 Poin):<br>
            &bull; SOP Bandara &amp; K3 (10) &bull; Troubleshooting Teknis (10)<br>
            &bull; Respon Insiden &amp; Komunikasi (10) &bull; Kerjasama Tim (10)
          </div>
        </div>

        <!-- Skala Predikat -->
        <div style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 0.85rem; background: #f8fafc;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
            <strong style="font-size: 0.82rem; color: var(--text-main);">Skala Predikat Akhir</strong>
            <span style="background: #e2e8f0; color: var(--text-main); font-weight: 800; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px;">Standar</span>
          </div>
          <div style="font-size: 0.75rem; color: var(--text-subtle); line-height: 1.4;">
            &bull; <strong style="color: #059669;">A (90 – 100)</strong>: Sangat Baik / Unggul<br>
            &bull; <strong style="color: #0284c7;">B (80 – 89.9)</strong>: Baik / Sesuai Standar SOP<br>
            &bull; <strong style="color: #d97706;">C (70 – 79.9)</strong>: Cukup / Perlu Peningkatan<br>
            &bull; <strong style="color: #dc2626;">D (&lt; 70)</strong>: Perlu Pembinaan Teknis
          </div>
        </div>

      </div>

      <div style="font-size: 0.75rem; color: var(--text-subtle); background: #f1f5f9; padding: 6px 10px; border-radius: 6px; border-left: 3px solid var(--primary);">
        <strong>Catatan Kebijakan Rilis:</strong> Rapor evaluasi kinerja teknisi bersifat privat dan <em>hanya akan muncul di akun teknisi jika Admin/Supervisor menekan tombol <strong>Kirim ke Teknisi</strong></em>. Jika status masih berupa Draft, akun teknisi tidak akan menampilkan data nilai apa pun demi menjaga kerahasiaan proses evaluasi.
      </div>
    </div>
  </div>

  <!-- ======================================================== -->
  <!-- TAMPILAN KHUSUS ADMIN & SUPERADMIN: LEADERBOARD & REVIEW -->
  <!-- ======================================================== -->
  <?php if ($isAdmin): ?>
    <div class="card" style="border: 1px solid #cbd5e1; background: #ffffff;">
      <div class="card-header" style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; padding: 0.85rem 1.25rem;">
        <div>
          <h2 style="font-size: 1rem; font-weight: 800; color: var(--text-main); margin: 0;">
            Papan Peringkat Kinerja Personel &bull; <?= htmlspecialchars($periodLabel) ?>
          </h2>
          <div style="font-size: 0.75rem; color: var(--text-subtle); margin-top: 2px;">
            Kelola penilaian kualitatif dan tentukan teknisi mana yang rapornya siap dirilis.
          </div>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table" style="width: 100%; border-collapse: collapse; margin-bottom: 0;">
          <thead>
            <tr style="background: #f1f5f9; border-bottom: 2px solid #cbd5e1; font-size: 0.75rem; text-transform: uppercase;">
              <th style="padding: 10px 12px; text-align: center; width: 45px;">Rank</th>
              <th style="padding: 10px 12px;">Personel / Teknisi</th>
              <th style="padding: 10px 12px; text-align: center; width: 100px;">Metrik Sistem (60%)</th>
              <th style="padding: 10px 12px; text-align: center; width: 100px;">Review Leader (40%)</th>
              <th style="padding: 10px 12px; text-align: center; width: 95px;">Total Skor</th>
              <th style="padding: 10px 12px; text-align: center; width: 85px;">Predikat</th>
              <th style="padding: 10px 12px; text-align: center; width: 130px;">Status Rapor</th>
              <th style="padding: 10px 12px; text-align: right; width: 220px;">Aksi Admin</th>
            </tr>
          </thead>
          <tbody>
            <?php 
            $rank = 1;
            foreach ($kpiList as $item): 
              $u = $item['user'];
              $sys = $item['system'];
              $rev = $item['review'];
              $tot = $item['total_score'];
              $grd = $item['grade'];
              $stat = $item['status'];
              $isPub = ($stat === 'published');

              $gradeColor = ($grd === 'A') ? '#059669' : (($grd === 'B') ? '#0284c7' : (($grd === 'C') ? '#d97706' : '#dc2626'));
              $gradeBg = ($grd === 'A') ? 'rgba(5, 150, 105, 0.12)' : (($grd === 'B') ? 'rgba(2, 132, 199, 0.12)' : (($grd === 'C') ? 'rgba(217, 119, 6, 0.12)' : 'rgba(220, 38, 38, 0.12)'));
            ?>
              <tr style="border-bottom: 1px solid #e2e8f0; font-size: 0.82rem;">
                <td style="padding: 10px 12px; text-align: center; font-weight: 800; color: var(--text-subtle);">
                  <?php if ($rank === 1): ?>
                    <span style="color: #d97706;" title="Peringkat 1"><?= app_icon('star', 16) ?> 1</span>
                  <?php else: ?>
                    <?= $rank ?>
                  <?php endif; ?>
                </td>
                <td style="padding: 10px 12px;">
                  <strong style="color: var(--text-main);"><?= htmlspecialchars($u['full_name'] ?: $u['username']) ?></strong>
                  <div style="font-size: 0.72rem; color: var(--text-subtle);">
                    @<?= htmlspecialchars($u['username']) ?> &bull; <span style="text-transform: uppercase;"><?= htmlspecialchars($u['role']) ?></span>
                  </div>
                </td>
                <td style="padding: 10px 12px; text-align: center;">
                  <span style="font-weight: 700; color: #008080;"><?= $sys['system_score'] ?></span>
                  <div style="font-size: 0.68rem; color: var(--text-subtle);">/ 60.0</div>
                </td>
                <td style="padding: 10px 12px; text-align: center;">
                  <span style="font-weight: 700; color: #6366f1;"><?= $rev['total'] ?></span>
                  <div style="font-size: 0.68rem; color: var(--text-subtle);">/ 40.0</div>
                </td>
                <td style="padding: 10px 12px; text-align: center;">
                  <strong style="font-size: 0.95rem; color: var(--text-main);"><?= $tot ?></strong>
                  <div style="font-size: 0.68rem; color: var(--text-subtle);">/ 100</div>
                </td>
                <td style="padding: 10px 12px; text-align: center;">
                  <span style="background: <?= $gradeBg ?>; color: <?= $gradeColor ?>; padding: 3px 8px; border-radius: 4px; font-weight: 800; font-size: 0.78rem;">
                    Grade <?= $grd ?>
                  </span>
                </td>
                <td style="padding: 10px 12px; text-align: center;">
                  <?php if ($isPub): ?>
                    <span style="background: rgba(16, 185, 129, 0.15); color: #059669; border: 1px solid rgba(16, 185, 129, 0.3); padding: 3px 8px; border-radius: 9999px; font-size: 0.72rem; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                      <?= app_icon('check-circle', 11) ?> Terkirim
                    </span>
                  <?php else: ?>
                    <span style="background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; padding: 3px 8px; border-radius: 9999px; font-size: 0.72rem; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                      <?= app_icon('lock', 11) ?> Draft Saja
                    </span>
                  <?php endif; ?>
                </td>
                <td style="padding: 10px 12px; text-align: right;">
                  <div style="display: inline-flex; align-items: center; gap: 4px;">
                    <!-- Tombol Form Nilai -->
                    <button type="button" class="btn btn-sm btn-secondary" onclick='openReviewModal(<?= json_encode($item) ?>)' style="padding: 0.25rem 0.55rem; font-size: 0.74rem; font-weight: 700;" title="Input atau ubah review kualitatif">
                      <?= app_icon('edit', 12) ?> Nilai
                    </button>

                    <!-- Tombol Toggle Publish / Tarik -->
                    <form method="POST" action="" style="display: inline;" onsubmit="return confirm('<?= $isPub ? 'Tarik rapor ini? Akun teknisi tidak akan dapat melihat rapor lagi.' : 'Kirim rapor ini ke akun teknisi?' ?>');">
                      <input type="hidden" name="action" value="toggle_publish">
                      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                      <input type="hidden" name="new_status" value="<?= $isPub ? 'draft' : 'published' ?>">
                      <?php if ($isPub): ?>
                        <button type="submit" class="btn btn-sm" style="background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; padding: 0.25rem 0.55rem; font-size: 0.74rem; font-weight: 700;" title="Tarik kembali rapor dari teknisi">
                          <?= app_icon('lock', 12) ?> Tarik
                        </button>
                      <?php else: ?>
                        <button type="submit" class="btn btn-sm" style="background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; padding: 0.25rem 0.55rem; font-size: 0.74rem; font-weight: 700;" title="Kirim rapor ini agar bisa dibaca oleh teknisi">
                          <?= app_icon('send', 12) ?> Kirim
                        </button>
                      <?php endif; ?>
                    </form>

                    <!-- Tombol Preview Rapor Detail -->
                    <button type="button" class="btn btn-sm btn-secondary" onclick='openScorecardModal(<?= json_encode($item) ?>)' style="padding: 0.25rem 0.55rem; font-size: 0.74rem;" title="Lihat kartu rapor lengkap">
                      <?= app_icon('eye', 12) ?>
                    </button>
                  </div>
                </td>
              </tr>
            <?php $rank++; endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  <!-- ======================================================== -->
  <!-- TAMPILAN KHUSUS TEKNISI (USER BIASA): RAPOR KINERJA SAYA -->
  <!-- ======================================================== -->
  <?php else: ?>
    
    <?php if (!$isPublishedToMe): ?>
      <!-- EMPTY STATE: KETIKA ADMIN BELUM KLIK KIRIM -->
      <div class="card text-center" style="padding: 3rem 1.5rem; border: 1px solid #cbd5e1; background: #ffffff;">
        <div style="font-size: 3rem; margin-bottom: 1rem; color: var(--primary);">
          <?= app_icon('clock', 56) ?>
        </div>
        <h3 style="font-size: 1.25rem; font-weight: 800; color: var(--text-main); margin-bottom: 0.5rem;">
          Evaluasi Kinerja Sedang Disiapkan
        </h3>
        <p style="color: var(--text-subtle); max-width: 520px; margin: 0 auto 1.5rem auto; font-size: 0.88rem; line-height: 1.5;">
          Rapor evaluasi KPI Anda untuk periode <strong><?= htmlspecialchars($periodLabel) ?></strong> saat ini sedang ditinjau oleh Supervisor / Team Leader NOC. Hasil penilaian resmi akan otomatis muncul di halaman ini setelah dikirim oleh atasan.
        </p>
        <div>
          <a href="./" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 700;">
            <?= app_icon('dashboard', 16) ?>
            <span>Kembali ke Dashboard</span>
          </a>
        </div>
      </div>

    <?php else: 
      // RAPOR SUDAH DIKIRIM OLEH ADMIN: TAMPILKAN KARTU RAPOR PENUH
      $myTot = (float)$mySaved['total_score'];
      $myGrd = $mySaved['grade'] ?: 'B';
      $mySysScore = (float)$mySaved['system_score'];
      $myRevScore = (float)$mySaved['review_score'];
      $myNotes = $mySaved['feedback_notes'] ?: 'Terima kasih atas kontribusi dan dedikasi Anda dalam operasional fasilitas telekomunikasi dan NOC Bandara Internasional Kualanamu.';

      $grdColor = ($myGrd === 'A') ? '#059669' : (($myGrd === 'B') ? '#0284c7' : (($myGrd === 'C') ? '#d97706' : '#dc2626'));
      $grdBg = ($myGrd === 'A') ? 'rgba(5, 150, 105, 0.12)' : (($myGrd === 'B') ? 'rgba(2, 132, 199, 0.12)' : (($myGrd === 'C') ? 'rgba(217, 119, 6, 0.12)' : 'rgba(220, 38, 38, 0.12)'));
    ?>
      <div class="card" style="border: 2px solid #008080; background: #ffffff; padding: 2rem; margin-bottom: 1.5rem; position: relative;">
        
        <!-- Header Rapor -->
        <div style="border-bottom: 2px solid var(--border); padding-bottom: 1.25rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
          <div>
            <div style="font-size: 0.75rem; font-weight: 800; color: #008080; text-transform: uppercase; letter-spacing: 0.05em;">
              LEMBAR EVALUASI &amp; RAPOR KINERJA BULANAN
            </div>
            <h2 style="font-size: 1.4rem; font-weight: 800; color: var(--text-main); margin: 4px 0;">
              <?= htmlspecialchars($currentUser['full_name'] ?: $currentUser['username']) ?>
            </h2>
            <div style="font-size: 0.8rem; color: var(--text-subtle);">
              ID: #<?= (int)$currentUser['id'] ?> &bull; Unit: IASS SBU ICT KNO &bull; Bandara Internasional Kualanamu
            </div>
          </div>

          <div style="text-align: right;">
            <div style="font-size: 0.75rem; color: var(--text-subtle);">Periode Evaluasi:</div>
            <div style="font-size: 1rem; font-weight: 800; color: var(--text-main);"><?= htmlspecialchars($periodLabel) ?></div>
            <span style="display: inline-block; margin-top: 4px; background: rgba(16, 185, 129, 0.15); color: #059669; font-weight: 800; font-size: 0.72rem; padding: 2px 8px; border-radius: 9999px;">
              <?= app_icon('check-circle', 11) ?> Resmi Dirilis
            </span>
          </div>
        </div>

        <!-- Skor Utama Hero Banner -->
        <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: var(--radius-md); padding: 1.5rem; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
          <div>
            <div style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: var(--text-subtle);">Total Skor Capaian KPI</div>
            <div style="display: flex; align-items: baseline; gap: 8px; margin-top: 4px;">
              <span style="font-size: 2.8rem; font-weight: 800; color: #008080; line-height: 1;"><?= $myTot ?></span>
              <span style="font-size: 1.1rem; font-weight: 600; color: var(--text-subtle);">/ 100 Poin</span>
            </div>
            <div style="font-size: 0.75rem; color: var(--text-subtle); margin-top: 4px;">
              Metrik Sistem: <strong><?= $mySysScore ?>/60</strong> &bull; Review Atasan: <strong><?= $myRevScore ?>/40</strong>
            </div>
          </div>

          <div style="text-align: center; background: <?= $grdBg ?>; border: 2px solid <?= $grdColor ?>; padding: 1rem 1.5rem; border-radius: 12px; min-width: 140px;">
            <div style="font-size: 0.72rem; font-weight: 800; text-transform: uppercase; color: <?= $grdColor ?>;">Predikat Resmi</div>
            <div style="font-size: 2.2rem; font-weight: 800; color: <?= $grdColor ?>; line-height: 1.1; margin: 2px 0;">
              GRADE <?= $myGrd ?>
            </div>
            <div style="font-size: 0.72rem; font-weight: 700; color: <?= $grdColor ?>;">
              <?= ($myGrd === 'A') ? 'Sangat Baik' : (($myGrd === 'B') ? 'Baik (Standar SOP)' : (($myGrd === 'C') ? 'Cukup' : 'Perlu Pembinaan')) ?>
            </div>
          </div>
        </div>

        <!-- Rincian 5 Metrik Indikator -->
        <h3 style="font-size: 0.95rem; font-weight: 800; color: var(--text-main); text-transform: uppercase; margin-bottom: 0.85rem; border-bottom: 1.5px solid var(--border); padding-bottom: 4px;">
          Rincian Capaian Indikator Kinerja
        </h3>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
          
          <div style="border: 1px solid var(--border); padding: 0.85rem; border-radius: 6px;">
            <div style="display: flex; justify-content: space-between; font-size: 0.82rem; font-weight: 700;">
              <span>1. Disiplin Kehadiran Shift</span>
              <strong style="color: #008080;"><?= (float)$mySaved['shift_score'] ?> / 15.0</strong>
            </div>
            <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 4px;">
              Total: <?= (int)$mySaved['completed_shifts'] ?> shift selesai dari <?= (int)$mySaved['shifts_count'] ?> jadwal shift.
            </div>
          </div>

          <div style="border: 1px solid var(--border); padding: 0.85rem; border-radius: 6px;">
            <div style="display: flex; justify-content: space-between; font-size: 0.82rem; font-weight: 700;">
              <span>2. Volume Logbook Harian</span>
              <strong style="color: #008080;"><?= (float)$mySaved['activity_score'] ?> / 20.0</strong>
            </div>
            <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 4px;">
              Total: <?= (int)$mySaved['activities_count'] ?> kegiatan logbook tercatat dan tertangani.
            </div>
          </div>

          <div style="border: 1px solid var(--border); padding: 0.85rem; border-radius: 6px;">
            <div style="display: flex; justify-content: space-between; font-size: 0.82rem; font-weight: 700;">
              <span>3. Dokumentasi Foto Fisik</span>
              <strong style="color: #008080;"><?= (float)$mySaved['photo_score'] ?> / 15.0</strong>
            </div>
            <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 4px;">
              Total: <?= (int)$mySaved['photos_count'] ?> foto bukti fisik kegiatan di lapangan.
            </div>
          </div>

          <div style="border: 1px solid var(--border); padding: 0.85rem; border-radius: 6px;">
            <div style="display: flex; justify-content: space-between; font-size: 0.82rem; font-weight: 700;">
              <span>4. Partisipasi PM Bulanan</span>
              <strong style="color: #008080;"><?= (float)$mySaved['pm_score'] ?> / 10.0</strong>
            </div>
            <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 4px;">
              Total: <?= (int)$mySaved['pm_count'] ?> dokumen inspeksi Preventive Maintenance (IBC/Tower).
            </div>
          </div>

          <div style="border: 1px solid var(--border); padding: 0.85rem; border-radius: 6px; grid-column: 1 / -1;">
            <div style="display: flex; justify-content: space-between; font-size: 0.82rem; font-weight: 700;">
              <span>5. Review Kualitatif Atasan</span>
              <strong style="color: #6366f1;"><?= (float)$mySaved['review_score'] ?> / 40.0</strong>
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 12px; margin-top: 6px; font-size: 0.74rem; color: var(--text-subtle);">
              <span>&bull; SOP Bandara: <strong><?= (float)$mySaved['sop_score'] ?>/10</strong></span>
              <span>&bull; Troubleshooting: <strong><?= (float)$mySaved['troubleshooting_score'] ?>/10</strong></span>
              <span>&bull; Respon &amp; Komunikasi: <strong><?= (float)$mySaved['response_score'] ?>/10</strong></span>
              <span>&bull; Inisiatif Tim: <strong><?= (float)$mySaved['initiative_score'] ?>/10</strong></span>
            </div>
          </div>

        </div>

        <!-- Catatan Masukan dari Supervisor -->
        <div style="background: #f8fafc; border-left: 4px solid #008080; padding: 1rem 1.25rem; border-radius: 4px; margin-bottom: 1.5rem;">
          <div style="font-size: 0.78rem; font-weight: 800; color: #008080; text-transform: uppercase;">
            Catatan / Rekomendasi Pengembangan dari Supervisor:
          </div>
          <p style="font-size: 0.84rem; color: var(--text-main); margin: 6px 0 0 0; line-height: 1.5; font-style: italic;">
            &ldquo;<?= nl2br(htmlspecialchars($myNotes)) ?>&rdquo;
          </p>
        </div>

        <!-- Tombol Cetak Rapor -->
        <div style="text-align: right;">
          <button type="button" class="btn btn-secondary" onclick="window.print()" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 700;">
            🖨️ Cetak Lembar Rapor Ini
          </button>
        </div>

      </div>
    <?php endif; ?>

  <?php endif; ?>

</div>

<!-- ======================================================== -->
<!-- MODAL: FORM PENILAIAN / REVIEW ATASAN (KHUSUS ADMIN)    -->
<!-- ======================================================== -->
<?php if ($isAdmin): ?>
<div id="modalReview" class="qr-modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
  <div class="card" style="max-width: 580px; width: 92%; margin: auto; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem; border-bottom: 1px solid var(--border);">
      <div>
        <h3 style="font-size: 1.05rem; font-weight: 800; margin: 0; color: var(--text-main);" id="modalRevTitle">
          Review Kinerja Personel
        </h3>
        <div style="font-size: 0.74rem; color: var(--text-subtle); margin-top: 2px;">
          Periode: <?= htmlspecialchars($periodLabel) ?>
        </div>
      </div>
      <button type="button" onclick="closeReviewModal()" style="background: none; border: none; font-size: 1.5rem; color: var(--text-subtle); cursor: pointer;">&times;</button>
    </div>

    <form method="POST" action="">
      <input type="hidden" name="action" value="save_review">
      <input type="hidden" name="user_id" id="revUserId" value="0">

      <div style="padding: 1.25rem;">
        
        <!-- Info Ringkas Metrik Sistem -->
        <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0.75rem 1rem; margin-bottom: 1.25rem; font-size: 0.78rem;">
          <div style="font-weight: 700; color: var(--text-main); margin-bottom: 4px;">Capaian Metrik Sistem (Otomatis 60%):</div>
          <div style="display: flex; justify-content: space-between; color: var(--text-subtle);">
            <span>Shift: <strong id="dispShiftScore" style="color: #008080;">0</strong>/15</span>
            <span>Logbook: <strong id="dispActScore" style="color: #008080;">0</strong>/20</span>
            <span>Foto: <strong id="dispPhotoScore" style="color: #008080;">0</strong>/15</span>
            <span>PM: <strong id="dispPmScore" style="color: #008080;">0</strong>/10</span>
          </div>
          <div style="margin-top: 4px; font-weight: 800; color: #008080; text-align: right;">
            Subtotal Sistem: <span id="dispSysTotal">0</span> / 60.0
          </div>
        </div>

        <div style="font-size: 0.82rem; font-weight: 800; color: var(--text-main); text-transform: uppercase; margin-bottom: 0.75rem;">
          Penilaian Kualitatif Supervisor (Maks 10 Poin per Aspek):
        </div>

        <!-- Aspek 1: SOP Bandara & K3 -->
        <div class="form-group" style="margin-bottom: 0.85rem;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
            <label class="form-label" style="font-size: 0.78rem; font-weight: 700; margin: 0;">1. Kepatuhan SOP Bandara &amp; K3</label>
            <span style="font-weight: 800; color: #6366f1; font-size: 0.82rem;" id="lblSop">0 / 10</span>
          </div>
          <input type="range" name="sop_score" id="inputSop" class="form-range" min="0" max="10" step="0.5" value="0" oninput="updateLiveTotal()" style="width: 100%;">
          <div style="font-size: 0.68rem; color: var(--text-subtle);">Izin kerja (security pass), penggunaan APD, dan disiplin area terbatas bandara.</div>
        </div>

        <!-- Aspek 2: Troubleshooting Teknis -->
        <div class="form-group" style="margin-bottom: 0.85rem;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
            <label class="form-label" style="font-size: 0.78rem; font-weight: 700; margin: 0;">2. Kualitas Troubleshooting Teknis</label>
            <span style="font-weight: 800; color: #6366f1; font-size: 0.82rem;" id="lblTrouble">0 / 10</span>
          </div>
          <input type="range" name="troubleshooting_score" id="inputTrouble" class="form-range" min="0" max="10" step="0.5" value="0" oninput="updateLiveTotal()" style="width: 100%;">
          <div style="font-size: 0.68rem; color: var(--text-subtle);">Ketepatan diagnosa gangguan link, perbaikan FO, dan pemecahan anomali sistem.</div>
        </div>

        <!-- Aspek 3: Respon Insiden & Komunikasi -->
        <div class="form-group" style="margin-bottom: 0.85rem;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
            <label class="form-label" style="font-size: 0.78rem; font-weight: 700; margin: 0;">3. Respon Insiden &amp; Komunikasi</label>
            <span style="font-weight: 800; color: #6366f1; font-size: 0.82rem;" id="lblResponse">0 / 10</span>
          </div>
          <input type="range" name="response_score" id="inputResponse" class="form-range" min="0" max="10" step="0.5" value="0" oninput="updateLiveTotal()" style="width: 100%;">
          <div style="font-size: 0.68rem; color: var(--text-subtle);">Kecepatan menindaklanjuti laporan stakeholder, komunikasi vendor telco, dan serah terima shift.</div>
        </div>

        <!-- Aspek 4: Kerjasama Tim & Inisiatif -->
        <div class="form-group" style="margin-bottom: 1rem;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
            <label class="form-label" style="font-size: 0.78rem; font-weight: 700; margin: 0;">4. Kerjasama Tim &amp; Inisiatif</label>
            <span style="font-weight: 800; color: #6366f1; font-size: 0.82rem;" id="lblInitiative">0 / 10</span>
          </div>
          <input type="range" name="initiative_score" id="inputInitiative" class="form-range" min="0" max="10" step="0.5" value="0" oninput="updateLiveTotal()" style="width: 100%;">
          <div style="font-size: 0.68rem; color: var(--text-subtle);">Kesigapan membantu rekan shift lain dan kepedulian terhadap kebersihan ruang NOC.</div>
        </div>

        <!-- Total Live Preview -->
        <div style="background: #f1f5f9; border-radius: 6px; padding: 0.65rem 1rem; display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
          <span style="font-size: 0.82rem; font-weight: 700; color: var(--text-main);">Proyeksi Total Skor &amp; Grade:</span>
          <div>
            <strong id="liveTotalScore" style="font-size: 1.1rem; color: #008080;">0</strong>
            <span style="font-size: 0.75rem; color: var(--text-subtle);">/ 100</span>
            <span id="liveGrade" style="margin-left: 6px; background: #008080; color: #ffffff; padding: 2px 6px; border-radius: 4px; font-weight: 800; font-size: 0.75rem;">C</span>
          </div>
        </div>

        <!-- Catatan Pembinaan -->
        <div class="form-group" style="margin-bottom: 1.25rem;">
          <label class="form-label" style="font-size: 0.78rem; font-weight: 700;">Catatan / Masukan Pengembangan untuk Teknisi</label>
          <textarea name="feedback_notes" id="inputNotes" class="form-control" rows="3" placeholder="Berikan catatan apresiasi atau poin peningkatan kinerja untuk teknisi ini..." style="font-size: 0.82rem;"></textarea>
        </div>

        <!-- Opsi Publikasi Langsung ke User -->
        <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 6px; padding: 0.75rem 1rem; display: flex; align-items: center; gap: 10px;">
          <input type="checkbox" name="send_to_user" id="checkSendUser" value="1" style="width: 18px; height: 18px; cursor: pointer;">
          <label for="checkSendUser" style="margin: 0; font-size: 0.82rem; font-weight: 700; color: #065f46; cursor: pointer;">
            🚀 Langsung Kirim Rapor ke Akun Teknisi Ini
            <div style="font-size: 0.72rem; font-weight: 400; color: #047857;">Jika tidak dicentang, hasil evaluasi akan disimpan sebagai Draft internal (teknisi belum bisa melihat).</div>
          </label>
        </div>

      </div>

      <div class="card-header" style="display: flex; justify-content: flex-end; gap: 8px; border-top: 1px solid var(--border); padding: 0.85rem 1.25rem;">
        <button type="button" class="btn btn-secondary" onclick="closeReviewModal()" style="font-weight: 700; font-size: 0.82rem;">Batal</button>
        <button type="submit" class="btn btn-primary" style="font-weight: 800; font-size: 0.82rem;">💾 Simpan Hasil Evaluasi</button>
      </div>
    </form>
  </div>
</div>

<!-- ======================================================== -->
<!-- MODAL: KARTU RAPOR DETAIL (SCORECARD MODAL)             -->
<!-- ======================================================== -->
<div id="modalScorecard" class="qr-modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
  <div class="card" style="max-width: 580px; width: 92%; margin: auto; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.2); padding: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1.5px solid var(--border); padding-bottom: 0.85rem; margin-bottom: 1rem;">
      <div>
        <div style="font-size: 0.72rem; font-weight: 800; color: #008080; text-transform: uppercase;">KARTU RAPOR EVALUASI KPI</div>
        <h3 style="font-size: 1.15rem; font-weight: 800; margin: 2px 0; color: var(--text-main);" id="scName">-</h3>
        <div style="font-size: 0.72rem; color: var(--text-subtle);">Periode: <?= htmlspecialchars($periodLabel) ?></div>
      </div>
      <button type="button" onclick="closeScorecardModal()" style="background: none; border: none; font-size: 1.5rem; color: var(--text-subtle); cursor: pointer;">&times;</button>
    </div>

    <!-- Hero Score -->
    <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 1rem; display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
      <div>
        <div style="font-size: 0.74rem; font-weight: 700; color: var(--text-subtle);">TOTAL SKOR CAPAIAN</div>
        <div style="font-size: 2.2rem; font-weight: 800; color: #008080; line-height: 1.1;" id="scTotalScore">0</div>
        <div style="font-size: 0.72rem; color: var(--text-subtle);">Metrik Sistem + Review Atasan</div>
      </div>
      <div style="text-align: center; border: 2px solid #008080; border-radius: 8px; padding: 0.65rem 1rem; min-width: 100px;">
        <div style="font-size: 0.68rem; font-weight: 800; text-transform: uppercase; color: #008080;">PREDIKAT</div>
        <div style="font-size: 1.6rem; font-weight: 800; color: #008080;" id="scGrade">C</div>
      </div>
    </div>

    <!-- Breakdown Details -->
    <div style="font-size: 0.8rem; margin-bottom: 1rem;">
      <div style="font-weight: 800; color: var(--text-main); margin-bottom: 6px; text-transform: uppercase; font-size: 0.75rem;">1. Rincian Metrik Sistem (Maks 60 Poin):</div>
      <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 0.65rem; display: flex; flex-direction: column; gap: 4px;">
        <div style="display: flex; justify-content: space-between;">
          <span>&bull; Kehadiran Shift (<span id="scShifts">-</span>):</span>
          <strong id="scShiftScore" style="color: #008080;">0</strong>
        </div>
        <div style="display: flex; justify-content: space-between;">
          <span>&bull; Volume Logbook (<span id="scActs">-</span>):</span>
          <strong id="scActScore" style="color: #008080;">0</strong>
        </div>
        <div style="display: flex; justify-content: space-between;">
          <span>&bull; Foto Dokumentasi (<span id="scPhotos">-</span>):</span>
          <strong id="scPhotoScore" style="color: #008080;">0</strong>
        </div>
        <div style="display: flex; justify-content: space-between;">
          <span>&bull; Partisipasi PM (<span id="scPm">-</span>):</span>
          <strong id="scPmScore" style="color: #008080;">0</strong>
        </div>
      </div>
    </div>

    <div style="font-size: 0.8rem; margin-bottom: 1rem;">
      <div style="font-weight: 800; color: var(--text-main); margin-bottom: 6px; text-transform: uppercase; font-size: 0.75rem;">2. Rincian Review Atasan (Maks 40 Poin):</div>
      <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 0.65rem; display: flex; flex-direction: column; gap: 4px;">
        <div style="display: flex; justify-content: space-between;">
          <span>&bull; SOP Bandara &amp; K3:</span>
          <strong id="scSop" style="color: #6366f1;">0</strong>
        </div>
        <div style="display: flex; justify-content: space-between;">
          <span>&bull; Troubleshooting Teknis:</span>
          <strong id="scTrouble" style="color: #6366f1;">0</strong>
        </div>
        <div style="display: flex; justify-content: space-between;">
          <span>&bull; Respon &amp; Komunikasi:</span>
          <strong id="scResponse" style="color: #6366f1;">0</strong>
        </div>
        <div style="display: flex; justify-content: space-between;">
          <span>&bull; Inisiatif Tim:</span>
          <strong id="scInitiative" style="color: #6366f1;">0</strong>
        </div>
      </div>
    </div>

    <!-- Catatan -->
    <div style="background: #f1f5f9; border-left: 3px solid #008080; padding: 0.75rem; border-radius: 4px; font-size: 0.78rem; margin-bottom: 1.25rem;">
      <strong>Catatan Supervisor:</strong>
      <div id="scNotes" style="font-style: italic; margin-top: 3px; color: var(--text-main);">-</div>
    </div>

    <div style="text-align: right;">
      <button type="button" class="btn btn-secondary" onclick="closeScorecardModal()" style="font-size: 0.8rem; font-weight: 700;">Tutup</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function toggleGuidePanel() {
  const pnl = document.getElementById('guidePanelContent');
  const btn = document.getElementById('btnToggleGuide');
  if (pnl.style.display === 'none') {
    pnl.style.display = 'block';
    btn.textContent = 'Sembunyikan Panduan';
  } else {
    pnl.style.display = 'none';
    btn.textContent = 'Buka Panduan & Rumus';
  }
}

<?php if ($isAdmin): ?>
let curSysScore = 0;

function openReviewModal(item) {
  document.getElementById('revUserId').value = item.user.id;
  document.getElementById('modalRevTitle').textContent = 'Review Kinerja: ' + (item.user.full_name || item.user.username);
  
  curSysScore = parseFloat(item.system.system_score) || 0;
  document.getElementById('dispShiftScore').textContent = item.system.shift_score;
  document.getElementById('dispActScore').textContent = item.system.activity_score;
  document.getElementById('dispPhotoScore').textContent = item.system.photo_score;
  document.getElementById('dispPmScore').textContent = item.system.pm_score;
  document.getElementById('dispSysTotal').textContent = item.system.system_score;

  document.getElementById('inputSop').value = item.review.sop || 0;
  document.getElementById('inputTrouble').value = item.review.troubleshooting || 0;
  document.getElementById('inputResponse').value = item.review.response || 0;
  document.getElementById('inputInitiative').value = item.review.initiative || 0;
  document.getElementById('inputNotes').value = item.review.notes || '';

  document.getElementById('checkSendUser').checked = (item.status === 'published');

  updateLiveTotal();
  document.getElementById('modalReview').style.display = 'flex';
}

function closeReviewModal() {
  document.getElementById('modalReview').style.display = 'none';
}

function updateLiveTotal() {
  const s1 = parseFloat(document.getElementById('inputSop').value) || 0;
  const s2 = parseFloat(document.getElementById('inputTrouble').value) || 0;
  const s3 = parseFloat(document.getElementById('inputResponse').value) || 0;
  const s4 = parseFloat(document.getElementById('inputInitiative').value) || 0;

  document.getElementById('lblSop').textContent = s1.toFixed(1) + ' / 10';
  document.getElementById('lblTrouble').textContent = s2.toFixed(1) + ' / 10';
  document.getElementById('lblResponse').textContent = s3.toFixed(1) + ' / 10';
  document.getElementById('lblInitiative').textContent = s4.toFixed(1) + ' / 10';

  const revTotal = s1 + s2 + s3 + s4;
  const grandTotal = Math.min(100, Math.round((curSysScore + revTotal) * 10) / 10);
  document.getElementById('liveTotalScore').textContent = grandTotal.toFixed(1);

  let gr = 'D';
  if (grandTotal >= 90) gr = 'A';
  else if (grandTotal >= 80) gr = 'B';
  else if (grandTotal >= 70) gr = 'C';
  document.getElementById('liveGrade').textContent = gr;
}

function openScorecardModal(item) {
  document.getElementById('scName').textContent = (item.user.full_name || item.user.username);
  document.getElementById('scTotalScore').textContent = item.total_score;
  document.getElementById('scGrade').textContent = item.grade;

  document.getElementById('scShifts').textContent = item.system.completed_shifts + '/' + item.system.shifts_count + ' shift';
  document.getElementById('scShiftScore').textContent = item.system.shift_score + ' / 15';

  document.getElementById('scActs').textContent = item.system.activities_count + ' logbook';
  document.getElementById('scActScore').textContent = item.system.activity_score + ' / 20';

  document.getElementById('scPhotos').textContent = item.system.photos_count + ' foto';
  document.getElementById('scPhotoScore').textContent = item.system.photo_score + ' / 15';

  document.getElementById('scPm').textContent = item.system.pm_count + ' site';
  document.getElementById('scPmScore').textContent = item.system.pm_score + ' / 10';

  document.getElementById('scSop').textContent = (item.review.sop || 0) + ' / 10';
  document.getElementById('scTrouble').textContent = (item.review.troubleshooting || 0) + ' / 10';
  document.getElementById('scResponse').textContent = (item.review.response || 0) + ' / 10';
  document.getElementById('scInitiative').textContent = (item.review.initiative || 0) + ' / 10';

  document.getElementById('scNotes').textContent = item.review.notes || 'Belum ada catatan khusus dari supervisor.';

  document.getElementById('modalScorecard').style.display = 'flex';
}

function closeScorecardModal() {
  document.getElementById('modalScorecard').style.display = 'none';
}
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
