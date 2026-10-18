# Find Match — one question at a time

Branch: `figma-integration` in `Nyatsimba01/Bushisa`.

## Install

Extract `Bushisa_Find_Match_Question_Flow_Update.zip`, then merge its `js` and
`css` folders into `C:\xampp\htdocs\Bushisa-figma\`. Replace matching files.
Keep your existing project folder and the other files in it.

The three application files are:

- `js/find-match.js`
- `js/find-match-delight.js`
- `css/find-match-flow.css` (new)

Open `http://localhost/Bushisa-figma/matches.php?edit=1` and press Ctrl+F5.
The `edit=1` link lets you review the questionnaire even if it is already complete.
No database import or configuration change is required.

## How the flow works

The original four phases remain: Your intentions, Your vibe, Shared interests
and Preferences. The opening question retains the existing choice-card design.
Continue replaces the current question with the next relevant question in the
same space. Completed questions are hidden rather than stacked above the new one.

Your vibe now asks social style and communication style separately. Optional
follow-ups also appear one at a time. Sports, playing/watching and football team
are separate questions; the team question appears only when Football is selected.

Back revisits a question and retains its selection. Selecting a radio option does
not advance automatically, so you can change your choice or use arrow keys before
pressing Continue. Shared interests and other checkbox questions retain multiple
selection.

Optional questions have Skip question and, when relevant, Skip remaining extras.
Skip clears the skipped optional answer. Previously completed extras in that phase
are retained. Deselecting an interest clears its obsolete follow-up values when
that phase is saved.

Answers save at the end of each phase using the existing authenticated PHP
endpoint and CSRF token. A failed save leaves the question and selected answers in
place for retry. The completed flow goes to the existing Find Match destination.

The new flow stylesheet loads automatically from `find-match.js`. Your existing
base questionnaire stylesheet, PHP page, API, account records and other pages are
unchanged. The previous floating extra-question controller is replaced by a small
compatibility file so two questionnaire controllers do not run together.

## Verification

The repository test `tests/find_match_flow_integration.py` runs the real PHP page
and save endpoint against a uniquely named local disposable database. It never
uses the application database. `tests/find_match_flow.cjs` performs browser checks.

Passed: one visible question, required-answer validation, all four phases,
Back and prefill, radio keyboard selection, multiple interests, optional skipping,
sports/team branches, removal of obsolete values, answer length limits, failed-save
retry, final completion, reload and account isolation.

Browser widths checked: 1440, 1024, 750, 390 and 320 pixels. Desktop and mobile
screenshots were reviewed for the opening screen and optional questions.

To run the tests, use a local disposable MySQL/MariaDB server, PHP 8 with PDO MySQL
and mbstring, Python 3 and Playwright. Configure `PHP_BIN`, `PHP_INI`, `TEST_DB_HOST`,
`TEST_DB_PORT`, `TEST_DB_USER`, `TEST_DB_PASS`, `PLAYWRIGHT_MODULE` and
`CHROMIUM_PATH` if necessary, then run:

`python tests/find_match_flow_integration.py`
