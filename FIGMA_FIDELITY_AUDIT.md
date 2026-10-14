# Bushisa — Figma fidelity audit

Source: [original Figma design](https://www.figma.com/design/AXn0tedfKv5sB0ZBu5E7DM/Untitled?node-id=0-1)
Comparison target: the **figma-integration** branch, not `main`.

## Figma reference frames

| Page | Desktop node | Mobile node | PHP page |
|---|---|---|---|
| Home | `4:2737` | `4:3145` | `discover.php` |
| Find Match | `4:2906` | `4:3310` | `matches.php` |
| Confessions | `4:2828` | `4:3234` | `confessions.php` |
| You | `4:3002` | `4:3400` | `profile.php` |

## Design tokens

- Background: `#21151A`; surfaces `#302128`, `#3B2931`; warm raised accent `#4A2434`.
- Text: `#F8EDE2`; muted `#C8B4B9`; peach `#F4B598`; borders `#57404A`.
- Heading font: **Lora**; body font: **Inter**.
- Top navigation reference height: **92px desktop, 76px mobile**.
- Mobile bottom dock: **72px**, with separate active tab styling.
- The four pages must share the same logo, navigation icons, footer language, palette and spacing rules.

## Comparison status and known differences

### Home

**Status: layout aligned, conditional content is necessarily different.**

- Figma references contain **fictional concept profile photos and chat avatars**, not real database data. PHP must not fabricate real students or invent conversations.
- The actual Home switches to a tailored empty-state panel when there are no eligible profiles. That state is a product extension absent from the original Figma frame.
- Re-check the real image aspect ratios, chat-row spacing, badge positions and card typography **after populating a consented test-only dataset**. Do not insert real users into demo content.

### Find Match

**Status: original four-step visual styling aligned; additions not represented in initial Figma.**

- The new optional theme dialogs and completed-questionnaire campus map were specifically requested after the Figma frames were created.
- Keep those screens visually consistent with the Figma colour, type, border and spacing system rather than remove them for screenshot similarity.
- Fictional map pins are illustrations, **not actual people, GPS points or consented matches**.
- Before any real map data: adult eligibility, student verification, consent, coarse location, off-by-default visibility, reporting and blocking must be enforced server-side.

### Confessions

**Status: shared layout/colours aligned; dynamic state differs.**

- This audit aligns the desktop right-column safety guidance and mobile mentions callout with the Figma references.
- Figma uses a fictional anonymous handle and confession content. The PHP page shows what the database actually stores.
- Anonymity is **not** equivalent to displaying the real profile or login handle; confirm the separate `anon_handle` flow is configured and editable before a public launch.
- Like/report controls must never be represented as fully working when they are disabled or only route to help information.

### You / Profile

**Status: matching shell, but important structural differences remain.**

- This audit brings draft text, photo-slot labels, and the desktop save-button dimensions closer to Figma.
- Current PHP has two forms, extra editable display name/faculty/year fields, and only one real upload slot. Figma shows a simpler one-panel draft with additional prompt/photo concepts.
- Do **not** remove working profile fields or imply all three images and both Figma prompt answers are saved: the backend currently supports fewer slots.
- A later carefully tested form refactor could close this gap further without breaking save actions or duplicating submitted fields.

## What was changed in this pass

- Added `css/figma-fidelity.css` as the last stylesheet loaded by `render_document_head`.
- Centralized the original visual tokens and adjusted the mobile header to 76px.
- Aligned desktop You page save buttons to compact, right-aligned actions; retained mobile full width.
- Adjusted You draft/photo-slot presentation without pretending unsupported uploads work.
- Aligned the Confessions desktop community sidebar and the mobile mentions callout.

## Required browser sign-off (not yet done)

Use Chrome on local XAMPP with the updated branch, **at 100% browser zoom**.

1. Desktop: capture each route at **1280px viewport width** (and the page's natural height) in Figma's displayed state.
2. Mobile: capture each route at **390px viewport width**, then test narrow `375px` and `360px`.
3. Confirm font loading in DevTools (Lora and Inter); if offline, browser font fallback will change letter wrapping.
4. Confirm all CSS assets load without 404s. Compare header/dock height, content left/right margins, text line wrapping, card shape, image crop and responsive states.
5. For Home and Confessions, compare an explicitly fictional sample-preview state to Figma; compare the real empty/database-driven state separately.
6. Test each form with a development account; confirm POST success/error states, keyboard focus and navigation behavior. Do not use actual student photos or identifying details for visual tests.

**Important:** This is a source-level comparison and alignment pass. A literal pixel-perfect certification would require the actual locally rendered screenshots at matched viewports and a pixel/visual-diff review. The newer features and real database content cannot be visually identical to the original static sample frames by definition.
