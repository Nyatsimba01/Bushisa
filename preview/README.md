# Bushisa Figma Home — visual checkpoint

This is a **frontend-only** concept preview of the linked Home design. It is separate from the PHP application. The fictional profiles, matching controls, and chats are design examples, not real student activity, and no actions write to the database.

## Preview in XAMPP on Windows

1. On GitHub select the **figma-integration** branch and download its ZIP.
2. Extract into a separate `C:\xampp\htdocs\Bushisa-Figma` folder. The `preview` folder should be immediately inside `Bushisa-Figma`. Do not merge into a production folder.
3. Start **Apache** in XAMPP. MySQL is **not needed** for this HTML-only preview.
4. Open **http://localhost/Bushisa-Figma/preview/home.html** (HTTP, not HTTPS). If replacing an older ZIP, hard refresh with **Ctrl+Shift+R**.

For a meaningful comparison with the source Figma frames, use **1280px viewport width** for Desktop (frame `4:2737`) or **390px width** for Mobile (frame `4:3145`). Use Chrome DevTools device toolbar for mobile.

## Implementation scope

- The preview HTML is `home.html` and its isolated styling is `home-figma.css`.
- It uses Figma's original profile sample images, request sample images, and exported SVG icons in `assets/`.
- Desktop: navigation, welcome, chats with pending requests, two featured cards, three-part story/Chiedza/consent row, caught-up panel, five-step explanation, beta footer.
- Mobile: compact header, four-avatar row, one-column profile cards, fixed bottom navigation.
- Demo controls intentionally show a preview notice instead of claiming to submit likes, matches, or confessions.

### Not yet complete

The site has not been verified against a browser screenshot **after these changes**. Pixel-level visual differences may remain, and Figma's desktop/mobile mockups intentionally show different sample prompts. The authenticated PHP Home now uses this shared Figma composition, with real database-backed profiles and chats. Pixel-level comparison against a local browser screenshot is still outstanding.

The live hosted site and Kyle's email-verification backend remain untouched. Before connecting real users, resolve the repository's incompatible base SQL schemas and test age eligibility, identity verification, matching and message consent. Do not run `database/bushisa.sql` on an existing populated database without a backup: it includes `DROP TABLE` commands.


## Automatic-scroll milestone (development branch)

The real PHP Home route `../discover.php` now initially renders **six** eligible database profiles, then requests further groups of six automatically from `../api/discovery_feed.php` when the end of the list approaches. The API uses a stable, ascending user-ID cursor and rechecks the user's server-side discovery preferences and exclusions on every request. The browser appends cards with `../js/discovery_feed.js`, and Like/Pass interactions on new cards are handled by `../js/app.js`. No Show More button is used.

To **see the interaction without a database or login**, open:

`http://localhost/Bushisa-Figma/preview/home.html?scrollDemo=1`

That optional demo shows the three exact original Figma concept profiles first, then loads clearly labelled fictional placeholders in batches of six on scroll, using the existing card geometry. It is not a real account list. The plain `preview/home.html` remains the unchanged Figma composition.

The original design has its own Desktop (1280px) and Mobile (390px) frames. Use Chrome's device toolbar at those widths when comparing.

### Before live deployment

- Confirm the local database schema matches the selected source schema; don't import destructive SQL over real data.
- Exercise pagination with more than six eligible test accounts, excluded matches/blocks/swipes, plus the last page and failed requests.
- Confirm local login sessions and age eligibility, then integrate Kyle's newer verification code; that integration is out of scope here.
- The PHP Home page now uses the Figma Home layout. It still needs a full browser screenshot comparison in a logged-in test environment before we can call it visually finished.


## Real PHP card-component checkpoint

The first discovery page (`discover.php`) and the infinite-scroll API (`api/discovery_feed.php`) now **render their cards with the same PHP function**, `php/discovery_cards.php`. Styling is scoped to Home in `css/home-discovery-cards.css`, preserving the Figma 320px desktop photo area and 160px mobile photo area, typography, interests/tag row and action layout.

You can verify those real PHP cards **without MySQL or login** by opening:

`http://localhost/Bushisa-Figma/preview/php-card-check.php`

This separate PHP fixture page uses fictional Naledi/Tafadzwa portraits from Figma and a photo-free example. Sample one includes a sample conversation prompt, answer and interests; the second and third demonstrate how the renderer handles data that the database does not yet store. No swipes are submitted from the fixture.

**Data contract:** The current backend supports the real student's name, computed age, faculty, year of study, bio and profile photo. The opt-in development migration adds prompt/answer and interests to a separate table, so eligible discovery records can now include them. The renderer never fabricates values when a user hasn't saved any. Faculty/year tags are labelled with academic icons instead. The current API returns escaped HTML made by that renderer rather than client-created cards.

**Feature status:** Main Like and Pass keep their existing swipe endpoint. Photo likes, answer likes, bookmarks and profile-specific confessions have no backend contracts yet and appear visibly disabled; they do not pretend to save anything.

The `?scrollDemo=1` fictional mock now includes example conversation prompts, tags and the same visual interaction slots as the original Figma cards. It remains completely separate from the actual database.

The two repository SQL schemas are incompatible alternatives; do not import either over an existing populated database without a backup.


## Optional real conversation prompt and interests persistence

The authenticated PHP `profile.php` page now includes a separate **Conversation prompt and interests** form, protected by the existing CSRF token. It saves an optional question (160 characters), answer (240 characters) and up to five comma-separated interests (40 characters each).

### Safe setup in a development database

1. Back up your current MySQL database and confirm which schema your local Bushisa installation uses.
2. Start Apache and MySQL in XAMPP.
3. In phpMyAdmin, select the **existing Bushisa development database** (normally `bushisa_db`, but check `config/db.php`).
4. Use phpMyAdmin's **SQL** tab to run **only** `database/migrations/20261014_profile_personality.sql`. It creates one new table using `CREATE TABLE IF NOT EXISTS`; it never drops or replaces existing tables.
5. Open the authenticated `profile.php#conversation-and-interests` page and save an example prompt and interests. They will appear on the real `discover.php` cards when another eligible account discovers that user.

**Until the migration has been applied**, the form is explicitly disabled, and the Home feed continues rendering profile bios and genuine academic tags. Database page views do not create tables or change schema.

The first six cards and automatically loaded later cards now use the **same optional LEFT JOIN** to `bushisa_profile_personality` when present. No fictional prompt or tags are invented for database users. This schema table is intentionally kept separate from `users` and `profiles` because this repository has two incompatible older base SQL schemas and Kyle's newer integration still needs reconciliation.

PHP-only helper tests (no live database required):
`php tests/profile_personality_test.php`
`php tests/discovery_cards_test.php`

**Note:** The settings form is an interim backend integration in the existing PHP Profile page; a full faithful Figma You/Profile page is a separate design-implementation milestone. Do not confuse the real PHP screens with the static `preview/home.html` design fixtures.


## Authenticated Figma Home integration

`../discover.php` now uses the **same Figma desktop and mobile composition** as `home.html`: original navigation icons, brand, notification/account actions, chat avatars, requests indicator, suggested cards, the story/third-card/consent row (desktop), caught-up panel, process section, beta bar and mobile bottom navigation.

- The Home-specific CSS is `../css/home-real.css`, layered after `home-figma.css` and `../css/home-discovery-cards.css`. Changing other pages' shell is out of scope.
- Real chat avatars, incoming message-request counts, and notifications come from the local database. When there are none, the page shows a compact genuine empty state—not fictional accounts.
- Eligible profiles come from `../php/discovery_feed.php`. The third card is presented as a compact feature-row card on desktop and a full single-column card on mobile. Both representations refer to the same candidate; a Like/Pass hides both.
- Additional profiles append **after** the desktop feature row and use the same escaped PHP card component. Existing server-side eligibility, blocking and cursor logic is preserved.
- When no candidate is eligible, an in-layout empty state replaces the cards. The page **must not** insert Naledi, Tafadzwa or other Figma sample people in the authenticated page.
- Failing profile images get a Figma-styled initial fallback in `../js/home-real.js`.
- Figma-only controls that still lack backend support remain disabled; do not present simulated likes/bookmarks/answers as working features.

### Updating XAMPP safely

From the newest `figma-integration` branch, copy these updated files into your existing **separate** `C:\xampp\htdocs\Bushisa-Figma` folder:

```
discover.php
php/frontend.php
css/home-real.css
js/discovery_feed.js
js/app.js
js/home-real.js
```

Keep the previously downloaded `preview/home-figma.css`, `css/home-discovery-cards.css`, and `preview/assets/icons/` files. Do not replace `config/db.php`, `uploads/`, or import either base database schema. Start Apache and MySQL; sign in at `http://127.0.0.1/Bushisa-Figma/login.php` and open `http://127.0.0.1/Bushisa-Figma/discover.php`.

The PHP syntax and JavaScript syntax checks in GitHub Actions cover source validity, **not** a pixel-perfect or end-to-end MySQL result. Inspect desktop at 1280px and mobile at 390px in Chrome DevTools and address any remaining differences against Figma.


## Figma Home: meaningful first impression when discovery is empty

The authenticated `discover.php` Home now has a dedicated visual **empty-data journey** using the original Figma palette, typography, heart/sprout imagery and desktop/mobile navigation:

- When there are no preferences, it says **“Every good story starts somewhere.”** and points toward profile setup, Find Match and Confessions.
- When preferences exist but no eligible profiles match, it says **“Nothing new to discover just yet.”** A simple, accurate status line and links help people explore without pretending a background search is happening.
- The large older empty-profile card and duplicated story/consent row are replaced with a compact status line while empty. The populated Figma two-card + third-card layout, real Like/Pass and infinite scroll remain unchanged.
- The links are non-blocking: Home stays available from desktop and mobile navigation; no returning user is automatically redirected when a feed is empty.
- The You tile is context-aware: it offers profile setup when the user's bio, saved prompt and interests are all blank; otherwise it offers refinement. No database changes are needed.
- Visual QA is still required; passing source checks is not a browser screenshot comparison.

**XAMPP upgrade from the previous branch checkpoint:** copy only `discover.php` and `css/home-real.css` from the newest `figma-integration` ZIP into the same paths of your existing `C:\xampp\htdocs\Bushisa-Figma` folder. Keep `config/db.php`, `uploads/` and the MySQL database unchanged. Sign in at `http://127.0.0.1/Bushisa-Figma/login.php`, visit `discover.php`, and hard-refresh with **Ctrl+Shift+R**. Check desktop at 1280px and mobile at 390px.


## Figma You/Profile integration — PHP development page

`profile.php` now follows the **You/Profile desktop and mobile Figma frames** (`4:3002`, `4:3400`), with Home's same brand/header/nav and mobile bottom dock. The two-column desktop workspace and stacked mobile design use `css/you-figma.css` layered over `preview/home-figma.css`.

**Already connected:** existing PHP profile updates (display name, bio, faculty, year of study and one main-photo upload), optional conversation prompt/answer + comma-separated interests, discovery preference updates, read-only student email, logout and the existing account deletion destination. The optional prompt form only enables when `bushisa_profile_personality` exists. Profile changes still use CSRF and the original PHP persistence functions.

**Not yet supported or verified:** genuine student/age verification, photo slots 2 and 3, a second conversation answer, pause/visibility controls and notification preferences. The Figma checklist marks them as pending rather than showing fabricated completion. Age restrictions must be enforced in registration, authentication and discovery before real launch.

The Figma prompt-choice chips fill the real **single** conversation question field; they don't create additional stored prompts. Client-side choices use `js/you-profile.js` without simulated saving.

### Update the existing XAMPP development checkout

From the latest `figma-integration` branch ZIP, copy **only these four updated/new files** to the corresponding places inside `C:\xampp\htdocs\Bushisa-Figma`:

```
profile.php
php/frontend.php
css/you-figma.css
js/you-profile.js
```

The updated page reuses the previously installed `preview/home-figma.css` and `preview/assets/icons/`. **Do not replace** `config/db.php`, your `uploads/` directory or the database. Log in through `http://127.0.0.1/Bushisa-Figma/login.php`, open `http://127.0.0.1/Bushisa-Figma/profile.php`, and hard refresh.

Check that the profile editor, checklist and privacy section follow the desktop Figma frame; on mobile 390x844 they should stack with the floating bottom navigation. Save only fictional development information and refresh to confirm persistence. Photo upload and preferences need independent manual tests before calling them complete; GitHub PHP/JS lint is not a substitute for a local functional test.
