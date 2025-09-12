-- Asset Vault schema
-- Create database manually if needed: CREATE DATABASE asset_vault CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

SET NAMES utf8mb4;

-- Lookups
CREATE TABLE IF NOT EXISTS asset_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  UNIQUE KEY (name)
);

CREATE TABLE IF NOT EXISTS coverage_definitions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(128) NOT NULL,
  description TEXT NULL,
  applicable_types SET('home','auto','boat','flood','umbrella','jewelry','electronics','other') NOT NULL,
  UNIQUE KEY (code)
);

-- Core assets
CREATE TABLE IF NOT EXISTS assets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parent_id INT NULL,
  name VARCHAR(200) NOT NULL,
  category_id INT NULL,
  description TEXT NULL,
  location VARCHAR(200) NULL,
  make VARCHAR(100) NULL,
  model VARCHAR(100) NULL,
  serial_number VARCHAR(100) NULL,
  year SMALLINT NULL,
  purchase_date DATE NULL,
  notes TEXT NULL,
  location_id INT NULL,
  public_token VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_assets_parent FOREIGN KEY (parent_id) REFERENCES assets(id) ON DELETE SET NULL,
  CONSTRAINT fk_assets_category FOREIGN KEY (category_id) REFERENCES asset_categories(id) ON DELETE SET NULL
);

-- Reusable Locations (e.g., rooms in a house, storage units, garages)
-- Scoped locations owned by an asset; children can use these
CREATE TABLE IF NOT EXISTS asset_locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_id INT NOT NULL,
  parent_id INT NULL,
  name VARCHAR(150) NOT NULL,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_asset_locations_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
  CONSTRAINT fk_asset_locations_parent FOREIGN KEY (parent_id) REFERENCES asset_locations(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_asset_location_name (asset_id, name)
);

-- Add asset-scoped location reference on assets
ALTER TABLE assets ADD COLUMN asset_location_id INT NULL;
ALTER TABLE assets
  ADD CONSTRAINT fk_assets_asset_location FOREIGN KEY (asset_location_id) REFERENCES asset_locations(id) ON DELETE SET NULL;

-- Public token unique for shareable links
ALTER TABLE assets
  ADD UNIQUE KEY uniq_assets_public_token (public_token);

-- Optional: asset addresses for physical property or storage addresses
CREATE TABLE IF NOT EXISTS asset_addresses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_id INT NOT NULL,
  address_type ENUM('physical','storage','mailing','other') NOT NULL DEFAULT 'physical',
  line1 VARCHAR(200) NOT NULL,
  line2 VARCHAR(200) NULL,
  city VARCHAR(100) NOT NULL,
  state VARCHAR(100) NULL,
  postal_code VARCHAR(20) NULL,
  country VARCHAR(100) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_asset_addr (asset_id, address_type),
  CONSTRAINT fk_asset_addresses_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS asset_values (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  asset_id INT NOT NULL,
  value_type ENUM('purchase','current','replace') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  valuation_date DATE NOT NULL,
  source VARCHAR(100) NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_asset_values_asset_date (asset_id, valuation_date),
  CONSTRAINT fk_asset_values_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
);

-- Files (images/documents) stored in DB
-- Generic association via entity_type + entity_id
CREATE TABLE IF NOT EXISTS files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity_type ENUM('asset','policy') NOT NULL,
  entity_id INT NOT NULL,
  filename VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  size INT NOT NULL,
  content LONGBLOB NOT NULL,
  caption VARCHAR(255) NULL,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_files_entity (entity_type, entity_id)
);

-- People (for policy coverage of people)
CREATE TABLE IF NOT EXISTS people (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  dob DATE NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Policies and renewals
CREATE TABLE IF NOT EXISTS policy_groups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  display_name VARCHAR(200) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS policies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  policy_group_id INT NOT NULL,
  version_number INT NOT NULL,
  policy_number VARCHAR(100) NOT NULL,
  insurer VARCHAR(200) NOT NULL,
  policy_type ENUM('home','auto','boat','flood','umbrella','jewelry','electronics','other') NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  premium DECIMAL(12,2) NOT NULL,
  status ENUM('active','expired','cancelled','quote') NOT NULL DEFAULT 'active',
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_group_version (policy_group_id, version_number),
  INDEX idx_policy_dates (start_date, end_date),
  CONSTRAINT fk_policies_group FOREIGN KEY (policy_group_id) REFERENCES policy_groups(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS policy_coverages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  policy_id INT NOT NULL,
  coverage_definition_id INT NOT NULL,
  limit_amount DECIMAL(12,2) NULL,
  deductible_amount DECIMAL(12,2) NULL,
  notes VARCHAR(255) NULL,
  CONSTRAINT fk_policy_coverages_policy FOREIGN KEY (policy_id) REFERENCES policies(id) ON DELETE CASCADE,
  CONSTRAINT fk_policy_coverages_def FOREIGN KEY (coverage_definition_id) REFERENCES coverage_definitions(id) ON DELETE RESTRICT
);

-- Links between policies and assets (with optional child inheritance)
CREATE TABLE IF NOT EXISTS policy_assets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  policy_id INT NOT NULL,
  asset_id INT NOT NULL,
  applies_to_children TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_policy_assets_policy FOREIGN KEY (policy_id) REFERENCES policies(id) ON DELETE CASCADE,
  CONSTRAINT fk_policy_assets_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_policy_asset (policy_id, asset_id)
);

-- People on policies
CREATE TABLE IF NOT EXISTS policy_people (
  id INT AUTO_INCREMENT PRIMARY KEY,
  policy_id INT NOT NULL,
  person_id INT NOT NULL,
  role ENUM('named_insured','driver','resident','listed','other') NOT NULL DEFAULT 'named_insured',
  CONSTRAINT fk_policy_people_policy FOREIGN KEY (policy_id) REFERENCES policies(id) ON DELETE CASCADE,
  CONSTRAINT fk_policy_people_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_policy_person (policy_id, person_id, role)
);

-- Minimal audit trail (app-managed inserts)
CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(64) NOT NULL,
  entity_id INT NOT NULL,
  action VARCHAR(32) NOT NULL,
  details TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Seeds
INSERT IGNORE INTO asset_categories (name, description) VALUES
('Home', 'Residential property'),
('Electronics', 'Consumer electronics'),
('Appliances', 'Home appliances'),
('Furniture', 'Household furniture'),
('Jewelry', 'Jewelry items'),
('Vehicle', 'Cars, trucks, motorcycles'),
('Boat', 'Boats and watercraft');

INSERT IGNORE INTO coverage_definitions (code, name, description, applicable_types) VALUES
('dwelling', 'Dwelling Coverage', 'Covers the physical structure of the home', 'home'),
('other_structures', 'Other Structures', 'Detached structures on the property', 'home'),
('personal_property', 'Personal Property', 'Belongings inside the home', 'home'),
('loss_of_use', 'Loss of Use', 'Additional living expenses', 'home'),
('liability', 'Liability', 'Personal liability coverage', 'home,auto,boat,umbrella'),
('medical_payments', 'Medical Payments', 'Guest medical coverage', 'home'),
('collision', 'Collision', 'Vehicle collision damage', 'auto'),
('comprehensive', 'Comprehensive', 'Non-collision vehicle damage', 'auto'),
('bodily_injury', 'Bodily Injury', 'Liability for injuries to others', 'auto'),
('property_damage', 'Property Damage', 'Liability for damage to property', 'auto'),
('uninsured_motorist', 'Uninsured/Underinsured Motorist', 'Covers you if other driver lacks insurance', 'auto'),
('boat_hull', 'Boat Hull', 'Covers the boat hull', 'boat'),
('boat_equipment', 'Boat Equipment', 'Covers motors and equipment', 'boat'),
('flood_building', 'Building (Flood)', 'Building coverage for flood', 'flood'),
('flood_contents', 'Contents (Flood)', 'Contents coverage for flood', 'flood'),
('scheduled_property', 'Scheduled Property', 'Scheduled personal property (e.g., jewelry)', 'jewelry,electronics,home'),
('umbrella_liability', 'Umbrella Liability', 'Excess liability coverage', 'umbrella');
