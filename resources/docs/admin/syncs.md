---
title: Roster and leave syncs
summary: When the portal refreshes the roster and leave, and when to run one by hand.
section: admin
order: 6
audience: admin
---

> [!why] The portal reads the Cav roster and the forum's leave threads, it never writes to them. If either is out of date, rollcalls show the wrong troopers or miss someone on leave.

## What runs on its own

1. The roster syncs every hour.
2. Leave syncs every hour at a quarter past, reading new posts, and once a day at 03:30 UTC reading every thread again.
3. Pages that show roster or leave data say when it last synced, and warn when a sync failed.
4. The first leave sync reads a year of posts, newest first. That takes several hourly runs; each one carries on where the last stopped. Until it is done, the leave note says "Older leave is still being read" and how far back it has got. This week's leave is read first.

## Sync the whole roster now

1. Open **Roster** and choose **Sync now**. It runs in the background; reload after a minute.
2. Do this after a batch of transfers or promotions, so rollcalls are right before practice.

> [!good-to-know] A leader can sync just their own unit with **Sync this unit**, every 5 minutes at most. Admins have no cooldown.

## Leave

1. There is no button for leave yet. If leave is wrong after the hourly run, ask S6 to run the leave sync on the server.
2. A leave post that is deleted, hidden or moved out of the leave sections stops counting after the next clean daily read. If it is put back, it counts again.

> [!good-to-know] What this changes, and how to undo it: a sync only refreshes the portal's copy. To undo a wrong change, fix the milpac or the leave post at the source, then sync again.
