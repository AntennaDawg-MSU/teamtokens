# Team Tokens Web Application

A secure web-based peer evaluation system for engineering capstone courses.
Students allocate tokens to teammates and grade advisors each week.

---

## Project Structure

```
team-tokens/
├── frontend/                  ← Deploy to GitHub Pages
│   ├── index.html             ← Login page
│   ├── css/
│   │   └── style.css
│   ├── js/
│   │   └── api.js             ← All API calls (update API_BASE here)
│   └── pages/
│       ├── dashboard.html     ← Student evaluation UI
│       └── admin.html         ← Administrator dashboard
│
├── backend/                   ← Deploy to PHP hosting (Render, etc.)
│   ├── .htaccess              ← Security rules
│   ├── config/
│   │   ├── db.php             ← DB connection (reads env vars)
│   │   ├── db.local.php       ← Local dev credentials (git-ignored)
│   │   └── schema.sql         ← PostgreSQL schema
│   ├── includes/
│   │   ├── auth.php           ← Login, logout, session, role checks
│   │   └── helpers.php        ← JSON responses, sanitisation, CSV utils
│   └── api/
│       ├── login.php          ← POST /api/login.php
│       ├── logout.php         ← POST /api/logout.php
│       ├── dashboard.php      ← GET  /api/dashboard.php (student data)
│       ├── submit.php         ← POST /api/submit.php
│       └── admin/
│           ├── import.php     ← POST /api/admin/import.php?type=...
│           ├── manage.php     ← CRUD /api/admin/manage.php?entity=...
│           └── reports.php    ← GET  /api/admin/reports.php?type=...
│
└── docs/
    ├── DEPLOYMENT.md          ← Step-by-step hosting guide
    └── SECURITY.md            ← Auth, passwords, DB security, backups
```

---

## Quick Start (Local Development)

### Prerequisites
- PHP 8.1+ with PDO and pgsql extensions
- PostgreSQL 14+
- A modern browser

### 1. Set up the database
```bash
createdb team_tokens
psql team_tokens < backend/config/schema.sql
```

### 2. Configure credentials
```bash
cp backend/config/db.php backend/config/db.local.php
# Edit db.local.php with your local DB credentials
```

Add `db.local.php` to your `.gitignore`.

### 3. Create an admin user
```bash
php -r "echo password_hash('admin123', PASSWORD_ARGON2ID);"
# Copy the hash output, then:
psql team_tokens -c "INSERT INTO users (netid,name,email,role,password_hash,must_reset) VALUES ('admin','Admin','admin@example.com','administrator','PASTE_HASH_HERE',FALSE);"
```

### 4. Run the backend
```bash
cd backend
php -S localhost:8000
```

### 5. Run the frontend
```bash
cd frontend
python3 -m http.server 3000
# Or open index.html directly in your browser
```

Update `API_BASE` in `frontend/js/api.js` to `http://localhost:8000/api`.

---

## API Endpoint Reference

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/api/login.php` | None | Authenticate |
| POST | `/api/logout.php` | Any | Sign out |
| GET | `/api/dashboard.php` | Student | Team, teammates, advisors, assignment |
| POST | `/api/submit.php` | Student | Save draft or final submission |
| POST | `/api/admin/import.php?type=students` | Admin | CSV import |
| POST | `/api/admin/import.php?type=teams` | Admin | CSV import |
| POST | `/api/admin/import.php?type=assignments` | Admin | CSV import |
| GET/POST/PUT/DELETE | `/api/admin/manage.php?entity=student` | Admin | CRUD |
| GET/POST/PUT/DELETE | `/api/admin/manage.php?entity=team` | Admin | CRUD |
| GET/POST/PUT/DELETE | `/api/admin/manage.php?entity=assignment` | Admin | CRUD |
| GET | `/api/admin/reports.php?type=student&id=X` | Instructor+ | Student report |
| GET | `/api/admin/reports.php?type=advisor&id=X` | Instructor+ | Advisor report |
| GET | `/api/admin/reports.php?type=team&id=X` | Instructor+ | Team report |

---

## CSV Import Formats

**students.csv**
```csv
name,netid,team_number
Jane Smith,jsmith,1
John Doe,jdoe123,1
```

**teams.csv**
```csv
team_number,team_name
1,Alpha Team
2,Beta Team
```

**assignments.csv**
```csv
assignment_number,title,open_date,due_date,token_value
1,Week 1 Evaluation,2024-09-09 00:00:00,2024-09-15 23:59:00,10
```

---

## Docs
- [Deployment Guide](docs/DEPLOYMENT.md)
- [Security Documentation](docs/SECURITY.md)
