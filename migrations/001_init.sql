-- миграции
CREATE TABLE IF NOT EXISTS crm_migrations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL UNIQUE,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- домены
CREATE TABLE IF NOT EXISTS crm_domains (
  id INT AUTO_INCREMENT PRIMARY KEY,
  domain VARCHAR(190) NOT NULL UNIQUE,
  registrar VARCHAR(190) NOT NULL DEFAULT '',
  status VARCHAR(50) NOT NULL DEFAULT 'new',      -- new / live / broken / queue / parked
  ad_network VARCHAR(190) NOT NULL DEFAULT '',     -- основная сетка (быстро)
  server_name VARCHAR(190) NOT NULL DEFAULT '',    -- DS-test etc
  server_ip VARCHAR(64) NOT NULL DEFAULT '',
  path VARCHAR(255) NOT NULL DEFAULT '',
  db_name VARCHAR(190) NOT NULL DEFAULT '',
  ssl_status VARCHAR(50) NOT NULL DEFAULT 'todo',  -- todo / ok / fail
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- чек-лист: поля (динамические)
CREATE TABLE IF NOT EXISTS crm_check_fields (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(64) NOT NULL UNIQUE,     -- token, metrika, revenue, nginx, ssl, ...
  title VARCHAR(190) NOT NULL,
  field_type VARCHAR(20) NOT NULL DEFAULT 'bool', -- bool / text / number / date
  sort_order INT NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- значения чек-листа на домен
CREATE TABLE IF NOT EXISTS crm_check_values (
  domain_id INT NOT NULL,
  field_id INT NOT NULL,
  val_bool TINYINT(1) NULL,
  val_text TEXT NULL,
  val_number DOUBLE NULL,
  val_date DATE NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(domain_id, field_id),
  CONSTRAINT fk_ccv_domain FOREIGN KEY (domain_id) REFERENCES crm_domains(id) ON DELETE CASCADE,
  CONSTRAINT fk_ccv_field  FOREIGN KEY (field_id)  REFERENCES crm_check_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- сетки
CREATE TABLE IF NOT EXISTS crm_networks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(64) NOT NULL UNIQUE,     -- kadam/clickadu/...
  title VARCHAR(190) NOT NULL,
  api_base_url VARCHAR(255) NOT NULL DEFAULT '',
  api_key TEXT NULL,
  api_extra JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- привязка сетки к домену (id сайта/токен/коммент)
CREATE TABLE IF NOT EXISTS crm_domain_networks (
  domain_id INT NOT NULL,
  network_id INT NOT NULL,
  external_site_id VARCHAR(190) NOT NULL DEFAULT '',
  token TEXT NULL,
  notes TEXT NULL,
  PRIMARY KEY(domain_id, network_id),
  CONSTRAINT fk_cdn_domain FOREIGN KEY (domain_id) REFERENCES crm_domains(id) ON DELETE CASCADE,
  CONSTRAINT fk_cdn_network FOREIGN KEY (network_id) REFERENCES crm_networks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- дефолтные чек-лист поля
INSERT IGNORE INTO crm_check_fields(code,title,field_type,sort_order) VALUES
('path_ok','Путь на сервере создан','bool',10),
('db_ok','База создана/импортирована','bool',20),
('nginx_ok','Nginx конфиг','bool',30),
('ssl_ok','SSL выдан','bool',40),
('metrika','Я.Метрика','text',60),
('token','Токен','text',70),
('revenue','Доход','number',80);
