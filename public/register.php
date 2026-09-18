<?php
require_once __DIR__ . '/../src/core/bootstrap.php';

if (isset($_SESSION['user_id'])) {
    header('Location: /dashboard.php');
    exit;
}

$pageTitle = 'Register — InternBoot';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body>

<main class="ib-auth-page">
  <div class="ib-auth-grid">

    <section class="ib-brand-panel">
      <img src="/assets/css/internboot-official-logo.webp" alt="InternBoot" class="ib-brand-logo">
      <h2 class="ib-brand-title">Launch your career with InternBoot</h2>
      <p class="ib-brand-sub">Register, take the verified assessment, and earn your official Level 1–5 certificate</p>
      <ul class="ib-brand-features">
        <li>Verified Level 1–5 certification</li>
        <li>100% remote &amp; self-paced</li>
        <li>Official completion certificate</li>
      </ul>
    </section>

    <section class="ib-form-panel">
      <div class="ib-auth-card">
        <img src="/assets/css/internboot-official-logo.webp" alt="InternBoot" class="ib-mobile-logo">

        <h1 class="ib-auth-title">Welcome Back to <span class="ib-gradient-text">InternBoot</span> </h1>
        <p class="ib-auth-sub text-center">Register to start your journey</p>

        <form id="registerForm" novalidate>

          <div class="ib-role-group">
            <label class="ib-role-card selected" id="roleCardStudent">
              <input type="radio" name="role" value="candidate" checked>
              <span class="ib-role-icon">🎓</span>
              <span class="ib-role-label">Student</span>
              <span class="ib-role-desc">Take assessments &amp; earn certificates</span>
            </label>
            <label class="ib-role-card" id="roleCardAdmin">
              <input type="radio" name="role" value="admin">
              <span class="ib-role-icon">🛠️</span>
              <span class="ib-role-label">Admin</span>
              <span class="ib-role-desc">Manage batches &amp; candidates</span>
            </label>
          </div>

          <div class="ib-form-row">
            <label for="full_name">Full Name</label>
            <input type="text" class="ib-input" id="full_name" name="full_name" autocomplete="name" required>
          </div>
          <div class="ib-form-row">
            <label for="email">Email Address</label>
            <input type="email" class="ib-input" id="email" name="email" autocomplete="email" required>
          </div>
          <div class="ib-form-row">
            <label for="phone">Phone Number</label>
            <input type="tel" class="ib-input" id="phone" name="phone" maxlength="17" placeholder="e.g. +91 98765 43210" autocomplete="tel" required>
          </div>
          <div class="ib-form-row">
            <label for="password">Password</label>
            <div class="ib-password-wrap">
              <input type="password" class="ib-input" id="password" name="password" minlength="8" autocomplete="new-password" required>
              <button type="button" class="ib-toggle-password" data-target="password" aria-label="Show password">👁️</button>
            </div>
          </div>
          <div class="ib-form-row">
            <label for="confirm_password">Confirm Password</label>
            <div class="ib-password-wrap">
              <input type="password" class="ib-input" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required>
              <button type="button" class="ib-toggle-password" data-target="confirm_password" aria-label="Show password">👁️</button>
            </div>
          </div>

          <div id="formAlert" class="ib-alert d-none"></div>

          <button type="submit" class="ib-btn-primary" id="registerBtn">Register now</button>
        </form>

        <form id="otpForm" novalidate class="d-none">
          <p class="ib-auth-sub text-center" style="margin-bottom: 28px;">
            We've sent a 6-digit code to <strong id="otpEmailDisplay"></strong>. Enter it below to verify your account.
          </p>

          <div class="ib-form-row">
            <label for="otp_code">Verification Code</label>
            <input type="text" class="ib-input" id="otp_code" name="otp_code" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" placeholder="000000" style="letter-spacing: 8px; font-size: 1.4rem; text-align: center; font-weight: 700;" required>
          </div>

          <div id="otpAlert" class="ib-alert d-none"></div>

          <button type="submit" class="ib-btn-primary" id="verifyOtpBtn">Verify &amp; Create Account</button>
          <button type="button" id="resendOtpBtn" class="ib-btn-primary" style="background: transparent; color: var(--ib-blue); margin-top: 12px; box-shadow: none;">Resend Code</button>
        </form>

        <p class="ib-auth-footer">Already registered? <a href="/login.php">Log in</a></p>
      </div>
    </section>

  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/auth.js"></script>
</body>
</html>