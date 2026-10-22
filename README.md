# Sipway Campus — Student Exam Results Management System

Full PHP + MySQL web application for managing student examination results, payments, and certificates.

---

## Features

- **Dashboard** — Summary statistics (students, present/absent, payment pending, certificates ready)
- **Courses** — Add / Edit / Delete courses
- **Batches** — Manage batches under each course
- **Students** — Full student registry with Reg No, Name, Phone, Address, Batch
- **Results** — Enter Assignment marks, Final Exam, Full Marks, Percentage + Status (Present / Absent / Pending / Not Eligible)
- **Payments** — Track payment status (Paid, Completed, One Time, CONVA PAY, Pending, Not Paid)
- **Certificates** — Auto eligibility based on marks + payment; manual status update
- **Reports** — Filterable reports + CSV export + Print
- **Colour-coded badges** for all statuses
- **Hardcoded Admin login**
- **Activity log**

---

## Folder Structure

```
sipway_results/
├── admin/
│   ├── index.php          → Dashboard
│   ├── courses.php
│   ├── batches.php
│   ├── students.php
│   ├── results.php
│   ├── payments.php
│   ├── certificates.php
│   ├── reports.php
│   └── logout.php
├── assets/
│   ├── css/style.css
│   └── js/script.js
├── config/
│   └── database.php       → DB config + Admin credentials
├── includes/
│   ├── auth.php
│   ├── header.php
│   └── footer.php
├── sql/
│   └── database.sql       → Full schema + sample data
├── uploads/
├── index.php              → Login page
└── README.md
```

---

## Installation (Register.lk / any PHP hosting)

### 1. Upload files
Upload the entire `sipway_results` folder to your hosting (e.g. `public_html/sipway_results`).

### 2. Create Database
1. Go to cPanel → MySQL Databases
2. Create database: `sipway_results` (or any name)
3. Create a user and grant ALL privileges
4. Import `sql/database.sql` via phpMyAdmin

### 3. Configure Database
Edit `config/database.php`:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_username');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'sipway_results');

define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', 'sipway2026');   // Change this!

define('BASE_URL', '/sipway_results');    // Change if folder name different
```

### 4. Access
Open: `https://yourdomain.com/sipway_results/`

**Login:**
- Username: `admin`
- Password: `sipway2026`

---

## Default Admin Credentials

| Username | Password   |
|----------|------------|
| admin    | sipway2026 |

**Please change the password in `config/database.php` after first login.**

---

## Technology

| Layer        | Technology              |
|--------------|-------------------------|
| Frontend     | HTML, CSS, JS, Bootstrap 5 |
| Backend      | Pure PHP (no framework) |
| Database     | MySQL                   |
| Auth         | Session + Hardcoded Admin |
| Hosting      | Register.lk / any PHP host |

---

## Sample Data Included

- 6 Courses (Russian Language, DBM, DHRM, Graphic Design, Import & Export, Management)
- Multiple batches
- Sample students with results, payments, certificates from the Excel sheets

---

## Notes

- When you add a new student, empty Result / Payment / Certificate records are auto-created.
- Updating Result or Payment automatically recalculates Certificate eligibility (Present + marks ≥ 40 + payment paid → READY).
- Status codes match your Excel sheets: AB = ABSENT, NO = NOT_ELIGIBLE, CONVA PAY, etc.

---

© Sipway Campus — Student Exam Results Management System
