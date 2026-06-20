# Team Tokens — Security Documentation

---

## 1. Authentication Design

### Login Flow
1. Student submits NetID + password over HTTPS (POST JSON body).
2. `backend/api/login.php` looks up the user by NetID using a parameterised query (no SQL injection possible).
3. `password_verify()` compares the submitted password against the stored Argon2id hash.
4. On success, PHP creates a server-side session. A signed, HttpOnly, Secure, SameSite=Strict cookie is set.
5. The raw password is never stored, logged, or returned to the client.

### Session Security
- Sessions are stored server-side (PHP session files or Redis if configured).
- The session cookie is:
  - `HttpOnly` — JavaScript cannot read it (blocks XSS token theft).
  - `Secure` — Only sent over HTTPS.
  - `SameSite=Strict` — Blocks CSRF from cross-site requests.
- `session_regenerate_id(true)` is called on every login to prevent session fixation.
- Sessions expire after 8 hours of inactivity.

### Password Reset
Password resets are **administrator-initiated only**. There is no public "forgot password" flow. This is appropriate for a closed academic system where all users are known. When an admin resets a password, `must_reset = TRUE` is set, and the student must change their password on next login (implementation: check `must_reset` on dashboard load and redirect to a change-password page).

---

## 2. Password Storage

All passwords are hashed with **Argon2id** via PHP's `password_hash()`:

```php
password_hash($plain, PASSWORD_ARGON2ID)
```

**Why Argon2id?**
- Winner of the Password Hashing Competition (2015).
- Resistant to GPU/ASIC brute-force and side-channel attacks.
- Memory-hard: requires significant RAM, slowing bulk cracking.
- PHP's default Argon2id parameters (time_cost=2, memory_cost=65536, threads=1) are considered secure as of 2024.

Passwords are **never**:
- Stored in plaintext or reversible encryption.
- Logged anywhere.
- Sent back to the client in any response.
- Exposed in error messages.

---

## 3. Database Security Architecture

### Access Control
- The database user `tt_app` has `SELECT, INSERT, UPDATE, DELETE` only — no `DROP`, `CREATE`, or superuser privileges.
- No student or browser ever connects directly to the database.
- All database access flows through the PHP API layer.

### SQL Injection Prevention
All queries use **PDO prepared statements** with parameterised placeholders:

```php
$stmt = $db->prepare('SELECT * FROM users WHERE netid = ?');
$stmt->execute([$netid]);
```

No query ever concatenates user input into a SQL string.

### Credential Protection
- Database credentials are stored in environment variables on the server.
- The `db.local.php` file (for local dev) is listed in `.gitignore`.
- Credentials are never shipped to the browser.
- The `.htaccess` file denies direct HTTP access to all config files.

### Input Sanitisation
Every API endpoint sanitises its inputs before use:
- Strings: `mb_substr(trim(...), 0, MAX_LENGTH)`
- Integers: `(int) filter_var(..., FILTER_SANITIZE_NUMBER_INT)`
- NetIDs: regex-stripped to `[a-zA-Z0-9_]` only
- Grades: whitelist-checked against `['A','B','C','D','F']`

---

## 4. API Security

### Authentication Enforcement
Every protected endpoint calls `require_auth()` or `require_role(...)` before processing any data. These functions check the server-side session — client-side claims (e.g., a role stored in localStorage) are never trusted.

### Privilege Escalation Prevention
- Students cannot access any `/admin/` endpoint — the `require_admin()` call will return HTTP 403 before any data is read or written.
- Students can only read/write their **own** records. All queries are scoped to the `$session['user_id']` from the server session — never from a client-supplied user ID.

### CORS
The `Access-Control-Allow-Origin` header is set to the exact GitHub Pages URL (configured via `FRONTEND_ORIGIN` env var). Wildcard `*` is never used. This prevents other websites from making credentialed requests to your API.

### XSS Prevention
- The API returns JSON, not HTML. There is no server-side template rendering where XSS could be injected.
- The frontend uses `textContent` and `escHtml()` when inserting server data into the DOM. `innerHTML` is only used with pre-escaped strings.

---

## 5. What Students Can Never See

- PHP source code (served as binary by the web server, direct file access denied by `.htaccess`)
- Database credentials
- SQL queries
- Other students' submissions
- Other teams' data
- Administrative endpoints

---

## 6. Backup and Recovery Procedures

### Backup Schedule
Daily automated `pg_dump` compressed backups (see Deployment Guide §6).
Backups are retained for 30 days.

### Recovery Steps
1. Stop the application (or put a maintenance page up).
2. Restore from the most recent clean backup:
   ```bash
   dropdb -U postgres team_tokens
   createdb -U postgres team_tokens
   gunzip -c backup_YYYYMMDD.sql.gz | psql -U tt_app team_tokens
   ```
3. Restart the PHP service.
4. Verify a test login works before reopening to users.

### Recovery Time Objective
With a managed database service (Render/Supabase), point-in-time recovery is available and can restore to any minute within the backup retention window.

---

## 7. Known Limitations and Future Recommendations

| Item | Current State | Recommended Improvement |
|------|--------------|------------------------|
| Rate limiting | None | Add fail2ban or PHP rate-limit middleware on login endpoint |
| 2FA | None | Add TOTP (e.g., Google Authenticator) for admin accounts |
| Audit log | None | Add an `audit_log` table recording all admin actions |
| Session storage | File-based | Move to Redis for multi-server deployments |
| Password reset | Admin-only | Add secure email-based self-service reset for production |
