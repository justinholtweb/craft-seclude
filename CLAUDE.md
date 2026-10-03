# Seclude — Craft CMS 5 Plugin

## Project Overview

Seclude carries Craft's content permissions past the container and down to the individual element:
*Jane may edit **these three** News entries*, not just *the News section*. Distributed as
`justinholtweb/craft-seclude`. **Free**, single edition.

It exists because `trendyminds/isolate` — 27,373 installs — was Craft 3/4 only and was retired in
February 2024, explicitly because entrification changed the shape of the problem. The Craft 5
plugins in this space all restrict by *entry type*, which is still a container.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- No build step, no runtime dependencies beyond Craft's own
- Namespace `justinholtweb\seclude`, handle `seclude`
- No editions. `Plugin` has no `isPro()`; do not add one without a reason

## Architecture

### The invariant: Seclude can only take away

Every authorization handler writes `false` or leaves `null`. **None of them ever writes `true`.**
`Verdict::forAuthorizationEvent()` is the single place this is enforced, and
`tests/integration/checks.php` asserts it across every element × user × ability combination it
builds.

This is not a stylistic preference. It is what makes the plugin safe to install on a live site
(switching it on cannot widen anybody's access), and it is also why letting policies govern *user*
elements cannot become a privilege-escalation route.

### One verdict

Everything funnels through `services\Authority::check()` returning a `models\Verdict`, which has
**three** outcomes, not two. `SILENT` is the important one: no policy governs this, so Craft
decides. Collapsing silent into allowed would make Seclude grant access.

The enforcement points only *ask*:

- `services\Guard` — the eight `Elements::EVENT_AUTHORIZE_*` events, mapped onto six abilities
- `services\QueryFilter` — `ElementQuery::EVENT_BEFORE_PREPARE`, **armed**, see below
- `services\Sources` — `Element::EVENT_REGISTER_SOURCES`
- `services\Backstop` — `Element::EVENT_BEFORE_SAVE` / `BEFORE_DELETE` / `BEFORE_MOVE_IN_STRUCTURE`
  and `Entries::EVENT_BEFORE_MOVE_TO_SECTION`, for the Craft endpoints that never raise an
  authorization event. Judges the *stored* element, not the posted one
- `services\Adoption` — `Elements::EVENT_AFTER_SAVE_ELEMENT`, gated on `isNew || firstSave`
- `twig\SecludeVariable`

If another surface needs guarding, it asks `Authority` too.

### Grants answer in SQL, once

`grants\*` each return a `craft\db\Query` selecting one `id` column. `services\Resolver` uses that
same query for both jobs — narrowing a listing, and judging one element — so an index and an edit
page cannot disagree. `GrantInterface::contains()` is an optimisation for grants that can answer
faster (only `ConditionGrant`, via `matchElement()`), and the checks assert the two agree.

### Combination

Policies **union**. Scope is what restricts: the moment any enabled policy names a user and covers
a section, that user is secluded there. `docs/plan.md` has the full decision order.

### Storage

Policies are configuration → project config, mirrored to `{{%seclude_policies}}`.
Assignments are content → `{{%seclude_assignments}}` **only**. An entry ID means a different entry
on every environment.

### Failure behaviour — opposite of Bouncer, deliberately

An unevaluable policy (deleted section, missing field) is **disabled and reported, never
enforced**. Bouncer denies in the same situation because its audience is the public and a wrong
allow is a leak. Seclude's audience is logged-in staff and a wrong deny locks the editorial team
out of the CP. Inside an evaluable policy, an element matching no grant *is* denied.

`php craft seclude/policies/panic` is the way back in.

## Traps found while building this

- **Craft's nested-entry delegation lives inside `Element::canView()`**, which the authorization
  *event* pre-empts — so a Matrix block inside a granted entry would be judged on its own, match no
  grant and be refused. `Authority::subject()` resolves nested → owner and draft → canonical before
  judging. The symptom is an entry that opens and then will not save.
- **`Elements::EVENT_AUTHORIZE_*`, not `Element::EVENT_AUTHORIZE_*`.** The element-level constants
  are deprecated since 4.3 and are per-instance; the service ones are class-level and fire for
  every element.
- **`EVENT_REGISTER_SOURCES` is on `craft\base\Element`, not `ElementInterface`.** Registering
  against the interface is an undefined-constant fatal that only shows up at install time.
- **Element index sources carry `'editable' => true` in their criteria**, so testing a source for
  emptiness conflates "Seclude hid it" with "Craft did". `Sources::governs()` only ever prunes a
  source a policy actually names.
- **Sources are memoized twice** — a private static on `Element` *and* the `elementSources` service
  — so the register event fires once per request. Correct in production; the checks clear both by
  reflection.
- **Per-request caches must be invalidated together.** `Authority::flush()` also flushes
  `Resolver`, and policy and assignment writes call it. Without that, `Adoption` assigns a
  just-created element and the redirect is still refused by a cached "no".
- **Abilities default to all-off.** A partial abilities array — a hand-written project config, a
  migration — must grant only what it names. `Abilities::suggested()` carries the friendly default
  for the CP instead.
- **`hasTitleField` is not enough.** An entry type whose field layout has no `EntryTitleField`
  saves entries with a null title. Every fixture entry then reads as "Untitled entry".
- **Field layout tabs go in as arrays, never as constructed `FieldLayoutTab` objects.**
  `setElements()` reaches for the tab's layout, which a standalone tab has not got — "Field layout
  tab is missing its field layout". Craft attaches the layout itself for an array.
- **Category groups need site settings for *every* site**, not just the primary one, or
  `saveGroup()` throws outright.
- **On a multi-site install, `editSite:<uid>` gates every localized element** before any section
  permission is consulted. Without it a test group can edit nothing anywhere, and every "Craft
  would have allowed this" assertion is vacuously true.
- **Project config writes are buffered in a bare script**, so `UserGroups::saveGroup()` leaves
  `$group->id` null and `assignUserToGroups()` silently assigns nothing. Flush and re-fetch.
- **Craft can't call a static method on a class-name string in Twig.** `getGrantTypes()` returns
  class names; labels are resolved in the controller.
- **`Elements::EVENT_BEFORE_SAVE_ELEMENT` and `EVENT_BEFORE_DELETE_ELEMENT` cannot cancel
  anything** (Craft 5.11 never reads `isValid`). Refuse through the element-level
  `Element::EVENT_BEFORE_SAVE` / `EVENT_BEFORE_DELETE` instead.
  `Structures::EVENT_BEFORE_MOVE_ELEMENT` is deprecated and no longer fires; use
  `Element::EVENT_BEFORE_MOVE_IN_STRUCTURE`, which exists across the whole 5.3+ range.
- **A public property on a query behaviour is settable from the request.** The index controllers
  pass posted `criteria` through `Craft::configure()`, which writes into behaviour properties.
  `SecludeQueryBehavior`'s flag is private, with a method to set it, for exactly this reason —
  `criteria[seclude]=0` used to return an unfiltered index.
- **A plugin controller with no `beforeAction` is reachable by any logged-in user from the front
  end** at `/actions/seclude/…`. Every controller here calls `requireCpRequest()` and checks a
  permission.
- **Craft's field condition rules match *everything* when their field is gone** (`matchElement()`
  returns true; the rule survives rebuild, so counting rules does not catch it). `ConditionGrant`
  probes each rule's `getLabel()`, which throws in that state, and matches nothing instead.
- **Not every save went through `Guard`.** Queued `ResaveElements` jobs run inside an editor's web
  request (`runQueueAutomatically`), so "governed, ungranted and saved anyway" does not mean
  "just created". Adoption keys off `firstSave`, which Craft also sets when an unpublished draft is
  applied.
- **Publishing a new entry asks `canSave()` about a fake canonical.** `canSaveCanonical()` clones
  the unpublished draft, clears `draftId` and keeps the ID, so it looks like an existing entry
  nobody has been granted. `Guard` checks the `elements` row and judges it as CREATE. Saving an
  entry directly in a test does not exercise this; the checks go through
  `saveElementAsDraft()` → `canSaveCanonical()` → `applyDraft()`.
- **The integration checks run in the console with an identity set**, so a guard on
  `getIsConsoleRequest()` silently disables a service under test. Use the identity, and
  `requestedRoute === 'queue/run'` for the queue.
- **A `php -l` failure straight after writing a file is usually a lie** — the bind mount can serve
  a half-synced file. Re-run before believing it.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container.

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-seclude/tests/integration/checks.php   # 106 checks
bash tests/manual/cp-smoke.sh                                                                                  # 16 checks
docker exec ddev-plugin-testing-web bash -c 'find /var/www/craft-seclude/src -name "*.php" -print0 | xargs -0 -n1 php -l'
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-seclude/tests/manual/seed-demo.php [--clean]
```

`checks.php` builds its own sections, fields, users and content, and is idempotent and
self-cleaning — it purges anything left by a previous run before it starts, because a run that dies
mid-build leaves duplicate handles behind and the *next* failure looks nothing like its cause.

**The two suites use different prefixes on purpose.** The fixture is `secludeTest`, the demo is
`secludeDemo`. They were both `seclude` at first, and running the checks silently deleted the demo.

`cp-smoke.sh` renders every CP screen as a logged-in admin and posts a real policy through the form
endpoint. Twig errors are invisible to the PHP checks, and it caught two.

The checks write project config (permissions, sections), so a run can leave `project.yaml` stale
and the next request errors with "The loaded project config is out-of-date." — `php craft up` fixes
it.

`ddev` on this machine is flaky: `ddev restart` reports `ddev-router failed to become ready` while
the containers are in fact healthy — see `[[ddev-shared-router-stale-vite-configs]]`. Use
`docker exec ddev-plugin-testing-web …` rather than `ddev exec`, which runs under `set -u`.

**Always test uninstall → reinstall before tagging.** `afterUninstall()` removes the top-level
`seclude` project-config key; without it the key outlives the plugin and reinstalling resurrects
old policies — restrictions nobody remembers writing, on content that has moved on. Verified clean
on 2026-08-27.

## Coding conventions

- `Craft::t('seclude', '…')` for user-facing strings; `src/translations/en/seclude.php`
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- Seclude's own element lookups use `.seclude(false)`
- When in doubt about a *policy*, do not enforce it. When in doubt about an *element* inside a
  policy that does apply, refuse it
