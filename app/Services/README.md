# `Services/` — the service seam: sole path to external systems (AD-3)

One facade per external target (`Cav/`, `Forum/`, and later `Sheets/`, `Crcon/`,
`BattleMetrics/`). **Every** call to an external system goes through its
`{Target}Api` facade here — no HTTP client is instantiated anywhere else.

**May depend on:** `Integration/` (the Saloon connectors that back each facade).
**Must not** reach the outside world directly — that lives one layer down in
`Integration/`. Facades are named `{Target}Api` (e.g. `CavApi`, `ForumApi`).
