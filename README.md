# My NJILGA

A WordPress plugin that gives NJILGA admins a one-stop dashboard for member status, trustees, and company rollups — plus the annual **dues invoicing** process (FluentCart + FluentCRM), a **membership application gate**, and a **member-facing dues status page**. Everything is driven by FluentCRM tags on the local WordPress install. No REST API, no credentials, no subscriptions anywhere.

---

## Installation

1. Copy this folder into `/wp-content/plugins/`
2. Run `composer install` inside the plugin folder (requires PHP 7.4+ and Composer)
3. Activate **My NJILGA** in **WordPress Admin → Plugins**
4. Make sure **FluentCRM** (with the **Companies** module) is active on the same site; **FluentCart** is needed for invoicing
5. Open **My NJILGA → Setup** to verify tags, products, and the environment

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
| **Applications** | Enrollment review queue — see [Enrollment gate](#enrollment-gate). |
| **Settings** | **Dues & Billing** — category mapping, assessment, per-firm billing mode, all switches. |
| **Setup** | Environment checks, tag checklist, **tag-slug audit** and **product-mapping audit** for the settings. |

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

An annual, admin-driven batch process (**My NJILGA → Invoicing**). Staff generate one preview across every FluentCRM Company for a dues year, then work a single firm-focused **Law Firms** table — reviewing each firm's dues in an inline preview and creating invoices one at a time or in bulk. Creating an invoice approves its frozen roster and places the FluentCart order in one click (background batches). Staff send the payment links, FluentCart collects, and payment settles the whole invoice — tags and WordPress roles for everyone on it — at once. There are **no subscriptions**.

**FluentCRM tags are the source of truth for who owes what. WordPress roles are a downstream effect of payment, never an input to pricing.**

**Flow:** Generate Preview → Create Invoices (per firm or in bulk; approves the frozen roster and places the order in one step, Action Scheduler ~25 per job, per-row failure isolation) → Send (email + CC policy + Company Note) → Paid (automatic, via the gateway's "order paid" hook) → end-of-year Downgrade Sweep (manual, behind a confirmation screen). Firms that can't be billed yet — no Owner, no members, nothing billable — are surfaced under **Needs Attention** rather than blocking the main list.

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

Also: default category for untagged contacts (seeded: Professional; can be "not billed"), the inactive-override tag, evergreen paid/unpaid tags and year-tag patterns, invoice email CC policy (bill-to only / + every member / + a fixed list), Reply-To, whether the downgrade sweep removes roles, the **mid-year join policy**, enrollment tags, and the batch size. Each category row maps to a **FluentCart product/variation** (picker reads live products) and a **WordPress role**; each mapped tag and product shows a live ✓/✗ check. **Per-firm billing mode** overrides live at the bottom.

Prices in Settings are what invoices charge; the mapped variation is what the line item points at. A price mismatch with FluentCart is flagged, never silently resolved.

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

Paying an invoice settles *every* member in its frozen snapshot, so both the FluentCart order and the email list all of them, $0 lines included, with the reason:

```
Ann Brown — 2027 Professional Membership (1st Member)              $125.00
Ed Fox — 2027 Professional Membership (Members 2–5)                 $75.00
Ed Fox — Trustee Dinner Assessment (Officer)                       $200.00
Sam Lee — 2027 Professional Membership (no charge, Members 6+)       $0.00
Pat Roe — 2027 Past President Membership (Exempt)                    $0.00
Chris Poe — 2027 Membership Dues (no charge, inactive)               $0.00
```

Each line references the mapped FluentCart product/variation at the Settings price (custom line if unmapped) and carries `line_meta` with `contact_id`, `dues_year`, `kind`, `category`, `tier`, `rank`. A firm where every member is $0 is refused ("nothing to invoice") because FluentCart auto-settles a $0 order.

### Frozen snapshot (spec §5)

Generating freezes each invoice's roster and pricing into `{$wpdb->prefix}njilga_dues_invoices` (`includes/invoicing/class-dues-invoice-table.php`; snapshot shape documented in `class-dues-snapshot.php`). Every later step — order creation, the payment hook, the downgrade sweep, the Company Note — reads that snapshot, never a fresh Company query. Re-running "Generate Preview" only touches rows still `draft`/`excluded`; stale drafts a billing-mode change left behind are removed; anything approved or later is untouched. Version-1 snapshots (pre-2.9) are upgraded on read.

### On payment

Registered through the gateway (`fluent_cart/order_paid_done`). Every member of a paid dues invoice gets the year tag (`Dues Paid 2027`), the evergreen `dues-paid` tag (losing `unpaid-dues`), and their **category's WordPress role** — best-effort: only where a linked WP user exists and the role is defined; contacts with no account are skipped cleanly, never an error. A Company Note records it. Idempotent on duplicate hook fires.

### Downgrade sweep

Manual, from the Invoicing page, via a **confirmation screen** showing the exact invoices, firms, and members it will touch (and how many are protected by a paid invoice elsewhere). Applies `Unpaid Dues {year}` + `unpaid-dues`, removes `dues-paid`, removes the role if the setting says so, marks rows downgraded, leaves a Company Note.

### Company Notes (spec §8)

Created, sent, paid, downgraded, application approved/rejected — each leaves a note on the FluentCRM Company's "Notes & Activities".

### InvoiceGateway (spec §9)

`includes/invoicing/interface-invoice-gateway.php` is the only seam to the commerce plugin; `class-fluentcart-invoice-gateway.php` is the only file that names a FluentCart class. Swap it with the `my_njilga_invoice_gateway` filter.

**FluentCart prerequisites:** the **Offline/Cash payment method must be enabled** (FluentCart routes admin-created orders through it and rejects them otherwise — firms still pay online by any gateway the store offers); mapped products must be **published or private** and **one-time**. The Invoicing page and Setup page check both up front. Verified against FluentCart 1.6.3 source: `OrderResource::updatedPlaceOrder()`, `AdminOrderProcessor` item mapping (it resets `line_meta`, so the gateway writes it back onto the saved `OrderItem`s), `OrderService::validateProducts()`, `PaymentHelper::getCustomPaymentLink()`, `fluent_cart/order_paid_done` payload. Run one test invoice on staging before the first real batch.

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

`[njilga_firm_dues_status]` — the logged-in member → FluentCRM contact → their Company(ies) → every invoice row, newest year first: bill-to, total, status, the **full roster** with amounts, and the **payment link** while an invoice is awaiting payment. Every member of the firm sees it, not just the Owner. Also shows the viewer's own paid/unpaid status.

---

## CSV / Excel exports

Each list page has a **Download CSV** button; **Membership by Firm** exports a formatted `.xls`; **Reports** offers the **Executive Summary** `.xls` combining every report. No third-party libraries.

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
│   ├── class-page-settings.php           ← Dues & Billing settings UI
│   ├── class-page-applications.php       ← Enrollment review queue
│   ├── class-page-setup.php              ← Environment, tag audit, product audit
│   ├── class-firm-status-page.php        ← [njilga_firm_dues_status]
│   ├── class-report-*.php                ← CSV / XLS / Executive Summary
│   ├── invoicing/
│   │   ├── class-dues-settings.php       ← Settings storage + seed defaults
│   │   ├── class-pricing-engine.php      ← PURE pricing function (unit-tested)
│   │   ├── class-dues-snapshot.php       ← roster_snapshot shape (v2) + v1 upgrade
│   │   ├── class-dues-invoice-table.php  ← njilga_dues_invoices schema + CRUD
│   │   ├── interface-invoice-gateway.php ← Commerce seam
│   │   ├── class-fluentcart-invoice-gateway.php ← The only file naming FluentCart classes
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
