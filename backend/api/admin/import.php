<?php
// backend/api/admin/import.php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

cors_headers();
require_admin();
$db = get_db();

$type = $_GET['type'] ?? '';

// ── Admin Shibboleth update ───────────────────────────────────────────────────
if ($type === 'admin_shibboleth') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_error('Method not allowed', 405); }
    $b        = json_body();
    $session  = require_admin();
    $new_shib = sanitise_string($b['shibboleth'] ?? '', 255);
    if ($new_shib === '') { json_error('Shibboleth code cannot be empty'); }
    update_admin_shibboleth((int)$session['user_id'], $new_shib);
    json_ok('Admin shibboleth updated');
}

$rows = parse_csv_upload('file');
if (empty($rows)) { json_error('CSV contained no data rows'); }

$errors  = [];
$created = 0;

// ─────────────────────────────────────────────
// STUDENT IMPORT
// Required columns: name, netid, team_number
// Optional columns: shibboleth_1, shibboleth_2, ... shibboleth_N (any number)
// ─────────────────────────────────────────────
if ($type === 'students') {
    foreach ($rows as $i => $row) {
        $line = $i + 2;
        foreach (['name', 'netid', 'team_number'] as $f) {
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

        // Upsert student user (no shibboleth_hash — uses per-assignment table)
        $stmt = $db->prepare(
            'INSERT INTO users (netid, name, team_id, role)
             VALUES (?, ?, ?, \'student\')
             ON CONFLICT (netid) DO UPDATE
             SET name = EXCLUDED.name, team_id = EXCLUDED.team_id
             RETURNING id'
        );
        $stmt->execute([$netid, $name, $team['id']]);
        $student_id = $stmt->fetchColumn();
        $created++;

        // Insert shibboleths for each shibboleth_N column found in the row
        foreach ($row as $col => $val) {
            // Match columns named shibboleth_1, shibboleth_2, etc.
            if (!preg_match('/^shibboleth_(\d+)$/i', $col, $matches)) {
                continue;
            }
            $assignment_number = (int) $matches[1];
            $shib_value        = sanitise_string($val, 255);

            if ($shib_value === '') {
                continue; // Skip blank shibboleth columns
            }

            $stmt = $db->prepare(
                'INSERT INTO student_shibboleths (student_id, assignment_number, shibboleth)
                 VALUES (?, ?, ?)
                 ON CONFLICT (student_id, assignment_number)
                 DO UPDATE SET shibboleth = EXCLUDED.shibboleth'
            );
            $stmt->execute([$student_id, $assignment_number, $shib_value]);
        }
    }
}

// ─────────────────────────────────────────────
// TEAM IMPORT
// Required: team_number, team_name
// Optional: advisor_1_netid, advisor_2_netid, advisor_3_netid
// ─────────────────────────────────────────────
elseif ($type === 'teams') {
    foreach ($rows as $i => $row) {
        $line = $i + 2;
        if (empty($row['team_number']) || empty($row['team_name'])) {
            $errors[] = "Row $line: missing team_number or team_name";
            continue;
        }

        // Upsert team
        $stmt = $db->prepare(
            'INSERT INTO teams (team_number, team_name)
             VALUES (?, ?)
             ON CONFLICT (team_number) DO UPDATE SET team_name = EXCLUDED.team_name
             RETURNING id'
        );
        $stmt->execute([sanitise_int($row['team_number']), sanitise_string($row['team_name'])]);
        $team_id = $stmt->fetchColumn();
        $created++;

        // Handle up to 3 advisors
        foreach (['advisor_1_netid', 'advisor_2_netid', 'advisor_3_netid'] as $col) {
            if (empty($row[$col])) { continue; }

            $advisor_netid = sanitise_netid($row[$col]);
            if ($advisor_netid === '') { continue; }

            // Find or create advisor user
            $stmt = $db->prepare('SELECT id FROM users WHERE netid = ?');
            $stmt->execute([$advisor_netid]);
            $advisor = $stmt->fetch();

            if (!$advisor) {
                $stmt = $db->prepare(
                    'INSERT INTO users (netid, name, role)
                     VALUES (?, ?, \'advisor\')
                     ON CONFLICT (netid) DO UPDATE SET role = \'advisor\'
                     RETURNING id'
                );
                $stmt->execute([$advisor_netid, $advisor_netid]);
                $advisor_id = $stmt->fetchColumn();
            } else {
                $advisor_id = $advisor['id'];
                $db->prepare('UPDATE users SET role = \'advisor\' WHERE id = ?')
                   ->execute([$advisor_id]);
            }

            // Link advisor to team
            $stmt = $db->prepare(
                'INSERT INTO team_advisors (team_id, advisor_id)
                 VALUES (?, ?)
                 ON CONFLICT (team_id, advisor_id) DO NOTHING'
            );
            $stmt->execute([$team_id, $advisor_id]);
        }
    }
}

// ─────────────────────────────────────────────
// ASSIGNMENT IMPORT
// Required: assignment_number, title, open_date, due_date, token_value
// shibboleth column optional (assignment-level, not used for student login)
// ─────────────────────────────────────────────
elseif ($type === 'assignments') {
    foreach ($rows as $i => $row) {
        $line = $i + 2;
        foreach (['assignment_number', 'open_date', 'due_date', 'token_value'] as $f) {
            if (empty($row[$f])) {
                $errors[] = "Row $line: missing '$f'";
            }
        }
        if (!empty($errors)) { continue; }

        $stmt = $db->prepare(
            'INSERT INTO assignments (assignment_number, title, open_date, due_date, token_value, shibboleth)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (assignment_number) DO UPDATE
             SET title       = EXCLUDED.title,
                 open_date   = EXCLUDED.open_date,
                 due_date    = EXCLUDED.due_date,
                 token_value = EXCLUDED.token_value,
                 shibboleth  = EXCLUDED.shibboleth'
        );
        $stmt->execute([
            sanitise_int($row['assignment_number']),
            sanitise_string($row['title'] ?? ''),
            $row['open_date'],
            $row['due_date'],
            sanitise_int($row['token_value']),
            sanitise_string($row['shibboleth'] ?? '', 255),
        ]);
        $created++;
    }
}

else {
    json_error("Unknown import type: $type");
}

if (!empty($errors)) {
    json_error('Import completed with errors', 422, [
        'errors'  => $errors,
        'created' => $created,
    ]);
}

json_ok(['created' => $created]);
