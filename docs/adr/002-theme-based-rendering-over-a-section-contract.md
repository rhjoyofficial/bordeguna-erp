# ADR-002: Theme-based rendering over an ERP-owned typed section contract

**Status:** accepted (Joy, 6 Sep 2026, decision D3, plus the contract rule)

## Context

D3: pick a theme, it goes live on that domain, data comes from the ERP, and new
designs arrive as new themes.

That promise only holds if a page is stored as meaning rather than as markup.
Measured on 7 September, both shops store a page design as a bespoke Blade
template: **16 templates, 7,044 lines**, one per product design. A new design is
a developer and a deploy; a redesign is sixteen rewrites.

## Decision

The ERP owns a **typed section contract**. A page is an ordered list of section
instances; each instance has a type key, a contract version and a payload
validated by the ERP against that type's schema. A theme decides only how each
section looks.

A theme **declares its capabilities** the way an HQ adapter does: which section
types it renders, at which contract version, and any fallbacks.

Switching a store's theme computes a compatibility report first. Unsupported
sections with no declared fallback **refuse the switch** and name every page and
section that would go dark. A published page never renders silence where a
section was.

Themes are **code registered by key**, never uploadable packages.

A section must never store business truth: no price, stock, delivery charge or
availability word. Those are asked of the ERP at render time.

Full specification: `WEBSITE-SECTION-CONTRACT-2026-09-07.md`.

## Alternatives

- **Store pages as HTML from a rich editor.** Rejected: the theme then is the
  page, so a theme switch means rebuilding every page by hand, which is the
  outcome D3 exists to avoid.
- **Keep per-design templates, just move them into the ERP.** Rejected: it
  relocates the 7,044 lines without removing the deploy-per-design cost.
- **Uploadable theme packages.** Rejected: arbitrary code or templating
  executing inside the system that holds the company's books.

## Consequences

- The landing page editor becomes a section composer, and it is the heart of
  Phase 1.
- Section types are versioned; both old and new majors stay renderable until
  every stored instance is migrated.
- Two themes rendering the same page must show the same facts, because
  validation lives with the section type and not with the theme.
