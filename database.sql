CREATE DATABASE IF NOT EXISTS uels_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE uels_db;
CREATE TABLE IF NOT EXISTS users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 full_name VARCHAR(120) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('admin','staff','student','faculty') NOT NULL DEFAULT 'student',
 student_staff_no VARCHAR(50) NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS categories (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL UNIQUE,
 description VARCHAR(255) NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS equipment (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 asset_code VARCHAR(50) NOT NULL UNIQUE,
 name VARCHAR(150) NOT NULL,
 category_id INT UNSIGNED NULL,
 description TEXT NULL,
 total_quantity INT UNSIGNED NOT NULL DEFAULT 1,
 available_quantity INT UNSIGNED NOT NULL DEFAULT 1,
 condition_status ENUM('good','fair','damaged') NOT NULL DEFAULT 'good',
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_equipment_category FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE SET NULL,
 CONSTRAINT chk_equipment_qty CHECK (available_quantity <= total_quantity)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS loans (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 borrower_id INT UNSIGNED NOT NULL,
 equipment_id INT UNSIGNED NOT NULL,
 quantity INT UNSIGNED NOT NULL,
 borrowed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 due_at DATE NOT NULL,
 returned_at DATETIME NULL,
 condition_before ENUM('good','fair','damaged') NOT NULL DEFAULT 'good',
 condition_after ENUM('good','fair','damaged') NULL,
 status ENUM('borrowed','returned','overdue','cancelled') NOT NULL DEFAULT 'borrowed',
 processed_by INT UNSIGNED NULL,
 return_processed_by INT UNSIGNED NULL,
 notes VARCHAR(500) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_loans_status_due(status,due_at), INDEX idx_loans_borrower(borrower_id), INDEX idx_loans_equipment(equipment_id),
 CONSTRAINT fk_loan_borrower FOREIGN KEY(borrower_id) REFERENCES users(id),
 CONSTRAINT fk_loan_equipment FOREIGN KEY(equipment_id) REFERENCES equipment(id),
 CONSTRAINT fk_loan_processed_by FOREIGN KEY(processed_by) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_loan_return_by FOREIGN KEY(return_processed_by) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT chk_loan_qty CHECK (quantity > 0)
) ENGINE=InnoDB;
INSERT IGNORE INTO categories(name,description) VALUES
('Computer Accessories','Keyboards, mice, cables and related devices'),
('Power Equipment','Power banks, chargers and extension cords'),
('Office Equipment','Printers, scanners and office devices'),
('Presentation Equipment','Projectors and presentation accessories');
-- Create the first administrator using the one-time setup page after importing the database.
