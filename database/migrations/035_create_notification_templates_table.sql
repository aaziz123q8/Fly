CREATE TABLE notification_templates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(50) NOT NULL,
  channel ENUM('email','whatsapp','push') NOT NULL,
  subject_en VARCHAR(200) NULL,
  subject_ar VARCHAR(200) NULL,
  body_en TEXT NULL,
  body_ar TEXT NULL,
  is_active BOOLEAN NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW() ON UPDATE NOW(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_code_channel (code, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
