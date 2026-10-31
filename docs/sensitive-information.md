# Sensitive information: what never goes in this repo

This repository is public. Anything committed, written in a pull request, an issue or a comment can be read by
anyone, is copied by forks and caches, and stays in git history even after a later commit removes it. This list
says what must never be pushed, what is fine, and where the real values live instead.

## In short

- Real secrets, server addresses and personal data stay on the server (`.env`) or in a private message to the
  person deploying. Never in code, docs, tests, pull requests, issues, commit messages or screenshots.
- Use placeholders in code and tests: `example.org`, `*.test`, and the documentation IP ranges `192.0.2.x`,
  `198.51.100.x` and `203.0.113.x`.
- If something slips through, treat it as leaked: rotate it first, then clean up.

## Never push

| Kind | Examples |
| --- | --- |
| Environment files | `.env`, `.env.testing`, `.env.production`, any copy of them. Only `.env.example` and `.env.production.example` belong in git, with empty values. |
| Keys, tokens and passwords | `APP_KEY`, database and Redis passwords, `SEVENCAV_OAUTH_CLIENT_SECRET`, `SEVENCAV_FORUM_API_KEY`, `CAV_API_KEY`, `CRCON_API_KEY`, `BATTLEMETRICS_API_KEY`, `NIGHTWATCH_API_KEY`, mail and AWS credentials, Discord webhook URLs, personal access tokens |
| Google credentials | The Drive service account key (`GOOGLE_DRIVE_PRIVATE_KEY`, or any service account JSON file) |
| Server addresses | IP addresses, ports, RCON and CRCON URLs (the `CRCON_SERVERS` and `CRCON_BASE_URL` values), admin panel addresses, database hosts, SSH hosts |
| Private document ids | `GOOGLE_DRIVE_FOLDER_ID`, `GOOGLE_SHEETS_REPORTS_SPREADSHEET_ID`, links to private Drive files or sheets |
| Database contents | Dumps and backups (`backup*.sql`, `backups/`), copies of the production database, exports of real roster or attendance data |
| Personal data | Real troopers' counseling records and PDFs, LOA reasons, email addresses, Discord ids, game account ids (Steam, Epic, platform ids from CRCON), IP addresses of members |
| Production output | Logs, error pages, stack traces or screenshots from the live portal or a game server that show any of the above |

## Fine to push

- Server names as players see them ("Vietnam - Training 1"), game names, and which servers are for training.
- The public 7cav.us addresses the portal links to: the forum, the wiki, milpac profile pages, the help page.
- Placeholders: `https://crcon.example.org/`, `one.test`, `203.0.113.9`, `<shared key>`.
- Made-up test data. Seeders and factories use invented names, ids and keys, never real ones.

## Where the real values live

- On the server, in `.env`, which only the person deploying can read. `docs/operations.md` and the example env
  files list the keys, never the values.
- In a private message or a local note (outside the repository) for the person deploying, when a change needs new
  values. A pull request says "shared privately" instead of the value.

## Before you push

1. Read the diff, not just the summary: `git diff develop...HEAD`.
2. Search it for addresses and keys, for example `git diff develop...HEAD | grep -iE "https?://|[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+|key|token|secret|password"`,
   and check every hit is a placeholder or a public 7cav.us page.
3. Check the pull request text, issue text and any screenshot the same way.

## If something sensitive was pushed

1. Rotate it first: change the key, password or token at its source, and update `.env` on the server. Removing the
   commit does not help, because forks and caches already have it.
2. Tell whoever deploys the portal.
3. Remove it from the repository and, if it is a secret, from history (a repository admin can do this), and edit or
   delete the pull request, issue or comment that shows it.

## Technical appendix

- CI's **Secret scan (gitleaks)** job scans the commits of every pull request and every push to develop with
  gitleaks' built-in rules plus `.gitleaks.toml`, which adds 7Cav game server and CRCON addresses and public IPv4
  addresses (loopback, private and documentation ranges are allowed). A hit fails the build. It cannot read pull
  request text, issues or screenshots, so check those yourself.
- `.gitignore` and `.dockerignore` keep `.env*` (except the examples), `backup*.sql` and `backups/` out of git and
  out of the Docker image.
- `phpunit.xml` pins every env key the tests read, so tests never need real values.
- Integration tests fake every outside service (Saloon `MockClient`, `Http::fake`); no test contacts a real server.
