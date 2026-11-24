<?php
/** Shared two-column layout for the public auth pages (login, register, forgot, reset). */

function auth_page_start(string $title, string $eyebrow, string $heading, string $subtitle): void
{
    $notices = pull_flashes();
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> | BuildTrack</title><link rel="stylesheet" href="assets/app.css"><script src="assets/app.js" defer></script></head>
<body class="login-bg"><main class="auth-wrap">
<section class="auth-intro">
  <div class="brand brand-light"><span class="brand-mark">B</span><span>BuildTrack<small>Construction workspace</small></span></div>
  <div class="auth-message"><span class="pill pill-blue">PROJECT CONTROL, SIMPLIFIED</span>
    <h1>Keep every project<br>moving forward.</h1>
    <p>Manage work schedules, track site progress and spending, coordinate resources and stay ahead of deadlines in one place.</p></div>
  <div class="auth-foot">Construction Project Management and Scheduling System</div>
</section>
<section class="auth-panel"><div class="auth-card">
  <div class="mobile-brand">BUILDTRACK</div>
  <div class="eyebrow"><?= e($eyebrow) ?></div>
  <h2><?= e($heading) ?></h2>
  <p class="muted"><?= e($subtitle) ?></p>
  <?php foreach ($notices as $n): ?><div class="alert alert-<?= e($n['type']) ?>" role="status"><?= e($n['message']) ?></div><?php endforeach; ?>
<?php
}

function auth_page_end(): void
{
    echo '</div></section></main></body></html>';
}
