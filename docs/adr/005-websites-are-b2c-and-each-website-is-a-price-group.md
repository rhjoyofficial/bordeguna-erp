# ADR-005: Websites are B2C only, and each website is a price group

**Status:** accepted (Joy, 6 Sep 2026, interview decisions)

## Context

The ERP already carries tiered pricing: retail, dealer and distributor, at
10, 15 and 20 percent off the selling price. Wholesale and dealer trade is done
by people who know each other, over phone and paperwork, not through a shopping
cart.

There is one customer record in the ERP, but a customer signing in on
laladia.com should not thereby have an account on bionic.garden.

## Decision

- A website sells **B2C only**. All wholesale, dealer and distributor trade
  stays manual in the ERP. No website exposes a tier price or a trade login.
- **Each website is a price group.** The store's price group decides what a
  visitor is charged, and there is no per-customer price on a storefront.
- **One ERP customer record, separate logins per site.** The person is one
  record; the credential is per store.
- **Brand and store are dimensions on the order and on the journal lines**, so
  per-brand profit survives one set of books.

Booking of revenue follows the site: laladia.com books to LaLaDia,
bionic.garden to Bionic, Econic to Bôr dé Güna.

## Alternatives

- **Let dealers log in and see their tier.** Rejected: it puts trade pricing on
  a public system, and the trade does not want a cart.
- **Per-customer pricing on the storefront.** Rejected: it makes every cached
  page and every advertised price conditional, for a case that does not exist.
- **A separate customer record per site.** Rejected: it destroys the single view
  of a customer, which is a large part of why the ERP owns this at all.

## Consequences

- Price changes for a website are a price-group operation, not a per-product
  edit, and one place to look when a price on the site disagrees with the ERP.
- Customer migration at cutover carries the person and their addresses; the
  login is created per store. Customer-facing order history starts fresh, by
  Joy's decision.
- Reporting must always group by the brand and store dimensions, or one set of
  books reads as one business.
