---
title: Staff grants
summary: Let someone without a leadership billet read a unit, without ever changing anything there.
section: admin
order: 2
audience: admin
page: /settings/staff-grants
---

> [!why] Staff such as S1 need to see units they do not command. A grant gives read access only: the holder can open the unit's roster, rollcall, reports and game identities, and can never mark, send or change anything there.

## Grant scope

1. Open **Settings**, then **Staff grants**.
2. Choose the user and the unit. The grant covers the unit and everything under it.
3. Give a reason and choose **Grant scope**.

> [!good-to-know] A grant only counts while its holder is a serving trooper. When they leave the Cav, it stops counting at once and is shown as lapsed.

> [!good-to-know] Regimental HQ staff, such as the Chief of Staff and the Adjutants General, need no grant. They read the whole regiment, and are Admins once an Admin approves their billet.

## See Regimental HQ staff

Under **Regimental HQ staff (Admin by billet)**, the page lists every serving trooper placed on the regiment by a Regimental HQ billet, with their name, billet and whether they are an Admin now. They are Admins because of their billet, not a grant: they lose it as soon as they leave the billet or stop serving, and while their billet waits for approval they only read the whole regiment. There is nothing to revoke here.

## Approve an Admin billet

Every Regimental HQ billet the org import finds is listed under **Admin billets**, as waiting for an Admin. Each row shows the billet's title and group now, who holds it, and the names last approved.

1. Choose **Approve** to make whoever holds the billet an Admin, or **Reject** to leave it a plain Regimental HQ billet.
2. Give a reason. It goes to the audit log.

> [!good-to-know] When Cav renames a billet or its group, the billet waits here again and its holder stops being an Admin until someone approves the new names. A rejected billet stays rejected until its name changes.

> [!good-to-know] You cannot approve or reject the billet you hold yourself. Ask another Admin.

While any billet waits, Admins see a count beside **Staff grants** in the sidebar.

## Revoke a grant

1. Under **Current grants**, choose **Revoke** next to it, with a reason.

> [!good-to-know] What this changes, and how to undo it: the holder loses read access to that unit on their next page load. To undo it, grant it again. Granting and revoking are both in the audit log.
