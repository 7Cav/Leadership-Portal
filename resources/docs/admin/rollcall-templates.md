---
title: Rollcall templates
summary: The templates a rollcall post is built from (a company's, a section's own and the regiment default) and who manages them.
section: admin
order: 3
audience: leader
page: /settings/templates
---

> [!why] Every section's rollcall post is built from a template. A mistake in one shows in the rollcall post of every section that uses it that week.

## Who manages which

1. The **regiment default** shows in every company's list and is used when a company has nothing switched on. Only Admins edit it.
2. A company's own templates are managed by its command (CO, XO, 1SG), the battalion and regiment command above it, and Admins.
3. A section's own templates are managed by its SL and ASL, the platoon, company, battalion and regiment command above it, and Admins. Only that section's rollcall can use them.
4. A staff grant, or someone viewing as a leader, can read templates but not change them.

## Open a company's templates

1. Open **Settings**, then **Templates**, and choose the company.
2. Its templates, each with its versions, and the regiment default are listed. The preview fills itself from one of the company's sections; pick another under **Fill with**.

## Open a section's own templates

1. Choose the company, then the section under **Templates of**.
2. The section's own templates are listed, and the preview fills itself from that section. Add, edit and roll them back as below. They have no switch and are never a company default: the section's leader picks one on the rollcall.

## Add or change a template

1. Add a template, or edit one. **Template codes** lists what it can use, such as `{{name}}`.
2. Give a reason and save. Every save is a new version, and a template with a mistake is refused, so it never reaches a rollcall.

## Choose what sections may use

1. Switch each template on or off for the company's sections.
2. Make one the company default. A section uses its leader's pick if they made one (one of its own, a switched-on company template or the regiment default), else the company default, else the regiment default, so a post is never blank.

> [!good-to-know] What this changes, and how to undo it: the next rollcall post in every section using that template changes. To undo it, make the previous version active, or switch the template off. Nothing is ever overwritten; every save, switch and activation is in the audit log.
