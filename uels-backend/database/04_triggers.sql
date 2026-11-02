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
