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

// ── Authenticate a user ───────────────────────────────────────────────────────
// For students: NetID + shibboleth must match the currently active assignment
// For admins/instructors: NetID + shibboleth must match their stored shibboleth_hash
function login(string $netid, string $shibboleth): array|false {
    $db   = get_db();

    // Look up the user
    $stmt = $db->prepare('SELECT * FROM users WHERE netid = ?');
    $stmt->execute([$netid]);
    $user = $stmt->fetch();

    if (!$user) {
        return false;
    }

    // ── Admin / instructor login ──────────────────────────────────────────────
    if (in_array($user['role'], ['administrator', 'instructor'], true)) {
        if (!$user['shibboleth_hash'] ||
            !password_verify($shibboleth, $user['shibboleth_hash'])) {
            return false;
        }
    }

    // ── Student / advisor login ───────────────────────────────────────────────
    else {
        // Find currently active assignment
        $stmt = $db->prepare(
            'SELECT * FROM assignments
             WHERE is_active = TRUE
               AND open_date <= NOW()
               AND due_date  >= NOW()
             ORDER BY due_date ASC
             LIMIT 1'
        );
        $stmt->execute();
        $assignment = $stmt->fetch();

        if (!$assignment) {
            // No active assignment — students cannot log in
            return false;
        }

        // Check shibboleth against active assignment (plain string comparison)
        if (!hash_equals($assignment['shibboleth'], $shibboleth)) {
            return false;
        }

        // Check if student already made a final submission for this assignment
        $stmt = $db->prepare(
            'SELECT is_final FROM submissions
             WHERE student_id = ? AND assignment_id = ?'
        );
        $stmt->execute([$user['id'], $assignment['id']]);
        $sub = $stmt->fetch();

        if ($sub && $sub['is_final']) {
            // Student has already submitted — lock them out
            return ['locked' => true, 'name' => $user['name']];
        }

        // Store active assignment in session
        $_SESSION['assignment_id'] = $assignment['id'];
    }

    // ── Start session ─────────────────────────────────────────────────────────
    session_start_secure();
    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['netid']   = $user['netid'];
    $_SESSION['role']    = $user['role'];
    $_SESSION['name']    = $user['name'];
    $_SESSION['team_id'] = $user['team_id'] ?? null;

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

// ── Require a logged-in user ──────────────────────────────────────────────────
function require_auth(): array {
    session_start_secure();
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthenticated']);
        exit;
    }
    return $_SESSION;
}

// ── Require a specific role ───────────────────────────────────────────────────
function require_role(string ...$roles): array {
    $session = require_auth();
    if (!in_array($session['role'], $roles, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    return $session;
}

function require_admin(): array {
    return require_role('administrator');
}

function require_instructor_or_above(): array {
    return require_role('instructor', 'administrator');
}

// ── Hash a shibboleth (for admin accounts only) ───────────────────────────────
function hash_shibboleth(string $plain): string {
    return password_hash($plain, PASSWORD_BCRYPT);
}

// ── Update admin shibboleth ───────────────────────────────────────────────────
function update_admin_shibboleth(int $user_id, string $new_shibboleth): void {
    $db   = get_db();
    $stmt = $db->prepare(
        'UPDATE users SET shibboleth_hash = ?, updated_at = NOW() WHERE id = ?'
    );
    $stmt->execute([hash_shibboleth($new_shibboleth), $user_id]);
}
