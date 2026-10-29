# Leadership Portal documentation

The Leadership Portal is the 7th Cavalry Regiment's tool for the weekly leadership cycle: roll call signatures,
Section Practice (SP) attendance, the roll call post for the forum, and the weekly report that rolls up from sections
to platoons, companies, battalions and the regiment. It reads the live Cav roster and the forum's leave threads, so
leaders do not keep their own lists.

Everyone signs in with their 7cav.us forum account. What you see and what you can change depends on your billet on
the Cav roster (or an Admin's assignment), not on a role someone types in.

## The documents

| File | For | What it covers |
| --- | --- | --- |
| [permissions.md](permissions.md) | S6, unit leadership | Who can see and change what, per command layer, with a full matrix and how scope is worked out |
| [features.md](features.md) | S6, unit leadership | Every page and what it does, the background syncs, makeups, JSAM guests and the KPI definitions |
| [operations.md](operations.md) | S6, whoever deploys | Deploy and after-deploy steps, the Import from Cav roster step, the env keys that matter |
| [copywriting.md](copywriting.md) | Developers, coding agents | The rules and glossary for every piece of user-facing text in the portal |

Each file ends with a short technical appendix for developers that names the code behind the rules.

## How to read these docs

- Start with the "In short" section at the top of each file. The tables after it are for looking things up.
- "Scope" means the set of units you can see. "Write scope" means the units you can change. They are not always the same:
  a staff grant gives read scope only.
- "Admin" is a flag on a portal user, set from the server console. It is not a Cav billet and does not come from the
  roster.
- A "Cav week" runs from Saturday 00:00 UTC to the next Saturday 00:00 UTC. All times in the portal are UTC.
- Unit names in examples (`A/1-7`, `1/A/1-7`) follow the Cav designations the org import reads.

Developers: the main [README](../README.md) covers install and the scheduler, [CONTRIBUTING.md](../CONTRIBUTING.md)
the conventions, and each folder under `app/` has its own README with the architecture decisions (AD-n) it follows.
