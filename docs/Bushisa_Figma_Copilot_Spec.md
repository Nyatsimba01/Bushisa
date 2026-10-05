# Bushisa — Figma companion specification and Copilot prompt pack

Prepared from the Bushisa Figma source and intended to be used alongside
`Bushisa_Frontend_Prompt_Workflow.md`.

## 1. Purpose, authority and usage

This document translates the supplied Figma direction into sequential, copy-ready
implementation prompts. It defines visual hierarchy, responsive behavior, reusable
components, interaction states and design QA. It does **not** replace the product,
backend, privacy, security or authorization requirements in
`Bushisa_Frontend_Prompt_Workflow.md`.

When the two documents appear to conflict, use this order of authority:

1. Authentication, authorization, privacy, anonymity, storage, verification,
   matching, DM consent and server behavior in
   `Bushisa_Frontend_Prompt_Workflow.md`.
2. Confirmed repository contracts and repository instructions.
3. The Figma source for visual language, composition and interaction direction.
4. The responsive extensions and inferred states in this document.

Run the prompts in this document with the matching numbered phases in the frontend
workflow. Do not run a visual prompt by itself when it touches backend-owned state.

## 2. Design source and evidence limits

- Figma file: `Bushisa pages`
- File key: `ip2lFVw9Jf9qP8o4TsihVN`
- Root canvas:
  `https://www.figma.com/design/ip2lFVw9Jf9qP8o4TsihVN/Bushisa-pages?node-id=0-1&p=f`
- Root node: `0:1`

The root canvas visibly establishes:

- A mobile login screen.
- A Home/discovery feed with established-match avatars and photo-led profile cards.
- Notification list, overlay, loading, error and caught-up states.
- A disconnected/retry Home state.
- Notification preferences.
- A “match cannot chat” permission explanation.
- Recipient approval and denial states for message requests.
- An approved conversation screen.
- A research/rationale board explaining consent before chat.

The canvas does not visibly provide a complete, production-ready treatment for every
required route. In particular, complete Confessions, Find Match questionnaire,
onboarding/verification and You/account-management flows must extend the same visual
system while obeying the frontend workflow. Do not claim those extensions are exact
Figma reproductions. They are product-required extrapolations.

The Figma root is a broad overview canvas rather than one application viewport.
Implement each visible phone frame as a responsive route, panel, dialog or state.
Never reproduce the empty canvas, phone hardware, OS status bar, fixed mockup width,
rounded device shell or desktop-sized whitespace.

## 3. Design north star

Bushisa should feel warm, private, youthful and intentional rather than neon,
gamified or generic. Its visual signature is:

- Deep wine/plum page chrome.
- Near-black brown content surfaces.
- Peach, coral and blush accents.
- A restrained plum-to-peach glow near important page edges and primary actions.
- Large editorial statements paired with compact, practical UI typography.
- Photo-led discovery cards.
- Rounded, tactile controls and panels.
- Thin, low-contrast borders that separate dark surfaces without creating noise.
- Clear consent and privacy explanations before messaging.

The interface must preserve the visual direction while improving contrast, focus
visibility, touch targets and text readability where the mockup is too subtle.

Avoid:

- Bright purple “AI product” gradients.
- Glassmorphism on every surface.
- Excessive shadows or glowing avatar rings.
- Decorative badges that imply verification, safety or compatibility without a
  real server-backed meaning.
- Dense dashboards, desktop admin tables or a fifth chat navigation tab.
- Using peach text for long paragraphs on dark backgrounds when a neutral light
  foreground is more readable.

## 4. Visual foundations

### 4.1 Color roles

Create semantic tokens rather than scattering sampled hex values. During
implementation, sample exact values from inspectable Figma nodes when available and
validate WCAG contrast. The following names describe the required roles:

| Token role | Intended use |
| --- | --- |
| `canvas` | Near-black warm brown behind feeds and full-page content |
| `surface` | Primary card, menu and input surface |
| `surface-raised` | Notification overlay, dialog and popover |
| `header` | Deep wine/plum app bar |
| `header-strong` | Darker plum edge or selected state |
| `text-primary` | Warm white for headings and essential values |
| `text-secondary` | Muted warm gray for descriptions and metadata |
| `text-subtle` | Lower-emphasis timestamps; still contrast-compliant |
| `accent` | Peach/coral for primary controls and selected indicators |
| `accent-soft` | Blush tint for chips and subtle highlights |
| `accent-strong` | Warm orange/coral for focus emphasis where needed |
| `border` | Quiet warm-gray divider on dark surfaces |
| `success` | Confirmed, server-backed success only |
| `warning` | Pending/caution state |
| `danger` | Destructive action and validated error |
| `focus` | High-contrast focus ring distinct from borders |

Use the plum-to-peach gradient as an accent field, not as the background of every
card. The Figma login and several phone frames use a warm glow toward the bottom.
Keep text on a stable opaque surface whenever a gradient would reduce legibility.

Support browser color-scheme metadata where appropriate, but the initial design is a
dark theme. Do not invent a light theme during these phases unless the product owner
requests one.

### 4.2 Typography

Use two typographic voices at most:

1. A readable UI sans-serif for labels, controls, metadata and body content.
2. A restrained editorial face or serif treatment for major discovery statements,
   such as “A little spark. A real connection.”

If the repository already has suitable fonts, reuse them. Otherwise use system-safe
fallbacks until the exact Figma family can be confirmed. Do not add a private font by
guessing its identity.

Recommended responsive hierarchy:

- Display statement: fluid, approximately 28–42 CSS px, compact line height.
- Page title: approximately 20–28 CSS px.
- Card name/title: approximately 17–20 CSS px.
- Body: 15–17 CSS px.
- Control label: 14–16 CSS px, never tiny.
- Metadata: 12–14 CSS px with sufficient contrast.

Allow text to wrap. Do not truncate names, bios, notification details or consent
explanations merely to preserve a mockup height.

### 4.3 Spacing, radius and elevation

Use a compact spacing scale built from a 4 px base with recurring 8, 12, 16, 20, 24,
32 and 40 px steps. Default phone gutters should feel close to 16 px and increase on
larger screens.

Use three radius families:

- Small: chips, tags and compact inline controls.
- Medium: inputs, buttons and list rows.
- Large: cards, sheets and dialogs.

Reserve pill shapes for chips, segmented controls and deliberately pill-like actions.
Avoid making every rectangular control fully pill-shaped.

Use borders and tonal separation before shadows. Popovers and dialogs may use one
soft shadow. Avatars must not use decorative glow, ring, padding or shadow; retain a
visible focus style on the interactive element around the image.

### 4.4 Iconography and imagery

Use one established icon library already present in the target repository. If none is
present, choose one small, accessible library only after checking compatibility.
Never use emoji as interface icons.

Icons must have accessible names through visible labels or assistive text. Use icons
above labels in the four-item primary navigation. Maintain at least a comfortable
44×44 px interactive target for icon-only controls such as the bell, menu, close,
back and confession composer button.

Profile and discovery images are content, not decoration:

- Use responsive `<img>` elements with meaningful alt text or deliberately empty alt
  text when adjacent text already identifies the same person.
- Use `object-fit: cover` and stable aspect-ratio containers.
- Preserve the subject's crop when focal-position data exists.
- Provide a neutral non-identifying fallback when an image is absent.
- Never expose private media through a guessed public URL.

## 5. Responsive application shell

### Phone

- Use one full-viewport application root with `min-height: 100dvh`.
- Use a compact top bar only on routes that require one.
- Reserve space for the bottom navigation and device safe-area inset.
- Keep a single readable content column.
- Present the notification panel as an anchored popover when it fits and a bottom
  sheet or full-width sheet on narrow screens.
- Keep the chat composer above the virtual keyboard and bottom safe area.
- Keep floating actions above bottom navigation.

### Tablet

- Increase gutters and card width without stretching prose edge to edge.
- Allow a centered feed with a secondary rail for transient content such as
  notifications when useful.
- Avoid turning every screen into a two-column layout.

### Desktop

- Use persistent four-destination navigation in a left rail or compact sidebar.
- Keep the main feed at a readable width.
- A contextual right rail may host notifications, match/request details or account
  help, but essential tasks must remain available without that rail.
- Do not enlarge phone components proportionally or center a 390 px phone mockup in
  empty space.
- Use responsive grids for discovery and results only where card reading order
  remains clear.

Validate at 320, 375, 390, 768, 1024 and 1440 CSS px, at 200% zoom, in portrait and
landscape, and with long content.

## 6. Shared component inventory

Build components only where reuse is real. Match existing repository patterns before
introducing new abstractions.

### Shell and navigation

- `AppShell`
- `TopBar`
- `PrimaryNavigation`
- `PageContainer`
- `DesktopRail`
- `OfflineBanner`

### Feedback and state

- `LoadingState` or skeleton group
- `EmptyState`
- `ErrorState`
- `InlineError`
- `StatusBanner`
- `Toast` or live-region notice
- `ConfirmationDialog`
- `BottomSheet`

### Identity and discovery

- `Avatar`
- `MatchAvatarButton`
- `MatchRow`
- `ProfileCard`
- `ProfileGallery`
- `BioBlock`
- `CompatibilityBadge`
- `LikeButton`
- `ReportMenu`

### Notifications

- `NotificationBell`
- `NotificationPanel`
- `NotificationItem`
- `NotificationPreferences`
- `UnreadIndicator`

### Consent and messaging

- `MessagePermissionCard`
- `MessageRequestCard`
- `ConsentStatus`
- `ConversationHeader`
- `MessageBubble`
- `MessageComposer`
- `MessageActionMenu`

### Forms and account

- `FormField`
- `PasswordField`
- `StudentNumberField`
- `ImageSlot`
- `QuotaMeter`
- `PreferenceGroup`
- `DangerZone`

### Confessions

- `ConfessionCard`
- `ConfessionReferencePreview`
- `ConfessionComposer`
- `WriteConfessionButton`

Every network-aware component needs explicit loading, success, empty, recoverable
error, authorization failure and stale/session-expired behavior where applicable.

## 7. Screen and state blueprint

### 7.1 Authentication

The Figma login frame uses a full-height wine-to-warm-peach field with a compact
centered form, brand mark, welcome copy, dark translucent inputs and a prominent
primary action. Reproduce the mood, not the mockup's placeholder contracts.

Required states:

- Sign in.
- Sign up entry.
- Password visible/hidden.
- Forgot-password request and result.
- Invalid credentials with generic server-backed messaging.
- Submitting and rate-limited.
- Session expired.
- Offline/retry.
- Verification pending, expired and resend.

Use actual institution email and password policy from the frontend workflow and
backend. Third-party login buttons may appear only when real providers are configured.
Never leave decorative Google or Apple controls that do nothing.

### 7.2 Onboarding and verification

Extend the login visual language into a staged, calm flow:

1. Account details.
2. Institution identity.
3. Verification.
4. Profile basics.
5. Images.
6. Discovery preferences.
7. Review and enter Home.

Use a small textual progress indicator, not a gamified progress ring. Preserve entered
values through recoverable errors. Normalize the student number visibly to lowercase
and explain the confirmed format without presenting an inferred regex as official.

### 7.3 Home

The Figma Home screen establishes:

- A deep wine top bar with Bushisa at left and a bell at right.
- A horizontal established-match avatar row.
- A large editorial discovery statement.
- Photo-first profile cards.
- Name/age and bio beneath the image.
- Compact like and report actions.
- A warm glow toward the bottom edge.
- Four-item bottom navigation.

Apply the workflow corrections:

- No Views button.
- No inbox subtitle.
- No “DIRECT MESSAGES (DMS)” heading.
- No “Tap to chat” instruction.
- Match avatars have zero decorative gap, ring or glow.
- Discovery cards show nonmatches only.

Home states:

- Matches plus discovery profiles.
- No established matches, with a useful Find Match action.
- No discovery profiles.
- Loading skeletons that reflect the final card geometry.
- Disconnected state preserving already loaded content where safe.
- Retryable server error.
- Session expired.

### 7.4 Notifications

The Figma shows both a standalone notification list and an anchored panel over Home.
Use one shared item model and rendering component.

An item contains:

- Category icon.
- Concise title.
- One-line or wrapping description.
- Server timestamp.
- Unread indication.
- Optional overflow action.
- Real deep link when authorized.

Supported categories come from the frontend workflow: matches, confession
references/mentions, profile views, important milestones and, where backed by the
server, message requests/approvals and unread messages.

Required states mirror the canvas:

- Loading skeleton.
- Populated list.
- Empty/caught-up.
- Could not load with Try again.
- Offline or stale cached content.
- Preferences link.

The panel closes on outside click and Escape and restores focus to the bell. On small
phones it becomes a sheet. Do not leak anonymous-confession ownership through item
payloads, text, URLs or metadata.

### 7.5 Notification preferences

The Figma preference frame uses a top back affordance, stacked labeled switches, a
compact informational panel and a wide save action.

Preferences must reflect only real backend capabilities. Use native semantic
checkbox/switch behavior, visible focus and status text. Distinguish:

- Saving.
- Saved.
- Failed save with retained local edits.
- Unsupported category.
- Browser-level notification permission when genuinely relevant.

Do not silently imply that an in-app preference has granted browser push permission.

### 7.6 Find Match

No complete questionnaire is visible on the root canvas. Extend the same dark,
rounded, warm-accent system.

Use one focused question per step on phone where practical. Provide:

- Contextual Find Match title.
- Progress and Back.
- Clear question and optional supporting text.
- Large selectable cards, radio groups or checkboxes.
- Explicit Search action after review.
- Recoverable validation.
- Search loading, zero results, error and retry.

Results should visually relate to Home profile cards while emphasizing a real,
server-authoritative compatibility percentage. The score is secondary to the person,
not a giant gamified gauge. Present permitted actions from server state:

- Like for eligible nonmatches.
- Request message for eligible established matches.
- Pending.
- Declined.
- Approved/Open chat.
- Revoked.

Never use visual treatment to collapse candidate, like, match and DM approval into one
state.

### 7.7 Message permission and request states

The Figma includes:

- A dark educational card headed “A match isn't chat permission.”
- A recipient request screen with profile image, name, short context and approve
  action.
- A denial/decline state with subdued status treatment.
- A larger rationale board explaining that connection is not consent to chat.

Use these as the canonical consent language and hierarchy. A request must identify the
established match, explain what approval enables and offer a safe decline path.
Approval and decline need confirmation from the real server before the UI transitions.

Do not add a free-text first message to the request unless the backend workflow gains
an explicit product rule for it.

### 7.8 Conversation

The Figma conversation screen establishes:

- Deep wine header.
- Back action, avatar/name and safety/overflow action.
- Near-black message field.
- Distinct sent and received bubbles.
- Warm accent for the current user's message.
- Compact timestamps/status.
- A bottom composer with send action.

Required additions:

- Empty conversation state.
- Loading older messages.
- Sending, sent and failed/retry.
- Approval revoked while open.
- Blocked/unmatched/suspended state.
- Session expiry.
- Own-message action menu with server-backed unsend.
- Unsent marker visible to both participants after confirmation.
- Keyboard-safe layout and live-region feedback that does not reread the entire thread.

Do not claim WebSocket real-time behavior if the backend uses polling.

### 7.9 Confessions

The complete screen is not visible on the root canvas. Extend the dark feed language
without reusing Home-only chrome.

- Open directly into the feed.
- Use a modest contextual heading if needed.
- No Bushisa Home title, bell, Views control or match avatars.
- Use text-led cards with anonymous handle, timestamp and view count.
- Keep the anonymous handle noninteractive.
- Use a small floating plus in a 44 px or larger target, above nav and safe area.
- Open the composer as a focused sheet or dialog.
- Render reference previews as visually nested context, while preserving each response
  as an independent confession.
- Do not add comment-thread indentation, Reply buttons or profile navigation.

Long text must wrap safely. User content must never be injected as HTML.

### 7.10 You

The complete profile/account screen is not visible on the root canvas. Extend the form
and preference styling from login and notification preferences.

Recommended section order:

1. Profile summary.
2. One profile image and two discovery image slots.
3. Shared 5 MB quota status.
4. Editable personal/profile fields and bio.
5. Read-only verified student email.
6. Discovery preferences.
7. Anonymous handle.
8. Notification and privacy settings.
9. Password/security.
10. Blocked users.
11. Logout.
12. Account deletion danger zone.

Do not use the Home header or match row. Image replacement must retain the existing
asset until the server confirms success. Destructive actions require clear, accessible
confirmation.

## 8. Motion and interaction

Motion should communicate state, not decorate:

- 120–200 ms for hover, press and simple disclosure transitions.
- 180–280 ms for sheets and dialogs.
- No long parallax, continuous glow animation or bouncing navigation.
- Skeletons may use a subtle low-contrast pulse.
- Respect `prefers-reduced-motion` and remove nonessential transforms.

Use optimistic UI only for reversible, low-risk actions with reliable rollback. Do not
optimistically claim verification, match establishment, DM approval, unsend, account
deletion or report submission.

## 9. Accessibility and content rules

- Target WCAG 2.2 AA.
- Use landmarks and one clear page heading.
- Keep DOM order aligned with visual and reading order.
- Use real buttons, links, fields, dialogs and lists.
- Preserve visible keyboard focus against both plum and peach surfaces.
- Associate every field with a persistent label.
- Do not rely on color alone for selected, unread, pending, failed or approved states.
- Announce asynchronous results concisely.
- Trap focus only in true modal dialogs and restore it when they close.
- Make sheets and popovers dismissible without forcing destructive changes.
- Never place essential text solely inside images.
- Avoid gendered assumptions and manipulative compatibility copy.
- Use “Bushisa” consistently in UI, metadata and accessible labels.

## 10. Sequential copy-ready prompts

Each prompt below assumes that the master prompt from
`Bushisa_Frontend_Prompt_Workflow.md` is attached first.

### Design Prompt 0 — capture design evidence before editing

```text
Read Bushisa_Frontend_Prompt_Workflow.md and
Bushisa_Figma_Copilot_Spec.md completely. Inspect repository instructions, the
active frontend, existing styles/components/assets and the real application entry
points. Open the Bushisa Figma root:
https://www.figma.com/design/ip2lFVw9Jf9qP8o4TsihVN/Bushisa-pages?node-id=0-1&p=f

Inventory the visible Figma frames and map each to a route, reusable component or
state. Separate exact Figma evidence from responsive extrapolation and product-required
screens not depicted in the root canvas. Record existing repository components that
can be reused. Do not edit yet.

Return a visual contract containing semantic color roles, typography roles, spacing,
radii, borders, image ratios, shell behavior, component variants and all visible
loading/empty/error/consent states. Flag any design text that contradicts backend,
privacy, accessibility or product rules; the frontend workflow wins those conflicts.
Do not infer a functional endpoint from a visual control.
```

### Design Prompt 1 — audit and route/state map

```text
Run frontend workflow Phase 1. Add a UI state matrix to the contract audit. For every
route and overlay list unauthenticated, loading, content, empty, recoverable error,
offline, forbidden and session-expired behavior where relevant. Map Figma evidence to
Auth, Home, Notifications, Notification Preferences, Message Permission, Message
Request and Conversation. Mark Onboarding, Confessions, Find Match and You as
visual-system extensions rather than exact reproduced frames.

Identify stale mockup labels and forbidden semantics: Views as a Home control, inbox
or DM navigation copy, decorative match avatars, direct chat before approval, random
compatibility percentages, clickable anonymous identities and unsupported screenshot
alerts. Produce no fake data contract to make a screen appear complete.
```

### Design Prompt 2 — tokens, primitives and responsive shell

```text
Run frontend workflow Phase 2. Implement the smallest coherent token set and reusable
primitives needed for the Figma direction: warm near-black canvas, plum header,
peach/coral accent, readable neutral text, quiet borders, three radius roles, spacing,
focus and state colors. Sample exact Figma values when inspectable and validate
contrast; otherwise document temporary inferred values.

Build the responsive AppShell, route-aware TopBar and exact four-item primary
navigation in this order: Home, Confessions, Find Match, You. Icons sit above labels
on phones. Use a sidebar/rail on desktop. Reserve bottom-nav and safe-area space.
Do not add Chat as navigation.

Build accessible base buttons, icon buttons, fields, cards, sheets/dialogs and shared
loading/empty/error states. Verify 320–1440 CSS px, keyboard focus, 200% zoom, reduced
motion and no fixed-element overlap. Do not restyle backend behavior in this phase.
```

### Design Prompt 3 — auth, verification and onboarding

```text
Run frontend workflow Phase 3. Implement the login screen using the Figma's full-height
wine-to-warm-peach atmosphere, compact centered form, rounded dark fields and clear
primary action. Replace placeholder policies and providers with real repository
contracts. Do not render nonfunctional social login controls.

Extend that language into staged signup, identity verification, profile setup, image
setup and preferences. Use persistent labels, password visibility, generic auth
errors, submitting/limited/offline/session states and recovery/verification outcomes.
Normalize student numbers visibly to lowercase according to the confirmed backend
contract. Preserve user input after recoverable errors. Never visually label identity
as verified before server proof succeeds.
```

### Design Prompt 4 — You, media and account controls

```text
Run frontend workflow Phase 4. Build You without Home chrome. Reuse form styling from
Auth and stacked settings styling from the Figma notification-preferences screen.
Implement exactly three image slots: one profile and two discovery images. Show a
single aggregate quota meter and per-slot replace/remove controls.

Keep verified student email visibly read-only while relying on server enforcement.
Organize profile, preferences, privacy, security, blocked users, logout and deletion
into readable sections. Retain dirty edits on save failure. Keep the current image
visible during replacement until the server commits. Use accessible confirmations
for logout/account deletion and honest retention copy from the real backend policy.
```

### Design Prompt 5 — Home and notifications

```text
Run frontend workflow Phase 5. Reproduce the Figma Home hierarchy as a responsive app
screen: plum Bushisa/bell header, established-match row, editorial discovery statement
and photo-led candidate cards with name, age, bio, Like and report actions. Remove
Views, inbox/DM headings and Tap to chat copy. Match avatars must have no decorative
gap, ring, glow or shadow; focus belongs on the interactive wrapper.

Implement the bell panel using the Figma populated, loading, caught-up and failed
states. Use one notification item model for panel and full list. On narrow screens use
a sheet; on larger screens anchor the panel or use a contextual rail. Close on Escape
and outside click and restore bell focus. Implement a disconnected Home state that
preserves safe cached content and exposes Retry.

Render only server-established matches in the avatar row and only eligible nonmatches
in discovery cards. A matched avatar opens the server-authorized profile/request/chat
state; it never implies chat permission.
```

### Design Prompt 6 — Find Match and result states

```text
Run frontend workflow Phase 6. Extend Bushisa's Figma language into an accessible
questionnaire with contextual title, restrained progress, Back, large selectable
answers, answer review and an explicit Search action. Preserve answers through
recoverable failures.

Use photo-led result cards related to Home but make the server compatibility score
clear and secondary, not a gamified giant gauge. Show a short explanation that it is
questionnaire compatibility. Render Like, Request message, Pending, Declined,
Approved/Open chat or Revoked only from authoritative server state. Visually and
semantically distinguish candidate, liked candidate, established match and approved
conversation. Add search loading, cancellation/stale-response protection, zero
results, retry and pagination states.
```

### Design Prompt 7 — consent, requests and conversations

```text
Run frontend workflow Phase 7. Treat the Figma message-permission card, approval
request, declined request and consent rationale board as canonical interaction
direction. Explain plainly that a connection is not consent to chat. Build pending,
approve, decline, revoked, blocked and unmatched states without shaming either user.
Only transition after server confirmation.

Build Conversation from the Figma header, message bubbles and composer. Add loading
history, empty, sending, sent, failed/retry, pagination, approval revoked and session
expired states. Keep the composer keyboard-safe. Implement sender-only unsend through
the real endpoint and replace confirmed removals with an unsent marker for both
participants. Do not claim real-time transport or read receipts without actual
support. Add accessible safety/report/block actions in the conversation menu.
```

### Design Prompt 8 — Confessions and references

```text
Run frontend workflow Phase 8. Extend the dark rounded card system into a text-led
Confessions feed, but omit Home title, bell, Views control and match avatars. Start at
the highest-view feed. Use a small Write confession floating action above navigation
and safe area; open a focused sheet/dialog only when requested.

Render anonymous handle, time, view count and confession text with no profile link.
Build reference previews as contained context inside an independent confession card,
not as nested comments. Provide composer draft, character count, searchable reference
selection, submission and recoverable error states. Include neutral unavailable
reference treatment and report actions. Never expose real-user identifiers in markup,
URLs, analytics, notifications or accessibility text.
```

### Design Prompt 9 — safety, reporting and capability honesty

```text
Run frontend workflow Phase 9. Apply one consistent report menu/sheet to profile
photos, profiles, confessions and conversations where authorized. Include catfish or
impersonation as a private report reason, clear receipt state and an optional immediate
block action. Never display public accusation badges.

Ensure blocked content and identities disappear consistently from Home, Find Match,
notifications and direct routes according to server results. Add honest privacy copy:
screenshot alerts are unavailable in standard web contexts unless a genuine declared
platform capability exists. Do not add keyboard, blur or visibility-event theater.
Audit focus order, error disclosure and private identifiers in every state.
```

### Design Prompt 10 — responsive and visual QA

```text
Run frontend workflow Phase 10. Compare each implemented state against the Figma root
and Bushisa_Figma_Copilot_Spec.md. Capture Auth, Home, notifications, Find Match,
Confessions, You, message request and Conversation at phone and desktop widths.

Check 320, 375, 390, 768, 1024 and 1440 CSS px; 200% zoom; portrait/landscape; long
names and bios; missing images; keyboard-only use; screen reader labels; reduced
motion; virtual keyboard; safe areas; loading/empty/error/offline/session states.
Confirm no device frame, OS status bar, fixed mockup width or root-canvas whitespace
was reproduced.

Search for stale brand spelling, obsolete Views/inbox/DM navigation copy, decorative
avatar rings, comments/replies in Confessions, random percentages, chat before
approval, fake verification and unsupported screenshot promises. Report exact visual
mismatches, backend blockers and evidence. Do not call a mocked screenshot proof of a
passing server flow.
```

## 11. Definition of visual completion

- [ ] The dark plum, near-black and peach visual signature is recognizable across all
      routes without sacrificing contrast.
- [ ] The implementation is responsive application UI, not a collection of fixed
      phone mockups.
- [ ] Phone, tablet and desktop layouts use available space intentionally.
- [ ] Primary navigation has exactly four items in the required order.
- [ ] Home uses Bushisa plus bell only in its top bar.
- [ ] Match avatars are true edge-to-edge circular crops without decorative rings.
- [ ] Discovery remains photo-led, with readable names, bios and clear actions.
- [ ] Notifications include populated, loading, caught-up, failure and preference
      states derived from the Figma direction.
- [ ] Consent appears before conversation access and denial remains respectful.
- [ ] Conversation handles keyboard, failure, revocation and unsend states.
- [ ] Confessions, Find Match and You feel native to the Figma system while being
      explicitly treated as extensions.
- [ ] Loading skeletons match final geometry and do not create severe layout shift.
- [ ] Empty and error states always provide a truthful next step.
- [ ] Focus, hover, pressed, disabled, pending and destructive states are distinct.
- [ ] User content wraps safely and no critical control relies on color alone.
- [ ] All copy says Bushisa and avoids unsupported claims.
- [ ] Visual success never substitutes for server authorization or privacy checks.

## 12. Handoff format for every implementation phase

At the end of each phase, return:

1. Figma states and specification sections implemented.
2. Reused and newly created components.
3. Exact changed files and rationale.
4. Responsive widths and interaction states checked.
5. Accessibility checks performed.
6. Real backend routes exercised.
7. Fixtures still present and proof they are excluded from production.
8. Visual differences from Figma and why they were necessary.
9. Product/backend blockers without simulated success.
10. The safe starting point for the next phase.
