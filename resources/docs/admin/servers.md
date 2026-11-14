---
title: Servers
summary: Choose which game servers the portal reads attendance from, which game each one is for, and check that the portal can read them.
section: admin
order: 9
updated: 2026-11-12
audience: admin
page: /settings/servers
---

> [!why] The practice capture only reads a server that is selected, set to a game and marked as training. A server left out, or set to the wrong game, means its practices are not captured.

## Fetch the servers

1. Open **Settings**, then **Servers**, and choose **Fetch servers**.
2. The portal asks each telemetry service whose credentials are set which servers it can see, and lists them under **Fetched servers**. A service with no credentials shows as **Not configured**.
3. CRCON lists one server per CRCON the portal is set up with, by the name the server gives itself. A new one comes with its game filled in when CRCON says which game it runs. If one CRCON cannot be reached, the others still list.

## Set up a server

1. In a server's row, tick **Selected**, choose its **Game**, and choose its **Type**: training or public.
2. For a CRCON server, you can choose a **Fallback**: the same server tracked on BattleMetrics, read when CRCON cannot be reached.
3. Choose **Save** on that row.

## Add the 7Cav Hell Let Loose servers

The unit runs a CRCON for each Hell Let Loose server, both WW2 and Vietnam. Once all of them are set up for the portal, **Fetch servers** lists each one by its name, with its game filled in.

1. Tick **Selected** on each training server, check the **Game**, set **Type** to training and save each row.
2. Leave the public servers unselected, or select them as public: a public server is never read for attendance.
3. A CRCON server from before this list existed shows as **No longer listed**. It keeps reading the same CRCON it always did; once the new rows are set up, untick it and save.

## Check what each game reads

1. **What each game reads** lists, for each game, the interface that reads it, whether it is **Configured** (it has a server to read and the credentials for it are set) and its selected servers.
2. Under that is every server a capture reads for the game, with when it was last read and, if it ever failed, when and why. A failure newer than the last read is shown in red, so a feed that is down does not pass for a practice nobody played.
3. A game with no server selected reads the server of its interface set in env, if that server is its own, whatever type it is. Selecting a training server for the game turns this off. A game with no server of its own reads nothing until a training server is selected. A game read by **None** says it is not set up yet, and its leaders mark attendance by hand. A game set to **None (impossible)** shows **Can't be read**: its attendance can never be read automatically, its leaders mark it by hand, and its reports say it is self-reported.
4. The times come from the captures and the tests, not from a check made when the page opens. A server that shows **credentials not set** is not read at all until its credentials are set. One that shows **no server selected** has its key set but no server: select a training server for the game. The credentials and the server addresses are never shown.

## Test a connection

1. Choose **Test connection** on a game.
2. The portal reads each of its servers over the last ten minutes, the same way a capture does, and lists what each one answered under the game. It only reads: nothing is sent to the game server and no attendance is marked.
3. The outcome is kept as that server's last read or last failure.

> [!good-to-know] What this changes, and how to undo it: once a game has a selected server, only its selected training servers are read for attendance; a public one never is. Untick **Selected** and save to stop reading a server. A server the services no longer list is marked **No longer listed** and is kept, so its past captures still show.
