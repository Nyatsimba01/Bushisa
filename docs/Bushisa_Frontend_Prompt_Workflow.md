# Bushisa — responsive frontend prompt engineering workflow

Prepared: 4 October 2026  
Target repository: https://github.com/Nyatsimba01/Bushisa  
Inspected source commit: `706718b06994c7974cf27235437df08194db0748`

## Purpose and use

This document is a sequential, copy-ready workflow for an AI coding assistant to build Bushisa's responsive frontend against the existing backend. It is a specification and implementation prompt pack, not a claim that the features are already implemented. Run the master prompt with each numbered prompt in order. Carry the preceding phase's artifacts into the next phase. Complete its acceptance checks before proceeding; report genuine backend blockers instead of simulating success.

The five supplied mockups establish the visual direction: dark plum surfaces, near-black cards, pink accents/gradients, rounded controls and photo-led discovery. Preserve that direction while applying the changes below. Use **Bushisa** in every screen, document title, notification and accessible label. Remove the misspelling found in the mockups.

## Verified repository context

A static source inspection found PHP/MySQL infrastructure, partial frontend assets and multiple schema/registration variants. Runtime behavior and database deployment have not been verified.

| Inspected location | Observation | Required follow-up |
| --- | --- | --- |
| `register.php`, `public/register.php`, `php/auth.php` | Student IDs are uppercased; `php/auth.php` accepts broad alphanumeric IDs of length 4–20. | Audit active signup path; enforce the intended lowercase NUST format consistently. |
| `php/auth.php`, `public/register.php` | Registration inserts `is_verified = 1`. | Check actual email proof flow; syntactically valid email alone must not be presented as verified identity. |
| `php/upload_validator.php`, `profile.php` | Default limit is 5 × 1024 × 1024 bytes per upload; profile update handles one image. | Introduce a shared three-slot account quota, replacement cleanup and two discovery image slots. |
| `api/messages.php` | Match participation is checked, but no DM-request approval gate appears in this endpoint. | Require approved conversation permission and block checks on server operations. |
| `discover.php` | Candidate query uses `ORDER BY RAND()` and preference filters. | Add an agreed questionnaire scoring contract and descending percentage results. |
| `confessions.php` | Feed orders by creation time; author handle is joined from profiles. | Add view ranking and opaque confession references; prevent identity leakage. |
| `js/confessions.js` | References `api/confessions.php`, which was absent from the inspected file inventory. | Resolve the missing API contract; do not wire a production UI to an invented endpoint. |
| `database/bushisa.sql`, `sql/schema.sql` | Different field names and structures exist. | Identify the active schema and migration path before frontend integration. |
| `css/style.css`, `js/*.js`, `public/register.php` | Frontend assets and partial markup already exist; several root pages end with an HTML placeholder. | Reuse working assets and progressive enhancement where practical. |

## Master prompt — attach to every phase

```text
You are implementing Bushisa, a NUST student discovery, matching and anonymous
confession web app. Treat Bushisa_Frontend_Prompt_Workflow.md as the product
specification. Read repository instructions first. Inspect the active source,
installed versions, schema, routes, authentication and existing UI before editing.
Preserve working backend behavior and use its actual contracts. Do not assume
all requested behavior exists just because a similarly named file exists.

Work in small, reviewable increments. Reuse the PHP/CSS/JavaScript stack unless
an evidenced limitation justifies another choice. Never migrate frameworks as
a side effect of visual work. When choosing dependencies or browser APIs, check
current official documentation and browser support, record the date and source,
and use versions compatible with the project. Do not choose an unpinned 'latest'.

Separate confirmed behavior, proposed contracts, product assumptions and blockers.
Never invent endpoints, verification, notifications, match percentages or screenshot
events. Development fixtures must be explicitly marked and excluded from production.
Authorization, anonymity, quotas and immutable email require server enforcement.

Build mobile-first, accessible interfaces with loading, empty, error, offline,
permission-denied and successful states. Escape user content. Preserve CSRF/session
protections. Do not put secrets, student numbers or emails in public cards or logs.

For this phase return: findings with source locations; changed files and rationale;
any API/schema changes needed; validation evidence; unresolved decisions; and the
handoff for the next phase. Fix failures introduced by this phase before proceeding.
```

## Product rules carried across all phases

### Navigation and page chrome

Four destinations appear in this order. Put each icon **above** its label. Use `Find Match` as the concise visible label for the requested Search/Find Match destination. Keep all four destinations available on desktop as well; a sidebar is acceptable there.

| Destination | Content | Header behavior |
| --- | --- | --- |
| Home | Existing matches row, followed by nonmatched discovery profiles with bio and like action | Bushisa title and notification bell only; no Views button or inbox subtitle |
| Confessions | Feed immediately, highest view count first; small bottom-right plus opens composer | No Bushisa title, Views button, bell or match bubbles; a modest contextual heading is acceptable |
| Find Match | Short questionnaire → Search → results sorted by match percentage descending | Contextual Find Match heading; no reused inbox/match-bubble header |
| You | Edit profile, three images, bio, preferences, account and privacy settings | No Bushisa title, Views button, bell or match bubbles |

Chat opens from an eligible match or notification; it is not a fifth bottom-nav item. A conversation screen may show the counterpart's name, back button and safety menu.

### Identity and storage

- A single designated profile picture and at most two additional discovery photos: **three images total**, not three plus an avatar. Replacing a slot must not create another permanent image.
- At most **5 MB combined per account** for those images. Proposed interpretation: decimal 5,000,000 bytes. The repository currently uses binary MiB per upload; resolve this difference explicitly and expose a single shared quota constant. Account for persistent thumbnails/derivatives within the allocation; remove temporary uploads on success/failure. The UI may display rounded MB while enforcement uses exact bytes.
- Student-number alphabetic characters must be lowercase. Example supplied by the owner: `n02531234f`; `25` is admission year 2025. Do not assume the suffix is always `f`.
- Proposed grammar from that example: `n0` + two admission-year digits + five serial digits + one lowercase ASCII letter, i.e. `^n0[0-9]{2}[0-9]{5}[a-z]$`. This is an **inferred candidate**, not a verified official NUST rule. Mockup/repository examples have different digit counts. Check the serial length, allowed suffix set, year range and older valid IDs against the authoritative backend/domain requirements before finalizing it.
- Student email is immutable in You and must remain immutable on the server. Other user-owned profile data should be editable. Student-number corrections, if permitted, must preserve uniqueness and repeat identity verification; roles, verification flags and moderation status are never user-editable.

### Match, like and messaging semantics

A like, a scored discovery result, an established match and an approved DM request are different states. Establish what the backend currently calls a match. Proposed product interpretation: Find Match returns scored candidates; only server-established matches appear in Home's match row and are eligible for a DM request. If questionnaire results themselves establish matches, implement that explicitly on the server. Never let the frontend promote a candidate to a match.

A user can DM only an established match **after the recipient approves the request**. Pending, declined, revoked, unmatched, suspended or blocked relationships cannot send. Like actions do not imply messaging consent. A scored candidate can always receive a like; show a request action only when the server says it is eligible, otherwise explain how a match is established. This resolves the requested Find-page request action without bypassing the match-only rule.

### Anonymous confessions

Display the author's anonymous username, never a clickable profile link. Even a match cannot resolve that name to a profile. Responses are **new confessions referencing another confession**, not comments, direct replies or DMs. Mention notifications refer to the confession/alias without revealing its owner. Do not offer real-user tagging through anonymous names.

### Requested safety and account actions

Provide logout, account deletion, picture/confession reporting, a catfish report reason, blocking/unmatching and unsending one's own messages. Screenshots taken through OS shortcuts, browser tools, another app or another device cannot be reliably detected by a standard website. Preserve the requested feature as a capability-dependent enhancement: alert only from a genuine supported event source; show honest availability information. Do not infer screenshots from keyboard presses, page blur or visibility changes. Never promise screenshot prevention or reliable web-wide alerts.

## Phase 1 — source and contract audit

```text
Audit the active Bushisa application before implementing UI. Locate deployment
entry points, applicable repository instructions, active database schema, PHP
session/CSRF helpers, asset loading, auth redirects and actual endpoint handlers.
Compare root registration with public/register.php and identify the live path.
Check source findings recorded in this document against the current commit.

Build a requirements-to-contract matrix for onboarding, verified identity,
profiles, three-slot media/quota, likes, matches, DM requests and approval,
chat/unsend, confession views/references, notifications, reports, blocks,
logout and account deletion. For each give the exact existing route, request,
response, permissions and status codes, or mark it missing. Resolve schema
variants rather than relying on frontend fallbacks to guess column names.

Deliver a minimal implementation plan. Separate visual work from backend additions.
Do not run destructive migrations or claim the app is functional without validation.
```

Acceptance: every requested feature has a known contract or a named gap; no assumed routes or fake success states; actual stack and active schema recorded.

## Phase 2 — design system and responsive shell

```text
Implement the shared visual system from the supplied mockups: dark plum background,
near-black panels, restrained pink accents, rounded cards and clear typography.
Use Bushisa everywhere. Define reusable color, spacing, radius, typography and
focus tokens. Validate contrast rather than copying low-contrast mockup text.

Create Home, Confessions, Find Match and You navigation with icons above labels,
active-state indication and accessible current-page semantics. On phones use a
bottom bar, with safe-area inset padding and reserved content space. Prevent the
bar from covering card bios, chat input, save buttons or the confession plus.
On larger screens use a centered content area/grid and persistent navigation.
Configure headers per the page-chrome table; do not hide them only with CSS while
leaving active/inaccessible controls in the page.

Check 320, 375, 390, 768, 1024 and 1440 CSS-pixel widths, portrait/landscape,
200% zoom, keyboard focus, reduced motion and the mobile virtual keyboard.
Use content-driven layouts, responsive images and no horizontal page overflow.
Dialogs need labels, focus management, Escape/close behavior and focus restoration.
Use a small visible plus icon inside a comfortable 44px-or-larger touch target.
```

Acceptance: exact four-item navigation; icon-above-label layout; no obsolete inbox or Views controls; appropriate chrome for every page; content remains reachable behind fixed elements.

## Phase 3 — onboarding, auth and student-number check

```text
Build WhatsApp-inspired onboarding in its clarity and staged identity setup, not
its phone-only account assumptions: signup → institution identity verification →
profile setup → discovery preferences → Home. Respect the backend's actual login
and verification mechanism. Collect only required fields; add a phone field only
when a supported purpose/verification flow exists. Do not imply end-to-end encryption
or WhatsApp account integration. Support login, password visibility, recovery,
verification pending/expired/resend, rate limiting and session expiry.

STUDENT-NUMBER CHECK: Inspect register.php, public/register.php, php/auth.php,
normalization helpers, database uniqueness/collation and all profile edit paths.
They currently uppercase IDs or accept a broad pattern. Confirm NUST's serial length,
allowed trailing letters and admission-year rules. Evaluate the candidate regex
^n0[0-9]{2}[0-9]{5}[a-z]$ against n02531234f, but do not present this inferred regex
as official. The two digits after n0 encode the admission year; do not confuse them
with the student's current year of study. Avoid inventing a century rule for older IDs.

Require lowercase canonical values in UI, payload and storage. Trim surrounding
whitespace and normalize typed/pasted uppercase visibly; disable auto-capitalization
and spellcheck for the ID. Show a lowercase example and explain the year segment.
Reject missing prefix/suffix, wrong length, punctuation and internal whitespace
according to the confirmed grammar. Ensure client/server parity. Handle existing
uppercase records with a collision-aware migration/backward-compatible lookup.
Prevent duplicate accounts through case variants. Student ID must never be a password.

Check student-email domain against the active institution policy (source currently
uses @students.nust.ac.zw), not the mockup's generic placeholder. Audit is_verified=1
at insertion: email-format validation is not proof of ownership. Never expose verified
UI until genuine verification succeeds. Use generic auth errors and server limits.
Use the real password policy, not the mockup's 'at least 6 characters' placeholder.
```

Acceptance: lowercase example accepted under confirmed grammar; uppercase input normalizes; malformed IDs fail consistently; duplicate casing blocked; recovery and verification have real server outcomes; email/student number stay private.

## Phase 4 — You, media and account management

```text
Build You as the user's editable profile/settings page without the Home header.
Include editable user-owned personal/profile fields, bio, discovery preferences,
anonymous handle management, one designated avatar slot and two discovery image
slots. Honor server-supported field limits and communicate changes to identity fields.
Display verified student email read-only; reject tampered updates server-side.
Allow clearing editable optional fields, including an existing bio.

Support selection, preview, crop/re-encode, replace and remove for each image slot.
Show combined bytes used and remaining within 5 MB; explain that the limit is shared.
Use client compression only as assistance; server validates decoded image content,
MIME, dimensions and final stored bytes, removes metadata, rejects executable/SVG
uploads unless explicitly safely supported, and prevents a fourth permanent image.
Enforce quota transactionally under concurrent uploads. Replacement calculations
subtract the replaced slot and include derivatives. Roll back failed uploads without
losing the existing photo; clean superseded/orphan files and revoke object URLs.

Add accessible logout, password/security controls, notification/privacy preferences,
blocked users and account deletion. Deletion needs a clear confirmation and recent
authentication, then session revocation, removal from discovery and documented media/
profile cleanup. Explain any moderation-record retention accurately. Log out by a
CSRF-protected server action that invalidates the session and clears private UI caches.
```

Acceptance: exactly three slots; aggregate limit tested at/above boundary; concurrent replacement cannot exceed quota; immutable email resists forged requests; failed save retains edits; logout and deletion have real server effects.

## Phase 5 — Home, matches and notifications

```text
Build Home with Bushisa at the top and a notification bell. Remove the Views button,
'Inbox DMs and Matches', 'DIRECT MESSAGES (DMS)' and the 'Tap to chat' instruction.
The top horizontal avatar row contains only established server-returned matches.
Images must fill their circular crop: zero decorative padding, no ring/glowing border
or avatar shadow. Retain a visible keyboard focus outline on the interactive wrapper.
No matches means a useful Find Match link, not placeholder match avatars.

Clicking a matched avatar opens its profile/request state or its approved conversation.
Do not treat every avatar as permission to chat. Below this row show other eligible,
nonmatched users as image-led cards with their bio below the photo, like/unlike and
report options. Exclude self, matches, blocked, suspended and deleted accounts on the
server. The two extra images may appear in each user's discovery gallery.

Clicking the bell opens an anchored notification bubble (a sheet on narrow phones)
with matches, confession mentions/references, profile views and only important
milestones. Add separate DM-request/approval and unread-message items if supported.
Use server timestamps, unread status and deduplication; support mark-read and real
deep links. Close on outside click/Escape and restore focus. Profile views belong
here, never in a standalone top button; anonymous confession views must not reveal
viewer identities. Show empty/loading/error states and configurable notifications.

Propose a restrained milestone allowlist: verified onboarding complete, first match,
first approved conversation. Other thresholds require an explicit product rule.
Do not add frequent like-count alerts or spam duplicate milestones.
```

Acceptance: matched-only row with no decorative avatar gap/ring; unmatched card feed with bio and likes; bell contains required categories; no leaked identity through anonymous notification metadata.

## Phase 6 — Find Match and scoring

```text
Implement a short, accessible questionnaire followed by an explicit Search action.
Proposed question topics: relationship intent, interests, preferred social activity,
communication style and availability. Use existing fields where possible; identify
which proposed questions need new persistence and an agreed scoring policy.
Show progress, allow Back/edit, preserve answers during recoverable errors and
validate required inputs. Do not auto-search on every keystroke.

Search through a real server contract. Results contain a server-authoritative score
between 0 and 100, photo gallery, bio and permitted actions; order by score descending
with a deterministic tie-breaker and stable pagination. Explain that this score is
questionnaire compatibility, not a guarantee. Never generate random percentages.
Exclude self/blocked/deleted/suspended or ineligible accounts. Scores must be computed
with an agreed documented formula, missing-answer handling and versioned weights.

Keep scored results distinct from established matches. Likes do not approve DMs.
Offer Request message for established eligible matches; show Pending, Declined,
Approved/Open chat or Revoked from server state. For nonmatch candidates, allow Like
and explain the establishment rule. If product semantics make questionnaire results
established matches, implement and document that server transition explicitly.
Handle changed answers, zero results, retries, cancelled searches and stale responses.
```

Acceptance: questionnaire → Search → descending real scores; stable ties/pagination; no stale search overwrites; request action never bypasses match establishment or consent.

## Phase 7 — DM consent, conversations and unsend

```text
Implement the server-backed request lifecycle: eligible match → pending request →
recipient approval or decline → approved conversation. Recipient approval enables
conversation participation for that pair; establish this pair-level policy explicitly.
Support revoke, block and unmatch. Deduplicate concurrent/repeated requests and apply
server-defined cooldowns after decline. A request must not carry an unsolicited
chat body unless a separate product rule explicitly permits one.

On every message send enforce authenticated pair membership, active match, current
approval, block/suspension state, CSRF and rate limits. Do not rely on disabled UI.
Protect conversation reads as well and document any access to history after revocation.
Handle approval revoked while the composer is open. Use the current API transport;
retain bounded polling if that is what the backend supports rather than claiming
WebSocket realtime. Stop listeners/polling on navigation and logout.

Add sent/sending/failed states, safe retry/deduplication, empty and paginated history,
keyboard-safe input and read status only if real. Unsend is available only for the
sender's own message with a defined server policy: remove its visible body from both
participants and replace it with an unsent marker. Propagate the mutation to other
sessions, older paginated history and cached views. Explain that recipients may have
already read/copied it and that restricted abuse evidence may be retained by policy.
Do not allow arbitrary message IDs or UI-only deletion to simulate unsend.
```

Acceptance: direct forged send without approval rejected; blocked/revoked state immediately stops sending; concurrent request/send retries do not duplicate data; only sender can unsend; receiver sees server-confirmed removal.

## Phase 8 — anonymous confession feed and reference responses

```text
Open Confessions directly into its feed. Remove match bubbles and the Bushisa title,
Views button and bell. Replace the large always-visible posting card with a small
bottom-right plus above the nav/safe area; its accessible label is 'Write confession'.
Open a focused composer only on request; provide draft, character count and submit states.

Default ordering is most views descending (the requested meaning of trending), with
newest/id as deterministic tie-breakers. Agree whether this uses all-time or a rolling
window; proposed default is all-time unique eligible views. Count actual visible reads
on the server with deduplication; rendering, retries and refreshes must not inflate
views. Preserve stable cursor pagination/ranking snapshots rather than resorting items
mid-scroll. A new low-view confession must not be pinned above trending items; show
posting confirmation/link without breaking ordering.

Render text, timestamp, view count and an anonymous username. No profile link, avatar,
real-user ID, student email, student number or match identifier belongs in the feed
payload, DOM, URLs, analytics or public notifications. Do not expose a public lookup
that maps anonymous handles to profiles. Warn users against identifying themselves
in confession text. Explain platform anonymity accurately; do not promise that
moderators with authorized safety access cannot ever identify authors.

Offer 'Reference in a new confession': add an opaque confession ID/link and safe
excerpt preview to a new post. Allow searching/selecting a confession in the composer.
This is a new standalone confession, not a comment thread or direct response.
No direct reply/comments UI. Notify the original author privately about the reference
through internal server ownership without returning that ownership to clients.
Handle deleted/moderated references with a neutral unavailable preview, not cached
removed text. If reference previews include a handle, it remains nonclickable.

Permit reporting with reasons and receipt states, and management of one's own posts
through owner-authorized actions without disclosing ownership publicly. Restrict
anonymous-handle choices to prevent deliberate impersonation/linking with real profiles.
```

Acceptance: feed-first layout; small plus; true view-descending ranking; no comments; reference creates an independent post; matched users cannot resolve authors; hidden/deleted content stays hidden in references.

## Phase 9 — safety and browser capability honesty

```text
Add Report photo and Report confession menus, catfish/impersonation as a report
reason, block/unmatch and a private moderation submission flow. Report only through
real contracts; show receipt, prevent duplicate spam and offer block immediately.
A catfish alarm is a confidential report, never an automatic public accusation or
an unverified badge. Preserve opaque content IDs for moderator evidence. Respect
blocked relationships across search, Home, messages, notifications and direct routes.

Evaluate screenshot alerts using current official browser/platform documentation.
Standard web pages cannot reliably observe arbitrary device/browser screenshots.
getDisplayMedia is permission-based capture initiated by the site, not an OS screenshot
notification API. Do not use PrintScreen handlers, blur/visibility events or screen
capture permission prompts as pretend detection. If a future native client provides
a supported event, integrate it behind a declared capability with honest limitations;
only then emit a genuine alert. For this web app, communicate 'Screenshot alerts are
not supported in this browser' where relevant in privacy information. Do not fabricate
alerts, guarantee screenshot prevention or promise notice for captures on another device.

Audit anonymous DTOs, notification previews, protected media access, CSRF, XSS,
IDOR, session expiry, immutable student email and all owner-only actions. Honor the
repository's existing age/eligibility and consent policies consistently. Add plain
community/privacy links, harassment reporting and a discoverability/privacy control
where supported; mark any missing backend capability explicitly.
```

Acceptance: catfish reporting does not publicly identify/accuse someone; reports remain private; authorization applies to direct requests; no fake screenshot event or absolute privacy promise.

## Phase 10 — integration, responsive QA and handoff

```text
Validate every product requirement in this document against the final UI and server
behavior. Run the repository's appropriate checks and targeted integration tests.
Do not label all behavior passing based only on a screenshot or mocked API.

Exercise onboarding, verification/recovery, lowercase ID and duplicates, all four
pages, image quota/replace races, immutable email, likes, scored search, DM request
approval/decline/revocation, unsend, view ranking, anonymous references, notifications,
reports/blocking, logout and account deletion. Use separate test accounts for identity,
consent and privacy boundaries; avoid real student data. Test unauthorized direct
requests, slow/offline networks, session expiry and server validation failures.

Capture the four main screens plus auth/composer/chat at phone and desktop widths.
Compare with the mockup styling and requested changes. Check keyboard/screen reader,
contrast, focus, 200% zoom, long names/bios, absent images, reduced motion, virtual
keyboard and fixed-nav overlap. Target WCAG 2.2 AA; use 44px touch targets as a
comfortable design choice, not a claim that every AA target must be 44px.

Deliver changed-file summary, reproducible setup, environment variable names without
secrets, actual endpoint/schema mapping, migration/rollback notes, validation evidence
and outstanding backend limitations. Mark unsupported screenshot alerts honestly.
Search product strings for the old misspelling and obsolete inbox/navigation labels.
Do not publish as part of this document-only request.
```

## Final release acceptance checklist

- [ ] Brand is Bushisa everywhere; supplied visual theme retained with readable contrast.
- [ ] Login/signup support a real verification/onboarding flow and lowercase student IDs.
- [ ] NUST format, serial length, suffix rules and year handling confirmed; client/server agree.
- [ ] One profile photo + two discovery photos; no fourth image; ≤5 MB aggregate.
- [ ] Home has only Bushisa + bell in its top header; obsolete inbox/DM/Views text removed.
- [ ] Home avatars represent established matches only, with no decorative padding/glow/ring.
- [ ] Home discovery cards represent nonmatches, with bio beneath images and Like.
- [ ] Nav order is Home, Confessions, Find Match, You, with icons above labels.
- [ ] Bell opens matches, confession mentions, profile views and important milestones.
- [ ] Confessions and You omit the Home title, Views control, bell and match bubbles.
- [ ] Confessions start with a view-descending feed; small bottom-right plus opens composer.
- [ ] Confession responses use standalone reference posts; no direct reply/profile navigation.
- [ ] Anonymous author identity remains inaccessible even to matches and through payloads.
- [ ] You edits user-owned data and media; student email remains immutable on server.
- [ ] Find Match uses short questions, explicit Search and real descending match percentages.
- [ ] Like/candidate/match/DM approval are separate; only approved established matches can DM.
- [ ] Logout, deletion, reporting, catfish reporting, blocking/unmatching and unsend work.
- [ ] Screenshot capability is honestly reported; no fabricated browser screenshot alerts.
- [ ] Responsive, accessibility, privacy and authorization checks have recorded evidence.
- [ ] Every backend gap is resolved or clearly marked; no production mock success paths.

## Assumptions to resolve during the audit

| Question | Proposed default / action |
| --- | --- |
| Student ID serial length and suffix letters | Candidate regex matches owner example; confirm institutional rule and legacy IDs before enforcing. |
| 5 MB meaning | 5,000,000 bytes across persistent images and their derivatives; document any approved binary interpretation. |
| What establishes a match? | Reuse existing match semantics, likely mutual likes; scoring alone does not grant conversation permission. Confirm before wiring results. |
| Questionnaire scoring | Server-owned documented weights and missing-answer rules; no percentages until implemented. |
| Confession trending window | All-time deduplicated views descending unless a rolling window is explicitly selected. |
| DM approval scope | Pair-level approval enables both participants; revocation/block/unmatch removes send permission. |
| Unsend time limit/history retention | Inspect policy; expose the actual allowed window, removal semantics and restricted evidence retention. |
| Identity edits beyond email | Editable with revalidation/reverification where appropriate; no self-editing roles or verification flags. |
| Screenshot alerts | Unsupported on standard web; optional future native capability only. |

## Official references and freshness procedure

Consult these official references and the selected stack's current documentation during implementation. Checked on 4 October 2026; refresh support/version details when implementation begins. The code audit above is static and tied to the recorded commit.

- WCAG 2.2: https://www.w3.org/TR/WCAG22/
- Target-size minimum and exceptions: https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum
- MDN `getDisplayMedia`, permission and activation constraints: https://developer.mozilla.org/en-US/docs/Web/API/MediaDevices/getDisplayMedia
- MDN Screen Capture API usage: https://developer.mozilla.org/en-US/docs/Web/API/Screen_Capture_API/Using_Screen_Capture

For each new library/API choice record the official URL, check date, selected version,
browser support and fallback. Recheck repository HEAD before starting and update the
contract matrix if it differs. 'Up to date' means evidenced compatibility at build time,
not replacing stable dependencies blindly.
