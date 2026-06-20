<?php
// backend/includes/auth.php

require_once __DIR__ . '/../config/db.php';

define('SESSION_LIFETIME', 60 * 60 * 8); // 8 hours

// ── Bootstrap a secure session ────────────────────────────────────────────────
function session_start_secure(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}

// ── Authenticate a user and start their session ───────────────────────────────
function login(string $netid, string $password): array|false {
    $db   = get_db();
    $stmt = $db->prepare('SELECT * FROM users WHERE netid = ?');
    $stmt->execute([$netid]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    session_start_secure();
    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['netid']   = $user['netid'];
    $_SESSION['role']    = $user['role'];
    $_SESSION['name']    = $user['name'];
    $_SESSION['team_id'] = $user['team_id'];

    return $user;
}

// ── Destroy session ───────────────────────────────────────────────────────────
function logout(): void {
    session_start_secure();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ── Require a logged-in user (any role) ──────────────────────────────────────
function require_auth(): array {
    session_start_secure();
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthenticated']);
        exit;
    }
    return $_SESSION;
}

// ── Require a specific role (or higher) ──────────────────────────────────────
function require_role(string ...$roles): array {
    $session = require_auth();
    if (!in_array($session['role'], $roles, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    return $session;
}

// Convenience wrappers
function require_admin(): array {
    return require_role('administrator');
}
function require_instructor_or_above(): array {
    return require_role('instructor', 'administrator');
}

// ── Hash a new password ───────────────────────────────────────────────────────
function hash_password(string $plain): string {
    return password_hash($plain, PASSWORD_ARGON2ID);
}

// ── Force-reset a student's password (admin action) ──────────────────────────
function admin_reset_password(int $user_id, string $new_password): void {
    $db   = get_db();
    $stmt = $db->prepare(
        'UPDATE users SET password_hash = ?, must_reset = TRUE, updated_at = NOW() WHERE id = ?'
    );
    $stmt->execute([hash_password($new_password), $user_id]);
}
