# Contributing to the 7Cav Leadership Portal

This portal turns live forum / roster / game-server data into leadership's weekly
artefacts (rollcalls, per-echelon reports). The forum is the **server-of-record**; the
portal is a read-mostly cache + staging layer. This guide is how we keep the codebase
consistent across a volunteer, part-time contributor base — read it before your first PR.

The full rationale lives in the **architecture spine**
(`_bmad-output/planning-artifacts/architecture/…/ARCHITECTURE-SPINE.md`); its decisions are
referenced below as `AD-n`. This file is the day-to-day working agreement.

---

## 1. Getting started

```bash
composer setup      # install, copy .env, key:generate, migrate, npm install + build
composer dev        # serve + queue + logs + vite (all at once)
```

- **PHP 8.3+**, **MySQL 8.4**, Node for the Vite/Vue front end.
- Copy `.env.example` → `.env` and fill in local values. `.env.example` is the contract:
  every key is documented with an empty/placeholder value. **Never** put a real secret in
  it, in the DB, or anywhere in version control (AD-23) — secrets are environment-only.
- A local MySQL 8.4 container works fine; point `DB_*` at it.

## 2. The architecture, in one screen

The paradigm is **layered CQRS-lite**: dependencies point one direction only, the
database is reachable through exactly one layer, and the outside world through exactly one
seam. This is not a convention you have to remember — **Deptrac fails the build if you
break it** (AD-1).

```
Route → Controller ─┬─ (write) Command → CommandHandler ─┐
                    └─ (read)  ReadService / Query ───────┤
                                                          ▼
                                                     Repository → Model → DB
Controller / CommandHandler / Sync ── Service seam (CavApi · ForumApi · …) → Integration → external
```

| Layer | Directory | May depend on | Role |
| --- | --- | --- | --- |
| Controller | `app/Http/Controllers/` | CommandHandler, ReadService, ScopeResolver | Orchestrate a request. **No business logic.** |
| Command / Handler | `app/Commands/` | Repository, AuditEnvelope, Service seam | Write-side business logic (AD-16) |
| ReadService / Query | `app/ReadServices/` | Repository, ScopeResolver | Shape Inertia read props (AD-15) |
| Repository | `app/Repositories/` | Model, DB | **Sole** DB access (AD-2) |
| Model | `app/Models/` | DB | Eloquent entities |
| Service seam | `app/Services/{Cav,Forum,…}/` | Integration | **Sole** path to external systems (AD-3) |
| Integration | `app/Integration/` | External (Saloon/HTTP) | Saloon connectors + DTOs |
| Sync | `app/Sync/` | Repository, Service seam | One-writer cache sync (AD-4) |
| Authorization | `app/Authorization/` | — | ScopeResolver, policies, view-as (AD-6/7/8/9) |
| Support/Audit | `app/Support/Audit/` | — | The audited-action envelope (AD-16) |

Each layer directory has a `README.md` explaining its rules — read the one for the layer
you're touching. The forbidden moves that catch people: **Controller → Repository** (skip
the read/write layer) and **`DB::` / Eloquent outside a Repository**. Both fail the build.

## 3. Rules that fail the build (Deptrac)

```bash
composer deptrac              # analyse — exits non-zero on any layer violation
composer deptrac:unassigned   # list app/ classes that match no layer (should be empty)
```

Run `composer deptrac` before you push. If it's red, the message names the exact class,
the disallowed target, and the file:line. Fix the dependency direction — don't reach for a
workaround.

**The graph evolves consciously.** If a new class genuinely needs an edge that isn't in
`deptrac.yaml` yet (e.g. the ScopeResolver reading the org tree through a Repository), add
that single edge in the same PR, with a one-line reason. That deliberate, reviewed edit
*is* the enforcement working — never widen a rule just to make a red build go away.

## 4. Where code goes & naming

- One Inertia page ↔ one controller; props shaped by a ReadService (AD-20). Pages are
  PascalCase matching the controller, under `resources/js/Pages/`.
- Eloquent models are **singular** (`Trooper`); tables **plural snake_case**
  (`weekly_reports`). Commands are imperative (`SendWeeklyReport`), handlers end `…Handler`,
  sync handlers `Sync{Thing}Handler`, service facades `{Target}Api` (`CavApi`).
- Reads never mutate. Every state change goes through a Command → CommandHandler and the
  audited envelope (AD-16); personnel actions and report sends **require** a reason.
- KPIs are defined once and computed by the one shared KPI service (AD-21) — never
  re-derived inline in a feature.
- Repositories return fully-loaded models or DTOs. `preventLazyLoading()` is on outside
  production, so a query escaping a Repository via lazy-loading throws loudly (AD-2).

## 5. Testing & style

```bash
composer test                 # Pest (config:clear + artisan test)
vendor/bin/pint --dirty       # format only changed files (run before committing)
```

- Test stack: **Pest 4** (PHP), Vitest 4 / Playwright (front end).
- Keep the build green: `pint --dirty`, `composer test`, and `composer deptrac` should all
  pass locally before you open a PR. CI enforces the same gates.

## 6. Git & PRs

- Branch off `develop`: `feat/<issue>-<slug>` (e.g. `feat/1-2-enforce-layers-deptrac`).
- One story / issue per PR; keep the diff focused. Reference the issue number.
- Commit messages describe the change plainly — Conventional Commits style
  (`feat:`, `chore:`, `fix:`) as used in the history.

---

Questions about *why* a rule exists → the architecture spine's `AD-n` entries. Questions
about *where* code goes → the layer `README.md` files. When in doubt, match the surrounding
code.
