# 📋 InternBoot Platform - Database & API Handover Document
**Author:** Database Schema Owner (M1)  
**Target Audience:** Development Team (PHP Engineers)  
**Database Server:** Railway.app Cloud Instance  
**Status:** Live & Active  

---

## 1. 🔌 Database Connection Details

The MySQL database schema is live and hosted on **Railway.app**. Use the exact credentials from your local `.env` file in your PHP database configuration file (e.g., `db.php` or `config.php`).

| Parameter | Value |
| :--- | :--- |
| **Host** | `tokaido.proxy.rlwy.net` |
| **Port** | `18068` |
| **Username** | `root` |
| **Password** | `YOUR_SECURE_PASSWORD_HERE` |
| **Database Name** | `railway` |

### 🛠️ Standard PHP (`mysqli`) Connection Snippet

Save this as `db.php` or include it in your project's database handler module:

```php
<?php
// db.php - Central Database Connection Handler (Environment Variables)

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$host     = $_ENV['DB_HOST']     ?? '127.0.0.1';
$port     = (int)($_ENV['DB_PORT'] ?? 3306);
$user     = $_ENV['DB_USER']     ?? 'root';
$password = $_ENV['DB_PASSWORD'] ?? '';
$dbname   = $_ENV['DB_NAME']     ?? 'railway';

// Enable error reporting for MySQLi
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $user, $password, $dbname, $port);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    die("Database Connection Failed: " . $e->getMessage());
}
?>
```

---

## 2. 🗂️ Database Table Overview (19 Tables)

Below is the complete reference of all tables present in the `railway` database and their core purpose:

| # | Table Name | Core Purpose / Functionality |
| :-: | :--- | :--- |
| 1 | `users` | Stores authentication credentials (email, hashed password), user roles (`candidate`, `admin`, `staff`), and account activation status. |
| 2 | `candidates` | Holds candidate profile details (full name, phone number, education, resume links) linked to `users`. |
| 3 | `payments` | Tracks registration fee payments, transaction reference numbers, amounts, and payment statuses (`pending`, `success`, `failed`). |
| 4 | `assessments` | Defines assessment configurations including title, description, exam duration, total questions, and status (`draft`, `active`, `archived`). |
| 5 | `batches` | Groups candidates into batches automatically once the target candidate threshold is reached for an assessment. |
| 6 | `enrollments` | Connects candidates to assessments, payment records, eligibility status (`pending`, `eligible`, `ineligible`), and allocated batches. |
| 7 | `exam_schedules` | Stores weekend exam dates (Saturday/Sunday) assigned to candidate batches. |
| 8 | `exam_slots` | Manages time slot allocations per schedule, total seat capacity, and real-time remaining seat counts (`seats_remaining >= 0`). |
| 9 | `question_banks` | Stores curated/AI-generated collections of question banks associated with specific assessments. |
| 10 | `questions` | Contains individual multiple-choice test questions, difficulty ratings (`easy`, `medium`, `hard`), and question bank associations. |
| 11 | `options` | Stores multiple-choice options for each question along with the correct answer indicator (`is_correct`). |
| 12 | `attempts` | Tracks exam session attempts per candidate slot, start/end deadlines, submission timestamps, and evaluation statuses. |
| 13 | `answers` | Stores individual candidate response selections for questions within a test attempt session. |
| 14 | `results` | Records evaluated total test scores, calculated percentage, and assigned assessment skill levels (Level 1 to 5). |
| 15 | `levels` | Configurable system table mapping score percentages (0-100%) to candidate skill levels (Level 1: Beginner to Level 5: Expert). |
| 16 | `certificates` | Stores unique generated certificate numbers, issue dates, and candidate level achievement records. |
| 17 | `placement_records` | Tracks post-assessment candidate recruitment status (`shortlisted`, `interviewing`, `placed`) and employer hiring notes. |
| 18 | `admin_logs` | Logs audit security trails for administrative actions, staff updates, and administrative IP tracking. |
| 19 | `settings` | System-wide key-value configuration parameters (e.g., `batch_threshold`, `exam_fee`, `negative_marking_enabled`). |

---

## 3. 🔌 API Contract Specifications

To maintain standard structure across backend endpoints developed by the PHP team, follow these API request/response contracts for core platform modules:

---

### Module 1: Auth & Login Endpoint
* **Endpoint Name:** `POST /api/login.php`
* **Purpose:** Authenticate user and return session/role info.

#### Request Fields (JSON Body):
```json
{
  "email": "candidate@example.com",
  "password": "UserSecurePassword123"
}
```

#### Response Fields (JSON Success):
```json
{
  "status": "success",
  "message": "Login successful",
  "data": {
    "user_id": 42,
    "email": "candidate@example.com",
    "role": "candidate",
    "candidate_id": 15,
    "full_name": "Aarav Sharma"
  }
}
```

---

### Module 2: Payment Verification Endpoint
* **Endpoint Name:** `POST /api/payments/verify.php`
* **Purpose:** Record payment transaction status and update assessment enrollment.

#### Request Fields (JSON Body):
```json
{
  "candidate_id": 15,
  "assessment_id": 2,
  "amount": 2999.00,
  "reference_number": "TXN_9876543210",
  "status": "success"
}
```

#### Response Fields (JSON Success):
```json
{
  "status": "success",
  "message": "Payment verified and enrollment marked as eligible",
  "data": {
    "payment_id": 108,
    "reference_number": "TXN_9876543210",
    "eligibility_status": "eligible",
    "allocated_batch_id": 5
  }
}
```

---

### Module 3: Slot Booking Endpoint
* **Endpoint Name:** `POST /api/slots/book.php`
* **Purpose:** Reserve an exam time slot for an eligible candidate.

#### Request Fields (JSON Body):
```json
{
  "candidate_id": 15,
  "assessment_id": 2,
  "exam_slot_id": 12
}
```

#### Response Fields (JSON Success):
```json
{
  "status": "success",
  "message": "Exam slot booked successfully",
  "data": {
    "attempt_id": 204,
    "candidate_id": 15,
    "exam_slot_id": 12,
    "exam_date": "2026-09-20",
    "start_time": "10:00:00",
    "end_time": "11:00:00",
    "seats_remaining": 34
  }
}
```

---

### Module 4: Exam Evaluation & Result Endpoint
* **Endpoint Name:** `POST /api/exam/submit.php`
* **Purpose:** Submit candidate answer payload, calculate total score, and assign skill level.

#### Request Fields (JSON Body):
```json
{
  "attempt_id": 204,
  "answers": [
    { "question_id": 101, "selected_option_id": 402 },
    { "question_id": 102, "selected_option_id": 406 },
    { "question_id": 103, "selected_option_id": 410 }
  ]
}
```

#### Response Fields (JSON Success):
```json
{
  "status": "success",
  "message": "Exam submitted and evaluated successfully",
  "data": {
    "result_id": 89,
    "attempt_id": 204,
    "total_score": 42.00,
    "percentage": 84.00,
    "level_assigned": 4,
    "level_name": "Advanced",
    "certificate_eligible": true
  }
}
```

---

## 4. 🔒 Database Governance & Schema Lock Rules

To avoid database downtime, broken queries, or foreign key corruption, all team members must strictly follow these rules:

1. **Strict Schema Lock:**  
   🚫 **DO NOT DELETE or RENAME** any existing table, column, index, or constraint in `schema.sql` or on Railway.
2. **Column Additions Protocol:**  
   If your module feature requires a new column or table, you **MUST notify M1 first**. Only M1 will execute schema modifications on Railway.
3. **No Direct Production Alterations:**  
   Do not run `ALTER TABLE`, `DROP TABLE`, or `TRUNCATE` directly via GUI clients (DBeaver, phpMyAdmin, DataGrip) on the Railway instance.
4. **Use Transactions (`START TRANSACTION / COMMIT / ROLLBACK`):**  
   For critical operations like payment insertion + enrollment status update + seat count reduction, wrap queries inside SQL transactions to prevent partial/corrupted states.

---

## 5. 💬 Team Support & Escalation

* ⚠️ **No Guesswork:** If you have any doubt about foreign key relations, constraints (e.g., `seats_remaining >= 0` check or UNIQUE slot bookings), or query optimization, contact **M1 immediately**.
* 📩 **Point of Contact:** Database Schema Owner (M1)
* 💡 **Rule of Thumb:** Always verify your raw SQL queries against `schema.sql` before pushing backend code to repository branches!
