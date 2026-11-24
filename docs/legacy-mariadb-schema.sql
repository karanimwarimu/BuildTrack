CREATE DATABASE IF NOT EXISTS construction_project_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE construction_project_system;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','project_manager','site_supervisor','worker') NOT NULL DEFAULT 'worker',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS projects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  description TEXT NULL,
  location VARCHAR(180) NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  budget DECIMAL(14,2) NULL,
  status ENUM('planned','active','on_hold','completed') NOT NULL DEFAULT 'planned',
  manager_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_projects_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_projects_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_projects_status (status),
  INDEX idx_projects_dates (start_date,end_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  description TEXT NULL,
  start_date DATE NULL,
  due_date DATE NULL,
  assigned_to INT UNSIGNED NULL,
  status ENUM('pending','in_progress','blocked','completed') NOT NULL DEFAULT 'pending',
  priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tasks_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_tasks_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_tasks_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_tasks_due_date (due_date),
  INDEX idx_tasks_status (status),
  INDEX idx_tasks_assignee (assigned_to)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS progress_updates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  progress_percentage TINYINT UNSIGNED NOT NULL,
  status ENUM('pending','in_progress','blocked','completed') NOT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_progress_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_progress_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_progress_task_date (task_id,created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS resources (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  resource_type ENUM('labour','equipment','material','other') NOT NULL DEFAULT 'other',
  unit VARCHAR(40) NOT NULL DEFAULT 'unit',
  quantity_available DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_resources_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS task_resources (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id INT UNSIGNED NOT NULL,
  resource_id INT UNSIGNED NOT NULL,
  quantity_assigned DECIMAL(12,2) NOT NULL,
  notes VARCHAR(255) NULL,
  assigned_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_task_resources_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_task_resources_resource FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE,
  CONSTRAINT fk_task_resources_user FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_task_resource (task_id, resource_id)
) ENGINE=InnoDB;
