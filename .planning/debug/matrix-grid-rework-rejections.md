# Debug Investigation: Managing Rework and Rejected Entries in Matrix Grid Entry

## Symptoms
1. When using **Matrix Grid Entry (Fast)** on `modules/production/index.php`, the grid only provides a single quantity input per cell (`good`).
2. There is no input field or mechanism to enter **Rejected** pieces or **Rework** pieces for any color/size variant.
3. In the backend submission handler (`modules/production/index.php:91-93`), `rejected_qty` and `rework_qty` are hardcoded to `0`, and no records are inserted into `production_rejections` or `production_rework`.
4. If a factory batch has defective pieces (e.g., 22 good and 2 rejected out of 24 pieces), supervisors have no way to record the defects in the matrix grid. To log them, they must leave the matrix view and manually submit individual entries in "Single Item" mode variant by variant.

## Root Cause
- The fast matrix grid was originally built as a rapid 1-click batch entry tool focused strictly on piece-rate good quantity calculation (`matrix[color_id][size_id][good]`).
- The database schema already provides first-class support for `production_rejections` (with reasons) and `production_rework` (with notes and status), but the matrix UI had no controls to capture quality inspection defects, and the matrix POST handler omitted quality tables.

## Proposed Resolution & Architectural Fix
1. **Quality Mode Toggle in Matrix Toolbar**:
   - Add a clean switch `[ ⚠️ Record Rejections & Rework ]` next to `⚡ 1-Click Fill All Pending`.
   - **Default (Off)**: The matrix grid remains simple and fast with a single input per cell (`Good`).
   - **Quality Mode (On)**: Each active cell dynamically displays three distinct inputs:
     - **Good** (Green border, min: 0): pieces that passed inspection (used for worker labour credit).
     - **Rej** (Red border, min: 0): unrepairable defect pieces (logged to `production_rejections`).
     - **Rew** (Amber border, min: 0): repairable pieces needing correction (logged to `production_rework`).
2. **Quality Inspection Notes Section**:
   - When Quality Mode is active, display a quality notes card below the matrix with:
     - **Rejection Reason**: Preset common causes ("Stitching fault", "Fabric tear / flaw", "Oil / stain mark", "Measurement tolerance issue", "Button / trim defect", "Other") plus optional custom description.
     - **Rework Notes**: Instructions for alteration ("Re-stitching / seam repair needed").
3. **Real-time Live Calculation**:
   - Header & footer summaries calculate:
     - **Good Pieces** &rarr; Worker Labour (`Good × Rate`).
     - **Rejected Pieces** &rarr; Logged as scrap/rejects.
     - **Rework Pieces** &rarr; Logged as pending rework.
     - **Total Inspected / Received**: `Good + Rejected + Rework`.
   - Validation ensures `Good + Rej + Rew` does not exceed the variant's pending balance.
4. **Backend POST Handler Update**:
   - Read `good`, `rejected`, and `rework` for each cell.
   - Insert `production_entries` with accurate `qty_received`, `good_qty`, `rejected_qty`, `rework_qty`.
   - Automatically insert audit rows into `production_rejections` and `production_rework` when `rejected_qty > 0` or `rework_qty > 0`.
   - Ensure worker ledger is credited ONLY for good pieces produced.
