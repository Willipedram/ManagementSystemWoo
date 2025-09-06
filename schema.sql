-- Database schema for user management
CREATE TABLE IF NOT EXISTS msw_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(191) UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(191),
  phone_number VARCHAR(20),
  role VARCHAR(50) DEFAULT 'user',
  status VARCHAR(20) DEFAULT 'active',
  permissions TEXT,
  created_at DATETIME,
  updated_at DATETIME
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
  FOREIGN KEY (user_id) REFERENCES msw_users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS msw_password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  reset_token VARCHAR(255),
  expires_at DATETIME,
  FOREIGN KEY (user_id) REFERENCES msw_users(id) ON DELETE CASCADE
);
