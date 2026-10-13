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
