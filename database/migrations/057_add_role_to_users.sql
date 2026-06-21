ALTER TABLE users
  ADD COLUMN role ENUM('user','admin','super_admin') NOT NULL DEFAULT 'user' AFTER last_name;
