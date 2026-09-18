# 🚀 InternBoot - Automated Level Assessment & Certification Platform

Welcome to the **InternBoot** unified core repository! InternBoot is an automated level assessment, batch slot allocation, exam delivery, and certification platform built with **Core PHP**, **MySQL**, and **Vanilla JS / Bootstrap**, deployed on **Railway.app**.

---

## 1. Overview

InternBoot manages the candidate assessment lifecycle from user registration and eligibility payment to batch/slot booking, online MCQ exam execution, automated evaluation, skill level assignment (Levels 1–5), PDF certificate generation, and placement tracking.

### Core Architecture Principles:
1. **Unified Codebase:** Single canonical source of truth organized into modular business layers (`/src/modules/`) and shared core utilities (`/src/core/`).
2. **3-Layer Architecture:** Public API endpoints parse requests (`controller.php`) $\to$ execute business rules (`service.php`) $\to$ perform database queries (`queries.php`).
3. **Database Concurrency & Integrity:** MySQL prepared statements everywhere, atomic slot seat decrements (`seats_remaining > 0`), row locking (`FOR UPDATE`), and foreign key constraints.

---

## 2. Tech Stack

- **Backend:** Core PHP (PHP 8.x)
- **Database:** MySQL 8.0 (Railway.app Cloud Instance)
- **Frontend:** HTML5, Vanilla JavaScript (ES6+), Vanilla CSS / Bootstrap 5, Lucide Icons
- **PDF Generation:** Core PHP FPDF / Native PDF Stream Handler
- **Deployment:** Railway.app Cloud Hosting

---

## 3. Local Environment Setup

Follow these steps to set up and run the platform locally:

### Step 1: Install Dependencies
Open your terminal in the project root directory and run:
```bash
composer install
```

### Step 2: Configure Environment (`.env`)
Copy `.env.example` to create your private `.env` file:
```bash
cp .env.example .env
```

Open `.env` and fill in your local or Railway database credentials:
```env
APP_ENV=development
DB_HOST=okaido.proxy.rlwy.net
DB_PORT=18068
DB_USER=root
DB_PASSWORD=your_assigned_password
DB_NAME=railway
```
> ⚠️ **STRICT WARNING:** Never commit `.env` to Git! It is excluded by `.gitignore`.

### Step 3: Serve the Application
Point the PHP built-in web server to the `/public` directory:
```bash
php -S localhost:8000 -t public
```

### Accessing Interfaces & Diagnostic Endpoints:
- **Candidate Interface:** `http://localhost:8000/dashboard.html` (Registration: `/register.php`, Login: `/login.php`)
- **Admin Dashboard UI:** `http://localhost:8000/admin/index.html`
- **Database Connection Sanity Check:** `http://localhost:8000/api/connection-test.php`
- **M7 Admin API Health Check:** `http://localhost:8000/api/admin/evaluate.php?action=health`

---

## 4. Railway & Production Environment Variables

The backend supports standard application variables as well as Railway-injected MySQL environment variables:

```env
# Shared Application Credentials
APP_ENV=development
DB_HOST=okaido.proxy.rlwy.net
DB_PORT=18068
DB_USER=root
DB_PASSWORD=YOUR_RAILWAY_PASSWORD
DB_NAME=railway

# Railway Platform Managed Variables
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

### Connection Priority Order:
1. `DB_*` variables when explicitly defined in `.env`.
2. `MYSQL_PUBLIC_URL` (for local development connecting to Railway's public proxy).
3. `MYSQLHOST` / `MYSQLPORT` / `MYSQLUSER` / `MYSQLPASSWORD` / `MYSQLDATABASE`.
4. `MYSQL_URL` (internal fallback for services running inside Railway's private network).

---

## 5. Module Ownership (M1–M7)

| Module ID | Module Name | Primary Responsibility & Core Scope | Directory Path |
| :--- | :--- | :--- | :--- |
| **M1** | **Database & AI QBank** | Schema governance, DB connection bootstrap, AI question bank generation & approval endpoints | `/src/core/`, `/src/modules/m1_ai_qbank/` |
| **M2** | **Landing Page** | Public landing page, course assessment summaries, marketing overview | `/src/modules/m2_landing/` |
| **M3** | **Authentication & Profile** | User registration, bcrypt hashing, candidate profile creation, login session management | `/src/modules/m3_auth/`, `/public/api/auth/` |
| **M4** | **Payment & Dashboard** | Payment verification, candidate dashboard, enrollment state tracking | `/src/modules/m4_payment_dashboard/`, `/public/api/payment/` |
| **M5** | **Batches & Slots** | Batch threshold grouping (default 100), weekend exam date math, atomic slot booking | `/src/modules/m5_batch_slots/`, `/public/api/slots/` |
| **M6** | **Exam Engine** | Timer-based MCQ exam delivery, anti-cheating browser deterrents, answer autosave | `/src/modules/m6_exam_engine/`, `/public/api/exam/` |
| **M7** | **Evaluation & Admin** | Score calculation, level mapping (Level 1-5), PDF certificate generation, admin panel | `/src/modules/m7_evaluation_admin/`, `/public/api/admin/` |

---

## 6. Database Schema Summary

The platform uses 19 relational tables defined in `schema.sql`:

| Table | Purpose | Key Foreign Keys & Constraints |
| :--- | :--- | :--- |
| `users` | Account credentials & authentication | `role`: (`candidate`, `admin`, `staff`), `is_active` |
| `candidates` | Candidate profile details | FK `user_id` $\to$ `users.id` |
| `payments` | Fee transactions & reference numbers | FK `candidate_id`, `assessment_id`, status (`pending`, `success`, `failed`) |
| `assessments` | Exam configurations & durations | `status`: (`draft`, `active`, `archived`) |
| `batches` | Candidate batch groupings | `batch_number`, FK `assessment_id` |
| `enrollments` | Eligibility & batch allocation | FK `candidate_id`, `assessment_id`, `payment_id`, `batch_id` |
| `exam_schedules` | Weekend exam dates | FK `batch_id`, Saturday/Sunday date validation |
| `exam_slots` | Time slots & seat inventory | FK `exam_schedule_id`, `seats_remaining >= 0` check trigger |
| `question_banks` | Question bank collections | FK `assessment_id` |
| `questions` | Multiple-choice questions | FK `question_bank_id`, `difficulty_level` |
| `options` | Choice options per question | FK `question_id`, `is_correct` (1/0) |
| `attempts` | Candidate test attempt sessions | FK `candidate_id`, `exam_slot_id`, status (`in_progress`, `submitted`, `expired`) |
| `answers` | Candidate answer selections | FK `attempt_id`, `question_id`, `selected_option_id` |
| `results` | Evaluated scores & percentages | FK `attempt_id`, `level_assigned` (1–5) |
| `levels` | Percentage score-to-level mapping | `level_number` (1–5), score ranges (0%–100%) |
| `certificates` | Issued candidate certificates | FK `candidate_id`, `result_id`, unique `certificate_number` |
| `placement_records` | Recruitment & hiring tracking | FK `candidate_id`, `placement_status` |
| `admin_logs` | Administrative security audit trail | FK `user_id`, action, IP address |
| `settings` | System-wide key-value configurations | `batch_threshold` (100), `exam_fee` (2999) |

---

## 7. Payment Module Notes (M4)

- **Verification Approach Kept:** During restructuring, the payment verification mechanism from the Razorpay standalone demo was retained as canonical. It generates a 32-byte cryptographically secure session-bound token (`bin2hex(random_bytes(32))`) stored in `$_SESSION['m4_demo_payment']` and verifies verify requests using `hash_equals()`. Browser-supplied success flags are strictly ignored.
- **Endpoints:**
  - `GET  /api/payment/payment.php?action=details` — Fetches candidate payment fee and current enrollment state.
  - `POST /api/payment/payment.php` (`{"action":"create", "assessment_id":1}`) — Creates a pending payment record and issues a verification token.
  - `POST /api/payment/payment.php` (`{"action":"verify", "payment_id":1, "token":"..."}`) — Performs server-side token verification, marks `payments.status = 'success'`, and updates `enrollments.eligibility_status = 'eligible'`.
  - `GET  /api/dashboard.php?candidate_id=1` — Returns complete dashboard status metrics.
  - `GET  /api/enrollment.php?candidate_id=1` — Returns candidate enrollment records.
- **Sample Seed Data:** Standalone M4 test data (demo candidate ID 1001, test assessment 1001) has been preserved under `database/seeds/m4_seed.sql`.

---

## 8. M7 / Evaluation & Admin Notes

- **Admin Panel URL:** `http://localhost:8000/admin/index.html`
- **Admin Public API:** `/api/admin/evaluate.php`
- **Core Operations Supported:**
  - GET `?action=health`: Validates DB connection and confirms presence of all 19 schema tables.
  - GET `?action=dashboard`: Returns total candidates, payments, active batches, and level distribution statistics.
  - POST `?action=evaluate`: Accepts `{ "attempt_id": 1, "generate_certificate": true }`, evaluates submitted answers against `options.is_correct`, calculates percentage, assigns skill level (Level 1–5), inserts `results` row, generates certificate, and creates initial placement record.
  - GET `?action=certificate_pdf.php?result_id=1`: Renders/downloads candidate PDF certificate.
  - POST `?action=batch`, `?action=slot`, `?action=allocate`: Manages batch creation, slot times, and candidate seat allocations.
  - Security: All POST operations require CSRF protection (`X-CSRF-Token` header).

---

## 9. Module Notes (M3, M5, M6)

### M3 — Authentication & Profile
- Registration creates entries in `users` and `candidates` within a single SQL transaction.
- Hashing uses `password_hash()` (bcrypt).
- Session ID regeneration (`session_regenerate_id(true)`) occurs on every successful login.

### M5 — Batch & Slot Management
- **Threshold Batching:** Automatically groups eligible unbatched candidates once candidate count reaches `settings.batch_threshold` (default `100`).
- **Weekend Scheduling:** Date math automatically projects upcoming Saturday and Sunday exam dates.
- **Anti-Race-Condition Booking:** Slot seat reservation uses atomic UPDATE checks (`UPDATE exam_slots SET seats_remaining = seats_remaining - 1 WHERE id = ? AND seats_remaining > 0`) inside a transaction to prevent overselling.

### M6 — Online Exam Engine
- **Server-Authoritative Timer:** Server records start time and deadline (`end_time`). Browser countdown is for display only; API rejects submissions arriving past deadline.
- **Deterministic Question Randomization:** Questions ordering is randomized per attempt using `ORDER BY MD5(CONCAT(attempt_id, ':', question_id))` without exposing answer keys (`options.is_correct`) to the frontend.
- **Browser Anti-Cheating:** Tracks tab/visibility changes (`visibilitychange`) and focus loss (`blur`). Exceeding 3 violations auto-submits the assessment.

---

## 10. Known Gaps & Future Roadmap Items

1. **AI Question Bank Auto-Generation:** Prompt-driven dynamic AI question generation endpoint is not yet fully wired to external LLM provider APIs.
2. **PDF Certificate Design Template:** PDF generation is active in M7 (`certificate_pdf.php`), but the visual layout uses a standard placeholder layout requiring final graphic styling.
3. **Evaluation Status-Check Bug:** Tracked for resolution in a separate pass following codebase restructuring.
4. **Database Connection Bootstrap Split:** Exam engine endpoints use `m6_exam_engine/config/database.php` while Core/Admin endpoints use `src/core/bootstrap.php`. Both resolve to identical credentials in `.env`, but should be refactored into a single connection helper in a future pass.
