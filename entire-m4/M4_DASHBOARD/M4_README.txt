M4 - InternBoot Payment, Enrollment & Dashboard

This package contains the M4 frontend plus PHP backend.

DATABASE
- The PHP backend is configured to use the Railway MySQL PUBLIC connection in .env.
- No local MySQL database is required for this configuration.
- Keep .env private and never commit it to Git.

RUN LOCALLY
1. Put this folder inside C:\xampp\htdocs\
2. Start Apache in XAMPP.
3. Open:
   http://localhost/M4_InternBoot_Railway_MySQL_Integrated/dashboard.html
4. Test the database connection:
   http://localhost/M4_InternBoot_Railway_MySQL_Integrated/backend/connection-test.php
5. Test the dashboard API:
   http://localhost/M4_InternBoot_Railway_MySQL_Integrated/backend/dashboard.php?candidate_id=1

M4 API
- GET  backend/dashboard.php?candidate_id=ID
- GET  backend/enrollment.php?candidate_id=ID
- POST backend/payment.php  {"action":"create", "candidate_id":ID, "assessment_id":ID}
- POST backend/payment.php  {"action":"verify", "candidate_id":ID, "payment_id":ID}

IMPORTANT
The Railway password in .env is a credential. Rotate it in Railway after sharing/testing if needed.

Dashboard note: dashboard.html uses dashboard.php for dynamic database-backed values. Static descriptive labels remain hardcoded.
