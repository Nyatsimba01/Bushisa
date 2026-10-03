# Bushisa — Structural Feasibility Report

> **Project:** Bushisa (Campus-crush)
> **Target Platform:** Student Dating & Matchmaking — National University of Science and Technology (NUST), Zimbabwe
> **Audit Date:** 03 October 2026
> **Scope:** Read-only structural analysis. No existing files were edited.

---

## 1  Current File Tree (As-Audited)

```
Bushisa/
├── .git/
├── .gitignore               ← configured (env, uploads, vendor)
├── README.md                ← "Campus-crush" — 2 lines
│
├── index.php                ← EMPTY
├── login.php                ← EMPTY
├── register.php             ← EMPTY
├── profile.php              ← EMPTY
├── discover.php             ← EMPTY
├── matches.php              ← EMPTY
├── chat.php                 ← EMPTY
├── confessions.php          ← EMPTY
├── logout.php               ← EMPTY
│
├── php/
│   ├── auth.php             ← EMPTY
│   ├── db.php               ← EMPTY
│   └── function.php         ← EMPTY
│
├── database/
│   └── bushisa.sql          ← EMPTY
│
├── js/
│   └── app.js               ← EMPTY
│
├── css/
│   └── style.css            ← EMPTY
│
├── uploads/
│   └── profile.photos       ← placeholder file
│
└── docs/                    ← EMPTY (this report now lives here)
```

**Total files:** 19 (excluding `.git/` internals)
**Files with implementation content:** 2 (`README.md`, `.gitignore`)
**Files that are empty shells:** 17

---

## 2  Feasibility Verdict

| Criterion | Rating | Rationale |
|---|---|---|
| **Naming & Intent** | ✅ Good | File names clearly map to core dating-app user journeys (discover → match → chat). |
| **Directory Separation** | ✅ Adequate | Front-end pages, back-end logic (`php/`), static assets (`css/`, `js/`), and data (`database/`) are already separated. |
| **Implementation Readiness** | ⚠️ Skeleton Only | Every source file is 0 bytes. There is no working code, schema, or UI to evaluate for runtime behaviour. |
| **Scalability to Production** | ❌ Not Feasible As-Is | Critical layers for security, data protection, and ethical safeguarding are entirely absent from the tree. |

> **Summary:** The skeleton demonstrates sensible *intent* — it outlines the right pages for a matchmaking flow. However, scaling this to a real student dating platform at NUST requires **significant structural additions** before any code is written. The gaps are listed below.

---

## 3  Gap Analysis — Security

> [!CAUTION]
> A dating platform stores **highly sensitive personal and behavioural data**. Security is not optional — it is a prerequisite for launch.

### 3.1  Missing files / functionality required

| # | Required File / Component | Purpose | Suggested Location |
|---|---|---|---|
| S-1 | `php/csrf.php` | Generate & validate CSRF tokens for every form (register, login, profile edit, chat send). | `php/` |
| S-2 | `php/sanitize.php` | Centralised input sanitisation (XSS, SQL injection, header injection). | `php/` |
| S-3 | `php/rate_limit.php` | Throttle login attempts, API calls, and chat messages to prevent brute-force and abuse. | `php/` |
| S-4 | `php/session_manager.php` | Secure session configuration: `HttpOnly`, `SameSite=Strict`, regeneration on privilege change, idle timeout. | `php/` |
| S-5 | `.env` (+ `.env.example`) | Store DB credentials, API keys, and secrets outside source code. `.gitignore` already excludes `.env`. | Root |
| S-6 | `php/password_policy.php` | Enforce minimum-strength passwords and use `password_hash()` / `password_verify()` with `PASSWORD_ARGON2ID`. | `php/` |
| S-7 | HTTPS enforcement | Redirect all HTTP to HTTPS; set `Strict-Transport-Security` header. Can live in `.htaccess` or `php/security_headers.php`. | Root or `php/` |
| S-8 | `php/upload_validator.php` | Validate MIME type, file size, and extension for profile photos. Re-encode images to strip EXIF metadata. | `php/` |
| S-9 | Content Security Policy | Add CSP, X-Frame-Options, X-Content-Type-Options headers to every response. | `php/security_headers.php` |

---

## 4  Gap Analysis — Data Capture & Database

### 4.1  Schema requirements (`database/bushisa.sql`)

The SQL file is empty. A minimum viable schema for a NUST dating app must include:

| Table | Key Columns | Notes |
|---|---|---|
| `users` | `id`, `nust_student_id`, `email` (@students.nust.ac.zw), `password_hash`, `created_at`, `is_verified`, `is_suspended` | Restrict registration to NUST student emails. |
| `profiles` | `user_id` (FK), `display_name`, `bio`, `gender`, `date_of_birth`, `faculty`, `year_of_study`, `profile_photo_path` | Core matchmaking dimensions. |
| `preferences` | `user_id` (FK), `preferred_gender`, `age_range_min`, `age_range_max`, `preferred_faculty` | Drive the discovery algorithm. |
| `swipes` | `swiper_id`, `swiped_id`, `direction` (like/pass), `created_at` | Record every interaction for matching logic. |
| `matches` | `user_a_id`, `user_b_id`, `matched_at` | Created when both swipe "like". |
| `messages` | `id`, `match_id` (FK), `sender_id`, `body`, `sent_at`, `is_read` | Chat history per match. |
| `confessions` | `id`, `author_id` (nullable for anonymity), `body`, `created_at`, `is_flagged` | Moderation-ready confession board. |
| `reports` | `id`, `reporter_id`, `reported_user_id`, `reason`, `evidence_path`, `status`, `created_at` | User safety reporting. |
| `audit_log` | `id`, `user_id`, `action`, `ip_address`, `timestamp` | Track sensitive operations (login, delete, report). |
| `consent_records` | `user_id`, `consent_type`, `granted_at`, `revoked_at` | POPIA/GDPR-aligned consent tracking. |

### 4.2  Missing data-layer files

| # | Required File | Purpose | Suggested Location |
|---|---|---|---|
| D-1 | `database/migrations/` directory | Version-controlled, sequential schema changes instead of a single monolithic SQL dump. | `database/migrations/` |
| D-2 | `database/seed.php` | Populate test data (demo profiles) for development. | `database/` |
| D-3 | `php/db.php` (implementation) | PDO connection with prepared statements, error handling, and connection pooling config. | `php/` (exists, needs content) |

---

## 5  Gap Analysis — Ethical Management & Safeguarding

> [!IMPORTANT]
> A campus dating app carries elevated ethical responsibility: users are students in a shared physical environment, and harassment or data leaks can have real-world safety consequences on campus.

### 5.1  Required files / functionality

| # | Required File / Component | Purpose | Suggested Location |
|---|---|---|---|
| E-1 | `terms_of_service.php` | Display enforceable Terms of Service; require acceptance at registration. | Root |
| E-2 | `privacy_policy.php` | Disclose what data is collected, how it is stored, and user rights (aligned with Zimbabwe's **Data Protection Act [Chapter 11:12]** and POPIA for cross-border users). | Root |
| E-3 | `community_guidelines.php` | Define prohibited behaviour (harassment, hate speech, catfishing, unsolicited explicit content). | Root |
| E-4 | `php/moderation.php` | Content moderation utilities: flag, review, and action (warn/suspend/ban). | `php/` |
| E-5 | `php/report_handler.php` | Process user-submitted reports against other users; notify moderators. | `php/` |
| E-6 | `php/consent_manager.php` | Record explicit consent for data processing; allow withdrawal at any time; cascade data deletion on withdrawal. | `php/` |
| E-7 | `php/anonymiser.php` | Implement right-to-erasure: anonymise or delete user data on account deletion. | `php/` |
| E-8 | `php/age_verification.php` | Verify that users are ≥ 18 before accessing the platform. | `php/` |
| E-9 | `admin/` directory | Admin dashboard for moderators to review reports, manage suspensions, and view audit logs. | `admin/` |
| E-10 | `docs/data_retention_policy.md` | Document how long data is kept and when it is purged. | `docs/` |
| E-11 | `php/block_handler.php` | Allow users to block other users, hiding all mutual visibility immediately. | `php/` |

---

## 6  Scalability Concerns

### 6.1  Architectural

| Concern | Detail | Recommendation |
|---|---|---|
| **Monolithic PHP** | All logic lives in flat `.php` files with no routing, autoloading, or MVC separation. | Introduce a front controller (`index.php` as router), models, and views — or adopt a lightweight framework (e.g. Slim, Laravel). |
| **Real-time chat** | PHP is request-response; `chat.php` cannot do real-time messaging natively. | Add WebSocket support (Ratchet, Socket.io + Node sidecar, or Pusher). |
| **Single JS file** | `app.js` will become unmaintainable as features grow. | Modularise: `auth.js`, `discover.js`, `chat.js`, `confessions.js`. |
| **No API layer** | Front-end pages are tightly coupled to back-end rendering. | Add a `api/` directory with JSON endpoints for AJAX / future mobile app support. |
| **No caching** | Every page hit queries the database. | Add Redis or Memcached integration; cache discovery results and unread-message counts. |

### 6.2  Operational

| Concern | Detail | Recommendation |
|---|---|---|
| **No CI/CD** | No GitHub Actions, no deployment scripts. | Add `.github/workflows/` with lint, test, and deploy pipelines. |
| **No testing** | Zero test files anywhere in the tree. | Add `tests/` directory with PHPUnit or Pest tests. |
| **No logging** | No error or access logging beyond PHP defaults. | Add `logs/` directory (gitignored) and a `php/logger.php` utility. |
| **Upload storage** | `uploads/profile.photos` is a flat file, not a directory structure. | Restructure to `uploads/{user_id}/` with size/type validation. |
| **No environment separation** | No distinction between dev, staging, and production configs. | Use `.env` files per environment with a config loader. |

---

## 7  Recommended Augmented File Tree

Below is the minimum additional structure needed (new items marked with `[NEW]`):

```
Bushisa/
├── .env.example                          [NEW]
├── .htaccess                             [NEW]  ← HTTPS redirect, URL rewriting
│
├── admin/                                [NEW]
│   ├── dashboard.php                     [NEW]
│   ├── reports.php                       [NEW]
│   └── moderation.php                    [NEW]
│
├── api/                                  [NEW]
│   ├── matches.php                       [NEW]
│   ├── messages.php                      [NEW]
│   └── swipe.php                         [NEW]
│
├── php/
│   ├── auth.php
│   ├── db.php
│   ├── function.php
│   ├── csrf.php                          [NEW]
│   ├── sanitize.php                      [NEW]
│   ├── rate_limit.php                    [NEW]
│   ├── session_manager.php               [NEW]
│   ├── password_policy.php               [NEW]
│   ├── upload_validator.php              [NEW]
│   ├── security_headers.php              [NEW]
│   ├── moderation.php                    [NEW]
│   ├── report_handler.php                [NEW]
│   ├── consent_manager.php               [NEW]
│   ├── anonymiser.php                    [NEW]
│   ├── age_verification.php              [NEW]
│   ├── block_handler.php                 [NEW]
│   └── logger.php                        [NEW]
│
├── database/
│   ├── bushisa.sql
│   ├── seed.php                          [NEW]
│   └── migrations/                       [NEW]
│
├── js/
│   ├── app.js
│   ├── auth.js                           [NEW]
│   ├── discover.js                       [NEW]
│   ├── chat.js                           [NEW]
│   └── confessions.js                    [NEW]
│
├── tests/                                [NEW]
│
├── logs/                                 [NEW]  ← gitignored
│
├── terms_of_service.php                  [NEW]
├── privacy_policy.php                    [NEW]
├── community_guidelines.php              [NEW]
│
├── docs/
│   ├── structural_feasibility_report.md  ← this file
│   └── data_retention_policy.md          [NEW]
│
└── .github/workflows/                    [NEW]
    └── ci.yml                            [NEW]
```

---

## 8  Conclusion

The Bushisa skeleton demonstrates correct **domain thinking** — its file names map cleanly to the core user journeys of a dating app (register → discover → match → chat → confess). However, it is a **naming-only scaffold with zero implementation**.

To scale this into a production-grade student dating platform for NUST, the project requires:

1. **17 new files** for security hardening (§3).
2. **10 new database tables** and a migration framework (§4).
3. **11 new files** for ethical management, moderation, and legal compliance (§5).
4. **Architectural restructuring** toward MVC, real-time chat, and API separation (§6).

> [!WARNING]
> **No code should be deployed to real students until sections 3, 4, and 5 are fully addressed.** A data breach or harassment incident on a campus dating platform carries legal, reputational, and personal-safety consequences.

---

*Report generated by structural audit — no existing files were modified.*
