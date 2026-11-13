---
title: Games
summary: Add, edit and retire the games the regiment plays, and what reads their attendance.
section: admin
order: 4
updated: 2026-11-13
audience: admin
page: /settings/games
---

> [!why] A game here is what leaders link troopers' identities to, and what the practice capture reads attendance from. A game set up wrong means practices are not captured.

## Add a game

1. Open **Settings**, then **Games**, and fill in **Add a game**.
2. Give its **Name**, its **Abbreviation** (2 to 6 letters or digits, such as HLLV; left blank, it is made from the name), its **Player ID kinds** (such as Steam ID), and what its **Attendance read by**.
3. Choose **Add game**.

## Pick what reads its attendance

1. **CRCON**, **BattleMetrics** or **Nightwatch** reads the game's servers. Pick its servers on [Servers](/docs/admin/servers).
2. **None** means reading is not set up yet. Leaders mark attendance by hand, and reports show **No game server data** where a check could not be made.
3. **None (impossible)** is for a game whose attendance can never be read automatically, such as Battlefield 6, DCS World, Foxhole or Star Citizen. Leaders mark attendance by hand. Staff reports for its units show no **No game server data** alerts. Instead, each report says once: "Server data can't be read automatically for Battlefield 6. All attendance is self-reported." The weekly reports of those units say the same once near the top, and so does each one's panel on the **Reports** overview.
4. Existing games keep **None** until an Admin switches them. Troopers still link identities for either kind of game.

## Edit or retire a game

1. Choose **Edit** next to it, change it, and choose **Save changes**. The abbreviation is what Org structure shows on the row of a unit that plays it; two games cannot share one.
2. For a game no longer played, choose **Retire**. Its links stay, but no new ones can be made, and it stops being captured.
3. Choose **Bring back** to reinstate it.

## Apply existing Steam or Epic links

When a game asks for a Steam ID or an Epic ID, troopers may already have that account linked on another game.

1. On **Games**, the game shows **Apply existing Steam links** (or Epic) and how many troopers it would link.
2. Choose it to link each of those troopers on this game with the ID they already have. It is recorded in the audit log, and it only runs when you choose it.

It only adds identities and never changes one that exists. A trooper who already has an identity on the game is left out, and so is one with two different IDs of that account on other games, or one whose ID another trooper holds on this game. Link those on **Game identities**.

> [!good-to-know] A game that is in use, with linked identities, captured practices or a unit that plays it, can only be retired, never deleted; **Delete** is offered only for a game nothing uses.
