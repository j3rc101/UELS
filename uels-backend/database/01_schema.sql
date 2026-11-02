-- =====================================================================
-- UELS - University Equipment Lending System
-- 01_schema.sql : database, tables, keys, constraints, indexes
-- Works on MySQL 8+ and MariaDB 10.4+ (XAMPP).
-- WARNING: re-running this file DROPS and recreates all tables.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS uels_db
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE uels_db;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS equipment_condition_logs;
DROP TABLE IF EXISTS loans;
DROP TABLE IF EXISTS equipment;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- roles  (3NF: role names live in one place, users reference them)
-- ---------------------------------------------------------------------
CREATE TABLE roles (
    role_id     TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_name   VARCHAR(30)  NOT NULL,
    description VARCHAR(255) NULL,
    PRIMARY KEY (role_id),
    UNIQUE KEY uq_roles_name (role_name)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- users  (students, faculty, staff, administrators)
-- ---------------------------------------------------------------------
CREATE TABLE users (
    user_id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_number       VARCHAR(30)  NOT NULL COMMENT 'Student / employee ID',
    first_name      VARCHAR(60)  NOT NULL,
    last_name       VARCHAR(60)  NOT NULL,
    email           VARCHAR(120) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    role_id         TINYINT UNSIGNED NOT NULL,
    department      VARCHAR(100) NULL,
    contact_number  VARCHAR(30)  NULL,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until    DATETIME NULL,
    last_login_at   DATETIME NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_id_number (id_number),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_name (last_name, first_name),
    KEY idx_users_role_status (role_id, status),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (role_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- categories
-- ---------------------------------------------------------------------
CREATE TABLE categories (
    category_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(80)  NOT NULL,
    description VARCHAR(255) NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (category_id),
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- equipment  (quantity-based: one row can represent e.g. 10 power banks)
--   Invariant: available + damaged + (currently on loan) = total
--   `status` is derived automatically by triggers (see 04_triggers.sql)
-- ---------------------------------------------------------------------
CREATE TABLE equipment (
    equipment_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_tag          VARCHAR(40)  NOT NULL COMMENT 'Inventory / property code',
    name               VARCHAR(120) NOT NULL,
    description        TEXT NULL,
    category_id        INT UNSIGNED NOT NULL,
    total_quantity     INT NOT NULL DEFAULT 1,
    available_quantity INT NOT NULL DEFAULT 1,
    damaged_quantity   INT NOT NULL DEFAULT 0,
    condition_status   ENUM('excellent','good','fair','poor','damaged') NOT NULL DEFAULT 'good',
    status             ENUM('available','borrowed','damaged','unavailable') NOT NULL DEFAULT 'available',
    location           VARCHAR(100) NULL,
    is_active          TINYINT(1) NOT NULL DEFAULT 1,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (equipment_id),
    UNIQUE KEY uq_equipment_asset_tag (asset_tag),
    KEY idx_equipment_name (name),
    KEY idx_equipment_category_status (category_id, status),
    CONSTRAINT fk_equipment_category FOREIGN KEY (category_id) REFERENCES categories (category_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_equipment_qty CHECK (
        total_quantity >= 0 AND available_quantity >= 0 AND damaged_quantity >= 0
        AND available_quantity + damaged_quantity <= total_quantity
    )
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- loans  (one row = one borrowing transaction for one equipment item)
--   pending -> borrowed -> returned      (or pending -> rejected / cancelled)
--   "overdue" is NOT stored; it is computed (status='borrowed' AND due_date < today)
-- ---------------------------------------------------------------------
CREATE TABLE loans (
    loan_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    borrower_id      INT UNSIGNED NOT NULL,
    equipment_id     INT UNSIGNED NOT NULL,
    quantity         INT NOT NULL DEFAULT 1,
    purpose          VARCHAR(255) NULL,
    status           ENUM('pending','borrowed','returned','rejected','cancelled') NOT NULL DEFAULT 'pending',
    requested_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    borrowed_at      DATETIME NULL,
    due_date         DATE NOT NULL,
    returned_at      DATETIME NULL,
    condition_before ENUM('excellent','good','fair','poor','damaged') NULL,
    condition_after  ENUM('excellent','good','fair','poor','damaged') NULL,
    issued_by        INT UNSIGNED NULL COMMENT 'Staff who released the item',
    received_by      INT UNSIGNED NULL COMMENT 'Staff who received the return',
    remarks          VARCHAR(500) NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (loan_id),
    KEY idx_loans_borrower (borrower_id, status),
    KEY idx_loans_equipment (equipment_id, status),
    KEY idx_loans_status_due (status, due_date),
    KEY idx_loans_requested (requested_at),
    CONSTRAINT fk_loans_borrower  FOREIGN KEY (borrower_id)  REFERENCES users (user_id)         ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_loans_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (equipment_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_loans_issued_by   FOREIGN KEY (issued_by)   REFERENCES users (user_id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_loans_received_by FOREIGN KEY (received_by) REFERENCES users (user_id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_loans_qty CHECK (quantity > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- equipment_condition_logs : condition before borrowing / after return / inspections
-- ---------------------------------------------------------------------
CREATE TABLE equipment_condition_logs (
    log_id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    equipment_id     INT UNSIGNED NOT NULL,
    loan_id          INT UNSIGNED NULL,
    event_type       ENUM('before_borrow','after_return','inspection') NOT NULL,
    condition_status ENUM('excellent','good','fair','poor','damaged') NOT NULL,
    notes            VARCHAR(500) NULL,
    recorded_by      INT UNSIGNED NULL,
    recorded_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    KEY idx_cond_equipment (equipment_id, recorded_at),
    KEY idx_cond_loan (loan_id),
    CONSTRAINT fk_cond_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (equipment_id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_cond_loan      FOREIGN KEY (loan_id)      REFERENCES loans (loan_id)           ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_cond_user      FOREIGN KEY (recorded_by)  REFERENCES users (user_id)           ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- audit_logs : who did what (written by the PHP backend)
-- ---------------------------------------------------------------------
CREATE TABLE audit_logs (
    log_id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NULL,
    action     VARCHAR(60)  NOT NULL,
    entity     VARCHAR(40)  NULL,
    entity_id  INT UNSIGNED NULL,
    details    VARCHAR(500) NULL,
    ip_address VARCHAR(45)  NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    KEY idx_audit_user (user_id, created_at),
    KEY idx_audit_entity (entity, entity_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;
