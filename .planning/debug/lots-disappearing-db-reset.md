---
status: resolved
trigger: "User noticed lots disappearing after creation when navigating away; suspected a piece of code resetting database to the same state."
created: 2026-11-08T23:35:00+05:00
updated: 2026-11-08T23:42:00+05:00
---

## Symptoms
- **Expected:** When a user creates a new production lot in `modules/lots/create.php`, it should persist in the database, appear in `modules/lots/index.php`, and remain available across navigation.
- **Actual:** User created lots approximately 4 times (Lot #100, Lot #137, Lot #147). Right after creation, the lot was visible on `view.php`. But upon navigating away to another step (e.g. production/materials) or list, the lot vanished, and the database appeared reset to the same state.
- **Error:** None directly shown in UI initially; subsequent requests returned 404 (e.g. `get_lot_stage_matrix&lot_id=147` returned 404).

## Evidence & Investigation
1. **Access Logs (`C:\wamp64\logs\access.log`):**
   - 23:15:16: `POST /garment/modules/lots/create.php` -> 302 -> `GET view.php?id=137` (200 OK).
   - 23:18:23: `POST /garment/modules/lots/create.php` -> 302 -> `GET view.php?id=147` (200 OK).
   - 23:19:19: Material purchases recorded for Lot #147.
   - 23:19:31: Production view opened for Lot #147.
   - 23:22:13: `ajax.php?action=get_lot_stage_matrix&lot_id=147` -> HTTP 404 (Lot vanished).

2. **Database Audit Logs (`audit_logs`):**
   - Audit entry #105 confirmed Lot #147 was successfully created and committed.
   - However, querying `lots` table showed only 9 lots with IDs 158 to 166 (all prefixed `LOT-TEST-...` and `LOT-101`), all created at `2026-11-08 23:22:48`.
   - Lots #100, #137, #147 were completely missing.

3. **Codebase Grep for blanket deletes:**
   - Found in `test_suite.php` (lines 28–67):
     ```php
     $db->exec("SET FOREIGN_KEY_CHECKS=0");
     ...
     $db->exec("DELETE FROM lots");
     $db->exec("DELETE FROM lot_variants");
     $db->exec("DELETE FROM lot_workflow_steps");
     $db->exec("DELETE FROM production_entries");
     ...
     ```
   - `test_suite.php` connects to the live development database (`garment_db`).
   - Every time automated tests or verification commands (`php test_suite.php`) were executed (e.g. before git commits at 22:59 and 23:23), the entire database of lots, variants, entries, and dispatches was wiped and re-seeded with the exact same 9 fixture lots (`LOT-101` and `LOT-TEST-*`).

## Root Cause
`test_suite.php` contains destructive blanket cleanup queries (`DELETE FROM lots`, `DELETE FROM production_entries`, etc.) directly on the shared/live database without scoping to test lots (`WHERE lot_number LIKE 'LOT-TEST-%'`). Whenever tests were executed during development/verification, any user-created lot was deleted, and the database was reset to the exact same fixture state.

## Resolution
1. Scope cleanup queries in `test_suite.php` to only delete test lots and test records (`WHERE lot_number LIKE 'LOT-TEST-%' OR lot_number = 'LOT-101'`).
2. Do not execute blanket `DELETE FROM lots;`.
