# University Equipment Lending System (UELS)

A PHP + MySQL starter application designed for XAMPP. It includes role-aware login, initial admin setup, equipment inventory, user creation, borrowing and returns, overdue status, borrower transaction history, inventory/overdue/history reports, CSV export, print-to-PDF, and password management for all accounts.

## Setup (XAMPP)
1. Copy the `uels` folder to `C:\xampp\htdocs\uels`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open `http://localhost/phpmyadmin`, create/import the database by using **Import** and selecting `database.sql` (the script creates `uels_db`).
4. Check `config.php`. The default XAMPP settings are host `127.0.0.1`, user `root`, and an empty password. Change them if your MySQL setup differs.
5. Open `http://localhost/uels/index.php?page=setup` to create the first administrator. Then sign in at `http://localhost/uels/`.
6. As administrator, create staff and borrower accounts under **Users**, then add equipment and start recording loans.

## Roles & Password Management
- **Administrator:** Full system access, including equipment, loans, reports, user creation, and user password resets. Administrators can also change their own password at any time via the **Change Password** option.
- **Lending staff:** Equipment management, borrowing, returns, reports, and loans. Staff can change their own password via **Change Password**.
- **Student / faculty:** Can browse the equipment catalog, view their own loan history under Loans & Returns, and change their account password via **Change Password**. Borrowing is processed by staff.
- **Admin Password Override:** Administrators can reset or set a new password for any registered user (student, faculty, staff, or admin) directly from the **Users** management table.

## Important notes
- Use only for local testing/class demonstration until reviewed and hardened for deployment. Use HTTPS, secure database credentials, backups, and institutional approval before real use.
- The app uses PDO prepared statements, password hashing, session ID regeneration at login, role checks, CSRF tokens, database foreign keys, stock row locking, and transactions for borrow/return operations.
- Bootstrap is loaded from a CDN, so internet access is required for styling unless you download Bootstrap locally.
- Self-service account registration, editable categories, audit log, email notifications, fine management, and borrower self-service requests are not included yet.
- The first-admin setup URL stops working after an administrator exists. Delete test data before using real data.
