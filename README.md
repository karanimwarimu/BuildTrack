# BuildTrack — Construction Project Management and Scheduling System

A student-project MVP built with PHP 8+, MySQL/MariaDB, HTML and CSS. It is designed to run locally in XAMPP and can be adapted for web hosting that supports PHP and MySQL.

## Included features

- Login/logout with password hashing and session regeneration.
- Role-based access: Administrator, Project Manager, Site Supervisor and Worker.
- Project register with dates, manager, status, location and optional budget.
- Task scheduling, priorities, assignees, status and due dates.
- Progress updates with a dated history for each task.
- In-app deadline alerts for unfinished tasks due within seven days or overdue.
- Resource register and task-level resource allocation with a basic quantity check.
- Task performance dashboard and CSV report export.
- Admin user creation, role changes and password resets.
- Prepared SQL statements, output escaping, CSRF tokens, password hashing and basic input validation.

## Requirements

- XAMPP with PHP 8.1+ and MySQL/MariaDB enabled.
- PHP extensions `pdo_mysql` and `mbstring` enabled (usually enabled in XAMPP).
- A modern web browser.

## Install using XAMPP on Windows

1. Extract the `construction-project-system` folder into `C:\xampp\htdocs\`.
2. Open the XAMPP Control Panel and start **Apache** and **MySQL**.
3. Open `http://localhost/phpmyadmin/`.
4. Choose **Import**, select `database.sql` from the project folder, then run the import. The script creates the `construction_project_system` database and its tables.
5. If your local MySQL uses a username/password other than `root` with a blank password, update `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` in `inc/boot.php`.
6. Open `http://localhost/construction-project-system/setup.php`.
7. Create the first administrator account with a strong password (at least 10 characters).
8. Sign in at `http://localhost/construction-project-system/index.php?page=login`.
9. From **User accounts**, create accounts for project managers, site supervisors and workers.
10. Create a project, add tasks, assign users, record progress and allocate resources.

## Run with PHP's built-in server (optional)

Import `database.sql` into a local MySQL/MariaDB server and confirm the credentials in `inc/boot.php`. In a terminal, enter the project folder and run:

```bash
php -S 127.0.0.1:8000
```

Then open `http://127.0.0.1:8000/setup.php`. The built-in server is for local development only, not public production hosting.

## Role summary

- **Administrator:** full access, including user accounts and deletion of projects/tasks/resources.
- **Project Manager:** manage projects and tasks, assign resources, view reports and manage the schedule.
- **Site Supervisor:** view projects, manage tasks, record progress, and allocate resources.
- **Worker:** view tasks assigned to them, post progress updates, view relevant alerts and reports.

## Notes and current limitations

- Deadline alerts appear inside the application. Email, SMS and push notifications are not configured.
- Resource allocation uses a simple total-quantity check. It does not calculate availability by date/time or account for resource double-booking across overlapping schedules.
- The report can be downloaded as CSV. PDF export, Gantt charts, file attachments, audit exports and automated backups are not implemented.
- A user with a visible task can submit progress for that task. Before deployment, refine project-level access rules to match the organisation's real structure.
- Change default credentials and configure HTTPS, database least-privilege access, backups, error logging and rate limiting before placing any real project or personal data online.
- This is a working MVP/prototype, not a certified construction or financial control product. Validate it with representative test data and users before relying on it operationally.

## Validation

Run PHP syntax checks from the project root:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

After installation, test at minimum: initial admin creation, sign-in/out, role restrictions, project creation, date validation, task assignment, progress history, deadline-alert conditions, resource allocation limits and CSV export.
