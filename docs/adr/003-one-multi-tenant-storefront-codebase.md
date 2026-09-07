# ADR-003: One multi-tenant storefront codebase, resolved by domain

**Status:** accepted (Joy, 6 Sep 2026; recommendation put to him and not contested)

## Context

The group runs, or will run, laladia.com, bionic.garden, Econic and Azmion. The
existing approach is a fork: Bionic is a git fork of LaLaDia that shares history
and receives fixes by merge.

That fork has already cost real money in ways that are recorded rather than
theoretical. A per-account identifier written as a code literal was inherited
silently by the fork: Bionic's `robots.txt` told crawlers its sitemap lived on
laladia.com, and Search Console later confirmed Bionic had never had a sitemap
submitted at all. The same shape appeared with the Meta domain-verification
token, the referral cookie, the webhook signature header and the coupon storage
key.

## Decision

One storefront application serves every store. The incoming host resolves to a
`Store`, and everything after that is store-scoped: theme, pages, catalogue
publishing, price group, settings, credentials, SEO.

⛔ **No per-account identifier may be a code literal.** Order prefix, pixel id,
CAPI token, GTM container, domain verification token, referral cookie name,
webhook signature header, coupon storage key: every one is a store field or a
store settings row, and blank means the feature renders nothing rather than
inheriting another store's value.

Per-store feature flags (wishlist on or off, which payment methods) are store
settings, not deploy-time environment values.

All sites run on the existing VPS. That was Joy's own plan and it is recorded
here so capacity is reviewed deliberately rather than discovered.

## Alternatives

- **Keep forking per brand.** Rejected: measured, the fork drifts. The previous
  Bionic fork reached 47 migrations against LaLaDia's 97 in one month, on a
  superseded admin, missing every recent fix.
- **A separate deployment per store from one codebase.** Rejected for now: it
  multiplies deploys and still needs the same store scoping. Revisit only if one
  store's traffic genuinely warrants isolation.

## Consequences

- Creating a store is a data operation, not a deploy.
- Every query in the storefront is store-scoped, and a missing scope is a
  cross-store data leak. Cross-store tests guard it, as they already do for the
  ERP's business scoping.
- Shared SSLCommerz and courier accounts are used today, so each store carries
  its own credential fields from the beginning even while one account fills them.
