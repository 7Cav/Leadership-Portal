# Leadership-Portal

The 7th Cavalry Regiment's leadership portal: rollcalls, Section Practice attendance and weekly
reports built on the live Cav roster. How to set up and contribute: see [CONTRIBUTING.md](CONTRIBUTING.md).

## Demo data

To show the portal with history on the **real** roster, generate a few past weeks of plausible
rollcall signatures, Section Practice marks and sent weekly reports. Generated rows are tagged
`is_demo`, never touch troopers, units or users, and skip any week that already holds real records.

```bash
# 1. Switch the demo tools on (off by default; leave them off in production)
echo "DEMO_TOOLS=true" >> .env && php artisan config:clear

# 2. Make yourself Admin (sign in once through 7Cav SSO first)
php artisan portal:admin <your forum username>

# 3. Import the org structure (Settings → Org structure → Import from Cav), then sync the
#    roster so troopers are placed in their sections (the scheduler does this hourly; to run it now:)
php artisan tinker --execute="App\Jobs\SyncRosterJob::dispatchSync()"

# 4. Generate 8 completed weeks for a unit's whole subtree (designation or unit id)
php artisan portal:demo-history A/1-7 --weeks=8

# Remove it again (only demo rows are deleted)
php artisan portal:demo-history A/1-7 --purge
```

Each week is one audited transaction. Section reports are sent for every generated week; platoon
and company reports for all but the most recent one, so that week's roll-up can be sent live
during the demo. The current week is never touched. Re-running replaces the earlier demo rows
with the same deterministic pattern. The audit actor and report sender is the earliest Admin, or
pass `--as=<username>`.

The same tools are on **Settings → Demo data** (Admin only, shown only while `DEMO_TOOLS=true`):
pick a unit, choose 1–12 weeks, then Generate or Purge.
