---
title: Org structure and acting leads
summary: Keep the unit tree right, and put someone in charge of a unit without the billet.
section: admin
order: 1
audience: admin
page: /settings/org-structure
---

> [!why] Everyone's access comes from where their billet sits in this tree. A unit in the wrong place gives the wrong people access to it, and a missing acting lead leaves a unit with nobody who can run its rollcall.

## Import from the Cav roster

1. Open **Settings**, then **Org structure**.
2. Choose **Import from Cav roster**. It adds units the roster has and the portal does not, and maps billets to units.

> [!good-to-know] What this changes, and how to undo it: the import adds units and maps billets; it never deletes a unit. To undo a unit it added, delete that unit while it is still empty.

## Add, rename or move a unit

1. Choose **Add unit**, or select a unit to edit it.
2. Set its **Name**, **Callsign**, **Echelon** and **Parent**, and choose **Save unit**.

> [!good-to-know] What this changes, and how to undo it: moving a unit moves everyone's access with it on the next page load. Move it back the same way. A unit with troopers, reports or anything else attached cannot be deleted.

> [!good-to-know] Moving a platoon or section to another company copies the rollcall templates its sections use into the new company, named like "Training (from A/1-7)", so their posts read the same. The new company owns the copies from then on; the old company's changes no longer reach them. Moving it back makes new copies the same way. A section's own templates belong to the section, so they move with it and are not copied.

## Make someone acting lead

1. Select the unit and choose **Make acting lead**.
2. Pick the trooper, give a reason, and save. They get exactly a platoon leader's rights over that unit and everything under it.

> [!good-to-know] What this changes, and how to undo it: choose **Remove acting lead** with a reason. Their access to the unit ends on their next page load. Both are in the audit log.

## Set the game a unit plays

1. Select the unit, pick its **Game** from the list, and choose **Save game**. The list has the games kept on the Games page that are not retired.
2. Every unit under it plays the same game, unless it has its own. The game shows next to each unit's name; one a unit inherits is muted, with the unit it comes from, such as "WARDOGS from 2/A/1-7".

> [!good-to-know] What this changes, and how to undo it: Game identities opens Add identity on this game, and the practice capture reads the section's practice from it. Pick **None (inherits)** to clear it, and the unit plays the game of the nearest unit above it again. A unit keeps a game that has since been retired, marked retired, until you change it. Both are in the audit log.

## Set a team label

1. Select the unit, fill in **Team label**, and choose **Save label**. It shows next to the unit's name. It is free text and changes nothing else.
