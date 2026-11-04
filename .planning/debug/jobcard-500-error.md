---
status: resolved
trigger: "/gsd-debug jobcard.php?id=56 throws HTTP 500 error"
created: 2026-11-04
updated: 2026-11-04
root_cause: "Unknown column 'pe.total_labour_amount' in production_entries table query in modules/lots/jobcard.php line 134"
fix: "Replaced pe.total_labour_amount with pe.labour_amount and added graceful redirect to latest active lot when an inactive/stale lot ID is requested"
files_changed:
  - modules/lots/jobcard.php
---

# Debug Investigation: jobcard.php HTTP 500 Error

## Symptom
Visiting `http://localhost/Garment/modules/lots/jobcard.php?id=56` produced an immediate `HTTP ERROR 500` ("This page isn't working right now").

## Root Cause Analysis
1. In [`modules/lots/jobcard.php`](file:///c:/xampp/htdocs/Garment/modules/lots/jobcard.php#L131-L144), the query building the Worker Operations Grid aggregated:
   ```sql
   SUM(pe.total_labour_amount) AS labour_rs
   ```
2. In the `production_entries` schema, the column is named `labour_amount`. `total_labour_amount` does not exist.
3. When PDO prepared/executed this query, it triggered `PDOException: Column not found: 1054 Unknown column 'pe.total_labour_amount' in 'field list'`, causing PHP to terminate with an unhandled fatal error and Apache to respond with HTTP 500.
4. Additionally, when requesting a lot ID that no longer exists in the database, the code previously redirected to the generic lots index rather than falling back to the active lot.

## Resolution
1. Corrected `SUM(pe.total_labour_amount) AS labour_rs` to `SUM(pe.labour_amount) AS labour_rs`.
2. Enhanced the lot ID resolver to gracefully load the latest active lot (`redirect('modules/lots/jobcard.php?id=' . $activeLots[0]['id'], ...)`).
3. Verified via CLI test and automated test suite (34 scenarios passed).
