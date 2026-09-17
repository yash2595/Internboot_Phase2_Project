# 🚀 InternBoot - Automated Level Assessment & Certification Platform

Welcome to the **InternBoot** core repository! This platform is built using **Core PHP**, **MySQL**, and **Bootstrap/Vanilla JS**, deployed on **Railway.app**.

---

## 🛠️ Local Environment Setup Instructions

Follow these exact steps after cloning the repository:

### Step 1: Install Dependencies
Open terminal in the project root directory and run:
```bash
composer install
```

### Step 2: Configure Local Environment (`.env`)
Copy `.env.example` to create your private `.env` file:
```bash
cp .env.example .env
```
Open `.env` and fill in your local or assigned Railway database credentials:
```env
DB_HOST=okaido.proxy.rlwy.net
DB_PORT=18068
DB_USER=root
DB_PASSWORD=your_assigned_password
DB_NAME=railway
```
> ⚠️ **STRICT WARNING:** NEVER commit or push `.env` to Git! It is excluded by `.gitignore`.

### Step 3: Serve the Application
Point your local web server (XAMPP / Apache / Nginx / PHP built-in server) to the `/public` directory:
```bash
php -S localhost:8000 -t public
```
Navigate to `http://localhost:8000` in your browser.

---

## 🌿 Git Branching Strategy (Conflict Prevention)

To prevent merge conflicts across 7 parallel modules, **NEVER commit directly to `main`**.

### Branch Naming Convention:
Each team member MUST work in their assigned module feature branch:
- **M1 (DB & AI QBank):** `feature/m1-ai-qbank`
- **M2 (Landing Page):** `feature/m2-landing`
- **M3 (Auth & Profile):** `feature/m3-auth`
- **M4 (Payments & Dashboard):** `feature/m4-payment`
- **M5 (Batches & Slots):** `feature/m5-slots`
- **M6 (Exam Engine):** `feature/m6-exam`
- **M7 (Evaluation & Admin):** `feature/m7-admin`

### Daily Workflow:
```bash
# 1. Checkout your feature branch
git checkout -b feature/m4-payment

# 2. Commit changes inside YOUR module folder ONLY (/src/modules/m4_...)
git add src/modules/m4_payment_dashboard/ public/api/payment/
git commit -m "feat(m4): added razorpay payment callback handler"

# 3. Push feature branch and open Pull Request for M1 review
git push origin feature/m4-payment
```

---

## 🗂️ Module Directory Mapping

| Module ID | Responsible Developer | Assigned Working Directory |
| :--- | :--- | :--- |
| **M1** | Lead / M1 | `/src/core/`, `/src/modules/m1_ai_qbank/` |
| **M2** | Developer 2 | `/src/modules/m2_landing/` |
| **M3** | Developer 3 | `/src/modules/m3_auth/` |
| **M4** | Developer 4 | `/src/modules/m4_payment_dashboard/` |
| **M5** | Developer 5 | `/src/modules/m5_batch_slots/` |
| **M6** | Developer 6 | `/src/modules/m6_exam_engine/` |
| **M7** | Developer 7 | `/src/modules/m7_evaluation_admin/` |

---

## 🔒 Code Standards & Rulebook

1. **Do not modify `/src/core/` files** (`db.php`, `session.php`, `response.php`). Only M1 updates shared core utilities.
2. **All SQL queries MUST use Prepared Statements** (`$stmt = $conn->prepare(...)`) to prevent SQL Injection.
3. **All API responses MUST return JSON** using `send_json_response($status, $message, $data)`.
