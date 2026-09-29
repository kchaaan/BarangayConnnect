-- Borrow & Inventory tables for BarangayConnect
USE barangayconnect;

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
  applicant_name VARCHAR(255) NULL,
  applicant_resident_id VARCHAR(64) NULL,
  applicant_contact VARCHAR(64) NULL,
  INDEX idx_br_status (status),
  INDEX idx_br_resident (resident_user_id),
  INDEX idx_br_item (item_id),
  INDEX idx_br_return_date (return_date),
  CONSTRAINT fk_br_item FOREIGN KEY (item_id)
    REFERENCES inventory_items(item_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
