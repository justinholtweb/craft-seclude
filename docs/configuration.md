---
title: Configuration
slug: configuration
order: 20
summary: Policies, grants, abilities, settings and permissions.
---

## Policies

A policy says: **these people**, in **this scope**, get **these elements**, and may do **this
much** to them.

| | |
|---|---|
| **Subjects** | User groups, named users, or everyone |
| **Scope** | Entries, categories, assets or users, and which sections, groups or volumes (optionally narrowed to entry types) |
| **Grants** | Which elements inside that scope they reach |
| **Abilities** | View, edit, create, delete, duplicate, propose changes |

Policies are project config, so they deploy with the sections they govern. Hand-picked
assignments are content and stay in the database, because an element ID refers to a different
element on every environment.

### Policies add up

When two policies name the same editor, their grants combine. Neither can take away what the other
gives.

What restricts is **scope**. Once any enabled policy names a user and covers a section, that user
is *secluded* there. From then on they see only what some policy grants them in that section.

## Grants

### Elements assigned to them

Pick elements by hand, per user, on **Seclude → Assignments**. This suits a handful of pages. For
forty editors and two thousand entries, use the grants below.

### Elements they authored

Entries they are an author of, multiple authors included, or assets they uploaded.

### Elements related to them

This is the grant that scales.

- **The element's field points at them.** *Article → Owner → Jane.*
- **The element's field and their own field point at the same thing.** *Article → Region →
  Midwest*, and *Jane → Region → Midwest*.

The second mode gives you multi-tenancy with two dropdowns. Add a Region field to entries and to
users, and every article written from then on reaches the right people. Nobody has to touch the
plugin again.

> **Lock the user's field.** Craft lets people edit the fields on their own account. If Jane can
> change her own Region, she can choose what this grant gives her. In the user field layout, give
> the field an **Editable** condition that leaves the governed users out.

### Elements matching a condition

Craft's own condition builder, the same one entry index filters use: status, dates, custom field
values, and any rule a plugin adds.

A condition with no rules matches the whole scope. The policy list says so in plain words rather
than "0 rules".

If a rule stops working, because its field was deleted or the plugin that provided it was
uninstalled, the grant matches **nothing** until you fix the condition. Left to itself, Craft
quietly treats a rule like that as matching everything.

### …and everything beneath them

**Include everything beneath** is a policy-level switch, not a grant, so it combines with all of
the above. Grant somebody a documentation landing page and they get the whole branch, including
pages written after the grant was made. Use **Levels** to limit how deep it goes.

## Abilities

| | |
|---|---|
| **View** | Open it |
| **Edit** | Save changes, and publish drafts of it |
| **Create** | Add new elements. Whatever they make is assigned to them |
| **Delete** | Delete it |
| **Duplicate** | Copy it |
| **Propose changes** | Create drafts. Without *Edit*, they can suggest but not publish |

**Propose changes without Edit** is the arrangement most teams are after: a contributor drafts
anything they are granted, and somebody else publishes it.

Every ability is off until you tick it, and a policy grants only what it names. Create, delete and
duplicate deserve the most thought. They are the three ways a restricted user gets *around* a
restriction: making a sibling, or deleting the thing they were supposed to edit carefully.

## Settings

**Settings → Plugins → Seclude**, or `config/seclude.php` for per-environment values.

| Setting | Default | |
|---|---|---|
| `secludeAdmins` | Off | Whether policies apply to admins. Off guarantees there is always somebody left who can undo a policy |
| `adoptCreatedElements` | On | Assign a new element to whoever created it. Otherwise *Create* is a trap: they save a new entry and are refused the page Craft takes them to next |
| `hideEmptySources` | On | Hide index sources in which a secluded user has nothing. Only sources a policy actually governs are ever hidden |
| `filterSelectionModals` | On | Filter relation-field and link pickers too. Turn off if editors must still be able to link to pages they can't edit |
| `logRefusals` | Off | Record every refusal. Useful while rolling a policy out, noisy afterwards |
| `logRetentionDays` | 30 | How long the refusal log is kept. Pruned by Craft's garbage collection |

```php
<?php
// config/seclude.php
return [
    'logRefusals' => App::env('SECLUDE_LOG_REFUSALS') ?? false,
];
```

## Permissions

| Permission | |
|---|---|
| **Bypass all Seclude policies** | Exempts a user without making them an admin. Suits an agency support account |
| **Assign elements to other users** | Lets a non-admin hand out work. See below |

Policies themselves are admin-only. They are permissions, and permissions are an admin's
business.

### What an assigner can and can't do

Holding **Assign elements to other users** does not let someone hand out more than they have:

- They can only assign an element if they hold every ability the policy grants on it themselves.
  Someone who can only view an entry can't hand it out under a policy that allows editing and
  deleting.
- They can't assign anything to themselves.
- Assignments of elements they couldn't hand over themselves are hidden from them, and saving the
  screen leaves those assignments where they are.
- Only elements inside the policy's scope can be assigned.
