---
title: Org structure, acting leads and oversight
summary: Keep the unit tree right, put someone in charge of a unit without the billet, and name who oversees a battalion.
section: admin
order: 1
audience: admin
page: /settings/org-structure
---

> [!why] Everyone's access comes from where their billet sits in this tree. A unit in the wrong place gives the wrong people access to it, and a missing acting lead leaves a unit with nobody who can run its roll call.

## Read the page

The page has two cards. **Chain of command** holds the regiment, its battalions (ACD and DEVCOM, Development Command, included) and everything under them. **Regimental staff** holds the portfolios, their departments and the holding groups. ACD and DEVCOM also show under Reserve & Development Command, and DEVCOM Staff under DEVCOM, as reference rows. Choose one to open the unit where it really sits.

> [!good-to-know] A line unit with nobody in it or under it is hidden. Tick **Show empty units** to see it, for example to rename, move or delete it. It shows again on its own once someone is placed in it.

## Import from the Cav roster

1. Open **Settings**, then **Org structure**.
2. Choose **Import from Cav roster**. It adds units the roster has and the portal does not, and maps billets to units. When Cav has moved a billet to another unit, the import moves its mapping too, so the next roster sync places the holder in the new unit. A mapping someone corrected by hand is kept, and the import says how many it kept.

> [!good-to-know] What this changes, and how to undo it: the import adds units and maps billets; it never deletes a unit. To undo a unit it added, delete that unit while it is still empty.

## Add, rename or move a unit

1. Choose **Add unit**, or select a unit to edit it.
2. Set its **Name**, **Callsign**, **Echelon** and **Parent**, and choose **Save unit**.

> [!good-to-know] What this changes, and how to undo it: moving a unit moves everyone's access with it on the next page load. Move it back the same way. A unit with troopers, reports or anything else attached cannot be deleted.

> [!good-to-know] Moving a platoon or section to another company copies the roll call templates its sections use into the new company, named like "Training (from A/1-7)", so their posts read the same. The new company owns the copies from then on; the old company's changes no longer reach them. Moving it back makes new copies the same way. A section's own templates belong to the section, so they move with it and are not copied.

## Make someone acting lead

1. Select the unit and choose **Make acting lead**.
2. Pick the trooper, give a reason, and save. They get exactly a platoon leader's rights over that unit and everything under it.

> [!good-to-know] A unit whose leader billet (CO, PL or SL) someone serving holds already has its lead, so it takes no acting lead. You cannot make yourself acting lead either: ask another Admin.

> [!good-to-know] What this changes, and how to undo it: choose **Remove acting lead** with a reason. Their access to the unit ends on their next page load. Both are in the audit log.

## Set the game a unit plays

1. Select the unit, pick its **Game** from the list, and choose **Save game**. The list has the games kept on the Games page that are not retired.
2. Every unit under it plays the same game, unless it has its own. The game shows next to each unit's name; one a unit inherits is muted, with the unit it comes from, such as "WARDOGS from 2/A/1-7".

> [!good-to-know] What this changes, and how to undo it: Game identities opens Add identity on this game, and the practice capture reads the section's practice from it. Pick **None (inherits)** to clear it, and the unit plays the game of the nearest unit above it again. A unit keeps a game that has since been retired, marked retired, until you change it. Both are in the audit log.

## Set a team label

1. Select the unit, fill in **Team label**, and choose **Save label**. It shows next to the unit's name. It is free text and changes nothing else.

## Set who oversees a battalion

1. Select a battalion or the Reservists. Under **Oversight**, search for the trooper.
2. Pick them, give a reason, and choose **Set overseer**. They can then see that unit and everything under it, read only, while they serve.

> [!good-to-know] What this changes, and how to undo it: to change the overseer, pick someone else and choose **Set overseer** again. To remove them, choose **Clear overseer** with a reason. Their access ends on their next page load, or when they leave every Cav roster. Every change is in the audit log. The portal sets none of these itself; an Admin enters each one.
