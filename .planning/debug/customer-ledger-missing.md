# Debug Investigation: Customer Ledger Missing

## Symptoms
1. In the sidebar under `Dispatch & Customers`, the link `Customer Ledgers` navigates to `modules/customers/index.php`.
2. On `modules/customers/index.php`, users only see a customer registration form and a table with aggregate balances (`Total Billed`, `Payments Received`, `Outstanding Balance`).
3. There is no Customer Ledger statement page (`modules/customers/view.php` or `ledger.php` does not exist).
4. The customer directory has no links or buttons to view the detailed running ledger or account statement of any customer party. The only button in the action column is `[ 📦 Dispatch ]`.
5. There is no mechanism to record payments received from customers (`transaction_type = 'Payment'` in `customer_ledger`), and no printable customer account statement.

## Root Cause
- The `customer_ledger` database table and schema were created, and dispatches (`modules/dispatch/create.php`) and returns (`modules/dispatch/returns.php`) properly post debit/credit entries to `customer_ledger`.
- However, the dedicated customer view/ledger module (`modules/customers/view.php`) and printable statement (`modules/customers/statement.php`) were never implemented.
- `modules/customers/index.php` lacked links to open the customer's ledger, and lacked customer payment collection functionality.

## Resolution Plan
1. **Build `modules/customers/view.php`**:
   - Customer profile details card (Code, Contact, Phone, Address, City).
   - Summary statistics: Total Invoiced (Debit), Total Payments Received (Credit), Net Receivable Balance.
   - Comprehensive running ledger statement table: Date, Type (Opening, Dispatch, Payment, Return), Reference Doc #, Description, Debit, Credit, Running Balance.
   - Transaction tabs: Detailed Ledger, Dispatch History, Payment History.
   - Integrated "Receive Payment" modal & POST handler: records customer recovery, credits `customer_ledger`, updates running balance, and deposits funds into `cash_bank_accounts`.
   - Print statement button linking to `statement.php`.
2. **Build `modules/customers/statement.php`**:
   - Clean, professional printable customer ledger statement with company header, party details, dated transactions, and signature blocks.
   - Implements graceful `closePrintView('view.php?id=' . $customerId)`.
3. **Upgrade `modules/customers/index.php`**:
   - Turn Party Name into a clickable link to `view.php?id=...`.
   - Add `[ 📑 Ledger ]` and `[ 💵 Receive ]` buttons in the table Actions column.
   - Add top KPI summary cards for Total Outstanding Receivables, Total Dispatched/Billed, and Total Payments Collected.
