<?php
// backend/api/admin/manage.php
// Handles CRUD for students, advisors, teams, assignments.
// Route via ?entity=student|advisor|team|assignment, method = GET|POST|PUT|DELETE

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

cors_headers();
require_admin();
$db     = get_db();
$method = $_SERVER['REQUEST_METHOD'];
$entity = $_GET['entity'] ?? '';
$id     = sanitise_int($_GET['id'] ?? 0);

// ─────────────────────────────────────────────
// STUDENTS
// ─────────────────────────────────────────────
if ($entity === 'student') {
    if ($method === 'GET') {
        if ($id) {
            $stmt = $db->prepare(
                'SELECT u.id, u.name, u.netid, u.email, u.team_id, u.must_reset,
                        t.team_number, t.team_name
                 FROM users u LEFT JOIN teams t ON t.id = u.team_id
                 WHERE u.id = ? AND u.role = \'student\''
            );
            $stmt->execute([$id]);
            json_ok($stmt->fetch() ?: null);
        }
        $stmt = $db->prepare(
            'SELECT u.id, u.name, u.netid, u.email, u.must_reset,
                    t.team_number, t.team_name
             FROM users u LEFT JOIN teams t ON t.id = u.team_id
             WHERE u.role = \'student\' ORDER BY u.name'
        );
        $stmt->execute();
        json_ok($stmt->fetchAll());
    }

    if ($method === 'POST') {
        $b = json_body();
        require_fields($b, ['name','netid','team_id']);
        $stmt = $db->prepare(
            'INSERT INTO users (netid, name, team_id, role, password_hash, must_reset)
             VALUES (?, ?, ?, \'student\', ?, TRUE)'
        );
        $pass = hash_password(sanitise_netid($b['netid']) . '_ChangeMe!');
        $stmt->execute([sanitise_netid($b['netid']), sanitise_string($b['name']),
                        sanitise_int($b['team_id']), $pass]);
        json_ok(['id' => $db->lastInsertId()], 201);
    }

    if ($method === 'PUT' && $id) {
        $b = json_body();
        $stmt = $db->prepare(
            'UPDATE users SET name = COALESCE(?, name),
                              netid = COALESCE(?, netid),
                              team_id = COALESCE(?, team_id),
                              updated_at = NOW()
             WHERE id = ? AND role = \'student\''
        );
        $stmt->execute([
            isset($b['name'])    ? sanitise_string($b['name'])    : null,
            isset($b['netid'])   ? sanitise_netid($b['netid'])    : null,
            isset($b['team_id']) ? sanitise_int($b['team_id'])    : null,
            $id,
        ]);
        json_ok();
    }

    if ($method === 'DELETE' && $id) {
        $db->prepare('DELETE FROM users WHERE id = ? AND role = \'student\'')->execute([$id]);
        json_ok();
    }
}

// ─────────────────────────────────────────────
// TEAMS
// ─────────────────────────────────────────────
elseif ($entity === 'team') {
    if ($method === 'GET') {
        if ($id) {
            $stmt = $db->prepare('SELECT * FROM teams WHERE id = ?');
            $stmt->execute([$id]);
            json_ok($stmt->fetch());
        }
        $stmt = $db->prepare('SELECT * FROM teams ORDER BY team_number');
        $stmt->execute();
        json_ok($stmt->fetchAll());
    }
    if ($method === 'POST') {
        $b = json_body();
        require_fields($b, ['team_number','team_name']);
        $stmt = $db->prepare('INSERT INTO teams (team_number, team_name) VALUES (?, ?)');
        $stmt->execute([sanitise_int($b['team_number']), sanitise_string($b['team_name'])]);
        json_ok(['id' => $db->lastInsertId()], 201);
    }
    if ($method === 'PUT' && $id) {
        $b = json_body();
        $stmt = $db->prepare(
            'UPDATE teams SET team_number = COALESCE(?, team_number),
                              team_name   = COALESCE(?, team_name)
             WHERE id = ?'
        );
        $stmt->execute([
            isset($b['team_number']) ? sanitise_int($b['team_number'])      : null,
            isset($b['team_name'])   ? sanitise_string($b['team_name'])     : null,
            $id,
        ]);
        json_ok();
    }
    if ($method === 'DELETE' && $id) {
        $db->prepare('DELETE FROM teams WHERE id = ?')->execute([$id]);
        json_ok();
    }
}

// ─────────────────────────────────────────────
// ASSIGNMENTS
// ─────────────────────────────────────────────
elseif ($entity === 'assignment') {
    if ($method === 'GET') {
        if ($id) {
            $stmt = $db->prepare('SELECT * FROM assignments WHERE id = ?');
            $stmt->execute([$id]);
            json_ok($stmt->fetch());
        }
        $stmt = $db->prepare('SELECT * FROM assignments ORDER BY assignment_number');
        $stmt->execute();
        json_ok($stmt->fetchAll());
    }
    if ($method === 'POST') {
        $b = json_body();
        require_fields($b, ['assignment_number','open_date','due_date','token_value']);
        $stmt = $db->prepare(
            'INSERT INTO assignments (assignment_number, title, open_date, due_date, token_value, is_active)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            sanitise_int($b['assignment_number']),
            sanitise_string($b['title'] ?? ''),
            $b['open_date'],
            $b['due_date'],
            sanitise_int($b['token_value']),
            !empty($b['is_active']),
        ]);
        json_ok(['id' => $db->lastInsertId()], 201);
    }
    if ($method === 'PUT' && $id) {
        $b = json_body();
        $stmt = $db->prepare(
            'UPDATE assignments
             SET title           = COALESCE(?, title),
                 open_date       = COALESCE(?, open_date),
                 due_date        = COALESCE(?, due_date),
                 token_value     = COALESCE(?, token_value),
                 is_active       = COALESCE(?, is_active)
             WHERE id = ?'
        );
        $stmt->execute([
            isset($b['title'])       ? sanitise_string($b['title'])    : null,
            $b['open_date']          ?? null,
            $b['due_date']           ?? null,
            isset($b['token_value']) ? sanitise_int($b['token_value']) : null,
            isset($b['is_active'])   ? (bool)$b['is_active']           : null,
            $id,
        ]);
        json_ok();
    }
    if ($method === 'DELETE' && $id) {
        $db->prepare('DELETE FROM assignments WHERE id = ?')->execute([$id]);
        json_ok();
    }
}

// ─────────────────────────────────────────────
// REOPEN SUBMISSION
// ─────────────────────────────────────────────
elseif ($entity === 'reopen_submission' && $method === 'POST') {
    $b = json_body();
    require_fields($b, ['submission_id']);
    $session = require_admin();
    $stmt = $db->prepare(
        'UPDATE submissions SET is_final = FALSE, reopened_by = ? WHERE id = ?'
    );
    $stmt->execute([$session['user_id'], sanitise_int($b['submission_id'])]);
    json_ok();
}

else {
    json_error("Unknown entity: $entity", 400);
}
