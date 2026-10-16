<?php
/**
 * Authentication, Session & Role-Based Access Control (RBAC)
 * IASS SBU ICT KNO
 * 
 * Rules:
 * - 12-hour session auto-logout (43,200 seconds)
 * - Roles: superadmin, admin, user
 * - Dual environment URL resolution (Docker / & XAMPP /apsd/)
 */

require_once __DIR__ . '/../config/database.php';

const SESSION_LIFETIME = 43200; // 12 jam (12 * 3600 detik)

/**
 * Get dynamic application base URL (works in both Docker root and XAMPP subdirectories)
 */
function get_app_base_url(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = dirname($script);
    if (basename($dir) === 'api' || basename($dir) === 'includes') {
        $dir = dirname($dir);
    }
    $dir = str_replace('\\', '/', $dir);
    return rtrim($dir, '/') . '/';
}

/**
 * Start or resume a secure session with 12-hour lifetime
 */
function start_secure_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // Enforce cookie lifetime & security settings
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
        
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path' => '/',
            'domain' => '',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        
        session_start();
    }

    // Check 12-hour absolute expiration
    if (isset($_SESSION['user_id'])) {
        $loginTime = (int)($_SESSION['login_time'] ?? 0);
        if ($loginTime > 0 && (time() - $loginTime) > SESSION_LIFETIME) {
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            @session_destroy();
            $baseUrl = get_app_base_url();
            header('Location: ' . $baseUrl . 'login?expired=1');
            exit;
        }
        // Update last activity
        $_SESSION['last_activity'] = time();
    }
}

/**
 * Check if current visitor is authenticated
 */
function is_logged_in(): bool {
    start_secure_session();
    return !empty($_SESSION['user_id']);
}

/**
 * Get current logged in user information
 */
function current_user(): ?array {
    start_secure_session();
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'role' => $_SESSION['user_role'] ?? 'user',
        'login_time' => $_SESSION['login_time'] ?? 0
    ];
}

/**
 * Check if logged in user has specific role(s)
 */
function has_role($roles): bool {
    $user = current_user();
    if (!$user) {
        return false;
    }
    if (is_string($roles)) {
        $roles = [$roles];
    }
    return in_array($user['role'], $roles, true);
}

/**
 * Check if the current user can manage (edit/reset password) a target user with a given role
 * Rule:
 * - superadmin can manage: superadmin, admin, user
 * - admin can only manage: user (role below admin)
 * - user cannot manage anyone
 */
function can_manage_role(string $target_role): bool {
    $user = current_user();
    if (!$user) {
        return false;
    }
    if ($user['role'] === 'superadmin') {
        return true;
    }
    if ($user['role'] === 'admin') {
        return $target_role === 'user';
    }
    return false;
}

/**
 * Check if the current user can assign a role to a new/edited user
 * - superadmin can assign: superadmin, admin, user
 * - admin can only assign: user
 */
function can_assign_role(string $role_to_assign): bool {
    $user = current_user();
    if (!$user) {
        return false;
    }
    if ($user['role'] === 'superadmin') {
        return in_array($role_to_assign, ['superadmin', 'admin', 'user'], true);
    }
    if ($user['role'] === 'admin') {
        return $role_to_assign === 'user';
    }
    return false;
}

/**
 * Enforce login on protected pages
 */
function require_login(): void {
    // Allow internal CLI / headless browser PDF generator with signed token
    if (!empty($_GET['pdf_token']) && !empty($_GET['id'])) {
        $expectedToken = hash_hmac('sha256', (string)$_GET['id'], 'apsd_internal_secret_2026');
        if (hash_equals($expectedToken, (string)$_GET['pdf_token'])) {
            return;
        }
    }

    start_secure_session();
    if (!is_logged_in()) {
        $baseUrl = get_app_base_url();
        $currentUri = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: ' . $baseUrl . 'login?redirect=' . urlencode($currentUri));
        exit;
    }
}

/**
 * Enforce role on restricted pages (e.g. User Management)
 */
function require_role($roles): void {
    require_login();
    if (!has_role($roles)) {
        http_response_code(403);
        include __DIR__ . '/../includes/header.php';
        echo '<div class="container" style="max-width: 600px; margin: 4rem auto; text-align: center;">';
        echo '  <div class="card" style="padding: 2.5rem 1.5rem;">';
        echo '    <div style="font-size: 3rem; margin-bottom: 1rem;">🚫</div>';
        echo '    <h2 style="color: var(--status-nok); margin-bottom: 0.5rem;">403 Akses Ditolak</h2>';
        echo '    <p style="color: var(--text-subtle); margin-bottom: 1.5rem;">Halaman ini hanya dapat diakses oleh level wewenang tertentu.</p>';
        echo '    <a href="./" class="btn btn-primary" style="display: inline-block;">Kembali ke Dashboard</a>';
        echo '  </div>';
        echo '</div>';
        include __DIR__ . '/../includes/footer.php';
        exit;
    }
}

/**
 * Perform login authentication
 */
function authenticate_user(string $username, string $password): array {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'Username atau password salah.'];
    }

    if (empty($user['is_active'])) {
        return ['success' => false, 'message' => 'Akun Anda dinonaktifkan. Silakan hubungi Administrator.'];
    }

    if (!password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'message' => 'Username atau password salah.'];
    }

    // Password OK: Start session & regenerate ID
    start_secure_session();
    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();

    // Update last_login in DB
    try {
        $upd = $pdo->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
        $upd->execute([$user['id']]);
    } catch (Exception $e) {}

    return ['success' => true, 'user' => $user];
}

/**
 * Logout user and redirect to login page
 */
function logout_user(string $reason = ''): void {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    @session_destroy();

    $baseUrl = get_app_base_url();
    $query = $reason ? ('?' . urlencode($reason) . '=1') : '';
    header('Location: ' . $baseUrl . 'login' . $query);
    exit;
}
