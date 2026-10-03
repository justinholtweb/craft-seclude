---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: Locked out, a policy that isn't enforced, and an entry that won't open.
---

## Start with Who can edit what

Nearly every question here has its answer on **Seclude → Who can edit what**. Pick the user and the
element. The report shows every ability, every policy that applied and why, and which grant
matched or didn't. The console version works even when the control panel doesn't:

```sh
php craft seclude/explain/element jane@example.com 1041
```

## Everyone is locked out

Admins aren't secluded unless `secludeAdmins` is on, so an admin account is the first way back in.

If that isn't available, switch every policy off from the console:

```sh
php craft seclude/policies/panic
```

Policies live in project config, so `panic` changes `project.yaml`. On an environment that deploys
project config from your repository, the next `project-config/apply` switches the policies back on.
Commit the change, or fix the policy before the next deploy.

To switch off one policy instead of all of them:

```sh
php craft seclude/policies/disable <handle>
```

## A policy isn't being enforced

Check **Seclude → Policies**. A policy that can't be evaluated is listed with a warning, and it is
**not enforced**. That happens when:

- a section, category group, volume or user group it names has been deleted
- a field a relation grant uses has been deleted

This is deliberate. A wrong *allow* here means a logged-in colleague sees a page early. A wrong
*deny* means the whole editorial team is locked out of the control panel by a stale UID. Fix the
policy and it is enforced again.

A named user who has been deleted doesn't count. They simply match nobody, and the policy carries
on for everyone else it names.

`php craft seclude/policies/list` shows the same thing from the console.

## Somebody can see more than they should

- **Do they have Craft's own permission for the section?** Seclude only narrows what that
  permission reaches.
- **Is the section actually in a policy's scope?** Seclude governs only what a policy names.
  Everything else is Craft's business.
- **Is another policy granting it?** Policies add up. Who can edit what shows which grant matched.
- **Are they an admin, or do they hold the bypass permission?** Either one exempts them.
- **Is it a shared relation grant?** If they can edit their own field, they can widen it. See
  [Lock the user's field](configuration#elements-related-to-them).

## Somebody can see less than they should

- **Is the element inside the scope of a policy that names them?** Once it is, they need a grant
  for it, from that policy or any other.
- **Is the ability ticked?** Abilities are off unless ticked, and a policy grants only what it
  names.
- **Is it a condition grant?** If a rule in the condition refers to a field that was deleted, the
  grant matches nothing until the condition is fixed.

## An entry opens but won't save

Usually the user has **Propose changes** but not **Edit**. They can draft the entry but not publish
it, which is exactly what that combination is for.

## They created an entry and can't open it

**Assign new elements to their creator** (`adoptCreatedElements`) is off, or the policy that lets
them create doesn't use **Elements assigned to them**. Turn the setting on and add that grant.

## Seeing what was refused

Turn on **Log refusals** while rolling out a policy. **Seclude → Refusals** then lists every refusal
with its reason. Turn it off again afterwards: a busy site's index requests write a lot of rows.
