# Completed Bushisa administrator update

Use the `figma-integration` branch of `Nyatsimba01/Bushisa`.

## Install in your existing XAMPP project

1. Keep your current project and database backup.
2. Extract `Bushisa_Admin_Complete_Update.zip`.
3. Copy its `admin`, `php`, `css`, `js`, `assets` and `docs` folders into
   `C:\xampp\htdocs\Bushisa-figma\`. Allow Windows to replace matching files.
   Merge these folders with the existing folders; keep the rest of your project.
4. Start Apache and MySQL. Sign in with your existing administrator account.
5. Open `http://localhost/Bushisa-figma/admin/dashboard.php` and press Ctrl+F5.
   Use the sidebar to open Users, Confessions, Reports and Moderation.

No SQL import, database replacement, password change or fake records are needed.
Your local `config/db.php` is not part of this update.

## Application files changed in this update

| Folder | Files |
| --- | --- |
| `admin` | `dashboard.php`, `users.php`, `confessions.php`, `reports.php`, `moderation.php`, `report-evidence.php` |
| `php` | `admin_workspace.php`, `admin_content.php`, `admin_evidence.php` |
| `css` | `admin-reports.css`, `admin-users.css` |

The package also includes existing required admin fonts, decorative SVGs,
`css/admin-dashboard.css` and `js/admin-users.js` for consistent installation.
Only the Confessions navigation link changes in the existing Dashboard.
Users keeps its working directory and export behaviour; its mobile navigation
now reaches the dedicated admin Confessions page.

## Completed behaviour

- Confessions: real totals, published/flagged filters, literal text or exact #ID
  search, twenty records per page, full post details and links to related reports.
- Confession authors remain anonymous in the admin directory. Their real account
  identity is not joined to the post listing or details.
- Moderation: flagged posts, suspended accounts and recorded activity each have
  searchable, paginated queues. Detail views show related reports for context.
- Moderators and administrators may hide or restore posts after confirmation.
  Administrators may restore suspended non-staff accounts after confirmation.
  Staff accounts and self-restoration are protected. No permanent deletion action
  is exposed by these pages; the shared existing deletion helpers remain intact.
- CSRF checks, current database permissions, confirmation and a record-version
  check protect each action. State changes and audit records commit together.
  Failed audit writes roll back changes. Old or repeated decisions are rejected.
- Existing report decisions remain intact when a post/account is restored.
  Mark report decisions from Reports after reviewing their context.
- The Confessions removal statistic counts distinct post IDs in retained deletion
  audit entries. It does not claim to reconstruct deleted content or missing logs.
- Reports retains its working statistics, filters, search, decisions and safeguards.
  Supported evidence images now open through a protected endpoint; PDFs download.
- Mobile admin navigation follows the content at the bottom and scrolls sideways.
- The student Home, Find Match, Confessions and You page files are unchanged.

## Optional: existing report evidence files

Skip this section if your reports have no separate evidence attachments.
No file upload or evidence-submission flow is added in this update.

Private evidence storage must be outside Apache's document root. With standard
XAMPP paths the default folder is:

`C:\xampp\bushisa-private\evidence\`

An existing `reports.evidence_path` value such as `photo.png` refers to the file
`C:\xampp\bushisa-private\evidence\photo.png`. The legacy relative prefix
`uploads/evidence/` also resolves inside this private folder. Absolute file
references work only when the file lies inside the configured private folder.
For a custom server, `BUSHISA_EVIDENCE_DIR` can point to another private folder
outside the project and the web document root.

If a legacy attachment is currently in a public folder, arrange its move to
private storage after retaining a backup. This update does not move or rename
existing files or edit their database references automatically. Do not put private
evidence inside `htdocs` to make a link work.

Supported: PNG, JPEG, GIF, WebP and PDF, up to 10 MiB. File contents must match the
extension. Images are limited to 40 million pixels. PDFs download as attachments.
Each opening checks moderator/admin access and creates an audit entry.
Missing files, URLs, traversal, escaping symlinks, unsupported or mismatched files
are rejected without exposing their private paths.

## Validation performed

Tested with PHP 8.3, MariaDB 10.11 and Chromium. Browser widths: 1440, 1024, 750,
390 and 320 pixels. Both new pages, responsive navigation, search scroll position,
details, confirmation controls and protected image viewing passed browser checks.

The automated integration tests create their own uniquely named LOCAL disposable
databases and private files. They never connect to the application database.
They cover real query results, totals, pagination, literal search, XSS escaping,
anonymity, stale session roles, CSRF, confirmation, staff restrictions, tampered
and stale submissions, reversible actions, activity visibility, audit failure
rollback, private evidence handling and legacy confession-column compatibility.
Existing Reports and Users regression tests also pass, including account export.

Run source tests from the repository if needed:

- `python tests/admin_content_integration.py`
- `python tests/reports_management_integration.py`
- `python tests/users_management_integration.py`

Requires local MySQL/MariaDB, PHP 8 with PDO MySQL, mbstring, ctype and fileinfo,
and Python 3. `TEST_DB_HOST` must identify a local server. `TEST_DB_PORT`,
`TEST_DB_USER`, `TEST_DB_PASS`, `PHP_BIN` and `PHP_INI` can select a local runtime.

Optional browser checks require Playwright:
`BROWSER_TEST=tests/admin_content_layout.cjs python tests/admin_content_integration.py`.
`PLAYWRIGHT_MODULE` and `CHROMIUM_PATH` select existing tooling when needed.
