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

The page has two cards. **Chain of command** holds the regiment, its battalions (ACD and DEVCOM, Development Command, included) and everything under them. **Regimental staff** holds the portfolios, their departments and the holding groups. ACD and DEVCOM also show under Reserve & Development Command, 1-7 under the Chief of Staff, 3-7 and 2-7 under the two Deputy Chiefs of Staff, and DEVCOM Staff under DEVCOM, as reference rows. Choose one to open the unit where it really sits.

> [!good-to-know] A line unit with nobody in it or under it is hidden. Tick **Show empty units** to see it, for example to rename, move or delete it. It shows again on its own once someone is placed in it.

> [!good-to-know] A unit whose lead billet is empty says so on its row: **CO vacant**, **PL vacant**, **SL vacant** or **HQ vacant**. A battalion with no overseer says **Oversight vacant**, or **Oversight & CO vacant** when its lead is empty too. Select the unit to see who stands in on its **Lead** line, such as "Lead: MAJ Dread.M (XO, acting CO)". The stand-in is the first one serving of the CO, XO and SGM on a battalion, the Commander, XO and 1SG on a company, the PL and PSG on a platoon, and the SL and ASL on a section. This is only shown: the stand-in gets no extra rights.

## Import from the Cav roster

1. Open **Settings**, then **Org structure**.
2. Choose **Import from Cav roster**. It adds units the roster has and the portal does not, and maps billets to units. When Cav has moved a billet to another unit, the import moves its mapping too, so the next roster sync places the holder in the new unit. A mapping someone corrected by hand is kept, and the import says how many it kept.

> [!good-to-know] What this changes, and how to undo it: the import adds units and maps billets; it never deletes a unit. To undo a unit it added, delete that unit while it is still empty.

## Add, rename or move a unit

1. To add a unit, select the unit it goes under and choose the button under its details, such as "Add a platoon under Alpha Company". A separate **Add a unit** window opens with the **Echelon** and, under it, the **Parent**. With nothing selected the button adds a battalion under the regiment.
2. Pick which one it is from the list, such as "2nd Section" or "Bravo Company". The list only offers the ones the parent does not have yet, so a platoon with a 1st and 3rd Section still offers the 2nd. The name and callsign follow from your pick and the parent's callsign, the same way the import names units (2nd Section under 1/A/3-7 is 2/1/A/3-7), and cannot be typed in. Choose **Add unit**. The new unit is selected in the tree. It has nobody in it yet, so **Show empty units** is ticked for you.
> [!good-to-know] Who can add: for now only Admins add and move units. Everyone else does not see the button. Added the wrong unit? Delete it while it is still empty.

3. To rename or move a unit, select it and change it in the details panel. To move it, pick its new **Parent** and choose **Save unit**. A window lists what changes, each unit under Now and After with its name, its parent and its callsign, such as 1st Platoon, Alpha Company, 1/A/1-7 becoming 1st Platoon, Golf Company, 1/G/1-7. The units under it are listed in a section you can open, such as "Its 2 sections", each with its callsign before and after. Confirm with **Move unit**.

> [!good-to-know] A moved unit takes its callsign from its new parent and keeps its number or letter: 1st Platoon 1/A/1-7 moved under Golf Company becomes 1/G/1-7, and its sections follow, so 1/1/A/1-7 becomes 1/1/G/1-7. A name that was the standard one, such as 1st Platoon, follows a new number; a name you chose is kept. Units without a callsign of that shape, such as groups or ACD, keep theirs.

> [!good-to-know] If the new parent already has that callsign, the window says so, such as "Golf Company already has a 1st Platoon (1/G/1-7)", and the move waits until you pick one way to settle it: **Swap the two platoons**, so the platoon already there takes the old place and callsign of the one you move; **Renumber the platoon already in Golf Company**; or **Give the moved platoon a different number**. For a company it says letter instead of number. The free numbers are listed like in **Add a unit**, and the Now and After rows show the result of the option and number you pick before you confirm. Swapping or renumbering the unit already there needs it in your write scope; otherwise only the last choice is offered and the window says why. Everything is saved together or not at all, and the move is refused if a callsign was taken in the meantime. The audit log lists each callsign before and after.

> [!warning] The import from the Cav roster finds units by callsign. If Cav still lists the old designation, the next import adds a unit with the old callsign again and moves the billets Cav lists there to it. Change the billets on the Cav roster too, or move the unit back.

> [!good-to-know] What this changes, and how to undo it: moving a unit moves everyone's access with it on the next page load. Move it back the same way. A unit with troopers, reports or anything else attached cannot be deleted.

> [!good-to-know] Moving a platoon or section to another company copies the roll call templates its sections use into the new company, named like "Training (from A/1-7)", so their posts read the same. The new company owns the copies from then on; the old company's changes no longer reach them. Moving it back makes new copies the same way. A section's own templates belong to the section, so they move with it and are not copied.

## Make someone acting lead

1. Select the unit and choose **Make acting lead**.
2. Pick the trooper, give a reason, and save. They get exactly a platoon leader's rights over that unit and everything under it.

Both Deputy Chiefs of Staff hold the same billet, so the import cannot tell whose portfolio is whose. Make each one acting lead of theirs: Deputy Chief of Staff 1 (S5, RRD, RTC) and Deputy Chief of Staff 2 (S3, S7). On the regimental staff, only a portfolio takes an acting lead, never a department or holding group.

> [!good-to-know] A unit whose leader billet (CO, PL or SL, or a portfolio's officer) someone serving holds already has its lead, so it takes no acting lead. You cannot make yourself acting lead either: ask another Admin.

> [!good-to-know] What this changes, and how to undo it: choose **Remove acting lead** with a reason. Their access to the unit ends on their next page load. Both are in the audit log.

## Set the game a unit plays

1. Select a unit, pick its **Game** from the list, and choose **Save game**. Any unit can have its own game while no unit above it has one. The list has the games kept on the Games page that are not retired.
2. Every unit under it plays the same game. That unit's row shows the game's abbreviation as a chip, such as HLLV; the units under it show no game in the tree, and their details panel shows it locked, such as "Hell Let Loose: Vietnam (from 2-7)".

Admins and Regimental HQ staff can change any unit's game. An overseer can change the games of the units they oversee and those under them, and DEVCOM's 1IC and 2IC those of DEVCOM and its units. Nobody else sees the control.

> [!good-to-know] What this changes, and how to undo it: Game identities opens Add identity on this game, and the practice capture reads the section's practice from it. Pick the inherited choice at the top of the list to clear it. A unit keeps a game that has since been retired, marked retired, until you change it. A unit under one with a game keeps a game of its own set before until you choose **Clear game**; no new one can be set there. A unit with units under it that have their own games cannot be given one until you clear those; the error names them. Both are in the audit log.

## Set what counts for battalion accountability

1. Select a battalion. Under **What counts for Battalion HQ accountability**, tick the options and choose **Save**. Regimental HQ staff can do this too: they see **Org structure** under **Settings** in the sidebar.
2. Below it, greyed out, is what each unit under the battalion uses now, each linked to its roster's Admin tab. See [Set what counts for accountability](/docs/your-troopers/accountability-options).

## Set a nickname

1. Select the unit, fill in **Nickname**, such as Jaegers, and choose **Save nickname**. It shows next to the unit's name on the rosters of that unit and of the company or platoon above it, not in the Org structure tree or across a battalion. It is free text and changes nothing else; to rename the unit itself, change **Name** above it.

## Set who oversees a battalion

1. Select a battalion or the Reservists. Under **Oversight**, search for the trooper.
2. Pick them, give a reason, and choose **Set overseer**. Only a trooper on the active duty roster can be picked. They can then see that unit and everything under it, read only, while they serve.

> [!good-to-know] What this changes, and how to undo it: to change the overseer, pick someone else and choose **Set overseer** again. To remove them, choose **Clear overseer** with a reason. Their access ends on their next page load. Every change is in the audit log. The portal sets none of these itself; an Admin enters each one.

> [!good-to-know] When the roster sync no longer finds an overseer on the active duty roster (they went ELOA, to the Reservists or were discharged), it removes them as overseer. The audit log shows it with **System** as the user and the reason "No longer found on active duty roster (ELOA, discharge, etc.)". Set a new overseer as usual.
