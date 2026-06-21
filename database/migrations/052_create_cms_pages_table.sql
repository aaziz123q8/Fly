CREATE TABLE cms_pages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(100) NOT NULL,
  title_en VARCHAR(200) NOT NULL,
  title_ar VARCHAR(200) NOT NULL,
  content_en LONGTEXT NULL,
  content_ar LONGTEXT NULL,
  meta_title_en VARCHAR(200) NULL,
  meta_desc_en VARCHAR(300) NULL,
  meta_title_ar VARCHAR(200) NULL,
  meta_desc_ar VARCHAR(300) NULL,
  is_published BOOLEAN NOT NULL DEFAULT 0,
  published_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW() ON UPDATE NOW(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
