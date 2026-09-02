# My NJILGA

A WordPress plugin that gives NJILGA admins a one-stop dashboard for member status, trustees, and company rollups — plus the annual **dues invoicing** process (Stripe + FluentCRM), a **membership application gate**, and a **member-facing dues status page**. Everything is driven by FluentCRM tags on the local WordPress install; billing is driven by Stripe. No subscriptions anywhere — every dues cycle is its own one-time invoice.

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
| **Dashboard** | Summary counts (paid members, trustees, companies with paid members), bucket distribution. |
| **Reports** | Landing page for every report below, plus the Executive Summary export. |
| **Active Paid Members** | Every contact carrying the **Dues Paid** tag, with firm, email, trustee flag, payment method. |
| **Trustees** | Every contact carrying a trustee-family tag, plus whether they've paid dues. |
| **Companies** | All FluentCRM Companies, grouped into **1 / 2–5 / 6+ Paid Members** buckets. |
| **Membership by Firm** | Every FluentCRM Company with ≥1 contact, listed with its contacts. Exports to formatted Excel. |
| **Invoicing** | Annual dues invoicing — see [Dues Invoicing](#dues-invoicing) below. |
| **Payments** | Cross-year Stripe payments ledger — see [The Payments ledger](#the-payments-ledger) below. |
| **Applications** | Enrollment review queue — see [Enrollment gate](#enrollment-gate). |
| **Settings** | **Dues & Billing** — category mapping, assessment, per-firm billing mode, all switches. **Payments** tab — Stripe connection, mode, and payment settings. |
| **Setup** | Environment checks, tag checklist, **tag-slug audit** and **product-mapping audit** for the settings, plus Stripe connection health and a recent-API-activity log. |

---

## How status is determined

| Concept | Source |
|---|---|
| Paid / Active member | Contact has the **Dues Paid** tag |
| Trustee | Contact has the **Trustees** tag |
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

---

## Dues Invoicing

An annual, admin-driven batch process (**My NJILGA → Invoicing**), billed through **Stripe**. Staff generate one preview across every FluentCRM Company for a dues year, then work a single firm-focused **Law Firms** table — reviewing each firm's dues in an inline preview and creating invoices one at a time or in bulk. Creating an invoice approves its frozen roster and creates the Stripe invoice in one click (background batches). Staff send it, the firm pays by card or ACH on Stripe's hosted invoice page, and payment settles the whole invoice — tags and WordPress roles for everyone on it — at once. There are **no subscriptions**; every dues cycle is its own one-time invoice.

**FluentCRM tags are the source of truth for who owes what. WordPress roles are a downstream effect of payment, never an input to pricing.**

**Flow:** Generate Preview → Create Invoices (per firm or in bulk; approves the frozen roster and creates the Stripe invoice in one step, Action Scheduler ~25 per job, per-row failure isolation) → Send (email + CC policy + Company Note) → Paid (automatic, driven by Stripe's `invoice.paid` webhook, with a daily reconciler as a safety net) → end-of-year Downgrade Sweep (manual, behind a confirmation screen). An invoice can also be settled by staff directly — **Mark Paid** for a check/cash/wire payment collected outside Stripe, or **Void** to cancel it — from a row action on the Invoicing table. Firms that can't be billed yet — no Owner, no members, nothing billable — are surfaced under **Needs Attention** rather than blocking the main list.

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

Settlement — granting tags and WordPress roles — is driven **only** by Stripe's `invoice.paid` webhook (`includes/invoicing/class-stripe-webhook.php`, its own REST route registered at `njilga/v1/stripe-webhook`, signature-verified against the mode's webhook secret). Every member of a paid dues invoice gets the year tag (`Dues Paid 2027`), the evergreen `dues-paid` tag (losing `unpaid-dues`), and their **category's WordPress role** — best-effort: only where a linked WP user exists and the role is defined; contacts with no account are skipped cleanly, never an error. A Company Note records it. Idempotent on duplicate webhook deliveries.

A **daily reconciler** (`class-stripe-reconciler.php`) is the webhook's safety net, not a second source of truth — it never calls Stripe directly, only through the same gateway seam every other class uses. It re-fetches every `created`/`sent`/`processing` invoice in the active mode and brings the local row's status/amounts up to date with whatever Stripe actually shows, firing the same "paid" event the webhook does if a delivery was missed, delayed, or arrived before this migration's webhook auto-provisioning was in place. Staff can also trigger it on demand from the Invoicing page's **Sync with Stripe** button or a single row's **Refresh** action.

An ACH (`us_bank_account`) payment doesn't clear instantly — while it's in flight, Stripe fires `payment_intent.processing` and the invoice row moves to a `processing` status (**Payment in progress (ACH)**), distinct from unpaid, so it isn't mistaken for a firm that hasn't paid.

**Mark Paid** and **Void**, both row actions on the Invoicing table for any `created`/`sent`/`processing` invoice, cover the cases Stripe itself never sees: **Mark Paid** records a check/cash/wire/other payment collected outside Stripe — a partial payment is logged entirely in WordPress (its own `njilga_dues_payments` ledger row plus a direct balance update, no Stripe call), while a payment that zeroes the balance calls Stripe's `mark_paid_out_of_band()` so the resulting `invoice.paid` webhook still drives settlement through the one path that's allowed to. **Void** cancels an invoice outright — terminal, the firm needs a new one if they still owe dues. Either action leaves a Company Note immediately.

### Downgrade sweep

Manual, from the Invoicing page, via a **confirmation screen** showing the exact invoices, firms, and members it will touch (and how many are protected by a paid invoice elsewhere). Applies `Unpaid Dues {year}` + `unpaid-dues`, removes `dues-paid`, removes the role if the setting says so, marks rows downgraded, leaves a Company Note.

### Company Notes (spec §8)

Created, sent, paid, downgraded, application approved/rejected — each leaves a note on the FluentCRM Company's "Notes & Activities".

### InvoiceGateway (spec §9)

`includes/invoicing/interface-invoice-gateway.php` is the only seam to the commerce system; `class-stripe-invoice-gateway.php` is the only implementation, and — together with `class-stripe-client.php` — the only pair of files allowed to construct a raw Stripe API call. Every invoice/customer id the interface passes around is a **string** (a Stripe object id such as `in_…`/`cus_…`), never assumed numeric. Swap the implementation with the `my_njilga_invoice_gateway` filter.

**Stripe prerequisites:** a connected account (Settings → Payments — see [Connecting Stripe](#connecting-stripe) below) that can actually accept charges, and a key with at minimum the permissions the connect form lists (Customers/Invoices write, Webhook Endpoints write for auto-provisioning, Charges/PaymentIntents/Credit notes read). The Invoicing page and Setup page both surface Stripe's own connection-health errors up front rather than letting a create attempt fail opaquely.

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
   Without it the plugin still works — the Stripe secret key and webhook secret are just stored in plaintext in the options table, and the Payments tab says so.
2. **Connect an account.** **My NJILGA → Settings → Payments** has a card for **Test mode** and one for **Live mode**, each independent — paste a Stripe secret or restricted key (`rk_…` preferred; `sk_…` accepted, with a nudge to switch) from [dashboard.stripe.com/apikeys](https://dashboard.stripe.com/apikeys). On success the plugin **auto-provisions this site's webhook endpoint** (finds an existing one pointed at this site's `njilga/v1/stripe-webhook` REST route, or creates one) and stores its signing secret; if the key lacks `Webhook Endpoints: Write`, the connect still succeeds and the page falls back to a manual "paste the signing secret" field for an endpoint added by hand in the Stripe Dashboard.
3. **Enable ACH if you want it.** Invoices already request both `card` and `us_bank_account` as accepted payment methods — whether a firm actually sees the bank-transfer option on Stripe's hosted invoice page depends on that payment method being enabled for the connected Stripe account (Settings → Payment methods, or Financial Connections, in the Stripe Dashboard itself — not a setting this plugin controls).
4. **Pick the active mode.** Test and Live are independent connections; only one is active at a time (My NJILGA → Settings → Payments), and switching never moves existing invoice rows or Stripe objects between modes. Run at least one real invoice through Test before flipping to Live.

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

## CSV / Excel exports

Each list page has a **Download CSV** button; **Membership by Firm** and the **Payments** ledger export a formatted `.xls`; **Reports** offers the **Executive Summary** `.xls` combining every report. No third-party libraries.

---

## File structure

```
my-njilga/
├── njilga-membership-report.php          ← Plugin bootstrap + hooks
├── includes/
│   ├── class-admin-menu.php
│   ├── class-tags.php                    ← Tag resolution (core + settings-driven slugs)
│   ├── class-members-data.php
│   ├── class-page-*.php                  ← Dashboard, Reports, Members, Trustees, Companies, Firms
│   ├── class-page-invoicing.php          ← Invoicing dashboard + admin-post handlers
│   ├── class-page-payments.php           ← Payments ledger (by invoice / firm / member / aging)
│   ├── class-page-settings.php           ← Dues & Billing settings UI + Payments (Stripe) tab
│   ├── class-page-applications.php       ← Enrollment review queue
│   ├── class-page-setup.php              ← Environment, tag/product audit, Stripe health + API log
│   ├── class-firm-status-page.php        ← [njilga_firm_dues_status]
│   ├── class-report-*.php                ← CSV / XLS / Executive Summary
│   ├── invoicing/
│   │   ├── class-dues-settings.php       ← Settings storage + seed defaults
│   │   ├── class-pricing-engine.php      ← PURE pricing function (unit-tested)
│   │   ├── class-dues-snapshot.php       ← roster_snapshot shape (v2) + v1 upgrade
│   │   ├── class-dues-invoice-table.php  ← njilga_dues_invoices schema (1.2.0) + CRUD
│   │   ├── class-dues-payments-table.php ← njilga_dues_payments schema + CRUD (Mark Paid ledger)
│   │   ├── interface-invoice-gateway.php ← Commerce seam
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
│   │   ├── class-payment-listener.php    ← Paid → tags + roles (best-effort)
│   │   ├── class-downgrade-sweep.php     ← preview() + run()
│   │   └── class-invoicing-notes.php     ← FluentCRM Company Note helper
│   └── enrollment/
│       ├── class-applications-table.php  ← njilga_membership_applications
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
