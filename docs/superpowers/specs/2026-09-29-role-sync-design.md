# WordPress role sync — design

**Date:** 2026-09-29
**Branch:** `feat/role-sync`
**Version:** 3.5.0 → 3.6.0 (minor: new behaviour, nothing removed)

## Implementation status

**Fully built.** `feat/role-sync` (v3.6.0) shipped everything below —
`MyNJILGA_Role_Sync` with the pure `resolve_role()` / `managed_roles()` /
`plan()` / `mapping_signature()`, removal and swapping, the managed-role
history (with a Setup **Stop managing** action for a role no longer in the
map), the FluentCRM tag-change hooks, the Settings-save resync, the Action
Scheduler full sync with the Setup review-and-apply screen, and the
downgrade sweep removing every managed role. Its implementation plan is
`docs/superpowers/plans/2026-09-29-role-sync.md`.

`sfx/gallant-edison` (v3.7.0) had built an add-only slice of the same
design in parallel; what it added beyond the design was folded in on merge:

- **Privileged roles are never granted by a payment** — a role holding any
  capability in `PRIVILEGED_CAPS` (`manage_options`, `promote_users`,
  `edit_users`, …) is refused and reported as `role_privileged`, and like an
  undefined role it leaves the member's existing roles untouched (Rule 5,
  widened).
- **The legacy `professional` role is created on demand** (capability
  `read` only) when a category maps to it and the site lacks it — nothing
  else in the plugin ever creates it.
- **`settle()` isolates each member's role step** (a Throwable costs that
  member their role and is flagged on the invoice row, never the roster
  its tags), tallies outcomes per status (`aggregate()`), and writes them
  into the Company Note in plain English (`describe_outcomes()` /
  `describe_problems()`).
- **A stored role problem** (`njilga_role_sync_problem`, `problem()`):
  `role_undefined` / `role_privileged` met by a payment surface as a
  Dashboard callout that keeps counting while the problem recurs and
  clears once a payment grants that role or no category maps to it.

Not adopted from that branch: its add-only `plan()` (superseded by
removal/swap), the snapshot role as a fallback when the current tags
resolve to no role ("— no role —" means no role — Rule 2), and its
email fallback for a `user_id` pointing at a deleted user (a linked
contact whose account is gone gets nothing rather than another account's
roles).
## Problem

Settings → Membership categories already maps each category tag to a
WordPress role. But the plugin only ever **adds** that role: on payment
(`MyNJILGA_Payment_Listener::settle()`), on registration/login
(`sync_role_for_user()`), on application approval and on invite claim.
The only thing that takes a role away is the manual downgrade sweep. So
roles go stale:

- A paid member whose category tag changes in FluentCRM (Student →
  Professional, Professional → Retired) keeps the old role, and only gets
  the new one at their next payment or login — on top of the old one.
- When staff change a category's role in Settings, existing members keep
  the old role.
- The downgrade sweep removes the role frozen into the invoice snapshot,
  which is the wrong one if the mapping changed after invoicing.

## Goal

A paid member's WordPress role always follows their category: granted on
payment, **swapped** on a category upgrade/downgrade or a mapping change,
removed by the downgrade sweep. One class decides and applies it; every
entry point calls that class.

**Out of scope:** removing roles from unpaid members outside the sweep; a
general tag → role mapping unrelated to dues categories; importing
existing WP roles into FluentCRM tags.

## Rules

1. **Who is synced.** Only *paid* contacts: they carry the evergreen paid
   tag (`general.paid_tag`, default `dues-paid`) **and** "Dues Paid
   {year}" for the current dues year or the next one — the same test
   `sync_role_for_user()` uses today (`paid_for_current_year()`). Anyone
   else is left untouched; removal for non-payment stays with the sweep.
   Exception: `settle()` skips the year test (see Entry points).
2. **Desired role.** The role of the first category, in Settings
   **Order**, whose tag the contact carries (`MyNJILGA_Tags::has_slug()`
   resolution). No category tag → the `general.default_category`'s role.
   No default → `''`.
3. **Managed roles** — the only roles sync may remove:
   - every role currently in the category map,
   - every role that was *ever* in the map (history, recorded on Settings
     save/reset),
   - the legacy `professional` (`MyNJILGA_Payment_Listener::WP_ROLE`),
   - **minus** WordPress core roles, which sync never removes:
     `administrator`, `editor`, `author`, `contributor`, `subscriber`.
     (A core role can still be *granted* if a category maps to it.)
4. **Apply.** Remove every managed role the user holds that isn't the
   desired role; add the desired role if missing. Desired `''` ("— no
   role —") removes all managed roles.
5. **Misconfiguration guard.** If the desired role is non-empty but not
   defined on the site (`get_role()` is null), do nothing at all and
   report `role_undefined` — never strip a member's old role and leave
   them with nothing because of a typo in Settings.
6. **Contact → account.** `contact->user_id`, else a WP user whose email
   matches the contact's (as `grant_role()` does today). No account →
   `no_account`, not an error.
7. **Idempotent.** Running sync twice changes nothing the second time.

## Components

### New: `includes/invoicing/class-role-sync.php` — `MyNJILGA_Role_Sync`

**Pure part** (no WordPress calls — loaded by `tests/bootstrap.php`):

| Method | Does |
|---|---|
| `resolve_role( array $categories, array $heldSlugs, string $defaultKey ): string` | Rule 2. `$heldSlugs` = category tag slugs the contact carries. |
| `managed_roles( array $categories, array $history ): array` | Rule 3. Sorted, unique, no `''`. |
| `plan( array $userRoles, string $desired, array $managed, bool $desiredDefined ): array` | Rules 4–5 → `{status: 'changed'|'unchanged'|'role_undefined', add: string[], remove: string[]}`. |
| `mapping_signature( array $categories, string $defaultKey ): string` | Hash of the ordered `[tag, role]` pairs + default key. Label/price/tier edits don't change it. |

Constants: `CORE_ROLES`, `HOOK_CHUNK = 'njilga_role_sync_chunk'`,
`AS_GROUP = 'njilga-roles'`, `OPTION_HISTORY = 'njilga_role_sync_history'`,
`OPTION_LAST = 'njilga_role_sync_last'`.

**WordPress part:**

| Method | Does |
|---|---|
| `register()` | Hooks the two FluentCRM tag actions and `HOOK_CHUNK`. |
| `sync_contact( $contact, bool $assumePaid = false ): array` | Rules 1–7 for one contact → `{status: 'changed'|'unchanged'|'not_paid'|'no_account'|'role_undefined', user_id, added, removed}`. |
| `remove_managed( $contact ): bool` | For the sweep: removes every managed role; true if any was removed. |
| `on_tags_changed( $subscriber, $tagIds )` | Tag hook handler (see Triggers). Wrapped in `try/catch`. |
| `remember_roles( array $categories )` | Adds those categories' roles to the history option. |
| `queue_full_sync(): array` | All contacts carrying the paid tag (`Subscriber::filterByTags()`, **any** status — unsubscribing from email isn't leaving the association), chunked by `general.batch_size` into Action Scheduler; inline when AS is missing or refuses a chunk (same pattern as `MyNJILGA_Invoice_Creator::schedule()`). Records `{queued_at, contacts, mode}` in `OPTION_LAST`. |
| `run_chunk( $contactIds )` | AS callback / inline path: `sync_contact()` each, adds the per-status counts to `OPTION_LAST`. |
| `preview(): array` | Dry run over the same contacts → counts per status plus up to 50 `changed` rows (name, email, roles removed, role added). |

### Changed

- **`class-payment-listener.php`**
  - `settle()`: calls `Role_Sync::sync_contact( $contact, true )` instead
    of `grant_role( snapshot role )`. `$assumePaid = true` because the
    payment itself is the proof — a late payment of a *past* year's
    invoice must still grant, as it does today. The Company Note's
    "granted / skipped" counts map to `changed|unchanged` vs the rest.
  - `sync_role_for_user()`: keeps its guards (`contact_for_user()`,
    never re-point a contact another account owns, link an unlinked
    contact), then calls `sync_contact()`. Its own copy of the category
    resolution goes away.
  - `grant_role()`: removed once no callers remain.
- **`class-application-review.php`** and **`class-join-invites.php`**:
  call `sync_contact()` instead of `grant_role( $category['role'] )`.
- **`class-downgrade-sweep.php`**: `remove_role( snapshot role )` →
  `Role_Sync::remove_managed()`. Setting label becomes "Remove WordPress
  membership roles (every role mapped in Settings)"; confirmation-screen
  copy in `class-page-invoicing.php` updated to match.
- **`class-page-settings.php`**: `handle_save()` and `handle_reset()`
  compare `mapping_signature()` before and after saving. If it changed:
  `remember_roles( old categories )`, `queue_full_sync()`, and the
  redirect carries the count for a callout ("Role mapping changed — role
  sync queued for N paid members. Details on Setup → WordPress role
  sync."). The categories section copy "Role is granted on payment,
  best-effort" becomes "Role follows the category: granted on payment
  and kept in sync when a paid member's category tag or this mapping
  changes."
- **`class-page-setup.php`**: new **WordPress role sync** section (FluentCRM
  active only), replacing the Environment row "WordPress roles mapped in
  Settings":
  - mapping table: Order · Category · Tag · Role · status pill (defined /
    not defined on this site / no role);
  - managed roles as pills; last run (time, mode, counts) from
    `OPTION_LAST`;
  - **Review role changes** → `?view=role-sync` confirmation screen
    (modelled on the downgrade sweep's): stat cards (will change,
    already correct, paid without an account, role not defined, carries
    the paid tag but not paid for the current/next year), the first 50
    changes, and an **Apply role changes** form posting to
    `admin-post.php?action=my_njilga_role_sync` (nonce +
    `manage_options`) → `queue_full_sync()` → back to Setup with a
    callout.
  - Built only from `MyNJILGA_Admin_UI` per `design.md` — no inline
    styles, no WP admin classes.
- **`njilga-membership-report.php`**: require the class, `Role_Sync::register()`
  on `plugins_loaded`, the admin-post action, `Version: 3.6.0`.

## Triggers

1. **FluentCRM tag change (automatic, instant).** `fluent_crm/contact_added_to_tags`
   and `fluent_crm/contact_removed_from_tags`, both `( $subscriber, $tagIds )`
   (verified in FluentCRM 3.2.0 `app/Models/Subscriber.php`; fired only
   for tags actually attached/detached). Acts only if `$tagIds`
   intersects the category tag ids, the paid tag id, or the "Dues Paid
   {year}" ids for the current and next year — looked up **without
   creating** tags. Then `sync_contact( $subscriber )`. Tag attaches
   inside `settle()` fire this too; that's harmless (idempotent).
2. **Login / registration.** `sync_role_for_user()` as above.
3. **Payment / approval / invite claim.** As above.
4. **Settings save or reset.** Full sync, only when the signature changed.
5. **Setup button.** Full sync after the confirmation screen.

No automatic full sync runs on upgrade to 3.6.0: staff review the
Setup screen and apply it the first time.

## Error handling

- The tag hook and every `sync_contact()` call from a login or CRM path
  are wrapped in `try/catch` — a role hiccup never breaks a login, a
  payment webhook or a FluentCRM operation.
- `run_chunk()` isolates each contact; one failure is counted and the
  loop continues.
- FluentCRM inactive → every entry point is a no-op; the Setup section
  isn't rendered.

## Testing

`tests/RoleSyncTest.php` (dependency-free runner, `php tests/run.php`),
written test-first against the pure part:

- first category in Order wins when a contact carries two category tags;
- no category tag → default category's role; no default → `''`;
- managed roles include mapped + history + legacy, exclude core roles and `''`;
- student → professional swap: removes `student`, adds `professional`;
- already correct → `unchanged`, no operations;
- desired `''` removes all managed roles;
- desired role undefined → `role_undefined`, no operations;
- `administrator` / `subscriber` never removed; an unmanaged custom role
  (e.g. `shop_manager`) untouched;
- signature changes on tag, role, order or default category; not on
  label or price.

Manual check on staging (njilga.s-fx.dev) with a test contact: move its
category tag in FluentCRM and confirm the WP role swaps; change a
category's role in Settings and confirm the queued sync; run the Setup
preview and apply.
