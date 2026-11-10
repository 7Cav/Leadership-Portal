---
title: Roster and leave syncs
summary: When the portal refreshes the roster and leave, and when to run one by hand.
section: admin
order: 6
audience: admin
---

> [!why] The portal reads the Cav roster and the forum's leave threads, it never writes to them. If either is out of date, roll calls show the wrong troopers or miss someone on leave.

## What runs on its own

1. The roster syncs every hour.
2. Leave syncs every hour at a quarter past, reading the last two weeks, and once a day at 0330Z reading the whole year again. Leave a trooper cancelled by deleting their thread is removed from the portal by that daily run.
3. Pages that show roster or leave data say when it last synced, and warn when a sync failed.
4. Leave has no sync button yet. If leave is wrong after the hourly run, ask S6 to run the leave sync on the server.
5. The first leave sync reads the whole year in one run. Only ELOA, which is read from the forum, still goes back a share at a time; until that is done, the leave note says "Older leave is still being read" and how far back it has got.

## Sync the whole roster now

1. Open **Roster** and choose **Sync now**. It runs in the background; reload after a minute.
2. Do this after a batch of transfers or promotions, so roll calls are right before practice.

> [!good-to-know] A leader can sync their own unit with **Sync this unit**, every 5 minutes at most. Admins have no cooldown.

## When leave looks wrong

1. When the latest leave sync had problems, **Roster** shows Admins a warning under the leave note. Open it to see the threads it could not read, each linked to the forum, and how many posts need review. A post that needs review is fixed by the trooper on the forum; the next sync reads it again.
2. A leave post that is deleted, hidden or moved out of the leave sections is removed from the portal by the next clean daily read, once it is confirmed gone. If it is put back, it is stored again.

> [!good-to-know] What this changes, and how to undo it: a sync only refreshes the portal's copy. To undo a wrong change, fix the milpac or the leave post at the source, then sync again.
