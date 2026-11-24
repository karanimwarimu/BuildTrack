# Manual acceptance test plan

Run these checks after `./start.sh` on an **empty** database (for T01–T03), then `php bin/console seed --fresh` for the rest. Demo password: `BuildTrack-demo-2026`. Record the actual result and date; do not claim a test passed until you run it. Automated coverage: `make test`.

| ID | Area | Test | Expected result |
|---|---|---|---|
| T01 | Setup | Open the URL printed by `./start.sh` on a fresh install | Redirected to sign-in; banner offers "Create the first account" |
| T02 | Setup | Register the first account | Account is created, signed in, and has the Administrator role |
| T03 | Setup | Register a second account | Account is created with the Worker role |
| T04 | Authentication | Sign in with a wrong password | Generic "email address or password is incorrect" error |
| T05 | Authentication | Fail sign-in 5 times for one email | 6th attempt shows the lockout message; `php bin/console unlock <email>` clears it |
| T06 | Authentication | Sign out, then open the dashboard URL | Redirected to sign-in |
| T07 | Password reset | Forgot password (APP_ENV=local, no SMTP) | Generic confirmation plus the reset link on screen; link also in `data/logs/mail.log` |
| T08 | Password reset | Open the link, set a new password, then reuse the link | Password changes; reused link says invalid/used |
| T09 | Password reset | Admin → Users → "Set temporary" for a worker, then sign in as the worker | Worker is forced to My account to choose a new password |
| T10 | Admin | `php bin/console make-admin worker@buildtrack.local` | Worker can open `/admin` after reload |
| T11 | Admin | Try to demote/deactivate the only admin (UI and `set-role`) | Refused with "last active administrator" message |
| T12 | Admin | Set `ALLOW_DEV_ADMIN_ELEVATION=true`, restart, open My account as a worker from localhost | "Make me an administrator" button appears and works; audit log shows `admin.dev_elevation` |
| T13 | Access | Worker opens `/admin` and `/index.php?page=expenses` | Access denied (403) |
| T14 | Projects | Create a project with a valid date range and budget | Redirected to the project page showing the details |
| T15 | Validation | Set project end date earlier than start date | Rejected with an error message |
| T16 | Tasks | Create a task assigned to a worker | Task appears; the worker sees it and can open its project |
| T17 | Access | Worker views the task register and projects | Only their assigned tasks/projects are listed; other project URLs return "not found" |
| T18 | Progress | Worker submits 100% for an assigned task | Task becomes Completed; history entry stored |
| T19 | Progress | Mark a task Blocked without notes | Rejected: notes are required for blocked tasks |
| T20 | Alerts | Create an unfinished task with a past due date | Appears in overdue alerts and in dashboard "Needs attention" |
| T21 | Resources | Set a low-stock threshold above the remaining quantity | Resource shows "Low stock" on resources, dashboard and alerts |
| T22 | Resources | Allocate more than the available quantity | Allocation rejected, showing what is left |
| T23 | Expenses | Record expenses exceeding a project's budget | Warning flash; budget bar turns red on dashboard/project/expenses |
| T24 | Site logs | Supervisor writes today's site log with an issue | Log appears on Site logs, project page and dashboard; worker can read it |
| T25 | Files | Upload a PDF/photo to a project; try uploading a `.php` file | PDF opens inline; `.php` rejected as not allowed |
| T26 | Reports | Export the task report and Admin → CSV exports | CSV files download; cells starting with `=` are prefixed with `'` |
| T27 | Backup | Admin → Backups → Download a fresh backup | `.sqlite` file downloads; `sqlite3 file 'PRAGMA integrity_check'` prints `ok` |
| T28 | Restore | Stop the app, `php bin/console restore <file>`, start again | Data matches the backup; `pre-restore-*.sqlite` was created |
| T29 | Audit | Admin → Audit log, filter by `auth.` | Sign-ins, failures and resets are listed with user, IP and time |
| T30 | Security | Submit a POST form without a valid CSRF token | Request is rejected (419) |
| T31 | Security | Request `/data/buildtrack.sqlite`, `/.env`, `/src/config.php` | 404 Not Found |
| T32 | Mobile | Open on a phone-width screen (≤ 560 px) and use ☰ | Menu slides in; tables scroll horizontally; forms stack in one column |
