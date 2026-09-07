# ADR-004: One stock number, an availability policy per channel; a made-to-order web sale records a commitment, never a movement

**Status:** accepted (Joy, 6 Sep 2026, interview decisions)

## Context

The goods are **made to order**. A web shop that refuses to sell at zero stock
would refuse most of what these businesses do, and the current shops already
behave this way: availability is driven by product status, not by a stock count.

The point of sale is the opposite case. A till cannot sell what is not on the
shelf in front of the customer.

Measured on 6 September, the ERP has taken no transactions at all: 0 sales
orders, 0 purchase orders, 0 stock movements, and opening stock applied to no
warehouse.

## Decision

There is **one ERP stock number**. Channels differ only in the **availability
policy** applied to it:

| Channel | Policy | Behaviour at zero |
|---|---|---|
| POS | `strict` | Cannot sell |
| Web | `status_based` | Keeps selling, because goods are made to order |

Backorder and preorder are first-class, not workarounds. A per-product override
exists for the cases the channel default gets wrong.

Three consequences are designed in, not bolted on later:

1. ⛔ **A web sale at zero records a commitment or backorder, never a stock
   movement.** Writing a movement for goods that do not exist is a lie in the
   ledger, and `inventory:verify-ledger` would eventually catch it. Availability
   is a promise; a movement is a fact.
2. **COGS attaches to dispatch, not to the order.** There is no cost until there
   are goods. ⚠ This needs the accountant's confirmation before Phase 2 posts
   anything (open question Q6).
3. **Make-to-order needs a promised lead time per product**, because the
   customer is being told when, not whether. Who maintains it is open question
   Q7.

⭐ Free consequence, worth keeping: seasonal products currently 404 when switched
off, throwing away the URL's accumulated authority every year. Under this policy
they keep the URL and show "out of season, notify me".

## Alternatives

- **Reserve stock at order time.** Rejected: it implies stock exists, which for
  made-to-order goods it does not, and it would make the warehouse look emptier
  than it is.
- **Sell only what is in stock on the web too.** Rejected: it contradicts how
  the business actually operates and would suppress most sales.
- **A separate web stock number.** Rejected: two numbers disagree, and the
  disagreement surfaces as a customer complaint rather than as an error.

## Consequences

- ⛔ **Phase 2 cannot run against an empty warehouse.** Sprint 0, the opening
  trial balance and opening stock, is a blocker and not background work.
- The first real order posted through this path is checked by hand, journal read
  by eye, before a second one is allowed near it.
- Checkout copy must state the promised lead time, or a made-to-order sale reads
  to the customer as an in-stock sale that is simply late.
