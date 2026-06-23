ALTER TABLE travelers
  ADD COLUMN title ENUM('mr','ms','mrs','miss','dr') NULL AFTER user_id;
