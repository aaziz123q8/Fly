CREATE TABLE hotel_images (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hotel_id INT UNSIGNED NOT NULL,
  url VARCHAR(500) NOT NULL,
  caption_en VARCHAR(300) NULL,
  caption_ar VARCHAR(300) NULL,
  category VARCHAR(50) NULL,
  is_main BOOLEAN NOT NULL DEFAULT 0,
  display_order TINYINT NOT NULL DEFAULT 0,
  width_px SMALLINT NULL,
  height_px SMALLINT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  PRIMARY KEY (id),
  KEY idx_hotel_main (hotel_id, is_main),
  KEY idx_hotel_order (hotel_id, display_order),
  KEY idx_hotel_category (hotel_id, category),
  FOREIGN KEY (hotel_id) REFERENCES hotels_content(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
