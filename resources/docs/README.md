# Writing the guides

The guides at `/docs` in the portal are the markdown files in this folder. Change a screen's behavior, change its
guide in the same pull request.

## Where a guide goes

```
resources/docs/
  sections.yaml           # the sections, in contents order: `folder-name: Title`
  weekly-rollcall/
    run-rollcall.md       # one guide; the file name is its slug: lowercase words joined by hyphens
```

The folder is the guide's section, and must be listed in `sections.yaml`. A slug is unique across all sections.
Images go in `public/guide-img/` (not `public/docs/`, which would shadow the `/docs` route).

## Who sees it

Every guide is for one audience. A reader sees the guides for their audience and the ones below it:

| `audience` | Who reads it |
| --- | --- |
| `everyone` | every signed-in member |
| `leader` | anyone who commands a unit (a billet or an acting lead), and Admins. A staff grant alone does not count. |
| `admin` | Admins only |

View as follows the person or unit being previewed, but never raises the audience: someone who commands nothing
reads the `everyone` guides in any preview, a unit preview through a staff grant included. A guide outside the reader's audience answers 404, exactly like
one that does not exist.

## The front matter

```markdown
---
title: Run this week's roll call         # required
summary: Mark attendance, confirm, post. # required, one sentence
section: weekly-rollcall                 # required, the folder it is in
order: 1                                 # required, its place in the section
audience: leader                         # required: everyone, leader or admin
page: /rollcall                          # optional: the portal path it explains, for the "?" help
weekly: true                             # optional: a weekly task, listed under "Every week" on docs home
minutes: 5                               # optional: about how long it takes
checklist:                               # optional: put it on the "Start here" list
  label: Run your first roll call
  audience: leader                       # never wider than the guide's own audience
  order: 3
---
```

`page` may name parts that change with `{name}`, such as `/reports/{unit}/{week}`. The "?" in the top bar opens the
best guide for the page the reader is on.

## The body

- Each `##` heading is a numbered step and shows under "On this page". Keep one action per step. A section with a
  single step needs no heading of its own: fold it into the step before or after it.
- Write for the reader's task, in plain sentence-case language. Explain jargon or link to the glossary. The copy rules
  are in `docs/copywriting.md`.
- Two call-outs, as a block quote that starts with a marker:

  ```markdown
  > [!why] The weekly report is built from your confirmed roll call.
  > [!good-to-know] Troopers on leave are already marked for you.
  ```

- Link another guide as `/docs/{section}/{slug}`, and the screen as its portal path.
- Raw HTML is not rendered.

## Screenshots

Take them where no real trooper's name or username can show: the browser-test data (`tests/e2e`), the preview setup in
`CONTRIBUTING.md`, or a test environment with demo data (`DEMO_TOOLS=true`, Settings, Demo data). Demo data fills in
weeks for a unit's real troopers, so check every screenshot for real names and blur any you find. Give every image
alt text that says what it shows.

## What checks it

- `tests/Feature/Docs/GuidesOnDiskTest.php` fails the build on bad front matter, a link to a guide that does not
  exist, a missing image or a duplicate slug.
- `resources/js/Layouts/__tests__/guideCoverage.spec.js` fails when a screen in the sidebar has no guide (a `page`
  pointing at it) and is not listed there as having none on purpose. A new screen ships with its guide, or with a
  line in that list saying why not.
