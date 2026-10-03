---
title: Installation
slug: installation
order: 10
summary: Requirements, install, and writing your first policy.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

No other dependencies, and no build step.

## Install

```sh
composer require justinholtweb/craft-seclude
php craft plugin/install seclude
```

Or find **Seclude** in the Craft Plugin Store and install it from there.

Seclude is free. There is one edition and nothing is held back.

## Nothing changes until you write a policy

Installing Seclude restricts nobody. A section is only affected once an enabled policy names a
user and covers it. Everything else carries on exactly as Craft's own permissions say.

This works because **Seclude can only take away**. Every decision it makes is either *refuse* or
*no opinion*, never *permit*. A user still needs Craft's own permission for a section. Seclude
narrows how much of that section the permission reaches. Switching Seclude on cannot widen
anybody's access, and switching it off cannot leave anything locked.

## Your first policy

Say a group of regional editors should edit only the News entries they wrote, plus a few picked
for them by hand.

1. Make sure the group already has Craft's own permissions for the **News** section. Seclude works
   inside those, so without them it has nothing to narrow.
2. Go to **Seclude → Policies** and choose **New policy**.
3. Under **Who this applies to**, pick the **Regional editors** group.
4. Under **What it governs**, choose **Entries** and tick **News**.
5. Under **What they get**, add **Elements they authored** and **Elements assigned to them**.
6. Under **What they may do**, tick the abilities they should have. **View**, **Edit** and
   **Propose changes** are a good start.
7. Save.

From now on a regional editor sees only those entries in the News index, and is refused any other
News entry if they reach it some other way. Hand-pick extras on the **Assignments** screen.

Before you tell the team, check the result on [Who can edit what](usage#who-can-edit-what).

## Admins are never restricted

By default Seclude leaves admins alone, so there is always somebody who can undo a policy. You can
change that under [Settings](configuration#settings), but think twice.

## Coming from Isolate

`trendyminds/isolate` was Craft 3/4 only and was retired in February 2024. Seclude does what it
did — per-entry assignment, per user, with a filtered index — and adds scopes, grants and
abilities.

There is no automatic migration. The two assignment models are close enough that a short script
writing to `{{%seclude_assignments}}` will move them across, and different enough that guessing
would be worse than asking.
