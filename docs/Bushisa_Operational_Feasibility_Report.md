# Bushisa Operational Feasibility Study

Prepared: 5 October 2026  
Scope: frontend/backend contract feasibility after applying the frontend layer from `Bushisa_Figma_Copilot_Spec.md` and `Bushisa_Frontend_Prompt_Workflow.md`.

## Executive Summary

Bushisa is feasible as a PHP/MySQL web application, but it is not operationally complete yet. The frontend layer can now render the requested product surfaces, but several product requirements remain blocked by missing or inconsistent backend contracts.

The main operational risk is not the visual frontend. It is contract fragmentation:

- Two schema files define different column names and different product models: `database/bushisa.sql` and `sql/schema.sql`.
- Root pages, `public/register.php`, helper modules and API files implement overlapping behavior differently.
- Several helper files exist for consent, blocking, reporting, anonymisation and moderation, but many are not exposed through user-facing routes or API endpoints.
- The frontend specification requires server-owned states for verification, three image slots, scored Find Match, notifications, DM approval, unsend, confession references and view ranking. These are not fully represented in the active schema/API.

Current feasibility rating:

| Area | Feasibility | Operational Status |
| --- | --- | --- |
| Visual frontend shell | High | Implemented as server-rendered PHP/CSS with responsive navigation. |
| Authentication/login | Medium | Login works against existing user records, but verification is not real. |
| Student identity | Medium | Lowercase UI now exists, but official NUST grammar and migration remain unresolved. |
| Discovery/Home | Medium | Candidate feed and mutual-like matching exist, but gallery, notification and consent semantics are incomplete. |
| Find Match scoring | Low until backend work | UI can show the flow, but no scored questionnaire contract exists. |
| Messaging | Medium-low | Match-based chat exists, but required DM approval and unsend are missing. |
| Confessions | Medium-low | Posting/feed exists, but view ranking, references and anonymity hardening need work. |
| Media quota | Low until schema work | One profile photo exists; required three-slot aggregate quota does not. |
| Notifications | Low | No notification table/API is present. |
| Safety/reporting/blocking | Medium | Helpers exist, but routes, schema migration and UI integration are incomplete. |
| Account deletion | Medium-low | Anonymiser helper exists, but no user-facing route with recent-auth flow exists. |

## Evidence Reviewed

Primary product/design specifications:

- `docs/Bushisa_Figma_Copilot_Spec.md`
- `docs/Bushisa_Frontend_Prompt_Workflow.md`

Frontend and route files:

- `login.php`
- `register.php`
- `discover.php`
- `matches.php`
- `confessions.php`
- `profile.php`
- `chat.php`
- `community_guidelines.php`
- `privacy_policy.php`
- `terms_of_service.php`
- `php/frontend.php`
- `css/style.css`
- `js/app.js`
- legacy JS files in `js/`

Backend helpers and APIs:

- `php/auth.php`
- `php/db.php`
- `php/session_manager.php`
- `php/csrf.php`
- `php/upload_validator.php`
- `php/block_handler.php`
- `php/consent_manager.php`
- `php/report_handler.php`
- `php/moderation.php`
- `php/anonymiser.php`
- `php/rate_limit.php`
- `api/swipe.php`
- `api/matches.php`
- `api/messages.php`

Schema/admin variants:

- `database/bushisa.sql`
- `sql/schema.sql`
- `public/register.php`
- `admin/dashboard.php`
- `admin/reports.php`
- `admin/moderation.php`

## Current Frontend/Backend Contract Map

| Requirement | Current frontend state | Current backend state | Gap |
| --- | --- | --- | --- |
| Four-item navigation | Implemented: Home, Confessions, Find Match, You. | Routes exist as `discover.php`, `confessions.php`, `matches.php`, `profile.php`. | Route names do not match product language; acceptable if documented. |
| Auth login | Server-rendered login form posts to `login.php`. | `attempt_login()` checks email/password and suspension. | No email verification gate despite `is_verified`. |
| Signup | Server-rendered register form posts to `register.php`. | `register_user()` inserts `is_verified = 1`. | Email ownership is not proven; student ID grammar still broad. |
| Lowercase student number | UI lowercases and backend stores lowercase now. | Existing data may be uppercase; `public/register.php` still uppercases. | Need canonical migration and removal/redirect of stale public route. |
| Home matches row | Uses server `matches` rows. | Mutual likes create rows in `matches`. | Match row still links directly to chat because no request/approval route exists. |
| Home discovery cards | Uses `discover.php` candidate query. | Query excludes swiped users and now excludes matches. | API and legacy JS discovery path are inconsistent; no extra discovery images. |
| Like/pass | Static buttons post to `api/swipe.php`. | `api/swipe.php` creates swipes and mutual matches. | No CSRF validation in `api/swipe.php` despite JS sending token; blocking not checked in API. |
| Notifications | Bell panel renders honest backend-pending state. | No notification table/API. | Need notification model, unread/read state, deep links and privacy filtering. |
| Find Match | UI shows questionnaire shell and backend blocker notice. | No questionnaire answers, scoring, result API or pagination. | Must design server scoring contract before enabling. |
| Profile/You | Editable profile and preferences form exists. | `update_profile()` supports display name, bio, faculty, year, one photo. | Required three image slots and 5 MB aggregate quota are missing. |
| Verified email immutable | UI renders email readonly. | Server update path does not expose email editing. | Good baseline; still needs verified-state truthfulness. |
| Notification preferences | UI shows disabled backend-pending controls. | No preferences schema for notification categories. | Need table/API and browser permission separation. |
| Chat | Server-rendered conversation posts to `chat.php`. | `api/messages.php` and `chat.php` check match membership. | DM request approval, revocation and unsend missing. |
| Confessions feed | Server-rendered feed and composer exist. | `confessions.php` supports two schema variants. | No view ranking, references, opaque reference IDs, owner management route or report endpoint. |
| Reports | Safety links exist; admin report helper exists. | `submit_report()` reports users only. | No public report route/API for profiles/photos/confessions/conversations; no catfish reason enum. |
| Blocking | UI copy mentions blocking gap. | `block_handler.php` helper exists and removes matches. | No route/API; schema comment not migrated into SQL files. |
| Account deletion | UI disabled action. | `anonymiser.php` helper exists. | No recent-auth user route, CSRF form or retention UX. |
| Admin moderation | Helper and admin logic exist. | Admin pages still have placeholder frontend comments. | Admin UI not rendered; schema assumptions differ. |

## Major Operational Gaps

### 1. Schema Fragmentation

Evidence:

- `database/bushisa.sql` uses `users.email`, `users.nust_student_id`, `profiles.profile_photo_path`, `matches.user_a_id/user_b_id`, `messages.body/sent_at`, and `confessions.author_id/body`.
- `sql/schema.sql` uses `users.student_email`, `profiles.photo_1_url/photo_2_url`, `matches.user_one_id/user_two_id`, `messages.message_text/created_at`, and `confessions.user_id/content`.
- Runtime PHP files contain compatibility checks for both variants, which reduces crashes but hides the lack of a single source of truth.

Operational impact:

- Queries become fragile.
- Migrations cannot be trusted.
- Frontend cannot know which fields are authoritative.
- Test fixtures may pass under one schema and fail under another.

Required decision:

- Pick one canonical schema and create migrations from known variants.

### 2. Verification and Student Identity Are Not Operationally Real

Evidence:

- `php/auth.php` inserts `is_verified = 1` during registration.
- `public/register.php` also inserts `is_verified = 1` and uppercases student IDs.
- The product spec requires institution email proof before showing verified identity.
- The official student-number grammar is still unresolved; the current backend accepts broad lowercase alphanumeric IDs of length 4 to 20 after the frontend pass.

Operational impact:

- A syntactically valid email can appear verified without ownership proof.
- Duplicate accounts may exist through legacy uppercase paths.
- Public registration can bypass the corrected root registration path.

Required decision:

- Confirm student-number grammar and email verification policy, then migrate data and delete/redirect stale registration paths.

### 3. Media Model Does Not Meet the Three-Slot 5 MB Requirement

Evidence:

- `profiles` currently has one `profile_photo_path` in `database/bushisa.sql`.
- `sql/schema.sql` has `photo_1_url/photo_2_url`, but not the required one profile plus two discovery slots.
- `php/upload_validator.php` enforces 5 MiB per upload, not 5 MB aggregate per account.
- `profile.php` updates only one uploaded photo.

Operational impact:

- Users cannot manage the required three images.
- Server cannot prevent a fourth permanent image.
- Replacement cleanup and aggregate quota are not transactional.

Required decision:

- Introduce a normalized `profile_images` table or explicit three columns with slot enum and stored-byte accounting.

### 4. Find Match Has No Server-Owned Questionnaire or Scoring Contract

Evidence:

- `matches.php` now displays a questionnaire shell and current established matches.
- `discover.php` still uses preference filters and `ORDER BY RAND()`.
- No table stores questionnaire answers.
- No endpoint returns compatibility scores, scoring formula version, stable pagination or candidate action state.

Operational impact:

- Any frontend percentage would be fake.
- Search cannot be deterministic or auditable.
- UI cannot distinguish candidate, liked candidate, established match and approved conversation from a single result contract.

Required decision:

- Define questionnaire questions, answer storage, scoring formula and `GET /api/find_match.php` response schema.

### 5. DM Consent Lifecycle Is Missing

Evidence:

- `api/messages.php` and `chat.php` only check match participation.
- `php/consent_manager.php` records generic consent but is not pair-specific DM approval.
- No `message_requests` or `conversation_permissions` table exists.

Operational impact:

- The frontend cannot truthfully enforce “a match is not chat permission.”
- A matched user can send directly if they know the match ID.
- Pending, declined, revoked and approved states cannot be represented server-side.

Required decision:

- Add pair-level DM request/approval state and enforce it on all message reads and writes.

### 6. Message Unsend Is Not Supported

Evidence:

- `messages` has no `deleted_at`, `unsent_at`, `unsent_by`, or visible marker fields.
- No unsend endpoint exists.
- `api/messages.php` only handles GET and POST send.

Operational impact:

- Sender-only unsend cannot be implemented truthfully.
- Cached/history views cannot synchronize removals.

Required decision:

- Add an unsend policy and implement a server mutation that replaces body visibility with an unsent marker for both participants.

### 7. Notifications Are Not Implemented

Evidence:

- No `notifications` table or notification API exists.
- The Home bell currently shows backend-pending empty content.
- The specification requires categories for matches, confession references, profile views, milestones, DM requests/approvals and unread messages where supported.

Operational impact:

- Notification preferences cannot save.
- Unread/read state and deep links cannot be authorized.
- Anonymous-confession notification privacy cannot be guaranteed.

Required decision:

- Create notification event model and central notification writer functions.

### 8. Confessions Need View Ranking, References and Stronger Anonymity

Evidence:

- `confessions.php` orders by `created_at DESC`.
- No view table or view deduplication exists.
- No confession reference table/column exists.
- `sql/schema.sql` stores `anon_handle` directly on confessions; `database/bushisa.sql` joins profile handle from author.
- `js/confessions.js` references `api/confessions.php`, which is absent.

Operational impact:

- “Trending” by highest views cannot be implemented.
- References cannot be independent posts.
- Anonymous ownership may leak if payloads or joins expose user IDs outside protected server code.

Required decision:

- Add view tracking, references and DTO builders that never expose real owner IDs.

### 9. Reporting, Catfish Handling and Blocking Are Partial

Evidence:

- `php/report_handler.php` only supports reports against users.
- Reports schema differs between schema files; one variant supports `confession_id`, the other does not.
- `php/block_handler.php` assumes a `blocks` table but the SQL is only present as a comment.
- No user-facing report/block APIs are wired.

Operational impact:

- Report profile/photo/confession/conversation menus cannot submit.
- Catfish report reason is not a controlled private moderation state.
- Blocked users may still appear unless every route checks a table that may not exist.

Required decision:

- Migrate `blocks`, normalize reports, expose CSRF-protected report/block endpoints, and enforce block filters everywhere.

### 10. Account Deletion Is Not User-Operable

Evidence:

- `php/anonymiser.php` can anonymise/hard delete users.
- `profile.php` shows delete disabled.
- No recent-auth confirmation route exists.
- Privacy policy says deleted account data is anonymised within 30 days, but no user-triggered workflow records deletion request timing.

Operational impact:

- Users cannot exercise deletion from the app.
- Retention copy and actual behavior may diverge.

Required decision:

- Add account deletion request/confirmation endpoint, session revocation and retention/audit records.

### 11. Session Cookie Defaults May Break Local HTTP Development

Evidence:

- `php/session_manager.php` always sets secure cookies.

Operational impact:

- On plain `http://localhost`, cookies may not persist, causing login/session confusion.

Required decision:

- Keep secure cookies in production but allow explicit local development override through environment configuration.

### 12. Legacy JS and Public Routes Are Out of Contract

Evidence:

- `public/register.php` has its own UI, uppercase ID normalization, weaker password rules and direct `is_verified = 1`.
- `js/discover.js` references `api/discover.php`, which does not exist, and submits `swiped_id` while `api/swipe.php` expects `target_id`.
- `js/auth.js` submits to `php/auth.php` as if it were an endpoint, but root auth pages are server-post forms.
- `js/confessions.js` references absent `api/confessions.php`.

Operational impact:

- Accidentally loading legacy JS or public routes can create broken UX or bypass corrected policy.

Required decision:

- Remove, rewrite or quarantine stale JS/public routes.

## Feasibility Roadmap

### Phase A: Stabilize Contracts

Deliverables:

- Canonical schema and migration plan.
- Route/API inventory.
- Removal or redirect of stale duplicate paths.
- Integration test database seed.

Acceptance:

- Every runtime query targets the canonical schema without compatibility guessing.
- `public/register.php` cannot bypass root registration.
- PHP syntax and endpoint smoke tests run in CI.

### Phase B: Identity and Account Foundations

Deliverables:

- Confirmed student-number grammar.
- Email verification token flow.
- Lowercase migration and unique index/collation protection.
- Recent-auth helper.
- Account deletion/anonymisation route.

Acceptance:

- No new user is verified until proving email ownership.
- Upper/lowercase student-number duplicates are impossible.
- Deletion revokes the session and removes the user from discovery.

### Phase C: Media and Profile Completion

Deliverables:

- Three image slots.
- Aggregate 5 MB quota.
- Replacement cleanup and transaction-safe quota checks.
- Server MIME/dimension validation and metadata stripping.

Acceptance:

- Exactly one profile image and two discovery images can persist.
- Concurrent replacements cannot exceed quota.
- Failed replacement keeps the previous image visible.

### Phase D: Discovery, Find Match and Notifications

Deliverables:

- Questionnaire answer schema.
- Scoring formula and result API.
- Stable pagination/tie-breakers.
- Notification events/preferences.
- Profile view tracking without anonymous-confession identity leakage.

Acceptance:

- Results return real descending scores.
- Notifications are authorized, deduplicated and privacy-filtered.

### Phase E: Consent Messaging and Safety

Deliverables:

- DM request lifecycle.
- Message send/read enforcement.
- Revoke/block/unmatch behavior.
- Sender-only unsend.
- Report/block APIs and catfish reason.

Acceptance:

- Forged message sends fail without approval.
- Blocked/revoked users cannot read or send.
- Unsend removes body visibility for both participants.

### Phase F: Confession Completion

Deliverables:

- View ranking and deduplication.
- Reference-in-new-confession workflow.
- Anonymous DTOs.
- Confession reporting and owner management.

Acceptance:

- Feed ranks by view count with deterministic ties.
- References do not expose original author identity.
- Deleted/moderated references show neutral unavailable previews.

## Prompt Engineering Pack

Use these prompts sequentially. Each prompt assumes the two specification files remain attached and authoritative.

### Prompt 1: Canonical Contract Audit and Schema Unification

```text
Read docs/Bushisa_Frontend_Prompt_Workflow.md, docs/Bushisa_Figma_Copilot_Spec.md, database/bushisa.sql, sql/schema.sql, all root PHP routes, api/*.php, php/*.php, admin/*.php and public/register.php.

Produce a canonical schema proposal for the active Bushisa app. Resolve column-name conflicts between users.email/student_email, matches.user_a_id/user_one_id, messages.body/message_text, profiles.profile_photo_path/photo_1_url/photo_2_url and confessions.author_id/user_id/body/content.

Implement a migration path that preserves existing data, adds missing required tables, and removes compatibility guessing from runtime queries only after migrations are in place. Do not drop data without an explicit rollback plan. Redirect or remove public/register.php so there is one registration path.

Acceptance: all runtime queries target one schema; root registration is the only user signup path; tests or smoke scripts prove login, register, discovery, matches, messages and confessions use the canonical columns.
```

### Prompt 2: Verified Identity and Student Number Completion

```text
Implement server-enforced NUST identity verification. Confirm the final student-number grammar from the product owner; until confirmed, keep the current broad validation but do not label it official. Normalize student numbers to lowercase in UI, payload and storage. Add a collision-safe migration for existing uppercase records and enforce case-insensitive uniqueness.

Replace automatic is_verified=1 with an email verification token flow for @students.nust.ac.zw addresses. Add verification pending, resend, expired token and verified states. Prevent unverified users from entering discovery if product policy requires it. Keep generic auth errors and rate limits.

Acceptance: registration creates an unverified account; email proof flips is_verified; uppercase and lowercase variants cannot create duplicates; forged verification flags cannot be user-edited.
```

### Prompt 3: Three-Slot Media and Aggregate Quota

```text
Implement the required Bushisa media model: one profile image and two discovery images, three total. Add server-side storage for slot type, path, MIME, dimensions, byte size, created_at and updated_at. Enforce an aggregate account quota of 5 MB or the product-approved exact value. Replacement must subtract the old slot, include derivatives, be transactional and clean orphan files on success/failure.

Update profile.php so each slot can preview, replace and remove images while preserving the current image until the server commits. Keep client compression optional only; server validation remains authoritative. Reject SVG/executable uploads unless a safe policy is explicitly adopted.

Acceptance: fourth images are impossible; concurrent uploads cannot exceed quota; failed replacement preserves current images; UI shows accurate bytes used and remaining.
```

### Prompt 4: Find Match Questionnaire and Scoring API

```text
Create a server-owned Find Match flow. Add tables for questionnaire questions, answer options, user answers and scoring formula version. Implement a POST endpoint to save answers and a GET endpoint that returns scored candidates with score 0-100, explanation label, permitted actions, stable pagination and deterministic tie-breakers.

Exclude self, blocked, deleted, suspended and ineligible users. Keep scored candidates distinct from likes, established matches and approved conversations. Return action states: like, liked, request_message, pending, declined, approved_open_chat, revoked, unavailable.

Update matches.php to enable the Search action only against this real API. Do not generate random percentages.

Acceptance: results are descending by server score; changed answers invalidate stale results; pagination is stable; action states come from the server.
```

### Prompt 5: Notifications and Preferences

```text
Design and implement a notification system for Bushisa. Add notifications table(s) with recipient_id, category, title, body, deep_link, read_at, created_at, dedupe_key and payload fields that never expose private anonymous-confession ownership. Add notification preferences per user and category.

Create server writers for first verified onboarding, first match, first approved conversation, confession references, profile views, DM requests/approvals and unread messages where supported. Build GET/POST APIs for listing notifications, marking read and saving preferences. Keep browser push permission separate from in-app preferences.

Update the Home bell panel and You preferences to use the real contracts.

Acceptance: unread/read state works; deep links are authorization-checked; anonymous notifications do not reveal owner IDs; duplicate milestones are suppressed.
```

### Prompt 6: DM Request Approval and Conversation Enforcement

```text
Implement pair-level DM approval. Add message_requests or conversation_permissions with requester_id, recipient_id, match_id, status, requested_at, responded_at, revoked_at and cooldown fields. A request must only be possible for an established active match. Approval enables messaging for the pair according to the chosen policy; decline, revoke, block or unmatch prevents sending.

Enforce approval in chat.php and api/messages.php for reads and writes. Do not rely on disabled UI. Add request, approve, decline, revoke and status endpoints with CSRF and rate limits. Update Home match avatars and Find Match actions to open request/permission states before chat.

Acceptance: forged send without approval fails; pending/declined/revoked/blocked states cannot send; repeated requests are deduplicated and cooldowns apply.
```

### Prompt 7: Message Unsend

```text
Implement sender-only unsend. Add message fields such as unsent_at, unsent_by and moderation_retained_body or an equivalent evidence-retention policy. Add a CSRF-protected endpoint that allows only the sender to unsend within the product-approved policy window. Replace message body visibility with "Message unsent" for both participants while preserving restricted moderation evidence if required.

Update chat rendering, API responses, polling/history and cached views to show unsent markers consistently. Explain that recipients may already have read or copied messages.

Acceptance: non-senders cannot unsend; unsent messages lose visible body in all history views; receiver sees the marker; moderation retention follows policy.
```

### Prompt 8: Confession Views, References and Anonymous DTOs

```text
Complete anonymous confessions. Add deduplicated view tracking and default feed ordering by view_count DESC, created_at DESC, id DESC unless a rolling window is selected. Add opaque reference support so a new confession can reference another confession with a safe excerpt preview. Deleted/moderated references must show neutral unavailable text.

Build api/confessions.php or remove legacy JS that expects it. Ensure every confession DTO excludes real user_id, student email, student number and profile links. Anonymous handles are nonclickable. Add report and owner-management actions without disclosing ownership publicly.

Acceptance: feed ranking is server-owned; rendering does not inflate views; references create independent posts; matched users cannot resolve authors.
```

### Prompt 9: Reporting, Blocking and Catfish Safety

```text
Normalize safety operations. Add/migrate blocks table and reports table so reports can target users, photos, confessions and conversations. Include a controlled private catfish/impersonation reason. Expose CSRF-protected APIs for report, block, unblock and unmatch. Enforce block filtering in discovery, Find Match, matches, notifications, chat reads/writes and direct routes.

Update profile cards, confessions and conversations to submit real reports and show receipt states. Never show public accusation badges.

Acceptance: blocked users disappear consistently; reporting duplicates are controlled; catfish reports stay private; direct blocked requests fail server-side.
```

### Prompt 10: Account Deletion and Retention

```text
Implement user-facing account deletion using php/anonymiser.php. Add recent-auth confirmation, CSRF, clear retention copy, session revocation, media cleanup and removal from discovery. Record deletion/anonymisation events in audit logs. Align privacy_policy.php copy with actual retention behavior.

Acceptance: deletion request cannot be forged; current session ends; user no longer appears in discovery/matches; media cleanup is verified; moderation/audit retention is documented accurately.
```

### Prompt 11: Admin UI and Moderation Operations

```text
Render admin/dashboard.php, admin/reports.php and admin/moderation.php using the Bushisa frontend primitives or a restrained admin variant. Keep admin UI separate from the student mobile-first shell where appropriate. Ensure moderator authorization uses the active role values in the canonical users table.

Fix schema assumptions in moderation helpers for both report targets and confession author/body columns after canonical schema migration.

Acceptance: moderators can review reports, suspend users, unflag/delete confessions and unsuspend users through CSRF-protected forms; non-moderators are denied.
```

### Prompt 12: Runtime QA and CI Hardening

```text
Add operational checks for Bushisa. Ensure PHP CLI is available in the dev environment or CI. Add php -l over all PHP files, endpoint smoke tests against a seeded test database, and targeted integration tests for registration, verification, discovery, swipes, matches, DM approval, messaging, unsend, confessions, reports, blocks and deletion.

Add a browser smoke test for the four primary pages at 320, 390, 768, 1024 and 1440 CSS px. Verify no obsolete Home Views/inbox/DM navigation copy appears. Record unsupported screenshot alerts honestly.

Acceptance: CI fails on syntax errors, missing endpoints, schema drift and forbidden stale product copy.
```

## Recommended Immediate Next Steps

1. Install or configure PHP CLI locally so `php -l` and smoke tests can run.
2. Decide the canonical schema and write migrations before adding more UI behavior.
3. Remove or redirect `public/register.php`.
4. Implement email verification and lowercased student-number uniqueness.
5. Add DM approval enforcement before presenting chat as complete.
6. Build notification and Find Match APIs only after schema consolidation.
7. Replace legacy JS files that target absent endpoints or keep them unloaded and documented as deprecated.

## Completion Definition

Bushisa should not be marked operationally complete until:

- One canonical schema is active.
- All frontend states map to real server states.
- Verification, media quota, scoring, notifications, DM approval, unsend, confession references, reports, blocks and deletion have server enforcement.
- Anonymous data transfer objects are audited for leaks.
- Stale public routes and legacy JavaScript contracts are removed or repaired.
- Automated syntax and smoke tests run in CI.

