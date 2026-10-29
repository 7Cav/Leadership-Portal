# Copywriting

## In short

- This file is the single source for every word a user reads in the portal. Follow it for new text and for any text
  you touch.
- Write short, plain, calm sentences. Say what happened, then what to do next.
- Sentence case everywhere. No em dashes, straight quotes, no exclamation marks, no contractions, no "please", no "we".
- Use the terms in the [glossary](#glossary) exactly. If a concept is missing, add it here in the same change.
- Before you finish, run the [checklist](#checklist-for-agents) at the end.

## Scope

In scope: any text a user can read or hear in the portal.

- Vue pages, components and `resources/js/lib` helpers: headings, labels, buttons, hints, placeholders, tooltips
  (`title`), `aria-label` and screen-reader text, toasts, dialogs, `window.confirm` and `window.prompt` text, empty
  states, loading text, error text, page titles.
- Server text that reaches a user: exception messages a controller returns as `message` or puts into
  `ValidationException::withMessages`, `abort(403, '...')` messages, flash messages on the login page, validation
  messages.
- Text the portal builds for users to paste elsewhere: the makeup PM, the report summary, the report reminder. The
  rollcall BBCode itself comes from each company's template and is out of scope.

Out of scope: code comments, log messages, exception text that only reaches logs, commit messages, issues, PRs and these
docs. PR and issue wording has its own rules in the working rules.

## Voice and tone

The reader is a section leader or officer in the middle of a weekly task, often on a second screen next to the game or
the forum. They want to know what state things are in and what to click next.

| Rule | Do | Don't |
| --- | --- | --- |
| Short and plain | "Report sent to 1/A/1-7" | "Your report has been successfully submitted to your chain of command" |
| Say what happened, then what to do | "Could not mark the rollcall posted" + "Your session expired; reload the page and try again." | "Error 419" |
| Direct, second person | "You can record a signature for a trooper who told you directly." | "Leaders are able to record signatures..." |
| No first person | "Could not reach the 7Cav roster to confirm your membership. Try again in a few minutes." | "We couldn't reach the 7th Cavalry roster..." |
| No filler | "Sign-in was cancelled or could not be verified. Try again." | "...could not be verified. Please try again." |
| No hype | "Weekly rollcall, section practice attendance and reports for 7Cav leaders." | "Run your platoon, not your spreadsheet." |
| One apology at most, usually none | "The portal hit an error. Your changes are kept; retry in a moment." | "Sorry! Something went wrong, sorry for the trouble." |
| Calm, no shouting | "It has not been sent; try again." | "It has NOT been sent; try again." |
| No jokes or slang | "Nothing needs a look" | "All quiet on the western front" |
| Facts the user can check | "Leave from <source>, synced 14Oct26 22:16Z" | "Leave data is fresh" |

Banned words and forms in UI text: "please", "sorry", "simply", "just" (as filler), "in order to", "please note",
"note that", "successfully", "oops", "we", "our" (except the established "Guests at our practices"), "I" (except a
checkbox the user ticks about themselves, such as "I have read the preview and my notes as command will see them"),
"!" in prose, all-caps words for emphasis, contractions ("don't", "can't", "isn't"; write "do not", "cannot",
"is not").

Exclamation marks: never. There is no exception.

## Mechanics

### Case

- Sentence case for everything: page titles, headings, buttons, labels, menu items, table headers, toasts, dialog
  titles. "Mark rollcall posted", "Command roll-up", "Game identities".
- Keep capitals only for proper nouns and fixed names: 7Cav, Cav, Leadership Portal, Admin, the Cav group names
  (ELOA, Reservists, New Recruits), game names (Hell Let Loose (WW2), Hell Let Loose: Vietnam, Arma Reforger, WARDOGS), Discord, BBCode, and billet
  titles written as on the roster ("Section Leader", "Platoon Leader").
- Billets: capitalise the exact roster title ("the Section Leader", "a Platoon Leader's command over this unit").
  Lowercase a role used generically ("section leaders add guests on their rollcall", "the leaders who command this
  section").
- The organisation: "7Cav" in running text ("your 7Cav milpac", "the 7Cav forum"). "7th Cavalry Regiment" only as the
  name of the regiment unit (the top of the tree, the sidebar scope). "7th Cavalry Gaming Regiment" only in the login
  footer. Not "7th Cavalry" alone. "Cav" stays in the fixed compounds "Cav roster" and "Cav week".
- Section practice: lowercase in the UI ("mark who was at section practice"); "Section practice" only at the start of a
  heading, label or button. Short form SP. Not "Section Practice" mid-sentence.
- Ranks: short ranks are always caps as Cav writes them: PVT, PFC, SPC, CPL, SGT, SSG, SFC, MSG, 1SG, SGM, CSM, WO1,
  CW2 to CW5, 2LT, 1LT, CPT, MAJ, LTC, COL. Full rank names are title case, as Cav's data has them (`rankFull`):
  "Staff Sergeant", "Private First Class", "Chief Warrant Officer 2". Any fallback map follows the same case.

### Punctuation

- No em dash (U+2014) or en dash (U+2013) as a dash. Use a colon, a comma, a semicolon or a new sentence.
  The one allowed em dash is a lone "—" in a table cell or figure that has no value (`'—'` in `lib/report.js`,
  `MatrixView.vue`, `OrbatView.vue`). Do not use "–" or "-" for that.
- Ranges: "to" in text ("Sat to Fri", "1 to 12 weeks"). The forum date range in the BBCode `week` value is template
  output and stays as it is.
- Straight quotes only: `"` and `'`. Quote what the user typed or a name in straight double quotes:
  `No trooper in your scope matches "Tay".`
- No trailing period on: buttons, labels, menu items, headings, table headers, toast titles, empty-state titles,
  chips and badges, single-line hints that are not a full sentence, `aria-label` text.
- A period on: every full sentence in hints, empty-state bodies, toast descriptions, dialog bodies and error messages.
  If a hint has two sentences, both end with a period.
- Question marks only on real questions: confirm dialog titles ("Freeze and send this report?") and "Trouble signing
  in?".
- Colons introduce a reason or a list: "Read-only: a staff grant lets you view this section, not mark it",
  "Waiting on 1/A/1-7, 2/A/1-7.".
- Write "and", never "&". Write "for example" or "such as", not "e.g.". Write "compared with", not "vs".
- Ellipsis: none, neither "…" nor "...". Not on menu items or buttons that open a dialog, and not on in-progress
  labels (see Loading and saving).
- Middle dot "·" only as a separator in page titles (`Rollcall 2/1/A/1-7 · 7Cav Leadership Portal`) and in compact
  meta lines (`MOS · source · fetched at`). Never in a sentence.

### Numbers and percentages

- Numerals always, also for one to nine: "3 visits", "Last 8 weeks", "2 or more practices". Screen-reader text too.
- Percent with no space: "85%". KPI labels keep the established compact form: "RC % · 30d", "SP % · 30d".
- A change in a percentage is in points: "SP up 4 pts to 82% over 30 days". "pts" is allowed only in that line and in
  compact KPI deltas.
- "of" for parts: "3 of 8 sent", "Rollcall signed by 6 of 9".
- Missing figure: the lone "—" (see Punctuation) in tables, "Numbers not available" in text.

### Counts and plurals

- Always agree the noun with the count. Use the `plural()` helper in `lib/report.js` or a ternary; never "trooper(s)".
  "1 guest", "2 guests", "1 week needs work", "3 weeks need work".
- A count of zero reads as a sentence, not "0 troopers": "No guests recorded for this practice."
- Lists of names: comma separated, "and" before the last only in prose sentences ("Choose a reason for Taylor.C and
  Jay.JH"); in "Waiting on ..." lines plain commas are fine.

### Dates and times

All times in the portal are UTC. The Cav works in Zulu time.

| What | Format | Example | Helper |
| --- | --- | --- | --- |
| A date | Forum date: 2 digit day, 3 letter month, 2 digit year | `03Oct26` | `forumDate()` |
| A date and time | Forum date, space, 24h time, `Z` | `14Oct26 22:16Z` | `forumDateTime()` + `Z` |
| A time today | 24h time and `Z` | `22:16Z` | `timeOf()` |
| A deadline | Weekday, forum date, time, `Z` | `Sat 10Oct26 23:59Z` | `stamp()` |
| A Cav week | "week of" and the Saturday it starts | `week of 03Oct26` | `weekLabel()` |
| The current week | "this week" | `Week of 03Oct26 (this week)` | |
| How long ago | Only next to a UTC stamp, never alone | `14Oct26 22:16Z, 3 h ago` | `<TimeAgo>` |
| The viewer's own clock | Only as extra detail, with the zone named | `Last synced Fri, Oct 16, 12:16 AM GMT+2 your time.` | `localTime()` |

- Name the zone as `Z` on a stamp. Use the word "UTC" only in prose that explains the zone and in column headers:
  "Time (UTC)", "All times are UTC".
- A Cav week runs Saturday 00:00 UTC to the next Saturday 00:00 UTC. Say "Sat to Fri" when you show a week as days.
- Durations: "in 2d", "35 min ago", "3 h ago", "2 days ago" (the `timeAgo()` forms). "Report due in 5 h" and "Report overdue by 2 d" come
  from `spanLabel()`; reuse it.
- Never US or ISO dates in UI text (`10/03/2026`, `2026-10-03`), except in an input whose browser control shows its own
  format.

### People

- A trooper is shown by their forum username, the display name: `Taylor.C`. That is `displayName()` in JS and
  `Trooper::display_name` on the server. Never build a name by hand.
- With rank: short rank, a space, the username: `SGT Taylor.C` (the way the forum names a member). Use this in pasted
  text (PMs, summaries) and where a list has no rank badge.
- In UI rows the rank is a `RankBadge` next to the name; do not repeat the rank in the text.
- A trooper whose username has not synced shows their milpac ID: `#12345`. Do not invent a name.
- Server messages name the trooper or unit, not the database ID. "Taylor.C is outside your scope", not "Trooper 412
  is outside your scope".

### Units

- Designations as Cav writes them: section `2/1/A/1-7` (section 2 of platoon 1), platoon `1/A/1-7`, company `A/1-7`,
  battalion `1-7`.
- Show a unit by its callsign, falling back to its name (`unit.callsign ?? unit.name`), as the roster and matrix do.
  In toasts, report titles and anything pasted to the forum or Discord, use the designation
  (`unitLabel()`, `section.designation`).
- A unit's team label shows next to its name as set. Do not reword it.
- A unit's game shows next to its name by the game's name. One it inherits reads "WARDOGS from 2/A/1-7", muted.

### Abbreviations

Allowed without spelling out: 7Cav, Cav, LOA, ELOA, PAF, SP, RC, KPI, BBCode, FTL, SL, ASL, PL, PSG, CO, XO, 1SG,
SGM, CSM, HQ, MOS, JSAM, DCS, LOR, DEVCOM, RDC, S1 to S7, MP, JAG, WAG, RRD, RTC, PM (private message), ID, UTC, the
short ranks, and the game names WARDOGS, CRCON, BattleMetrics.

Use the short form only where space is tight (table headers, chips, badges, KPI labels). In sentences prefer the word:
"section practice", "rollcall", "fireteam leader".

Spell out: ORBAT ("order of battle"), TIS and TIG ("time in service", "time in grade"), S-shop ("staff department"),
AD-n, FR, NFR and story numbers (never in UI text), "wk" ("week"), "vs" ("compared with"), "e.g." ("for example").

## Patterns

### Buttons and links

- Verb first, specific object: "Send report", "Copy BBCode", "Mark rollcall posted", "Save label", "Make acting lead",
  "Place trooper". Not "Submit", "OK", "Go", "Yes".
- One or two words is fine when the context names the object: "Retry", "Undo", "Keep", "Replace", "Dismiss".
- A repeat action says so: "Copy again", "Mark posted again", "Confirm again".
- The button that goes ahead despite open checks: "<verb> anyway" ("Send anyway").
- Cancel in a dialog: "Cancel", or "Keep <thing>" when cancelling protects something ("Keep editing", "Keep my edits").
- Links that leave the portal say where: "View on 7cav.us", "Send makeup PM" plus the screen-reader text "(opens the
  forum in a new tab)".
- "Open <thing>" for links into another page: "Open report", "Open rollcall", "Open milpac".
- Step navigation: "Next: section practice", "Next: review and post", "Back".

### Destructive and irreversible confirmations

Template:

- Title: a question naming the action and the object: `Delete "Apache"?`, `Replace Taylor.C's Hell Let Loose ID?`,
  `Make version 4 the active template?`.
- Body: what changes, and what does not. One or two sentences. "Past attendance is unchanged." "This cannot be undone."
- Reason field when the action is audited: label "Reason" or "Reason for the record", hint "Saved in the audit log with
  the <thing>." Disable the confirm button until the reason has text.
- Buttons: the specific verb from the title, then "Cancel" or "Keep ...".

Use an `AlertDialog`, not `window.confirm` or `window.prompt`, for new destructive actions. The "Leave anyway?" guard on
unsaved changes is the only place `window.confirm` stays (the browser's own leave prompt).

### Toasts

Success:

- Title: past tense, what happened and to what: "Report sent to A/1-7", "Rollcall marked posted", "Trooper placed",
  "BBCode copied", "Linked PlayerOne to Taylor.C".
- Description: where it applies or what to do next: "2/1/A/1-7, week of 03Oct26", "Paste it as a reply in the
  2/1/A/1-7 rollcall thread."
- Bulk changes offer "Undo" as the action and list the names in the description.

Error:

- Title: "Could not <verb> <object>": "Could not copy the BBCode", "Could not confirm the practice", "Could not add
  Taylor.C".
- Description: why, then what to do. Use `httpError.js` (`messageFor()`, `onHttpException()`) so 403, 419, 429 and
  other codes read the same everywhere:
  - 403: "You are not allowed to do that; reload the page, or exit view as first."
  - 419: "Your session expired; reload the page and try again."
  - 429: "Too many requests; wait a minute and try again."
  - other: "(error 500). Try again."
- Say whether the user's work is safe: "Your changes are kept; retry in a moment." "It has not been sent; try again."

Warning: something worked only in part: "Marked posted, but the copied BBCode is out of date", "Some marks were already
saved".

Info: neutral results with nothing to fix, such as a demo data summary.

### Form labels, hints and validation

- Label: a noun or short noun phrase, no colon: "Reason", "Player name", "Training subject", "Game or team label".
- Hint under the field: one sentence that says what the value is used for. "One line, shown as the report headline."
  "Recorded in the audit log with the grant."
- Placeholder: an example or what to type, never the only label: "Find a trooper by name", "Search by username",
  "Why this unit, such as who confirmed it".
- Validation messages say what is wrong and how to fix it, in the field's own words: "A reason is required to revoke a
  grant.", "Search with 2 to 64 characters.", "Keep the reason to 500 characters." Every rule on a field the user types
  into has a custom message in this style (`'reason.required' => ...` in the controller's `validate()` call); do not
  rely on Laravel's default "The reason field is required.".
- A refused write explains the rule and the way out: "This practice is confirmed, so its guests can no longer change."
  "Your platoon set this week's training topic and locked it, so it cannot be changed here. Reload the page to see
  it."

### Empty states

- Title: what is missing, no period: "No guests yet", "No units in your scope yet", "Nothing needs a look", "All clear".
- Body: one or two sentences: why it is empty and what fills it. "Troopers appear here once the roster places them in
  a section of this unit." When the user can act, say how; when only an Admin can, say "An Admin ...".
- A filtered list that is empty says so and offers the way back: title "No troopers match these filters", button
  "Clear filters".
- Searches with no match quote the query: `No trooper in your scope matches "Tay".`

### Read-only and locked reasons

The portal often shows a control but refuses it. Always say why, as a `title` on the control and, when the whole page
is affected, as a line near the top. Use these exact reasons:

| Lock | Text |
| --- | --- |
| View as | "Read-only while viewing as <target>" and, near the actions, "Exit view as to mark attendance." |
| Staff grant | "Read-only: a staff grant lets you view this section, not mark it" (or "this report, not edit or send it") |
| Week floor | "Read-only, this week is too far back to change" |
| Sent report | "The report for this week has been sent, so its rollcall is locked." |
| Confirmed practice | "The practice is confirmed, so its guests can no longer change" |
| Leave | Row badge "LOA" and the source line "On leave of absence, from LOA records. Nothing to mark." |
| Not your unit | "Only the leaders who command this section can change its fireteams" |
| Set by company | "Set by company" next to the control |
| Admin only | "Admin only" badge on Settings pages; server: "Only an Admin may <action>." |

New locks follow the same shape: "Read-only: <reason>" or "<thing> is locked because <reason>".

### Loading and saving

- Saving state on autosave pages: "Saving", "Saved 22:16Z", "Unsaved changes", "Not saved" with "Retry".
- Loading text names what loads: "Loading roster", "Loading the section", "Loading trooper" (screen readers).
- In-progress labels have no punctuation; the spinner shows progress. "Saving", "Importing", "Loading roster", not
  "Saving..." or "Importing…".
- A button that runs a job changes its label to the present participle of its verb while busy, with a spinner:
  "Importing", "Placing", "Freezing".
- A queued job says it is queued and when to look again: "Roster sync started" + "Reload in a minute to see the
  refreshed roster."

### Tooltips and screen-reader text

- `title` tooltips repeat or extend the visible text; never hide the only explanation of a lock in a tooltip on touch
  screens. Pair it with visible text where the action matters.
- `aria-label` describes the control and its object: "Undo adding Taylor.C", "Open the 1/A/1-7 report", "Previous
  week, 26Sep26".
- Status cells read as a sentence: "Week of 03Oct26. Rollcall: Will attend. SP: Present."
- Icon-only buttons always have an `aria-label`.

### Page titles and breadcrumbs

- `<Head title>`: page, then context, then the product, separated by " · ":
  `Rollcall 2/1/A/1-7 · 7Cav Leadership Portal`, `Settings · Staff grants · 7Cav Leadership Portal`.
- The page `<h1>` matches the sidebar label: "Org structure", "Staff grants", "Game identities".
- Breadcrumbs run from the top of the user's scope down, by callsign or name, with the current unit last and not a
  link. "Back to <section>" or "Back to roster" for a single step up.

### Pasted text (PMs, summaries, reminders)

Text the user pastes to the forum or Discord follows the same rules and reads as the leader's own words: no "please",
no product voice. Start with the subject ("Section practice makeup: week of 03Oct26"), use designations and forum
dates.

## Glossary

Counts are from string literals and template text in `resources/js` (JS) and `app` (server), October 2026, excluding
tests and comments. They include some route and key names, so read them as rough weight, not exact use.

| Canonical | Meaning | Where | Variants found | Avoid |
| --- | --- | --- | --- | --- |
| rollcall | The weekly forum thread troopers sign, and the portal page for one section's week | Nav, Rollcall page, reports | rollcall 88 JS / 40 server; "roll call" 0 | roll call, roll-call, Roll Call |
| rollcall post | The BBCode post the portal builds for the rollcall thread | Rollcall step 3 | "Rollcall post" (step 3 heading) | forum message |
| sign, signature | A trooper answers the rollcall thread; the answer recorded for them | Rollcall step 1 | sign 44, signature 19 | RSVP, respond |
| Will attend / Cannot attend / Unsigned | The three signature states. "Unsigned" is the state name on buttons, chips, legends and counts; while the rollcall is still open the legend and matrix say "Unsigned (rollcall open)"; "Not signed yet" only as a sentence fragment in a tooltip or card hint | Rollcall cards, legend, report | "Unsigned", "Unsigned (rollcall open)" (legend, matrix), "Not signed yet" (`TrooperCard.vue` hint) | Can't attend, Not signed yet (as a state label), Yes/No (BBCode only) |
| section practice, SP | The section's weekly practice, and its attendance | Rollcall step 2, KPIs | "section practice" 11, SP 42 | Section Practice mid-sentence, drill, training night |
| mark, practice mark | The recorded SP result for one trooper | Rollcall, roster | mark used throughout | status (UI), grade |
| Present / Missed / Makeup / Attached / LOA / Not marked | The practice marks. LOA is derived, never set by hand | Rollcall cards, legend, report | Not marked | Excused (not a mark; 0 uses), Absent, Unmarked |
| makeup | A practice attended elsewhere that counts for the trooper's own section; verb "make up" | Rollcall, KPIs, guests | makeup 36 / 17; "make-up" 0 | make-up, make up (as a noun) |
| unaccounted trooper | Missed with no makeup, needs a reason on the report | Section report | Unaccounted 17 / 13 | unexcused |
| reason | Fixed list reason for an unaccounted trooper; also the audit reason on Admin actions | Report, Settings | | excuse |
| at-risk | 2 or more Missed marks in the 30 day window | KPIs, roster, Home | at-risk 7 UI, "at risk" 4 UI | "at risk" as a label |
| capture | Reading who was on the game server and pre-marking from it | Rollcall banner, sources | capture 16 / 23 | telemetry, scrape, auto-attendance |
| game server | The source of a capture | Source dots, legend | 8 / 2 | server (alone) |
| unmatched player | A player the capture saw that no trooper is linked to | Rollcall drawer | unmatched (toasts, server, drawer title "Unmatched players") | unlinked player, unknown player |
| link, dismiss, restore | What a leader does with an unmatched player | Rollcall drawer | Undo for restore | ignore, hide |
| game identity | A trooper's in-game name and ID for one game | Nav, Game identities page | game identity 7; "player name" for the name field | account, gamertag |
| confirm (a practice) | A leader states the captured marks are right | Rollcall | Confirm practice, Confirmed by | approve, verify |
| guest | A serving trooper from another section recorded at this section's practice | Rollcall, roster | guest 54 / 15 | visitor |
| JSAM candidate | A guest who meets the JSAM thresholds | Roster guests panel | 1 | nominee, eligible |
| own section SP | SP at the trooper's own section only, makeups not counted (JSAM) | Roster guests panel | "practices of their own section" 1 | own SP |
| report, weekly report | A unit's weekly report | Reports | report everywhere | AAR, submission |
| send | Freeze a report and pass it up the chain | Report buttons, toasts | send 151 / 24; submit only as HTML type | submit, file, publish |
| frozen, snapshot | A sent report, read back as sent | Report history | freeze 11, snapshot 10 | locked copy |
| draft | A report saved but not sent | Reports | | pending |
| roll-up | Noun: the combined view of reports below a unit. Verb: "roll up" | Reports | roll-up 4 / 4, "rollup" only in code | rollup, roll up (as a noun) |
| training subject | What a section trained this week, on its report | Section report | 7 JS | training topic (on a section) |
| training topic | The platoon's subject for its sections for the week, default or enforced | Platoon report | 1 JS / 6 server | training subject (for the platoon) |
| training plan | A platoon's subjects for each Cav week of one month, reviewed by its company | Training plan page | new (#380) | training schedule, monthly plan |
| approve, send back (a training plan) | What the company does with a platoon's training plan; "Sent back" is the status, "Waiting for approval" the one before | Training plan page | new (#380) | reject, decline, return |
| Cav week | Saturday 00:00 UTC to the next Saturday 00:00 UTC | Everywhere | Cav week 6, "week of" 27 | calendar week, Mon to Sun |
| week floor | Weeks older than this are read-only (`PORTAL_WEEK_FLOOR_WEEKS`) | Docs; UI says "too far back" | UI: "too far back" 2 / 6 | locked week, archived |
| LOA, ELOA | Leave of absence and extended leave of absence, from forum threads | Rollcall, roster | LOA 39, "on LOA" 19, "on leave" 1, "leave of absence" 1 | absence, away |
| on LOA | A trooper whose leave covers the week | Rollcall, KPIs | on LOA | on leave (in labels) |
| needs review | A leave post the sync could not read | LOA badge | | invalid, error |
| trooper | Any 7Cav member on the roster | Everywhere | trooper 426 / 135; member 21 (fireteam members, "Not a member?") | soldier, user (except portal accounts), member (for a trooper) |
| user | A portal account (sign-in, staff grant holder) | Staff grants, menus | user 19 / 37 | member |
| milpac | A trooper's Cav personnel record and page | Trooper sheet, milpac page | milpac 10 / 14, "Milpac ID" at sentence start | MILPAC, Milpac mid-sentence, profile |
| billet | A trooper's position on the Cav roster | Roster, Org structure | billet 37 / 16, position 1 UI | position, role, job |
| Developmental Counseling Statement, DCS | A written counseling of a trooper, filed with its signed PDF (#381). Spell it out in labels and headings; "DCS" on badges and in counts. Plural "DCSs" | Milpac counseling panel | new | counseling form, write-up |
| Letter of Reprimand, LOR | A written reprimand, filed with its signed PDF (#381). Spell it out in labels and headings; "LOR" on badges and in counts. Plural "LORs" | Milpac counseling panel | new | reprimand letter, write-up |
| scope | The units you can see; "write scope" the ones you can change | Empty states, errors | 32 / 18 | access, permissions (in UI) |
| staff grant | Admin-given read access to a unit and everything under it | Settings, read-only reasons | staff grant 7 / 7, grant 43 | permission, role |
| view as | Read-only preview as a unit or (Admins) a person | User menu, banner | view as 15, "viewing as" 4 | impersonate, act as (planned feature, see features.md) |
| acting lead | A trooper an Admin assigned to lead a unit | Org structure | 6 / 4, "(acting)" | acting leader, temporary lead |
| portfolio | A regimental staff officer's set of staff departments | Docs, Org structure tree | 0 UI | division, area |
| HQ | A unit's headquarters node where its staff sit | Roster, Org structure | unit names only | headquarters (in labels) |
| group | A non-line node: staff departments, ELOA, Reservists, New Recruits | Org structure | "Troopers in this group", "staff group" in server | pool, bucket |
| New Recruits | The Cav group of recruits not yet in a section. Only ever this group | Org structure, roster toggle | "New Recruits" 2 | new recruits (for the group), New recruit (for anything else) |
| Joined recently | Roster badge and filter chip for a trooper who joined in the last 8 Cav weeks | Roster | "Joined recently" (badge, chip, count badge) | New recruit, New |
| 7Cav | The organisation, in running text | Everywhere | 7Cav, "7th Cavalry Regiment" (regiment unit), "7th Cavalry Gaming Regiment" (login footer) | 7th Cavalry, 7CAV in text |
| strength | Troopers placed in sections in a unit | KPIs | 28 / 18 | headcount (in UI), size |
| activity log | Settings page listing the pages each user opened, for retracing a bug report. Not the audit log, which lists changes | Settings | | page log, visit log, browsing history |
| Admin | The portal Admin flag, not a billet | Settings, messages | Admin 15 / 20, admin 3 / 5 | admin, administrator, S1 admin |
| template | A company's rollcall BBCode template | Settings | 54 / 20 | format, layout |
| org structure | The unit tree | Nav, Settings | "Org structure" | ORBAT (that is the roster view) |
| order of battle | The roster's unit view | Roster | 4 | ORBAT |
| attendance matrix | The roster's 20 week grid | Roster | | heatmap |
| fireteam, fireteam leader (FTL) | Sub-group of a section and its leader | Rollcall | fireteam 56 / 26, FTL 8 | fire team |
| callsign | A unit's short name | Org structure, roster | 53 / 17 | call sign, nickname |
| guidon | A unit's swallowtail flag: two colors split on the diagonal, with text on each half | Roster, Org structure | | flag, banner, pennant |
| designation | A unit's Cav code such as `2/1/A/1-7` | Toasts, reports | 28 / 9 | unit code |
| sign in, sign out | Start or end a portal session | Login, user menu | sign in 7 UI; "log in" only in code | log in, log out, login (as a verb) |
| Departed | A trooper no longer on any Cav roster | Roster, search | | discharged, removed |
| sync | A background read from Cav or the forum | Roster, LOA | 79 / 21 | refresh, import (except "Import from Cav roster") |
| Import from Cav roster | The Admin step that builds the unit tree and billet mapping | Org structure | | sync (for this step) |

## Checklist for agents

Run this before finishing any change that adds or edits user-facing text.

1. Every new term is in the glossary with its canonical form; you used that form, not a variant.
2. Sentence case on headings, buttons, labels, menu items, toasts and dialog titles.
3. No em or en dash used as a dash, no curly quotes, no ellipsis, no "&", no "e.g.", no "vs", no exclamation mark.
4. No "please", "sorry", "we", "simply", "successfully", contractions or all-caps emphasis.
5. No trailing period on buttons, labels, titles and single-line fragments; a period on every full sentence.
6. Buttons start with a specific verb; destructive actions use an `AlertDialog` with a question title and a reason
   field when audited.
7. Success toasts are past tense with the object; error toasts are "Could not ..." plus why and what to do, via
   `httpError.js`.
8. Every disabled or read-only control says why, using the reasons table.
9. Dates use `forumDate()` and friends with `Z`; weeks read "week of 03Oct26"; no local or ISO dates in text.
10. Names come from `displayName()` or `display_name`; units from the callsign, name or designation helpers.
11. Counts agree with their noun; numerals for all numbers.
12. Server messages a user can see name the trooper or unit, never a database ID, and never cite AD or story numbers.
13. Icon-only controls have an `aria-label` written to the same rules.
14. If a test asserts on the text, update the test in the same change.

## Appendix: known deviations

None. The copy revision (#387) fixed every item that was listed here. Add a row here when you find text that breaks
this guide and cannot fix it in the same change.
