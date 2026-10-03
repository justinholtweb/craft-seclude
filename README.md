# Seclude

Per-element user restrictions for Craft CMS 5.

Craft's content permissions stop at the container. You can say *Jane may edit the News section*.
You cannot say *Jane may edit these three News entries* — and the same wall stands in front of
category groups, asset volumes and user groups.

Seclude carries the permission the rest of the way down, to the individual element.

```
Craft            Jane → News  ────────────────────────────►  all 2,400 entries
Seclude          Jane → News → entries she authored,
                               entries in her region,
                               and three picked by hand   ►  31 entries
```

## The one thing to know

**Seclude can only take away.**

Every authorization decision it makes is *refuse* or *no opinion*. Never *permit*. A user still
needs Craft's own permission for the section first; Seclude narrows what that permission reaches.

That is what makes it safe to put on a live site. Switching it on cannot widen anybody's access,
switching it off cannot leave anything locked, and a policy you have not written yet governs
nothing at all.

## Installing

```sh
composer require justinholtweb/craft-seclude
php craft plugin/install seclude
```

Craft 5.3+, PHP 8.2+. No runtime dependencies, no build step.

Nothing changes until you write a policy.

## Policies

A policy says: **these people**, in **this scope**, get **these elements**, and may do **this much**
to them.

| | |
| --- | --- |
| **Subjects** | User groups, named users, or everyone |
| **Scope** | Entries, categories, assets or users — and which sections, groups or volumes |
| **Grants** | Which elements inside that scope they reach |
| **Abilities** | View, edit, create, delete, duplicate, propose |

Policies are project config, so they deploy with the sections they govern.

**Policies add up.** Two policies naming the same editor combine their grants; neither can take
away what the other gives. What restricts is *scope*: the moment any policy names a user and covers
a section, that user is secluded there and sees only what some policy grants them.

## Grants

### Assigned elements

Pick them by hand, per user, on the Assignments screen. This is what
[`trendyminds/isolate`](https://plugins.craftcms.com/isolate) did, and it is still the right answer
for a handful of pages.

It is the wrong answer for forty editors and two thousand entries, which is what the rest of these
are for.

### Elements they authored

Entries they are an author of — Craft 5 multi-author aware — or assets they uploaded.

### Elements related to them

The one that scales.

- **Direct** — the element's relation field points at them. *Article → Owner → Jane.*
- **Shared** — the element's field and the user's own field point at the same thing.
  *Article → Region → Midwest*, and *Jane → Region → Midwest*.

Shared mode is multi-tenancy in two dropdowns. Add a Region field to entries and to users, and
every article written from then on lands with the right people without anybody touching the plugin
again.

**Lock the user's field.** Craft lets people edit the fields on their own account. If Jane can
set her own Region, she can choose what this grant gives her. In the user field layout, give the
field an *Editable* condition that leaves the governed users out.

### Elements matching a condition

Craft's own condition builder — status, dates, custom field values, anything a plugin adds a rule
for. The same UI as entry index filters.

If a rule stops working — its field is deleted, or the plugin that provided it is uninstalled —
the grant matches **nothing** until the condition is fixed. Craft on its own would quietly treat
that rule as matching everything.

### …and everything beneath them

A policy-level switch rather than a grant, so it composes over all of the above. Grant somebody a
documentation landing page and they get the whole branch, including pages written after the grant
was made. Depth-limitable.

## Abilities

| | |
| --- | --- |
| **View** | Open it |
| **Edit** | Save changes, and publish drafts of it |
| **Create** | Add new elements — whatever they make is assigned to them |
| **Delete** | Delete it |
| **Duplicate** | Copy it |
| **Propose changes** | Create drafts. Without *Edit*, this is suggest-only |

**Propose without Edit** is the arrangement most people are actually after: a contributor can draft
anything they are granted, and somebody else publishes it.

Create, delete and duplicate are off by default. They are the three ways a restricted user gets
*around* a restriction — by making a sibling, or by deleting the thing they were supposed to be
editing carefully.

## What it enforces

Everything routes through one verdict, so a listing and an edit page cannot disagree.

- **Edit pages** — `view`, `save`, `delete`, `duplicate` and draft creation, through Craft's
  authorization events
- **Element indexes** — filtered, so refused entries are not sitting there leaking their titles
- **Relation fields and link pickers** — filtered too, optionally
- **Index sources** — sections with nothing in them for that user are hidden
- **Moves, deletes and saves that skip Craft's checks** — moving an entry to another section,
  dragging it in a structure, the asset and user screens. Refused for an element the user may not
  edit or delete
- **New elements** — assigned to whoever created them, so nobody is locked out of their own work

Front-end output is untouched. Seclude is about authoring; for gating public content, that is a
different job.

Two places in the control panel can still show the **title** of an element the user can't open:
the *Recent Entries* dashboard widget, and an element chip requested directly by its ID. Neither
lets them open, edit or list it.

## Who can edit what

For admins and anyone who can assign. A screen that answers the question, for any user and any
element they can see themselves: every ability, every policy that
applied, every policy that did not and why, and which grant matched.

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

## Console

```sh
php craft seclude/policies/list              # what is enforced, and what is not
php craft seclude/policies/disable <handle>
php craft seclude/policies/panic             # turn every policy off
php craft seclude/assignments/list jane@example.com
php craft seclude/assignments/prune
```

`panic` exists because a permissions plugin needs a way back that does not require getting into the
control panel it may be blocking.

Policies live in project config, so `panic` changes `project.yaml`. On an environment that deploys
project config from the repository, the next `project-config/apply` switches them back on — commit
the change, or fix the policy before the next deploy.

## Settings

| | |
| --- | --- |
| **Seclude admins** | Off. There is always somebody left who can undo a policy |
| **Assign new elements to their creator** | On. Otherwise "may create" is a trap |
| **Hide empty sources** | On. Only sources a policy actually governs are ever hidden |
| **Filter element pickers** | On. Turn off if editors must still link to pages they cannot edit |
| **Log refusals** | Off. Useful while rolling a policy out, noisy afterwards |

## Permissions

- **Bypass all Seclude policies** — for a support account that should not be an admin
- **Assign elements to other users** — hand out work without being an admin. Non-admins can only
  hand over an element if they hold every ability the policy grants on it themselves, can't assign
  to themselves, and leave alone assignments of elements they couldn't hand over

## When a policy cannot be read

If a policy names a section that has been deleted, or a field that has gone, Seclude **disables it
and says so**. It does not enforce it.

This is the opposite of what a public-facing access plugin should do, and deliberately so. The cost
of a wrong *allow* here is that a logged-in colleague sees a page early. The cost of a wrong *deny*
is the entire editorial team locked out of the control panel by a stale UID.

Inside a policy it can read, an element matching no grant is refused.

A named user who has been deleted doesn't count as unreadable: they simply match nobody. Otherwise
deleting one person a policy names would switch it off for everyone else it names.

## Coming from Isolate

`trendyminds/isolate` was Craft 3/4 only and was retired in February 2024. Seclude does what it did
— per-entry assignment, per user, with a filtered index — and adds the scopes, grants and abilities
above. There is no automatic migration; the assignment model is close enough that a short script
against `{{%seclude_assignments}}` will do it, and different enough that guessing would be worse
than asking.

## Notes

Craft 5's nested entries are handled: a Matrix or CKEditor entry defers to whichever entry owns it,
and drafts and revisions defer to their canonical. Judging them on their own — which is what happens
if a plugin takes Craft's authorization event at face value — refuses a block inside an entry the
user legitimately holds.

## Licence

Commercial, free of charge. See `LICENSE.md`.
