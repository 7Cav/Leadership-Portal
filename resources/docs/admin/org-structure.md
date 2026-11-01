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

The page has two cards. **Chain of command** holds the regiment, its battalions (ACD and DEVCOM included) and everything under them. **Regimental staff** holds the portfolios, their departments and the holding groups. ACD and DEVCOM also show under Reserve & Development Command, and DEVCOM Staff under DEVCOM, as reference rows. Choose one to open the unit where it really sits.

> [!good-to-know] A line unit with nobody in it or under it is hidden. Tick **Show empty units** to see it, for example to rename, move or delete it. It shows again on its own once someone is placed in it.

## Import from the Cav roster

1. Open **Settings**, then **Org structure**.
2. Choose **Import from Cav roster**. It adds units the roster has and the portal does not, and maps billets to units. When Cav has moved a billet to another unit, the import moves its mapping too, so the next roster sync places the holder in the new unit. A mapping someone corrected by hand is kept, and the import says how many it kept.

> [!good-to-know] What this changes, and how to undo it: the import adds units and maps billets; it never deletes a unit. To undo a unit it added, delete that unit while it is still empty.

## Add, rename or move a unit

1. To add a unit, select the unit it goes under and choose the button under its details, such as "Add a platoon under Alpha Company". A separate **Add a unit** window opens with the **Echelon** and, under it, the **Parent**. With nothing selected the button adds a battalion under the regiment.
2. Pick which one it is from the list, such as "2nd Section" or "Bravo Company". The list only offers the ones the parent does not have yet, so a platoon with a 1st and 3rd Section still offers the 2nd. The name and callsign follow from your pick and the parent's callsign, the same way the import names units (2nd Section under 1/A/3-7 is 2/1/A/3-7), and cannot be typed in. Choose **Add unit**. The new unit is selected in the tree. It has nobody in it yet, so **Show empty units** is ticked for you.
> [!good-to-know] Who can add: Admins anywhere, and Regimental HQ staff any unit below the regiment. A leader or overseer can add units inside their own unit, at least two echelons below it: a company commander adds sections, a battalion commander platoons, and the overseer of a battalion its companies. Without that, the button is not shown.

3. To rename or move a unit, select it and change it in the details panel.

> [!good-to-know] What this changes, and how to undo it: moving a unit moves everyone's access with it on the next page load. Move it back the same way. A unit with troopers, reports or anything else attached cannot be deleted.

> [!good-to-know] Moving a platoon or section to another company copies the roll call templates its sections use into the new company, named like "Training (from A/1-7)", so their posts read the same. The new company owns the copies from then on; the old company's changes no longer reach them. Moving it back makes new copies the same way. A section's own templates belong to the section, so they move with it and are not copied.

## Make someone acting lead

1. Select the unit and choose **Make acting lead**.
2. Pick the trooper, give a reason, and save. They get exactly a platoon leader's rights over that unit and everything under it.

> [!good-to-know] A unit whose leader billet (CO, PL or SL) someone serving holds already has its lead, so it takes no acting lead. You cannot make yourself acting lead either: ask another Admin.

> [!good-to-know] What this changes, and how to undo it: choose **Remove acting lead** with a reason. Their access to the unit ends on their next page load. Both are in the audit log.

## Set the game a unit plays

1. Select a unit, pick its **Game** from the list, and choose **Save game**. Any unit can have its own game while no unit above it has one. The list has the games kept on the Games page that are not retired.
2. Every unit under it plays the same game. That unit's row shows the game's abbreviation as a chip, such as HLLV; the units under it show no game in the tree, and their details panel shows it locked, such as "Hell Let Loose: Vietnam (from 2-7)".

Admins and Regimental HQ staff can change any unit's game. An overseer can change the games of the units they oversee and those under them, and DEVCOM's 1IC and 2IC those of DEVCOM and its units. Nobody else sees the control.

> [!good-to-know] What this changes, and how to undo it: Game identities opens Add identity on this game, and the practice capture reads the section's practice from it. Pick the inherited choice at the top of the list to clear it. A unit keeps a game that has since been retired, marked retired, until you change it. A unit under one with a game keeps a game of its own set before until you choose **Clear game**; no new one can be set there. A unit with units under it that have their own games cannot be given one until you clear those; the error names them. Both are in the audit log.

## Set a nickname

1. Select the unit, fill in **Nickname**, such as Jaegers, and choose **Save nickname**. It shows next to the unit's name on the rosters of that unit and of the company or platoon above it, not in the Org structure tree or across a battalion. It is free text and changes nothing else; to rename the unit itself, change **Name** above it.

## Set who oversees a battalion

1. Select a battalion or the Reservists. Under **Oversight**, search for the trooper.
2. Pick them, give a reason, and choose **Set overseer**. They can then see that unit and everything under it, read only, while they serve.

> [!good-to-know] What this changes, and how to undo it: to change the overseer, pick someone else and choose **Set overseer** again. To remove them, choose **Clear overseer** with a reason. Their access ends on their next page load, or when they leave every Cav roster. Every change is in the audit log. The portal sets none of these itself; an Admin enters each one.
