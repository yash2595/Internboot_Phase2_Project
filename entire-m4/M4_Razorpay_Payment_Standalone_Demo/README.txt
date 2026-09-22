INTERNBOOT M4 - STANDALONE DEMO PAYMENT

URL:
http://localhost/M4_Razorpay_Payment_Standalone_Demo/

Purpose:
- Independent payment module for M4.
- No Razorpay account, PAN, KYC or external gateway required.
- Uses the existing MySQL schema and existing Railway database.
- Reads exam_fee from settings (currently the schema seed defines exam_fee as 2999).
- Creates a pending payment.
- Generates a server-side demo verification token in the PHP session.
- Only after successful server verification does it mark payment=success and enrollment=eligible.

Setup:
1. Copy this folder into C:\xampp\htdocs\
2. Rename it to M4_Razorpay_Payment_Standalone if desired.
3. Copy your working .env from the dashboard project into this folder root.
4. Keep the same DB_HOST / DB_PORT / DB_USER / DB_PASSWORD / DB_NAME values.
5. Start Apache in XAMPP.
6. Open the URL above.
7. Candidate defaults to candidate_id=1. You can use ?candidate_id=1&assessment_id=1.

IMPORTANT:
- Do NOT run any SQL from this package. It uses the existing database schema.
- This is a demo/sandbox simulation, not a real payment processor.
- Later a real gateway can replace the create/verify implementation without changing the checkout UI contract.
