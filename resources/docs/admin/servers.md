---
title: Servers
summary: Choose which game servers the portal reads attendance from, and which game each one is for.
section: admin
order: 9
audience: admin
page: /settings/servers
---

> [!why] The practice capture only reads a server that is selected, set to a game and marked as training. A server left out, or set to the wrong game, means its practices are not captured.

## Fetch the servers

1. Open **Settings**, then **Servers**, and choose **Fetch servers**.
2. The portal asks each telemetry service whose credentials are set which servers it can see, and lists them under **Fetched servers**. A service with no credentials shows as **Not configured**.

## Set up a server

1. In a server's row, tick **Selected**, choose its **Game**, and choose its **Type**: training or public.
2. For a CRCON server, you can choose a **Fallback**: the same server tracked on BattleMetrics, read when CRCON cannot be reached.
3. Choose **Save** on that row.

## Check what each game reads

1. **What each game reads** lists, for each game, the selected training servers and when each was last read.
2. A game with no training server selected reads nothing, and its leaders mark attendance by hand.

> [!good-to-know] What this changes, and how to undo it: only a selected training server is read for attendance; a public one never is. Untick **Selected** and save to stop reading a server. A server the services no longer list is marked **No longer listed** and is kept, so its past captures still show.
