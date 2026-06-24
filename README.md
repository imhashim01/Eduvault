# EduVault – Academic Submission & Similarity Analyzer
### HTML · CSS · JS · PHP · MySQL (XAMPP / phpMyAdmin)

---

## ⚙️ XAMPP Setup Guide (Step-by-Step)

### Step 1 — Install & Start XAMPP
1. Download XAMPP from https://www.apachefriends.org
2. Open the **XAMPP Control Panel**
3. Click **Start** next to **Apache**
4. Click **Start** next to **MySQL**
   - Both should show a green **Running** status

### Step 2 — Copy Project to htdocs
Copy the entire `EduVault2` folder into your XAMPP web root:

| OS      | htdocs path                          |
|---------|--------------------------------------|
| Windows | `C:\xampp\htdocs\EduVault2\`         |
| macOS   | `/Applications/XAMPP/htdocs/EduVault2/` |
| Linux   | `/opt/lampp/htdocs/EduVault2/`       |

### Step 3 — Import the Database in phpMyAdmin
1. Open your browser and go to: http://localhost/phpmyadmin
2. Click **New** (left sidebar) to create a new database
3. Name it **`eduvault`**, choose **`utf8mb4_unicode_ci`**, click **Create**
4. With `eduvault` selected, click the **Import** tab (top menu)
5. Click **Choose File** → select `database/eduvault.sql` from this project
6. Click **Import** at the bottom
7. You should see a success message with all 6 tables created

### Step 4 — Configure the Database Connection
Open **`php/config.php`** in a text editor and update if needed:

```php
define('DB_HOST', 'localhost');   // leave as-is for XAMPP
define('DB_PORT', '3306');        // default MySQL port
define('DB_NAME', 'eduvault');    // must match what you created in phpMyAdmin
define('DB_USER', 'root');        // XAMPP default username
define('DB_PASS', '');            // blank by default; fill in if you set a password
```

> **Note:** You only need to edit `config.php`. Never edit `db.php` directly.

### Step 5 — Verify the Connection
Open in your browser:
```
http://localhost/EduVault2/php/test_connection.php
```
- ✅ Green = database is connected and ready
- ❌ Red = follow the troubleshooting tips shown on the page

### Step 6 — Open EduVault
```
http://localhost/EduVault2/
```

---

## 🔐 Demo Credentials

| Role    | Email               | Password    |
|---------|---------------------|-------------|
| Admin   | admin@eduvault.io   | Admin@123   |
| Student | john@university.edu | Student@123 |

---

## 📁 Project Structure

```
EduVault2/
├── index.html            ← Landing page
├── login.html            ← Sign-in (student / admin tabs)
├── register.html         ← 3-step registration
├── dashboard.html        ← Main app
│
├── php/
│   ├── config.php        ← ★ XAMPP DB settings (edit this)
│   ├── db.php            ← PDO singleton + helpers (do not edit)
│   ├── test_connection.php ← DB connection tester
│   ├── login.php         ← Auth handler
│   ├── register.php      ← Registration handler
│   ├── upload_assignment.php ← File upload + similarity
│   ├── compare.php       ← Similarity detail API
│   └── admin.php         ← Admin CRUD API
│
├── database/
│   └── eduvault.sql      ← Full schema + sample data (import this)
│
├── css/   js/   uploads/
└── assets/
```

---

## 🛠️ Troubleshooting

| Problem | Fix |
|---------|-----|
| MySQL won't start in XAMPP | Another program (e.g. Skype, SQL Server) is using port 3306. Change MySQL port in XAMPP config or stop the conflicting app. |
| "Unknown database eduvault" | You haven't imported the SQL file yet — follow Step 3. |
| "Access denied for user root" | You set a password in phpMyAdmin — put it in `DB_PASS` in `config.php`. |
| Uploads not saving | Right-click the `uploads/` folder → Properties → allow write permission. |
| Blank page / 500 error | Enable PHP error display: in `php/config.php` set `DEBUG_MODE` to `true`. |

---

## 🗄️ Database Tables

| Table | Purpose |
|-------|---------|
| `users` | Student & admin accounts |
| `submissions` | Uploaded assignments + similarity scores |
| `similarity_matches` | Matched submission pairs with snippets |
| `activity_log` | Audit trail (login, upload, admin actions) |
| `courses` | Course catalog |
| `settings` | System configuration key-value store |

---

## 📄 License
MIT — Free for educational and commercial use.
