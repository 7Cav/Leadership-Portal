---
title: Staff grants
summary: Let someone without a leadership billet read a unit, without ever changing anything there.
section: admin
order: 2
audience: admin
page: /settings/staff-grants
---

> [!why] Staff such as S1 need to see units they do not command. A grant gives read access only: the holder can open the unit's roster, roll call, reports and game identities, and can never mark, send or change anything there.

## Grant or revoke scope

1. Open **Settings**, then **Staff grants**.
2. Choose the user and the unit. The grant covers the unit and everything under it.
3. Give a reason and choose **Grant scope**.
4. To take a grant back, choose **Revoke** next to it under **Current grants**, with a reason. The holder loses read access to that unit on their next page load. To undo it, grant it again. Granting and revoking are both in the audit log.

> [!good-to-know] A grant only counts while its holder is a serving trooper. When they leave the Cav, it stops counting at once and is shown as lapsed.

> [!good-to-know] Regimental HQ staff, such as the Chief of Staff and the Adjutants General, need no grant. They read the whole regiment, and are Admins once an Admin approves their billet.

## See who is Admin via billet

Under **Admin via billet**, the page lists every approved billet: its title and group, everyone who holds it now (a billet can have more than one holder, such as two Adjutants General), and when and by whom it was approved. Whoever holds one of these billets is an Admin because of the billet, not a grant. They lose it as soon as they leave the billet or stop serving. Where they sit in the org structure does not matter, so S6 HQ staff in the S6 department count too.

A billet tagged **Not an Admin group** sits in a group whose billets can no longer make an Admin, so its holders are not Admins.

Under the list, a short note names the Regimental HQ staff placed on the regiment. They read the whole regiment, read-only, whether or not their billet is approved, until Cav moves them to a billet outside Regimental HQ.

## Revoke an Admin billet

1. Under **Admin via billet**, choose **Revoke** next to the billet.
2. Give a reason. It goes to the audit log as a rejection.

Its holders stop being Admins on their next page load, and the billet moves down to **Admin billets** as rejected. You cannot revoke the billet you hold yourself.

## Approve an Admin billet

Every Regimental HQ and S6 HQ billet the org import finds is listed under **Admin billets**, as waiting for an Admin. Each row shows the billet's title and group now, who holds it, and its status. Approved billets move up to **Admin via billet**.

1. Choose **Approve** to make whoever holds the billet an Admin, or **Reject** to leave it a plain billet.
2. Give a reason. It goes to the audit log.

> [!good-to-know] When Cav renames a billet or moves it to another group, the billet waits here again, tagged **Renamed**, with "Approved as:" and the old names under the new ones. Its holder stops being an Admin until someone approves the new names. A rejected billet stays rejected until its name changes.

> [!good-to-know] A billet moved out of Regimental HQ and S6 HQ is tagged **Not an Admin group** and cannot be approved. Reject it, or wait for Cav to move it back.

> [!good-to-know] You cannot approve or reject the billet you hold yourself. Ask another Admin.

While any billet waits, Admins see a count beside **Staff grants** in the sidebar. Rejected billets do not count.
