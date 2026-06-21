CREATE TABLE notification_dispatch_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  notification_id INT UNSIGNED NOT NULL,
  channel ENUM('email','whatsapp','push') NOT NULL,
  recipient VARCHAR(200) NOT NULL,
  status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  provider_message_id VARCHAR(100) NULL,
  error_message TEXT NULL,
  sent_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  PRIMARY KEY (id),
  KEY idx_notification (notification_id),
  KEY idx_status (status),
  FOREIGN KEY (notification_id) REFERENCES user_notifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
