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
3. CRCON lists one server per CRCON the portal is set up with, by the name the server gives itself. A new one comes with its game filled in when CRCON says which game it runs. If one CRCON cannot be reached, the others still list.

## Set up a server

1. In a server's row, tick **Selected**, choose its **Game**, and choose its **Type**: training or public.
2. For a CRCON server, you can choose a **Fallback**: the same server tracked on BattleMetrics, read when CRCON cannot be reached.
3. Choose **Save** on that row.

## Add the 7Cav Hell Let Loose servers

The unit runs a CRCON for each Hell Let Loose server. Once all of them are set up for the portal, **Fetch servers** lists five:

| Server | Game | Type |
| --- | --- | --- |
| WW2 - Tac East | Hell Let Loose (WW2) | Public |
| WW2 - Training Server 1 | Hell Let Loose (WW2) | Training |
| Vietnam - Tac East | Hell Let Loose: Vietnam | Public |
| Vietnam - Tac Central | Hell Let Loose: Vietnam | Public |
| Vietnam - Training 1 | Hell Let Loose: Vietnam | Training |

1. Tick **Selected** on the two training servers, check the **Game**, set **Type** to training and save each row.
2. Leave the public servers unselected, or select them as public: a public server is never read for attendance.
3. A CRCON server from before this list existed shows as **No longer listed**. It keeps reading the same CRCON it always did; once the new rows are set up, untick it and save.

## Check what each game reads

1. **What each game reads** lists, for each game, the selected training servers and when each was last read.
2. A game with no training server selected reads nothing, and its leaders mark attendance by hand.

> [!good-to-know] What this changes, and how to undo it: only a selected training server is read for attendance; a public one never is. Untick **Selected** and save to stop reading a server. A server the services no longer list is marked **No longer listed** and is kept, so its past captures still show.
