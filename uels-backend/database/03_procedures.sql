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
