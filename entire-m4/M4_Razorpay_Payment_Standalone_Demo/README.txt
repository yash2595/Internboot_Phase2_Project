INTERNBOOT M4 - PAYU TEST PAYMENT MODULE

Purpose
- M4 standalone payment module using PayU Hosted Checkout TEST environment.
- Registration fee is fixed at INR 2999 for the requested test flow.
- Payment is verified server-side before enrollment is marked eligible.
- Success, failure, pending and cancelled/non-success paths do not create an eligible enrollment unless PayU verification returns success.

Setup
1. Copy this folder into C:\xampp\htdocs\.
2. Copy your working database .env into this folder root, or update it from .env.example.
3. Add the PayU TEST key and TEST salt provided by your lead:
   PAYU_TEST_KEY=...
   PAYU_TEST_SALT=...
4. Start Apache in XAMPP.
5. Open: http://localhost/M4_Razorpay_Payment_Standalone_Demo/
6. Use candidate_id and assessment_id query parameters if needed.

Important
- Do not use production PayU credentials for this task.
- Keep PAYU_TEST_SALT server-side only; never put it in HTML/JavaScript.
- PayU Hosted Checkout needs reachable success/failure URLs. For localhost testing, if PayU cannot reach your local callback, use the project through a public HTTPS tunnel/domain or the test environment provided by your team.
- The existing MySQL tables are reused; no SQL is included in this package.
- Existing .env containing database secrets is intentionally not included in the final handoff ZIP.

Flow
Register/Candidate -> Create pending payment -> PayU TEST checkout (INR 2999) -> PayU return -> response hash validation -> server-side Verify Payment API -> payment success -> enrollment eligible.
