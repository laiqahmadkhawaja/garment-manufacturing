---
status: resolved
trigger: "worker name is missing from the job sheet /gsd-debug"
created: 2026-11-03T10:33:45+05:00
updated: 2026-11-03T10:38:00+05:00
---

## Current Focus
hypothesis: `modules/lots/print_job_sheet.php` hardcoded `<td>____________________</td>` in the Sequential Production Stages table rather than fetching and displaying the artisan/worker name(s) from `production_entries`
test: Updated query to aggregate worker names, rejection quantities, and latest entry date from `production_entries`, and displayed them conditionally in the job sheet table
expecting: Display worker name(s), latest entry date, and rejection quantities from `production_entries` if work has been recorded, falling back to a fillable line `____________________` if pending
next_action: None — bug resolved and verified
bug_class: bohrbug
reasoning_checkpoint: null
tdd_checkpoint: null

## Symptoms
expected: Job sheet displays the artisan / worker name (e.g. "Asad Cutter") who executed the operation, along with entry date and rejected quantity if recorded.
actual: "Assigned Artisan / Worker" column always printed hardcoded blank line `____________________`, even when production entries exist with assigned workers and completed quantities.
errors: None (hardcoded UI string in template)
reproduction: Open http://localhost/Garment/modules/lots/print_job_sheet.php?id=1 for a lot with production entries
started: Present in original job sheet template

## Eliminated

## Evidence
- timestamp: 2026-11-03T10:33:16+05:00
  checked: `modules/lots/print_job_sheet.php` lines 47-55 and 198-213
  found: The query for workflow steps only selected `SUM(good_qty) AS good_done`, and did not select worker names, rejection counts, or entry dates. Line 206 hardcoded `<td>____________________</td>`. Lines 208 and 209 hardcoded empty cells `<td></td>`.
  implication: The traveller sheet never outputted assigned worker names from `production_entries`.
- timestamp: 2026-11-03T10:37:45+05:00
  checked: Rendered job sheet for Lot #1 with production entries (5 pcs cut by Asad Cutter on 03 Nov 2026)
  found: Row 1 displays "Asad Cutter", Qty Done "5", Date "03 Nov 2026". Subsequent pending stages display blank fillable lines `____________________`.
  implication: Stage records accurately show artisan details for completed operations and preserve traveller blanks for pending operations.

## Resolution
root_cause: In `modules/lots/print_job_sheet.php`, the SQL query for lot workflow steps omitted worker joins from `production_entries`, and the table cell for `Assigned Artisan / Worker` had hardcoded placeholder underscores `<td>____________________</td>` on every row regardless of logged work entries.
fix: Updated `$wStmt` in `modules/lots/print_job_sheet.php` to fetch `GROUP_CONCAT(DISTINCT w.name ORDER BY w.name ASC SEPARATOR ', ') AS worker_names`, `MAX(entry_date) AS last_entry_date`, and `SUM(rejected_qty) AS rej_done`. Updated table rows to render `clean($st['worker_names'])` if work exists, otherwise falling back to `____________________`.
verification: Rendered `print_job_sheet.php?id=1` and confirmed "Asad Cutter" renders under "Assigned Artisan / Worker", date renders "03 Nov 2026", and pending rows display clean fillable underlines.
oracle_type: derived
files_changed:
  - modules/lots/print_job_sheet.php
