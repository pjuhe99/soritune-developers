CREATE TABLE IF NOT EXISTS tasks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  description TEXT NULL,
  status ENUM(
    'drafting','dev_pending','dev_deploying','dev_ready',
    'review_requested','changes_requested','approved',
    'prod_deploying','prod_done','failed','on_hold'
  ) NOT NULL DEFAULT 'drafting',
  current_job_id INT UNSIGNED NULL DEFAULT NULL,    -- no FK: circular ref with jobs (jobs.task_id → tasks)
  last_dev_commit VARCHAR(40) NULL DEFAULT NULL,
  last_prod_commit VARCHAR(40) NULL DEFAULT NULL,
  last_dev_deploy_at TIMESTAMP NULL DEFAULT NULL,
  admin_comment TEXT NULL,
  reviewed_at TIMESTAMP NULL DEFAULT NULL,
  approved_by INT UNSIGNED NULL DEFAULT NULL,
  approved_at TIMESTAMP NULL DEFAULT NULL,
  prod_deployed_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_project_user (project_id, user_id),
  INDEX idx_status (status),
  CONSTRAINT fk_tasks_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_tasks_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_tasks_approved_by FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
