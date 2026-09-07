# Architecture Decision Records

Decisions that would otherwise be re-litigated silently, one per file, newest
number last. A decision recorded here is settled until a later ADR supersedes it
by name.

Format follows the one already in use in the BordeGuna-HeadQuarter repository:
Status, Context, Decision, Alternatives, Consequences.

| # | Decision | Status |
|---|---|---|
| [001](001-erp-owns-website-management.md) | The ERP owns website management; HQ stays the intelligence layer | accepted 6 Sep 2026 |
| [002](002-theme-based-rendering-over-a-section-contract.md) | Theme-based rendering over an ERP-owned typed section contract | accepted 6 Sep 2026 |
| [003](003-one-multi-tenant-storefront-codebase.md) | One multi-tenant storefront codebase, resolved by domain | accepted 6 Sep 2026 |
| [004](004-availability-policy-per-channel.md) | One stock number, availability policy per channel; a made-to-order web sale records a commitment | accepted 6 Sep 2026 |
| [005](005-websites-are-b2c-and-each-website-is-a-price-group.md) | Websites are B2C only, and each website is a price group | accepted 6 Sep 2026 |
| [006](006-econic-is-a-multi-brand-store-not-a-marketplace.md) | Econic is a multi-brand store, not a marketplace; no intercompany machinery | accepted 6 Sep 2026 |

## Related documents

These live in `D:\WORK\BorDeGuna\` because they are working documents rather
than repository contracts:

- `WEBSITE-MANAGEMENT-ARCHITECTURE-2026-09-06.md`, the programme architecture
  and the full decision table D1 to D22
- `WEBSITE-PARITY-INVENTORY-2026-09-07.md`, the definition of done, screen by
  screen and URL by URL
- `WEBSITE-SECTION-CONTRACT-2026-09-07.md`, the specification ADR-002 refers to
- `WEBSITE-CUTOVER-RUNBOOK-2026-09-07.md`, the cutover and rollback procedure

## Cross-repository

BordeGuna-HeadQuarter ADR-015 records the other side of ADR-001, and amends that
repository's ADR-009. If these two ever disagree, they are both wrong until one
supersedes the other explicitly.
