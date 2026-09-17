# M7 Admin Ready Build

This build keeps the existing HTML/CSS UI and IDs intact and updates the JavaScript/PHP behavior.

Implemented:
- Candidate search and Payment/Enrollment/Assessment filters
- Candidate View
- Add Candidate (creates a candidate user and returns a temporary password)
- Batch weekend validation and creation
- Slot creation and candidate allocation
- Question search/level/status filters and approve/reject
- Results search/level/status filters, View and Evaluate
- Certificate search/level/status filters, View/Download/Verify
- Placement search/level/status filters and placement editor
- Settings/profile save and preferences
- Lucide icons re-rendered after dynamic table updates
- Uses DB_* and Railway MYSQL_* environment variables from .env
- Railway public host corrected to `tokaido.proxy.rlwy.net` based on the provided Railway screenshot.

Run from this folder:
C:\xampp\php\php.exe -S localhost:8000

Then open:
http://localhost:8000/admin/index.html

Do not open `http://localhost:8000/` because there is no root index.php/html file.

If the database is empty, run schema.sql in the Railway MySQL database before testing CRUD operations.
