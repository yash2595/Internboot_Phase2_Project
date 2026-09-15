# 📘 InternBoot Developer Module Guide

Welcome team! Follow these instructions to build your assigned feature module cleanly without merge conflicts.

---

## 🎯 Golden Rules for All Developers (M1 to M7)

1. **Isolation:** Only write business logic code inside your assigned module directory: `/src/modules/<module_name>/`.
2. **Entry Points:** Public API endpoints must be placed inside `/public/api/<module_name>/<feature>.php`.
3. **Bootstrapping:** Every public API file MUST start by loading the bootstrap initializer:
   ```php
   require_once __DIR__ . '/../../../src/core/bootstrap.php';
   ```
4. **Pattern to Follow:** Follow the 3-layer architecture already demonstrated in M3 Auth (`controller.php` -> `service.php` -> `queries.php`).
   - **`controller.php`:** Parses JSON input, validates request params, calls service, returns `send_json_response()`.
   - **`service.php`:** Business logic, computations, calls queries.
   - **`queries.php`:** Pure SQL execution using MySQLi Prepared Statements. NEVER write SQL in controller or service!

---

## 🗺️ Module Assignment & Instructions

### 🔹 Module 1: AI Question Bank (`src/modules/m1_ai_qbank/`)
* **Lead Developer:** M1
* **Task:** Implement AI question generation algorithms, prompt parsing, and question bank approval endpoints (`/public/api/qbank/`).

### 🔹 Module 2: Landing Page & Public Info (`src/modules/m2_landing/`)
* **Developer:** M2
* **Task:** Build public landing page views, course assessment summaries, and homepage features (`/src/modules/m2_landing/`).

### 🔹 Module 3: Authentication & User Profile (`src/modules/m3_auth/`)
* **Developer:** M3 (Reference Implementation Already Completed)
* **Task:** User registration, password hashing, login API (`/public/api/auth/login.php`), session state.

### 🔹 Module 4: Payment Verification & Dashboard (`src/modules/m4_payment_dashboard/`)
* **Developer:** M4
* **Task:** Process Razorpay/UPI payment callbacks (`/public/api/payment/verify.php`), update candidate eligibility, render candidate dashboard.

### 🔹 Module 5: Batch & Slot Allocation (`src/modules/m5_batch_slots/`)
* **Developer:** M5
* **Task:** Batch threshold grouping calculation, weekend exam schedule generation, slot booking API (`/public/api/slots/book.php`).

### 🔹 Module 6: Exam Engine & MCQ Renderer (`src/modules/m6_exam_engine/`)
* **Developer:** M6
* **Task:** Timer-based MCQ exam interface, question randomizer, real-time auto-save answer payload (`/public/api/exam/submit.php`).

### 🔹 Module 7: Evaluation, Level Assignment & Admin (`src/modules/m7_evaluation_admin/`)
* **Developer:** M7
* **Task:** Score evaluation engine, candidate level (Level 1-5) mapping, PDF certificate generator (`/public/api/admin/evaluate.php`), admin audit logs.
