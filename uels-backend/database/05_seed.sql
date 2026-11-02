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
