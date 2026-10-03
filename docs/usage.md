---
title: Usage
slug: usage
order: 30
summary: What Seclude enforces, Who can edit what, assignments, Twig and the console.
---

## What it enforces

Every check goes through one verdict, so a listing and an edit page can't disagree about an element.

- **Edit pages.** View, save, delete, duplicate and draft creation are refused through Craft's own
  authorization events, so the buttons Craft draws match what the user can actually do.
- **Element indexes.** Refused elements are filtered out of the listing, the counts and exports.
  Leaving them listed would leak their titles, and keeping titles private is often the whole
  point.
- **Relation fields and link pickers.** Filtered too, unless you turn off `filterSelectionModals`.
- **Index sources.** Sections in which a user has nothing are hidden, but only sections a policy
  actually governs.
- **Moves, deletes and saves that skip Craft's checks.** Moving an entry to another section,
  dragging it around a structure, and the asset and user screens never ask Craft's authorization
  question. Seclude refuses those writes for any element the user may not edit or delete.
- **New elements.** Whatever a user creates is assigned to them, so nobody is locked out of their
  own work. It only happens for a real creation, never when an element is resaved or moved.

### Drafts and nested entries

A draft or revision is judged as the entry it belongs to, and so is a Matrix or CKEditor nested
entry. Judging a block on its own would refuse an edit to an entry the user legitimately holds:
the page opens and then won't save. The same rule applies to listings, which hide the nested
entries of an owner the user can't see.

### What it doesn't touch

Front-end output. Seclude is about authoring. Gating public content is a different job.

Two places in the control panel can still show the **title** of an element a user can't open: the
*Recent Entries* dashboard widget, and an element chip requested directly by its ID. Neither lets
them open, edit or list the element.

## Who can edit what

**Seclude → Who can edit what** answers that question for any user and any element: every ability,
every policy that applied, every policy that didn't and why, and which grant matched.

Admins can use it, and so can anyone with **Assign elements to other users**. Non-admins can only
explain elements they can see themselves.

It is the first place to look when someone says "I can't open this". The same report is available
from the console:

```sh
php craft seclude/explain/element jane@example.com 1041
php craft seclude/explain/coverage jane@example.com regionalEditors
```

```
Ana Demo → Style guide

  view         Permitted     Granted by the “Demo — their own writing” policy.
  save         Permitted     Granted by the “Demo — their own writing” policy.
  create       Permitted     The “Demo — hand-assigned notes” policy permits creating.
  delete       Refused       No policy permits “Delete” here.
  duplicate    Refused       No policy permits “Duplicate” here.
  propose      Permitted     Granted by the “Demo — their own writing” policy.

Policies
  secludeDemoAssigned      applies
      ✗ elements assigned to them (assigned)
  secludeDemoAuthors       applies
      ✓ entries they authored (author)
```

`coverage` turns the question around and lists everything one user can reach under one policy.

## Assignments

**Seclude → Assignments** lists every policy that uses **Elements assigned to them**, the people it
names, and how many elements each one holds. Choose **Edit** next to a person to pick their
elements. The picker only offers the policy's own sections.

Assignments made automatically, when somebody creates an element, show up here like any other.

## Twig

`craft.seclude` is for sites that give contributors a front-end dashboard instead of CP access, and
want it to list the same things Seclude would let them into.

```twig
{# Craft's permissions and Seclude's together. False for a guest. Safe to gate on. #}
{% if craft.seclude.can(entry, 'save') %}
    <a href="{{ entry.cpEditUrl }}">Edit</a>
{% endif %}

{# Seclude's verdict alone, with the reason. #}
{% set verdict = craft.seclude.check(entry, 'view') %}
{{ verdict.reason }}

{# Is this user secluded for entries at all? #}
{% if craft.seclude.governs('craft\\elements\\Entry') %}…{% endif %}
```

`check()` is *silent*, not *refused*, for anything no policy governs, so don't gate on it. Gate on
`can()`.

## Console

```sh
php craft seclude/policies/list              # what is enforced, and what isn't
php craft seclude/policies/enable <handle>
php craft seclude/policies/disable <handle>
php craft seclude/policies/panic             # switch every policy off
php craft seclude/assignments/list jane@example.com
php craft seclude/assignments/prune          # drop assignments no policy uses any more
```

`panic` exists because a permissions plugin needs a way back in that doesn't depend on the control
panel it may be blocking. See [Troubleshooting](troubleshooting#everyone-is-locked-out).
