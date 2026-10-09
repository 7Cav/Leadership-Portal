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

The paradigm is a **slim, Laravel-idiomatic layering**: dependencies point one direction
only, the database is reachable through exactly one layer (`Repositories/`), and the
outside world through exactly one layer (`Integration/`). This is not a convention you
have to remember — **Deptrac fails the build if you break it** (AD-1). It began as a
fuller CQRS-lite graph; the read-service and service-facade indirection were dropped in
favour of the conventional Laravel shape — controllers read repositories directly, and
CommandHandlers call the outbound seam directly.

```
Route → Controller ─┬─ (write) Command → CommandHandler ─┐
                    │                                     ▼
                    └─ (read) ────────────────────► Repository → Model → DB
                                       CommandHandler ─► Integration (Saloon connectors) → external
```

| Layer | Directory | May depend on | Role |
| --- | --- | --- | --- |
| Controller | `app/Http/Controllers/` | CommandHandler, Repository, Model, Authorization, Job, Support\Time, Kpi, Milpac | Orchestrate a request. **No business logic.** Reads repositories directly; writes via Commands. |
| Command / Handler | `app/Commands/` | Repository, Model, Integration, Support\Audit, Authorization, Support\Time, Kpi | Write-side business logic; the **only** caller of the outbound seam. |
| Repository | `app/Repositories/` | Model, DB, Support\Time | **Sole** DB access (AD-2) |
| Model | `app/Models/` | DB | Eloquent entities |
| Integration | `app/Integration/` | External (Saloon/HTTP) | **Sole** path to external systems (AD-3) — Saloon connectors, requests, DTOs, and a `{Target}Api` seam class |
| Authorization | `app/Authorization/` | Repository, Model | The single `ScopeResolver` gating read visibility (AD-8), plus policies and view-as |
| Support\Audit | `app/Support/Audit/` | Repository | The audited-action envelope every state-changing Command routes through (AD-16) |
| Support\Time | `app/Support/Time/` | — | `WeekOf::start()`, the **only** time → `week_start` mapping (AD-11): Saturday 00:00 UTC |
| Kpi | `app/Kpi/` | Repository, Model, Support\Time | `KpiService`, the **one** place the six canonical KPIs are defined and computed (AD-21) — read-only, over facts read through Repositories |
| Milpac | `app/Milpac/` | Repository, Model | Read-only props for the trooper detail page: the ID block and the courses panel (Epic 6) |
| Sync | `app/Sync/` | Repository, Model, Integration | One-writer cache sync handlers (AD-4, AD-22) — `Sync{Thing}Handler` |
| Provider | `app/Providers/` | (wires every layer) | DI composition root — bootstrap, not business logic |
| Middleware | `app/Http/Middleware/` | — | Request glue (e.g. Inertia shared props) |

**Layers added consciously as their stories landed.** `Authorization/`, `Support/Audit/`, and
`Sync/` were all placeholders in `deptrac.yaml` until each one's own story added it — with its
edges — deliberately (Story 1.11, Story 1.10, and Story 2.2 respectively). All three are
enforced today. `Support/Time/` (Story 3.2) was added the same way: a pure helper with no
outgoing edge, reachable from Controller, CommandHandler, and Repository. `Kpi/` (Story 5.1)
likewise: the shared KPI service (AD-21), reading through Repositories and read by Controllers
and CommandHandlers.

Each layer directory has a `README.md` explaining its rules — read the one for the layer
you're touching. The forbidden moves that catch people: **Controller → Integration** (an
external call in a controller — route it through a Command) and **`DB::` / Eloquent
outside a Repository**. Both fail the build.

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

- One Inertia page ↔ one controller; the controller shapes its own props, reading
  `Repositories/` directly (AD-20). Pages are PascalCase matching the controller, under
  `resources/js/Pages/`.
- UI is composed from the in-repo **shadcn-vue** components in `resources/js/components/ui/`
  (add more with `npx shadcn-vue@latest add <name>`, then re-theme them; never ship one in its
  default look), Phosphor icons (`@phosphor-icons/vue`), and the design tokens in
  `resources/css/app.css`. Every authenticated page renders inside `Layouts/AppLayout.vue`; new
  sidebar entries go in `Layouts/navigation.js`.
- Eloquent models are **singular** (`Trooper`); tables **plural snake_case**
  (`weekly_reports`). Commands are imperative (`SendWeeklyReport`), handlers end `…Handler`,
  sync handlers `Sync{Thing}Handler`, and each external target's seam class is
  `{Target}Api` in `app/Integration/{Target}/` (`ForumApi`, `CavApi`).
- Reads never mutate. Every state change goes through a Command → CommandHandler;
  personnel actions and report sends **require** a reason. These route through the
  audited-action envelope (AD-16) once it lands in Story 1.10.
- KPIs are defined once and computed by the one shared KPI service, `App\Kpi\KpiService`
  (AD-21) — never re-derived inline in a feature.
- Repositories return fully-loaded models or DTOs. `preventLazyLoading()` is on outside
  production, so a query escaping a Repository via lazy-loading throws loudly (AD-2).

## 5. Testing & style

```bash
composer test                 # Pest (config:clear + artisan test)
vendor/bin/pint --dirty       # format only changed files (run before committing)
npm audit --audit-level=high  # supply-chain gate — must report no high/critical
```

- Test stack: **Pest 4** (PHP), Vitest 4 / Playwright (front end).
- Keep the build green: `pint --dirty`, `composer test`, and `composer deptrac` should all
  pass locally before you open a PR. CI enforces the same gates.
- CI also fails on any **high or critical** npm advisory. That gate can go red on a PR that
  changed no dependencies, because advisories are published against packages we already
  had — it isn't your PR's fault, but it does block it. Fix it in its own dependency PR
  rather than lowering `--audit-level`. Moderate findings are reported locally and never
  block a merge.

## 6. Git & PRs

- Branch off `develop`: `feat/<issue>-<slug>` (e.g. `feat/1-2-enforce-layers-deptrac`).
- One story / issue per PR; keep the diff focused. Reference the issue number.
- Commit messages describe the change plainly — Conventional Commits style
  (`feat:`, `chore:`, `fix:`) as used in the history.

---

Questions about *why* a rule exists → the architecture spine's `AD-n` entries. Questions
about *where* code goes → the layer `README.md` files. When in doubt, match the surrounding
code.
