---
paths:
  - resources/views/pdf/ledgers/form48.blade.php
---

# Ledgers

## Form 48 uses calendar boundary context rows
Render two context rows before every calendar month and retain two empty reserve rows after any populated trailing context. Empty boundary rows and nonexistent dates are uninterrupted excluded bands with no placeholder; only duties crossing the month activate boundary content. Punches are placed on their actual calendar date, except an OUT intended exactly at 00:00 stays on the preceding duty row; prior-ledger context is subdued and a trailing timeout for the ledger month remains full emphasis. Boundary context never contributes metrics or totals.
