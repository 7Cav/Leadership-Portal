---
title: Counseling import
summary: Bring older DCSs and LORs into the portal from their Google Drive links or PDFs.
section: admin
order: 10
audience: admin
page: /settings/counseling-import
---

> [!why] Older DCSs and LORs live only as PDFs on Google Drive. Importing them puts each one on the trooper's milpac, where it counts toward their standing and follows the same 30-day and 90-day windows as a record written in the portal.

## Fill in the template

1. Open **Settings**, then **Counseling import**, and choose **Download CSV template**.
2. Add one row per document. Give either a `drive_url` or the `file` name of a PDF you will upload, never both.
3. Fill in the other columns where you can:
   - `type`: DCS or LOR.
   - `trooper` and `issuer`: their Last.F, such as `Okafor.D`.
   - `kind`: `conduct` or `missed_practice`. Left blank, the record counts as conduct.
   - `counseled_on`: the Date of Letter, as YYYY-MM-DD.

   A blank column is read from the PDF's form when the portal can. The CSV wins over the form when both give a value.

> [!good-to-know] A Drive link must be shared as "Anyone with the link". The portal downloads it like someone without a Google account would. It cannot open a file shared only with named people, and it does not take folder links. Import up to 200 rows at a time.

## Upload and check

1. Choose the CSV, then the PDFs its `file` column names, and choose **Check files**.
2. Your browser reads each PDF's form before it is uploaded. Nothing is saved yet.
3. The check lists every row with its result:
   - **Import**: the record is stored as it is.
   - **Needs review**: the record is stored and flagged so someone can fill in its fields by hand. This happens for a Drive link, a scanned or flattened PDF, or a form with empty fields.
   - **Skipped**: the row is left out, and the reason says why. Common reasons are a missing date or type, a trooper not on the roster, a link that is not public or not a PDF, and a document already imported.
4. When two troopers share a Last.F, the row asks you to pick one. If you do not pick, the row is left out.
5. A row without a matching issuer is attributed to you, and the row says so.

## Import

1. Choose **Import**. The import runs in the background. Each record appears on the trooper's milpac as it is stored.
2. An imported record counts as shared and acknowledged on its date. Its original PDF is kept and is what downloads.
3. Every record is in the audit log as `counseling.imported`, with you as the one who ran it.

> [!good-to-know] Running the same CSV again never adds a record twice. The portal recognizes each document by its Drive file ID, or by the file's contents for an uploaded PDF, and skips it as already imported.

## Fill in a record that needs review

1. Open the record from the trooper's milpac. Its PDF shows beside the fields.
2. Copy the form's fields into the portal and save. The flag clears. The PDF itself never changes.

The issuer, their chain of command and Admins can do this.

## From the server

An Admin with server access can run the same import from the command line:

```
php artisan counseling:import file.csv --files=pdfs/ --dry-run
```

Leave out `--dry-run` to import. The command reads no form fields, so every record it stores needs review, and a Last.F that two troopers share is skipped. It records the earliest Admin as the one who ran it; pass `--as=Last.F` to name another Admin.
