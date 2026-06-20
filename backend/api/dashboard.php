<?php
// backend/api/dashboard.php
// Returns everything the student dashboard needs.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

cors_headers();
$session = require_auth();
$db      = get_db();

$student_id = (int) $session['user_id'];
$team_id    = (int) $session['team_id'];

if (!$team_id) {
    json_error('You are not assigned to a team yet', 404);
}

// ── Team info ─────────────────────────────────────────────────────────────────
$stmt = $db->prepare('SELECT id, team_number, team_name FROM teams WHERE id = ?');
$stmt->execute([$team_id]);
$team = $stmt->fetch();

// ── Teammates (exclude self) ──────────────────────────────────────────────────
$stmt = $db->prepare(
    'SELECT id, name, netid FROM users
     WHERE team_id = ? AND role = \'student\' AND id != ?
     ORDER BY name'
);
$stmt->execute([$team_id, $student_id]);
$teammates = $stmt->fetchAll();

// ── Advisors ──────────────────────────────────────────────────────────────────
$stmt = $db->prepare(
    'SELECT u.id, u.name, u.email
     FROM users u
     JOIN team_advisors ta ON ta.advisor_id = u.id
     WHERE ta.team_id = ?
     ORDER BY u.name'
);
$stmt->execute([$team_id]);
$advisors = $stmt->fetchAll();

// ── Active assignment ─────────────────────────────────────────────────────────
$stmt = $db->prepare(
    'SELECT id, assignment_number, title, open_date, due_date, token_value
     FROM assignments
     WHERE is_active = TRUE AND open_date <= NOW() AND due_date >= NOW()
     ORDER BY due_date ASC
     LIMIT 1'
);
$stmt->execute();
$assignment = $stmt->fetch() ?: null;

// ── Existing draft submission (if any) ───────────────────────────────────────
$draft = null;
if ($assignment) {
    $stmt = $db->prepare(
        'SELECT s.id, s.advisor_meeting_ans, s.comments, s.has_warnings, s.is_final,
                json_agg(json_build_object(\'recipient_id\', ta.recipient_id, \'tokens\', ta.tokens)) AS token_allocations,
                json_agg(json_build_object(\'advisor_id\', ag.advisor_id, \'grade\', ag.grade)) AS advisor_grades
         FROM submissions s
         LEFT JOIN token_allocations ta ON ta.submission_id = s.id
         LEFT JOIN advisor_grades ag    ON ag.submission_id = s.id
         WHERE s.student_id = ? AND s.assignment_id = ?
         GROUP BY s.id'
    );
    $stmt->execute([$student_id, $assignment['id']]);
    $raw = $stmt->fetch();
    if ($raw) {
        $draft = $raw;
        $draft['token_allocations'] = json_decode($raw['token_allocations'], true);
        $draft['advisor_grades']    = json_decode($raw['advisor_grades'], true);
    }
}

json_ok([
    'team'       => $team,
    'teammates'  => $teammates,
    'advisors'   => $advisors,
    'assignment' => $assignment,
    'draft'      => $draft,
]);
