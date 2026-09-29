-- BarangayConnect — XAMPP / MySQL (phpMyAdmin or install_xampp.bat)
-- Default login after import: admin / admin123

CREATE DATABASE IF NOT EXISTS barangayconnect
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE barangayconnect;

CREATE TABLE IF NOT EXISTS users_resident (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  resident_id VARCHAR(32) DEFAULT NULL,
  first_name VARCHAR(100) NOT NULL,
  middle_name VARCHAR(100) DEFAULT '',
  last_name VARCHAR(100) NOT NULL,
  suffix VARCHAR(20) NULL,
  gender VARCHAR(32) NOT NULL,
  birthdate DATE NOT NULL,
  age INT NOT NULL,
  civil_status VARCHAR(64) DEFAULT '',
  place_of_birth VARCHAR(128) NULL,
  citizenship VARCHAR(64) NULL,
  nationality VARCHAR(64) NULL,
  religion VARCHAR(128) NULL,
  contact_number VARCHAR(11) NOT NULL,
  email VARCHAR(255) DEFAULT '',
  street VARCHAR(128) NULL,
  block_lot VARCHAR(32) NULL,
  house_unit VARCHAR(32) NULL,
  house_street VARCHAR(255) DEFAULT '',
  barangay VARCHAR(255) DEFAULT '',
  city VARCHAR(255) DEFAULT '',
  province VARCHAR(255) DEFAULT '',
  region VARCHAR(32) NULL,
  full_address VARCHAR(512) NULL,
  resident_type VARCHAR(64) NULL,
  owner_name VARCHAR(128) NULL,
  employer_name VARCHAR(128) NULL,
  employer_address VARCHAR(255) NULL,
  years_residency VARCHAR(16) NULL,
  employment VARCHAR(128) NULL,
  voter_status VARCHAR(16) NULL,
  precinct_number VARCHAR(32) NULL,
  blood_type VARCHAR(16) NULL,
  vehicle_owner VARCHAR(8) NULL,
  vehicle_type VARCHAR(64) NULL,
  plate_number VARCHAR(32) NULL,
  sector VARCHAR(128) NULL,
  emergency_contact_name VARCHAR(128) NULL,
  emergency_contact_number VARCHAR(20) NULL,
  emergency_contact_relationship VARCHAR(64) NULL,
  barangay_id_number VARCHAR(32) NULL,
  national_id_number VARCHAR(32) NULL,
  voter_id_number VARCHAR(32) NULL,
  identity_fingerprint CHAR(64) NULL,
  profile_extra LONGTEXT NULL,
  username VARCHAR(64) NOT NULL,
  password VARCHAR(255) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'Approved',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at DATETIME NULL DEFAULT NULL,
  UNIQUE KEY uk_users_username (username),
  UNIQUE KEY uk_users_contact (contact_number),
  UNIQUE KEY uk_users_email (email),
  UNIQUE KEY uk_users_barangay_id (barangay_id_number),
  UNIQUE KEY uk_users_national_id (national_id_number),
  UNIQUE KEY uk_users_voter_id (voter_id_number),
  UNIQUE KEY uk_users_identity_fp (identity_fingerprint),
  INDEX idx_users_status (status),
  INDEX idx_users_name (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW v_users_detail AS
SELECT
  user_id, resident_id, first_name, middle_name, last_name, suffix, gender, birthdate, age,
  civil_status, place_of_birth, citizenship, nationality, religion, contact_number, email,
  street, block_lot, house_unit, house_street, barangay, city, province, region, full_address,
  resident_type, owner_name, employer_name, employer_address, years_residency, employment,
  voter_status, precinct_number, blood_type, vehicle_owner, vehicle_type, plate_number, sector,
  emergency_contact_name, emergency_contact_number, emergency_contact_relationship,
  username, status, created_at, approved_at
FROM users_resident;

-- Legacy rename (safe if already users_resident)
-- RENAME TABLE users TO users_resident;

CREATE TABLE IF NOT EXISTS admins (
  admin_id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL DEFAULT '',
  password VARCHAR(255) NOT NULL,
  full_name VARCHAR(150) DEFAULT 'Barangay Admin',
  employee_id VARCHAR(32) NULL,
  staff_id VARCHAR(32) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_admins_email (email),
  UNIQUE KEY uk_admins_employee_id (employee_id),
  UNIQUE KEY uk_admins_staff_id (staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Demo admin for presentations: username admin / password admin123
INSERT INTO admins (username, email, password, full_name)
SELECT 'admin', 'admin@barangayconnect.local',
  '$2y$10$vXk0g36u8TZei3AOeCBldupw3bQO4aDazgzciCxVYbSV2GsLeOInG',
  'Demo Administrator'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM admins WHERE username = 'admin');

CREATE TABLE IF NOT EXISTS service_requests (
  request_pk INT AUTO_INCREMENT PRIMARY KEY,
  request_code VARCHAR(32) NOT NULL UNIQUE,
  resident_user_id INT NULL DEFAULT NULL,
  request_type VARCHAR(64) NOT NULL DEFAULT 'personal_identification',
  document_type VARCHAR(128) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  remarks TEXT NULL,
  first_name VARCHAR(80) NULL,
  middle_name VARCHAR(80) NULL,
  last_name VARCHAR(80) NULL,
  suffix VARCHAR(20) NULL,
  gender VARCHAR(24) NULL,
  birthdate DATE NULL,
  age TINYINT UNSIGNED NULL,
  place_of_birth VARCHAR(128) NULL,
  civil_status VARCHAR(32) NULL,
  citizenship VARCHAR(64) NULL,
  religion VARCHAR(128) NULL,
  email VARCHAR(128) NULL,
  mobile VARCHAR(20) NULL,
  street VARCHAR(128) NULL,
  house_unit VARCHAR(32) NULL,
  block_lot VARCHAR(32) NULL,
  house_street VARCHAR(255) NULL,
  barangay VARCHAR(64) NULL,
  city VARCHAR(128) NULL,
  province VARCHAR(64) NULL,
  region VARCHAR(32) NULL,
  full_address VARCHAR(512) NULL,
  request_notes TEXT NULL,
  payload_json LONGTEXT NOT NULL,
  submission_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sr_status (status),
  INDEX idx_sr_resident (resident_user_id),
  INDEX idx_sr_name (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW v_service_requests_detail AS
SELECT
  request_pk, request_code, resident_user_id, request_type, document_type, status, remarks,
  first_name, middle_name, last_name, suffix, gender, birthdate, age, place_of_birth,
  civil_status, citizenship, religion, email, mobile, street, house_unit, block_lot,
  house_street, barangay, city, province, region, full_address, request_notes,
  submission_date, updated_at
FROM service_requests;

CREATE TABLE IF NOT EXISTS service_request_files (
  file_id INT AUTO_INCREMENT PRIMARY KEY,
  request_pk INT NOT NULL,
  file_label VARCHAR(64) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_type VARCHAR(128) NOT NULL,
  file_path VARCHAR(512) NOT NULL,
  INDEX idx_srf_request (request_pk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventory_categories (
  category_id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  description VARCHAR(512) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_inv_cat_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_items (
  item_id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  total_qty INT NOT NULL DEFAULT 0,
  available_qty INT NOT NULL DEFAULT 0,
  unit VARCHAR(32) NOT NULL DEFAULT 'pcs',
  status VARCHAR(32) NOT NULL DEFAULT 'available',
  image_path VARCHAR(512) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_inv_item_category (category_id),
  INDEX idx_inv_item_status (status),
  CONSTRAINT fk_inv_item_category FOREIGN KEY (category_id)
    REFERENCES inventory_categories(category_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS borrow_requests (
  borrow_pk INT AUTO_INCREMENT PRIMARY KEY,
  borrow_code VARCHAR(32) NOT NULL UNIQUE,
  resident_user_id INT NOT NULL,
  auth_user_id CHAR(36) NULL,
  item_id INT NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  date_needed DATE NOT NULL,
  return_date DATE NOT NULL,
  purpose TEXT NOT NULL,
  remarks TEXT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  admin_remarks TEXT NULL,
  rejection_reason VARCHAR(255) NULL,
  return_condition VARCHAR(128) NULL,
  return_remarks TEXT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  released_at DATETIME NULL,
  returned_at DATETIME NULL,
  INDEX idx_br_status (status),
  INDEX idx_br_resident (resident_user_id),
  INDEX idx_br_auth_user (auth_user_id),
  INDEX idx_br_item (item_id),
  INDEX idx_br_return_date (return_date),
  CONSTRAINT fk_br_item FOREIGN KEY (item_id)
    REFERENCES inventory_items(item_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
