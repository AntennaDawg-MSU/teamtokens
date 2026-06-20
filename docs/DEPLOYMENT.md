# Team Tokens — Deployment Guide

This guide walks through deploying Team Tokens from scratch.
You do not need prior server experience to follow these steps.

---

## Overview of the Architecture

```
Browser (student/admin)
        │  HTTPS
        ▼
GitHub Pages          ← frontend HTML/CSS/JS (free hosting)
        │  HTTPS API calls
        ▼
PHP Backend Server    ← Render / Railway / DigitalOcean / AWS
        │
        ▼
PostgreSQL Database   ← same server, or managed DB service
```

---

## Part 1 — Domain Setup (optional but recommended)

1. Purchase a domain from Namecheap, Cloudflare, or similar (~$12/yr).
2. Point it at your backend server using an **A record** in DNS.
3. You can use a subdomain for the API, e.g. `api.yourproject.com`.
4. GitHub Pages serves the frontend at `https://your-org.github.io/team-tokens/`.

---

## Part 2 — Backend Hosting (Render — recommended free tier)

### 2.1 Create a Render account
Go to https://render.com and sign up with GitHub.

### 2.2 Create a Web Service
1. Click **New → Web Service**.
2. Connect your GitHub repository.
3. Set:
   - **Environment**: PHP
   - **Build command**: `composer install --no-dev` *(only if you add Composer later)*
   - **Start command**: leave blank for PHP (Render auto-detects)
   - **Root directory**: `backend/`

### 2.3 Set environment variables (never commit these)
In Render → Environment → Add:

| Key            | Value                        |
|----------------|------------------------------|
| DB_HOST        | (your DB host)               |
| DB_PORT        | 5432                         |
| DB_NAME        | team_tokens                  |
| DB_USER        | tt_app                       |
| DB_PASS        | (strong password)            |
| FRONTEND_ORIGIN| https://your-org.github.io   |

### 2.4 Note your service URL
It will be something like `https://team-tokens-api.onrender.com`.
Update `API_BASE` in `frontend/js/api.js` to this URL.

---

## Part 3 — Database Setup

### Option A: Render Postgres (easiest)
1. In Render: **New → PostgreSQL**.
2. Choose the free tier (or paid for production).
3. Copy the **Internal Database URL** into your Web Service's DB_* env vars.

### Option B: Supabase (generous free tier)
1. Sign up at https://supabase.com.
2. Create a new project.
3. Go to **Settings → Database** → copy the connection string.
4. Fill in your Render env vars from those credentials.

### Option C: Self-hosted on DigitalOcean
1. Create a $6/month Droplet (Ubuntu 22.04).
2. SSH in: `ssh root@YOUR_IP`
3. Install Postgres:
   ```bash
   apt update && apt install -y postgresql postgresql-contrib
   sudo -u postgres psql
   ```
4. Create the DB and user:
   ```sql
   CREATE USER tt_app WITH PASSWORD 'StrongPass123!';
   CREATE DATABASE team_tokens OWNER tt_app;
   GRANT ALL PRIVILEGES ON DATABASE team_tokens TO tt_app;
   \q
   ```

### Run the Schema
Copy `backend/config/schema.sql` to your server and run:
```bash
psql -U tt_app -d team_tokens -f schema.sql
```

### Create the first administrator account
```sql
INSERT INTO users (netid, name, email, role, password_hash, must_reset)
VALUES (
  'admin',
  'Administrator',
  'admin@youruniversity.edu',
  'administrator',
  -- Replace below with output of: php -r "echo password_hash('YourPassword', PASSWORD_ARGON2ID);"
  '$argon2id$...',
  FALSE
);
```

---

## Part 4 — Frontend (GitHub Pages)

1. Push the `frontend/` folder contents to a GitHub repo.
2. Go to **Settings → Pages** → Source: **Deploy from branch** → `main` → `/` (root).
3. Your site will be at `https://YOUR-USERNAME.github.io/REPO-NAME/`.
4. Update `API_BASE` in `frontend/js/api.js` to your backend URL.

---

## Part 5 — SSL

- **Render / Railway**: SSL is automatic (Let's Encrypt). Nothing to do.
- **DigitalOcean with Apache**:
  ```bash
  apt install -y certbot python3-certbot-apache
  certbot --apache -d api.yourproject.com
  ```
  Certbot auto-renews every 90 days.

---

## Part 6 — Database Backups

### Automated daily backup script
Save as `/home/ubuntu/backup_db.sh`:
```bash
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M)
BACKUP_DIR="/backups/team_tokens"
mkdir -p "$BACKUP_DIR"
pg_dump -U tt_app team_tokens | gzip > "$BACKUP_DIR/backup_$DATE.sql.gz"
# Keep last 30 days only
find "$BACKUP_DIR" -name "*.gz" -mtime +30 -delete
```
Schedule with cron:
```bash
chmod +x /home/ubuntu/backup_db.sh
crontab -e
# Add: 0 2 * * * /home/ubuntu/backup_db.sh
```

### Restore from backup
```bash
gunzip -c backup_20240101_0200.sql.gz | psql -U tt_app team_tokens
```

---

## Part 7 — System Updates

### Update the frontend
```bash
git pull origin main
# Changes go live automatically on GitHub Pages
```

### Update the backend on Render
Push to GitHub → Render auto-deploys from your connected branch.

### Update the backend on a self-hosted server
```bash
ssh root@YOUR_IP
cd /var/www/team-tokens/backend
git pull origin main
# Restart PHP-FPM if running:
systemctl restart php8.2-fpm
```

---

## Security Checklist Before Going Live

- [ ] `backend/config/db.local.php` is in `.gitignore` and never committed
- [ ] All environment variables set in hosting dashboard (not in code)
- [ ] HTTPS is active and working
- [ ] `FRONTEND_ORIGIN` env var is set to your exact GitHub Pages URL
- [ ] Default admin password has been changed
- [ ] `.htaccess` is active (test by trying to browse to a PHP file directly — it should be denied)
- [ ] Database backups are scheduled and tested
