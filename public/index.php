<?php
declare(strict_types=1);

/**
 * Front controller. Every page is index.php?page=<name> (or /<name> with the bundled router / .htaccess).
 * Unauthenticated visitors only reach the PUBLIC_ROUTES; everything else redirects to the login page.
 */

require __DIR__ . '/../src/bootstrap.php';
$pdo = bootstrap_web();

const PAGES_DIR = __DIR__ . '/../pages';

const PUBLIC_ROUTES = [
    'login' => 'auth/login.php',
    'register' => 'auth/register.php',
    'forgot' => 'auth/forgot.php',
    'reset' => 'auth/reset.php',
];

const PRIVATE_ROUTES = [
    'dashboard' => 'dashboard.php',
    'projects' => 'projects.php',
    'project' => 'project.php',
    'tasks' => 'tasks.php',
    'progress' => 'progress.php',
    'resources' => 'resources.php',
    'expenses' => 'expenses.php',
    'sitelogs' => 'sitelogs.php',
    'alerts' => 'alerts.php',
    'reports' => 'reports.php',
    'attachment' => 'attachment.php',
    'account' => 'account.php',
    'admin' => 'admin.php',
    'logout' => 'auth/logout.php',
];

$page = preg_replace('/[^a-z_]/', '', get_str('page', 'dashboard')) ?: 'dashboard';
if ($page === 'users' || $page === 'setup') { // old URLs
    redirect($page === 'users' ? url('admin') : url('register'));
}

if (isset(PUBLIC_ROUTES[$page])) {
    require PAGES_DIR . '/' . PUBLIC_ROUTES[$page];
    exit;
}

if (!logged_in()) {
    if ($page !== 'dashboard') {
        flash('info', 'Please sign in to continue.');
    }
    redirect(url('login'));
}

// Accounts flagged by an admin/CLI reset must choose a new password before doing anything else.
if ((int)user()['must_change_password'] === 1 && !in_array($page, ['account', 'logout'], true)) {
    flash('info', 'Please choose a new password before continuing.');
    redirect(url('account'));
}

if (!isset(PRIVATE_ROUTES[$page])) {
    http_response_code(404);
    render_message_page('Page not found', 'That page does not exist.');
}
$activePage = $page;
require PAGES_DIR . '/' . PRIVATE_ROUTES[$page];
