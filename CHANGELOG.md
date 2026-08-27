# Changelog

## 5.0.0 — 2026-08-27

Initial release.

- Policies: subjects (user groups, named users, everyone), scope (entries, categories, assets,
  users, narrowed to sections / category groups / volumes / user groups, and optionally entry
  types), grants, and abilities. Stored in project config.
- Grants: hand-assigned elements, authorship, relations (direct and shared), and Craft element
  conditions. Plus a policy-level "and everything beneath them" for structures, depth-limitable.
- Abilities: view, edit, create, delete, duplicate, and propose — the last of which allows drafts
  while withholding the right to publish them.
- Enforcement through Craft's `Elements` authorization events, element index and selector query
  filtering, index source pruning, and automatic assignment of newly created elements.
- "Who can edit what": a control-panel screen and console command explaining any verdict, including
  the policies that did not apply and why.
- Console commands for listing, enabling, disabling and panicking on policies, and for listing and
  pruning assignments.
- Optional refusal log, off by default, pruned by Craft's garbage collection.
- Two permissions: bypass, and assign-to-others (which cannot be used to hand out anything the
  assigner cannot reach themselves).
