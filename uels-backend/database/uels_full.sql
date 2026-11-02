-- UELS full install (schema + views + procedures + triggers + seed)

-- >>>>>>>>>> 01_schema.sql
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

-- >>>>>>>>>> 02_views.sql
-- =====================================================================
-- 02_views.sql : reporting / lookup views
-- =====================================================================
USE uels_db;

-- Equipment with category name and computed "on loan" quantity
CREATE OR REPLACE VIEW v_equipment_availability AS
SELECT  e.equipment_id, e.asset_tag, e.name, e.description,
        e.category_id, c.name AS category_name,
        e.total_quantity, e.available_quantity, e.damaged_quantity,
        (e.total_quantity - e.available_quantity - e.damaged_quantity) AS borrowed_quantity,
        e.condition_status, e.status, e.location, e.is_active,
        e.created_at, e.updated_at
FROM equipment e
JOIN categories c ON c.category_id = e.category_id;

-- Every loan with borrower / equipment / staff names and computed overdue state
CREATE OR REPLACE VIEW v_loan_details AS
SELECT  l.loan_id,
        l.borrower_id,
        CONCAT(b.first_name, ' ', b.last_name) AS borrower_name,
        b.id_number   AS borrower_id_number,
        b.email       AS borrower_email,
        r.role_name   AS borrower_role,
        b.department  AS borrower_department,
        l.equipment_id, e.asset_tag, e.name AS equipment_name,
        e.category_id, c.name AS category_name,
        l.quantity, l.purpose, l.status,
        CASE WHEN l.status = 'borrowed' AND l.due_date < CURDATE() THEN 'overdue' ELSE l.status END AS display_status,
        CASE WHEN l.status = 'borrowed' AND l.due_date < CURDATE() THEN DATEDIFF(CURDATE(), l.due_date) ELSE 0 END AS days_overdue,
        l.requested_at, l.borrowed_at, l.due_date, l.returned_at,
        l.condition_before, l.condition_after,
        l.issued_by,   CONCAT(i.first_name,  ' ', i.last_name)  AS issued_by_name,
        l.received_by, CONCAT(rc.first_name, ' ', rc.last_name) AS received_by_name,
        l.remarks
FROM loans l
JOIN users b       ON b.user_id = l.borrower_id
JOIN roles r       ON r.role_id = b.role_id
JOIN equipment e   ON e.equipment_id = l.equipment_id
JOIN categories c  ON c.category_id = e.category_id
LEFT JOIN users i  ON i.user_id = l.issued_by
LEFT JOIN users rc ON rc.user_id = l.received_by;

-- Items currently out (including overdue)
CREATE OR REPLACE VIEW v_active_loans AS
SELECT * FROM v_loan_details WHERE status = 'borrowed';

-- Items not returned by the due date
CREATE OR REPLACE VIEW v_overdue_loans AS
SELECT * FROM v_loan_details WHERE display_status = 'overdue';

-- Equipment that has damaged units or is in poor/damaged condition
CREATE OR REPLACE VIEW v_damaged_equipment AS
SELECT * FROM v_equipment_availability
WHERE damaged_quantity > 0 OR condition_status IN ('poor','damaged');

-- Inventory totals per category
CREATE OR REPLACE VIEW v_category_summary AS
SELECT  c.category_id, c.name AS category_name,
        COUNT(e.equipment_id)                     AS equipment_items,
        COALESCE(SUM(e.total_quantity), 0)        AS total_units,
        COALESCE(SUM(e.available_quantity), 0)    AS available_units,
        COALESCE(SUM(e.damaged_quantity), 0)      AS damaged_units,
        COALESCE(SUM(e.total_quantity - e.available_quantity - e.damaged_quantity), 0) AS units_on_loan
FROM categories c
LEFT JOIN equipment e ON e.category_id = c.category_id AND e.is_active = 1
GROUP BY c.category_id, c.name;

-- >>>>>>>>>> 03_procedures.sql
-- =====================================================================
-- 03_procedures.sql : stored function + transactional stored procedures
-- All lending operations run inside START TRANSACTION ... COMMIT and lock
-- the equipment row (SELECT ... FOR UPDATE) so two staff members can never
-- lend the same last unit (no double-booking).
-- Run in phpMyAdmin's SQL tab or: mysql -u root < 03_procedures.sql
-- =====================================================================
USE uels_db;

DELIMITER $$

-- ---------------------------------------------------------------------
-- fn_equipment_status : derive equipment.status from quantities/active flag
-- ---------------------------------------------------------------------
DROP FUNCTION IF EXISTS fn_equipment_status$$
CREATE FUNCTION fn_equipment_status(p_active TINYINT, p_total INT, p_available INT, p_damaged INT)
RETURNS VARCHAR(12)
DETERMINISTIC NO SQL
BEGIN
    IF p_active = 0 OR p_total <= 0 THEN
        RETURN 'unavailable';
    ELSEIF p_available > 0 THEN
        RETURN 'available';
    ELSEIF p_damaged >= p_total THEN
        RETURN 'damaged';
    END IF;
    RETURN 'borrowed';
END$$

-- ---------------------------------------------------------------------
-- sp_request_loan : borrower asks for equipment -> loan.status = 'pending'
-- (stock is NOT deducted until staff issue the item)
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_request_loan$$
CREATE PROCEDURE sp_request_loan(
    IN p_borrower  INT UNSIGNED,
    IN p_equipment INT UNSIGNED,
    IN p_quantity  INT,
    IN p_due_date  DATE,
    IN p_purpose   VARCHAR(255)
)
BEGIN
    DECLARE v_user_status VARCHAR(10) DEFAULT NULL;
    DECLARE v_active      TINYINT DEFAULT NULL;
    DECLARE v_available   INT DEFAULT NULL;
    DECLARE v_overdue     INT DEFAULT 0;
    DECLARE v_pending     INT DEFAULT 0;
    DECLARE v_loan_id     INT UNSIGNED;
    DECLARE v_msg         VARCHAR(255);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT status INTO v_user_status FROM users WHERE user_id = p_borrower;
    IF v_user_status IS NULL OR v_user_status <> 'active' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Borrower not found or account is inactive';
    END IF;

    SELECT COUNT(*) INTO v_overdue FROM loans
     WHERE borrower_id = p_borrower AND status = 'borrowed' AND due_date < CURDATE();
    IF v_overdue > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Borrower has overdue items. Please return them before borrowing again';
    END IF;

    SELECT COUNT(*) INTO v_pending FROM loans WHERE borrower_id = p_borrower AND status = 'pending';
    IF v_pending >= 5 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Too many pending requests (maximum is 5)';
    END IF;

    IF p_quantity IS NULL OR p_quantity <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quantity must be at least 1';
    END IF;

    -- Business rule: equipment may be borrowed for at most 30 days
    IF p_due_date < CURDATE() OR p_due_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Due date must be between today and 30 days from today';
    END IF;

    SELECT is_active, available_quantity INTO v_active, v_available
      FROM equipment WHERE equipment_id = p_equipment;
    IF v_active IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Equipment not found';
    END IF;
    IF v_active = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This equipment is not available for lending';
    END IF;
    IF p_quantity > v_available THEN
        SET v_msg = CONCAT('Requested quantity exceeds available stock (available: ', v_available, ')');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;

    INSERT INTO loans (borrower_id, equipment_id, quantity, purpose, status, due_date)
    VALUES (p_borrower, p_equipment, p_quantity, p_purpose, 'pending', p_due_date);
    SET v_loan_id = LAST_INSERT_ID();

    COMMIT;
    SELECT v_loan_id AS loan_id;
END$$

-- ---------------------------------------------------------------------
-- sp_issue_loan : staff approve a pending request and hand over the item
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_issue_loan$$
CREATE PROCEDURE sp_issue_loan(
    IN p_loan_id          INT UNSIGNED,
    IN p_staff            INT UNSIGNED,
    IN p_due_date         DATE,
    IN p_condition_before VARCHAR(10)
)
BEGIN
    DECLARE v_status    VARCHAR(10) DEFAULT NULL;
    DECLARE v_equipment INT UNSIGNED;
    DECLARE v_borrower  INT UNSIGNED;
    DECLARE v_qty       INT;
    DECLARE v_due       DATE;
    DECLARE v_active    TINYINT DEFAULT NULL;
    DECLARE v_available INT DEFAULT 0;
    DECLARE v_condition VARCHAR(10);
    DECLARE v_overdue   INT DEFAULT 0;
    DECLARE v_staff_role VARCHAR(30) DEFAULT NULL;
    DECLARE v_msg       VARCHAR(255);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT r.role_name INTO v_staff_role FROM users u JOIN roles r ON r.role_id = u.role_id
     WHERE u.user_id = p_staff AND u.status = 'active';
    IF v_staff_role IS NULL OR v_staff_role NOT IN ('administrator','staff') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only lending staff or administrators can issue equipment';
    END IF;

    SELECT status, equipment_id, borrower_id, quantity, due_date
      INTO v_status, v_equipment, v_borrower, v_qty, v_due
      FROM loans WHERE loan_id = p_loan_id FOR UPDATE;
    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Loan not found';
    END IF;
    IF v_status <> 'pending' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only pending requests can be issued';
    END IF;

    SELECT COUNT(*) INTO v_overdue FROM loans
     WHERE borrower_id = v_borrower AND status = 'borrowed' AND due_date < CURDATE();
    IF v_overdue > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Borrower has overdue items and cannot borrow more equipment';
    END IF;

    IF p_due_date IS NOT NULL THEN
        SET v_due = p_due_date;
    END IF;
    IF v_due < CURDATE() THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Due date has already passed; please set a new due date';
    END IF;

    -- Lock the equipment row so concurrent staff cannot lend the same last unit
    SELECT is_active, available_quantity, condition_status
      INTO v_active, v_available, v_condition
      FROM equipment WHERE equipment_id = v_equipment FOR UPDATE;
    IF v_active IS NULL OR v_active = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This equipment is not available for lending';
    END IF;
    IF v_qty > v_available THEN
        SET v_msg = CONCAT('Insufficient stock to issue this request (available: ', v_available, ')');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;

    UPDATE equipment SET available_quantity = available_quantity - v_qty WHERE equipment_id = v_equipment;

    UPDATE loans
       SET status = 'borrowed',
           borrowed_at = NOW(),
           due_date = v_due,
           issued_by = p_staff,
           condition_before = COALESCE(p_condition_before, v_condition)
     WHERE loan_id = p_loan_id;

    COMMIT;
    SELECT p_loan_id AS loan_id;
END$$

-- ---------------------------------------------------------------------
-- sp_direct_borrow : walk-in borrowing recorded by staff (request + issue in one step)
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_direct_borrow$$
CREATE PROCEDURE sp_direct_borrow(
    IN p_borrower         INT UNSIGNED,
    IN p_equipment        INT UNSIGNED,
    IN p_quantity         INT,
    IN p_due_date         DATE,
    IN p_staff            INT UNSIGNED,
    IN p_condition_before VARCHAR(10),
    IN p_purpose          VARCHAR(255)
)
BEGIN
    DECLARE v_user_status VARCHAR(10) DEFAULT NULL;
    DECLARE v_staff_role  VARCHAR(30) DEFAULT NULL;
    DECLARE v_active      TINYINT DEFAULT NULL;
    DECLARE v_available   INT DEFAULT 0;
    DECLARE v_condition   VARCHAR(10);
    DECLARE v_overdue     INT DEFAULT 0;
    DECLARE v_loan_id     INT UNSIGNED;
    DECLARE v_msg         VARCHAR(255);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT r.role_name INTO v_staff_role FROM users u JOIN roles r ON r.role_id = u.role_id
     WHERE u.user_id = p_staff AND u.status = 'active';
    IF v_staff_role IS NULL OR v_staff_role NOT IN ('administrator','staff') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only lending staff or administrators can issue equipment';
    END IF;

    SELECT status INTO v_user_status FROM users WHERE user_id = p_borrower;
    IF v_user_status IS NULL OR v_user_status <> 'active' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Borrower not found or account is inactive';
    END IF;

    SELECT COUNT(*) INTO v_overdue FROM loans
     WHERE borrower_id = p_borrower AND status = 'borrowed' AND due_date < CURDATE();
    IF v_overdue > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Borrower has overdue items and cannot borrow more equipment';
    END IF;

    IF p_quantity IS NULL OR p_quantity <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quantity must be at least 1';
    END IF;
    IF p_due_date < CURDATE() OR p_due_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Due date must be between today and 30 days from today';
    END IF;

    SELECT is_active, available_quantity, condition_status
      INTO v_active, v_available, v_condition
      FROM equipment WHERE equipment_id = p_equipment FOR UPDATE;
    IF v_active IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Equipment not found';
    END IF;
    IF v_active = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This equipment is not available for lending';
    END IF;
    IF p_quantity > v_available THEN
        SET v_msg = CONCAT('Insufficient stock (available: ', v_available, ')');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
    END IF;

    UPDATE equipment SET available_quantity = available_quantity - p_quantity WHERE equipment_id = p_equipment;

    INSERT INTO loans (borrower_id, equipment_id, quantity, purpose, status, requested_at, borrowed_at,
                       due_date, condition_before, issued_by)
    VALUES (p_borrower, p_equipment, p_quantity, p_purpose, 'borrowed', NOW(), NOW(),
            p_due_date, COALESCE(p_condition_before, v_condition), p_staff);
    SET v_loan_id = LAST_INSERT_ID();

    COMMIT;
    SELECT v_loan_id AS loan_id;
END$$

-- ---------------------------------------------------------------------
-- sp_return_loan : staff receive equipment back and record its condition
--   * damaged units are NOT returned to available stock (they go to damaged_quantity)
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_return_loan$$
CREATE PROCEDURE sp_return_loan(
    IN p_loan_id         INT UNSIGNED,
    IN p_staff           INT UNSIGNED,
    IN p_condition_after VARCHAR(10),
    IN p_remarks         VARCHAR(500)
)
BEGIN
    DECLARE v_status     VARCHAR(10) DEFAULT NULL;
    DECLARE v_equipment  INT UNSIGNED;
    DECLARE v_qty        INT;
    DECLARE v_cond_before VARCHAR(10);
    DECLARE v_total      INT DEFAULT 0;
    DECLARE v_damaged    INT DEFAULT 0;
    DECLARE v_final_cond VARCHAR(10);
    DECLARE v_staff_role VARCHAR(30) DEFAULT NULL;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT r.role_name INTO v_staff_role FROM users u JOIN roles r ON r.role_id = u.role_id
     WHERE u.user_id = p_staff AND u.status = 'active';
    IF v_staff_role IS NULL OR v_staff_role NOT IN ('administrator','staff') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only lending staff or administrators can receive returns';
    END IF;

    SELECT status, equipment_id, quantity, condition_before
      INTO v_status, v_equipment, v_qty, v_cond_before
      FROM loans WHERE loan_id = p_loan_id FOR UPDATE;
    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Loan not found';
    END IF;
    IF v_status <> 'borrowed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only borrowed items can be returned';
    END IF;

    SET v_final_cond = COALESCE(p_condition_after, v_cond_before, 'good');

    SELECT total_quantity, damaged_quantity INTO v_total, v_damaged
      FROM equipment WHERE equipment_id = v_equipment FOR UPDATE;

    IF v_final_cond = 'damaged' THEN
        SET v_damaged = v_damaged + v_qty;
        UPDATE equipment
           SET damaged_quantity = v_damaged,
               condition_status = IF(v_damaged >= v_total, 'damaged', condition_status)
         WHERE equipment_id = v_equipment;
    ELSE
        UPDATE equipment
           SET available_quantity = available_quantity + v_qty,
               condition_status = v_final_cond
         WHERE equipment_id = v_equipment;
    END IF;

    UPDATE loans
       SET status = 'returned',
           returned_at = NOW(),
           condition_after = v_final_cond,
           received_by = p_staff,
           remarks = COALESCE(p_remarks, remarks)
     WHERE loan_id = p_loan_id;

    COMMIT;
    SELECT p_loan_id AS loan_id;
END$$

DELIMITER ;

-- >>>>>>>>>> 04_triggers.sql
-- =====================================================================
-- 04_triggers.sql : keep data consistent automatically
-- =====================================================================
USE uels_db;

DELIMITER $$

-- Equipment status is always derived from quantities / active flag ----
DROP TRIGGER IF EXISTS trg_equipment_bi$$
CREATE TRIGGER trg_equipment_bi BEFORE INSERT ON equipment FOR EACH ROW
BEGIN
    SET NEW.status = fn_equipment_status(NEW.is_active, NEW.total_quantity, NEW.available_quantity, NEW.damaged_quantity);
END$$

DROP TRIGGER IF EXISTS trg_equipment_bu$$
CREATE TRIGGER trg_equipment_bu BEFORE UPDATE ON equipment FOR EACH ROW
BEGIN
    SET NEW.status = fn_equipment_status(NEW.is_active, NEW.total_quantity, NEW.available_quantity, NEW.damaged_quantity);
END$$

-- Equipment with loan history can never be hard-deleted ---------------
DROP TRIGGER IF EXISTS trg_equipment_bd$$
CREATE TRIGGER trg_equipment_bd BEFORE DELETE ON equipment FOR EACH ROW
BEGIN
    IF EXISTS (SELECT 1 FROM loans WHERE equipment_id = OLD.equipment_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Equipment with borrowing history cannot be deleted; deactivate it instead';
    END IF;
END$$

-- Loan validation ------------------------------------------------------
DROP TRIGGER IF EXISTS trg_loans_bi$$
CREATE TRIGGER trg_loans_bi BEFORE INSERT ON loans FOR EACH ROW
BEGIN
    IF NEW.quantity <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quantity must be at least 1';
    END IF;
    IF NEW.due_date < CURDATE() THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Due date cannot be in the past';
    END IF;
END$$

-- Only legal status transitions are allowed -----------------------------
DROP TRIGGER IF EXISTS trg_loans_bu$$
CREATE TRIGGER trg_loans_bu BEFORE UPDATE ON loans FOR EACH ROW
BEGIN
    IF OLD.status <> NEW.status THEN
        IF OLD.status IN ('returned','rejected','cancelled') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Closed loans cannot be reopened';
        ELSEIF NEW.status = 'borrowed' AND OLD.status <> 'pending' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only pending requests can be issued';
        ELSEIF NEW.status = 'returned' AND OLD.status <> 'borrowed' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only borrowed items can be returned';
        ELSEIF NEW.status IN ('rejected','cancelled') AND OLD.status <> 'pending' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only pending requests can be rejected or cancelled';
        END IF;
    END IF;
END$$

-- Equipment condition history (before borrowing / after return) ---------
DROP TRIGGER IF EXISTS trg_loans_ai$$
CREATE TRIGGER trg_loans_ai AFTER INSERT ON loans FOR EACH ROW
BEGIN
    IF NEW.status = 'borrowed' THEN   -- walk-in borrowing
        INSERT INTO equipment_condition_logs (equipment_id, loan_id, event_type, condition_status, recorded_by)
        VALUES (NEW.equipment_id, NEW.loan_id, 'before_borrow', COALESCE(NEW.condition_before, 'good'), NEW.issued_by);
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_loans_au$$
CREATE TRIGGER trg_loans_au AFTER UPDATE ON loans FOR EACH ROW
BEGIN
    IF OLD.status = 'pending' AND NEW.status = 'borrowed' THEN
        INSERT INTO equipment_condition_logs (equipment_id, loan_id, event_type, condition_status, recorded_by)
        VALUES (NEW.equipment_id, NEW.loan_id, 'before_borrow', COALESCE(NEW.condition_before, 'good'), NEW.issued_by);
    ELSEIF OLD.status = 'borrowed' AND NEW.status = 'returned' THEN
        INSERT INTO equipment_condition_logs (equipment_id, loan_id, event_type, condition_status, notes, recorded_by)
        VALUES (NEW.equipment_id, NEW.loan_id, 'after_return', COALESCE(NEW.condition_after, 'good'), NEW.remarks, NEW.received_by);
    END IF;
END$$

DELIMITER ;

-- >>>>>>>>>> 05_seed.sql
-- =====================================================================
-- 05_seed.sql : reference data + sample inventory
-- (User accounts are created through the API: POST /api/setup)
-- =====================================================================
USE uels_db;

INSERT INTO roles (role_name, description) VALUES
 ('administrator', 'Manages users, equipment, reports and the whole system'),
 ('staff',         'Lending staff / librarian: processes borrowing and returns'),
 ('student',       'Student borrower'),
 ('faculty',       'Faculty / staff borrower');

INSERT INTO categories (name, description) VALUES
 ('Computer Accessories', 'Keyboards, mice, webcams, flash drives, cables'),
 ('Power Equipment',      'Power banks, chargers, extension cords'),
 ('Office Equipment',     'Printers, scanners, staplers'),
 ('Audio-Visual',         'Projectors, speakers, microphones, screens');

-- available_quantity starts equal to total_quantity; `status` is set by trigger
INSERT INTO equipment (asset_tag, name, description, category_id, total_quantity, available_quantity, condition_status, location) VALUES
 ('PWR-001', 'Power Bank 10,000 mAh',  'Portable charger with USB-A / USB-C',            2, 10, 10, 'good',      'Library Counter'),
 ('PWR-002', 'Laptop Charger (Universal)', 'Universal laptop AC adapter with tips',      2,  5,  5, 'good',      'Library Counter'),
 ('PWR-003', 'Extension Cord 5m',      '4-gang extension cord with surge protection',    2,  8,  8, 'good',      'Equipment Room'),
 ('CMP-001', 'USB Keyboard',           'Standard wired USB keyboard',                    1, 10, 10, 'good',      'Equipment Room'),
 ('CMP-002', 'Wireless Mouse',         '2.4GHz wireless optical mouse',                  1, 12, 12, 'excellent', 'Equipment Room'),
 ('CMP-003', 'HDMI Cable 3m',          'HDMI 2.0 cable',                                 1, 10, 10, 'good',      'Equipment Room'),
 ('OFC-001', 'Portable Printer',       'Compact inkjet printer',                         3,  2,  2, 'fair',      'IT Office'),
 ('OFC-002', 'Document Scanner',       'Flatbed A4 scanner',                             3,  2,  2, 'good',      'IT Office'),
 ('AV-001',  'LCD Projector',          'XGA projector with remote',                      4,  4,  4, 'good',      'AV Room'),
 ('AV-002',  'Portable Speaker',       'Bluetooth speaker with microphone input',        4,  3,  3, 'good',      'AV Room'),
 ('AV-003',  'Projector Screen (Tripod)', '70-inch tripod screen',                       4,  3,  3, 'good',      'AV Room');
