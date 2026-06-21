CREATE TABLE whatsapp_templates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id TINYINT UNSIGNED NOT NULL,
  template_code VARCHAR(50) NOT NULL,
  provider_template_name VARCHAR(100) NOT NULL,
  language CHAR(5) NOT NULL DEFAULT 'ar',
  params_schema JSON NULL,
  is_active BOOLEAN NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_provider_code_lang (provider_id, template_code, language),
  FOREIGN KEY (provider_id) REFERENCES whatsapp_providers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
