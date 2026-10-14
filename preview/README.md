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

The site has not been verified against a browser screenshot **after these changes**. Pixel-level visual differences may remain, and Figma's desktop/mobile mockups intentionally show different sample prompts. The PHP application (`../discover.php`) is still an earlier integration version and has **not** been updated with this complete HTML structure.

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
- The PHP Home page is still an earlier Figma visual integration. This milestone adds real scrolling without claiming that the whole PHP screen is pixel-identical to the static design preview.


## Real PHP card-component checkpoint

The first discovery page (`discover.php`) and the infinite-scroll API (`api/discovery_feed.php`) now **render their cards with the same PHP function**, `php/discovery_cards.php`. Styling is scoped to Home in `css/home-discovery-cards.css`, preserving the Figma 320px desktop photo area and 160px mobile photo area, typography, interests/tag row and action layout.

You can verify those real PHP cards **without MySQL or login** by opening:

`http://localhost/Bushisa-Figma/preview/php-card-check.php`

This separate PHP fixture page uses fictional Naledi/Tafadzwa portraits from Figma and a photo-free example. Sample one includes a sample conversation prompt, answer and interests; the second and third demonstrate how the renderer handles data that the database does not yet store. No swipes are submitted from the fixture.

**Data contract:** The current backend supports the real student's name, computed age, faculty, year of study, bio and profile photo. It does **not** currently store the Figma conversation prompt/answer or interest tags. The renderer accepts those optional fields when a verified source is connected, but does not infer or fabricate them today. Faculty/year tags are labelled with academic icons instead. The current API returns escaped HTML made by that renderer rather than client-created cards.

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
