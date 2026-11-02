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
