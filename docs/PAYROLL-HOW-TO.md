# Payroll: how to run the month

A plain guide to paying salaries in the ERP, in the order the steps happen. Screens are under **HRM > Payroll**.

## The monthly steps

1. **Prepare the inputs.** Before calculating, finish everything that changes pay: attendance corrections and forgiveness, overtime, bonuses, adjustments, loans and salary advances (**HRM > Payroll inputs**, **HRM > Loans**). An unapproved input is not included.
2. **Calculate.** On **HRM > Payroll**, pick the year and month and press **Calculate**. It runs in the background. If anything blocks the run, every problem is listed together; fix them and press **Recalculate**. You can recalculate freely while the run is Draft or Calculated.
3. **Review.** Open the payslips, check the totals, then press **Review**. This sends the run for approval.
4. **Approve.** The approver(s) approve it in the approval inbox. **What it posts:** one accounting entry (the "accrual"), dated the **last day of the payroll period**: Dr Salaries and Wages (the cost), Cr Salaries and Wages Payable (what is owed to each person), plus the deduction and loan-recovery lines. **Why:** the cost belongs to the month the work was done, not the month the bank paid. If accounting is not switched on yet, approval posts nothing (see mistake 3 below).
5. **Mark as disbursed.** This releases the run for payment and applies the loan installments and carry-forward recoveries. Nothing can be recorded as paid before this.
6. **Create disbursement batches.** On the run's **Disbursement** page press **Create batches**. You get one batch per payment method (bank transfer, cash, mobile). Download each batch's register for the bank or the cash desk.
7. **Pay.** The bank (or cash desk) actually pays the staff. Upload the bank advice against the batch if you have it.
8. **Confirm each batch.** On the batch, choose **Paid from** (the bank, cash or mobile account), and type **Paid on**: the date on the bank statement, not today. Then press **Confirm batch paid**. **What it posts:** one entry per person, dated the Paid on date: Dr Salaries and Wages Payable, Cr the bank or cash account. After this, the payable for the month is back to zero. For one person paid on their own, use **Record payment** on their row; it asks the same questions and posts the same entry.
9. **Lock.** When everything is paid and checked, press **Lock**. A locked payroll can only be reopened through the unlock request (HR Head, Finance, Management).

## What each status means

| Status | Meaning |
| --- | --- |
| Draft | Created, not calculated yet. |
| Calculated | Payslips exist. Can be recalculated freely. |
| Reviewed | Sent for approval. Waiting for the approver. |
| Approved | Final figures. The salary cost is posted to the books (if accounting is on). Can still be reopened as a new version before disbursement. |
| Disbursed | Released for payment. Loan and carry-forward recoveries applied. Batches and payments can be recorded. |
| Locked | Closed. Payments can still be recorded or corrected, but the figures cannot change without an unlock request. |
| Rejected | The approver turned it down, with a reason. Recalculate and review again. |
| Cancelled | Withdrawn. Kept on record, never paid. |

## Common mistakes and how to fix them

**1. Wrong paid date (confirmed on the wrong day).**
Example: the bank paid on 30 Sep, the batch was confirmed on 12 Oct with 12 Oct as the date. The bank book then disagrees with the statement.
Fix: the **Owner** opens the Disbursement page, and on the confirmed batch (next to "Paid on ...") presses **Correct paid date**, enters the bank date and a reason. Each wrongly dated entry is reversed on its own date (so that day nets to zero) and posted again on the right date with the same amounts. Nothing is deleted; the old entry, its reversal and the new entry all stay in the books, and the change is in the activity log. A single person's payment can be corrected the same way from their row (open the row to see its payments). The new date must be in an open accounting period and not in the future.

**2. Paying salaries outside payroll with a payment voucher.**
If a salary is paid with a general payment voucher and then also confirmed in payroll, the money leaves the bank twice in the books: a double payment. Salaries are paid **only** through the Disbursement page. If it already happened, reverse the voucher (the payroll confirmation is the correct record), or reverse the payroll payment from the person's row if the voucher is the one to keep. Never keep both.

**3. Approving before accounting was set up.**
If a run was approved while accounting was not switched on, its salary cost never reached the books. Paying it later makes Salaries and Wages Payable go negative (a debit) and the month's salary expense show zero.
Fix: open **HRM > Payroll** on that month. A red box says "The salary cost of this payroll is not in the books". Press **Post the missing accounting entry** (Owner or HR manager). It posts the same entry approval would have posted, dated the last day of the payroll period. It posts only once. From now on, recording a payment for such a run also posts the missing entry first, automatically. If that month is already closed in accounting, it refuses and says so: ask the Founder to unlock the period first.

**4. Confirming a batch before the bank has paid.**
Confirming means the money has gone, so wait for the bank. If a transfer fails before you confirm, use **Record batch failure** (or **Record failure** on one person). If it comes back after you confirmed, open the person's row and press **Reverse** on that payment: its entry is reversed and the person shows as unpaid again, ready to be paid and recorded once more.
