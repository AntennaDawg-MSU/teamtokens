<?php
// backend/api/admin/reports.php
// GET ?type=student|advisor|team  &id=X  &export=csv

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

cors_headers();
require_instructor_or_above();
$db = get_db();

$type   = $_GET['type']   ?? '';
$id     = sanitise_int($_GET['id']  ?? 0);
$export = $_GET['export'] ?? '';

// ─────────────────────────────────────────────
// STUDENT REPORT
// ─────────────────────────────────────────────
if ($type === 'student') {
    if (!$id) { json_error('id required for student report'); }

    // Basic info
    $stmt = $db->prepare('SELECT id, name, netid FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $student = $stmt->fetch();
    if (!$student) { json_error('Student not found', 404); }

    // Per-assignment summary
    $stmt = $db->prepare(
        'SELECT
             a.assignment_number,
             a.title,
             s.submitted_at,
             s.is_final,
             s.has_warnings,
             COALESCE(SUM(ta.tokens), 0)                        AS tokens_cast,
             COALESCE(
                (SELECT SUM(t2.tokens)
                 FROM token_allocations t2
                 JOIN submissions s2 ON s2.id = t2.submission_id
                 WHERE t2.recipient_id = u.id AND s2.assignment_id = a.id), 0
             )                                                   AS tokens_received
         FROM users u
         JOIN assignments a ON TRUE
         LEFT JOIN submissions s      ON s.student_id = u.id AND s.assignment_id = a.id
         LEFT JOIN token_allocations ta ON ta.submission_id = s.id
         WHERE u.id = ?
         GROUP BY u.id, a.id, s.id
         ORDER BY a.assignment_number'
    );
    $stmt->execute([$id]);
    $rows = $stmt->fetchAll();

    if ($export === 'csv') {
        send_csv("student_{$student['netid']}_report.csv", $rows);
    }

    json_ok(['student' => $student, 'assignments' => $rows]);
}

// ─────────────────────────────────────────────
// ADVISOR REPORT
// ─────────────────────────────────────────────
elseif ($type === 'advisor') {
    if (!$id) { json_error('id required for advisor report'); }

    $stmt = $db->prepare('SELECT id, name FROM users WHERE id = ? AND role = \'advisor\'');
    $stmt->execute([$id]);
    $advisor = $stmt->fetch();
    if (!$advisor) { json_error('Advisor not found', 404); }

    $stmt = $db->prepare(
        'SELECT
             a.assignment_number,
             ag.grade,
             COUNT(*) AS count
         FROM advisor_grades ag
         JOIN submissions s ON s.id = ag.submission_id
         JOIN assignments a ON a.id = s.assignment_id
         WHERE ag.advisor_id = ?
         GROUP BY a.assignment_number, ag.grade
         ORDER BY a.assignment_number, ag.grade'
    );
    $stmt->execute([$id]);
    $grade_dist = $stmt->fetchAll();

    $stmt = $db->prepare(
        'SELECT
             a.assignment_number,
             AVG(CASE ag.grade
                WHEN \'A\' THEN 4 WHEN \'B\' THEN 3 WHEN \'C\' THEN 2
                WHEN \'D\' THEN 1 ELSE 0 END) AS avg_grade_points
         FROM advisor_grades ag
         JOIN submissions s ON s.id = ag.submission_id
         JOIN assignments a ON a.id = s.assignment_id
         WHERE ag.advisor_id = ?
         GROUP BY a.assignment_number
         ORDER BY a.assignment_number'
    );
    $stmt->execute([$id]);
    $weekly = $stmt->fetchAll();

    if ($export === 'csv') {
        send_csv("advisor_{$advisor['id']}_grades.csv", $grade_dist);
    }

    json_ok(['advisor' => $advisor, 'grade_distribution' => $grade_dist, 'weekly' => $weekly]);
}

// ─────────────────────────────────────────────
// TEAM REPORT
// ─────────────────────────────────────────────
elseif ($type === 'team') {
    if (!$id) { json_error('id required for team report'); }

    $stmt = $db->prepare(
        'SELECT
             u.name,
             u.netid,
             a.assignment_number,
             s.is_final,
             COALESCE(SUM(ta.tokens), 0) AS tokens_cast,
             COALESCE(
                (SELECT SUM(t2.tokens)
                 FROM token_allocations t2
                 JOIN submissions s2 ON s2.id = t2.submission_id
                 WHERE t2.recipient_id = u.id AND s2.assignment_id = a.id), 0
             ) AS tokens_received
         FROM users u
         JOIN assignments a ON TRUE
         LEFT JOIN submissions s       ON s.student_id = u.id AND s.assignment_id = a.id
         LEFT JOIN token_allocations ta ON ta.submission_id = s.id
         WHERE u.team_id = ? AND u.role = \'student\'
         GROUP BY u.id, a.id, s.id
         ORDER BY u.name, a.assignment_number'
    );
    $stmt->execute([$id]);
    $rows = $stmt->fetchAll();

    if ($export === 'csv') {
        send_csv("team_{$id}_report.csv", $rows);
    }

    json_ok(['team_id' => $id, 'data' => $rows]);
}

// ─────────────────────────────────────────────
// LIST ALL STUDENTS / ADVISORS / TEAMS
// ─────────────────────────────────────────────
elseif ($type === 'list_students') {
    $stmt = $db->prepare(
        'SELECT u.id, u.name, u.netid, t.team_number, t.team_name
         FROM users u LEFT JOIN teams t ON t.id = u.team_id
         WHERE u.role = \'student\' ORDER BY u.name'
    );
    $stmt->execute();
    json_ok($stmt->fetchAll());
}

elseif ($type === 'list_advisors') {
    $stmt = $db->prepare(
        'SELECT id, name, email FROM users WHERE role = \'advisor\' ORDER BY name'
    );
    $stmt->execute();
    json_ok($stmt->fetchAll());
}

elseif ($type === 'list_teams') {
    $stmt = $db->prepare('SELECT id, team_number, team_name FROM teams ORDER BY team_number');
    $stmt->execute();
    json_ok($stmt->fetchAll());
}

else {
    json_error("Unknown report type: $type");
}
