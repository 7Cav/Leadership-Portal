---
title: Audit log
summary: "Every change made in the portal: who, what, when and why."
section: admin
order: 7
audience: admin
page: /settings/audit-log
---

> [!why] When something changed and nobody knows why, the audit log answers it. Every change in the portal is written here in the same step as the change itself, so nothing is missing.

## Find an entry

1. Open **Settings**, then **Audit log**. The newest entries are first.
2. Filter by **Actor**, **Action**, **Target type**, **Target ID**, dates, or **Unit**. A unit includes everything under it.
3. Choose **Apply filters**, or **Clear** to start again.

## Read an entry

1. **Actor** is who did it. When an Admin acted as someone, the entry also says who or which billet, such as "acting as Vasquez.R". An Admin by billet (a Regimental HQ, S6 1IC or S6 2IC billet an Admin approved) shows as the actor like any other Admin; approving such a billet is recorded as `admin_billet.approved`, and rejecting or revoking it as `admin_billet.rejected`.
2. **Action** is what happened, such as `weekly_report.sent`. **Target** names what the entry is about, with its week or date where it has one, such as "Staff report of 1LT Lindqvist.P, week 17OCT26 to 23OCT26" or "DCS for PFC Novak.E dated 10OCT26". Under it, each change reads as before and after, such as "Slot: none → Wed 2359Z", and a link opens the page that shows it. Free text such as a counseling reason is never shown here.
3. **Reason** is the reason given, where the action asks for one.
4. **Digest** is a fingerprint of the change, so a record can be proven unchanged since.

> [!good-to-know] An entry keeps the name it had when it was written, so it still reads the same after what it is about is deleted. Entries from before this was stored are named from what they are about as it is now, without the before and after.

Starting and stopping view as, and act as, are recorded too. The audit log cannot be edited or deleted from the portal.
