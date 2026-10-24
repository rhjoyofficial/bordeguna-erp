# Customer advances (B11) and small sales gaps (B13)

24 Oct 2026. Base: production `c8b11d43`. Branch `feat/ip-customer-advances`.
Source: Joy's initial plan R90 (B11), R80, R100, R101 (B13); sales guide gaps S4, S5, S6, S21.

## B11. Customer advances (R90, gap S6)

What Joy gets:
- Money from a customer with no invoice and no order is kept as their advance: Dr the money account, Cr 2150 Advances from Customers (party = the customer). Document CADV-yyyy-yy-nnnn.
- Receive payment screen: the rest of what came in, beyond the invoices ticked, can be kept as an advance (a tick, never silent). With no invoice at all, the whole amount is an advance.
- Use an advance on an invoice (invoice page, and the receive payment screen): oldest advance first, the person may change the split. Document CAAP-...: Dr 2150 / Cr 1140 (both the customer), the invoice reduced through a `customer_advance_applied` party adjustment so outstanding, ageing and statements read it unchanged.
- Refund an advance: CARF-...: Dr 2150 / Cr the money account.
- A cancelled order still holding money offers two buttons: Refund (exists) and Move to advance (new): CADV kind `from_order`, Dr 1140 / Cr 2150. The order then holds nothing.
- The customer's on-account 2150 balance from Receive and Payment vouchers is offered as one more row ("On account"), as the supplier side does (SupplierAdvanceBook).
- Party statement and party card count the customer's advance with us (2150) as a fourth part of the net; the customer page shows advance paid, used, refunded and "their advance with us".
- Reverse through the corrections engine for all three documents (sales.receipts.correct: Owner, Finance). Dependency order: an advance with a live use or refund is refused until those are reversed. Correct = reverse and enter again (the receipt screen is the entry point).

Readers that learn the `from_order` move (it debits 1140): SalesOrder::advanceHeld/paidSoFar, CustomerStatement, Ageing, SubledgerReconciliation AR.

## B13. Small sales gaps

- Quotation: Edit while draft or sent (sent goes back to draft); Delete a draft; a Terms box on the form; the internal note never printed on the customer copy.
- Courier payout statement: Reverse a posted statement through the engine (sales.correct: Owner, Manager, Finance): mirror journal on its own date, parcel claims released, status Reversed; the same file and invoice id can then be imported again. Refused while the deposit is matched on the bank statement, while a loss claim it settled is still settled, or when it would leave the courier's payable below zero.
- Sales return: a credit note number (SCN-...) on every new return, shown on the return and its print; Reverse through the engine (sales.correct): stock goes out of the same batches again, the journal mirrored, the invoices it credited owe again. Refused while a refund on it is live or the stock that came back has been used.

Not built here: counter multi-pay (B12), stock reservation (B17, Q4).
