-- Digitz PMS — MySQL 8 / MariaDB 10.6+ schema (utf8mb4)
-- Money is always stored as integer fils (1 AED = 100 fils). Timestamps are UTC.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) PRIMARY KEY,
  v MEDIUMTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS plans (
  id CHAR(36) PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  base_monthly_fils BIGINT NOT NULL DEFAULT 0,
  base_yearly_fils BIGINT NOT NULL DEFAULT 0,
  sync_monthly_fils BIGINT NOT NULL DEFAULT 0,
  sync_yearly_fils BIGINT NOT NULL DEFAULT 0,
  modules JSON NOT NULL,                       -- e.g. ["health","family"]
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every login identity. role: super_admin | client_admin | vendor | user
CREATE TABLE IF NOT EXISTS users (
  id CHAR(36) PRIMARY KEY,
  role ENUM('super_admin','client_admin','vendor','user') NOT NULL,
  username VARCHAR(64) NULL UNIQUE,            -- staff login
  name VARCHAR(150) NOT NULL,
  mobile VARCHAR(20) NULL UNIQUE,              -- E.164, end users
  email VARCHAR(190) NULL,
  dob DATE NULL,
  secret_hash VARCHAR(255) NULL,               -- PIN (users) or password (staff), password_hash()
  must_change_secret TINYINT(1) NOT NULL DEFAULT 0,
  client_admin_id CHAR(36) NULL,               -- tenant scope (users, vendors)
  vendor_id CHAR(36) NULL,                     -- users linked to a vendor/broker
  plan_id CHAR(36) NULL,
  status ENUM('active','pending','disabled') NOT NULL DEFAULT 'active',
  can_share_vacancies TINYINT(1) NOT NULL DEFAULT 0,
  recovery_secret CHAR(64) NULL,               -- releases the local vault key after OTP-verified PIN reset
  failed_logins INT NOT NULL DEFAULT 0,
  lockouts INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at DATETIME(3) NULL,
  INDEX (client_admin_id), INDEX (vendor_id), INDEX (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Agency (tenant) record for each client_admin user
CREATE TABLE IF NOT EXISTS client_admins (
  id CHAR(36) PRIMARY KEY,                     -- = users.id of the client admin
  company VARCHAR(150) NOT NULL,
  status ENUM('Active','Trial','Expired') NOT NULL DEFAULT 'Trial',
  expires_at DATE NULL,
  max_users INT NOT NULL DEFAULT 10,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  plan_id CHAR(36) NULL,
  sharing_enabled TINYINT(1) NOT NULL DEFAULT 0,      -- may share vacancies in the network
  users_see_shared TINYINT(1) NOT NULL DEFAULT 1,     -- its users may browse shared listings
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per Client Admin module override (absent row = plan default)
CREATE TABLE IF NOT EXISTS module_flags (
  client_admin_id CHAR(36) NOT NULL,
  module VARCHAR(32) NOT NULL,
  enabled TINYINT(1) NOT NULL,
  PRIMARY KEY (client_admin_id, module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vendors (
  id CHAR(36) PRIMARY KEY,                     -- = users.id of the vendor
  client_admin_id CHAR(36) NOT NULL,
  commission_pct DECIMAL(5,2) NOT NULL DEFAULT 10.00,
  INDEX (client_admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vendor_plan_rates (
  vendor_id CHAR(36) NOT NULL,
  plan_id CHAR(36) NOT NULL,
  commission_pct DECIMAL(5,2) NOT NULL,
  PRIMARY KEY (vendor_id, plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS otps (
  id CHAR(36) PRIMARY KEY,
  mobile VARCHAR(20) NOT NULL,
  purpose ENUM('signup','reset') NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  payload JSON NULL,
  attempts INT NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  last_sent_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  INDEX (mobile, purpose)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rate_limits (
  rk VARCHAR(190) PRIMARY KEY,
  window_start INT NOT NULL,
  hits INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS refresh_tokens (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Structured sync data (JSON text only — never binaries)
CREATE TABLE IF NOT EXISTS sync_records (
  entity VARCHAR(32) NOT NULL,
  id CHAR(36) NOT NULL,
  owner_user_id CHAR(36) NOT NULL,
  updated_at DATETIME(3) NOT NULL,
  deleted_at DATETIME(3) NULL,
  payload JSON NOT NULL,
  rev BIGINT NOT NULL,
  PRIMARY KEY (entity, id),
  INDEX (owner_user_id, rev)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sync_counter (
  id TINYINT PRIMARY KEY,
  v BIGINT NOT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO sync_counter (id, v) VALUES (1, 0);

CREATE TABLE IF NOT EXISTS sync_conflicts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  owner_user_id CHAR(36) NOT NULL,
  entity VARCHAR(32) NOT NULL,
  record_id CHAR(36) NOT NULL,
  winner ENUM('server','client') NOT NULL,
  server_version JSON NOT NULL,
  client_version JSON NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX (owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscriptions (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  plan_id CHAR(36) NOT NULL,
  cycle ENUM('monthly','yearly') NOT NULL,
  includes_sync TINYINT(1) NOT NULL DEFAULT 0,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  status ENUM('pending','active','expired','cancelled') NOT NULL DEFAULT 'pending',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invoices (
  id CHAR(36) PRIMARY KEY,
  number VARCHAR(32) NOT NULL UNIQUE,
  seq INT NOT NULL,
  subscription_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  items JSON NOT NULL,
  total_fils BIGINT NOT NULL,
  payment_status ENUM('Pending','Paid','Failed','Refunded') NOT NULL DEFAULT 'Pending',
  paid_at DATETIME NULL,
  method VARCHAR(40) NULL,
  reference VARCHAR(120) NULL,
  receipt_number VARCHAR(32) NULL UNIQUE,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sequences (
  name VARCHAR(32) PRIMARY KEY,
  v INT NOT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO sequences (name, v) VALUES ('invoice', 0), ('receipt', 0);

CREATE TABLE IF NOT EXISTS commissions (
  id CHAR(36) PRIMARY KEY,
  vendor_id CHAR(36) NOT NULL,
  invoice_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  base_fils BIGINT NOT NULL,
  pct DECIMAL(5,2) NOT NULL,
  amount_fils BIGINT NOT NULL,                 -- negative for refund reversals
  paid_fils BIGINT NOT NULL DEFAULT 0,
  status ENUM('Unpaid','Partial','Paid','Reversed') NOT NULL DEFAULT 'Unpaid',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX (vendor_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS commission_payouts (
  id CHAR(36) PRIMARY KEY,
  vendor_id CHAR(36) NOT NULL,
  amount_fils BIGINT NOT NULL,
  paid_on DATE NOT NULL,
  method VARCHAR(40) NOT NULL,
  reference VARCHAR(120) NULL,
  created_by CHAR(36) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX (vendor_id, paid_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vacancy_shares (
  id CHAR(36) PRIMARY KEY,
  client_admin_id CHAR(36) NOT NULL,           -- listing agency
  owner_user_id CHAR(36) NOT NULL,             -- broker who shared it
  unit_entity VARCHAR(32) NOT NULL,
  unit_id CHAR(36) NOT NULL,
  meta JSON NOT NULL,                          -- text metadata only
  commission_split_pct DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  status ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
  expires_at DATE NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_unit (unit_entity, unit_id),
  INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS push_subscriptions (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  endpoint VARCHAR(600) NOT NULL,
  endpoint_hash CHAR(64) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS push_messages (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  title VARCHAR(190) NOT NULL,
  body VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reminder_log (
  rkey VARCHAR(190) PRIMARY KEY,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  actor_id CHAR(36) NULL,
  actor_role VARCHAR(20) NULL,
  client_admin_id CHAR(36) NULL,
  action VARCHAR(80) NOT NULL,
  details JSON NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX (client_admin_id, created_at), INDEX (actor_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
