<?php
require_once __DIR__ . '/../src/core/bootstrap.php';
require_once __DIR__ . '/../src/core/candidate_resolver.php';

if (isset($_SESSION['user_id']) || isset($_SESSION['candidate_id'])) {
    $role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';

    if (in_array($role, ['admin', 'staff'], true)) {
        $validatedRole = resolve_admin_role($conn);
        if ($validatedRole && in_array($validatedRole, ['admin', 'staff'], true)) {
            header('Location: /admin/index.html');
            exit;
        }
    } elseif ($role === 'candidate' || isset($_SESSION['candidate_id'])) {
        $validatedCid = validate_candidate_session($conn);
        if ($validatedCid !== null) {
            header('Location: /dashboard.html');
            exit;
        }
    } else {
        // Unknown or unset role: check admin/staff first, then candidate
        $validatedRole = resolve_admin_role($conn);
        if ($validatedRole && in_array($validatedRole, ['admin', 'staff'], true)) {
            header('Location: /admin/index.html');
            exit;
        }
        $validatedCid = validate_candidate_session($conn);
        if ($validatedCid !== null) {
            header('Location: /dashboard.html');
            exit;
        }
    }

    // If validation failed (deactivated, row missing, or session stale), destroy session entirely
    destroy_session();
}

$pageTitle = 'Log In — InternBoot';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body>

<main class="ib-auth-page-rich">
  <div class="ib-auth-card-full">

    <img src="/assets/css/internboot-official-logo.webp" alt="InternBoot" class="ib-form-logo">

    <h1 class="ib-auth-title text-center">Welcome back to <span class="ib-gradient-text">InternBoot</span></h1>
    <p class="ib-auth-sub text-center">Log in to continue your assessment journey.</p>

    <form id="loginForm" novalidate>

      <div class="ib-role-group">
        <label class="ib-role-card selected" id="roleCardStudent">
          <input type="radio" name="role" value="candidate" checked>
          <span class="ib-role-icon">🎓</span>
          <span class="ib-role-label">Student</span>
          <span class="ib-role-desc">Log in to your dashboard</span>
        </label>
        <label class="ib-role-card" id="roleCardAdmin">
          <input type="radio" name="role" value="admin">
          <span class="ib-role-icon">🛠️</span>
          <span class="ib-role-label">Admin</span>
          <span class="ib-role-desc">Access the admin panel</span>
        </label>
      </div>

      <div class="ib-form-row">
        <label for="email">Email Address</label>
        <input type="email" class="ib-input" id="email" name="email" autocomplete="email" required>
      </div>
      <div class="ib-form-row">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <label for="password" class="mb-0">Password</label>
            <a href="/forgot-password.php" class="text-decoration-none" style="font-size: 0.85rem; color: #1E4FD1;">Forgot password?</a>
        </div>
        <div class="ib-password-wrap">
          <input type="password" class="ib-input" id="password" name="password" autocomplete="current-password" required>
          <button type="button" class="ib-toggle-password" data-target="password" aria-label="Show password">👁️</button>
        </div>
      </div>

      <div id="formAlert" class="ib-alert d-none"></div>

      <button type="submit" class="ib-btn-primary" id="loginBtn">Log in</button>
    </form>

    <p class="ib-auth-footer text-center">New to InternBoot? <a href="/register.php">Register now</a></p>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="/assets/js/auth.js"></script>
</body>
</html>