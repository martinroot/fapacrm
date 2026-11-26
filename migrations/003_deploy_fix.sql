-- 003_deploy_fix.sql
-- Добавляем то, чего может не хватать, не трогая deploy_status (он уже есть)

ALTER TABLE crm_domains
  ADD COLUMN deploy_last_log MEDIUMTEXT NULL AFTER deploy_status;

ALTER TABLE crm_domains
  ADD COLUMN deployed_at DATETIME NULL AFTER deploy_last_log;

CREATE TABLE IF NOT EXISTS crm_deploy_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  domain_id INT NOT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'running',
  summary TEXT NULL,
  CONSTRAINT fk_deploy_domain FOREIGN KEY (domain_id) REFERENCES crm_domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_deploy_steps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  run_id INT NOT NULL,
  step_key VARCHAR(64) NOT NULL,
  title VARCHAR(190) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  rc INT NULL,
  output MEDIUMTEXT NULL,
  CONSTRAINT fk_step_run FOREIGN KEY (run_id) REFERENCES crm_deploy_runs(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_run_step (run_id, step_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
