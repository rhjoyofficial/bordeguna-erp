# ADR-001: The ERP owns website management; HQ stays the intelligence layer

**Status:** accepted (Joy, 6 Sep 2026, decision D1)

## Context

The boss's brief is that every website is managed from the ERP and new ones are
opened from it. At the same time, BordeGuna-HeadQuarter was built on 5 September
for the cross-business role, and its ADR-009 named Econic as the future custom
commerce platform. Two systems were therefore in line to claim the group
commerce role, which is how two repositories come to disagree about who owns
what.

Measured on 6 September: the ERP has no store, website, channel, theme, page or
seller tables. It does have brands. LaLaDia's admin, the reference
implementation, is 48 screens, 236 admin routes, 113 public routes, 120
migrations and 61 models.

## Decision

The ERP owns website and digital commerce management: stores, domains, themes,
pages, catalogue publishing, prices, orders, customers, content.

HQ remains the intelligence and orchestration layer. It reaches commerce through
its existing `CommercePlatform` interface, and the ERP website module becomes
the implementation behind that interface as each store moves onto it.

There is **no second admin panel**. A screen that exists in the ERP is not
rebuilt for websites.

## Alternatives

- **HQ owns commerce.** Rejected: it puts the order and the money one system
  away from the ledger that has to account for them, and the boss's brief is
  explicit that management happens in the ERP.
- **A separate commerce application beside both.** Rejected: it is a third place
  for catalogue and customer truth to diverge, and D1 exists precisely to stop
  that.

## Consequences

- HQ ADR-009 must be amended, not left to contradict this. That amendment is HQ
  ADR-015.
- The parity inventory (`WEBSITE-PARITY-INVENTORY-2026-09-07.md`) is binding:
  ten of LaLaDia's 48 admin screens are marked ERP-HAS and must not be rebuilt.
- Websites become a consumer of the ERP's existing spine: RBAC, audit log,
  notifications, documents, accounting.
