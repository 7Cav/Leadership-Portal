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
2. An entry with a small arrow in front of it has more to say. Choose the arrow to open it, and again to close it.

> [!good-to-know] Only the changelog editors can change it, and only while signed in as themselves, never while someone acts as them or during View as.

## Add entries

1. Choose **Add**, or **Add entry** on the blue "Changelog outdated" bar that shows after a new version goes live.
2. Check the **Date**. It starts at today in Zulu.
3. Write one change per row. Press Enter, or choose the plus at the end of a row, to add a row below it. To add several empty rows at once, set the number next to **Add 3 rows** and choose it. Remove a row with its bin.
4. To say more about a change, tick **Add additional information** under its row and write it in Markdown. Raw HTML is removed.
5. Choose the add button under the rows; it counts the entries, such as **Add 2 entries**. Empty rows are left out. The bar goes away for both editors.

## Edit or delete an entry

1. Point at the entry and choose the pencil, change it, and choose **Save changes**.
2. To remove it, choose the bin, give a reason, and choose **Delete entry**.

## Import many entries at once

1. Choose **Bulk import** and paste Markdown like this:

   ```
   ## 2026-10-29
   - One change per line
     Indented lines under a change are its additional information.
   - Another change
   ```

2. Choose **Preview**. Fix any line it lists as wrong.
3. Leave **Skip changes that already exist on the same date** ticked to leave out entries you already have; the preview marks them.
4. Choose **Import**.

## Dismiss the bar

1. When a new version needs no entry, choose **Dismiss** on the bar. It stays away until the next version goes live.
