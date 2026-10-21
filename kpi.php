<?php
/**
 * Penilaian Kinerja & KPI Tahunan Tim NOC
 * PT Angkasa Pura Sarana Digital & IASS SBU ICT KNO
 * 
 * Fitur:
 * - Periode Tahunan (Annual Evaluation): Akumulasi shift, logbook, foto, dan PM setahun penuh
 * - Kalkulasi Otomatis Sistem + Opsi Pengisian / Override Manual Penuh oleh Superadmin
 * - Fleksibilitas PM: Nilai PM bisa disesuaikan manual agar adil terhadap rotasi jadwal
 * - Kontrol Rilis (Kirim ke Teknisi): Rapor hanya muncul jika admin klik 'Kirim ke Teknisi'
 * - Lembar Cetak Dokumen Resmi A4 Terintegrasi (print_kpi.php)
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/audit.php';
require_login();

$pdo = get_db_connection();
$currentUser = current_user();
$isAdmin = has_role(['superadmin', 'admin']);

// Filter Periode Tahun
$currentYear = (int)date('Y');
$filterYear = (int)($_GET['year'] ?? $currentYear);
if ($filterYear < 2020 || $filterYear > 2035) {
    $filterYear = $currentYear;
}
$periodYearStr = (string)$filterYear;
$periodLabel = 'Tahun Anggaran ' . $filterYear;

$feedbackMsg = '';
$feedbackType = '';

// ==========================================
// FUNGSI KALKULASI METRIK SISTEM TAHUNAN
// ==========================================
if (!function_exists('calculate_user_annual_kpi')) {
    function calculate_user_annual_kpi(PDO $pdo, int $userId, int $year): array {
        $yearStr = (string)$year;

        // 1. Shift tahunan
        $stmtShift = $pdo->prepare("
            SELECT COUNT(id) AS total_shifts,
                   SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_shifts
            FROM daily_shifts
            WHERE user_id = ? AND shift_date LIKE ?
        ");
        $stmtShift->execute([$userId, $yearStr . '%']);
        $shiftData = $stmtShift->fetch() ?: ['total_shifts' => 0, 'completed_shifts' => 0];
        $totShifts = (int)$shiftData['total_shifts'];
        $compShifts = (int)$shiftData['completed_shifts'];
        $shiftScore = ($totShifts > 0) ? min(15.0, round(($compShifts / $totShifts) * 15.0, 2)) : 0.0;

        // 2. Logbook tahunan (Benchmark 120 logbook setahun = 20 poin penuh)
        $stmtAct = $pdo->prepare("
            SELECT COUNT(a.id) 
            FROM daily_activities a
            INNER JOIN daily_shifts s ON a.shift_id = s.id
            WHERE s.user_id = ? AND s.shift_date LIKE ?
        ");
        $stmtAct->execute([$userId, $yearStr . '%']);
        $actsCount = (int)$stmtAct->fetchColumn();
        $activityScore = min(20.0, round(($actsCount / 120.0) * 20.0, 2));

        // 3. Foto bukti fisik
        $stmtPhotos = $pdo->prepare("
            SELECT COUNT(p.id)
            FROM daily_activity_photos p
            INNER JOIN daily_activities a ON p.activity_id = a.id
            INNER JOIN daily_shifts s ON a.shift_id = s.id
            WHERE s.user_id = ? AND s.shift_date LIKE ?
        ");
        $stmtPhotos->execute([$userId, $yearStr . '%']);
        $photosCount = (int)$stmtPhotos->fetchColumn();
        $photoRatio = ($actsCount > 0) ? min(1.0, ($photosCount / $actsCount)) : 0.0;
        $photoScore = round($photoRatio * 15.0, 2);

        // 4. Partisipasi PM tahunan (Target 4 dokumen PM dalam setahun = 10 poin penuh)
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
            $stmtPm->execute([$yearStr . '%', '%' . $uName . '%', '%' . $fName . '%']);
            $pmCount = (int)$stmtPm->fetchColumn();
        }
        $pmScore = min(10.0, round($pmCount * 2.5, 2));

        $sysScore = round($shiftScore + $activityScore + $photoScore + $pmScore, 2);

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
            'system_score' => $sysScore
        ];
    }
}

if (!function_exists('calculate_user_system_kpi')) {
    /**
     * Backward-compatible alias for calculate_user_annual_kpi
     */
    function calculate_user_system_kpi(PDO $pdo, int $userId, $period = null): array {
        $year = is_numeric($period) ? (int)$period : (int)substr((string)($period ?: date('Y')), 0, 4);
        return calculate_user_annual_kpi($pdo, $userId, $year);
    }
}

// ==========================================
// HANDLE ACTIONS (ADMIN & SUPERADMIN ONLY)
// ==========================================
if ($isAdmin && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. SIMPAN REVIEW PENILAIAN (DENGAN MANUAL OVERRIDE FLEKSIBEL)
    if ($action === 'save_review') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        
        // Skor Sistem (Bisa manual override oleh Superadmin)
        $shiftScore = max(0, min(15, (float)($_POST['shift_score'] ?? 0)));
        $actScore = max(0, min(20, (float)($_POST['activity_score'] ?? 0)));
        $photoScore = max(0, min(15, (float)($_POST['photo_score'] ?? 0)));
        $pmScore = max(0, min(10, (float)($_POST['pm_score'] ?? 0)));
        $sysScore = round($shiftScore + $actScore + $photoScore + $pmScore, 2);

        // Skor Kualitatif Leader (Maks 10 Poin per aspek)
        $sopScore = max(0, min(10, (float)($_POST['sop_score'] ?? 10)));
        $troubleScore = max(0, min(10, (float)($_POST['troubleshooting_score'] ?? 10)));
        $responseScore = max(0, min(10, (float)($_POST['response_score'] ?? 10)));
        $initiativeScore = max(0, min(10, (float)($_POST['initiative_score'] ?? 10)));
        $notes = trim($_POST['feedback_notes'] ?? '');
        $sendToUser = !empty($_POST['send_to_user']);

        $reviewScore = round($sopScore + $troubleScore + $responseScore + $initiativeScore, 2);
        $totalScore = round($sysScore + $reviewScore, 2);

        if ($totalScore >= 90) $grade = 'A';
        elseif ($totalScore >= 80) $grade = 'B';
        elseif ($totalScore >= 70) $grade = 'C';
        else $grade = 'D';

        $status = $sendToUser ? 'published' : 'draft';
        $publishedAt = $sendToUser ? date('Y-m-d H:i:s') : null;
        $publishedBy = $sendToUser ? (int)$currentUser['id'] : null;

        $autoSys = calculate_user_annual_kpi($pdo, $targetUserId, $filterYear);

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmtUpsert = $pdo->prepare("
                INSERT INTO kpi_evaluations (
                    user_id, period_year, shifts_count, completed_shifts, shift_score,
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
                ON CONFLICT(user_id, period_year) DO UPDATE SET
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
        } else {
            $stmtUpsert = $pdo->prepare("
                INSERT INTO kpi_evaluations (
                    user_id, period_year, shifts_count, completed_shifts, shift_score,
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
        }

        try {
            $stmtUpsert->execute([
                $targetUserId, $periodYearStr,
                $autoSys['shifts_count'], $autoSys['completed_shifts'], $shiftScore,
                $autoSys['activities_count'], $actScore,
                $autoSys['photos_count'], $photoScore,
                $autoSys['pm_count'], $pmScore,
                $sysScore, $sopScore, $troubleScore, $responseScore, $initiativeScore,
                $reviewScore, $totalScore, $grade, $notes, $status, $publishedAt, $publishedBy
            ]);

            log_audit_event('KPI', 'SAVE_REVIEW_ANNUAL', "Simpan penilaian KPI tahunan user ID {$targetUserId} tahun {$filterYear} (Skor: {$totalScore}, Status: {$status})", 'user', $targetUserId);
            $feedbackMsg = $sendToUser ? 'Evaluasi KPI tahunan berhasil disimpan dan LANGSUNG DIKIRIM ke teknisi!' : 'Evaluasi KPI tahunan berhasil disimpan sebagai DRAFT internal.';
            $feedbackType = 'success';
        } catch (Exception $e) {
            $feedbackMsg = 'Gagal menyimpan evaluasi KPI: ' . $e->getMessage();
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

        $autoSys = calculate_user_annual_kpi($pdo, $targetUserId, $filterYear);
        $defSys = $autoSys['system_score'];
        $defRev = 40.0;
        $defTot = round($defSys + $defRev, 2);
        $defGrd = ($defTot >= 90 ? 'A' : ($defTot >= 80 ? 'B' : ($defTot >= 70 ? 'C' : 'D')));

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmtToggle = $pdo->prepare("
                INSERT INTO kpi_evaluations (
                    user_id, period_year, shifts_count, completed_shifts, shift_score,
                    activities_count, activity_score, photos_count, photo_score,
                    pm_count, pm_score, system_score, review_score, total_score, grade, status, published_at, published_by, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now', 'localtime')
                )
                ON CONFLICT(user_id, period_year) DO UPDATE SET
                    status = excluded.status,
                    published_at = excluded.published_at,
                    published_by = excluded.published_by,
                    updated_at = datetime('now', 'localtime')
            ");
        } else {
            $stmtToggle = $pdo->prepare("
                INSERT INTO kpi_evaluations (
                    user_id, period_year, shifts_count, completed_shifts, shift_score,
                    activities_count, activity_score, photos_count, photo_score,
                    pm_count, pm_score, system_score, review_score, total_score, grade, status, published_at, published_by, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
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
                $targetUserId, $periodYearStr,
                $autoSys['shifts_count'], $autoSys['completed_shifts'], $autoSys['shift_score'],
                $autoSys['activities_count'], $autoSys['activity_score'],
                $autoSys['photos_count'], $autoSys['photo_score'],
                $autoSys['pm_count'], $autoSys['pm_score'],
                $defSys, $defRev, $defTot, $defGrd, $newStatus, $pubAt, $pubBy
            ]);

            log_audit_event('KPI', 'TOGGLE_STATUS_ANNUAL', "Ubah status rapor KPI tahunan user ID {$targetUserId} tahun {$filterYear} menjadi {$newStatus}", 'user', $targetUserId);
            $feedbackMsg = $isPublished ? 'Rapor KPI tahunan berhasil DIKIRIM ke akun teknisi!' : 'Rapor KPI tahunan berhasil DITARIK kembali (kini berstatus Draft).';
            $feedbackType = 'success';
        } catch (Exception $e) {
            $feedbackMsg = 'Gagal mengubah status rapor: ' . $e->getMessage();
            $feedbackType = 'danger';
        }
    }

    // 3. PUBLISH SEMUA RAPOR TAHUNAN
    elseif ($action === 'publish_all') {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $nowSql = ($driver === 'sqlite') ? "datetime('now', 'localtime')" : "NOW()";

            $stmtPubAll = $pdo->prepare("
                UPDATE kpi_evaluations 
                SET status = 'published', published_at = {$nowSql}, published_by = ?, updated_at = {$nowSql}
                WHERE period_year = ?
            ");
            $stmtPubAll->execute([(int)$currentUser['id'], $periodYearStr]);
            log_audit_event('KPI', 'PUBLISH_ALL_ANNUAL', "Rilis seluruh rapor KPI tahun {$filterYear} ke seluruh anggota tim", 'system', null);
            $feedbackMsg = 'Seluruh rapor evaluasi KPI tahun ' . $filterYear . ' berhasil dikirim ke teknisi!';
            $feedbackType = 'success';
        } catch (Exception $e) {
            $feedbackMsg = 'Gagal merilis rapor: ' . $e->getMessage();
            $feedbackType = 'danger';
        }
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

    $stmtSaved = $pdo->prepare("SELECT * FROM kpi_evaluations WHERE period_year = ?");
    $stmtSaved->execute([$periodYearStr]);
    $savedEvals = [];
    while ($row = $stmtSaved->fetch()) {
        $savedEvals[(int)$row['user_id']] = $row;
    }

    $kpiList = [];
    $totalScoresSum = 0;
    $gradeACount = 0;
    $publishedCount = 0;

    foreach ($allUsers as $u) {
        $uId = (int)$u['id'];
        $saved = $savedEvals[$uId] ?? null;
        $auto = calculate_user_annual_kpi($pdo, $uId, $filterYear);

        // Jika ada nilai override tersimpan, gunakan nilai tersimpan; jika tidak, gunakan auto sistem
        $shiftScore = $saved ? (float)$saved['shift_score'] : $auto['shift_score'];
        $actScore = $saved ? (float)$saved['activity_score'] : $auto['activity_score'];
        $photoScore = $saved ? (float)$saved['photo_score'] : $auto['photo_score'];
        $pmScore = $saved ? (float)$saved['pm_score'] : $auto['pm_score'];
        $sysScore = round($shiftScore + $actScore + $photoScore + $pmScore, 2);

        // Aspek kualitatif: Default otomatis 10 per aspek (total 40) jika belum diedit
        $sop = $saved ? (float)$saved['sop_score'] : 10.0;
        $trouble = $saved ? (float)$saved['troubleshooting_score'] : 10.0;
        $response = $saved ? (float)$saved['response_score'] : 10.0;
        $initiative = $saved ? (float)$saved['initiative_score'] : 10.0;
        $reviewScore = round($sop + $trouble + $response + $initiative, 2);

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
            'auto_system' => $auto,
            'system' => [
                'shift_score' => $shiftScore,
                'activity_score' => $actScore,
                'photo_score' => $photoScore,
                'pm_score' => $pmScore,
                'system_score' => $sysScore,
                'shifts_count' => $auto['shifts_count'],
                'completed_shifts' => $auto['completed_shifts'],
                'activities_count' => $auto['activities_count'],
                'photos_count' => $auto['photos_count'],
                'pm_count' => $auto['pm_count']
            ],
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

    usort($kpiList, function($a, $b) {
        return $b['total_score'] <=> $a['total_score'];
    });

    $avgScore = count($kpiList) > 0 ? round($totalScoresSum / count($kpiList), 1) : 0;

} else {
    // Teknisi biasa: Hanya melihat rapor tahunannya sendiri
    $myId = (int)$currentUser['id'];
    $stmtMyEval = $pdo->prepare("SELECT * FROM kpi_evaluations WHERE user_id = ? AND period_year = ?");
    $stmtMyEval->execute([$myId, $periodYearStr]);
    $mySaved = $stmtMyEval->fetch();

    $myAuto = calculate_user_annual_kpi($pdo, $myId, $filterYear);
    $myStatus = $mySaved ? ($mySaved['status'] ?: 'draft') : 'draft';
    $isPublishedToMe = ($myStatus === 'published');
}

$page_title = 'Penilaian Kinerja & KPI Tahunan - ' . $filterYear;
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
            <?= $isAdmin ? 'Penilaian Kinerja &amp; KPI Tahunan NOC' : 'Rapor Kinerja KPI Tahunan Saya' ?>
          </h1>
          <p style="font-size: 0.82rem; color: var(--text-subtle); margin: 2px 0 0 0;">
            Periode Evaluasi: <strong style="color: var(--primary);"><?= htmlspecialchars($periodLabel) ?></strong>
          </p>
        </div>
      </div>
    </div>

    <!-- Year Filter & Print Actions -->
    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
      <form method="GET" action="kpi" style="display: flex; align-items: center; gap: 6px;">
        <label for="year" style="font-size: 0.8rem; font-weight: 700; color: var(--text-subtle); margin: 0;">Tahun:</label>
        <select id="year" name="year" class="form-control" onchange="this.form.submit()" style="min-height: 38px; width: 110px; font-weight: 700;">
          <?php for ($y = date('Y') + 1; $y >= 2023; $y--): ?>
            <option value="<?= $y ?>" <?= ($filterYear === $y) ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </form>

      <?php if ($isAdmin): ?>
        <!-- Tombol Cetak Rekap A4 Tim -->
        <a href="print_kpi?year=<?= $filterYear ?>" target="_blank" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 700; min-height: 38px; font-size: 0.82rem;" title="Buka lembar cetak A4 rekapitulasi KPI tahunan seluruh tim">
          🖨️ Cetak Rekap Tim (A4)
        </a>

        <!-- Tombol Kirim Semua -->
        <form method="POST" action="" onsubmit="return confirm('Kirim seluruh rapor evaluasi KPI tahun <?= $filterYear ?> ke akun masing-masing teknisi?');">
          <input type="hidden" name="action" value="publish_all">
          <button type="submit" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 700; min-height: 38px; font-size: 0.82rem;">
            <?= app_icon('send', 14) ?>
            <span>Kirim Semua Rapor Tahun Ini</span>
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Papan Ringkasan Eksekutif (Khusus Admin) -->
  <?php if ($isAdmin): ?>
    <div class="row g-3" style="display: flex; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1.5rem;">
      <div style="flex: 1; min-width: 200px; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <div style="font-size: 0.74rem; font-weight: 700; text-transform: uppercase; color: var(--text-subtle);">Rata-Rata Skor Tahunan</div>
        <div style="font-size: 1.6rem; font-weight: 800; color: var(--primary); margin-top: 4px;"><?= $avgScore ?> <span style="font-size: 0.85rem; font-weight: 600; color: var(--text-subtle);">/ 100</span></div>
        <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 2px;">Akumulasi tahun <?= $filterYear ?></div>
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
        <div style="font-size: 0.74rem; font-weight: 700; text-transform: uppercase; color: var(--text-subtle);">Fleksibilitas Penilaian</div>
        <div style="font-size: 1.15rem; font-weight: 800; color: #059669; margin-top: 6px;">Kalkulasi + Override Manual</div>
        <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 2px;">Superadmin dapat mengisi manual</div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Panel Transparansi: Sumber Data & Metodologi Penilaian Tahunan -->
  <div class="card" style="margin-bottom: 1.5rem; border: 1px solid #cbd5e1; background: #ffffff;">
    <div class="card-header" style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1.25rem;">
      <div style="display: flex; align-items: center; gap: 8px;">
        <span style="color: var(--primary); display: inline-flex;"><?= app_icon('info', 18) ?></span>
        <h2 style="font-size: 0.95rem; font-weight: 800; color: var(--text-main); margin: 0; text-transform: uppercase; letter-spacing: 0.02em;">
          Panduan &amp; Sumber Data Penilaian KPI Tahunan
        </h2>
      </div>
      <button type="button" class="btn btn-sm btn-secondary" onclick="toggleGuidePanel()" id="btnToggleGuide" style="font-size: 0.75rem; padding: 0.25rem 0.65rem; font-weight: 700;">
        Sembunyikan Panduan
      </button>
    </div>
    <div id="guidePanelContent" style="padding: 1.25rem;">
      <p style="font-size: 0.84rem; color: var(--text-subtle); margin-bottom: 1rem; line-height: 1.5;">
        Penilaian KPI dihitung secara <strong>Tahunan (Tahun Anggaran <?= $filterYear ?>)</strong>. Sistem secara otomatis menghitung akumulasi seluruh data riil dari 12 bulan operasional. Namun, untuk menjaga keadilan penugasan (seperti rotasi jadwal PM dan kondisi darurat), <strong>Superadmin memiliki wewenang penuh untuk menyesuaikan / mengisi nilai secara manual</strong>.
      </p>

      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
        
        <!-- Pilar 1: Kehadiran Shift -->
        <div style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 0.85rem; background: #f8fafc;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
            <strong style="font-size: 0.82rem; color: var(--text-main);">1. Disiplin Kehadiran Shift</strong>
            <span style="background: rgba(14, 165, 233, 0.15); color: #0284c7; font-weight: 800; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px;">Bobot 15%</span>
          </div>
          <div style="font-size: 0.75rem; color: var(--text-subtle); line-height: 1.4;">
            <strong>Sumber:</strong> Akumulasi tabel <code>daily_shifts</code> setahun.<br>
            <strong>Rumus:</strong> <code>(Shift Selesai / Total Shift) &times; 15 Poin</code>.<br>
            Menilai kedisiplinan dan ketuntasan jadwal shift sepanjang tahun.
          </div>
        </div>

        <!-- Pilar 2: Logbook Harian -->
        <div style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 0.85rem; background: #f8fafc;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
            <strong style="font-size: 0.82rem; color: var(--text-main);">2. Volume Logbook Tahunan</strong>
            <span style="background: rgba(16, 185, 129, 0.15); color: #059669; font-weight: 800; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px;">Bobot 20%</span>
          </div>
          <div style="font-size: 0.75rem; color: var(--text-subtle); line-height: 1.4;">
            <strong>Sumber:</strong> Akumulasi tabel <code>daily_activities</code> setahun.<br>
            <strong>Rumus:</strong> <code>Min(20 Poin, (Total Logbook / 120) &times; 20)</code>.<br>
            Benchmark 120 logbook dalam setahun mencapai skor maksimal.
          </div>
        </div>

        <!-- Pilar 3: Dokumentasi Foto -->
        <div style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 0.85rem; background: #f8fafc;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
            <strong style="font-size: 0.82rem; color: var(--text-main);">3. Bukti Fisik Lapangan</strong>
            <span style="background: rgba(139, 92, 246, 0.15); color: #7c3aed; font-weight: 800; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px;">Bobot 15%</span>
          </div>
          <div style="font-size: 0.75rem; color: var(--text-subtle); line-height: 1.4;">
            <strong>Sumber:</strong> Akumulasi tabel <code>daily_activity_photos</code>.<br>
            <strong>Rumus:</strong> <code>(Total Foto / Total Kegiatan) &times; 15 Poin</code>.<br>
            Kepatuhan SOP dokumentasi visual setiap pekerjaan teknis.
          </div>
        </div>

        <!-- Pilar 4: Partisipasi PM (Fleksibel Rotasi) -->
        <div style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 0.85rem; background: #f8fafc;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
            <strong style="font-size: 0.82rem; color: var(--text-main);">4. Partisipasi Inspeksi PM</strong>
            <span style="background: rgba(245, 158, 11, 0.15); color: #d97706; font-weight: 800; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px;">Bobot 10%</span>
          </div>
          <div style="font-size: 0.75rem; color: var(--text-subtle); line-height: 1.4;">
            <strong>Sumber:</strong> Akumulasi <code>pm_reports</code> setahun.<br>
            <strong>Catatan Rotasi:</strong> Karena penugasan PM dibagi berkala, Superadmin dapat mengoreksi skor PM ini secara manual agar adil.
          </div>
        </div>

        <!-- Pilar 5: Penilaian Atasan (Otomatis & Bisa Diubah) -->
        <div style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 0.85rem; background: #f8fafc;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
            <strong style="font-size: 0.82rem; color: var(--text-main);">5. Penilaian Atasan / Supervisor</strong>
            <span style="background: rgba(239, 68, 68, 0.15); color: #dc2626; font-weight: 800; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px;">Bobot 40%</span>
          </div>
          <div style="font-size: 0.75rem; color: var(--text-subtle); line-height: 1.4;">
            <strong>Default Otomatis:</strong> Nilai dasar standar 10 per aspek (total 40).<br>
            Superadmin dapat mengubah nilai secara manual sesuai hasil evaluasi (SOP, Troubleshooting, Respon, Kerjasama).
          </div>
        </div>

        <!-- Skala Predikat -->
        <div style="border: 1px solid var(--border); border-radius: var(--radius-md); padding: 0.85rem; background: #f8fafc;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
            <strong style="font-size: 0.82rem; color: var(--text-main);">Skala Predikat Akhir</strong>
            <span style="background: #e2e8f0; color: var(--text-main); font-weight: 800; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px;">Standar</span>
          </div>
          <div style="font-size: 0.75rem; color: var(--text-subtle); line-height: 1.4;">
            &bull; <strong style="color: #059669;">A (90 - 100)</strong>: Sangat Baik / Unggul<br>
            &bull; <strong style="color: #0284c7;">B (80 - 89.9)</strong>: Baik / Sesuai Standar SOP<br>
            &bull; <strong style="color: #d97706;">C (70 - 79.9)</strong>: Cukup / Perlu Peningkatan<br>
            &bull; <strong style="color: #dc2626;">D (&lt; 70)</strong>: Perlu Pembinaan Teknis
          </div>
        </div>

      </div>

      <div style="font-size: 0.75rem; color: var(--text-subtle); background: #f1f5f9; padding: 6px 10px; border-radius: 6px; border-left: 3px solid var(--primary);">
        <strong>Kebijakan Kerahasiaan &amp; Rilis:</strong> Rapor evaluasi kinerja tahunan ini <em>hanya akan muncul di akun teknisi jika Admin menekan tombol <strong>Kirim ke Teknisi</strong></em>. Jika status masih berupa Draft, akun teknisi tidak akan menampilkan data nilai apa pun.
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
            Papan Peringkat Kinerja Tahunan &bull; Tahun <?= $filterYear ?>
          </h2>
          <div style="font-size: 0.75rem; color: var(--text-subtle); margin-top: 2px;">
            Klik tombol "Nilai / Edit" untuk mengisi atau mengoreksi nilai indikator secara manual.
          </div>
        </div>
        <div>
          <a href="print_kpi?year=<?= $filterYear ?>" target="_blank" class="btn btn-sm btn-secondary" style="font-size: 0.78rem; font-weight: 700;">
            🖨️ Cetak Lembar Rekap A4
          </a>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table" style="width: 100%; border-collapse: collapse; margin-bottom: 0;">
          <thead>
            <tr style="background: #f1f5f9; border-bottom: 2px solid #cbd5e1; font-size: 0.75rem; text-transform: uppercase;">
              <th style="padding: 10px 12px; text-align: center; width: 55px;">Peringkat</th>
              <th style="padding: 10px 12px;">Personel / Teknisi</th>
              <th style="padding: 10px 12px; text-align: center; width: 110px;">Metrik Sistem (60%)</th>
              <th style="padding: 10px 12px; text-align: center; width: 120px;">Penilaian Atasan (40%)</th>
              <th style="padding: 10px 12px; text-align: center; width: 95px;">Total Skor</th>
              <th style="padding: 10px 12px; text-align: center; width: 90px;">Predikat</th>
              <th style="padding: 10px 12px; text-align: center; width: 110px;">Status Rapor</th>
              <th style="padding: 10px 12px; text-align: right; width: 230px;">Aksi</th>
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
                    @<?= htmlspecialchars($u['username']) ?> &bull; <span style="text-transform: uppercase;"><?= ($u['role'] === 'superadmin' ? 'Super Admin' : ($u['role'] === 'admin' ? 'Admin' : 'Teknisi')) ?></span>
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
                    Predikat <?= $grd ?>
                  </span>
                </td>
                <td style="padding: 10px 12px; text-align: center;">
                  <?php if ($isPub): ?>
                    <span style="background: rgba(16, 185, 129, 0.15); color: #059669; border: 1px solid rgba(16, 185, 129, 0.3); padding: 3px 8px; border-radius: 9999px; font-size: 0.72rem; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                      <?= app_icon('check-circle', 11) ?> Terkirim
                    </span>
                  <?php else: ?>
                    <span style="background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; padding: 3px 8px; border-radius: 9999px; font-size: 0.72rem; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                      <?= app_icon('lock', 11) ?> Draf Internal
                    </span>
                  <?php endif; ?>
                </td>
                <td style="padding: 10px 12px; text-align: right;">
                  <div style="display: inline-flex; align-items: center; gap: 4px;">
                    <!-- Tombol Nilai / Manual Override -->
                    <button type="button" class="btn btn-sm btn-secondary" onclick='openReviewModal(<?= json_encode($item) ?>)' style="padding: 0.25rem 0.55rem; font-size: 0.74rem; font-weight: 700;" title="Input / koreksi nilai indikator manual">
                      <?= app_icon('edit', 12) ?> Nilai / Koreksi
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

                    <!-- Tombol Cetak Slip Rapor Individu -->
                    <a href="print_kpi?year=<?= $filterYear ?>&user_id=<?= (int)$u['id'] ?>" target="_blank" class="btn btn-sm btn-secondary" style="padding: 0.25rem 0.55rem; font-size: 0.74rem;" title="Cetak slip rapor A4 individu">
                      🖨️
                    </a>
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
          Evaluasi Kinerja Tahunan Sedang Disiapkan
        </h3>
        <p style="color: var(--text-subtle); max-width: 520px; margin: 0 auto 1.5rem auto; font-size: 0.88rem; line-height: 1.5;">
          Rapor evaluasi KPI Anda untuk <strong><?= htmlspecialchars($periodLabel) ?></strong> saat ini sedang ditinjau dan disusun oleh Supervisor / Team Leader NOC. Hasil penilaian resmi akan otomatis muncul di halaman ini setelah dikirim oleh atasan.
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
              LEMBAR EVALUASI &amp; RAPOR KINERJA TAHUNAN
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
            <div style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: var(--text-subtle);">Total Skor Capaian KPI Tahunan</div>
            <div style="display: flex; align-items: baseline; gap: 8px; margin-top: 4px;">
              <span style="font-size: 2.8rem; font-weight: 800; color: #008080; line-height: 1;"><?= $myTot ?></span>
              <span style="font-size: 1.1rem; font-weight: 600; color: var(--text-subtle);">/ 100 Poin</span>
            </div>
            <div style="font-size: 0.75rem; color: var(--text-subtle); margin-top: 4px;">
              Metrik Sistem: <strong><?= $mySysScore ?>/60</strong> &bull; Review Leader: <strong><?= $myRevScore ?>/40</strong>
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
          Rincian Capaian Indikator Kinerja Tahunan
        </h3>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
          
          <div style="border: 1px solid var(--border); padding: 0.85rem; border-radius: 6px;">
            <div style="display: flex; justify-content: space-between; font-size: 0.82rem; font-weight: 700;">
              <span>1. Disiplin Kehadiran Shift</span>
              <strong style="color: #008080;"><?= (float)$mySaved['shift_score'] ?> / 15.0</strong>
            </div>
            <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 4px;">
              Total: <?= (int)$mySaved['completed_shifts'] ?> shift selesai dari <?= (int)$mySaved['shifts_count'] ?> jadwal tahun ini.
            </div>
          </div>

          <div style="border: 1px solid var(--border); padding: 0.85rem; border-radius: 6px;">
            <div style="display: flex; justify-content: space-between; font-size: 0.82rem; font-weight: 700;">
              <span>2. Volume Logbook Tahunan</span>
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
              <span>4. Partisipasi Inspeksi PM</span>
              <strong style="color: #008080;"><?= (float)$mySaved['pm_score'] ?> / 10.0</strong>
            </div>
            <div style="font-size: 0.72rem; color: var(--text-subtle); margin-top: 4px;">
              Total: <?= (int)$mySaved['pm_count'] ?> dokumen inspeksi Preventive Maintenance perangkat &amp; tower.
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

        <!-- Tombol Cetak Rapor Resmi A4 -->
        <div style="text-align: right;">
          <a href="print_kpi?year=<?= $filterYear ?>&user_id=<?= (int)$currentUser['id'] ?>" target="_blank" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 700;">
            🖨️ Cetak Lembar Rapor Resmi (A4)
          </a>
        </div>

      </div>
    <?php endif; ?>

  <?php endif; ?>

</div>

<!-- ======================================================== -->
<!-- MODAL: FORM PENILAIAN / OVERRIDE MANUAL SUPERADMIN      -->
<!-- ======================================================== -->
<?php if ($isAdmin): ?>
<div id="modalReview" class="qr-modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
  <div class="card" style="max-width: 620px; width: 92%; margin: auto; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem; border-bottom: 1px solid var(--border);">
      <div>
        <h3 style="font-size: 1.05rem; font-weight: 800; margin: 0; color: var(--text-main);" id="modalRevTitle">
          Penilaian &amp; Koreksi Manual KPI
        </h3>
        <div style="font-size: 0.74rem; color: var(--text-subtle); margin-top: 2px;">
          Tahun Anggaran: <?= $filterYear ?> &bull; <span style="color: #059669; font-weight: 700;">Nilai dapat disesuaikan manual</span>
        </div>
      </div>
      <button type="button" onclick="closeReviewModal()" style="background: none; border: none; font-size: 1.5rem; color: var(--text-subtle); cursor: pointer;">&times;</button>
    </div>

    <form method="POST" action="" novalidate id="formReview">
      <input type="hidden" name="action" value="save_review">
      <input type="hidden" name="user_id" id="revUserId" value="0">

      <div style="padding: 1.25rem;">
        
        <!-- Section 1: Skor Metrik Sistem (Bisa Diedit Manual) -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
          <div style="font-size: 0.82rem; font-weight: 800; color: #008080; text-transform: uppercase;">
            1. Metrik Operasional Sistem (Maksimal 60 Poin):
          </div>
          <button type="button" class="btn btn-sm" onclick="resetToAutoCalculated()" style="font-size: 0.72rem; padding: 3px 8px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-weight: 700;">
            ↺ Isi Nilai Hitungan Sistem
          </button>
        </div>

        <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 1rem; margin-bottom: 1.25rem;">
          
          <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 0.5rem;">
            <!-- Shift Score -->
            <div style="display: flex; flex-direction: column; gap: 4px;">
              <label class="form-label" style="font-size: 0.76rem; font-weight: 700; margin: 0; color: #1e293b;">
                Kehadiran Shift (Maksimal 15)
              </label>
              <input type="number" step="any" min="0" max="15" name="shift_score" id="inputShiftScore" class="form-control" oninput="updateLiveTotal()" style="font-size: 0.86rem; font-weight: 700; padding: 0.45rem 0.65rem;">
              <div style="font-size: 0.7rem; color: #64748b; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 4px; padding: 3px 6px; margin-top: 2px;">
                Hitungan Sistem: <strong id="autoShiftScore" style="color: #008080;">0</strong> / 15 (<span id="autoShiftCount">0</span> shift selesai)
              </div>
            </div>

            <!-- Logbook Score -->
            <div style="display: flex; flex-direction: column; gap: 4px;">
              <label class="form-label" style="font-size: 0.76rem; font-weight: 700; margin: 0; color: #1e293b;">
                Volume Logbook (Maksimal 20)
              </label>
              <input type="number" step="any" min="0" max="20" name="activity_score" id="inputActScore" class="form-control" oninput="updateLiveTotal()" style="font-size: 0.86rem; font-weight: 700; padding: 0.45rem 0.65rem;">
              <div style="font-size: 0.7rem; color: #64748b; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 4px; padding: 3px 6px; margin-top: 2px;">
                Hitungan Sistem: <strong id="autoActScore" style="color: #008080;">0</strong> / 20 (<span id="autoActCount">0</span> kegiatan)
              </div>
            </div>

            <!-- Photo Score -->
            <div style="display: flex; flex-direction: column; gap: 4px;">
              <label class="form-label" style="font-size: 0.76rem; font-weight: 700; margin: 0; color: #1e293b;">
                Bukti Foto Fisik (Maksimal 15)
              </label>
              <input type="number" step="any" min="0" max="15" name="photo_score" id="inputPhotoScore" class="form-control" oninput="updateLiveTotal()" style="font-size: 0.86rem; font-weight: 700; padding: 0.45rem 0.65rem;">
              <div style="font-size: 0.7rem; color: #64748b; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 4px; padding: 3px 6px; margin-top: 2px;">
                Hitungan Sistem: <strong id="autoPhotoScore" style="color: #008080;">0</strong> / 15 (<span id="autoPhotoCount">0</span> foto)
              </div>
            </div>

            <!-- PM Score (Fleksibel Rotasi) -->
            <div style="display: flex; flex-direction: column; gap: 4px;">
              <label class="form-label" style="font-size: 0.76rem; font-weight: 700; margin: 0; color: #1e293b;">
                Partisipasi PM (Maksimal 10)
              </label>
              <input type="number" step="any" min="0" max="10" name="pm_score" id="inputPmScore" class="form-control" oninput="updateLiveTotal()" style="font-size: 0.86rem; font-weight: 700; padding: 0.45rem 0.65rem;">
              <div style="font-size: 0.7rem; color: #64748b; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 4px; padding: 3px 6px; margin-top: 2px;">
                Hitungan Sistem: <strong id="autoPmScore" style="color: #008080;">0</strong> / 10 (<span id="autoPmCount">0</span> PM)
              </div>
            </div>
          </div>

          <div style="text-align: right; font-size: 0.82rem; font-weight: 800; color: #008080; border-top: 1px dashed #cbd5e1; padding-top: 6px; margin-top: 6px;">
            Subtotal Metrik Sistem: <span id="dispSysTotal">0</span> / 60.0
          </div>
        </div>

        <!-- Section 2: Penilaian Kualitatif Atasan (Maksimal 40 Poin) -->
        <div style="font-size: 0.82rem; font-weight: 800; color: #6366f1; text-transform: uppercase; margin-bottom: 0.5rem;">
          2. Penilaian Kualitatif Atasan (Maksimal 40 Poin):
        </div>

        <!-- Aspek 1: SOP Bandara & K3 -->
        <div class="form-group" style="margin-bottom: 0.65rem;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px;">
            <label class="form-label" style="font-size: 0.76rem; font-weight: 700; margin: 0;">1. Kepatuhan SOP Bandara &amp; K3</label>
            <span style="font-weight: 800; color: #6366f1; font-size: 0.78rem;" id="lblSop">10 / 10</span>
          </div>
          <input type="range" name="sop_score" id="inputSop" class="form-range" min="0" max="10" step="0.5" value="10" oninput="updateLiveTotal()" style="width: 100%;">
        </div>

        <!-- Aspek 2: Troubleshooting Teknis -->
        <div class="form-group" style="margin-bottom: 0.65rem;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px;">
            <label class="form-label" style="font-size: 0.76rem; font-weight: 700; margin: 0;">2. Troubleshooting &amp; Problem Solving Teknis</label>
            <span style="font-weight: 800; color: #6366f1; font-size: 0.78rem;" id="lblTrouble">10 / 10</span>
          </div>
          <input type="range" name="troubleshooting_score" id="inputTrouble" class="form-range" min="0" max="10" step="0.5" value="10" oninput="updateLiveTotal()" style="width: 100%;">
        </div>

        <!-- Aspek 3: Respon Insiden & Komunikasi -->
        <div class="form-group" style="margin-bottom: 0.65rem;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px;">
            <label class="form-label" style="font-size: 0.76rem; font-weight: 700; margin: 0;">3. Respon Insiden &amp; Komunikasi Tim</label>
            <span style="font-weight: 800; color: #6366f1; font-size: 0.78rem;" id="lblResponse">10 / 10</span>
          </div>
          <input type="range" name="response_score" id="inputResponse" class="form-range" min="0" max="10" step="0.5" value="10" oninput="updateLiveTotal()" style="width: 100%;">
        </div>

        <!-- Aspek 4: Kerjasama Tim & Inisiatif -->
        <div class="form-group" style="margin-bottom: 0.85rem;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px;">
            <label class="form-label" style="font-size: 0.76rem; font-weight: 700; margin: 0;">4. Kerjasama Tim &amp; Inisiatif Lapangan</label>
            <span style="font-weight: 800; color: #6366f1; font-size: 0.78rem;" id="lblInitiative">10 / 10</span>
          </div>
          <input type="range" name="initiative_score" id="inputInitiative" class="form-range" min="0" max="10" step="0.5" value="10" oninput="updateLiveTotal()" style="width: 100%;">
        </div>

        <!-- Total Live Preview -->
        <div style="background: #f1f5f9; border-radius: 6px; padding: 0.75rem 1rem; display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
          <span style="font-size: 0.82rem; font-weight: 700; color: var(--text-main);">Proyeksi Total Nilai Akhir:</span>
          <div style="display: flex; align-items: center; gap: 6px;">
            <strong id="liveTotalScore" style="font-size: 1.2rem; color: #008080;">0</strong>
            <span style="font-size: 0.75rem; color: var(--text-subtle);">/ 100</span>
            <span id="liveGrade" style="background: rgba(16, 185, 129, 0.15); color: #059669; border: 1px solid #059669; padding: 2px 8px; border-radius: 4px; font-weight: 800; font-size: 0.75rem;">Predikat C</span>
          </div>
        </div>

        <!-- Catatan Pembinaan -->
        <div class="form-group" style="margin-bottom: 1rem;">
          <label class="form-label" style="font-size: 0.76rem; font-weight: 700;">Catatan / Rekomendasi Evaluasi</label>
          <textarea name="feedback_notes" id="inputNotes" class="form-control" rows="2" placeholder="Catatan evaluasi atau apresiasi untuk teknisi ini..." style="font-size: 0.82rem;"></textarea>
        </div>

        <!-- Opsi Kirim ke Teknisi -->
        <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 6px; padding: 0.65rem 0.85rem; display: flex; align-items: center; gap: 8px;">
          <input type="checkbox" name="send_to_user" id="checkSendUser" value="1" style="width: 17px; height: 17px; cursor: pointer;">
          <label for="checkSendUser" style="margin: 0; font-size: 0.8rem; font-weight: 700; color: #065f46; cursor: pointer;">
            🚀 Langsung Kirim Rapor ke Akun Teknisi Ini
            <div style="font-size: 0.7rem; font-weight: 400; color: #047857;">Jika tidak dicentang, hasil evaluasi hanya disimpan sebagai Draf internal.</div>
          </label>
        </div>

      </div>

      <div class="card-header" style="display: flex; justify-content: flex-end; gap: 8px; border-top: 1px solid var(--border); padding: 0.85rem 1.25rem;">
        <button type="button" class="btn btn-secondary" onclick="closeReviewModal()" style="font-weight: 700; font-size: 0.82rem;">Batal</button>
        <button type="submit" class="btn btn-primary" style="font-weight: 800; font-size: 0.82rem;">💾 Simpan Penilaian KPI</button>
      </div>
    </form>
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
let curAutoItem = null;

function openReviewModal(item) {
  curAutoItem = item;
  document.getElementById('revUserId').value = item.user.id;
  document.getElementById('modalRevTitle').textContent = 'Penilaian KPI: ' + (item.user.full_name || item.user.username);
  
  // Hitungan Otomatis Sistem
  document.getElementById('autoShiftScore').textContent = item.auto_system.shift_score;
  document.getElementById('autoShiftCount').textContent = item.auto_system.completed_shifts;

  document.getElementById('autoActScore').textContent = item.auto_system.activity_score;
  document.getElementById('autoActCount').textContent = item.auto_system.activities_count;

  document.getElementById('autoPhotoScore').textContent = item.auto_system.photo_score;
  document.getElementById('autoPhotoCount').textContent = item.auto_system.photos_count;

  document.getElementById('autoPmScore').textContent = item.auto_system.pm_score;
  document.getElementById('autoPmCount').textContent = item.auto_system.pm_count;

  // Nilai aktif (bisa nilai tersimpan atau nilai auto sistem)
  document.getElementById('inputShiftScore').value = (parseFloat(item.system.shift_score) || 0).toString();
  document.getElementById('inputActScore').value = (parseFloat(item.system.activity_score) || 0).toString();
  document.getElementById('inputPhotoScore').value = (parseFloat(item.system.photo_score) || 0).toString();
  document.getElementById('inputPmScore').value = (parseFloat(item.system.pm_score) || 0).toString();

  // Nilai kualitatif
  document.getElementById('inputSop').value = item.review.sop || 10;
  document.getElementById('inputTrouble').value = item.review.troubleshooting || 10;
  document.getElementById('inputResponse').value = item.review.response || 10;
  document.getElementById('inputInitiative').value = item.review.initiative || 10;
  document.getElementById('inputNotes').value = item.review.notes || '';

  document.getElementById('checkSendUser').checked = (item.status === 'published');

  updateLiveTotal();
  document.getElementById('modalReview').style.display = 'flex';
}

function closeReviewModal() {
  document.getElementById('modalReview').style.display = 'none';
}

function resetToAutoCalculated() {
  if (!curAutoItem) return;
  document.getElementById('inputShiftScore').value = (parseFloat(curAutoItem.auto_system.shift_score) || 0).toString();
  document.getElementById('inputActScore').value = (parseFloat(curAutoItem.auto_system.activity_score) || 0).toString();
  document.getElementById('inputPhotoScore').value = (parseFloat(curAutoItem.auto_system.photo_score) || 0).toString();
  document.getElementById('inputPmScore').value = (parseFloat(curAutoItem.auto_system.pm_score) || 0).toString();
  updateLiveTotal();
}

function updateLiveTotal() {
  const sh = parseFloat(document.getElementById('inputShiftScore').value) || 0;
  const ac = parseFloat(document.getElementById('inputActScore').value) || 0;
  const ph = parseFloat(document.getElementById('inputPhotoScore').value) || 0;
  const pm = parseFloat(document.getElementById('inputPmScore').value) || 0;
  const sysTotal = Math.min(60, sh + ac + ph + pm);
  document.getElementById('dispSysTotal').textContent = sysTotal.toFixed(1);

  const s1 = parseFloat(document.getElementById('inputSop').value) || 0;
  const s2 = parseFloat(document.getElementById('inputTrouble').value) || 0;
  const s3 = parseFloat(document.getElementById('inputResponse').value) || 0;
  const s4 = parseFloat(document.getElementById('inputInitiative').value) || 0;

  document.getElementById('lblSop').textContent = s1.toFixed(1) + ' / 10';
  document.getElementById('lblTrouble').textContent = s2.toFixed(1) + ' / 10';
  document.getElementById('lblResponse').textContent = s3.toFixed(1) + ' / 10';
  document.getElementById('lblInitiative').textContent = s4.toFixed(1) + ' / 10';

  const revTotal = s1 + s2 + s3 + s4;
  const grandTotal = Math.min(100, Math.round((sysTotal + revTotal) * 10) / 10);
  document.getElementById('liveTotalScore').textContent = grandTotal.toFixed(1);

  let gr = 'D';
  let grColor = '#dc2626';
  let grBg = 'rgba(220, 38, 38, 0.15)';
  if (grandTotal >= 90) {
    gr = 'A';
    grColor = '#059669';
    grBg = 'rgba(5, 150, 105, 0.15)';
  } else if (grandTotal >= 80) {
    gr = 'B';
    grColor = '#0284c7';
    grBg = 'rgba(2, 132, 199, 0.15)';
  } else if (grandTotal >= 70) {
    gr = 'C';
    grColor = '#d97706';
    grBg = 'rgba(217, 119, 6, 0.15)';
  }
  const badgeEl = document.getElementById('liveGrade');
  badgeEl.textContent = 'Predikat ' + gr;
  badgeEl.style.color = grColor;
  badgeEl.style.background = grBg;
  badgeEl.style.borderColor = grColor;
}
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
