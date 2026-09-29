-- Run in phpMyAdmin (barangayconnect database) to expand service_requests for readable columns.
-- Or open: http://localhost/.../FrontPage/php/migrate_requests_schema.php

USE barangayconnect;

ALTER TABLE service_requests
  ADD COLUMN IF NOT EXISTS first_name VARCHAR(80) NULL AFTER remarks,
  ADD COLUMN IF NOT EXISTS middle_name VARCHAR(80) NULL AFTER first_name,
  ADD COLUMN IF NOT EXISTS last_name VARCHAR(80) NULL AFTER middle_name,
  ADD COLUMN IF NOT EXISTS suffix VARCHAR(20) NULL AFTER last_name,
  ADD COLUMN IF NOT EXISTS gender VARCHAR(24) NULL AFTER suffix,
  ADD COLUMN IF NOT EXISTS birthdate DATE NULL AFTER gender,
  ADD COLUMN IF NOT EXISTS age TINYINT UNSIGNED NULL AFTER birthdate,
  ADD COLUMN IF NOT EXISTS place_of_birth VARCHAR(128) NULL AFTER age,
  ADD COLUMN IF NOT EXISTS civil_status VARCHAR(32) NULL AFTER place_of_birth,
  ADD COLUMN IF NOT EXISTS citizenship VARCHAR(64) NULL AFTER civil_status,
  ADD COLUMN IF NOT EXISTS religion VARCHAR(128) NULL AFTER citizenship,
  ADD COLUMN IF NOT EXISTS email VARCHAR(128) NULL AFTER religion,
  ADD COLUMN IF NOT EXISTS mobile VARCHAR(20) NULL AFTER email,
  ADD COLUMN IF NOT EXISTS street VARCHAR(128) NULL AFTER mobile,
  ADD COLUMN IF NOT EXISTS house_unit VARCHAR(32) NULL AFTER street,
  ADD COLUMN IF NOT EXISTS block_lot VARCHAR(32) NULL AFTER house_unit,
  ADD COLUMN IF NOT EXISTS house_street VARCHAR(255) NULL AFTER block_lot,
  ADD COLUMN IF NOT EXISTS barangay VARCHAR(64) NULL AFTER house_street,
  ADD COLUMN IF NOT EXISTS city VARCHAR(128) NULL AFTER barangay,
  ADD COLUMN IF NOT EXISTS province VARCHAR(64) NULL AFTER city,
  ADD COLUMN IF NOT EXISTS region VARCHAR(32) NULL AFTER province,
  ADD COLUMN IF NOT EXISTS full_address VARCHAR(512) NULL AFTER region,
  ADD COLUMN IF NOT EXISTS request_notes TEXT NULL AFTER full_address;

-- MariaDB 10.5+ supports IF NOT EXISTS on ADD COLUMN; on older XAMPP run migrate_requests_schema.php instead.

CREATE OR REPLACE VIEW v_service_requests_detail AS
SELECT
  request_pk,
  request_code,
  resident_user_id,
  request_type,
  document_type,
  status,
  remarks,
  first_name,
  middle_name,
  last_name,
  suffix,
  gender,
  birthdate,
  age,
  place_of_birth,
  civil_status,
  citizenship,
  religion,
  email,
  mobile,
  street,
  house_unit,
  block_lot,
  house_street,
  barangay,
  city,
  province,
  region,
  full_address,
  request_notes,
  submission_date,
  updated_at
FROM service_requests;
