CREATE TABLE user_notification_preferences (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  channel ENUM('email','whatsapp','push') NOT NULL,
  event_type VARCHAR(50) NOT NULL,
  is_enabled BOOLEAN NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_channel_event (user_id, channel, event_type),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
