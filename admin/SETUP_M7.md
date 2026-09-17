# InternBoot M7 – Dynamic PHP Admin

The existing M7 HTML/CSS layout is preserved. The admin pages now use the PHP API and Railway MySQL for live data.

## 1. Railway environment variables

The backend understands **all Railway variables supplied for this project**:

```env
MYSQL_DATABASE=railway
MYSQL_PUBLIC_URL=mysql://root:YOUR_PASSWORD@okaido.proxy.rlwy.net:18068/railway
MYSQL_ROOT_PASSWORD=YOUR_PASSWORD
MYSQL_URL=mysql://root:YOUR_PASSWORD@mysql.railway.internal:3306/railway
MYSQLDATABASE=railway
MYSQLHOST=mysql.railway.internal
MYSQLPASSWORD=YOUR_PASSWORD
MYSQLPORT=3306
MYSQLUSER=root
```

It also supports these application aliases:

```env
APP_ENV=development
DB_HOST=okaido.proxy.rlwy.net
DB_PORT=18068
DB_USER=root
DB_PASSWORD=YOUR_PASSWORD
DB_NAME=railway
```

### Local PC connection

For a local PC, use the Railway **public** connection. The private hostname `mysql.railway.internal` only works inside Railway's private network.

The Railway screenshot supplied with the project shows the public host as:

```text
okaido.proxy.rlwy.net
```

Do not use the private `mysql.railway.internal` address from your local PC.

The connection resolver uses this priority:

1. `DB_*` variables when explicitly set.
2. `MYSQL_PUBLIC_URL`.
3. `MYSQLHOST` / `MYSQLPORT` / `MYSQLUSER` / `MYSQL_ROOT_PASSWORD` / `MYSQLPASSWORD` / `MYSQLDATABASE`.
4. `MYSQL_URL` as a final fallback for environments running inside Railway.

If `MYSQLPASSWORD` accidentally contains another assignment such as `MYSQL_DATABASE=railway`, the resolver ignores that malformed value and uses `MYSQL_ROOT_PASSWORD` or the password embedded in `MYSQL_PUBLIC_URL` instead.

## 2. Create the private `.env`

Copy `.env.example` to `.env` and put the real Railway password in it.

PowerShell:

```powershell
Copy-Item .env.example .env
```

Then edit `.env`.

**Never commit `.env`. It is already ignored by `.gitignore`.**

## 3. Database

The supplied `schema.sql` contains the M7 tables:

`users`, `candidates`, `payments`, `assessments`, `batches`, `enrollments`, `exam_schedules`, `exam_slots`, `question_banks`, `questions`, `options`, `attempts`, `answers`, `results`, `levels`, `certificates`, `placement_records`, `admin_logs`, `settings`.

If the Railway database already has these tables and project data, **do not import `schema.sql` again** because it contains `DROP TABLE` statements.

## 4. Run locally

From the project folder:

```powershell
cd C:\xampp\htdocs\Internboot_Phase2_Project\Internboot_Project
C:\xampp\php\php.exe -S localhost:8000
```

Open:

```text
http://localhost:8000/admin/index.html
```

XAMPP MySQL does **not** need to run because the PHP app connects directly to Railway MySQL.

## 5. Verify the connection

Open:

```text
http://localhost:8000/public/api/admin/evaluate.php?action=health
```

A healthy database returns JSON containing:

```json
{"database":"connected","missing_tables":[],"ready":true}
```

If `missing_tables` is not empty, the Railway database schema is incomplete.

## 6. M7 functionality

- Dashboard counters, level distribution, recent candidates and upcoming batches
- Candidate list, search/filter and details
- Results and pending attempts
- Server-side MCQ evaluation and level assignment from the `levels` table
- Certificate generation and dependency-free PDF download
- Certificate verification by certificate number
- Question approval/rejection
- Weekend batch creation with the configured candidate threshold
- Automatic creation of two initial slots for a new batch
- Additional slot creation with overlap validation
- Candidate allocation with transaction + row locking + seat protection
- Automatic placement record creation for evaluated results
- Placement status/company/notes updates
- Admin profile/settings persistence
- Local browser admin avatar preview
- Logout endpoint
- CSRF protection for all M7 POST requests
- Prepared statements for database reads/writes

## 7. Authentication note

M7 is designed to use the shared M3 authentication session when that branch is merged. In `APP_ENV=development`, the standalone M7 UI is allowed without a login session so the module can be tested independently. In production, admin/staff session access is required.

## 8. Composer

Composer is not required for this standalone Core PHP M7 backend.

Your Composer installation can be checked with:

```powershell
C:\xampp\php\php.exe C:\xampp\php\composer.phar --version
```

There is intentionally no `composer install` command for this folder because it has no `composer.json` dependency file.
