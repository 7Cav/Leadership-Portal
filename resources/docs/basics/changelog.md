---
title: Write the changelog
summary: Read what changed in the portal, and for the changelog editors, add and import entries.
section: basics
order: 8
audience: everyone
page: /changelog
---

> [!why] The changelog tells everyone what changed in the portal. A dot on **Changelog** in the sidebar means there is an entry you have not seen yet.

## Read the changelog

1. Choose **Changelog** under Help in the sidebar. The newest date is at the top. Dates are Zulu days.
2. **Major changes** lists the bigger changes. **Full changelog** lists every change, the major ones too. Choose **Full changelog** to open or close it; the page remembers your choice in this browser.
3. An entry with a small arrow in front of it has more to say. Choose the arrow to open it, and again to close it.
4. Use the chips above the columns to show only one kind of change: **Major**, **Added**, **Changed**, **Fixed**, **Removed** or **Security**. **All** shows everything. The filter applies to both columns, and the page remembers it in this browser. Changes that start with "Security:" carry a Security badge.
5. If you see **Show technical and security items** next to the chips, tick it to also list behind-the-scenes work such as security fixes and updates. They are marked Technical, and a **Technical** chip appears. The page remembers your choice in this browser.

Under the page title you see how many changes are recorded and since which date.

> [!good-to-know] Admins choose whether technical and security items are available to everyone with **Make technical and security items available to everyone**, behind the gear button (**Changelog settings**) at the top of the page. When it is off, only the changelog editors see them.

> [!good-to-know] Only the changelog editors can change it, and only while signed in as themselves, never while someone acts as them or during View as.

## Add entries

1. Choose **Add**, or **Add entry** on the blue "Changelog outdated" bar that shows after a new version goes live.
2. Check the **Date**. It starts at today in Zulu.
3. Write one change per row, in plain words that say what changed for the reader. Use the names on the portal's own buttons and pages, keep it to one short sentence, and write fixes as "Fixed ...". Press Enter, or choose the plus at the end of a row, to add a row below it. To add several empty rows at once, set the number next to **Add 3 rows** and choose it. Remove a row with its bin.
4. Every change goes in the full changelog. Tick **Major change** under a row to also list it under **Major changes**, or **Technical** for behind-the-scenes and security work. A change is never both. For a security fix, say what was fixed, not how it could be abused.
5. To say more about a change, tick **Add additional information** under its row and write it in Markdown. Raw HTML is removed.
6. Choose the add button under the rows; it counts the entries, such as **Add 2 entries**. Empty rows are left out. The bar goes away for both editors.

## Edit or delete an entry

1. Point at the entry and choose the pencil, change it, and choose **Save changes**. Tick or clear **Major change** or **Technical** there too.
2. To remove it, choose the bin, give a reason, and choose **Delete entry**.

## Delete all entries

1. Choose **Delete all**. The dialog says how many entries go, such as "Delete all 265 entries?".
2. Choose **Delete all** in the dialog. Every entry leaves the page for everyone.
3. For 72 hours a bar at the top says when they were deleted and until when you can bring them back. Choose **Restore** on it to bring every entry back as it was.

> [!good-to-know] If you delete all again within the 72 hours, **Restore** brings back both sets and the 72 hours start again. After 72 hours the deleted entries are gone for good.

## Import many entries at once

1. Choose **Bulk import** and paste Markdown like this:

   ```
   ## 2026-10-29
   - One change per line
     Indented lines under a change are its additional information.
   - Another change
   - [major] A bigger change
   - [technical] Dependency updates
   ```

   Start a change with `[major]` to also list it under **Major changes**, or with `[technical]` to mark it as technical. The marker is not saved as part of the text. A change cannot have both.

2. Choose **Preview**. Fix any line it lists as wrong.
3. Leave **Skip changes that already exist on the same date** ticked to leave out entries you already have; the preview marks them.
4. Choose **Import**.

## Dismiss the bar

1. When a new version needs no entry, choose **Dismiss** on the bar. It stays away until the next version goes live.
