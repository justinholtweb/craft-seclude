---
title: FAQ
slug: faq
order: 50
summary: Common questions about per-entry permissions in Craft CMS.
---

## Is Seclude free?

Yes. There is one edition and nothing is held back.

## Can Seclude give someone access Craft wouldn't?

No, and it is built so it can't. Every decision Seclude makes is *refuse* or *no opinion*, never
*permit*. A user still needs Craft's own permission for a section. Seclude only narrows how much of
it they reach.

That is also what makes it safe to install on a live site. Switching it on can't widen anybody's
access, and switching it off can't leave anything locked.

## Does installing it change anything?

No. Nothing is restricted until you write a policy, and a policy only affects the sections it
names.

## How is this different from restricting by entry type?

The other Craft 5 plugins in this space restrict by entry type, which is still a container: every
entry of that type, or none. Seclude goes down to the individual entry, so *Jane may edit these
three News entries*, or *the entries in her region*, or *the ones she wrote*.

## I used Isolate. Is this a replacement?

Yes. `trendyminds/isolate` was Craft 3/4 only and was retired in February 2024. Seclude covers what
it did — per-entry assignment with a filtered index — and adds rules, so you don't have to
hand-assign everything. Existing assignments need a short script to move across; there's no
automatic migration.

## Does it work with categories, assets and users?

Yes. A policy can govern entries, categories, assets or users, narrowed to particular sections,
category groups, volumes or user groups.

## Can an editor suggest changes without publishing them?

Yes. Give them **Propose changes** without **Edit**. They can draft anything they are granted, and
somebody else publishes it.

## Will refused entries still show up in the entry index?

No. Indexes, counts, exports and relation-field pickers are filtered, so a refused entry's title
doesn't appear. Two places can still show a title: the Recent Entries dashboard widget, and an
element chip requested directly by its ID.

## Does it affect the front end?

No. Seclude governs authoring in the control panel. Front-end templates can ask
`craft.seclude.can()` if they want the same answer.

## Can a department head hand out work without being an admin?

Yes, with **Assign elements to other users**. They can only hand over an element if they hold every
ability the policy grants on it themselves, and they can't assign anything to themselves.

## What if I lock everyone out?

Admins aren't restricted by default, and `php craft seclude/policies/panic` switches every policy
off from the console. See [Troubleshooting](troubleshooting#everyone-is-locked-out).

## Do policies deploy?

Yes. Policies are project config, so they deploy with the sections they govern. Hand-picked
assignments stay in the database, because an element ID refers to a different element on every
environment.
