---
title: Roll call templates
summary: Who can edit the templates a roll call post is built from, where to find them, and how to add, edit, switch on and roll back a template.
section: admin
order: 3
audience: leader
page: /settings/templates
---

> [!why] Every section's roll call post is built from a template. Leaders can edit their own unit's templates, not only Admins. A mistake in one shows in the roll call post of every section that uses it that week.

## Who can edit which

1. A section's own templates: its SL and ASL, the platoon command, the company command and everyone above, and anyone an Admin made acting lead of the section or a unit above it. Only that section's roll call can use them.
2. A company's templates: its command (CO, XO, 1SG), the battalion and regiment command above it, and an acting lead of the company.
3. The regiment default: Admins only. It shows in every company's list and is used when nothing else is picked.
4. Admins can edit all of them. A staff grant, or someone viewing as a leader, can read templates but not change them.

## Open the templates

1. In the sidebar, open **Settings**, then **Templates**. You see it if you can edit at least one unit's templates.
2. Under **Company**, choose the company.
3. Under **Templates of**, choose **The company** for the company's templates, or a section for that section's own.

## Add a template

1. Open the company or section the template is for, and choose **New template**.
2. Give it a **Name** and a **Reason for the record**, then choose **Add template**. It starts as a copy of the template you had open, or of the default.
3. A company template starts switched on for the company's sections. A section's own template is used only once its leader picks it.

## Edit and preview it

1. Choose the template in the list. It opens in **Visual**: the text formatted as the forum shows it, each code as a gold chip named for what it prints (**Trooper name**, **Roll call mark**), and the trooper loop as a row marked **Repeats for each trooper**.
2. Type into it as into any text. Select text and use the toolbar for bold, italic, underline, size, colour, centre and a link, or press Ctrl+B, Ctrl+I or Ctrl+U. The toolbar also adds an image, a trooper table, and a column after the one the cursor is in (or removes it), in the header and the trooper row alike. Ctrl+Z takes a change back.
3. **Insert code** lists only the codes that can go where the cursor is: the post's own codes (callsign, week, counts, the live images) anywhere, a trooper's own codes as well inside the trooper loop, and the loop itself as well outside it. A chip is deleted whole with Backspace, and deleting either end of a condition (**If Will attend** … **End**) removes the condition but keeps what it showed.
4. **Source** shows the template as written, BBCode and codes, for whoever prefers to type it. Both edit the same text, and switching between them changes nothing. The page remembers which one you used last.
5. **Preview**, beside the editor, shows the post as it would be this week, as you type. Under **Fill with**, pick which section fills it, or **Sample section**.
6. A mistake shows under the editor as you type, with the line and column and the same words the save would be refused with. **Show in Source** selects it.
7. Give a **Reason** and choose **Save as a new version**. A template with a mistake is refused, so it never reaches a roll call.

## Choose what the sections use

1. On a company template, choose **Switch on** so its sections can pick it, or **Switch off**.
2. Choose **Make company default** on the one sections use when their leader picks nothing. Only a switched-on template can be the company default.
3. A section's leader picks the template on the roll call page, under **Template**: one of the section's own, a switched-on company template, the regiment default, or **Company default**. The pick is kept for later weeks. See [Run this week's roll call](/docs/weekly-rollcall/run-rollcall).
4. With no pick, a section uses the company default, else the regiment default, so a post is never blank.

## Versions and rollback

1. Every save is a new version, and the newest is active. Older ones are listed under **Versions** and never overwritten.
2. To roll back, choose **Make active** on an earlier version and give a reason. The next roll call post uses it.

## The template language

1. Write the BBCode as normal and put codes where the week's data goes. `{{name}}` prints a value.
2. `{{#troopers}}...{{/troopers}}` repeats for each trooper. `{{#name}}...{{/name}}` shows only when a value or flag is set, and `{{^name}}...{{/name}}` only when it is not.
3. `{{name|upper}}` and `{{name|lower}}` print a value in capitals or small letters.
4. **Template codes** on the page lists every code you can use, with an example. Click one to put it in the template at the cursor, in **Visual** or **Source**. A code not on the list is refused when you save.
5. To keep the Signed RC and Attended SP marks current after the post is on the forum, put the two live images in the template: `[IMG]{{rc_image}}[/IMG]` for who signed and `[IMG]{{sp_image}}[/IMG]` for who attended practice. Each lists every trooper with their mark, and the portal draws it from the marks as they are when the forum fetches it, so a mark changed later shows without pasting the post again (the forum may reuse an image for about a minute). The text table keeps working beside them if you want both.
6. To show the week's training in the post, use `{{training_date}}` for when practice starts (such as `Wed 20May26 2300Z`) and `{{training_topic}}` for the topic, which is the same one the weekly report shows. Either is empty until it is set, so `{{#training_topic}}Topic: {{training_topic}}{{/training_topic}}` leaves the line out when there is none.

> [!good-to-know] What this changes, and how to undo it: the next roll call post in every section using that template changes. To undo it, make the previous version active, or switch the template off. Every save, switch and activation is in the audit log.
