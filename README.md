# My NJILGA

A WordPress plugin that gives NJILGA admins a one-stop dashboard for the whole association — membership, invoices and applications — with reports for trustees and firms — plus the annual **dues invoicing** process (Stripe + FluentCRM), **online joining** with a firm upsell (Stripe Checkout), a **membership application gate**, a **member-facing dues status page**, and a **My Membership page** for the member and their firm. Everything is driven by FluentCRM tags on the local WordPress install; billing is driven by Stripe. No subscriptions anywhere — every dues cycle is its own one-time invoice.

---

## Installation

1. Copy this folder into `/wp-content/plugins/`
2. Run `composer install` inside the plugin folder (requires PHP 7.4+ and Composer)
3. Activate **My NJILGA** in **WordPress Admin → Plugins**
4. Make sure **FluentCRM** (with the **Companies** module) is active on the same site
5. Connect **Stripe** for invoicing — see [Connecting Stripe](#connecting-stripe) below
6. Open **My NJILGA → Setup** to verify tags, the Stripe connection, and the environment

---

## Menu

| Page | What it shows |
|---|---|
| **Dashboard** | The association at a glance, in three rows of cards that link to where the work happens. **Membership** — active members, paid ahead for next year, expired, firms with an active member, trustees, members without a firm, and a per-category table. **Invoices** — invoiced / collected for the dues year, outstanding and past due across all years, drafts to review, blocked (no Owner), flagged; scoped to the active Stripe mode. **Applications** — the application queue and online joins that need a person. Plus a **Refresh figures** button (membership figures are cached ten minutes and flushed after each payment, sweep, approval and settings save) and alerts (Stripe Test mode; a role a payment could not grant). See [One definition of "active"](#one-definition-of-active). |
| **Reports** | Landing page for every report below, plus the Executive Summary export. |
| **Active Paid Members** | Every *active* member — paid through this year or later — whatever their email-subscription status, with firm, email, trustee flag, payment method. |
| **Trustees** | Every contact carrying a trustee-family tag, labelled Paid, Unpaid, Exempt (Past Presidents and Senior Trustees owe no dues), Inactive or None. |
| **Companies** | FluentCRM Companies that have at least one contact, grouped into **1 / 2–5 / 6+ Active Members** buckets and a **No Active Members** bucket. Companies with no contacts at all are not listed, only counted in a note. |
| **Membership by Firm** | Every FluentCRM Company with ≥1 contact, listed with its contacts. Exports to formatted Excel. |
| **Invoicing** | Annual dues invoicing — see [Dues Invoicing](#dues-invoicing) below. |
| **Payments** | Cross-year Stripe payments ledger — see [The Payments ledger](#the-payments-ledger) below. |
| **Applications** | Enrollment review queue — see [Enrollment gate](#enrollment-gate) — and the **Online joins** tab — see [Online joining](#online-joining). |
| **Settings** | **Dues & Billing** — category mapping, assessment, per-firm billing mode, all switches. **Payments** tab — Stripe connection, mode, and payment settings. |
| **Setup** | Environment checks, tag checklist, **tag-slug audit** and **product-mapping audit** for the settings, plus Stripe connection health and a recent-API-activity log. |
| **Shortcodes** | Every shortcode the plugin provides — a ready-to-paste `[njilga_join]` line per membership category, `[njilga_firm_dues_status]`, `[njilga_firm_renewal_lookup]`, `[njilga_my_membership]`, `[njilga_membership_application]` — with copy buttons and the pages that use each one now. |
| **Tools** | One-off data jobs: the **PMPro Migrator** and the **Historical Invoice Import** (see [Tools](#tools)), plus the import log with an **Undo** for every run. Both create reference history that shows on the [Dues History](#dues-history) tab of a FluentCRM contact or company. |

---

## How status is determined

| Concept | Source |
|---|---|
| Active member | Paid through **this year or later**: the highest `Dues Paid {year}` tag the contact carries (see [One definition of "active"](#one-definition-of-active)) |
| Trustee | Contact has a trustee-family tag (**Trustees**, **Senior Trustee**, **Past President**) |
| Payment method = Check / Invoice | **Paid by Check** / **Paid by Invoice** tags (default: Credit Card) |
| Firm | The FluentCRM **Company** entity linked to the contact |

Tags are looked up by **slug** first, then by exact **title** as a fallback.

### Core report tags

| Slug | Title | Required? |
|---|---|---|
| `dues-paid` | Dues Paid | Yes |
| `unpaid-dues` | Unpaid Dues | Optional |
| `trustees` | Trustees | Yes |
| `senior-trustee` | Senior Trustee | Optional |
| `past-president` | Past President | Optional |
| `paid-by-check` / `paid-by-invoice` | Paid by Check / Invoice | Optional |
| `officer` | Officer | Optional — assessment eligibility |
| `inactive` | Inactive | Optional — "don't bill this record" override |

The **Setup** page can create any of these in one click, plus any slug the Dues & Billing settings refer to (`professional`, `law-student`, `emerging-professional`, `pending-approval`, …).

### One definition of "active"

Every membership number — the Dashboard, the Reports KPI tiles, the Members / Trustees / Companies / Membership-by-Firm lists, their CSV and Excel exports and the Executive Summary — comes from one class, `MyNJILGA_Membership_Stats`, which classifies each contact with `MyNJILGA_My_Membership::standing()`, the same rule the member-facing [My Membership](#my-membership-page) page uses:

- **Active** — paid through this year or later. There is no stored expiration date: a member is paid through the highest `Dues Paid {year}` tag they carry (Settings → year-tag pattern), and a membership ends 12/31 of that year. A contact with the evergreen `dues-paid` tag and **no** year tag (paid before invoicing existed) counts as active through the end of this year — the Dashboard shows them separately as "on the older tag, no date".
- **Expired** — paid through an earlier year, or carrying only the `unpaid-dues` tag. The date wins over a lingering `dues-paid` tag.
- **Exempt** (Past Presidents, Senior Trustees) and **Inactive** contacts owe no dues, so they are never "expired". The Trustees tiles partition the whole trustee family: Exempt / Paid / Unpaid / other.
- It ignores the FluentCRM **contact status** (subscribed, transactional, pending, unsubscribed…): colleagues added by an online join are `transactional` and are paid members. A FluentCRM contact with no dues tag and on no firm roster (a newsletter subscriber) is not a member and is never counted.
- **Firms** come from the FluentCRM Company roster. Companies with no contacts are reported separately, never as "no active member".

The configured **paid / unpaid / inactive** tags and year-tag pattern in Settings are what is read — not fixed slugs. The provider reads FluentCRM with a constant number of queries (about 18, however many contacts) and caches the result for ten minutes.

---

## Dues Invoicing

An annual, admin-driven batch process (**My NJILGA → Invoicing**), billed through **Stripe**. Staff generate one preview across every FluentCRM Company for a dues year, then work a single firm-focused **Law Firms** table — reviewing each firm's dues in an inline preview and creating invoices one at a time or in bulk. Creating an invoice approves its frozen roster and creates the Stripe invoice in one click (background batches). Staff send it, the firm pays by card or ACH on Stripe's hosted invoice page, and payment settles the whole invoice — tags and WordPress roles for everyone on it — at once. There are **no subscriptions**; every dues cycle is its own one-time invoice.

**FluentCRM tags are the source of truth for who owes what. WordPress roles are a downstream effect of payment, never an input to pricing.**

**Flow:** Generate Preview → Create Invoices (per firm or in bulk; approves the frozen roster and creates the Stripe invoice in one step, Action Scheduler ~25 per job, per-row failure isolation) → Send (email + CC policy + Company Note) → Paid (automatic, driven by Stripe's `invoice.paid` webhook, with a daily reconciler as a safety net) → end-of-year Downgrade Sweep (manual, behind a confirmation screen). An invoice can also be cancelled outright with **Void**, a row action on the Invoicing table. A payment that arrives outside Stripe — a check in the post — is closed out in the **Stripe Dashboard** with its own "Mark as paid", which fires the same `invoice.paid` webhook and settles membership exactly as an online payment does. Firms that can't be billed yet — no Owner, no members, nothing billable — are surfaced under **Needs Attention** rather than blocking the main list.

### Settings → Dues & Billing (spec §3)

Everything the engine needs lives in **My NJILGA → Settings**, stored as one option (`njilga_dues_settings`) and seeded with these defaults:

| Category (in precedence order) | Tag | Price | Tier-eligible | Role |
|---|---|---|---|---|
| Past President Membership (Exempt) | `past-president` | $0 | no | `professional` |
| Senior Trustee Membership (Exempt) | `senior-trustee` | $0 | no | `professional` |
| Law Student Membership | `law-student` | $30 (flat; NJILGA may make students free later) | no | `professional` |
| Emerging Professional Membership | `emerging-professional` | $50 (flat) | no | `professional` |
| Professional Membership | `professional` | tiers: 1st Member $125 · Members 2–5 $75 · Members 6+ $0 | **yes** | `professional` |

**Assessment:** Trustee Dinner Assessment, $200, one product; qualifying tags in order `officer`, `trustees`, `senior-trustee`, `past-president`.

Also: default category for untagged contacts (seeded: Professional; can be "not billed"), the inactive-override tag, evergreen paid/unpaid tags and year-tag patterns, invoice email CC policy (bill-to only / + every member / + a fixed list), Reply-To, whether the downgrade sweep removes roles, the **mid-year join policy**, enrollment tags, and the batch size. Each category row still has a product/variation picker and a **WordPress role**, but the gateway is Stripe now — Stripe has no product catalog for this integration, so the picker has nothing to list and every line is built inline at the Settings price instead (see [Dues Invoicing](#dues-invoicing)); the picker and its live ✓/✗ check stay in place for a future catalog-backed gateway. **Per-firm billing mode** overrides live at the bottom.

Prices in Settings are what invoices actually charge — that never depends on a product mapping existing.

### Pricing engine (spec §6)

`includes/invoicing/class-pricing-engine.php` is a **pure function**: roster in, priced roster out, no I/O. Rules, in order:

1. **Inactive override** — a contact carrying the inactive tag is billed nothing (no dues, no assessment), still listed.
2. **Category** — first configured category whose tag the contact carries; else the default category; else "no category" (listed as an exception, not billed).
3. **Ranking partition** — tier-eligible active members are ranked 1..n alphabetically (last name, first name, contact id) and priced by rank. Everyone else — exempt/comped categories, inactive, uncategorised — ranks **after** them and never occupies a paid slot. An exempt Past President whose surname sorts first does not take the $125 slot and does not push a 5th paying member into the free bracket.
4. Non-tier categories charge their flat price (normally $0).
5. **Assessment** — an active contact with any qualifying tag owes it once, on top of dues (an exempt Senior Trustee still owes the dinner).

Seventeen unit tests cover this, including the ranking-partition cases. Run them with any PHP CLI — no WordPress, no PHPUnit:

```bash
php tests/run.php
```

CI runs them on PHP 7.4 and 8.3 on every push (`.github/workflows/tests.yml`).

### Billing modes (spec §3.4)

| Mode | Rows generated per firm |
|---|---|
| **firm** (default) | One `combined` invoice to the Owner covering everyone. |
| **individual** | One `combined` invoice per billed member, addressed to that member. Members at $0 ride on the Owner's own invoice (or the rank-1 member's) so someone's payment still covers them. |
| **split_assessment** | One `dues` invoice to the Owner (assessments zeroed) + one `assessment` invoice per assessed member, addressed to that member. Paying an assessment invoice tags "Assessment Paid {year}" only — it never marks dues paid, and an unpaid assessment never lapses a membership. |

A member with no email can't be billed individually; their invoice is addressed to the Owner and the card says so.

### Exceptions (never silently skipped)

The preview flags, separately from normal rows: firms with **no members**, firms with **no Owner** (roster shown so you can see what would be billed), firms where **nothing is billable**, and members with **no category tag** (badge on the card). Rows that hit an error on create/send show under **Needs attention** with the error text and stay selectable for a retry.

### The invoice names everyone it covers

Paying an invoice settles *every* member in its frozen snapshot, so both the Stripe invoice and the email list all of them, $0 lines included, with the reason:

```
Ann Brown — 2027 Professional Membership (1st Member)              $125.00
Ed Fox — 2027 Professional Membership (Members 2–5)                 $75.00
Ed Fox — Trustee Dinner Assessment (Officer)                       $200.00
Sam Lee — 2027 Professional Membership (no charge, Members 6+)       $0.00
Pat Roe — 2027 Past President Membership (Exempt)                    $0.00
Chris Poe — 2027 Membership Dues (no charge, inactive)               $0.00
```

Each line carries `line_meta` with `contact_id`, `dues_year`, `kind`, `category`, `tier`, `rank`. Stripe has no product catalog for this gateway — every line is built inline at the Settings price via the `create → add_lines → finalize` sequence (see [Stripe billing](#stripe-billing) below), not pulled from a mapped product/variation. A firm where every member is $0 is refused ("nothing to invoice") — Stripe doesn't auto-settle a $0 invoice, but the plugin still won't issue paperwork for a firm that owes nothing this cycle.

### Frozen snapshot (spec §5)

Generating freezes each invoice's roster and pricing into `{$wpdb->prefix}njilga_dues_invoices` (`includes/invoicing/class-dues-invoice-table.php`; snapshot shape documented in `class-dues-snapshot.php`). Every later step — Stripe invoice creation, the payment webhook, the downgrade sweep, the Company Note — reads that snapshot, never a fresh Company query. Re-running "Generate Preview" only touches rows still `draft`/`excluded`; stale drafts a billing-mode change left behind are removed; anything approved or later is untouched. Version-1 snapshots (pre-2.9) are upgraded on read.

### Stripe billing

Each invoice row is built through Stripe's own three-step sequence — `create` a draft invoice on the firm's Stripe Customer, `add_lines` (chunked, since Stripe caps how many lines one call accepts) to attach every roster member's line, then `finalize` to turn it into something the firm can actually pay. There is **one Stripe Customer per firm**, not per bill-to contact — `MyNJILGA_Stripe_Customer_Map` (`njilga_stripe_customers` table) keeps the (company, mode) → Stripe Customer id mapping, backstopped by a metadata search so a re-provisioned site or a race between two requests can't create a duplicate Customer for the same firm. Finalized invoices accept **card and ACH (US bank account)**, both offered on Stripe's own hosted invoice page — the link the invoice email points to.

`includes/invoicing/class-stripe-invoice-gateway.php` is the only file that constructs a raw Stripe API call for order creation; everything else in the plugin talks in the plain arrays `interface-invoice-gateway.php` defines.

### On payment

Settlement — granting tags and WordPress roles — is driven **only** by Stripe's `invoice.paid` webhook (`includes/invoicing/class-stripe-webhook.php`, its own REST route registered at `njilga/v1/stripe-webhook`, signature-verified against the mode's webhook secret). Every member of a paid dues invoice gets the year tag (`Dues Paid 2027`), the evergreen `dues-paid` tag (losing `unpaid-dues`), and their **category's WordPress role**. `MyNJILGA_Role_Sync` decides it from the contact's **current CRM tags** at the moment of payment (the category whose tag they hold, in Settings order, else the default category) — not from the roster frozen into the invoice — and swaps it in: any other membership role they held is removed (never a WordPress core role). A role holding administrator-level capabilities is never granted (reported as `role_privileged`); the `professional` role is created on demand, with the `read` capability only, if the site lacks it — any other undefined role is reported, not created. Each member's role step is isolated, so one failure can't stop the rest of the roster or leave the invoice unmarked, and a contact with no website account is skipped cleanly, never an error. The outcome is counted in the Company Note ("WordPress role: 3 granted, 1 already had it, 2 have no website account…"), and an undefined or privileged role also raises a Dashboard callout until it is fixed. Idempotent on duplicate webhook deliveries: a re-delivered event is acknowledged quietly, while a database failure while recording one is answered with a 503 so Stripe retries it (an acknowledged delivery is never resent).

A **daily reconciler** (`class-stripe-reconciler.php`) is the webhook's safety net, not a second source of truth — it never calls Stripe directly, only through the same gateway seam every other class uses. It re-fetches every `created`/`sent`/`processing` invoice in the active mode and brings the local row's status/amounts up to date with whatever Stripe actually shows, firing the same "paid" event the webhook does if a delivery was missed, delayed, or arrived before this migration's webhook auto-provisioning was in place. Staff can also trigger it on demand from the Invoicing page's **Sync with Stripe** button or a single row's **Refresh** action.

An ACH (`us_bank_account`) payment doesn't clear instantly — while it's in flight, Stripe fires `payment_intent.processing` and the invoice row moves to a `processing` status (**Payment in progress (ACH)**), distinct from unpaid, so it isn't mistaken for a firm that hasn't paid.

**Stripe is the only place a payment is ever recorded.** A check that arrives in the post is closed out with "Mark as paid" in the Stripe Dashboard; Stripe fires `invoice.paid` with `paid_out_of_band` set, and the plugin records it like any other payment — a `njilga_dues_payments` ledger row referenced as "Marked paid in Stripe", dated from Stripe's own `paid_at`, counted toward the invoice's `paid_off_stripe_cents` (money a Stripe payout will never contain), and settled through the one webhook path allowed to grant tags and roles. The method records as `other`: Stripe captures nothing about how an out-of-band invoice was actually paid, and inventing "check" would be a guess.

**Void**, a row action on the Invoicing table for any `created`/`sent`/`processing` invoice, cancels an invoice outright — terminal, the firm needs a new one if they still owe dues. It leaves a Company Note immediately.

Note the one thing this costs: **Stripe's "Mark as paid" settles the whole invoice**, so a *partial* payment has nowhere to be recorded. Leave the invoice open until the balance arrives in full.

### Downgrade sweep

Manual, from the Invoicing page, via a **confirmation screen** showing the exact invoices, firms, and members it will touch (and how many are protected by a paid invoice elsewhere). Applies `Unpaid Dues {year}` + `unpaid-dues`, removes `dues-paid`, removes every WordPress membership role if the setting says so, marks rows downgraded, leaves a Company Note.

### WordPress role sync

A paid member's WordPress role follows their category (Settings → Membership categories), through `MyNJILGA_Role_Sync`. It's granted on payment and on login/registration, and **swapped** — the new category's role added, every other membership role removed — when a paid member's category tag changes in FluentCRM (instantly, via FluentCRM's tag hooks) or when staff change the mapping in Settings (a background resync of everyone carrying the paid tag). Setup → **WordPress role sync** shows the mapping, the managed roles, the last run, and a **Review role changes** screen that lists what a full sync would change before applying it.

Only *managed* roles are ever removed — every role the category map uses or has used, plus the legacy `professional` — never WordPress's own `administrator`, `editor`, `author`, `contributor` or `subscriber`. Contacts not paid for the current or next dues year are left alone (non-payment is the downgrade sweep's job), and a category mapped to a role the site doesn't define leaves its members untouched.

**Upgrading from 3.5.x:** a category set to *— no membership role —* now means exactly that — its paid members lose every managed role at their next login, payment or category change (3.5.x quietly gave them the default category's role at login instead). If a category was set that way to mean "no *extra* role", map it to the role it should carry. A role that was mapped once stays managed after it's taken out of the map, so it can still be removed from members who hold it; Setup → WordPress role sync lists those with a **Stop managing** button for each.

### Company Notes (spec §8)

Created, sent, paid, downgraded, application approved/rejected — each leaves a note on the FluentCRM Company's "Notes & Activities".

### InvoiceGateway (spec §9)

`includes/invoicing/interface-invoice-gateway.php` is the only seam to the commerce system; `class-stripe-invoice-gateway.php` is the only implementation, and — together with `class-stripe-client.php` — the only pair of files allowed to construct a raw Stripe API call. Every invoice/customer id the interface passes around is a **string** (a Stripe object id such as `in_…`/`cus_…`), never assumed numeric. Swap the implementation with the `my_njilga_invoice_gateway` filter.

**Stripe prerequisites:** a connected account (Settings → Payments — see [Connecting Stripe](#connecting-stripe) below) that can actually accept charges, and a key with at minimum the permissions the connect form lists (Customers/Invoices write, Webhook Endpoints write for auto-provisioning, Charges/PaymentIntents/Credit notes read, and **Checkout Sessions write** for online joining). The Invoicing page and Setup page both surface Stripe's own connection-health errors up front rather than letting a create attempt fail opaquely.

### The Payments ledger

**My NJILGA → Payments** is a read-only, cross-year view of every invoice that has actually reached Stripe (`created` and later — `draft`/`approved`/`excluded` rows are Invoicing's business, not the ledger's), scoped to whichever Stripe mode is currently active. Same data-table conventions as Invoicing (search, filters, pagination, stat cards — see design.md), but its tabs are **four different views of the same row set**, not status buckets:

| View | Shows |
|---|---|
| **By Invoice** | Every invoice row, newest first, with an expandable per-member breakdown. |
| **By Firm** | Rolled up per firm, across every dues year, with total outstanding. |
| **By Member** | Rolled up per member, with the firm and every dues year they appear on. |
| **Aging** | Outstanding balances bucketed by days past due (Not Yet Due / 0–30 / 31–60 / 61–90 / 90+), one boxed table per bucket. |

Toolbar filters (dues year, status, payment method) narrow the underlying row set that all four views draw from; switching tabs just changes which view is on screen. Exports both a CSV (By Invoice / By Firm / Aging) and a formatted `.xls` (By Firm / Aging) of exactly what's on screen.

---

## Connecting Stripe

Stripe is the commerce backend for dues invoicing — invoices are created, finalized, hosted and collected there, and this plugin never handles a card number. Every install needs this done once before the Invoicing page can create anything:

1. **Encrypt secrets at rest (recommended).** Generate a key once at a terminal:
   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```
   and paste the resulting 64-character hex string into `wp-config.php`:
   ```php
   define( 'NJILGA_STRIPE_KEY', '<paste the hex string here>' );
   ```
   Without it the plugin still works — the Stripe secret key and webhook secret are just stored in plaintext in the options table. The Payments tab has a collapsed "Optional: encrypt the stored Stripe keys at rest" footnote with the same steps while the constant is undefined. The constant is only an encryption passphrase; the Stripe keys themselves always come from the Payments tab.
2. **Connect an account.** **My NJILGA → Settings → Payments** has a card for **Test mode** and one for **Live mode**, each independent — paste a Stripe secret or restricted key (`rk_…` preferred; `sk_…` accepted, with a nudge to switch) from [dashboard.stripe.com/apikeys](https://dashboard.stripe.com/apikeys). On success the plugin **auto-provisions this site's webhook endpoint** (finds an existing one pointed at this site's `njilga/v1/stripe-webhook` REST route, or creates one) and stores its signing secret; if the key lacks `Webhook Endpoints: Write`, the connect still succeeds and the page falls back to a manual "paste the signing secret" field for an endpoint added by hand in the Stripe Dashboard. A hand-made endpoint doesn't block invoicing; if Stripe reports it missing events this plugin relies on, the Invoicing and Setup pages say which to add.
3. **Enable ACH if you want it.** Invoices already request both `card` and `us_bank_account` as accepted payment methods — whether a firm actually sees the bank-transfer option on Stripe's hosted invoice page depends on that payment method being enabled for the connected Stripe account (Settings → Payment methods, or Financial Connections, in the Stripe Dashboard itself — not a setting this plugin controls).
4. **Pick the active mode.** Test and Live are independent connections; only one is active at a time (My NJILGA → Settings → Payments), and switching never moves existing invoice rows or Stripe objects between modes. Run at least one real invoice through Test before flipping to Live.

---

## Online joining

`[njilga_join category="…"]` goes on each Membership page — `professional`, `law_student`, `emerging_professional` (any Settings category an applicant may pick; its tag, e.g. `law-student`, works too). The applicant walks through a short wizard and pays on **Stripe Checkout** (card, or US bank account); **the payment is the membership** — no staff review — and the plugin applies it the moment Stripe confirms the money. **FluentCart is not involved anywhere**: Stripe and Stripe's webhooks are the whole payment path, for joins and for the annual invoices alike.

| Page | The form asks |
|---|---|
| **Professional** | *Which firm do you represent?* — type-ahead over FluentCRM Companies, or **Add your firm** → **Personal Details** (Prefix, first and last name, phone, email + its code, username, set/confirm password) · **Contact Details** (address 1–2, city, State — New Jersey only — and an NJ ZIP) · **Professional Details** (NJ Attorney ID, date of admission to the NJ Bar, NJ County, Municipality) → **Bring your firm along** (the upsell) → review & pay |
| **Emerging Professional** | the same, without the upsell (a flat-priced category) |
| **Law Student** | *Are you currently enrolled in law school?* (enrolled / undergraduate aspiring) → **Personal Details** (as above) · **Contact Details** (any US state, or an address abroad) · **Student Details** (school, and — if enrolled — the **student ID or transcript upload**) → review & pay |

**Account fields.** Prefix offers FluentCRM's own list (Mr, Mrs, Ms, plus anything the site adds with FluentCRM's `fluent_crm/contact_name_prefixes` filter) and is written to the contact's Prefix. The username defaults to first initial + last name, letters and digits only (Ann O'Neil → `aoneil`), until the person types their own; left blank without JavaScript, the server gives it the same default. The username and email are checked against existing accounts as they're entered: a taken username is swapped for (or offered) the next free one — `aoneil2` — and an email that already has an account is refused with a log-in link, again on submit. One email field (the emailed code proves it) and one phone.

**New Jersey addresses.** Professional and Emerging Professional joins — and the colleagues they pay for, on the invite form — take New Jersey addresses only: State is New Jersey and the ZIP must start 07 or 08. Law students may live anywhere.

**The upsell.** On a tier-eligible category (Professional) the payer can add colleagues — first name, last name, email each. They're priced on the category's own ladder with the **payer as the 1st member**: 1st $125, members 2–5 $75, beyond 5 free (Settings → categories drive the numbers and the copy). The payment covers everyone: each colleague gets the membership, the category tag and the firm, and an **invitation email** with a single-use link to create their own website account (their role is granted then, or on first login if they get an account another way). A join is priced on its own — the payer is the 1st member even at a firm that already has paid members this year — which is the existing mid-year rule.

**Before anything is created.** The email is confirmed with a 6-digit code before a WordPress account exists for it. Nothing touches FluentCRM until payment is confirmed: an abandoned checkout leaves only the join record (Online joins lists it) and an account with no membership.

**On payment** (`MyNJILGA_Join_Fulfillment`, triggered by `checkout.session.completed` / `async_payment_succeeded`, by `invoice.paid` on the join's own invoice, by the return from Checkout, by the daily sweep, and by staff — whichever arrives first; idempotent and concurrency-safe):

1. The firm: the chosen Company; else an exact or normalised-name match ("Smith & Jones, LLP" = "Smith and Jones LLP"); else a new Company with the payer as Owner. A joiner is **never made Owner of a firm that already exists**.
2. Contacts for the payer and every colleague, found by email or created. New colleague contacts are created **transactional/pending**, never subscribed — someone else typed their address. An existing unsubscribed contact is never resubscribed.
3. **Who goes on the firm now.** For an existing firm, a joiner is attached automatically when their email domain is already at the firm (free-mail never counts — a long built-in list, including country and subdomain variants, adjustable with the `my_njilga_free_mail_domains` filter) — Settings can switch this to "always, review afterwards". Anyone else is **held**: still a paid member, but not attached until staff click **Add to firm** (or **Leave off firm**) on Online joins. Someone held only over the firm (domain, another firm, payer not yet confirmed) gets the category tag straight away; someone whose own record is the question (another category, inactive, unsubscribed) isn't re-categorised until staff confirm. A held **payer**'s invoice row is filed as an individual membership — out of the firm's sight on its status page — and moves onto the firm when staff confirm.
4. An `njilga_dues_invoices` row of kind **`join`**, written straight in as **paid**, whose snapshot names everyone — so the Payments ledger, the firm status page, the downgrade sweep (which protects them) and the next Generate Preview (which prices them at $0 for that year, "paid via online join") all see it — then the ledger row and `settle()`: `Dues Paid {year}`, `dues-paid`, roles, Company Note.
5. Invitations, a welcome email to the payer, and a staff email (Settings → Online joining → notify).

**Late in the year.** Once next year's invoices exist (or from the date set in Settings), a join pays **next** year's dues and covers the rest of this one — so a late joiner is never missing from next year's already-frozen invoices.

**Never paid for twice.** The form refuses anyone already current for the year, anyone on a firm invoice for it (draft, approved or sent), and anyone on another join whose money is already committed (a clearing ACH debit) — for a colleague it says only that they can't be added online, never which. Fulfillment re-checks and flags any overlap (never advising a refund where the firm invoice priced that person at $0 because of this join). Generate Preview prices someone at $0 "paid via online join" only once the join's money has **settled** — a bank debit still clearing, or a payment held for staff review, covers nobody: those people are billed normally and the invoice card says so, and Refresh Firms re-prices them at $0 if it settles. **Create** re-checks each row against settled join payments at that moment and sends it back to draft (nothing sent to Stripe) when someone billed on it has since paid online, or someone listed at $0 for a join is no longer covered. If a join's bank payment fails, any open firm row that listed its people at $0 is flagged. Only a firm invoice's **members** count as covered by it — not its Owner or bill-to contact. On the Invoicing page, join rows sit under their own **Online joins** tab and don't count toward the batch's firm totals.

**Mode.** Joins pay in the mode active under **Settings → Payments**, the same one invoices bill in — so a staging copy switched to **Test** takes every join in Test (test card, no real money, and every email a test join sends goes to the person joining). The Payments tab warns while Test is on, because on the live site Test would hand out real memberships for test-card payments. While the site is **Live**, staff rehearse one join with `?njilga_test=1` on a Membership page. A join stays in the mode it was started in, whichever way the toggle moves afterwards.

**$0 joins** (a category priced at $0) have no payment to prove intent, so the form ends in **Submit for approval** rather than Stripe, and they wait on **Online joins** for Approve/Reject.

**Abuse limits.** The code, submit, new-account and invite-acceptance steps are rate-limited per client address and per verified email. Colleague checks are counted per colleague row **before** anything is looked up — per payer mailbox and account, max(30, 3 × the colleague limit) a day; per client address, max(60, 6 × it) — and past that, colleagues can't be added online that day (the payer can still join alone), so the answer never depends on who is covered. Sending a code answers the same whether or not an account exists: an existing account gets a "log in instead" email and a decoy code, so a wrong guess reads the same either way; guesses are counted atomically. Every logged-out POST whose Origin or Referer names another site is refused; a first view's form must positively come from this site, and later views are bound to a per-visitor cookie (`__Host-njilga_join_visitor` over HTTPS). A visitor whose browser sends no cookie is told to enable cookies. A signed-in payer whose email's FluentCRM record is linked to a different website account is asked to contact NJILGA rather than pay. Behind a proxy or load balancer that doesn't restore the client address, return the real one from the `my_njilga_client_ip` filter, or every visitor shares one limit.

**Invitations** open with "You're creating the account for *email*" and a **This isn't me** button that clears the invitation, so a forwarded or planted link can't pass itself off as the visitor's own join. A link that is used, superseded, expired or unknown clears itself, with a one-time note above the normal join page, and a signed-in visitor never sees an invitation.

**Phone numbers** are stored the way FluentCRM records here keep them: a US number typed any way — "(201) 555 0100", "2015550100", "+1.201.555.0100" — is shown as `201-555-0100` and written to FluentCRM as `+1 201-555-0100` (the contact's phone and the mailing-phone custom field alike). An impossible US number is refused with an example; a number with another country code (starting with `+`) is kept, tidied, as typed.

**NJ County and Municipality** are selects whose choices are the options of the FluentCRM custom fields they're written to (Settings → Online joining: `nj_county` and `municipality` by default), so the form offers exactly what staff filter on. NJ County is left out when its field has no options; Municipality then falls back to the one-per-line list in Settings, else free text. Settings shows how many options each field gives the form.

**Mailing address.** Without JavaScript every address field shows, labelled for US or overseas addresses, and the server checks them by the "outside the United States" box; with JavaScript only the fields that apply show and are sent. The postcode field is shared: a 5-digit ZIP for US addresses, optional free text for others, saved either way.

**Look.** The public forms — the join wizard, the invite form, `[njilga_firm_dues_status]`, `[njilga_my_membership]` and `[njilga_membership_application]` — follow the NJILGA site's stylesheet: **Playfair Display** headings, **Helvetica** body text, and **Inter** for eyebrows ("Step 2 of 4", "NJILGA Membership"), labels and buttons; colours come from the site's Automatic.css tokens (`--secondary` navy buttons, `--primary` blue, `--accent` gold, `--btn-radius`), each with the site's own value as the fallback on a site without Automatic.css. Sizes are in px because Automatic.css sets the root font size to 62.5%. Playfair Display comes from the theme; Inter isn't on the site, so the plugin loads it from Google Fonts on pages that show a form — return `''` from the `my_njilga_front_font_url` filter to leave font loading to the theme.

**Shortcodes.** My NJILGA → **Shortcodes** lists a ready-to-paste `[njilga_join category="…"]` line for every category an applicant may pick (with its price), the `category`/`form` attributes, whether joining is open right now, and every page that carries each shortcode — with a warning when a category has no page.

**Student documents** are stored outside the Media Library in `uploads/njilga-private/` (deny-all `.htaccess`, random 128-bit names; on nginx deny the path, or define `NJILGA_PRIVATE_DIR` outside the web root), shown to staff only, flagged "not yet checked" until staff mark them, and purged 30 days after a join that never became a membership. The size limit shown and enforced is 8 MB or the server's own upload limit, whichever is lower.

**Online joins** (Applications → Online joins) lists every attempt with its status — Awaiting payment, Bank payment clearing, Member, Needs a decision, Checkout expired… — and the actions each needs: Approve/Reject, Check payment / Retry, Add to firm / Leave off firm, Resend invites, Mark reviewed, the receipt and the student document. **Needs attention** (and the menu bubble) collects what a person must act on.

**Webhook events.** Joins need `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired` (and `charge.dispute.created` is now subscribed for every payment). An endpoint provisioned by an earlier version gets them added automatically on the next admin page load; one added by hand can't be read back, so Setup → Online joining lists the events to add to it. Without the webhook, the daily sweep still settles a join — including noticing a bank debit that failed.

**Checkout availability** (whether the key may create Checkout Sessions) is checked from admin page loads and on connect, never on a public page view; Setup → Online joining shows the answer per mode with a **Re-check** button.

---

## Enrollment gate

`[njilga_membership_application]` renders the public application form — first/last name, email, phone, **firm with search-as-you-type against existing FluentCRM Companies and an "Add “…” as a new firm" fallback**, category (those flagged *applicant may pick* in Settings), message. Submitting creates/updates the FluentCRM contact, tags it **pending-approval**, records the application, and emails staff. The applicant is **not** attached to any Company and gets no role or paid tag, so they never enter the billing pool until approved.

**My NJILGA → Applications** is the review queue (with a pending-count bubble in the menu). **Approve** attaches the contact to the firm (creating it if new, making the applicant Owner if the firm has none), swaps the pending tag for the category tag, then branches on the **mid-year join policy** setting:

| Policy | Effect |
|---|---|
| **Invoice now** (default — confirmed by NJILGA) | A draft individual invoice for the current year appears in Invoicing for staff to approve/create/send. |
| **Free until next cycle** | Marked current for the current year (evergreen paid tag + `Dues Paid {year}` + role); first invoice is next year's batch. |
| **Manual** | Category tag only. |

**Reject** swaps the pending tag for `application-rejected`. Both email the applicant and leave a Company Note.

---

## Firm dues status page

`[njilga_firm_dues_status]` — the logged-in member → FluentCRM contact → their Company(ies) → every invoice row, newest year first: bill-to, total, status, the **full roster** with amounts, and the **Stripe hosted-invoice payment link** (plus a secondary PDF download) while an invoice is awaiting payment or processing an ACH transfer. A paid row names the payment method ("Paid by card on…") and links the PDF too. Every member of the firm sees it, not just the Owner. Also shows the viewer's own paid/unpaid status.

---

## My Membership page

`[njilga_my_membership]` — the complete picture of a member **and** their firm, for the page you'd call "My Membership". Signed-in members only; a visitor is asked to log in. For the viewer it shows:

1. **Active or expired** — a pill, plus the **next expiration date** (`12/31/YYYY`).
2. **Their firm(s)** and whether the firm **manages their membership** — *Managed by your firm* (one invoice to the Owner covers everyone), *You manage this firm's membership* (the viewer is the Owner), or *You manage your own membership* (the firm is in Individual billing mode). Split-assessment firms manage dues but bill each assessment to the member.
3. **Every member of the firm**: category, active/expired/exempt/inactive standing and expiration.
4. **Fees by member** — every fee ever invoiced to each person (membership dues with their tier, Trustee Dinner assessments, $0 lines with the reason), per year, with each invoice's status.
5. **Invoices** — year, what it covers, who it was **billed to**, total, status, and the **Pay now** / PDF links while it's awaiting payment, so a payment traces to the people it covers.

**There is no stored expiration date, so it is derived:** a member is paid through the highest `Dues Paid {year}` tag they carry (Settings → year tag pattern), and memberships end 12/31 of that year. Paid through this year or later = **active**; paid through an earlier year, or carrying only the `unpaid-dues` tag = **expired** (the date wins over a lingering `dues-paid` tag). A contact with the evergreen `dues-paid` tag and *no* year tag (paid before invoicing existed) reads as active through the end of this year. Dues-exempt (Past President / Senior Trustee) and Inactive contacts owe nothing, so they're never shown as expired.

Like the firm dues status page, it shows every member of the firm the same thing (not just the Owner), always uses **Live**-mode invoices (whatever the admin Test/Live toggle says), and never shows staff-only **draft** invoices. Unlike it, the payment link is offered only while an invoice is *awaiting payment* — never while an ACH transfer is clearing. Invoices that list the viewer but sit outside a firm they belong to now (an old online join, a former firm) appear under **Other invoices**, restricted to the viewer's own lines unless the invoice was billed to them. The page sets `DONOTCACHEPAGE` — it lists other people's names and fees, so keep it out of any page or CDN cache.

---

## Dues History

A **Dues History** tab on every FluentCRM **contact** record and every FluentCRM **company** record — the invoices and payments for that person or firm in one place, with every invoice one click from being called up.

It uses FluentCRM's own extension API (`FluentCrmApi('extender')->addProfileSection()` / `->addCompanyProfileSection()`), so nothing in FluentCRM is patched or injected. The contact tab needs FluentCRM 2.2+, the company tab FluentCRM 2.8+ with the Companies module; on an older release the contact tab still works. The tab is for administrators (`manage_options`), like every My NJILGA screen.

**Where the data comes from.** Two sources, merged for display only:

| Source | What it is | Freshness |
|---|---|---|
| **Stripe** | The live invoices and the payment ledger (`njilga_dues_invoices`, `njilga_dues_payments`) — everything created through [Dues Invoicing](#dues-invoicing) | Read straight from the tables on every view, so an invoice that is created, sent, paid, voided or refunded shows its new state the next time the tab opens. Nothing is copied, so there is nothing to fall out of date. |
| **PMPro / Imported** | Invoices recreated from earlier years by the [Tools](#tools) (`njilga_dues_history`) | Static reference records. |

Draft and approved invoices aren't shown — they haven't been issued, and live in Invoicing. Like the Payments ledger, the Stripe half follows the active **Test/Live** mode; recreated history isn't mode-bound.

**On a contact** — a switch between **This contact** (every invoice that names them: billed to them, or listing them as a member of its roster) and **each firm they belong to** (every invoice for the firm, whoever it was billed to). On a company — the firm's whole history. Each view shows:

- **Figures** — open balance, paid to date (net of refunds), invoice count and years covered, last payment. On a contact's own tab these are for *the invoices that name them* (a whole firm invoice counts in full — the *This contact* column shows their share) and are labelled so; on a firm view they are the firm's.
- **Open invoices** — oldest first, with due date and balance. *Open* means money is still owed: awaiting payment, **ACH clearing**, or **Lapsed** (a downgraded invoice that was never paid — the Payments ledger counts it as outstanding too). An open invoice past its due date reads **Overdue**; a live one has a **Pay online** button to its Stripe payment page. On a contact's tab, a *This contact* column shows their own share of a firm invoice.
- **Payment history** — every payment and refund, newest first, with method (`Visa ••4242`, `Check #4417`, `ACH — Chase`), reference and the invoice it was for.
- **All invoices** — newest dues year first, with source (Stripe / PMPro / Imported), status and a **View** button.

**Calling up an invoice.** *View* (or the invoice number) opens a read-only invoice page in a new tab (`My NJILGA → … → Invoice`, a hidden admin page): the invoice document — status, dates, who it was billed to and the firm (each linked back to its FluentCRM record), every line item, totals, and its payments — with **Print**, and for a live invoice the Stripe **PDF** and **Payment page**. It prints without the admin chrome. The same page serves a recreated invoice, which has no Stripe page of its own.

The panel is rendered by FluentCRM with `v-html`, so it uses no JavaScript (the contact/firm switch is CSS-only) and escapes everything it prints. See [design.md](design.md#embedded-panels).

Recreated history lives in its **own table**, deliberately: nothing the Tools create can move a dashboard figure, appear in Invoicing or the Payments ledger, be touched by the Stripe reconciler or the downgrade sweep, or change anyone's tags or WordPress role.

---

## Tools

**My NJILGA → Tools** holds one-off data jobs for bringing earlier years' dues into the site. Both tools create *reference history* — see [Dues History](#dues-history) — and both follow the same pattern: **choose → preview → confirm**. A preview writes nothing; the confirm step is a nonce-protected POST; running again is safe (what is already in is skipped); and every run is logged on the Tools page with an **Undo** that removes exactly the invoices that run created.

### PMPro Migrator

Recreates a past year's **paid** dues invoices from Paid Memberships Pro. It reads PMPro's order table (`{prefix}pmpro_membership_orders`, with `pmpro_membership_levels` for names) directly — PMPro doesn't need to be active, but its data does need to be in this site's database. It never writes to PMPro.

1. **Choose** — the window of orders (last calendar year by default) and which **dues year** to record them under: *the year each order was placed*, or a fixed year (for a PMPro cycle that doesn't follow the calendar). A **Skip $0 orders** switch is on by default. The top of the screen shows PMPro's paid orders and totals per year, so the right window is easy to pick.
2. **Preview** — every paid order matched to a contact and firm: how many will be migrated, how many already were, how many can't be matched, and what was skipped (and why — $0, test orders, orders that were never paid). A table shows the invoices that would be created.
3. **Migrate** — creates them.

| PMPro order | Becomes |
|---|---|
| `status = success` | A **paid** invoice. Pending, refunded, cancelled, error and review orders are not migrated, and `sandbox` (test-gateway) orders never are. |
| `code` / `id` | Invoice number = PMPro's own invoice code; the order id is the idempotency key (`source_ref`), so a re-run — or an overlapping window — can't create a second copy. |
| `user_id` → WordPress user → FluentCRM contact | Matched by linked user, then by email. The contact's **firm** (FluentCRM company) is the invoice's firm. An order with no matching contact is **not migrated** — it would appear on no tab — and is listed with the reason (e.g. *Website user #99 no longer exists*) so it can be fixed and the tool run again. A contact with no firm still migrates, onto the contact tab only. |
| `membership_id` | The level's name: *2025 Professional Membership*. |
| `subtotal`, `couponamount`, `tax`, `total` | Line items that always add up to the amount paid: the subtotal, a **Discount** line for a coupon, **Tax**, and an **Adjustment** for any difference PMPro's own figures leave. |
| `gateway`, `payment_type`, `cardtype`, `accountnumber` | Method — card (`Visa ••4242`), check, PayPal or other. |
| `payment_transaction_id` | The payment's reference (the order code if there is none). |
| `timestamp` | Invoice date and paid date. |

One run migrates up to 2,000 orders; it says so and a second run continues.

### Historical Invoice Import

Upload a **CSV or Excel (.xlsx)** sheet of earlier years' invoices. Nothing is stored from the file itself: it is parsed in the upload request, and the parsed invoices are held for an hour — for the uploading user only — while you review them.

**The sheet** is *one row per invoice line*, with column headings in the first row. A firm's invoice for three members is three rows with the same invoice number; a one-member invoice is one row. **Download a blank template** on the tool's page for the exact headings and two example invoices.

| Column | Needed | Notes |
|---|---|---|
| **Amount** | Required | Negative amounts (or `(125.00)`) are credits. `$1,250.00`, `1.250,50` and `125` all read. |
| **Dues Year** *or* **Invoice Date** | Required | The year is taken from the invoice date if there is no Dues Year column. `2025`, `FY2025` and `2025-26` all read as 2025. |
| **Firm** and/or **Member Email** | One of them | Firm → a FluentCRM **Company** by name (`Smith & Jones, LLP` = `Smith and Jones LLP`, the same rule the online join uses); Member Email → a **contact**, which also supplies the firm when the sheet has none. |
| Invoice Number | Recommended | Rows sharing a number, year *and firm* become one invoice — so a placeholder like `N/A`, or a number two firms both used, never merges different firms. It is also what makes a re-upload safe. Without it, each row is its own invoice and is keyed by its content. |
| Status | Optional | *Paid*, *Unpaid / Open / Outstanding*, or *Void*. Free text is read sensibly (*Paid - no balance* and *Overdue - paid* are paid; *Unpaid*, *Not paid* and *Partially paid* are open; *N/A*, *TBD* and *-* say nothing). A row with no status is recorded as paid or open, as you choose on upload. |
| Amount Paid, Date Paid, Payment Method, Reference / Check # | Optional | The payment. A paid invoice with no Amount Paid is paid in full; an Amount Paid below the total is recorded as part-paid (open). |
| Invoice Date, Due Date, Member Name, Description, Notes | Optional | Shown on the invoice. |

Headings are matched loosely (`Invoice #`, `Invoice No`, `Invoice Number`) and extra columns are ignored; the review screen shows exactly how each heading was read. Dates read US-style (`3/4/2025` is March 4th) unless the first number can only be a day. Excel dates are converted using the cell's own format. Only the first worksheet of a workbook is read, up to **5 MB, 10,000 rows and 5,000 invoices** per upload. A sheet over the row limit is refused outright — never partly imported, since an invoice could straddle the cut — so split it, keeping each invoice's rows together. A worksheet may inflate to at most 8 MB of XML. An old `.xls` is declined with instructions for saving as CSV.

**Review** shows what would be imported (new), what already was (skipped), invoices that **can't be imported** (not imported, with the reason — fix the sheet or FluentCRM and upload again), rows that couldn't be read (with their **sheet row numbers**, as a spreadsheet counts them), and notes where something was adjusted. An invoice is *held back* if it can't be matched to a firm or contact, **or if one of its own rows couldn't be read** — importing it would record it short, and the corrected sheet would then be skipped as "already imported". An invoice naming an email that isn't a FluentCRM contact still imports onto its firm. The matching is redone at import time, so what is written is checked against FluentCRM as it is then.

---

## Firm renewal lookup

`[njilga_firm_renewal_lookup]` — for the person who **didn't see the invoice email**. Anyone, signed in or not, can search for a firm by name; every firm with an **open** invoice that matches is listed with a **Pay now** button. Put it on a public page (e.g. *Pay your dues*).

```
[njilga_firm_renewal_lookup]
[njilga_firm_renewal_lookup verify="email" title="Pay your firm's dues"]
```

**Paying settles the whole firm — because it pays the real invoice.** *Pay now* goes to that invoice's own Stripe-hosted payment page, the same link the invoice email carries. Stripe's `invoice.paid` webhook then does what it always does ([On payment](#on-payment)): every member on the invoice's frozen roster gets the year tag, `dues-paid` and their category's WordPress role, and the Payments ledger, Company Note and Dues History all update. The lookup creates nothing and settles nothing itself, so there is no second payment path to keep in step. A firm with several open invoices (individual billing, split assessments, an earlier year) lists each, oldest first. An invoice whose bank (ACH) payment is already clearing shows *a payment is already being processed* instead of a second button.

**What a visitor can and can't learn.** The lookup is public, so it is deliberately small:

| Shown | Never shown |
|---|---|
| Firm name, dues year, amount due, member count, and *billed to Ann B.* (first name and last initial — enough to tell two invoices at one firm apart) | On this page: email addresses, member names, paid invoices, firms with nothing open. **Not true of the Stripe page** the button opens — see below. |

- Only firms with an open, issued (created or sent) **Live** invoice are searchable, so a firm that doesn't exist and one that has already paid get **the same answer** — the lookup can't be used to find out who exists or who owes. Draft, voided and downgraded invoices never appear; a Test-mode invoice never does, whichever way the admin Test/Live toggle is set.
- A search needs a real search word of at least **three letters** (judged on the words searched for, so `a bc` doesn't pass), lists at most **eight** firms, and is **rate-limited per visitor** (10 searches per 10 minutes, 60 per day). The visitor is the connecting address — an IPv6 visitor is their whole `/64`, so rotating addresses inside it buys nothing. With a persistent object cache the counter is an atomic increment; without one it uses transients, which a burst of parallel requests can overshoot by about the burst's size but never leaves open. Behind a proxy or load balancer, return the real client address from the `my_njilga_client_ip` filter (the same one the join form uses), or every visitor shares one limit.
- Searches are **POSTs**, so a page cache never stores a result and a firm name never lands in a URL or access log; a hidden honeypot field quietly ignores bots. The *Pay now* link must be `https://`.
- **The Stripe page the button opens is not minimal.** It is the same page the firm was emailed, and it shows the invoice's **bill-to name and email address** and **every line — member names and amounts**. So without `verify`, anyone who can find a firm by name can read that. If the firm name alone is too little of a gate for that, add **`verify="email"`**: the visitor must also give an email address that is on that firm's invoice (its bill-to, Owner or a listed member) before the firm — and so its Stripe page — is shown, and a wrong address gets the same "couldn't find" answer. The default is open because the page exists for people who *don't* have the email; whether that is the right trade is a decision for NJILGA.

`title="…"` changes the heading. The page looks like the other public forms ([design.md §6](design.md#6-not-covered)). My NJILGA → **Shortcodes** lists it with the pages that carry it.

---

## CSV / Excel exports

Each list page has a **Download CSV** button; **Membership by Firm** and the **Payments** ledger export a formatted `.xls`; **Reports** offers the **Executive Summary** `.xls` combining every report. No third-party libraries. Names and firm names can come from the public join form, so a CSV cell that would start a spreadsheet formula (`=`, `+`, `-`, `@`) is written as text; the `.xls` exports already mark data cells as text.

---

## File structure

```
my-njilga/
├── njilga-membership-report.php          ← Plugin bootstrap + hooks
├── includes/
│   ├── class-admin-menu.php
│   ├── class-tags.php                    ← Tag resolution (core + settings-driven slugs)
│   ├── class-membership-stats.php        ← ONE definition of active/expired/exempt + firm/trustee/category counts (pure half unit-tested), 10-min cache
│   ├── class-members-data.php            ← Report row/list shaping on top of the stats provider
│   ├── class-page-*.php                  ← Dashboard, Reports, Members, Trustees, Companies, Firms
│   ├── class-page-invoicing.php          ← Invoicing dashboard + admin-post handlers
│   ├── class-page-payments.php           ← Payments ledger (by invoice / firm / member / aging)
│   ├── class-page-settings.php           ← Dues & Billing settings UI + Payments (Stripe) tab
│   ├── class-page-applications.php       ← Enrollment review queue
│   ├── class-page-setup.php              ← Environment, tag/product audit, Stripe health + API log
│   ├── class-firm-status-page.php        ← [njilga_firm_dues_status]
│   ├── class-my-membership.php           ← [njilga_my_membership]: standing, firm, members, fees (pure half unit-tested)
│   ├── class-phone.php                   ← PURE: phone numbers in FluentCRM's shape (+1 ###-###-####) — unit-tested
│   ├── class-front-style.php             ← Shared look of the public forms: site fonts + Automatic.css colour tokens
│   ├── class-firm-renewal-lookup.php     ← [njilga_firm_renewal_lookup]: find a firm's open invoice and pay it (matching/selection pure, unit-tested)
│   ├── history/                          ← Dues History tab + the table of recreated invoices
│   │   ├── class-dues-history-table.php  ← njilga_dues_history schema + CRUD (PMPro / imported invoices; batch id per run)
│   │   ├── class-dues-history.php        ← Read model: live Stripe invoices + payments + history → one entry shape (pure, unit-tested)
│   │   ├── class-dues-history-view.php   ← Panel markup for the FluentCRM tabs (no JS: CSS-only contact/firm switch)
│   │   ├── class-dues-history-tabs.php   ← Registers the contact + company tabs via FluentCrmApi('extender')
│   │   └── class-page-dues-invoice.php   ← Hidden admin page: one invoice, live or recreated, printable
│   ├── tools/                            ← My NJILGA → Tools
│   │   ├── class-page-tools.php          ← Landing page, import log, per-run Undo
│   │   ├── class-pmpro-migrator.php      ← PMPro orders → history invoices (mapping pure, unit-tested)
│   │   ├── class-page-tool-pmpro.php     ← Choose → preview → migrate
│   │   ├── class-spreadsheet-reader.php  ← CSV + .xlsx reader, no library (ZipArchive + DOMDocument), size/entity safe
│   │   ├── class-historical-import.php   ← Headings, parsing, grouping, FluentCRM matching → history rows (pure, unit-tested)
│   │   └── class-page-tool-import.php    ← Upload → review → import, template download
│   ├── join/                             ← [njilga_join] — online joining (Stripe Checkout)
│   │   ├── class-join-form.php           ← Controller: POST handling, validation, email codes, account, checkout
│   │   ├── class-join-view.php           ← Markup, scoped front-end CSS, wizard/type-ahead JS
│   │   ├── class-join-pricing.php        ← PURE: what a join costs (payer + colleagues) — unit-tested
│   │   ├── class-join-fulfillment.php    ← Paid → firm, contacts, tags, join invoice row, settle, invites
│   │   ├── class-join-invites.php        ← Colleague invitations: issue, email, accept
│   │   ├── class-join-documents.php      ← Private student ID / transcript storage
│   │   ├── class-join-orders-table.php   ← njilga_join_orders
│   │   └── class-join-invites-table.php  ← njilga_join_invites
│   ├── class-report-*.php                ← CSV / XLS / Executive Summary
│   ├── invoicing/
│   │   ├── class-dues-settings.php       ← Settings storage + seed defaults
│   │   ├── class-pricing-engine.php      ← PURE pricing function (unit-tested)
│   │   ├── class-dues-snapshot.php       ← roster_snapshot shape (v2) + v1 upgrade
│   │   ├── class-dues-invoice-table.php  ← njilga_dues_invoices schema (1.2.0) + CRUD
│   │   ├── class-dues-payments-table.php ← njilga_dues_payments schema + CRUD (payment/refund ledger)
│   │   ├── interface-invoice-gateway.php ← Commerce seam
│   │   ├── interface-checkout-gateway.php ← Checkout seam (online joins) — a separate interface, so swapped gateways don't break
│   │   ├── class-stripe-client.php       ← Raw Stripe HTTP transport (the only other file naming a Stripe endpoint)
│   │   ├── class-stripe-connection.php   ← Credential storage/encryption, connect flow, webhook auto-provisioning
│   │   ├── class-stripe-invoice-gateway.php ← The only file implementing the gateway interface
│   │   ├── class-stripe-webhook.php      ← REST webhook receiver (njilga/v1/stripe-webhook)
│   │   ├── class-stripe-reconciler.php   ← Daily safety-net sync + Invoicing page's "Sync with Stripe"
│   │   ├── class-stripe-events-table.php ← njilga_stripe_events schema + CRUD (webhook dedupe/audit)
│   │   ├── class-stripe-customer-map.php ← njilga_stripe_customers schema + CRUD (firm → Stripe Customer)
│   │   ├── class-invoicing.php           ← Gateway locator + helpers
│   │   ├── class-dues-preview.php        ← Preview builder (engine + billing modes + exceptions)
│   │   ├── class-dues-roster.php         ← Line labels / line items / email summary
│   │   ├── class-invoice-creator.php     ← Action Scheduler batches, per-row isolation
│   │   ├── class-invoice-sender.php      ← Email + CC policy + Company Note
│   │   ├── class-payment-listener.php    ← Paid → tags + role (via role sync)
│   │   ├── class-role-sync.php           ← Paid member's WP role follows their category
│   │   ├── class-invoice-stats.php       ← Dashboard invoice figures: one GROUP BY, mode-scoped, parity-tested against the Payments ledger
│   │   ├── class-downgrade-sweep.php     ← preview() + run()
│   │   └── class-invoicing-notes.php     ← FluentCRM Company Note helper
│   └── enrollment/
│       ├── class-applications-table.php  ← njilga_membership_applications
│       ├── class-application-stats.php   ← Dashboard application + online-join figures
│       ├── class-application-form.php    ← [njilga_membership_application] + AJAX + submit
│       └── class-application-review.php  ← approve() / reject() + join policy
├── tests/                                ← php tests/run.php
├── .github/workflows/
│   ├── release.yml                       ← Auto GitHub Release on version bump
│   └── tests.yml                         ← Lint + unit tests on PHP 7.4 / 8.3
├── composer.json
└── README.md
```

---

## Updates

The plugin checks **`s-fx-com/MyNJILGA`** on GitHub for tagged releases via [yahnis-elsts/plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker). Bump the `Version:` header, push to `main`, and `release.yml` publishes the matching `v<version>` release automatically. For a private repo, define `MY_NJILGA_GITHUB_TOKEN` in `wp-config.php`.
