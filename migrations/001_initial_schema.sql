-- 001: SQLite port of the original MariaDB schema (docs/legacy-mariadb-schema.sql).
--  * INT UNSIGNED AUTO_INCREMENT -> INTEGER PRIMARY KEY AUTOINCREMENT
--  * ENUM(...)                   -> TEXT + CHECK (col IN (...))
--  * DECIMAL                     -> NUMERIC (+ CHECK >= 0)
--  * ON UPDATE CURRENT_TIMESTAMP -> AFTER UPDATE triggers (see bottom)
--  * Timestamps are stored as UTC 'YYYY-MM-DD HH:MM:SS' text; dates as 'YYYY-MM-DD'.

CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  full_name TEXT NOT NULL CHECK (length(full_name) BETWEEN 1 AND 120),
  email TEXT NOT NULL UNIQUE COLLATE NOCASE CHECK (length(email) <= 190),
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'worker' CHECK (role IN ('admin','project_manager','site_supervisor','worker')),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE projects (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 180),
  description TEXT,
  location TEXT,
  start_date TEXT,
  end_date TEXT,
  budget NUMERIC CHECK (budget IS NULL OR budget >= 0),
  status TEXT NOT NULL DEFAULT 'planned' CHECK (status IN ('planned','active','on_hold','completed')),
  manager_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CHECK (start_date IS NULL OR end_date IS NULL OR start_date <= end_date)
);
CREATE INDEX idx_projects_status ON projects(status);
CREATE INDEX idx_projects_dates ON projects(start_date, end_date);
CREATE INDEX idx_projects_manager ON projects(manager_id);

CREATE TABLE tasks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  title TEXT NOT NULL CHECK (length(title) BETWEEN 1 AND 180),
  description TEXT,
  start_date TEXT,
  due_date TEXT,
  assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL,
  status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','in_progress','blocked','completed')),
  priority TEXT NOT NULL DEFAULT 'medium' CHECK (priority IN ('low','medium','high','urgent')),
  progress_percent INTEGER NOT NULL DEFAULT 0 CHECK (progress_percent BETWEEN 0 AND 100),
  created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_tasks_project ON tasks(project_id);
CREATE INDEX idx_tasks_due_date ON tasks(due_date);
CREATE INDEX idx_tasks_status ON tasks(status);
CREATE INDEX idx_tasks_assignee ON tasks(assigned_to);

CREATE TABLE progress_updates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  task_id INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  progress_percentage INTEGER NOT NULL CHECK (progress_percentage BETWEEN 0 AND 100),
  status TEXT NOT NULL CHECK (status IN ('pending','in_progress','blocked','completed')),
  notes TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_progress_task_date ON progress_updates(task_id, created_at);
CREATE INDEX idx_progress_user ON progress_updates(user_id);

CREATE TABLE resources (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 160),
  resource_type TEXT NOT NULL DEFAULT 'other' CHECK (resource_type IN ('labour','equipment','material','other')),
  unit TEXT NOT NULL DEFAULT 'unit',
  quantity_available NUMERIC NOT NULL DEFAULT 0 CHECK (quantity_available >= 0),
  notes TEXT,
  created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_resources_creator ON resources(created_by);

CREATE TABLE task_resources (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  task_id INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
  resource_id INTEGER NOT NULL REFERENCES resources(id) ON DELETE CASCADE,
  quantity_assigned NUMERIC NOT NULL CHECK (quantity_assigned > 0),
  notes TEXT,
  assigned_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (task_id, resource_id)
);
CREATE INDEX idx_task_resources_resource ON task_resources(resource_id);

-- Emulate MySQL "ON UPDATE CURRENT_TIMESTAMP". The WHEN clause skips rows whose
-- updated_at was set explicitly, and prevents the trigger re-firing on its own UPDATE.
CREATE TRIGGER trg_projects_updated_at AFTER UPDATE ON projects
WHEN NEW.updated_at = OLD.updated_at
BEGIN UPDATE projects SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id; END;

CREATE TRIGGER trg_tasks_updated_at AFTER UPDATE ON tasks
WHEN NEW.updated_at = OLD.updated_at
BEGIN UPDATE tasks SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id; END;

CREATE TRIGGER trg_resources_updated_at AFTER UPDATE ON resources
WHEN NEW.updated_at = OLD.updated_at
BEGIN UPDATE resources SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id; END;
