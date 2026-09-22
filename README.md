# University Equipment Borrowing and Management System

A centralized database system and web application designed to streamline the borrowing, returning, inventory monitoring, and condition tracking of university-owned equipment (such as power banks, chargers, keyboards, mice, printers, scanners, extension cords, projectors, and other academic assets).

---

## 📋 Table of Contents
- [Overview](#overview)
- [Problems Solved](#problems-solved)
- [Key Functionalities](#key-functionalities)
- [User Roles & Capabilities](#user-roles--capabilities)
- [Tech Stack](#tech-stack)
- [Database Features & Architecture](#database-features--architecture)
- [Getting Started / Local Setup](#getting-started--local-setup)

---

## 📌 Overview
Managing university assets manually often leads to lost items, unmonitored damage, and administrative bottlenecks. This system provides a web-based portal backed by a relational database to handle equipment workflows end-to-end—from user registration and real-time inventory checking to automated overdue tracking and reporting.

---

## ❓ Problems Solved
* **Lost or Incomplete Records:** Eliminates paper logbooks with automated logging.
* **Lack of Real-time Visibility:** Instantly shows who currently holds a specific asset.
* **Unmonitored Due Dates:** Tracks overdue items to prevent late returns.
* **Double-Booking:** Prevents lending out items that are currently unavailable, reserved, or damaged.
* **Condition Tracking:** Captures item condition before and after borrowing to hold users accountable for damage or loss.
* **Inaccurate Inventory:** Maintains synchronized dynamic counts for available stock.
* **Manual Reporting Delays:** Generates inventory, borrowing, damage, and transaction reports automatically.

---

## ✨ Key Functionalities

1. **User Registration & Management**  
   Store and manage detailed profiles for students, faculty, staff, and system administrators.
2. **Equipment Inventory Management**  
   Add, update, retire, and monitor all university physical assets.
3. **Equipment Categorization**  
   Group items into logical categories (e.g., Computer Accessories, Power Equipment, Office Equipment).
4. **Real-time Availability Checking**  
   Track asset status dynamically (`Available`, `Borrowed`, `Damaged`, `Under Maintenance`, `Unavailable`).
5. **Borrowing Management**  
   Record transaction details including borrower details, quantity, date/time borrowed, and anticipated return date.
6. **Return Management**  
   Process returned equipment, record return timestamps, and instantly make items available again.
7. **Equipment Condition Monitoring**  
   Inspect and record asset condition prior to checkout and immediately upon return.
8. **Overdue Monitoring**  
   Identify and flag transactions that pass the expected return date without clearance.
9. **Borrowing History**  
   Maintain comprehensive log history searchable by individual borrower or specific equipment item.
10. **Reports Generation**  
    Export and analyze reports on current inventory status, lending history, overdue list, and damaged items.
11. **Search & Filtering**  
    Quickly locate borrowers, specific assets, or historical transactions using filtered searches.
12. **Authentication & Access Control**  
    Role-based authentication ensures users can only access features permitted by their privilege level.

---

## 👥 User Roles & Capabilities

| User Type | Capabilities |
| :--- | :--- |
| **Administrator** | Manage user accounts/roles, update equipment records, view all system transactions, generate global analytics reports, and configure system settings. |
| **Lending Staff / Librarian** | Register borrowers, process checkouts and returns, inspect asset condition, verify availability, and manage overdue notices. |
| **Student / Borrower** | Browse available items, submit borrowing requests, view active loans and return due dates, and view personal transaction history. |
| **Faculty / Staff Borrower** | Browse available assets, request equipment for academic/event purposes, track active loans, and view personal transaction history. |

---

## 🛠️ Tech Stack

* **Frontend:** HTML5, CSS3, JavaScript (ES6+), Bootstrap
* **Backend:** PHP
* **Database:** MySQL
* **Local Server Environment:** XAMPP (Apache + MySQL)
* **Development Tools:** Visual Studio Code, Git / GitHub

---

## 🗄️ Database Features & Architecture

To ensure high performance, data integrity, and strict access control, the underlying MySQL database implements:

* **Primary & Foreign Keys:** Enforce relational integrity across users, assets, and transactions.
* **Database Normalization (up to 3NF):** Eliminates redundant data and ensures structural efficiency.
* **SQL Constraints:** `NOT NULL`, `UNIQUE`, `CHECK`, and `DEFAULT` values to maintain clean data.
* **Views:** Custom query views for quick lookup of active loans, overdue lists, and inventory summaries.
* **Stored Procedures & Functions:** Encapsulate recurring business logic (e.g., calculating overdue days, issuing checkout validation).
* **Triggers:** Automatically update asset availability status when a borrow or return record is created.
* **Indexing:** Indexed fields on frequently searched columns (e.g., `user_id`, `equipment_id`, `borrow_date`) for fast search performance.
* **Transaction Management:** ACID-compliant transaction blocks for multi-table updates during borrowing/return operations.
* **User Roles & Access Privileges:** SQL-level user privileges to secure the backend architecture.

---

## 🚀 Getting Started / Local Setup

### Prerequisites
* [XAMPP](https://www.apachefriends.org/index.html) (PHP 8.x + MySQL)
* [Git](https://git-scm.com/)

### Installation Steps

1. **Clone the Repository**
   ```bash
   git clone https://github.com/your-username/equipment-borrowing-system.git
   cd equipment-borrowing-system
   ```

2. **Move Project to Local Server Directory**
   Copy the project directory to your XAMPP `htdocs` folder:
   * Windows: `C:\xampp\htdocs\equipment-borrowing-system`
   * macOS: `/Applications/XAMPP/htdocs/equipment-borrowing-system`

3. **Database Setup**
   * Start Apache and MySQL in the XAMPP Control Panel.
   * Open `http://localhost/phpmyadmin/` in your browser.
   * Create a new database (e.g., `equipment_db`).
   * Import the SQL file located at `/database/equipment_db.sql`.

4. **Database Configuration**
   Update your database connection credentials in the backend configuration file (e.g., `config/database.php`):
   ```php
   $host = "localhost";
   $user = "root";
   $pass = "";
   $dbname = "equipment_db";
   ```

5. **Run the Application**
   Open your browser and navigate to:
   ```text
   http://localhost/equipment-borrowing-system/
   ```
