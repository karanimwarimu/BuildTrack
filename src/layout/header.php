<?php
/** @var string $pageTitle  @var string $activePage */
$pageTitle = $pageTitle ?? 'Dashboard';
$activePage = $activePage ?? 'dashboard';
$navItems = [
    ['dashboard', 'Dashboard', '⌂', true],
    ['projects', 'Projects', '▦', true],
    ['tasks', 'Tasks & schedule', '☷', true],
    ['progress', 'Progress updates', '↗', true],
    ['sitelogs', 'Daily site logs', '✎', true],
    ['resources', 'Resources', '▤', true],
    ['expenses', 'Expenses & budget', '¤', can('expenses.view')],
    ['alerts', 'Deadline alerts', '◷', true],
    ['reports', 'Reports', '▥', true],
];
$navActive = $activePage === 'project' ? 'projects' : $activePage;
$notices = pull_flashes();
$me = user();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> | BuildTrack</title>
<link rel="stylesheet" href="assets/app.css">
<script src="assets/app.js" defer></script>
</head>
<body>
<div class="layout">
<aside class="sidebar" id="sidebar">
    <a class="brand brand-light" href="index.php"><span class="brand-mark">B</span><span>BuildTrack<small>Construction workspace</small></span></a>
    <div class="nav-label">WORKSPACE</div>
    <nav>
      <?php foreach ($navItems as [$key, $label, $symbol, $visible]): if (!$visible) continue; ?>
        <a class="nav-link <?= $navActive === $key ? 'active' : '' ?>" href="<?= e(url($key)) ?>" title="<?= e($label) ?>"><span class="nav-symbol"><?= e($symbol) ?></span><?= e($label) ?></a>
      <?php endforeach; ?>
      <div class="nav-label nav-label-spaced">ACCOUNT</div>
      <a class="nav-link <?= $navActive === 'account' ? 'active' : '' ?>" href="<?= e(url('account')) ?>" title="My account"><span class="nav-symbol">⚙</span>My account</a>
      <?php if (can('admin.access')): ?>
        <a class="nav-link <?= $navActive === 'admin' ? 'active' : '' ?>" href="<?= e(url('admin')) ?>" title="Administration"><span class="nav-symbol">♙</span>Administration</a>
      <?php endif; ?>
    </nav>
    <div class="sidebar-bottom">
      <div class="avatar"><?= e(first_letter($me['full_name'] ?? 'U')) ?></div>
      <div class="user-summary"><strong><?= e($me['full_name'] ?? '') ?></strong><small><?= e(role_label($me['role'] ?? '')) ?></small></div>
      <form method="post" action="<?= e(url('logout')) ?>" class="logout-form"><?= csrf_field() ?><button class="logout" type="submit" title="Sign out" aria-label="Sign out">↪</button></form>
    </div>
</aside>
<div class="nav-backdrop" data-nav-close></div>
<main class="main">
<header class="topbar">
  <button class="nav-toggle" type="button" aria-label="Open menu" aria-controls="sidebar" data-nav-toggle>☰</button>
  <div class="topbar-title"><div class="eyebrow">PROJECT OPERATIONS</div><h1><?= e($pageTitle) ?></h1></div>
  <div class="topbar-user">
    <?php if (is_local_env()): ?><span class="env-badge" title="APP_ENV=local">LOCAL</span><?php endif; ?>
    <span class="online-dot"></span><a href="<?= e(url('account')) ?>"><?= e($me['full_name'] ?? '') ?></a>
  </div>
</header>
<div class="content">
<?php if (is_demo_mode()): ?><div class="demo-strip">Public demo — data resets automatically when the server restarts. Please don't enter real personal information.</div><?php endif; ?>
<?php foreach ($notices as $notice): ?><div class="alert alert-<?= e($notice['type']) ?>" role="status"><?= e($notice['message']) ?></div><?php endforeach; ?>
