# University Equipment Lending System (UELS)

A PHP and MySQL web application for managing university equipment inventory, borrowers, lending/returns, overdue loans, and reports. Includes a student-facing equipment catalog.

## Technology
- PHP 8+ (PDO)
- MySQL / MariaDB
- HTML, CSS, JavaScript, Bootstrap 5
- XAMPP for local development

## Run locally with XAMPP
1. Copy this repository folder to `C:\xampp\htdocs\uels`.
2. Start **Apache** and **MySQL** in XAMPP.
3. Open `http://localhost/phpmyadmin`.
4. Import `database.sql`. The script creates the `uels_db` database and tables.
5. Verify database settings in `config.php` (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).
6. Visit `http://localhost/uels/index.php?page=setup` and create the first administrator account. This setup page is disabled after an administrator exists.
7. Sign in at `http://localhost/uels/`.
8. As administrator or lending staff, add equipment and user accounts.
9. Students and faculty can browse `http://localhost/uels/catalog.php` after signing in.

## Student equipment catalog
`catalog.php` is in the project root beside `index.php` and `config.php`. It reads active equipment from the same database inventory and supports searching by equipment name/asset code and filtering by category. Students cannot edit inventory; staff must process borrowing transactions.

## Roles
- **Administrator:** user management, equipment management, transactions, and reports.
- **Lending staff:** equipment management and lending/return operations.
- **Student / faculty:** view their loan records and browse the equipment catalog.

## Notes
- This is a class-project/local-demo starter. Test all workflows before relying on it for real transactions.
- Do not commit real credentials, private user data, or production database dumps to GitHub.
- `config.php` uses default local XAMPP credentials. Update them for your environment.
- Bootstrap is loaded from a CDN, so internet access is needed for its styles.
- Self-service borrowing requests, password reset, email notifications, audit logs, and fine management are not included.
