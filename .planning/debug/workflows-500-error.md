---
status: resolved
trigger: "/gsd-debug"
created: 2026-11-03T10:17:00+05:00
updated: 2026-11-03T10:20:45+05:00
---

## Current Focus
hypothesis: Fixed non-existent `lot_workflows` table reference in modules/settings/workflows.php
test: Tested query execution and full HTTP rendering of workflows.php
expecting: HTTP 200 / valid page render with 0 errors
next_action: None — bug resolved and verified
bug_class: bohrbug
reasoning_checkpoint: null
tdd_checkpoint: null

## Symptoms
expected: Loading http://localhost/Garment/modules/settings/workflows.php displays the Production Workflow Templates page
actual: HTTP 500 Internal Server Error ("This page isn't working right now - localhost can't currently handle this request")
errors: "PHP Fatal error: Uncaught PDOException: SQLSTATE[42S02]: Base table or view not found: 1146 Table 'garment_erp.lot_workflows' doesn't exist in C:\\xampp\\htdocs\\Garment\\modules\\settings\\workflows.php:48"
reproduction: Navigate to http://localhost/Garment/modules/settings/workflows.php in the browser
started: Immediately upon opening Workflow Templates

## Eliminated
- hypothesis: Syntax error in workflows.php
  evidence: php -l reported "No syntax errors detected"
  timestamp: 2026-11-03T10:14:01+05:00
- hypothesis: MySQL service or database connection offline
  evidence: Database connection successful and queries to operations table returned valid records
  timestamp: 2026-11-03T10:15:21+05:00

## Evidence
- timestamp: 2026-11-03T10:15:27+05:00
  checked: garment_erp database tables via SHOW TABLES and error.log
  found: error.log records "Uncaught PDOException: SQLSTATE[42S02]: Base table or view not found: 1146 Table 'garment_erp.lot_workflows' doesn't exist in C:\xampp\htdocs\Garment\modules\settings\workflows.php:48". SHOW TABLES in garment_erp shows lot_workflow_steps, workflow_templates, and workflow_template_steps, but no lot_workflows table.
  implication: modules/settings/workflows.php line 50 executes a subquery against a non-existent table `lot_workflows`.
- timestamp: 2026-11-03T10:18:25+05:00
  checked: Query replacement using schema relation lots -> designs/products -> workflow_templates
  found: Query executed successfully and returned 3 workflow templates with correct step counts.
  implication: Replacing the query resolves the PDOException fatal error.

## Resolution
root_cause: In `modules/settings/workflows.php` line 50, the query attempted to calculate `lots_count` using `FROM lots WHERE id IN (SELECT lot_id FROM lot_workflows WHERE template_id = wt.id)`. The table `lot_workflows` does not exist in the database schema (lots connect to templates via designs.workflow_template_id and products.default_workflow_template_id, and store snapshot steps in lot_workflow_steps). This resulted in an unhandled PDOException 1146 causing Apache/PHP to return HTTP 500.
fix: Corrected the query in `modules/settings/workflows.php` to calculate `lots_count` via `lots l JOIN products p ON l.product_id = p.id LEFT JOIN designs d ON l.design_id = d.id WHERE COALESCE(d.workflow_template_id, p.default_workflow_template_id) = wt.id`. Also guarded `$_SERVER['SERVER_PORT']` in `config/config.php` against undefined index warnings.
verification: Verified full page rendering output (37KB HTML rendered without errors, all 3 workflow templates loaded, operations displayed). Ran test suite and reseeded database to pristine state.
oracle_type: derived
files_changed:
  - modules/settings/workflows.php
  - config/config.php
