-- 003: expenses (budget vs actual), daily site logs, low-stock thresholds, file attachments.

ALTER TABLE resources ADD COLUMN low_stock_threshold NUMERIC CHECK (low_stock_threshold IS NULL OR low_stock_threshold >= 0);

CREATE TABLE expenses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  category TEXT NOT NULL DEFAULT 'other'
    CHECK (category IN ('materials','labour','equipment','subcontractor','permits','transport','other')),
  description TEXT NOT NULL CHECK (length(description) BETWEEN 1 AND 255),
  vendor TEXT,
  amount NUMERIC NOT NULL CHECK (amount >= 0),
  spent_on TEXT NOT NULL,                       -- YYYY-MM-DD
  recorded_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_expenses_project_date ON expenses(project_id, spent_on);
CREATE INDEX idx_expenses_category ON expenses(category);
CREATE INDEX idx_expenses_recorded_by ON expenses(recorded_by);

CREATE TABLE site_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  log_date TEXT NOT NULL,                       -- YYYY-MM-DD
  weather TEXT,
  crew_count INTEGER NOT NULL DEFAULT 0 CHECK (crew_count >= 0),
  work_done TEXT NOT NULL CHECK (length(work_done) >= 1),
  issues TEXT,
  safety_notes TEXT,
  created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_site_logs_project_date ON site_logs(project_id, log_date);
CREATE INDEX idx_site_logs_created_by ON site_logs(created_by);

-- Files live in data/uploads/<stored_name>; rows cascade with their project/task and the
-- application deletes the files (see attachments_delete_for() in src/attachments.php).
CREATE TABLE attachments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  task_id INTEGER REFERENCES tasks(id) ON DELETE CASCADE,
  original_name TEXT NOT NULL,
  stored_name TEXT NOT NULL UNIQUE,
  mime_type TEXT NOT NULL,
  size_bytes INTEGER NOT NULL CHECK (size_bytes >= 0),
  uploaded_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_attachments_project ON attachments(project_id);
CREATE INDEX idx_attachments_task ON attachments(task_id);
CREATE INDEX idx_attachments_uploaded_by ON attachments(uploaded_by);

CREATE TRIGGER trg_expenses_updated_at AFTER UPDATE ON expenses
WHEN NEW.updated_at = OLD.updated_at
BEGIN UPDATE expenses SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id; END;

CREATE TRIGGER trg_site_logs_updated_at AFTER UPDATE ON site_logs
WHEN NEW.updated_at = OLD.updated_at
BEGIN UPDATE site_logs SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id; END;
