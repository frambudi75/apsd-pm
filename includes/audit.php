<?php
/**
 * Audit Trail & Security Event Logging Helper
 * PT Angkasa Pura Sarana Digital - Sistem PM
 *
 * Provides a resilient, fail-safe audit logging mechanism.
 * All functions are wrapped in catch-all blocks to guarantee
 * zero interference with primary application workflows.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';

/**
 * Safely detect client IP address
 */
if (!function_exists('get_client_ip_safe')) {
    function get_client_ip_safe(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $rawIp = $_SERVER[$header];
                if (strpos($rawIp, ',') !== false) {
                    $parts = explode(',', $rawIp);
                    $rawIp = trim($parts[0]);
                }
                if (filter_var($rawIp, FILTER_VALIDATE_IP)) {
                    return $rawIp;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}

/**
 * Log an audit or security event to the audit_logs table.
 * 100% fail-safe: never throws exceptions, returns true on success, false on error.
 *
 * @param string $actionType e.g. 'auth', 'document', 'external_share', 'daily_activity', 'settings', 'system'
 * @param string $actionName e.g. 'LOGIN_SUCCESS', 'DOCUMENT_UPLOAD', 'DAILY_SHIFT_DELETE'
 * @param string $description Human readable details
 * @param string|null $targetType e.g. 'document', 'daily_shift', 'daily_activity', 'user'
 * @param string|int|null $targetId Affected identifier
 * @param int|null $overrideUserId
 * @param string|null $overrideUsername
 * @param string|null $overrideRole
 * @return bool
 */
function log_audit_event(
    string $actionType,
    string $actionName,
    string $description,
    ?string $targetType = null,
    $targetId = null,
    $overrideUserId = null,
    ?string $overrideUsername = null,
    ?string $overrideRole = null
): bool {
    try {
        $pdo = get_db_connection();
        if (!$pdo) {
            return false;
        }

        // Determine user identity
        $userId = $overrideUserId;
        $username = $overrideUsername;
        $userRole = $overrideRole;

        if (php_sapi_name() === 'cli') {
            $username = $username ?: 'Sistem/CLI';
            $userRole = $userRole ?: 'system';
        } else {
            if ($userId === null && function_exists('current_user')) {
                $curr = current_user();
                if ($curr) {
                    $userId = $curr['id'] ?? null;
                    $username = $username ?: ($curr['username'] ?? null);
                    $userRole = $userRole ?: ($curr['role'] ?? null);
                }
            }

            // Fallback session inspection
            if ($userId === null && isset($_SESSION['user_id'])) {
                $userId = (int)$_SESSION['user_id'];
                $username = $username ?: ($_SESSION['username'] ?? null);
                $userRole = $userRole ?: ($_SESSION['user_role'] ?? null);
            }

            $username = $username ?: 'Sistem/Tamu';
            $userRole = $userRole ?: ($userId ? 'user' : 'guest');
        }

        $ipAddress = get_client_ip_safe();
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr(trim($_SERVER['HTTP_USER_AGENT']), 0, 250) : null;
        $targetIdStr = $targetId !== null ? (string)$targetId : null;

        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (
                user_id, username, user_role, action_type, action_name,
                target_type, target_id, description, ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now', 'localtime'))
        ");

        // Adapt datetime syntax for MySQL if applicable
        $dbConnection = strtolower(getenv('DB_CONNECTION') ?: 'sqlite');
        if ($dbConnection === 'mysql') {
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (
                    user_id, username, user_role, action_type, action_name,
                    target_type, target_id, description, ip_address, user_agent, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
        }

        return $stmt->execute([
            $userId,
            $username,
            $userRole,
            strtoupper(trim($actionType)),
            strtoupper(trim($actionName)),
            $targetType ? strtolower(trim($targetType)) : null,
            $targetIdStr,
            $description,
            $ipAddress,
            $userAgent
        ]);
    } catch (\Throwable $e) {
        // Complete fail-safe: suppress and log to system error log if needed
        error_log('Audit Log error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Fetch paginated audit logs with search filters
 */
function get_audit_logs(array $filters = [], int $limit = 50, int $offset = 0): array {
    try {
        $pdo = get_db_connection();
        $sql = "SELECT * FROM audit_logs WHERE 1=1";
        $params = [];

        if (!empty($filters['action_type'])) {
            $sql .= " AND action_type = ?";
            $params[] = strtoupper(trim($filters['action_type']));
        }

        if (!empty($filters['user_id'])) {
            $sql .= " AND user_id = ?";
            $params[] = (int)$filters['user_id'];
        }

        if (!empty($filters['search'])) {
            $kw = '%' . trim($filters['search']) . '%';
            $sql .= " AND (username LIKE ? OR description LIKE ? OR ip_address LIKE ? OR action_name LIKE ?)";
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $sql .= " ORDER BY id DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('Fetch audit logs error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Count total audit logs matching filters
 */
function count_audit_logs(array $filters = []): int {
    try {
        $pdo = get_db_connection();
        $sql = "SELECT COUNT(*) FROM audit_logs WHERE 1=1";
        $params = [];

        if (!empty($filters['action_type'])) {
            $sql .= " AND action_type = ?";
            $params[] = strtoupper(trim($filters['action_type']));
        }

        if (!empty($filters['user_id'])) {
            $sql .= " AND user_id = ?";
            $params[] = (int)$filters['user_id'];
        }

        if (!empty($filters['search'])) {
            $kw = '%' . trim($filters['search']) . '%';
            $sql .= " AND (username LIKE ? OR description LIKE ? OR ip_address LIKE ? OR action_name LIKE ?)";
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * Get high-level statistics for the audit log dashboard
 */
function get_audit_log_stats(): array {
    try {
        $pdo = get_db_connection();
        $today = date('Y-m-d');

        // Total today
        $stmtToday = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE created_at >= ?");
        $stmtToday->execute([$today . ' 00:00:00']);
        $totalToday = (int)$stmtToday->fetchColumn();

        // Total auth events
        $totalAuth = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action_type = 'AUTH'")->fetchColumn();

        // Total document events
        $totalDocs = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action_type IN ('DOCUMENT', 'EXTERNAL_SHARE')")->fetchColumn();

        // Total daily activity events
        $totalNoc = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action_type = 'DAILY_ACTIVITY'")->fetchColumn();

        // Total overall
        $totalAll = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();

        return [
            'today' => $totalToday,
            'auth' => $totalAuth,
            'documents' => $totalDocs,
            'daily_activity' => $totalNoc,
            'total' => $totalAll
        ];
    } catch (\Throwable $e) {
        return [
            'today' => 0,
            'auth' => 0,
            'documents' => 0,
            'daily_activity' => 0,
            'total' => 0
        ];
    }
}

/**
 * Render visual badge for audit action
 */
function format_audit_badge(string $actionType, string $actionName): string {
    $t = strtoupper($actionType);
    $n = strtoupper($actionName);

    $bg = 'rgba(100, 116, 139, 0.12)';
    $color = '#475569';
    $border = 'rgba(100, 116, 139, 0.25)';

    if (strpos($n, 'SUCCESS') !== false || strpos($n, 'START') !== false || strpos($n, 'OPTIMIZE') !== false) {
        $bg = 'rgba(16, 185, 129, 0.12)';
        $color = '#059669';
        $border = 'rgba(16, 185, 129, 0.28)';
    } elseif (strpos($n, 'FAILED') !== false || strpos($n, 'DELETE') !== false || strpos($n, 'REVOKE') !== false) {
        $bg = 'rgba(239, 68, 68, 0.12)';
        $color = '#dc2626';
        $border = 'rgba(239, 68, 68, 0.28)';
    } elseif (strpos($n, 'SHARE') !== false || strpos($n, 'UPLOAD') !== false) {
        $bg = 'rgba(14, 165, 233, 0.12)';
        $color = '#0284c7';
        $border = 'rgba(14, 165, 233, 0.28)';
    } elseif ($t === 'SETTINGS') {
        $bg = 'rgba(245, 158, 11, 0.12)';
        $color = '#d97706';
        $border = 'rgba(245, 158, 11, 0.28)';
    }

    return sprintf(
        '<span style="display:inline-block; font-size:0.75rem; font-weight:700; padding:2px 8px; border-radius:6px; background:%s; color:%s; border:1px solid %s;">%s</span>',
        $bg, $color, $border, htmlspecialchars($n)
    );
}
