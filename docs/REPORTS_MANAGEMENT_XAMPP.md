# Reports management — XAMPP update

Branch: `figma-integration` only.

For the complete admin update, follow [ADMIN_MANAGEMENT_XAMPP.md](ADMIN_MANAGEMENT_XAMPP.md).
The Reports feature now also requires `php/admin_evidence.php` and
`admin/report-evidence.php`. Copy these files, keeping their folders:

- `admin/reports.php`
- `css/admin-reports.css`
- `php/admin_evidence.php`
- `admin/report-evidence.php`

For your installation, the destination is `C:\xampp\htdocs\Bushisa-figma\`.
The page reuses the fonts and four halo SVG files already installed under
`assets/admin/` for Users Management. Keep that folder in place.

Start Apache and MySQL, sign in as an administrator or moderator, then open:
`http://localhost/Bushisa-figma/admin/reports.php`
Use Ctrl+F5 once to refresh cached styling.

No SQL import, database replacement, password change, or new configuration file
is required. The existing operational-schema compatibility helper is retained;
no new migration or sample records are added.

## Behaviour

- Opens the pending queue; all five status filters are available.
- Counts come from all database reports, independently of search and pagination.
- Search matches reason text literally or an exact report number, e.g. `#12`.
- Twenty reports per page, newest first. Search and page links return to the filters.
- Details show the reason, available confession content, private account IDs and status.
- No names, email addresses, anonymous handles, passwords or private file paths are exposed.
- Supported evidence images and PDFs are served through a protected, audited
  endpoint from private storage. See the complete admin guide for storage paths
  and limits. Missing or unsafe files stay unavailable.
- Moderators and administrators can mark pending reports reviewed, dismiss them,
  or hide reported confessions with confirmation.
- Only administrators can suspend reported non-staff accounts after confirmation.
  Self-suspension, staff suspension and already-suspended accounts are rejected.
- Hiding and suspension set the report to `action_taken`. Historical status values
  remain unchanged. Completed decisions cannot be overwritten from this page.
- Decisions and audit records commit together. Stale or repeated submissions are rejected.
- No account or confession deletion is introduced.
- Mobile navigation sits after the content and scrolls horizontally.

## Validation

`tests/reports_management_integration.py` creates and removes a uniquely named,
local disposable test database. It never reads or changes the application database.
Requires PHP 8 with PDO MySQL, mbstring and ctype, Python 3, and local MySQL/MariaDB.
Run using `python tests/reports_management_integration.py`; configure `TEST_DB_PORT`,
`TEST_DB_USER`, `TEST_DB_PASS`, `PHP_BIN` and `PHP_INI` as necessary.

Optional browser check (Playwright required):
`BROWSER_TEST=tests/reports_layout.cjs python tests/reports_management_integration.py`
`PLAYWRIGHT_MODULE` and `CHROMIUM_PATH` can select existing browser tooling.

Tested with PHP 8.3, MariaDB 10.11 and Chromium, at widths 1440, 1024, 750, 390 and
320 pixels. Tests cover permissions, totals, search, pagination, escaping, details,
CSRF, confirmation, staff protection, review actions, audit failure rollback,
stale decisions and legacy confession-column compatibility.

The complete update adds dedicated admin Confessions and Moderation pages.
Student-facing pages and the existing shared account/report logic are preserved.
