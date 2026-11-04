# Debug Investigation: Close Button Not Working on Print Slips & Receipts

## Symptoms
- When viewing payment receipts (`modules/payments/receipt.php`), material issue slips (`modules/raw_materials/print_issue.php`), delivery challans, or job sheets, clicking the "Close" button did nothing.
- The user remained trapped on the print/receipt voucher view.

## Root Cause
1. In modern web browsers (Chrome, Edge, Firefox, Safari), W3C security specifications stipulate that `window.close()` may only close windows/tabs that were explicitly opened by a script using `window.open()`.
2. When creating a worker payment (`modules/payments/create.php:108`), issuing material (`modules/raw_materials/issues.php:76`), or generating a dispatch (`modules/dispatch/create.php:166`), the server uses standard HTTP redirect (`redirect(...)`) to take the user directly to the printable slip within the SAME window/tab.
3. Because the page was not spawned by `window.open()`, the browser rejected `window.close()` with the console message `Scripts may close only the windows that were opened by it`, rendering the button non-functional.

## Fix Applied
Replaced naked `onclick="window.close()"` across all 5 printable voucher and slip pages with an intelligent fallback handler `closePrintView(fallbackUrl)`:
1. It attempts `window.close()` first (which closes child popups or script-opened tabs cleanly).
2. If the browser blocks `window.close()` (after 150ms and window is still open), it gracefully redirects the user back to the module's parent listing or view page:
   - `modules/payments/receipt.php` &rarr; `modules/payments/index.php`
   - `modules/raw_materials/print_issue.php` &rarr; `modules/raw_materials/issues.php`
   - `modules/dispatch/challan.php` &rarr; `modules/dispatch/index.php`
   - `modules/lots/print_job_sheet.php` &rarr; `modules/lots/view.php?id=<lot_id>`
   - `modules/workers/statement.php` &rarr; `modules/workers/view.php?id=<worker_id>`

## Verification
- All 5 printable files linted cleanly with zero syntax errors.
- Test suite (`test_suite.php`) run: 21 PASSED, 0 FAILED.
