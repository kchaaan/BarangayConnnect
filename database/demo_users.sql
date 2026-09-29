-- Demo resident accounts for presentations (password for all: demo123)
USE barangayconnect;

DELETE FROM users_resident WHERE username IN ('demoresident', 'mariasantos');

INSERT INTO users_resident (
  resident_id, first_name, middle_name, last_name, gender, birthdate, age,
  civil_status, contact_number, email, house_street, barangay, city, province,
  profile_extra, username, password, status, approved_at
) VALUES
(
  'RES-001', 'Juan', 'M.', 'Demo', 'Male', '1995-06-15', 30,
  'Single', '09171234567', 'demo@barangayconnect.local',
  '123 Mabini St', 'Tambo', 'Parañaque City', 'Metro Manila',
  NULL, 'demoresident',
  '$2y$10$FaOc9tAxRbd8Ue9YgQk02enDlhlq9fby549Cu1FYigcL5bFg09pTi',
  'Approved', NOW()
),
(
  'RES-002', 'Maria', 'L.', 'Santos', 'Female', '1998-03-20', 27,
  'Married', '09179876543', 'maria@barangayconnect.local',
  '45 Rizal Ave', 'Tambo', 'Parañaque City', 'Metro Manila',
  NULL, 'mariasantos',
  '$2y$10$FaOc9tAxRbd8Ue9YgQk02enDlhlq9fby549Cu1FYigcL5bFg09pTi',
  'Approved', NOW()
);
