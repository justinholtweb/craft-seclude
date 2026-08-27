# Seclude — design

## The gap

Craft's content permissions stop at the container. You can say "Jane may edit the News section";
you cannot say "Jane may edit *these three* News entries". The same wall exists for category
groups, asset volumes and user groups.

`trendyminds/isolate` filled the entry half for Craft 3/4 — 27,373 installs — and was abandoned on
2024-02-20, explicitly because entrification changed the shape of the problem. The Craft 5 plugins
in this space (`brikdigital/entry-type-permissions`, `zeix/craft-entry-type-policy`,
`amici/craft-super-content-access`) all restrict by *entry type*, which is still a container.

Nothing restricts by element.

## The one invariant

**Seclude can only take away.**

Every authorization handler returns `false` or `null`. Never `true`. A user needs Craft's own
permission for the section first; Seclude narrows what that permission reaches. This is what makes
the plugin safe to install on a live site: switching it on cannot widen anybody's access, and
switching it off cannot leave anything locked.

## Model

### Policy

A policy is configuration (project config, mirrored to `{{%seclude_policies}}`):

- **Subjects** — user groups and/or named users. Who this policy secludes.
- **Scope** — an element type plus the sources it governs (section UIDs, category group UIDs,
  volume UIDs, user group UIDs). Outside its scope a policy is silent.
- **Grants** — one or more providers that answer "which elements inside the scope".
- **Abilities** — view / save / create / delete / duplicate, and `propose`, which allows drafts
  while refusing to publish them.

### Grants

| Grant | Answers |
| --- | --- |
| `assigned` | elements hand-picked for this user in the CP (the Isolate behaviour) |
| `author` | elements the user authored (Craft 5 multi-author aware) |
| `branch` | an assigned structure entry **and its descendants** |
| `relation` | elements whose field *X* relates to the user, or to what the user relates to |
| `condition` | Craft's element condition builder |

Each grant answers with a `craft\db\Query` selecting element IDs. There is exactly one
implementation per grant, used for both halves of the job — filtering an index and judging a
single element — so the listing and the edit page cannot disagree.

### Combination

Policies **grant**, so they union (OR). But scope is what makes a user secluded at all:

1. Exempt user (admin, or `seclude:bypass`) → `null`, Seclude is silent.
2. No enabled policy whose subjects match the user *and* whose scope covers this element → `null`.
3. Otherwise the user is secluded here. Allow only if some matching policy grants the element
   **and** permits the ability. Otherwise `false`.

Step 2 is the safety valve: Seclude never governs content it was not pointed at.

## Storage

Policies are configuration → project config, so they deploy.
Assignments are content → `{{%seclude_assignments}}` only. Entry IDs do not belong in project
config, and an assignment made by an editor is not a schema change.

## Enforcement points

All five ask `services\Authority`. None of them decide anything themselves.

- `services\Guard` — `Elements::EVENT_AUTHORIZE_{VIEW,SAVE,CREATE_DRAFTS,DUPLICATE,DUPLICATE_AS_DRAFT,COPY,DELETE,DELETE_FOR_SITE}`
- `services\QueryFilter` — `ElementQuery::EVENT_BEFORE_PREPARE`, **armed only inside element-index
  and element-selector controller actions**
- `services\Sources` — `Element::EVENT_REGISTER_SOURCES`, hides empty sources
- `services\Adoption` — `Elements::EVENT_AFTER_SAVE_ELEMENT`, assigns what a secluded user creates
- `twig\SecludeVariable`

### Why the query filter is armed, not global

Bouncer filters element queries globally and needs a routing flag to stop breaking its own URLs.
Seclude does not repeat that: it only filters CP requests inside a known list of index/selector
actions. Everything else — the edit page's own element lookup, front-end routing, queue jobs,
Craft's internal queries — is untouched, so a denied element reaches its edit page and gets a
clean refusal instead of a mystery 404.

## Failure behaviour

Opposite of Bouncer, deliberately.

Bouncer denies when it cannot evaluate a rule: the audience is the public and the cost of a wrong
allow is a leak. Seclude's audience is logged-in staff and the cost of a wrong deny is the whole
editorial team locked out of the CP. So:

- A policy that cannot be evaluated (missing section, deleted field, malformed condition) is
  **disabled and reported**, never silently enforced.
- Inside an evaluable policy, an element matching no grant is **denied**.

`seclude/policies/panic` disables every policy from the console, for when it goes wrong anyway.

## Traps this has to survive

- **Nested entries.** Craft's own owner-delegation lives inside `Element::canView()`, which the
  authorization event pre-empts. A nested entry must resolve to its owner before judgement, or
  editing a Matrix block inside a granted entry is refused.
- **Drafts and revisions** resolve to their canonical element.
- **Users.** Seclude must never become a privilege-escalation route: a policy may not grant access
  to an admin account, and `seclude:manageAssignments` may only hand out elements the assigner can
  reach themselves.
- **Create then lose it.** A secluded user with `create` who saves a new entry has no assignment
  for it, so the redirect back to the edit page would refuse them. `Adoption` assigns it.
