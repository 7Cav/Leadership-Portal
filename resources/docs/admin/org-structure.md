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
2. Choose **Import from Cav roster**. It adds units the roster has and the portal does not, and maps billets to units. When Cav has moved a billet to another unit, the import moves its mapping too, so the next roster sync places the holder in the new unit. A mapping someone corrected by hand is kept, and the import says how many it kept.

> [!good-to-know] What this changes, and how to undo it: the import adds units and maps billets; it never deletes a unit. To undo a unit it added, delete that unit while it is still empty.

## Add, rename or move a unit

1. Choose **Add unit**, or select a unit to edit it.
2. Set its **Name**, **Callsign**, **Echelon** and **Parent**, and choose **Save unit**.

> [!good-to-know] What this changes, and how to undo it: moving a unit moves everyone's access with it on the next page load. Move it back the same way. A unit with troopers, reports or anything else attached cannot be deleted.

> [!good-to-know] Moving a platoon or section to another company copies the rollcall templates its sections use into the new company, named like "Training (from A/1-7)", so their posts read the same. The new company owns the copies from then on; the old company's changes no longer reach them. Moving it back makes new copies the same way. A section's own templates belong to the section, so they move with it and are not copied.

## Make someone acting lead

1. Select the unit and choose **Make acting lead**.
2. Pick the trooper, give a reason, and save. They get exactly a platoon leader's rights over that unit and everything under it.

> [!good-to-know] A unit whose leader billet (CO, PL or SL) someone serving holds already has its lead, so it takes no acting lead. You cannot make yourself acting lead either: ask another Admin.

> [!good-to-know] What this changes, and how to undo it: choose **Remove acting lead** with a reason. Their access to the unit ends on their next page load. Both are in the audit log.

## Set a game or team label

1. Select the unit, fill in **Game or team label** (such as WARDOGS), and choose **Save label**. It shows next to the unit's name.
