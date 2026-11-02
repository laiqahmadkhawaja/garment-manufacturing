# Garment Manufacturing & Production Management ERP System

A complete, production-grade Garment Factory Production, Worker/Labour Management, Inventory & Dispatch ERP Suite built with Core PHP (non-MVC), MySQL, Bootstrap 5 (locally hosted), and jQuery/AJAX.

---

## 🚀 Key Modules & Capabilities

1. **Dashboard**
   - Active lots, newly created lots, completed lots, and ready stock metrics.
   - Raw material stock valuation, finished goods inventory, and dispatched pieces.
   - Financial KPIs: Real-time worker payables vs. advances, customer receivables, and today's labour cost.
   - Interactive Chart.js department production progress.

2. **Lot Management & Planning**
   - Unique Lot Numbers with Color $\times$ Size variant matrix.
   - Optional Design Assignment (assign or update design numbers anytime later without losing existing records).
   - Workflow snapshot per lot (changes to master templates never alter running batches).
   - Printable **Lot Assignment Sheet / Floor Traveller** with stage tracking and supervisor signatures.

3. **Dynamic Production Operations & Work Entry**
   - Dynamic departments and operations (Cutting, Stitching, Safety, Neck Finishing, Buttons, Kaj, Press, Packing).
   - Fast production entry: Auto-calculates piece labour, credits worker ledger automatically.
   - Multiple artisans per operation (e.g. Latif: 60 pcs, Amir: 48 pcs, Asad: 36 pcs).
   - Instant shortage and bottleneck alerts (e.g. 143/144 cutting immediately flags 1 piece pending).
   - Rejections and rework tracking without inflating good output.

4. **Workers, Double-Entry Ledgers & Payments**
   - Clear accounting convention: Credit = Labour Earned, Debit = Payments / Advances Made.
   - Clear labels: "Payable to Worker" vs. "Worker Advance / Receivable".
   - Disburse payments and advances via Cash, Bank Transfer, JazzCash, or Easypaisa.
   - Atomic payment reversals with automatic ledger correction.
   - Printable **Worker Labour Statements** and **Payment Receipts**.

5. **Raw Material Inventory**
   - Yarn, fabric, thread, buttons, zips, polybags, labels.
   - Inward purchases, issue to production lots, returns of unused material.
   - Bill of Materials (BOM) specifications.
   - Printable **Material Issue Requisition Slips**.

6. **Packing & Finished Goods Warehouse**
   - Partial packing support with stage output verification (cannot pack more than good output).
   - Packing deposits stock into Finished Goods Inventory by product, design, color, size, lot, and warehouse.
   - Distinction between "Ready Stock" (packed in factory) and "Dispatched" (shipped to parties).

7. **Customer Dispatches & Returns**
   - Outward shipments reduce finished goods and debit customer accounts.
   - Printable **Delivery Challans (Bilty / Gate Pass)**.
   - Customer return processing (saleable goods returned to warehouse; damaged goods isolated).

8. **Costing & Profitability**
   - Actual lot costing: Net Material Cost + Operations Labour + Packing Labour = Total Batch Cost.
   - Automatic unit cost calculation ($\text{Cost Per Piece} = \frac{\text{Total Cost}}{\text{Accepted Pieces}}$).

9. **Reports & Exports**
   - Filterable reports for Lots, Cutting, Worker Performance, Stock, Dispatches, and Costing.
   - Print-friendly layouts and one-click **CSV / Excel export**.

---

## 🛠️ Technology Stack

- **Backend:** Core PHP (PHP 8.2+) with strict PDO prepared statements & transactions.
- **Database:** MySQL 8.0+ / MariaDB.
- **Frontend:** HTML5, CSS3, JavaScript, jQuery 3.7.1, Bootstrap 5.3.3 (locally hosted), Bootstrap Icons 1.11.3 (locally hosted), Chart.js (locally hosted).
- **Deployment:** 100% offline localhost compatible; works on WampServer, XAMPP, or Linux Apache/Nginx.

---

## 💻 Installation & Setup

1. **Place Code in Web Directory:**
   ```bash
   # WampServer:
   c:\wamp64\www\Garment
   ```

2. **Initialize Database:**
   Run the automated database setup script via CLI or browser:
   ```bash
   php database/setup.php
   ```
   Or import `database/schema.sql` into MySQL (`garment_erp`).

3. **Access ERP in Browser:**
   ```text
   http://localhost/Garment/
   ```

4. **Default Administrator Credentials:**
   - **Username:** `admin`
   - **Password:** `admin123`

---

## 🧪 Automated Test Suite

Run the full end-to-end verification suite:
```bash
php test_suite.php
```
Verified against all 21 mandatory manufacturing and accounting scenarios.
