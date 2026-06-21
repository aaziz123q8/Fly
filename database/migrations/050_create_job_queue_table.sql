CREATE TABLE job_queue (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_type VARCHAR(50) NOT NULL,
  payload JSON NOT NULL,
  status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  attempts TINYINT NOT NULL DEFAULT 0,
  max_attempts TINYINT NOT NULL DEFAULT 3,
  error_message TEXT NULL,
  run_at DATETIME NULL,
  started_at TIMESTAMP NULL,
  completed_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  PRIMARY KEY (id),
  KEY idx_status_run (status, run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
