CREATE TABLE settings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  key_name VARCHAR(100) NOT NULL,
  value TEXT NULL,
  type ENUM('string','integer','boolean','json','encrypted') NOT NULL DEFAULT 'string',
  description VARCHAR(300) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT NOW() ON UPDATE NOW(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_key (key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
