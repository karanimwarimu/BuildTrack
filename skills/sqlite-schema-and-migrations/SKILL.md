---
name: sqlite-schema-and-migrations
description: Safely add or change BuildTrack's SQLite tables, columns, indexes, triggers, seed data or the MariaDB importer without breaking existing databases. Use whenever a change needs a schema change or new stored data.
---

# SQLite schema & migrations

## When to use
- A feature needs a new table, column, index, constraint or trigger.
- You need to rename or drop a column, change a CHECK list (e.g. a new task status), or backfill data.
- You are changing demo seed data (`seeds/demo.php`) or the MariaDB importer (`src/mysql_import.php`).

## How the migrator works (read first)
- Files: `migrations/NNN_snake_case.sql`, applied in numeric order **once each** by `migrate()` in `src/db.php`.
- Each file runs inside **one** `BEGIN IMMEDIATE` transaction with `PRAGMA foreign_keys=OFF`. `PRAGMA foreign_key_check` runs before commit, and any violation rolls the whole file back.
- After success the migrator writes `schema_migrations(version, name)` and sets `PRAGMA user_version = NNN`. The web app compares `user_version` with the newest file on each request and migrates automatically. So does `./start.sh` (via `php bin/console migrate`).
- **Never** put `BEGIN`/`COMMIT` in a migration file. A test enforces this.
- **Never edit a migration that has shipped.** Databases that already applied it will not re-run it. Add a new file instead.

## Steps

1. **Pick the next number:** `ls migrations/`. Create e.g. `migrations/004_inspections.sql`.
2. **Write SQLite-native DDL** following the house conventions:
   - PK: `id INTEGER PRIMARY KEY AUTOINCREMENT`
   - Enums: `TEXT NOT NULL DEFAULT 'x' CHECK (col IN ('x','y'))`
   - Money/quantities: `NUMERIC` + `CHECK (col >= 0)`; round to 2 dp in PHP before writing.
   - Dates: `TEXT` `'YYYY-MM-DD'`. Timestamps: `TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP` (UTC).
   - Booleans: `INTEGER NOT NULL DEFAULT 0 CHECK (col IN (0,1))`.
   - FKs inline: `REFERENCES projects(id) ON DELETE CASCADE` for children, `ON DELETE RESTRICT` for authorship (`created_by`), `ON DELETE SET NULL` for optional links.
   - Index every FK column and every column you filter or sort on: `CREATE INDEX idx_<table>_<cols> ON <table>(<cols>);`
   - `updated_at` needs a trigger (SQLite has no `ON UPDATE`):
     ```sql
     CREATE TRIGGER trg_inspections_updated_at AFTER UPDATE ON inspections
     WHEN NEW.updated_at = OLD.updated_at
     BEGIN UPDATE inspections SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id; END;
     ```
3. **Changes SQLite cannot do with `ALTER TABLE`** (change a CHECK list, add a FK or NOT NULL without a default, change a column type) need the 12-step table rebuild, inside the same file:
   ```sql
   CREATE TABLE tasks_new ( ...full new definition... );
   INSERT INTO tasks_new (id, col1, col2, ...) SELECT id, col1, col2, ... FROM tasks;
   DROP TABLE tasks;
   ALTER TABLE tasks_new RENAME TO tasks;
   -- re-create EVERY index and trigger that existed on tasks (DROP TABLE removed them)
   CREATE INDEX idx_tasks_project ON tasks(project_id);
   ...
   ```
   Foreign keys are already OFF during migrations, so dependent tables keep pointing at `tasks` by name. `foreign_key_check` verifies the result.
   - Simple additions are fine with `ALTER TABLE x ADD COLUMN y ... DEFAULT ...`. Renames use `ALTER TABLE x RENAME COLUMN a TO b` (SQLite ≥ 3.25).
4. **Update PHP constants** if you changed an enum, e.g. `TASK_STATUSES` in `src/helpers.php`.
5. **Update dependents:**
   - `seeds/demo.php` if demo data should use the new structure.
   - `system_health()` in `src/backup.php` (table counts list) for new tables.
   - `admin.php` `$exports` if the table should be exportable.
   - `import_mysql_dump()` wipe list if the new table holds data that must be cleared on import.
6. **Write SQL that SQLite understands**:
   - `app_today()` (not `CURDATE()`)
   - `date(app_today(), '+7 days')` (not `DATE_ADD`)
   - `julianday(a) - julianday(b)` (not `DATEDIFF`)
   - `ON CONFLICT (...) DO UPDATE SET c = excluded.c` (not `ON DUPLICATE KEY`)
   - `||` for concatenation; `LIKE` is case-insensitive for ASCII only.
7. **Add a test** in `tests/schema_test.php` (or a new `tests/<area>_test.php`). Use `fresh_db()` and assert the table exists, constraints reject bad data, and cascades behave.

## Verification
```bash
php tests/run.php schema          # schema tests only
make test                         # full suite (includes HTTP end-to-end)
cp data/buildtrack.sqlite /tmp/copy.sqlite && BUILDTRACK_DB_PATH=/tmp/copy.sqlite php bin/console migrate   # dry-run on a copy of real data
php bin/console doctor            # "Schema version N of N", integrity ok, foreign keys ON
sqlite3 data/buildtrack.sqlite '.schema inspections'
```
Always back up before migrating a real database: `make backup`.

## Gotchas
- `PRAGMA foreign_keys` is per connection and cannot change inside a transaction. `db_connect()` turns it ON; the migrator manages it during migrations.
- `CURRENT_TIMESTAMP` is UTC. Display with `datetime_label()`, never raw.
- Do not store derived totals (e.g. project spent). Compute them with queries (see `src/construction.php`) so they cannot drift.
- `AUTOINCREMENT` keeps IDs from being reused after deletes. Keep it for consistency with existing tables.
- WAL leaves `-wal`/`-shm` files next to the database. They are part of the database; never delete them while the app runs.
