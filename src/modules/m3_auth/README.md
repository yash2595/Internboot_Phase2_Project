# M3 — Registration & Login

Updated against the real `schema.sql` pushed by M1.

## Files
- `src/modules/m3_auth/queries.php` — all SQL (prepared statements + transaction)
- `src/modules/m3_auth/service.php` — hashing, verification, duplicate checks
- `src/modules/m3_auth/controller.php` — request validation + session handling
- `public/api/auth/register.php`, `login.php`, `logout.php` — thin endpoints
- `public/register.php`, `public/login.php` — UI pages
- `public/assets/css/auth.css`, `public/assets/js/auth.js` — form styling + fetch wiring
- `public/assets/images/internboot-logo.png` — logo file

## How registration maps to the schema
Registration writes to **two tables in one transaction**:

| Field (form) | Table.column |
|---|---|
| email | `users.email` |
| password | `users.password` (bcrypt hash) |
| — | `users.role` = `'candidate'` (fixed) |
| full_name | `candidates.full_name` |
| phone | `candidates.phone` |

`candidates.user_id` is set from the `users` insert's `insert_id` inside the
same transaction — if the second insert fails (e.g. duplicate phone slips
past the pre-check in a race), the whole transaction rolls back so you never
get an orphaned `users` row without a matching `candidates` row.

`service.php` pre-checks both `email` (via `users`) and `phone` (via
`candidates`, which has its own UNIQUE constraint) before attempting the
insert, so the normal path never hits the database's duplicate-key error at
all — the transaction/rollback in `queries.php` is just a safety net for the
race-condition case.

## What M3 does *not* touch
- **No status field on `users`.** I initially assumed a `registered → paid →
  enrolled` progression lived on the user row — the real schema puts that on
  `enrollments.eligibility_status` (`pending`/`eligible`) instead, which
  belongs to M4 (payment) and M5 (batch/slot), not M3. Nothing in this module
  reads or writes `enrollments`.
- **`users.is_active`** is checked on login (blocks login with "This account
  has been deactivated" if `0`) but M3 never sets it — that's an admin action
  (M7's Admin Panel).

## Theme / logo
`register.php` / `login.php` reuse your existing `src/templates/header.php`,
`navbar.php`, `footer.php` includes so the site-wide theme carries over
automatically. `auth.css` is scoped to `.ib-*` classes only and just styles
the form card — colors approximated from your landing-page screenshot.

If the navbar doesn't already render the logo, add inside `navbar.php`:
```html
<a class="navbar-brand" href="/">
  <img src="/assets/images/internboot-logo.png" alt="InternBoot" height="40">
</a>
```

## Security checklist (implemented)
- `password_hash()` (bcrypt) — matches the schema's own column comment
  ("Bcrypt/Argon2id hashed password, never plaintext")
- `password_verify()` on login, `is_active` gate after verification
- `session_regenerate_id(true)` on every successful login
- Prepared statements everywhere — no string-concatenated SQL
- Two-table registration wrapped in `begin_transaction()` / `commit()` /
  `rollback()`
- Generic "Incorrect email or password" message either way
- Only a session id is stored client-side, never user data

## Endpoints (for the shared API contract doc)
| Method | Endpoint | Body | Success | Error |
|---|---|---|---|---|
| POST | `/api/auth/register.php` | `full_name, email, phone, password, confirm_password` | 201 `{status:"success", data:{user_id}}` | 422 validation / 409 duplicate email or phone |
| POST | `/api/auth/login.php` | `email, password` | 200 `{status:"success", data:{role, redirect}}` | 422 validation / 401 bad credentials or deactivated |
| POST | `/api/auth/logout.php` | — | 200 `{status:"success"}` | — |

## For M4/M5 (handoff)
Once a candidate registers, `candidates.id` is what you'll use as the FK in
`enrollments.candidate_id`. You'll need `$_SESSION['user_id']` (the
`users.id`) to look up the matching `candidates.id` — there's no
`get_candidate_id_by_user_id()` helper in this module yet since it wasn't
needed for register/login; add one in your own module or ask M3 to expose it
if you'd rather keep the query centralized.
