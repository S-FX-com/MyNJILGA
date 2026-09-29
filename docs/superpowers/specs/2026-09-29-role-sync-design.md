# WordPress role sync — design

**Date:** 2026-09-29
**Branch:** `feat/role-sync`
**Version:** 3.5.0 → 3.6.0 (minor: new behaviour, nothing removed)

## Implementation status

**Only the add-only slice is built.** Everything below this section is the
original design, kept as the reference for the deferred parts. What shipped
answers one question: when an invoice is paid and the contact is tagged
Professional in the CRM, does their account get the `professional` role?
Before, only when every category happened to map to `professional`, the
role already existed, and the invoice's frozen roster agreed with the
contact's tags.

**Built** (`includes/invoicing/class-role-sync.php`, `MyNJILGA_Role_Sync`;
`tests/RoleSyncTest.php`):

- Rule 2 as a pure `resolve_role()`, reusing `MyNJILGA_Pricing_Engine::category_for()`
  so a member is roled as the category they are billed as. `settle()` reads
  the contact's **current CRM tags** at payment time; the role frozen in the
  invoice snapshot is only the fallback when the tags resolve to no role, so
  the old behaviour is a strict subset of the new.
- A pure, **add-only** `plan()`: it returns roles to add and has no remove
  list. Rules 3 and 4 (managed roles, removal, swap) are not implemented.
- Rule 5, widened: `role_undefined` is reported, not silently skipped, and a
  role holding an administrator-level capability (`PRIVILEGED_CAPS`) is never
  granted by a payment (`role_privileged`). The one exception to "undefined is
  a miss": `professional` is created on demand with the capability `read` only.
- Rule 6, hardened: a `user_id` pointing at a deleted user falls back to the
  contact's email; no link is ever written.
- `settle()` counts outcomes per status, isolates each member's role step,
  and writes plain English into the Company Note. `role_undefined` and
  `role_privileged` also leave a callout, option `njilga_role_sync_problem`
  (`MyNJILGA_Role_Sync::problem()`).
- `sync_role_for_user()` uses the same resolver; `grant_role()` stays as a thin
  wrapper for application approval and invite claim, with the same guards.

**Deviations from the design below:** `sync_contact( $contact, string $fallbackRole = '' )`
replaces `sync_contact( $contact, bool $assumePaid )`: there is no "is the
contact paid?" test to skip here, since a payment is its own proof and the
login hook keeps its existing paid gate. Statuses: `changed`, `unchanged`,
`no_role_configured`, `role_undefined`, `role_privileged`, `no_account`,
`no_contact` (`error` inside `settle()` only); there is no `not_paid`. One
behaviour change: a matched category mapped to "— no role —" now gets no role,
where the login hook's old loop fell through to the default category's role.

**Deferred on purpose:** removal or swapping of roles, the managed-role
history, `mapping_signature()`, the Settings-save auto-sync, the Action
Scheduler full sync and preview, the Setup review screen, the FluentCRM
tag-change hooks (their names could not be verified here), and any change to
the downgrade sweep. Removal is where the risk lives: an empty desired role
would strip a paid member, and the plugin swaps category tags as
detach-then-attach, so a synchronous hook would see a transient "no category"
state and flap the role.

**Recommended next steps**, in order:

1. **A tag-change trigger, add-only.** Payment covers "tagged, *then* paid".
   It does not cover "paid, *then* tagged Professional": a contact tagged after
   their invoice settled, on no firm roster, tagged by hand, or in a firm whose
   members all owe $0 never passes through `settle()`. Their only safety net is
   the next login or registration, and `wp_login` does not fire for a
   remember-me cookie session.
2. **An add-only backfill** (the Setup preview/apply, restricted to add) for
   members who are already paid and lack the role today.

Verify on staging first: the FluentCRM hook names and argument order, whether
they fire for bulk and automation tag changes, and that
`Subscriber::hasAnyTagId()` does not read a stale cached tag list.

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
