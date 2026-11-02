---
title: Game server connections
summary: Check whether the portal can read each game's servers, and test a connection.
section: admin
order: 10
updated: 2026-11-02
audience: admin
page: /settings/connections
---

> [!why] When a feed is down, a capture finds nobody and a practice looks empty. This page tells a feed that is down apart from a practice nobody played.

## Read the page

1. Open **Settings**, then **Connections**.
2. Each game shows the interface that reads it and whether it is **Configured**: it has a server to read and the credentials for it are set. The credentials and the server addresses are never shown.
3. Under each game is every server a capture reads, with when it was last read and, if it ever failed, when and why. A failure newer than the last read is shown in red.
4. A game read by none says so: its leaders mark attendance by hand.

## Test a connection

1. Choose **Test connection** on a game.
2. The portal reads each of its servers over the last ten minutes, the same way a capture does, and lists what each one answered under the game. It only reads: nothing is sent to the game server and no attendance is marked.
3. The outcome is kept as that server's last read or last failure.

> [!good-to-know] The times on this page come from the captures and the tests, not from a check made when the page opens. A server that shows **credentials not set** is not read at all until its credentials are set. To choose which servers a game reads, use **Servers**.
