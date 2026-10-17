<?php
/**
 * Brankas Dokumen Pribadi & Berbagi (Personal & Shared Document Vault)
 * IASS SBU ICT KNO
 * 
 * Aturan Keamanan & Privasi:
 * 1. Setiap pengguna memiliki brankas dokumen terkompresi pribadi.
 * 2. Super Admin tidak berhak melihat berkas orang lain kecuali telah dibagikan kepadanya.
 * 3. Berkas (Word, Excel, PPT, PDF, Gambar, dll.) dikompresi otomatis saat diunggah (gzencode).
 * 4. Berkas didekompresi secara transparan saat diunduh (gzdecode).
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

start_secure_session();

if (!is_logged_in()) {
    $baseUrl = get_app_base_url();
    header('Location: ' . $baseUrl . 'login?expired=1');
    exit;
}

$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);
$pdo = get_db_connection();
$page_title = 'Brankas Dokumen';

// Helper: Format byte sizes
function format_doc_size(int $bytes): string {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }
    return $bytes . ' B';
}

// Helper: Icon & Color badge based on extension
function get_doc_file_badge(string $ext): array {
    $ext = strtolower($ext);
    switch ($ext) {
        case 'pdf':
            return ['icon' => 'document', 'color' => '#dc2626', 'bg' => '#fee2e2', 'label' => 'PDF'];
        case 'doc':
        case 'docx':
        case 'rtf':
        case 'odt':
            return ['icon' => 'file-text', 'color' => '#2563eb', 'bg' => '#dbeafe', 'label' => strtoupper($ext)];
        case 'xls':
        case 'xlsx':
        case 'csv':
        case 'ods':
            return ['icon' => 'clipboard-list', 'color' => '#059669', 'bg' => '#d1fae5', 'label' => strtoupper($ext)];
        case 'ppt':
        case 'pptx':
        case 'odp':
            return ['icon' => 'play', 'color' => '#ea580c', 'bg' => '#ffedd5', 'label' => strtoupper($ext)];
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'webp':
        case 'gif':
        case 'svg':
        case 'bmp':
            return ['icon' => 'camera', 'color' => '#7c3aed', 'bg' => '#ede9fe', 'label' => strtoupper($ext)];
        case 'zip':
        case 'rar':
        case '7z':
        case 'tar':
        case 'gz':
            return ['icon' => 'archive', 'color' => '#d97706', 'bg' => '#fef3c7', 'label' => strtoupper($ext)];
        default:
            return ['icon' => 'document', 'color' => '#008080', 'bg' => '#e6f5f5', 'label' => strtoupper($ext ?: 'FILE')];
    }
}

// 1. Fetch Summary Stats
$stmtMyCount = $pdo->prepare("SELECT COUNT(*) FROM user_documents WHERE user_id = ?");
$stmtMyCount->execute([$userId]);
$countMyDocs = (int)$stmtMyCount->fetchColumn();

$stmtSharedCount = $pdo->prepare("
    SELECT COUNT(DISTINCT d.id) 
    FROM user_documents d 
    JOIN user_document_shares s ON d.id = s.document_id 
    WHERE s.shared_with_user_id = ? AND d.user_id != ?
");
$stmtSharedCount->execute([$userId, $userId]);
$countSharedDocs = (int)$stmtSharedCount->fetchColumn();

$stmtTeamCount = $pdo->query("SELECT COUNT(*) FROM user_documents WHERE privacy_type = 'team'");
$countTeamDocs = (int)$stmtTeamCount->fetchColumn();

$stmtSizes = $pdo->prepare("
    SELECT SUM(file_size_raw) as total_raw, SUM(file_size_compressed) as total_comp 
    FROM user_documents WHERE user_id = ?
");
$stmtSizes->execute([$userId]);
$sizeRow = $stmtSizes->fetch();
$totalRawSize = (int)($sizeRow['total_raw'] ?? 0);
$totalCompSize = (int)($sizeRow['total_comp'] ?? 0);
$savedBytes = max(0, $totalRawSize - $totalCompSize);
$savedPercent = ($totalRawSize > 0) ? round(($savedBytes / $totalRawSize) * 100, 1) : 0;

// 2. Fetch Active Users list for Sharing Modal (exclude current user)
$stmtUsers = $pdo->prepare("
    SELECT id, username, full_name, role 
    FROM users 
    WHERE is_active = 1 AND id != ? 
    ORDER BY full_name ASC
");
$stmtUsers->execute([$userId]);
$activeUsers = $stmtUsers->fetchAll();

// 3. Fetch Tab Data
// A. Dokumen Saya
$stmtMy = $pdo->prepare("
    SELECT d.*, u.full_name as owner_name, u.username as owner_username,
           (SELECT COUNT(*) FROM user_document_shares WHERE document_id = d.id) as total_shares,
           (SELECT GROUP_CONCAT(shared_with_user_id) FROM user_document_shares WHERE document_id = d.id) as shared_user_ids
    FROM user_documents d
    JOIN users u ON d.user_id = u.id
    WHERE d.user_id = ?
    ORDER BY d.created_at DESC
");
$stmtMy->execute([$userId]);
$myDocs = $stmtMy->fetchAll();

// B. Dibagikan ke Saya
$stmtShared = $pdo->prepare("
    SELECT d.*, u.full_name as owner_name, u.username as owner_username,
           s.created_at as shared_at
    FROM user_documents d
    JOIN users u ON d.user_id = u.id
    JOIN user_document_shares s ON d.id = s.document_id
    WHERE s.shared_with_user_id = ? AND d.user_id != ?
    ORDER BY s.created_at DESC
");
$stmtShared->execute([$userId, $userId]);
$sharedDocs = $stmtShared->fetchAll();

// C. Dokumen Tim
$stmtTeam = $pdo->prepare("
    SELECT d.*, u.full_name as owner_name, u.username as owner_username
    FROM user_documents d
    JOIN users u ON d.user_id = u.id
    WHERE d.privacy_type = 'team'
    ORDER BY d.created_at DESC
");
$stmtTeam->execute();
$teamDocs = $stmtTeam->fetchAll();

// 4. Fetch Configured Document Categories
$docCategoriesSetting = get_system_setting(
    'document_categories',
    'Sertifikat & Lisensi, Pass Bandara & Identitas, Surat Tugas & SK, Manual & Dokumen Teknis, Kontrak & Legalitas, Laporan & Notulen, Lainnya'
);
$configuredCategories = array_values(array_unique(array_filter(array_map('trim', explode(',', $docCategoriesSetting)))));
if (empty($configuredCategories)) {
    $configuredCategories = ['Sertifikat & Lisensi', 'Pass Bandara & Identitas', 'Surat Tugas & SK', 'Manual & Dokumen Teknis', 'Kontrak & Legalitas', 'Laporan & Notulen', 'Lainnya'];
}

$allDocCategories = $configuredCategories;
foreach (array_merge($myDocs, $sharedDocs, $teamDocs) as $d) {
    if (!empty($d['category']) && !in_array($d['category'], $allDocCategories, true)) {
        $allDocCategories[] = $d['category'];
    }
}

// Helper: Generate Expiry status badge
function get_vault_expiry_badge(?string $expiryDate): string {
    if (empty($expiryDate)) return '';
    $today = new DateTime('today');
    $exp = new DateTime($expiryDate);
    $diff = (int)$today->diff($exp)->format('%r%a');
    if ($diff < 0) {
        return '<span class="doc-expiry-badge doc-expiry-expired" title="Masa berlaku telah berakhir">' . app_icon('alert-triangle', 12) . ' Expired (' . $exp->format('d M Y') . ')</span>';
    } elseif ($diff <= 30) {
        return '<span class="doc-expiry-badge doc-expiry-warning" title="Masa berlaku segera berakhir">' . app_icon('clock', 12) . ' Expire ' . $diff . ' hari lagi</span>';
    }
    return '<span class="doc-expiry-badge doc-expiry-valid">' . app_icon('check-circle', 12) . ' Berlaku s/d ' . $exp->format('d M Y') . '</span>';
}

include __DIR__ . '/includes/header.php';
?>

<style>
  /* Document Vault Custom Styles */
  .vault-hero {
    background: linear-gradient(135deg, rgba(0, 128, 128, 0.08) 0%, rgba(14, 165, 233, 0.08) 100%), var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 1.5rem 1.75rem;
    margin-bottom: 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1.25rem;
  }
  .vault-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
  }
  .vault-stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 1.15rem 1.25rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
  }
  .vault-stat-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }
  .vault-stat-val {
    font-size: 1.35rem;
    font-weight: 800;
    color: var(--text-main);
    line-height: 1.2;
  }
  .vault-stat-lbl {
    font-size: 0.78rem;
    color: var(--text-subtle);
    margin-top: 2px;
  }
  
  /* Tabs Navigation */
  .vault-tabs {
    display: flex;
    gap: 6px;
    border-bottom: 2px solid var(--border);
    margin-bottom: 1.25rem;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 2px;
  }
  .vault-tab-btn {
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    margin-bottom: -4px;
    padding: 0.65rem 1.15rem;
    font-weight: 700;
    font-size: 0.9rem;
    color: var(--text-subtle);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
    border-radius: 6px 6px 0 0;
    transition: all 0.15s ease;
  }
  .vault-tab-btn:hover {
    color: var(--primary);
    background: rgba(0, 128, 128, 0.05);
  }
  .vault-tab-btn.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
    background: rgba(0, 128, 128, 0.08);
  }
  .vault-tab-count {
    background: rgba(0, 0, 0, 0.08);
    color: inherit;
    font-size: 0.72rem;
    padding: 2px 7px;
    border-radius: 10px;
    font-weight: 700;
  }
  .vault-tab-btn.active .vault-tab-count {
    background: var(--primary);
    color: #ffffff;
  }

  /* Filter Controls */
  .vault-toolbar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.25rem;
  }
  .vault-search-box {
    position: relative;
    flex: 1;
    min-width: 240px;
  }
  .vault-search-box input {
    width: 100%;
    padding: 0.55rem 0.85rem 0.55rem 2.2rem;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg-card);
    color: var(--text-main);
    font-size: 0.88rem;
    box-sizing: border-box;
  }
  .vault-search-icon {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-subtle);
    pointer-events: none;
  }

  /* View Toggle Buttons */
  .vault-view-toggle {
    display: inline-flex;
    background: var(--bg-alt);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 3px;
    gap: 3px;
  }
  .vault-view-btn {
    border: none;
    background: transparent;
    color: var(--text-muted);
    padding: 6px 13px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
  }
  .vault-view-btn:hover {
    color: var(--text-main);
  }
  .vault-view-btn.active {
    background: var(--bg-card);
    color: var(--primary);
    font-weight: 700;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
  }

  /* Custom Modern Sleek Scrollbars */
  .vault-modal-body,
  .vault-doc-table-wrapper,
  .vault-tabs {
    scrollbar-width: thin;
    scrollbar-color: rgba(0, 128, 128, 0.38) transparent;
  }
  .vault-modal-body::-webkit-scrollbar,
  .vault-doc-table-wrapper::-webkit-scrollbar,
  .vault-tabs::-webkit-scrollbar {
    width: 6px;
    height: 6px;
  }
  .vault-modal-body::-webkit-scrollbar-track,
  .vault-doc-table-wrapper::-webkit-scrollbar-track,
  .vault-tabs::-webkit-scrollbar-track {
    background: transparent;
  }
  .vault-modal-body::-webkit-scrollbar-thumb,
  .vault-doc-table-wrapper::-webkit-scrollbar-thumb,
  .vault-tabs::-webkit-scrollbar-thumb {
    background: rgba(0, 128, 128, 0.32);
    border-radius: 999px;
    transition: background 0.15s ease;
  }
  .vault-modal-body::-webkit-scrollbar-thumb:hover,
  .vault-doc-table-wrapper::-webkit-scrollbar-thumb:hover,
  .vault-tabs::-webkit-scrollbar-thumb:hover {
    background: var(--primary);
  }

  /* Detail Table View Styles */
  .vault-doc-table-wrapper {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
  }
  .vault-doc-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.84rem;
    text-align: left;
    min-width: 820px;
  }
  .vault-doc-table th {
    background: var(--bg-alt);
    color: var(--text-subtle);
    font-weight: 700;
    font-size: 0.73rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 0.85rem 1rem;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
  }
  .vault-doc-table td {
    padding: 0.85rem 1rem;
    border-bottom: 1px solid var(--border);
    color: var(--text-main);
    vertical-align: middle;
  }
  .vault-doc-table tr:last-child td {
    border-bottom: none;
  }
  .vault-doc-table tr:hover td {
    background: rgba(0, 128, 128, 0.025);
  }
  .vault-table-file-badge {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 0.7rem;
    letter-spacing: 0.5px;
    flex-shrink: 0;
  }
  .vault-table-actions {
    display: flex;
    gap: 4px;
    align-items: center;
    justify-content: flex-end;
  }

  /* Document Cards Grid */
  .vault-doc-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1rem;
  }
  .vault-doc-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 1.15rem;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
  }
  .vault-doc-card:hover {
    border-color: var(--primary);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
  }
  .vault-doc-header {
    display: flex;
    gap: 12px;
    align-items: flex-start;
  }
  .vault-doc-icon {
    width: 44px;
    height: 44px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 0.72rem;
    letter-spacing: 0.5px;
    flex-shrink: 0;
  }
  .vault-doc-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--text-main);
    margin: 0 0 4px 0;
    line-height: 1.35;
    word-break: break-word;
  }
  .vault-doc-sub {
    font-size: 0.75rem;
    color: var(--text-subtle);
    word-break: break-all;
  }
  .vault-doc-meta {
    margin-top: 10px;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
  }
  .doc-category-badge {
    background: rgba(0, 128, 128, 0.1);
    color: var(--primary);
    font-size: 0.7rem;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 6px;
    border: 1px solid rgba(0, 128, 128, 0.2);
  }
  .doc-privacy-badge {
    font-size: 0.7rem;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
  }
  .doc-privacy-private {
    background: rgba(100, 116, 139, 0.12);
    color: #475569;
    border: 1px solid rgba(100, 116, 139, 0.25);
  }
  .doc-privacy-shared {
    background: rgba(59, 130, 246, 0.12);
    color: #2563eb;
    border: 1px solid rgba(59, 130, 246, 0.25);
  }
  .doc-privacy-team {
    background: rgba(16, 185, 129, 0.12);
    color: #059669;
    border: 1px solid rgba(16, 185, 129, 0.25);
  }
  .doc-expiry-badge {
    font-size: 0.7rem;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
  }
  .doc-expiry-valid {
    background: rgba(16, 185, 129, 0.1);
    color: #059669;
    border: 1px solid rgba(16, 185, 129, 0.2);
  }
  .doc-expiry-warning {
    background: rgba(245, 158, 11, 0.12);
    color: #d97706;
    border: 1px solid rgba(245, 158, 11, 0.3);
  }
  .doc-expiry-expired {
    background: rgba(239, 68, 68, 0.12);
    color: #dc2626;
    border: 1px solid rgba(239, 68, 68, 0.3);
  }
  .doc-compression-tag {
    font-size: 0.68rem;
    color: #059669;
    background: #ecfdf5;
    padding: 2px 6px;
    border-radius: 4px;
    font-weight: 700;
    border: 1px solid #a7f3d0;
  }
  .vault-doc-actions {
    margin-top: 14px;
    padding-top: 10px;
    border-top: 1px dashed var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
  }
  .vault-btn-group {
    display: flex;
    gap: 5px;
    align-items: center;
  }
  .btn-vault-action {
    padding: 0.35rem 0.65rem;
    font-size: 0.78rem;
    font-weight: 700;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: var(--bg-card);
    color: var(--text-main);
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.15s ease;
  }
  .btn-vault-action:hover {
    border-color: var(--primary);
    color: var(--primary);
    background: rgba(0, 128, 128, 0.05);
  }
  .btn-vault-primary {
    background: var(--primary);
    color: #ffffff;
    border-color: var(--primary);
  }
  .btn-vault-primary:hover {
    background: #006666;
    color: #ffffff;
  }
  .btn-vault-danger {
    color: #dc2626;
    border-color: #fecaca;
    background: #fff5f5;
  }
  .btn-vault-danger:hover {
    background: #fee2e2;
    border-color: #ef4444;
    color: #991b1b;
  }

  /* Empty state */
  .vault-empty-state {
    text-align: center;
    padding: 3rem 1.5rem;
    background: var(--bg-card);
    border: 2px dashed var(--border);
    border-radius: 12px;
    margin: 1.5rem 0;
  }
  .vault-empty-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: rgba(0, 128, 128, 0.1);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 12px;
  }

  /* Modal Dialog Styles */
  .vault-modal-backdrop {
    position: fixed;
    inset: 0;
    width: 100%;
    height: 100%;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1100;
    padding: 1rem;
    box-sizing: border-box;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
  }
  .vault-modal-dialog {
    background: var(--bg-card);
    width: 100%;
    max-width: 580px;
    height: auto;
    max-height: 85vh;
    max-height: 85dvh;
    border-radius: 12px;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25);
    border: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    margin: auto;
  }
  .vault-modal-header {
    flex-shrink: 0;
    padding: 0.95rem 1.25rem;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--bg-card);
    z-index: 10;
  }
  .vault-modal-header h3 {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--text-main);
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .vault-modal-close {
    background: transparent;
    border: none;
    font-size: 1.4rem;
    color: var(--text-subtle);
    cursor: pointer;
    line-height: 1;
    padding: 4px;
    border-radius: 6px;
  }
  .vault-modal-close:hover {
    color: var(--text-main);
  }
  .vault-modal-body {
    flex: 1 1 auto;
    min-height: 0;
    padding: 1.15rem 1.25rem;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior: contain;
    touch-action: pan-y;
  }
  .vault-modal-footer {
    flex-shrink: 0;
    padding: 0.85rem 1.25rem;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 8px;
    background: var(--bg-card);
    z-index: 10;
  }

  @media (max-width: 768px) {
    .vault-modal-backdrop {
      padding: 0;
      align-items: flex-end;
    }
    .vault-modal-dialog {
      max-height: calc(94dvh - 0.5rem);
      max-height: calc(94vh - 0.5rem);
      border-bottom-left-radius: 0;
      border-bottom-right-radius: 0;
      border-bottom: none;
      width: 100%;
      margin: 0;
    }
    .vault-modal-header {
      padding: 0.85rem 1rem;
    }
    .vault-modal-body {
      padding: 1rem 0.9rem;
    }
    .vault-modal-footer {
      padding: 0.8rem 1rem calc(0.8rem + env(safe-area-inset-bottom, 0px)) 1rem;
      box-shadow: 0 -4px 16px rgba(0, 0, 0, 0.08);
      gap: 10px;
    }
    .vault-modal-footer .btn {
      flex: 1;
      min-height: 46px;
      font-size: 0.88rem;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
  }

  /* Form Elements */
  .vault-form-group {
    margin-bottom: 0.95rem;
  }
  .vault-form-group label {
    display: block;
    font-size: 0.84rem;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 4px;
  }
  .vault-form-control {
    width: 100%;
    padding: 0.55rem 0.8rem;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg-card);
    color: var(--text-main);
    font-size: 0.88rem;
    box-sizing: border-box;
  }
  .vault-form-control:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(0, 128, 128, 0.15);
  }
  .vault-file-dropzone {
    border: 2px dashed var(--border);
    border-radius: 10px;
    padding: 1rem 0.85rem;
    text-align: center;
    cursor: pointer;
    background: rgba(0, 128, 128, 0.02);
    transition: all 0.2s ease;
  }
  .vault-file-dropzone:hover, .vault-file-dropzone.dragover {
    border-color: var(--primary);
    background: rgba(0, 128, 128, 0.06);
  }
  .vault-privacy-options {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-top: 4px;
  }
  .vault-privacy-option {
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 8px 10px;
    display: flex;
    align-items: flex-start;
    gap: 8px;
    cursor: pointer;
    transition: border-color 0.15s ease;
  }
  .vault-privacy-option:hover {
    border-color: var(--primary);
  }
  .vault-privacy-option input[type="radio"] {
    margin-top: 3px;
  }
  .vault-user-checklist {
    max-height: 180px;
    overflow-y: auto;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 8px 12px;
    margin-top: 8px;
    background: var(--bg-card);
  }
  .vault-user-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 0;
    border-bottom: 1px solid var(--border);
    font-size: 0.85rem;
  }
  .vault-user-item:last-child {
    border-bottom: none;
  }
  .doc-owner-badge {
    font-size: 0.72rem;
    color: var(--text-subtle);
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-top: 4px;
  }
</style>

<main class="container" style="max-width: 1200px; margin: 1.5rem auto; padding: 0 1rem;">

  <!-- Hero Header -->
  <div class="vault-hero">
    <div>
      <div style="font-size: 0.78rem; font-weight: 700; color: var(--primary); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">
        Penyimpanan Berkas Terenkripsi & Terkompresi
      </div>
      <h2 style="font-size: 1.45rem; font-weight: 800; color: var(--text-main); margin: 0;">
        Brankas Dokumen (Personal &amp; Shared Vault)
      </h2>
      <p style="color: var(--text-subtle); font-size: 0.86rem; margin: 4px 0 0 0; max-width: 680px; line-height: 1.5;">
        Simpan berkas penting (K3, Pass Bandara, SK, Surat Tugas, Sertifikat, Manual). Berkas terkompresi otomatis di server dan hanya dapat dilihat oleh Anda serta rekan yang Anda bagikan secara eksplisit.
      </p>
    </div>
    <div>
      <button type="button" class="btn btn-primary" onclick="openUploadModal()" style="display: inline-flex; align-items: center; gap: 6px; min-height: 44px; padding: 0.6rem 1.25rem; font-weight: 700; border-radius: 8px;">
        <?= app_icon('plus', 18) ?>
        <span>+ Upload Dokumen</span>
      </button>
    </div>
  </div>

  <!-- Summary Statistics Grid -->
  <div class="vault-stats-grid">
    <div class="vault-stat-card">
      <div class="vault-stat-icon" style="background: rgba(0, 128, 128, 0.1); color: var(--primary);">
        <?= app_icon('folder', 22) ?>
      </div>
      <div>
        <div class="vault-stat-val"><?= $countMyDocs ?></div>
        <div class="vault-stat-lbl">Dokumen Saya</div>
      </div>
    </div>
    <div class="vault-stat-card">
      <div class="vault-stat-icon" style="background: rgba(59, 130, 246, 0.1); color: #2563eb;">
        <?= app_icon('users', 22) ?>
      </div>
      <div>
        <div class="vault-stat-val"><?= $countSharedDocs ?></div>
        <div class="vault-stat-lbl">Dibagikan ke Saya</div>
      </div>
    </div>
    <div class="vault-stat-card">
      <div class="vault-stat-icon" style="background: rgba(16, 185, 129, 0.1); color: #059669;">
        <?= app_icon('share', 22) ?>
      </div>
      <div>
        <div class="vault-stat-val"><?= $countTeamDocs ?></div>
        <div class="vault-stat-lbl">Dokumen Seluruh Tim</div>
      </div>
    </div>
    <div class="vault-stat-card">
      <div class="vault-stat-icon" style="background: rgba(245, 158, 11, 0.1); color: #d97706;">
        <?= app_icon('archive', 22) ?>
      </div>
      <div>
        <div class="vault-stat-val"><?= format_doc_size($totalCompSize) ?></div>
        <div class="vault-stat-lbl">
          Hemat <?= $savedPercent ?>% (<?= format_doc_size($savedBytes) ?>)
        </div>
      </div>
    </div>
  </div>

  <!-- Tabs Navigation -->
  <div class="vault-tabs" role="tablist">
    <button type="button" class="vault-tab-btn active" id="tab_btn_my" onclick="switchVaultTab('my')" role="tab" aria-selected="true">
      <?= app_icon('folder', 16) ?>
      <span>Dokumen Saya</span>
      <span class="vault-tab-count"><?= count($myDocs) ?></span>
    </button>
    <button type="button" class="vault-tab-btn" id="tab_btn_shared" onclick="switchVaultTab('shared')" role="tab" aria-selected="false">
      <?= app_icon('users', 16) ?>
      <span>Dibagikan ke Saya</span>
      <span class="vault-tab-count"><?= count($sharedDocs) ?></span>
    </button>
    <button type="button" class="vault-tab-btn" id="tab_btn_team" onclick="switchVaultTab('team')" role="tab" aria-selected="false">
      <?= app_icon('share', 16) ?>
      <span>Dokumen Tim</span>
      <span class="vault-tab-count"><?= count($teamDocs) ?></span>
    </button>
  </div>

  <!-- Search, Category Filter, and View Mode Toolbar -->
  <div class="vault-toolbar">
    <div class="vault-search-box">
      <span class="vault-search-icon"><?= app_icon('search', 16) ?></span>
      <input type="text" id="vault_search_input" placeholder="Cari judul, nama file, atau catatan..." oninput="applyVaultFilter()">
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
      <select id="vault_category_select" class="vault-form-control" style="width: auto; min-width: 190px;" onchange="applyVaultFilter()">
        <option value="">Semua Kategori</option>
        <?php foreach ($allDocCategories as $cat): ?>
          <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
        <?php endforeach; ?>
      </select>

      <div class="vault-view-toggle" role="group" aria-label="Mode Tampilan Dokumen">
        <button type="button" class="vault-view-btn active" id="btn_view_grid" onclick="setVaultViewMode('grid')" title="Tampilan Grid Kotak">
          <?= app_icon('grid', 15) ?>
          <span>Grid</span>
        </button>
        <button type="button" class="vault-view-btn" id="btn_view_detail" onclick="setVaultViewMode('detail')" title="Tampilan Detail / Daftar Tabel">
          <?= app_icon('list', 15) ?>
          <span>Detail</span>
        </button>
      </div>
    </div>
  </div>

  <!-- Tab 1: Dokumen Saya -->
  <div id="vault_pane_my" class="vault-tab-pane">
    <?php if (empty($myDocs)): ?>
      <div class="vault-empty-state">
        <div class="vault-empty-icon"><?= app_icon('folder-plus', 30) ?></div>
        <h4 style="margin: 0 0 6px 0; color: var(--text-main);">Belum Ada Dokumen Pribadi</h4>
        <p style="color: var(--text-subtle); font-size: 0.88rem; margin: 0 0 16px 0;">
          Unggah sertifikat, pass bandara, lisensi tower/K3, atau berkas penting lainnya ke brankas pribadi Anda.
        </p>
        <button type="button" class="btn btn-primary btn-sm" onclick="openUploadModal()">
          + Upload Dokumen Pertama
        </button>
      </div>
    <?php else: ?>
      <!-- Mode 1: Grid Kotak Dokumen Saya -->
      <div class="vault-doc-grid vault-view-mode-grid">
        <?php foreach ($myDocs as $doc): ?>
          <?php 
            $badge = get_doc_file_badge($doc['file_ext']);
            $savedDocPct = ($doc['file_size_raw'] > 0 && $doc['file_size_compressed'] < $doc['file_size_raw'])
              ? round((1 - ($doc['file_size_compressed'] / $doc['file_size_raw'])) * 100, 1)
              : 0;
            $expBadgeHtml = get_vault_expiry_badge($doc['expiry_date']);
          ?>
          <div class="vault-doc-card" 
               data-title="<?= htmlspecialchars(strtolower($doc['title'])) ?>" 
               data-file="<?= htmlspecialchars(strtolower($doc['original_filename'])) ?>"
               data-desc="<?= htmlspecialchars(strtolower($doc['description'] ?? '')) ?>"
               data-cat="<?= htmlspecialchars($doc['category']) ?>">
            <div>
              <div class="vault-doc-header">
                <div class="vault-doc-icon" style="background: <?= $badge['bg'] ?>; color: <?= $badge['color'] ?>;">
                  <?= $badge['label'] ?>
                </div>
                <div style="flex: 1; min-width: 0;">
                  <h4 class="vault-doc-title"><?= htmlspecialchars($doc['title']) ?></h4>
                  <div class="vault-doc-sub" title="<?= htmlspecialchars($doc['original_filename']) ?>">
                    <?= htmlspecialchars($doc['original_filename']) ?>
                  </div>
                </div>
              </div>

              <?php if (!empty($doc['description'])): ?>
                <div style="font-size: 0.8rem; color: var(--text-subtle); margin-top: 8px; line-height: 1.4; word-break: break-word;">
                  <?= nl2br(htmlspecialchars($doc['description'])) ?>
                </div>
              <?php endif; ?>

              <div class="vault-doc-meta">
                <span class="doc-category-badge"><?= htmlspecialchars($doc['category']) ?></span>
                
                <?php if ($doc['privacy_type'] === 'private'): ?>
                  <span class="doc-privacy-badge doc-privacy-private">
                    <?= app_icon('lock', 11) ?> Pribadi
                  </span>
                <?php elseif ($doc['privacy_type'] === 'shared_users'): ?>
                  <span class="doc-privacy-badge doc-privacy-shared">
                    <?= app_icon('users', 11) ?> Dibagikan (<?= (int)$doc['total_shares'] ?> org)
                  </span>
                <?php else: ?>
                  <span class="doc-privacy-badge doc-privacy-team">
                    <?= app_icon('share', 11) ?> Seluruh Tim
                  </span>
                <?php endif; ?>

                <?= $expBadgeHtml ?>

                <span class="doc-compression-tag" title="Ukuran asli <?= format_doc_size($doc['file_size_raw']) ?> dikompresi menjadi <?= format_doc_size($doc['file_size_compressed']) ?>">
                  <?= format_doc_size($doc['file_size_compressed']) ?>
                  <?php if ($savedDocPct > 0): ?>
                    (Hemat <?= $savedDocPct ?>%)
                  <?php endif; ?>
                </span>
              </div>
            </div>

            <div class="vault-doc-actions">
              <span style="font-size: 0.72rem; color: var(--text-subtle);">
                <?= date('d M Y', strtotime($doc['created_at'])) ?>
              </span>
              <div class="vault-btn-group">
                <a href="download_document.php?id=<?= $doc['id'] ?>&view=1" target="_blank" class="btn-vault-action" title="Pratinjau Berkas">
                  <?= app_icon('eye', 13) ?>
                  <span>Lihat</span>
                </a>
                <a href="download_document.php?id=<?= $doc['id'] ?>" class="btn-vault-action btn-vault-primary" title="Unduh Berkas Asli">
                  <?= app_icon('download', 13) ?>
                  <span>Unduh</span>
                </a>
                <button type="button" class="btn-vault-action" onclick="openShareModal(<?= htmlspecialchars(json_encode($doc)) ?>)" title="Atur Hak Akses Berbagi">
                  <?= app_icon('share', 13) ?>
                </button>
                <button type="button" class="btn-vault-action" onclick="openEditDocModal(<?= htmlspecialchars(json_encode($doc)) ?>)" title="Edit Metadata Dokumen">
                  <?= app_icon('edit', 13) ?>
                </button>
                <button type="button" class="btn-vault-action btn-vault-danger" onclick="confirmDeleteDoc(<?= $doc['id'] ?>, '<?= htmlspecialchars(addslashes($doc['title'])) ?>')" title="Hapus Dokumen">
                  <?= app_icon('trash', 13) ?>
                </button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Mode 2: Detail Daftar / Tabel Dokumen Saya -->
      <div class="vault-doc-table-wrapper vault-view-mode-detail" style="display: none;">
        <table class="vault-doc-table">
          <thead>
            <tr>
              <th style="width: 48px; text-align: center;">Tipe</th>
              <th>Judul &amp; Nama File</th>
              <th>Kategori</th>
              <th>Hak Akses</th>
              <th>Masa Berlaku</th>
              <th>Ukuran</th>
              <th>Tanggal</th>
              <th style="text-align: right; width: 140px;">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($myDocs as $doc): ?>
              <?php
                $badge = get_doc_file_badge($doc['file_ext']);
                $savedDocPct = ($doc['file_size_raw'] > 0 && $doc['file_size_compressed'] < $doc['file_size_raw'])
                  ? round((1 - ($doc['file_size_compressed'] / $doc['file_size_raw'])) * 100, 1)
                  : 0;
                $expBadgeHtml = get_vault_expiry_badge($doc['expiry_date']);
              ?>
              <tr class="vault-doc-row"
                  data-title="<?= htmlspecialchars(strtolower($doc['title'])) ?>"
                  data-file="<?= htmlspecialchars(strtolower($doc['original_filename'])) ?>"
                  data-desc="<?= htmlspecialchars(strtolower($doc['description'] ?? '')) ?>"
                  data-cat="<?= htmlspecialchars($doc['category']) ?>">
                <td style="text-align: center;">
                  <span class="vault-table-file-badge" style="background: <?= $badge['bg'] ?>; color: <?= $badge['color'] ?>;">
                    <?= $badge['label'] ?>
                  </span>
                </td>
                <td>
                  <div style="font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($doc['title']) ?></div>
                  <div style="font-size: 0.74rem; color: var(--text-subtle);"><?= htmlspecialchars($doc['original_filename']) ?></div>
                  <?php if (!empty($doc['description'])): ?>
                    <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 2px;">
                      <?= htmlspecialchars(mb_strimwidth($doc['description'], 0, 75, '...')) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="doc-category-badge"><?= htmlspecialchars($doc['category']) ?></span>
                </td>
                <td>
                  <?php if ($doc['privacy_type'] === 'private'): ?>
                    <span class="doc-privacy-badge doc-privacy-private">
                      <?= app_icon('lock', 11) ?> Pribadi
                    </span>
                  <?php elseif ($doc['privacy_type'] === 'shared_users'): ?>
                    <span class="doc-privacy-badge doc-privacy-shared">
                      <?= app_icon('users', 11) ?> Dibagikan (<?= (int)$doc['total_shares'] ?>)
                    </span>
                  <?php else: ?>
                    <span class="doc-privacy-badge doc-privacy-team">
                      <?= app_icon('share', 11) ?> Seluruh Tim
                    </span>
                  <?php endif; ?>
                </td>
                <td>
                  <?= $expBadgeHtml ?: '<span style="color: var(--text-subtle); font-size: 0.75rem;">-</span>' ?>
                </td>
                <td>
                  <span class="doc-compression-tag" title="Ukuran asli <?= format_doc_size($doc['file_size_raw']) ?> dikompresi menjadi <?= format_doc_size($doc['file_size_compressed']) ?>">
                    <?= format_doc_size($doc['file_size_compressed']) ?>
                    <?php if ($savedDocPct > 0): ?>
                      <br><small style="color: #059669; font-weight: 700;">Hemat <?= $savedDocPct ?>%</small>
                    <?php endif; ?>
                  </span>
                </td>
                <td style="font-size: 0.74rem; color: var(--text-subtle); white-space: nowrap;">
                  <?= date('d M Y', strtotime($doc['created_at'])) ?>
                </td>
                <td>
                  <div class="vault-table-actions">
                    <a href="download_document.php?id=<?= $doc['id'] ?>&view=1" target="_blank" class="btn-vault-action" title="Lihat">
                      <?= app_icon('eye', 13) ?>
                    </a>
                    <a href="download_document.php?id=<?= $doc['id'] ?>" class="btn-vault-action btn-vault-primary" title="Unduh Berkas Asli">
                      <?= app_icon('download', 13) ?>
                    </a>
                    <button type="button" class="btn-vault-action" onclick="openShareModal(<?= htmlspecialchars(json_encode($doc)) ?>)" title="Atur Hak Akses">
                      <?= app_icon('share', 13) ?>
                    </button>
                    <button type="button" class="btn-vault-action" onclick="openEditDocModal(<?= htmlspecialchars(json_encode($doc)) ?>)" title="Edit Metadata">
                      <?= app_icon('edit', 13) ?>
                    </button>
                    <button type="button" class="btn-vault-action btn-vault-danger" onclick="confirmDeleteDoc(<?= $doc['id'] ?>, '<?= htmlspecialchars(addslashes($doc['title'])) ?>')" title="Hapus Dokumen">
                      <?= app_icon('trash', 13) ?>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Tab 2: Dibagikan ke Saya -->
  <div id="vault_pane_shared" class="vault-tab-pane" style="display: none;">
    <?php if (empty($sharedDocs)): ?>
      <div class="vault-empty-state">
        <div class="vault-empty-icon"><?= app_icon('users', 30) ?></div>
        <h4 style="margin: 0 0 6px 0; color: var(--text-main);">Belum Ada Dokumen yang Dibagikan</h4>
        <p style="color: var(--text-subtle); font-size: 0.88rem; margin: 0;">
          Rekan kerja belum membagikan dokumen khusus kepada Anda. Dokumen yang dibagikan akan otomatis muncul di sini.
        </p>
      </div>
    <?php else: ?>
      <!-- Mode 1: Grid Kotak Dibagikan ke Saya -->
      <div class="vault-doc-grid vault-view-mode-grid">
        <?php foreach ($sharedDocs as $doc): ?>
          <?php 
            $badge = get_doc_file_badge($doc['file_ext']);
            $savedDocPct = ($doc['file_size_raw'] > 0 && $doc['file_size_compressed'] < $doc['file_size_raw'])
              ? round((1 - ($doc['file_size_compressed'] / $doc['file_size_raw'])) * 100, 1)
              : 0;
            $expBadgeHtml = get_vault_expiry_badge($doc['expiry_date']);
          ?>
          <div class="vault-doc-card" 
               data-title="<?= htmlspecialchars(strtolower($doc['title'])) ?>" 
               data-file="<?= htmlspecialchars(strtolower($doc['original_filename'])) ?>"
               data-desc="<?= htmlspecialchars(strtolower($doc['description'] ?? '')) ?>"
               data-cat="<?= htmlspecialchars($doc['category']) ?>">
            <div>
              <div class="vault-doc-header">
                <div class="vault-doc-icon" style="background: <?= $badge['bg'] ?>; color: <?= $badge['color'] ?>;">
                  <?= $badge['label'] ?>
                </div>
                <div style="flex: 1; min-width: 0;">
                  <h4 class="vault-doc-title"><?= htmlspecialchars($doc['title']) ?></h4>
                  <div class="vault-doc-sub" title="<?= htmlspecialchars($doc['original_filename']) ?>">
                    <?= htmlspecialchars($doc['original_filename']) ?>
                  </div>
                  <div class="doc-owner-badge">
                    <?= app_icon('user', 12) ?> Dibagikan oleh: <strong><?= htmlspecialchars($doc['owner_name'] ?: $doc['owner_username']) ?></strong>
                  </div>
                </div>
              </div>

              <?php if (!empty($doc['description'])): ?>
                <div style="font-size: 0.8rem; color: var(--text-subtle); margin-top: 8px; line-height: 1.4; word-break: break-word;">
                  <?= nl2br(htmlspecialchars($doc['description'])) ?>
                </div>
              <?php endif; ?>

              <div class="vault-doc-meta">
                <span class="doc-category-badge"><?= htmlspecialchars($doc['category']) ?></span>
                <span class="doc-privacy-badge doc-privacy-shared">
                  <?= app_icon('users', 11) ?> Dibagikan ke Anda
                </span>
                <?= $expBadgeHtml ?>
                <span class="doc-compression-tag" title="Ukuran asli <?= format_doc_size($doc['file_size_raw']) ?> dikompresi menjadi <?= format_doc_size($doc['file_size_compressed']) ?>">
                  <?= format_doc_size($doc['file_size_compressed']) ?>
                  <?php if ($savedDocPct > 0): ?>
                    (Hemat <?= $savedDocPct ?>%)
                  <?php endif; ?>
                </span>
              </div>
            </div>

            <div class="vault-doc-actions">
              <span style="font-size: 0.72rem; color: var(--text-subtle);">
                Dibagikan: <?= date('d M Y', strtotime($doc['shared_at'])) ?>
              </span>
              <div class="vault-btn-group">
                <a href="download_document.php?id=<?= $doc['id'] ?>&view=1" target="_blank" class="btn-vault-action" title="Pratinjau Berkas">
                  <?= app_icon('eye', 13) ?>
                  <span>Lihat</span>
                </a>
                <a href="download_document.php?id=<?= $doc['id'] ?>" class="btn-vault-action btn-vault-primary" title="Unduh Berkas Asli">
                  <?= app_icon('download', 13) ?>
                  <span>Unduh</span>
                </a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Mode 2: Detail Daftar / Tabel Dibagikan ke Saya -->
      <div class="vault-doc-table-wrapper vault-view-mode-detail" style="display: none;">
        <table class="vault-doc-table">
          <thead>
            <tr>
              <th style="width: 48px; text-align: center;">Tipe</th>
              <th>Judul &amp; Nama File</th>
              <th>Kategori</th>
              <th>Dibagikan Oleh</th>
              <th>Masa Berlaku</th>
              <th>Ukuran</th>
              <th>Tanggal</th>
              <th style="text-align: right; width: 110px;">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sharedDocs as $doc): ?>
              <?php
                $badge = get_doc_file_badge($doc['file_ext']);
                $savedDocPct = ($doc['file_size_raw'] > 0 && $doc['file_size_compressed'] < $doc['file_size_raw'])
                  ? round((1 - ($doc['file_size_compressed'] / $doc['file_size_raw'])) * 100, 1)
                  : 0;
                $expBadgeHtml = get_vault_expiry_badge($doc['expiry_date']);
              ?>
              <tr class="vault-doc-row"
                  data-title="<?= htmlspecialchars(strtolower($doc['title'])) ?>"
                  data-file="<?= htmlspecialchars(strtolower($doc['original_filename'])) ?>"
                  data-desc="<?= htmlspecialchars(strtolower($doc['description'] ?? '')) ?>"
                  data-cat="<?= htmlspecialchars($doc['category']) ?>">
                <td style="text-align: center;">
                  <span class="vault-table-file-badge" style="background: <?= $badge['bg'] ?>; color: <?= $badge['color'] ?>;">
                    <?= $badge['label'] ?>
                  </span>
                </td>
                <td>
                  <div style="font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($doc['title']) ?></div>
                  <div style="font-size: 0.74rem; color: var(--text-subtle);"><?= htmlspecialchars($doc['original_filename']) ?></div>
                </td>
                <td>
                  <span class="doc-category-badge"><?= htmlspecialchars($doc['category']) ?></span>
                </td>
                <td>
                  <div class="doc-owner-badge" style="margin: 0;">
                    <?= app_icon('user', 12) ?> <strong><?= htmlspecialchars($doc['owner_name'] ?: $doc['owner_username']) ?></strong>
                  </div>
                </td>
                <td>
                  <?= $expBadgeHtml ?: '<span style="color: var(--text-subtle); font-size: 0.75rem;">-</span>' ?>
                </td>
                <td>
                  <span class="doc-compression-tag" title="Ukuran asli <?= format_doc_size($doc['file_size_raw']) ?> dikompresi menjadi <?= format_doc_size($doc['file_size_compressed']) ?>">
                    <?= format_doc_size($doc['file_size_compressed']) ?>
                    <?php if ($savedDocPct > 0): ?>
                      <br><small style="color: #059669; font-weight: 700;">Hemat <?= $savedDocPct ?>%</small>
                    <?php endif; ?>
                  </span>
                </td>
                <td style="font-size: 0.74rem; color: var(--text-subtle); white-space: nowrap;">
                  <?= date('d M Y', strtotime($doc['shared_at'])) ?>
                </td>
                <td>
                  <div class="vault-table-actions">
                    <a href="download_document.php?id=<?= $doc['id'] ?>&view=1" target="_blank" class="btn-vault-action" title="Lihat">
                      <?= app_icon('eye', 13) ?>
                    </a>
                    <a href="download_document.php?id=<?= $doc['id'] ?>" class="btn-vault-action btn-vault-primary" title="Unduh Berkas Asli">
                      <?= app_icon('download', 13) ?>
                    </a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Tab 3: Dokumen Tim -->
  <div id="vault_pane_team" class="vault-tab-pane" style="display: none;">
    <?php if (empty($teamDocs)): ?>
      <div class="vault-empty-state">
        <div class="vault-empty-icon"><?= app_icon('share', 30) ?></div>
        <h4 style="margin: 0 0 6px 0; color: var(--text-main);">Belum Ada Dokumen Bersama Tim</h4>
        <p style="color: var(--text-subtle); font-size: 0.88rem; margin: 0 0 16px 0;">
          Dokumen SOP, petunjuk teknis, atau berkas kerja yang disetel untuk Seluruh Tim akan tampil di sini.
        </p>
        <button type="button" class="btn btn-primary btn-sm" onclick="openUploadModal('team')">
          + Bagikan Dokumen ke Tim
        </button>
      </div>
    <?php else: ?>
      <!-- Mode 1: Grid Kotak Dokumen Tim -->
      <div class="vault-doc-grid vault-view-mode-grid">
        <?php foreach ($teamDocs as $doc): ?>
          <?php 
            $badge = get_doc_file_badge($doc['file_ext']);
            $isMine = ((int)$doc['user_id'] === $userId);
            $savedDocPct = ($doc['file_size_raw'] > 0 && $doc['file_size_compressed'] < $doc['file_size_raw'])
              ? round((1 - ($doc['file_size_compressed'] / $doc['file_size_raw'])) * 100, 1)
              : 0;
            $expBadgeHtml = get_vault_expiry_badge($doc['expiry_date']);
          ?>
          <div class="vault-doc-card" 
               data-title="<?= htmlspecialchars(strtolower($doc['title'])) ?>" 
               data-file="<?= htmlspecialchars(strtolower($doc['original_filename'])) ?>"
               data-desc="<?= htmlspecialchars(strtolower($doc['description'] ?? '')) ?>"
               data-cat="<?= htmlspecialchars($doc['category']) ?>">
            <div>
              <div class="vault-doc-header">
                <div class="vault-doc-icon" style="background: <?= $badge['bg'] ?>; color: <?= $badge['color'] ?>;">
                  <?= $badge['label'] ?>
                </div>
                <div style="flex: 1; min-width: 0;">
                  <h4 class="vault-doc-title"><?= htmlspecialchars($doc['title']) ?></h4>
                  <div class="vault-doc-sub" title="<?= htmlspecialchars($doc['original_filename']) ?>">
                    <?= htmlspecialchars($doc['original_filename']) ?>
                  </div>
                  <div class="doc-owner-badge">
                    <?= app_icon('user', 12) ?> Pengunggah: <strong><?= $isMine ? 'Anda' : htmlspecialchars($doc['owner_name'] ?: $doc['owner_username']) ?></strong>
                  </div>
                </div>
              </div>

              <?php if (!empty($doc['description'])): ?>
                <div style="font-size: 0.8rem; color: var(--text-subtle); margin-top: 8px; line-height: 1.4; word-break: break-word;">
                  <?= nl2br(htmlspecialchars($doc['description'])) ?>
                </div>
              <?php endif; ?>

              <div class="vault-doc-meta">
                <span class="doc-category-badge"><?= htmlspecialchars($doc['category']) ?></span>
                <span class="doc-privacy-badge doc-privacy-team">
                  <?= app_icon('share', 11) ?> Seluruh Tim
                </span>
                <?= $expBadgeHtml ?>
                <span class="doc-compression-tag" title="Ukuran asli <?= format_doc_size($doc['file_size_raw']) ?> dikompresi menjadi <?= format_doc_size($doc['file_size_compressed']) ?>">
                  <?= format_doc_size($doc['file_size_compressed']) ?>
                  <?php if ($savedDocPct > 0): ?>
                    (Hemat <?= $savedDocPct ?>%)
                  <?php endif; ?>
                </span>
              </div>
            </div>

            <div class="vault-doc-actions">
              <span style="font-size: 0.72rem; color: var(--text-subtle);">
                <?= date('d M Y', strtotime($doc['created_at'])) ?>
              </span>
              <div class="vault-btn-group">
                <a href="download_document.php?id=<?= $doc['id'] ?>&view=1" target="_blank" class="btn-vault-action" title="Pratinjau Berkas">
                  <?= app_icon('eye', 13) ?>
                  <span>Lihat</span>
                </a>
                <a href="download_document.php?id=<?= $doc['id'] ?>" class="btn-vault-action btn-vault-primary" title="Unduh Berkas Asli">
                  <?= app_icon('download', 13) ?>
                  <span>Unduh</span>
                </a>
                <?php if ($isMine): ?>
                  <button type="button" class="btn-vault-action" onclick="openShareModal(<?= htmlspecialchars(json_encode($doc)) ?>)" title="Atur Hak Akses">
                    <?= app_icon('share', 13) ?>
                  </button>
                  <button type="button" class="btn-vault-action btn-vault-danger" onclick="confirmDeleteDoc(<?= $doc['id'] ?>, '<?= htmlspecialchars(addslashes($doc['title'])) ?>')" title="Hapus Dokumen">
                    <?= app_icon('trash', 13) ?>
                  </button>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Mode 2: Detail Daftar / Tabel Dokumen Tim -->
      <div class="vault-doc-table-wrapper vault-view-mode-detail" style="display: none;">
        <table class="vault-doc-table">
          <thead>
            <tr>
              <th style="width: 48px; text-align: center;">Tipe</th>
              <th>Judul &amp; Nama File</th>
              <th>Kategori</th>
              <th>Pengunggah</th>
              <th>Masa Berlaku</th>
              <th>Ukuran</th>
              <th>Tanggal</th>
              <th style="text-align: right; width: 140px;">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($teamDocs as $doc): ?>
              <?php
                $badge = get_doc_file_badge($doc['file_ext']);
                $isMine = ((int)$doc['user_id'] === $userId);
                $savedDocPct = ($doc['file_size_raw'] > 0 && $doc['file_size_compressed'] < $doc['file_size_raw'])
                  ? round((1 - ($doc['file_size_compressed'] / $doc['file_size_raw'])) * 100, 1)
                  : 0;
                $expBadgeHtml = get_vault_expiry_badge($doc['expiry_date']);
              ?>
              <tr class="vault-doc-row"
                  data-title="<?= htmlspecialchars(strtolower($doc['title'])) ?>"
                  data-file="<?= htmlspecialchars(strtolower($doc['original_filename'])) ?>"
                  data-desc="<?= htmlspecialchars(strtolower($doc['description'] ?? '')) ?>"
                  data-cat="<?= htmlspecialchars($doc['category']) ?>">
                <td style="text-align: center;">
                  <span class="vault-table-file-badge" style="background: <?= $badge['bg'] ?>; color: <?= $badge['color'] ?>;">
                    <?= $badge['label'] ?>
                  </span>
                </td>
                <td>
                  <div style="font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($doc['title']) ?></div>
                  <div style="font-size: 0.74rem; color: var(--text-subtle);"><?= htmlspecialchars($doc['original_filename']) ?></div>
                </td>
                <td>
                  <span class="doc-category-badge"><?= htmlspecialchars($doc['category']) ?></span>
                </td>
                <td>
                  <div class="doc-owner-badge" style="margin: 0;">
                    <?= app_icon('user', 12) ?> <strong><?= $isMine ? 'Anda' : htmlspecialchars($doc['owner_name'] ?: $doc['owner_username']) ?></strong>
                  </div>
                </td>
                <td>
                  <?= $expBadgeHtml ?: '<span style="color: var(--text-subtle); font-size: 0.75rem;">-</span>' ?>
                </td>
                <td>
                  <span class="doc-compression-tag" title="Ukuran asli <?= format_doc_size($doc['file_size_raw']) ?> dikompresi menjadi <?= format_doc_size($doc['file_size_compressed']) ?>">
                    <?= format_doc_size($doc['file_size_compressed']) ?>
                    <?php if ($savedDocPct > 0): ?>
                      <br><small style="color: #059669; font-weight: 700;">Hemat <?= $savedDocPct ?>%</small>
                    <?php endif; ?>
                  </span>
                </td>
                <td style="font-size: 0.74rem; color: var(--text-subtle); white-space: nowrap;">
                  <?= date('d M Y', strtotime($doc['created_at'])) ?>
                </td>
                <td>
                  <div class="vault-table-actions">
                    <a href="download_document.php?id=<?= $doc['id'] ?>&view=1" target="_blank" class="btn-vault-action" title="Lihat">
                      <?= app_icon('eye', 13) ?>
                    </a>
                    <a href="download_document.php?id=<?= $doc['id'] ?>" class="btn-vault-action btn-vault-primary" title="Unduh Berkas Asli">
                      <?= app_icon('download', 13) ?>
                    </a>
                    <?php if ($isMine): ?>
                      <button type="button" class="btn-vault-action" onclick="openShareModal(<?= htmlspecialchars(json_encode($doc)) ?>)" title="Atur Hak Akses">
                        <?= app_icon('share', 13) ?>
                      </button>
                      <button type="button" class="btn-vault-action btn-vault-danger" onclick="confirmDeleteDoc(<?= $doc['id'] ?>, '<?= htmlspecialchars(addslashes($doc['title'])) ?>')" title="Hapus Dokumen">
                        <?= app_icon('trash', 13) ?>
                      </button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</main>

<!-- Modal 1: Upload / Edit Dokumen -->
<div class="vault-modal-backdrop" id="modal_upload_doc">
  <form id="form_upload_doc" class="vault-modal-dialog" onsubmit="submitDocForm(event)">
    <div class="vault-modal-header">
      <h3 id="modal_upload_title">
        <?= app_icon('folder-plus', 20) ?>
        <span id="modal_upload_title_text">Upload Dokumen Baru</span>
      </h3>
      <button type="button" class="vault-modal-close" onclick="closeUploadModal()" aria-label="Tutup">&times;</button>
    </div>
    
    <div class="vault-modal-body">
      <input type="hidden" name="document_id" id="doc_field_id" value="0">
      
      <div class="vault-form-group">
        <label for="doc_field_title">Judul Dokumen <span style="color: #dc2626;">*</span></label>
        <input type="text" name="title" id="doc_field_title" class="vault-form-control" placeholder="Contoh: Sertifikat K3 Ketinggian 2026, Pass Bandara KNO" required>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
        <div class="vault-form-group">
          <label for="doc_field_category">Kategori Dokumen</label>
          <select name="category" id="doc_field_category" class="vault-form-control" onchange="handleCategorySelectChange(this)">
            <?php foreach ($allDocCategories as $cat): ?>
              <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
            <?php endforeach; ?>
            <option value="__new__">+ Tambah Kategori Baru...</option>
          </select>
          <div id="box_custom_category" style="display: none; margin-top: 6px;">
            <input type="text" id="doc_field_category_custom" class="vault-form-control" placeholder="Tulis nama kategori baru..." style="border-color: var(--primary);">
            <div style="font-size: 0.7rem; color: var(--text-subtle); margin-top: 2px;">Kategori ini akan langsung tersimpan pada dokumen.</div>
          </div>
        </div>
        <div class="vault-form-group">
          <label for="doc_field_expiry">Masa Berlaku (Opsional)</label>
          <input type="date" name="expiry_date" id="doc_field_expiry" class="vault-form-control" title="Berguna untuk Pass Bandara atau Lisensi K3 dengan tanggal kadaluarsa">
        </div>
      </div>

      <div class="vault-form-group">
        <label for="doc_field_desc">Catatan / Keterangan (Opsional)</label>
        <textarea name="description" id="doc_field_desc" class="vault-form-control" rows="2" placeholder="Catatan tambahan nomor lisensi, instansi penerbit, dll."></textarea>
      </div>

      <!-- Privacy Selection -->
      <div class="vault-form-group">
        <label>Hak Akses &amp; Privasi Dokumen</label>
        <div class="vault-privacy-options">
          <label class="vault-privacy-option">
            <input type="radio" name="privacy_type" value="private" id="priv_private" checked onchange="toggleShareUsersBox()">
            <div>
              <div style="font-weight: 700; font-size: 0.88rem; color: var(--text-main);">
                🔒 Pribadi (Hanya Saya)
              </div>
              <div style="font-size: 0.75rem; color: var(--text-subtle);">
                Hanya Anda yang dapat melihat dan mengunduh berkas ini. Super Admin pun tidak dapat melihatnya.
              </div>
            </div>
          </label>

          <label class="vault-privacy-option">
            <input type="radio" name="privacy_type" value="shared_users" id="priv_shared" onchange="toggleShareUsersBox()">
            <div>
              <div style="font-weight: 700; font-size: 0.88rem; color: var(--text-main);">
                👥 Bagikan ke Rekan Tertentu
              </div>
              <div style="font-size: 0.75rem; color: var(--text-subtle);">
                Pilih rekan kerja spesifik yang diizinkan melihat dan mengunduh berkas ini.
              </div>
            </div>
          </label>

          <label class="vault-privacy-option">
            <input type="radio" name="privacy_type" value="team" id="priv_team" onchange="toggleShareUsersBox()">
            <div>
              <div style="font-weight: 700; font-size: 0.88rem; color: var(--text-main);">
                🌐 Seluruh Tim NOC
              </div>
              <div style="font-size: 0.75rem; color: var(--text-subtle);">
                Seluruh staf dan teknisi yang terdaftar di aplikasi dapat melihat berkas ini.
              </div>
            </div>
          </label>
        </div>

        <!-- User Checklist (visible when shared_users chosen) -->
        <div id="box_share_users" style="display: none; margin-top: 8px;">
          <div style="font-size: 0.8rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px;">
            Pilih Rekan yang Diberikan Akses:
          </div>
          <div class="vault-user-checklist">
            <?php if (empty($activeUsers)): ?>
              <div style="font-size: 0.8rem; color: var(--text-subtle); padding: 4px 0;">Tidak ada pengguna lain terdaftar.</div>
            <?php else: ?>
              <?php foreach ($activeUsers as $u): ?>
                <label class="vault-user-item">
                  <input type="checkbox" name="share_user_ids[]" value="<?= $u['id'] ?>" class="cb-share-user">
                  <span>
                    <strong><?= htmlspecialchars($u['full_name'] ?: $u['username']) ?></strong>
                    <span style="font-size: 0.72rem; color: var(--text-subtle);">(@<?= htmlspecialchars($u['username']) ?> &bull; <?= htmlspecialchars($u['role']) ?>)</span>
                  </span>
                </label>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- File Picker -->
      <div class="vault-form-group" id="box_file_picker">
        <label id="lbl_doc_file">Pilih Berkas Dokumen <span style="color: #dc2626;">*</span></label>
        <div class="vault-file-dropzone" onclick="document.getElementById('input_doc_file').click()">
          <div style="color: var(--primary); margin-bottom: 6px;">
            <?= app_icon('folder-plus', 32) ?>
          </div>
          <div style="font-size: 0.88rem; font-weight: 700; color: var(--text-main);" id="dropzone_label">
            Klik atau geser berkas ke sini
          </div>
          <div style="font-size: 0.75rem; color: var(--text-subtle); margin-top: 4px;">
            Word (.docx, .doc), Excel (.xlsx, .xls), PPT (.pptx), PDF, Gambar (.jpg, .png, .webp), Arsip (.zip). Otomatis terkompresi. Maks 64 MB.
          </div>
        </div>
        <input type="file" id="input_doc_file" name="document_file" style="display: none;" onchange="handleFileSelected(this)">
        <div id="selected_file_preview" style="display: none; margin-top: 8px; font-size: 0.82rem; color: var(--primary); font-weight: 700;"></div>
      </div>

      <div id="upload_alert" style="display: none; padding: 10px; border-radius: 8px; font-size: 0.85rem; margin-top: 10px;"></div>
    </div>
    
    <div class="vault-modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeUploadModal()">Batal</button>
      <button type="submit" class="btn btn-primary" id="btn_submit_doc" style="min-width: 140px; font-weight: 700;">
        Simpan Dokumen
      </button>
    </div>
  </form>
</div>

<!-- Modal 2: Atur Hak Akses Berbagi Saja (Share Modal) -->
<div class="vault-modal-backdrop" id="modal_share_doc">
  <form id="form_share_doc" class="vault-modal-dialog" onsubmit="submitShareForm(event)">
    <div class="vault-modal-header">
      <h3>
        <?= app_icon('share', 20) ?>
        <span>Atur Hak Akses Berbagi</span>
      </h3>
      <button type="button" class="vault-modal-close" onclick="closeShareModal()" aria-label="Tutup">&times;</button>
    </div>
    
    <div class="vault-modal-body">
      <input type="hidden" name="document_id" id="share_doc_id" value="0">
      <div style="font-weight: 700; font-size: 0.95rem; color: var(--text-main); margin-bottom: 4px;" id="share_doc_title_text">
        -
      </div>
      <div style="font-size: 0.78rem; color: var(--text-subtle); margin-bottom: 14px;" id="share_doc_file_text">
        -
      </div>

      <div class="vault-privacy-options">
        <label class="vault-privacy-option">
          <input type="radio" name="privacy_type" value="private" id="modal_share_priv_private" onchange="toggleModalShareBox()">
          <div>
            <div style="font-weight: 700; font-size: 0.88rem; color: var(--text-main);">
              🔒 Pribadi (Hanya Saya)
            </div>
            <div style="font-size: 0.75rem; color: var(--text-subtle);">
              Tutup akses berbagi. Hanya Anda yang dapat melihat berkas ini.
            </div>
          </div>
        </label>

        <label class="vault-privacy-option">
          <input type="radio" name="privacy_type" value="shared_users" id="modal_share_priv_shared" onchange="toggleModalShareBox()">
          <div>
            <div style="font-weight: 700; font-size: 0.88rem; color: var(--text-main);">
              👥 Bagikan ke Rekan Tertentu
            </div>
            <div style="font-size: 0.75rem; color: var(--text-subtle);">
              Pilih rekan kerja yang diizinkan mengunduh dan membaca berkas ini.
            </div>
          </div>
        </label>

        <label class="vault-privacy-option">
          <input type="radio" name="privacy_type" value="team" id="modal_share_priv_team" onchange="toggleModalShareBox()">
          <div>
            <div style="font-weight: 700; font-size: 0.88rem; color: var(--text-main);">
              🌐 Seluruh Tim NOC
            </div>
            <div style="font-size: 0.75rem; color: var(--text-subtle);">
              Buka akses untuk semua staf dan teknisi yang terdaftar.
            </div>
          </div>
        </label>
      </div>

      <div id="box_modal_share_users" style="display: none; margin-top: 10px;">
        <div style="font-size: 0.8rem; font-weight: 700; color: var(--text-main); margin-bottom: 4px;">
          Beri Tanda Centang untuk Rekan yang Diizinkan:
        </div>
        <div class="vault-user-checklist">
          <?php foreach ($activeUsers as $u): ?>
            <label class="vault-user-item">
              <input type="checkbox" name="share_user_ids[]" value="<?= $u['id'] ?>" class="cb-modal-share-user">
              <span>
                <strong><?= htmlspecialchars($u['full_name'] ?: $u['username']) ?></strong>
                <span style="font-size: 0.72rem; color: var(--text-subtle);">(@<?= htmlspecialchars($u['username']) ?> &bull; <?= htmlspecialchars($u['role']) ?>)</span>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div id="share_alert" style="display: none; padding: 10px; border-radius: 8px; font-size: 0.85rem; margin-top: 12px;"></div>
    </div>
    
    <div class="vault-modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeShareModal()">Batal</button>
      <button type="submit" class="btn btn-primary" id="btn_submit_share" style="min-width: 140px; font-weight: 700;">
        Simpan Hak Akses
      </button>
    </div>
  </form>
</div>

<script>
  // View Mode: Grid vs Detail List
  function setVaultViewMode(mode) {
    if (mode !== 'detail') mode = 'grid';
    try {
      localStorage.setItem('apsd_vault_view_mode', mode);
    } catch (e) {}

    var btnGrid = document.getElementById('btn_view_grid');
    var btnDetail = document.getElementById('btn_view_detail');
    if (btnGrid && btnDetail) {
      if (mode === 'detail') {
        btnGrid.classList.remove('active');
        btnDetail.classList.add('active');
      } else {
        btnDetail.classList.remove('active');
        btnGrid.classList.add('active');
      }
    }

    var grids = document.querySelectorAll('.vault-view-mode-grid');
    var details = document.querySelectorAll('.vault-view-mode-detail');

    grids.forEach(function(el) {
      el.style.display = (mode === 'grid') ? 'grid' : 'none';
    });
    details.forEach(function(el) {
      el.style.display = (mode === 'detail') ? 'block' : 'none';
    });

    applyVaultFilter();
  }

  // Tab Switching
  function switchVaultTab(tab) {
    var tabs = ['my', 'shared', 'team'];
    tabs.forEach(function(t) {
      var btn = document.getElementById('tab_btn_' + t);
      var pane = document.getElementById('vault_pane_' + t);
      if (btn && pane) {
        if (t === tab) {
          btn.classList.add('active');
          btn.setAttribute('aria-selected', 'true');
          pane.style.display = 'block';
        } else {
          btn.classList.remove('active');
          btn.setAttribute('aria-selected', 'false');
          pane.style.display = 'none';
        }
      }
    });
    applyVaultFilter();
  }

  // Filtering by search term and category across both Grid and Table
  function applyVaultFilter() {
    var query = (document.getElementById('vault_search_input').value || '').toLowerCase().trim();
    var category = document.getElementById('vault_category_select').value;
    
    var activePane = document.querySelector('.vault-tab-pane:not([style*="display: none"])');
    if (!activePane) return;

    // Filter Grid Cards
    var cards = activePane.querySelectorAll('.vault-doc-card');
    cards.forEach(function(card) {
      var title = card.getAttribute('data-title') || '';
      var file = card.getAttribute('data-file') || '';
      var desc = card.getAttribute('data-desc') || '';
      var cat = card.getAttribute('data-cat') || '';

      var matchQuery = !query || title.indexOf(query) !== -1 || file.indexOf(query) !== -1 || desc.indexOf(query) !== -1;
      var matchCat = !category || cat === category;

      card.style.display = (matchQuery && matchCat) ? 'flex' : 'none';
    });

    // Filter Detail Table Rows
    var rows = activePane.querySelectorAll('.vault-doc-row');
    rows.forEach(function(row) {
      var title = row.getAttribute('data-title') || '';
      var file = row.getAttribute('data-file') || '';
      var desc = row.getAttribute('data-desc') || '';
      var cat = row.getAttribute('data-cat') || '';

      var matchQuery = !query || title.indexOf(query) !== -1 || file.indexOf(query) !== -1 || desc.indexOf(query) !== -1;
      var matchCat = !category || cat === category;

      row.style.display = (matchQuery && matchCat) ? '' : 'none';
    });
  }

  // Handle Custom Category Toggle
  function handleCategorySelectChange(select) {
    var customBox = document.getElementById('box_custom_category');
    var customInput = document.getElementById('doc_field_category_custom');
    if (select.value === '__new__') {
      customBox.style.display = 'block';
      customInput.required = true;
      customInput.focus();
    } else {
      customBox.style.display = 'none';
      customInput.required = false;
    }
  }

  // File Picker & Dropzone
  function handleFileSelected(input) {
    var preview = document.getElementById('selected_file_preview');
    var dropLabel = document.getElementById('dropzone_label');
    if (input.files && input.files[0]) {
      var f = input.files[0];
      var sz = (f.size / (1024 * 1024)).toFixed(2) + ' MB';
      preview.style.display = 'block';
      preview.textContent = 'Berkas dipilih: ' + f.name + ' (' + sz + ')';
      dropLabel.textContent = f.name;

      var titleInput = document.getElementById('doc_field_title');
      if (!titleInput.value.trim()) {
        var cleanName = f.name.replace(/\.[^/.]+$/, "");
        titleInput.value = cleanName;
      }
    } else {
      preview.style.display = 'none';
      dropLabel.textContent = 'Klik atau geser berkas ke sini';
    }
  }

  var dropzone = document.querySelector('.vault-file-dropzone');
  if (dropzone) {
    ['dragenter', 'dragover'].forEach(function(ev) {
      dropzone.addEventListener(ev, function(e) {
        e.preventDefault();
        dropzone.classList.add('dragover');
      }, false);
    });
    ['dragleave', 'drop'].forEach(function(ev) {
      dropzone.addEventListener(ev, function(e) {
        e.preventDefault();
        dropzone.classList.remove('dragover');
      }, false);
    });
    dropzone.addEventListener('drop', function(e) {
      var dt = e.dataTransfer;
      var files = dt.files;
      if (files && files.length) {
        var input = document.getElementById('input_doc_file');
        input.files = files;
        handleFileSelected(input);
      }
    }, false);
  }

  // Privacy toggles in Upload Modal
  function toggleShareUsersBox() {
    var isShared = document.getElementById('priv_shared').checked;
    document.getElementById('box_share_users').style.display = isShared ? 'block' : 'none';
  }

  // Privacy toggles in Share Modal
  function toggleModalShareBox() {
    var isShared = document.getElementById('modal_share_priv_shared').checked;
    document.getElementById('box_modal_share_users').style.display = isShared ? 'block' : 'none';
  }

  // Open Upload Modal
  function openUploadModal(defaultPrivacy) {
    document.getElementById('form_upload_doc').reset();
    document.getElementById('doc_field_id').value = '0';
    document.getElementById('modal_upload_title_text').textContent = 'Upload Dokumen Baru';
    document.getElementById('selected_file_preview').style.display = 'none';
    document.getElementById('dropzone_label').textContent = 'Klik atau geser berkas ke sini';
    document.getElementById('upload_alert').style.display = 'none';
    document.getElementById('input_doc_file').required = true;
    document.getElementById('lbl_doc_file').innerHTML = 'Pilih Berkas Dokumen <span style="color: #dc2626;">*</span>';

    // Reset custom category
    document.getElementById('box_custom_category').style.display = 'none';
    document.getElementById('doc_field_category_custom').value = '';
    document.getElementById('doc_field_category_custom').required = false;
    document.getElementById('doc_field_category').selectedIndex = 0;

    var cbs = document.querySelectorAll('.cb-share-user');
    cbs.forEach(function(cb) { cb.checked = false; });

    if (defaultPrivacy === 'team') {
      document.getElementById('priv_team').checked = true;
    } else {
      document.getElementById('priv_private').checked = true;
    }
    toggleShareUsersBox();

    document.body.style.overflow = 'hidden';
    document.getElementById('modal_upload_doc').style.display = 'flex';
  }

  // Open Edit Modal
  function openEditDocModal(doc) {
    document.getElementById('form_upload_doc').reset();
    document.getElementById('doc_field_id').value = doc.id;
    document.getElementById('modal_upload_title_text').textContent = 'Edit Dokumen';
    document.getElementById('doc_field_title').value = doc.title || '';
    document.getElementById('doc_field_expiry').value = doc.expiry_date || '';
    document.getElementById('doc_field_desc').value = doc.description || '';
    document.getElementById('input_doc_file').required = false;
    document.getElementById('lbl_doc_file').innerHTML = 'Ganti Berkas (Opsional, biarkan kosong jika tidak ingin mengganti)';
    document.getElementById('dropzone_label').textContent = 'Berkas saat ini: ' + doc.original_filename;
    document.getElementById('selected_file_preview').style.display = 'none';
    document.getElementById('upload_alert').style.display = 'none';

    // Category handling
    var catSelect = document.getElementById('doc_field_category');
    var customBox = document.getElementById('box_custom_category');
    var customInput = document.getElementById('doc_field_category_custom');
    customBox.style.display = 'none';
    customInput.required = false;
    customInput.value = '';

    var found = false;
    for (var i = 0; i < catSelect.options.length; i++) {
      if (catSelect.options[i].value === doc.category) {
        catSelect.selectedIndex = i;
        found = true;
        break;
      }
    }
    if (!found && doc.category) {
      var opt = document.createElement('option');
      opt.value = doc.category;
      opt.textContent = doc.category;
      catSelect.insertBefore(opt, catSelect.querySelector('option[value="__new__"]'));
      catSelect.value = doc.category;
    }

    // Privacy type
    if (doc.privacy_type === 'shared_users') {
      document.getElementById('priv_shared').checked = true;
    } else if (doc.privacy_type === 'team') {
      document.getElementById('priv_team').checked = true;
    } else {
      document.getElementById('priv_private').checked = true;
    }
    toggleShareUsersBox();

    var sharedIds = (doc.shared_user_ids || '').split(',').map(function(s) { return s.trim(); });
    var cbs = document.querySelectorAll('.cb-share-user');
    cbs.forEach(function(cb) {
      cb.checked = sharedIds.indexOf(cb.value) !== -1;
    });

    document.body.style.overflow = 'hidden';
    document.getElementById('modal_upload_doc').style.display = 'flex';
  }

  function closeUploadModal() {
    document.body.style.overflow = '';
    document.getElementById('modal_upload_doc').style.display = 'none';
  }

  // Open Share Modal
  function openShareModal(doc) {
    document.getElementById('share_doc_id').value = doc.id;
    document.getElementById('share_doc_title_text').textContent = doc.title;
    document.getElementById('share_doc_file_text').textContent = 'Berkas: ' + doc.original_filename;
    document.getElementById('share_alert').style.display = 'none';

    if (doc.privacy_type === 'shared_users') {
      document.getElementById('modal_share_priv_shared').checked = true;
    } else if (doc.privacy_type === 'team') {
      document.getElementById('modal_share_priv_team').checked = true;
    } else {
      document.getElementById('modal_share_priv_private').checked = true;
    }
    toggleModalShareBox();

    var sharedIds = (doc.shared_user_ids || '').split(',').map(function(s) { return s.trim(); });
    var cbs = document.querySelectorAll('.cb-modal-share-user');
    cbs.forEach(function(cb) {
      cb.checked = sharedIds.indexOf(cb.value) !== -1;
    });

    document.body.style.overflow = 'hidden';
    document.getElementById('modal_share_doc').style.display = 'flex';
  }

  function closeShareModal() {
    document.body.style.overflow = '';
    document.getElementById('modal_share_doc').style.display = 'none';
  }

  // Submit Upload / Edit Form
  function submitDocForm(e) {
    e.preventDefault();
    var form = document.getElementById('form_upload_doc');
    var btn = document.getElementById('btn_submit_doc');
    var alertBox = document.getElementById('upload_alert');

    var formData = new FormData(form);

    var catSelect = document.getElementById('doc_field_category');
    if (catSelect.value === '__new__') {
      var customVal = (document.getElementById('doc_field_category_custom').value || '').trim();
      if (!customVal) {
        alertBox.textContent = 'Silakan tulis nama kategori baru.';
        alertBox.style.display = 'block';
        alertBox.style.background = '#fee2e2';
        alertBox.style.color = '#991b1b';
        alertBox.style.border = '1px solid #fecaca';
        return;
      }
      formData.set('category', customVal);
    }

    btn.disabled = true;
    btn.innerHTML = 'Sedang Mengompres &amp; Menyimpan...';
    alertBox.style.display = 'none';

    fetch('api/save_document.php', {
      method: 'POST',
      body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (data.success) {
        alertBox.className = '';
        alertBox.style.background = '#d1fae5';
        alertBox.style.color = '#065f46';
        alertBox.style.border = '1px solid #a7f3d0';
        alertBox.textContent = data.message || 'Dokumen berhasil disimpan!';
        alertBox.style.display = 'block';
        setTimeout(function() {
          window.location.reload();
        }, 800);
      } else {
        btn.disabled = false;
        btn.innerHTML = 'Simpan Dokumen';
        alertBox.style.background = '#fee2e2';
        alertBox.style.color = '#991b1b';
        alertBox.style.border = '1px solid #fecaca';
        alertBox.textContent = data.message || 'Gagal menyimpan dokumen.';
        alertBox.style.display = 'block';
      }
    })
    .catch(function(err) {
      btn.disabled = false;
      btn.innerHTML = 'Simpan Dokumen';
      alertBox.style.background = '#fee2e2';
      alertBox.style.color = '#991b1b';
      alertBox.style.border = '1px solid #fecaca';
      alertBox.textContent = 'Terjadi gangguan koneksi: ' + err.message;
      alertBox.style.display = 'block';
    });
  }

  // Submit Share Form
  function submitShareForm(e) {
    e.preventDefault();
    var form = document.getElementById('form_share_doc');
    var btn = document.getElementById('btn_submit_share');
    var alertBox = document.getElementById('share_alert');

    var formData = new FormData(form);
    btn.disabled = true;
    btn.innerHTML = 'Menyimpan Hak Akses...';
    alertBox.style.display = 'none';

    fetch('api/share_document.php', {
      method: 'POST',
      body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (data.success) {
        alertBox.style.background = '#d1fae5';
        alertBox.style.color = '#065f46';
        alertBox.style.border = '1px solid #a7f3d0';
        alertBox.textContent = data.message || 'Hak akses berhasil diperbarui!';
        alertBox.style.display = 'block';
        setTimeout(function() {
          window.location.reload();
        }, 800);
      } else {
        btn.disabled = false;
        btn.innerHTML = 'Simpan Hak Akses';
        alertBox.style.background = '#fee2e2';
        alertBox.style.color = '#991b1b';
        alertBox.style.border = '1px solid #fecaca';
        alertBox.textContent = data.message || 'Gagal memperbarui hak akses.';
        alertBox.style.display = 'block';
      }
    })
    .catch(function(err) {
      btn.disabled = false;
      btn.innerHTML = 'Simpan Hak Akses';
      alertBox.style.background = '#fee2e2';
      alertBox.style.color = '#991b1b';
      alertBox.style.border = '1px solid #fecaca';
      alertBox.textContent = 'Terjadi gangguan koneksi: ' + err.message;
      alertBox.style.display = 'block';
    });
  }

  // Confirm Delete
  function confirmDeleteDoc(id, title) {
    if (!confirm('Apakah Anda yakin ingin menghapus berkas "' + title + '" dari brankas dokumen Anda?\n\nBerkas fisik dan data di server akan dihapus secara permanen.')) {
      return;
    }

    var formData = new FormData();
    formData.append('document_id', id);

    fetch('api/delete_document.php', {
      method: 'POST',
      body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (data.success) {
        alert(data.message);
        window.location.reload();
      } else {
        alert('Gagal: ' + data.message);
      }
    })
    .catch(function(err) {
      alert('Terjadi kesalahan koneksi: ' + err.message);
    });
  }

  // Close modals on Escape key
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' || e.keyCode === 27) {
      closeUploadModal();
      closeShareModal();
    }
  });

  // Close modals when clicking backdrop
  ['modal_upload_doc', 'modal_share_doc'].forEach(function(modalId) {
    var modal = document.getElementById(modalId);
    if (modal) {
      modal.addEventListener('click', function(e) {
        if (e.target === modal) {
          closeUploadModal();
          closeShareModal();
        }
      });
    }
  });

  // Initialize view mode on load
  document.addEventListener('DOMContentLoaded', function() {
    var savedView = 'grid';
    try {
      savedView = localStorage.getItem('apsd_vault_view_mode') || 'grid';
    } catch (e) {}
    setVaultViewMode(savedView);
  });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
