# Users Management — XAMPP update

Branch: `figma-integration`. Entry point: `admin/users.php`.

## Install

For a Git checkout, save local edits, check out `figma-integration`, then pull that branch. Do not switch to or merge into main.

For a manual update, copy these files into the same paths in your existing XAMPP Bushisa folder:

- `admin/users.php`
- `css/admin-users.css`
- `js/admin-users.js`
- `assets/admin/fonts/Inter.ttf`
- `assets/admin/fonts/Lora.ttf`
- `assets/admin/fonts/Inter-OFL.txt`
- `assets/admin/fonts/Lora-OFL.txt`

Keep the four existing `assets/admin/users-*-halo-*.svg` files. The existing branch already supplies `php/admin_access.php`, `php/csrf.php`, shared frontend helpers and Admin Dashboard styles.

No database migration, seed or configuration change is required. Do not import `database/bushisa.sql` for this update: it contains destructive table resets. Keep your existing `config/db.php` and uploads.

Open `http://localhost/YOUR-BUSHISA-FOLDER/admin/users.php` and sign in with an existing, non-suspended account whose current database role is `admin`. Moderator accounts cannot access this directory. This update does not create or promote any account.

## Behavior

- Statistics cover all database accounts; filters affect the directory and export.
- Search matches display name, email, student ID and account ID (including `#002`). SQL wildcard characters are treated literally.
- Role, suspension and verification filters use database fields; 25 accounts per page.
- View Details is read-only, with masked email, role, verification, status and joining date. Missing accounts return a visible 404 state.
- Export CSV is a CSRF-protected POST and exports all currently applied matches. Email local parts are masked; student IDs, passwords, tokens, anonymous handles, private messages and dating preferences are excluded. Spreadsheet formulas are escaped. Export completion is logged to the PHP server error log with the administrator ID, without search terms or account contents.
- Mobile follows Figma node 85:34: four administrator tabs below the directory. Verification filter and CSV export remain desktop-only as shown in the design.
- Fonts are served locally; the existing restrictive content security policy is retained.

## Verification performed

PHP 8.3 and JavaScript syntax checks; real MariaDB 10.11 HTTP integration tests in a disposable database. Synthetic fixtures were confined to that database. No deployed database or user records were accessed.

Checks covered statistics, combined filters, literal wildcard and SQL-injection inputs, masked data, read-only details, missing account IDs, student/moderator/suspended access denial, stale session revocation, invalid CSRF rejection, CSV formula escaping, filtered export, 1,007-row export, pagination clamping and accounts without profiles.

Chromium layout checks at 1440, 1024, 390 and 320 px: no document horizontal overflow. Desktop/mobile screenshots reviewed against the latest Figma; local fonts and existing halo assets loaded. Browser checks covered select auto-submit, modal focus/background isolation, Escape to close, empty state and the export button.

Run the repeatable backend test with a local disposable MySQL/MariaDB server and PHP 8 with pdo_mysql/mbstring:

```sh
python tests/users_management_integration.py
```

Optional environment variables: `TEST_DB_HOST` (local only), `TEST_DB_PORT`, `TEST_DB_USER`, `TEST_DB_PASS`, `PHP_BIN`, `PHP_INI`. The test creates a uniquely named temporary database and deletes only that database on completion. Never deploy test fixtures into the application database.

A final smoke check on your XAMPP installation is still needed because this environment cannot access its database or PHP configuration.
