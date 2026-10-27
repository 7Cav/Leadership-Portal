# `Docs/`: the in-portal guides (#408)

Guides are markdown files in the repo, under `resources/docs`, edited by pull request so a change to a screen and
the change to its guide land in the same diff. They are files, not database rows, so this is not a Repository: it reads
`resources/docs` and `public/guide-img` and nothing else.

## Who reads what

`ViewerAudience::of($user)` gives the `Audience` a viewer reads the guides as, through the `ScopeResolver` only (AD-8):

- `admin` when they act as an Admin (`actsAsAdmin`),
- `leader` when they command at least one unit (`leads`: a synced billet or an acting lead; a staff grant is read-only
  and does not count),
- `everyone` otherwise.

The audiences nest: an Admin reads every guide, a leader reads `leader` and `everyone` guides. A view-as preview (AD-9)
answers for whoever it shows: a unit preview is that unit's leader, a person preview is that person.

## The library

`GuideLibrary` parses every guide once per change of the files (the cache key is a fingerprint of their paths, sizes
and mtimes) and answers, for one `Audience`:

- `contents()`: the sections the reader has guides in, in `sections.yaml` order, each with its guides by `order`;
- `find($section, $slug)`: one guide, or null. A guide the reader may not read is null too, exactly like a missing one;
- `checklist()`: the "Start here" items listed for the reader, by `checklist.order`;
- `pages()`: each portal path a readable guide explains, mapped to its URL, for the "?" help.

`problems()` lists everything wrong with the files on disk. A guide with a problem is left out, and
`tests/Feature/Docs/GuidesOnDiskTest.php` fails the build on any.

## A guide file

`resources/docs/{section}/{slug}.md`, with the section listed in `resources/docs/sections.yaml`. Slugs are lowercase
words joined by hyphens and unique across all sections.

```markdown
---
title: Run this week's rollcall
summary: Mark attendance, confirm, post.
section: weekly-rollcall
order: 1
audience: leader            # everyone, leader or admin
page: /rollcall             # optional: the portal path this guide explains
checklist:                  # optional: list it under "Start here"
  label: Run your first rollcall
  audience: leader          # never wider than the guide's own audience
  order: 3
---

> [!why] The weekly report is built from your confirmed rollcall.

## Open Rollcall and pick your section

1. Choose **Rollcall** in the sidebar.

![The section picker](/guide-img/rollcall-picker.png)

> [!good-to-know] Troopers on leave are already marked.
```

Level-2 headings are the steps listed under "On this page"; every heading gets a stable anchor from its text. Raw HTML
is stripped. A link to `/docs/{section}/{slug}` must name a guide that exists, and every image must be a file in
`public/guide-img` (not `public/docs`, which would shadow the `/docs` route).

**May depend on:** `Authorization/` (the audience) and `Models/` (the `User`).
**Reached from:** nothing yet; the docs routes (#409) read it from a controller.
