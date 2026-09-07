# ADR-006: Econic is a multi-brand store, not a marketplace; one legal company means no intercompany machinery

**Status:** accepted (Joy, 6 Sep 2026, interview decisions)

## Context

Earlier design work, including HQ ADR-009 and ADR-010, allowed for Econic being
a multi-seller platform with sellers, onboarding, commission and payouts. That
reading survived into the 6 September architecture draft.

Joy corrected it directly: **only they publish. Nobody else logs in.** Econic
sells LaLaDia products, Bionic products, Bôr dé Güna-only products, and
bought-in grocery.

Separately: **there is one legal company.** The brands are brands, not entities.

## Decision

⛔ **Econic is a multi-brand store.** There is **no seller portal, no seller
onboarding, no commission calculation and no payout run.** None of that is
built, and none of it is designed for "later" either, because designing for a
seller that will never exist distorts the order and the ledger.

⛔ **No intercompany machinery at all.** No intercompany invoices, no elimination
entries, no transfer pricing. Brand and store are dimensions on the order and
journal lines, and that is the whole mechanism for seeing per-brand profit.

Econic's bought-in grocery is ordinary purchasing for resale: supplier, purchase
order, stock, which the ERP already has. ⚠ Whether Bôr dé Güna already purchases
for resale commercially is open question Q10; measured on 7 September the ERP
has 6 suppliers and 0 purchase orders, so the flow exists and has never run.

Azmion is a software-company corporate site in the shape of Brain Station 23:
services, portfolio, team, careers, blog, with lead forms feeding the ERP's CRM.
No commerce. It comes last.

Selling the platform to other companies is a later phase. Group first.

## Alternatives

- **Build the seller model now because it is cheaper than retrofitting.**
  Rejected: it is only cheaper if it is ever needed, and it costs clarity on
  every order line and every journal line in the meantime.
- **Treat brands as separate entities with intercompany postings.** Rejected:
  they are not separate entities, and inventing the machinery would produce
  reconciliations nobody owes.

## Consequences

- The domain model carries store and brand, and carries no seller.
- If a genuine third-party seller ever appears, that is a new ADR and a real
  design, not a flag switched on.
- Econic is Phase 5. Nothing about it blocks Bionic or LaLaDia.
