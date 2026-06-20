-- Team Tokens Database Schema
-- PostgreSQL

-- ─────────────────────────────────────────────
--  ROLES & USERS
-- ─────────────────────────────────────────────
CREATE TYPE user_role AS ENUM ('student', 'advisor', 'instructor', 'administrator');

CREATE TABLE users (
    id              SERIAL PRIMARY KEY,
    netid           VARCHAR(50) UNIQUE NOT NULL,
    name            VARCHAR(120) NOT NULL,
    email           VARCHAR(120),
    role            user_role NOT NULL DEFAULT 'student',
    password_hash   VARCHAR(255) NOT NULL,          -- bcrypt / Argon2
    must_reset      BOOLEAN NOT NULL DEFAULT TRUE,  -- admin-initiated reset
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ─────────────────────────────────────────────
--  TEAMS
-- ─────────────────────────────────────────────
CREATE TABLE teams (
    id          SERIAL PRIMARY KEY,
    team_number INT UNIQUE NOT NULL,
    team_name   VARCHAR(120) NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- student ↔ team  (many students : one team)
ALTER TABLE users ADD COLUMN team_id INT REFERENCES teams(id) ON DELETE SET NULL;

-- ─────────────────────────────────────────────
--  ADVISORS  (users with role='advisor' linked to teams)
-- ─────────────────────────────────────────────
CREATE TABLE team_advisors (
    id         SERIAL PRIMARY KEY,
    team_id    INT NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
    advisor_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE(team_id, advisor_id)
);

-- ─────────────────────────────────────────────
--  ASSIGNMENTS
-- ─────────────────────────────────────────────
CREATE TABLE assignments (
    id              SERIAL PRIMARY KEY,
    assignment_number INT UNIQUE NOT NULL,
    title           VARCHAR(200),
    open_date       TIMESTAMPTZ NOT NULL,
    due_date        TIMESTAMPTZ NOT NULL,
    token_value     INT NOT NULL DEFAULT 10,  -- total tokens each student distributes
    is_active       BOOLEAN NOT NULL DEFAULT FALSE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ─────────────────────────────────────────────
--  SUBMISSIONS
-- ─────────────────────────────────────────────
CREATE TABLE submissions (
    id                  SERIAL PRIMARY KEY,
    student_id          INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    assignment_id       INT NOT NULL REFERENCES assignments(id) ON DELETE CASCADE,
    submitted_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    advisor_meeting_ans TEXT,
    comments            TEXT,
    has_warnings        BOOLEAN NOT NULL DEFAULT FALSE,
    is_final            BOOLEAN NOT NULL DEFAULT FALSE,   -- locked after final submit
    reopened_by         INT REFERENCES users(id),         -- admin who reopened
    UNIQUE(student_id, assignment_id)
);

-- token allocations (one row per recipient per submission)
CREATE TABLE token_allocations (
    id              SERIAL PRIMARY KEY,
    submission_id   INT NOT NULL REFERENCES submissions(id) ON DELETE CASCADE,
    recipient_id    INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    tokens          INT NOT NULL CHECK (tokens >= 0),
    UNIQUE(submission_id, recipient_id)
);

-- advisor grades (one row per advisor per submission)
CREATE TABLE advisor_grades (
    id              SERIAL PRIMARY KEY,
    submission_id   INT NOT NULL REFERENCES submissions(id) ON DELETE CASCADE,
    advisor_id      INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    grade           CHAR(1) NOT NULL CHECK (grade IN ('A','B','C','D','F')),
    UNIQUE(submission_id, advisor_id)
);

-- ─────────────────────────────────────────────
--  AUDIT / SESSIONS
-- ─────────────────────────────────────────────
CREATE TABLE sessions (
    id          VARCHAR(128) PRIMARY KEY,
    user_id     INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at  TIMESTAMPTZ NOT NULL
);

-- ─────────────────────────────────────────────
--  INDEXES
-- ─────────────────────────────────────────────
CREATE INDEX idx_users_team        ON users(team_id);
CREATE INDEX idx_submissions_student ON submissions(student_id);
CREATE INDEX idx_submissions_assign  ON submissions(assignment_id);
CREATE INDEX idx_token_alloc_sub     ON token_allocations(submission_id);
CREATE INDEX idx_advisor_grades_sub  ON advisor_grades(submission_id);
CREATE INDEX idx_sessions_user       ON sessions(user_id);
CREATE INDEX idx_sessions_expires    ON sessions(expires_at);
