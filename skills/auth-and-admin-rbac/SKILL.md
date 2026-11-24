---
name: auth-and-admin-rbac
description: How BuildTrack's login, registration, password reset, sessions, roles/permissions and admin elevation work, and how to change them safely with tests. Use for any change touching authentication, account management, role checks or access to data.
---

# Auth, password resets & admin RBAC

## When to use
- Adding a permission, changing who can do what, or adding a role.
- Changing login, registration, lockout, sessions, or the forgot/reset flow.
- Adding admin-only screens or CLI account commands.
- Investigating "user can't log in" or "user sees data they shouldn't".

## Map of the code
| Concern | Where |
|---|---|
| Permission map, `can()`, `require_permission()`, row visibility | `src/helpers.php` (`PERMISSIONS`, `current_task_access_sql()`, `current_project_access_sql()`, `project_visible()`, `task_visible()`) |
| Current user (re-read from DB each request, checks `is_active` + `session_version`) | `user()` in `src/helpers.php` |
| Login/throttle, registration, reset tokens, last-admin guard, dev elevation, env admin | `src/auth.php` |
| Email delivery / mail.log | `src/mailer.php` |
| Route gate (public vs private routes, forced password change) | `public/index.php` |
| Screens | `pages/auth/*.php`, `pages/account.php`, `pages/admin.php` |
| CLI recovery | `bin/console` (`make-admin`, `set-role`, `reset-password`, `reset-link`, `unlock`, `activate`/`deactivate`) |
| Tests | `tests/auth_test.php`, `tests/http_test.php` |

## Rules that must stay true
1. **Every POST verifies CSRF** (`verify_csrf()`) before doing anything. Logout is POST.
2. **Authorization is server-side**: check `can()`/`require_permission()` in the POST handler, not only in the template that hides the button.
3. **Role lists live only in `PERMISSIONS`.** Add a named permission instead of writing `in_array($role, [...])` in a page.
4. **Workers see only their own work.** Any new query over tasks/projects or their children must apply `current_task_access_sql()` or `current_project_access_sql()`, or check `project_visible()` / `task_visible()` for single records.
5. **Never reveal whether an email exists.** Login and forgot-password return identical messages and timing for unknown emails.
6. **Reset tokens:** random 32 bytes, only `hash('sha256')` stored, single-use, TTL, invalidated by a newer token or a password change. Show links on screen only when `is_local_env()` and email was not delivered.
7. **Password changes call `set_user_password()`**, which bumps `session_version` (signs out other sessions) and marks open tokens used. After changing your *own* password, call `start_user_session()` again.
8. **The last active admin cannot be demoted or deactivated.** Use `change_user_role()` / `set_user_active()`; never update `users.role` directly.
9. **Dev elevation needs all three:** `ALLOW_DEV_ADMIN_ELEVATION=true`, `APP_ENV=local`, loopback IP. Keep it audited.
10. **Audit security events** with `audit('auth.*' | 'admin.*', 'user', $id, [...])`.

## Recipes

### Add a permission
1. Add `'inspections.manage' => ['admin', 'project_manager', 'site_supervisor'],` to `PERMISSIONS`.
2. In the page: `require_permission('inspections.manage');` inside the POST branch; `<?php if (can('inspections.manage')): ?>` around forms and buttons.
3. Add an assertion row to the "permission map matches the role design" test.
4. Update the permission table in `ARCHITECTURE.md` §4.

### Add a role
1. Write a migration that rebuilds `users` with the new CHECK list (see `sqlite-schema-and-migrations`, table rebuild).
2. Add it to `ROLES` and `role_label()` in `src/helpers.php`, plus `.role-<name>` colours in `public/assets/app.css`.
3. Grant it permissions in `PERMISSIONS`. Decide whether it gets `projects.view_all`.
4. Update the README roles table, ARCHITECTURE §4 and tests.

### Add an account action to the admin page
1. Add a `case 'my_action':` in the `switch` at the top of `pages/admin.php`. The POST is already CSRF-checked and admin-only.
2. Put the logic in a function in `src/auth.php` taking `PDO $pdo` (testable). Throw `UserError` for user-facing problems.
3. Call `audit('admin.my_action', 'user', $targetId, [...])`, then `flash()`.
4. If it makes sense on the server, add a matching `bin/console` command.

### Change lockout or reset policy
Edit `.env` keys (`LOGIN_MAX_ATTEMPTS`, `LOGIN_LOCKOUT_MINUTES`, `RESET_TOKEN_TTL_MINUTES`). Change constants (`RESET_REQUESTS_PER_WINDOW`, `PASSWORD_MIN_LENGTH`) in `src/auth.php` only if the policy itself changes, and update the tests.

## Verification
```bash
php tests/run.php auth            # unit tests for auth/RBAC
php tests/run.php HTTP            # end-to-end: gate, register, RBAC 403s, logout, reset link
make test
```
Manual checks:
- Sign in as each demo role (`./start.sh --seed`) and open `/admin`, `/expenses`, and another worker's project. Expect 403/404 where appropriate.
- Forgot password with `APP_ENV=local` shows the link; with `APP_ENV=production` it does not, and the link is in `data/logs/mail.log`.
- `php bin/console audit --limit=20` shows the events you expect.

## Troubleshooting
- **"Too many failed sign-in attempts"**: `php bin/console unlock user@x.com`.
- **Locked out of every admin account**: `php bin/console make-admin you@x.com`, then `php bin/console reset-password you@x.com`.
- **User keeps getting signed out**: their `session_version` changed (password reset, deactivation), or the `data/sessions/` folder is not writable.
- **Registration says closed**: `ALLOW_REGISTRATION=false`. Admins create accounts, or the first account must be created.
