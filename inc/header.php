<?php
$pageTitle = $pageTitle ?? 'Dashboard';
$activePage = $_GET['page'] ?? 'dashboard';
$navItems = [
    ['dashboard','Dashboard','⌂'], ['projects','Projects','▦'], ['tasks','Tasks & schedule','☷'],
    ['progress','Progress updates','↗'], ['resources','Resources','▤'], ['alerts','Deadline alerts','◷'], ['reports','Reports','▥']
];
$notice = pull_flash();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> | BuildTrack</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="layout">
<aside class="sidebar">
    <a class="brand" href="index.php"><span class="brand-mark">B</span><span>BuildTrack<small>Construction workspace</small></span></a>
    <div class="nav-label">WORKSPACE</div>
    <nav>
      <?php foreach ($navItems as [$key,$label,$symbol]): ?>
        <a class="nav-link <?= $activePage === $key ? 'active' : '' ?>" href="index.php?page=<?= e($key) ?>"><span class="nav-symbol"><?= e($symbol) ?></span><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php if (is_admin()): ?><div class="nav-label nav-label-spaced">ADMINISTRATION</div><a class="nav-link <?= $activePage === 'users' ? 'active' : '' ?>" href="index.php?page=users"><span class="nav-symbol">♙</span>User accounts</a><?php endif; ?>
    </nav>
    <div class="sidebar-bottom"><div class="avatar"><?= e(strtoupper(substr(user()['full_name'] ?? 'U', 0, 1))) ?></div><div class="user-summary"><strong><?= e(user()['full_name'] ?? '') ?></strong><small><?= e(role_label(user()['role'] ?? '')) ?></small></div><a class="logout" href="logout.php" title="Sign out">↪</a></div>
</aside>
<main class="main">
<header class="topbar"><div><div class="eyebrow">PROJECT OPERATIONS</div><h1><?= e($pageTitle) ?></h1></div><div class="topbar-user"><span class="online-dot"></span><?= e(user()['full_name'] ?? '') ?></div></header>
<div class="content">
<?php if ($notice): ?><div class="alert alert-<?= e($notice['type']) ?>"><?= e($notice['message']) ?></div><?php endif; ?>
