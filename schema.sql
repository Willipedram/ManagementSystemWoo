-- Database schema for user management
CREATE TABLE IF NOT EXISTS msw_roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(191) UNIQUE,
  permissions TEXT
);

INSERT INTO msw_roles (id,name,permissions) VALUES (1,'مدیر کل','all')
  ON DUPLICATE KEY UPDATE name='مدیر کل', permissions='all';

CREATE TABLE IF NOT EXISTS msw_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(191) UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(191),
  phone_number VARCHAR(20),
  role_id INT,
  status VARCHAR(20) DEFAULT 'active',
  created_at DATETIME,
  updated_at DATETIME,
  FOREIGN KEY (role_id) REFERENCES msw_roles(id)
);

CREATE TABLE IF NOT EXISTS msw_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  token VARCHAR(255),
  ip_address VARCHAR(45),
  device_info VARCHAR(191),
  expires_at DATETIME,
  FOREIGN KEY (user_id) REFERENCES msw_users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS msw_clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_name VARCHAR(191),
  api_key VARCHAR(191),
  client_secret VARCHAR(191),
  redirect_uri TEXT,
  status VARCHAR(20)
);

CREATE TABLE IF NOT EXISTS msw_user_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  action VARCHAR(50),
  timestamp DATETIME,
  ip_address VARCHAR(45),
  country VARCHAR(100),
  city VARCHAR(100),
  isp VARCHAR(191),
  FOREIGN KEY (user_id) REFERENCES msw_users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS msw_product_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  product_id BIGINT,
  assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY product_unique (product_id),
  KEY user_idx (user_id),
  FOREIGN KEY (user_id) REFERENCES msw_users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS msw_assignment_modes (
  user_id INT PRIMARY KEY,
  mode VARCHAR(20),
  quota_min INT,
  quota_max INT,
  category_id BIGINT,
  FOREIGN KEY (user_id) REFERENCES msw_users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS msw_password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  reset_token VARCHAR(255),
  expires_at DATETIME,
  FOREIGN KEY (user_id) REFERENCES msw_users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS msw_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(191) UNIQUE,
  value TEXT
);

CREATE TABLE IF NOT EXISTS msw_product_content_history (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT,
  old_content LONGTEXT,
  new_content LONGTEXT,
  changed_by INT,
  changed_at DATETIME,
  version INT,
  FOREIGN KEY (changed_by) REFERENCES msw_users(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS msw_product_seo_scores (
  product_id BIGINT PRIMARY KEY,
  score INT,
  details LONGTEXT,
  analyzed_at DATETIME
);
