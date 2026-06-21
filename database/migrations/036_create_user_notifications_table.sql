CREATE TABLE user_notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  template_id INT UNSIGNED NULL,
  channel ENUM('email','whatsapp','push') NOT NULL,
  title_ar VARCHAR(200) NULL,
  title_en VARCHAR(200) NULL,
  body_ar TEXT NULL,
  body_en TEXT NULL,
  is_read BOOLEAN NOT NULL DEFAULT 0,
  read_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  PRIMARY KEY (id),
  KEY idx_user_read (user_id, is_read),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
