---
status: resolved
trigger: "/gsd-debug modules/raw_materials/stock_in.php returns 404 Not Found"
created: 2026-11-04
updated: 2026-11-04
root_cause: "Missing modules/raw_materials/stock_in.php file referenced by lot shortage alert banner in modules/lots/view.php"
fix: "Created modules/raw_materials/stock_in.php as alias to purchases.php with pre-selection and lot return redirect support; added navigation link to sidebar"
files_changed:
  - modules/raw_materials/stock_in.php
  - modules/raw_materials/purchases.php
  - modules/lots/view.php
  - includes/sidebar.php
---

# Debug Investigation: stock_in.php 404 Not Found

## Symptom
Clicking "اسٹاک شامل کریں (Stock In)" from the raw material shortage banner on a lot directed the browser to `http://localhost/Garment/modules/raw_materials/stock_in.php` and resulted in:
`404 Not Found - The requested URL was not found on this server. Apache/2.4.58 (Win64) ... Port 80`.

## Root Cause Analysis
1. In [`modules/lots/view.php`](file:///c:/xampp/htdocs/Garment/modules/lots/view.php#L263), the button links to `modules/raw_materials/stock_in.php`.
2. In the `modules/raw_materials/` directory, supplier purchases and inward stock-in entries were handled by `purchases.php`.
3. `stock_in.php` did not exist as a physical file, causing Apache to return an HTTP 404 error.

## Resolution
1. Created [`modules/raw_materials/stock_in.php`](file:///c:/xampp/htdocs/Garment/modules/raw_materials/stock_in.php) providing direct access to Material Purchases & Inward Goods.
2. Updated [`modules/raw_materials/purchases.php`](file:///c:/xampp/htdocs/Garment/modules/raw_materials/purchases.php) with:
   - Query string pre-selection for `material_id` (`$_GET['material_id']`).
   - Seamless return redirection back to the originating Lot (`$_GET['lot_id']` / `$_POST['lot_id']`).
   - Fixed undefined `$costPrice` variable by reading current material cost price from database when unit price is 0.
3. Updated [`modules/lots/view.php`](file:///c:/xampp/htdocs/Garment/modules/lots/view.php) to pass `?lot_id=...` to stock in buttons.
4. Added "Stock In / Purchases" link to [`includes/sidebar.php`](file:///c:/xampp/htdocs/Garment/includes/sidebar.php).
5. Verified with test suite (`test_suite.php` - 34 scenarios passed).
