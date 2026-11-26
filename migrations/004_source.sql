CREATE TABLE IF NOT EXISTS crm_buy_campaigns (
  id INT NOT NULL AUTO_INCREMENT,
  network_id INT NOT NULL,
  domain_id INT NOT NULL,

  campaign_id VARCHAR(64) NOT NULL,
  title VARCHAR(255) NOT NULL DEFAULT '',
  notes TEXT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  UNIQUE KEY uniq_net_campaign (network_id, campaign_id),
  KEY idx_network (network_id),
  KEY idx_domain (domain_id),
  KEY idx_domain_campaign (domain_id, campaign_id),

  CONSTRAINT fk_buy_campaigns_network
    FOREIGN KEY (network_id) REFERENCES crm_networks(id)
    ON DELETE CASCADE,

  CONSTRAINT fk_buy_campaigns_domain
    FOREIGN KEY (domain_id) REFERENCES crm_domains(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
