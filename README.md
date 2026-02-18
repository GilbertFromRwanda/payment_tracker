# Payment Tracker

A web-based payment management and collection system for tracking customer payments across different sectors. Built with PHP and MySQL, it provides dashboards, bulk imports, detailed reporting, and customer payment profiles.

## Tech Stack

- **Backend:** PHP 7+ with PDO (MySQL)
- **Frontend:** HTML5, CSS3, JavaScript
- **Database:** MySQL
- **Libraries:** PHPSpreadsheet (Excel/CSV import)
- **Server:** Apache (XAMPP)

## Features

- **Dashboard** — Summary statistics (total customers, sectors, amounts) and recent payments
- **Sector Management** — Create and manage payment sectors
- **Customer Management** — Add customers manually or bulk import from Excel/CSV with duplicate detection
- **Payment Tracking** — Record payments per month, track missing months, detect overdue status
- **Customer Profiles** — Payment history, payment rate %, consistency rate, trend analysis
- **Reporting** — Filter by sector/customer/month/year, collection rate calculations, Excel export
- **Data Import** — Bulk import customers and payments from `.xls`, `.xlsx`, or `.csv` files
- **Export** — Printable HTML lists, plain lists, and Excel reports with financial summaries

## Installation

### Prerequisites

- PHP 7.0+
- MySQL 5.7+
- Apache (or XAMPP)
- Composer

### Setup

1. Clone or copy the project into your web server directory:
   ```
   /xampp/htdocs/
   ```

2. Install PHP dependencies:
   ```bash
   composer install
   ```

3. Create the database and import the schema:
   ```bash
   mysql -u root -p < sql.sql
   ```

4. Configure database credentials in [config/database.php](config/database.php):
   ```php
   $host = 'localhost';
   $dbname = 'payment_tracker';
   $username = 'root';
   $password = '';
   ```

5. Open in your browser:
   ```
   http://localhost/
   ```

### Default Login

- **Username:** `admin`
- **Password:** `admin123`

> Change the default credentials after first login.


## Importing Data

### Customer Import

Upload an Excel or CSV file with columns: **Name**, **Phone**, **Occupation**, **Amount to Pay**. Existing customers (matched by phone + sector) are updated automatically.

### Payment Import

Upload an Excel or CSV file with columns: **Phone**, **Month (YYYY-MM)**, **Paid Amount**, **Payment Date**. Customers are matched by phone number and sector.

Template files can be downloaded from the import pages.

## License

All rights reserved.
