---
title: Rollcall templates
summary: The templates a company's rollcall posts are built from, who manages them, and the regiment default.
section: admin
order: 3
audience: admin
page: /settings/templates
---

> [!why] Every section's rollcall post is built from a template. A mistake in one shows in the rollcall post of every section that uses it that week.

## Who manages which

1. The **regiment default** shows in every company's list and is used when a company has nothing switched on. Only Admins edit it.
2. A company's own templates are managed by its command (CO, XO, 1SG), the battalion and regiment command above it, and Admins.
3. Battalion command can switch a company's template editing off. Then battalion command and Admins manage that company's templates, and the company sees why its controls are gone.

## Open a company's templates

1. Open **Settings**, then **Templates**, and choose the company.
2. Its templates, each with its versions, and the regiment default are listed. The preview fills itself from one of the company's sections; pick another under **Fill with**.

## Add or change a template

1. Add a template, or edit one. **Template codes** lists what it can use, such as `{{name}}`.
2. Give a reason and save. Every save is a new version, and a template with a mistake is refused, so it never reaches a rollcall.

## Choose what sections may use

1. Switch each template on or off for the company's sections.
2. Make one the company default. A section uses its leader's pick if they made one, else the company default, else the regiment default, so a post is never blank.

> [!good-to-know] What this changes, and how to undo it: the next rollcall post in every section using that template changes. To undo it, make the previous version active, or switch the template off. Nothing is ever overwritten; every save, switch and activation is in the audit log.
