---
status: resolved
trigger: "/gsd-debug Duplicate entry '' for key 'entry_number' on operator_stage.php"
created: 2026-11-04
updated: 2026-11-04
root_cause: "Omitting entry_number from INSERT statement in modules/production/operator_stage.php resulted in MySQL defaulting to '' which violated unique key constraint on subsequent operator entries"
fix: "Generated distinct sequential document numbers via generate_doc_number('PE-', ...) for Op1 and Op2, populated rate & labour_amount, and added ON DELETE CASCADE to foreign key"
files_changed:
  - modules/production/operator_stage.php
---

# Debug Investigation: Duplicate entry '' for key 'entry_number'

## Symptom
Submitting operator entries in [`modules/production/operator_stage.php`](file:///c:/xampp/htdocs/Garment/modules/production/operator_stage.php) resulted in a database toast error:
`SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '' for key 'entry_number'`

## Root Cause Analysis
1. In `production_entries`, `entry_number` is a mandatory, unique column (`VARCHAR(50) NOT NULL UNIQUE`).
2. When [`modules/production/operator_stage.php`](file:///c:/xampp/htdocs/Garment/modules/production/operator_stage.php) automatically synchronized operator piece counts to Stage 1 in `production_entries`, the SQL `INSERT` statement omitted `entry_number`.
3. In non-strict SQL mode, MySQL defaulted the missing column value to an empty string `''`.
4. When Operator 1 and Operator 2 both reported pieces, or when a second lot was recorded, the subsequent attempt to insert `''` into `entry_number` failed with duplicate key error `1062`.

## Resolution
1. Generated distinct document sequence numbers (`PE-...`) for both Operator 1 and Operator 2 before inserting into `production_entries`.
2. Populated all mandatory audit fields including `qty_received`, `rate`, and calculated `labour_amount`.
3. Updated the foreign key constraint `lot_operator_element_entries_ibfk_2` on `product_elements(id)` with `ON DELETE CASCADE`.
4. Verified end-to-end with live transaction simulation and full 34-scenario regression test suite.
