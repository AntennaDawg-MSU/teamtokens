<?php
// backend/api/admin/import.php
// POST multipart/form-data with ?type=students|teams|assignments

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

cors_headers();
require_admin();
$db = get_db();

$type = $_GET['type'] ?? '';
$rows = parse_csv_upload('file');

if (empty($rows)) {
    json_error('CSV contained no data rows');
}

$errors  = [];
$created = 0;

// ─────────────────────────────────────────────
// STUDENT IMPORT
// Fields: name, netid, team_number
// ─────────────────────────────────────────────
if ($type === 'students') {
    $required = ['name', 'netid', 'team_number'];
    foreach ($rows as $i => $row) {
        $line = $i + 2;
        foreach ($required as $f) {
            if (empty($row[$f])) {
                $errors[] = "Row $line: missing '$f'";
            }
        }
        if (!empty($errors)) { continue; }

        $netid       = sanitise_netid($row['netid']);
        $name        = sanitise_string($row['name']);
        $team_number = sanitise_int($row['team_number']);

        // Resolve team
        $stmt = $db->prepare('SELECT id FROM teams WHERE team_number = ?');
        $stmt->execute([$team_number]);
        $team = $stmt->fetch();
        if (!$team) {
            $errors[] = "Row $line: team_number $team_number not found";
            continue;
        }

        // Upsert user
        $stmt = $db->prepare(
            'INSERT INTO users (netid, name, team_id, role, password_hash, must_reset)
             VALUES (?, ?, ?, \'student\', ?, TRUE)
             ON CONFLICT (netid) DO UPDATE SET name = EXCLUDED.name, team_id = EXCLUDED.team_id'
        );
        $default_pass = hash_password($netid . '_ChangeMe!');
        $stmt->execute([$netid, $name, $team['id'], $default_pass]);
        $created++;
    }
}

// ─────────────────────────────────────────────
// TEAM IMPORT
// Fields: team_number, team_name
// ─────────────────────────────────────────────
elseif ($type === 'teams') {
    foreach ($rows as $i => $row) {
        $line = $i + 2;
        if (empty($row['team_number']) || empty($row['team_name'])) {
            $errors[] = "Row $line: missing team_number or team_name";
            continue;
        }
        $stmt = $db->prepare(
            'INSERT INTO teams (team_number, team_name)
             VALUES (?, ?)
             ON CONFLICT (team_number) DO UPDATE SET team_name = EXCLUDED.team_name'
        );
        $stmt->execute([sanitise_int($row['team_number']), sanitise_string($row['team_name'])]);
        $created++;
    }
}

// ─────────────────────────────────────────────
// ASSIGNMENT IMPORT
// Fields: assignment_number, title, open_date, due_date, token_value
// ─────────────────────────────────────────────
elseif ($type === 'assignments') {
    foreach ($rows as $i => $row) {
        $line = $i + 2;
        foreach (['assignment_number','open_date','due_date','token_value'] as $f) {
            if (empty($row[$f])) {
                $errors[] = "Row $line: missing '$f'";
            }
        }
        if (!empty($errors)) { continue; }

        $stmt = $db->prepare(
            'INSERT INTO assignments (assignment_number, title, open_date, due_date, token_value)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (assignment_number) DO UPDATE
             SET title = EXCLUDED.title, open_date = EXCLUDED.open_date,
                 due_date = EXCLUDED.due_date, token_value = EXCLUDED.token_value'
        );
        $stmt->execute([
            sanitise_int($row['assignment_number']),
            sanitise_string($row['title'] ?? ''),
            $row['open_date'],
            $row['due_date'],
            sanitise_int($row['token_value']),
        ]);
        $created++;
    }
} else {
    json_error("Unknown import type: $type");
}

if (!empty($errors)) {
    json_error('Import completed with errors', 422, [
        'errors'  => $errors,
        'created' => $created,
    ]);
}

json_ok(['created' => $created]);
