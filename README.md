# My NJILGA

A WordPress plugin that gives NJILGA admins a one-stop dashboard for member status, trustees, and company rollups — all driven by FluentCRM tags on the local WordPress install. No REST API, no credentials.

---

## Installation

1. Copy this folder into `/wp-content/plugins/`
2. Run `composer install` inside the plugin folder (requires PHP 7.4+ and Composer)
3. Activate **My NJILGA** in **WordPress Admin → Plugins**
4. Make sure **FluentCRM** is also active on the same site
5. Open **My NJILGA → Setup** to verify or create the required tags

---

## Menu

The plugin registers a top-level **My NJILGA** menu:

| Page | What it shows |
|---|---|
| **Dashboard** | Summary counts (paid members, trustees, companies with paid members), bucket distribution, and the Excel download. |
| **Reports** | Landing page for every report below, plus the Executive Summary export. |
| **Active Paid Members** | Every contact carrying the **Dues Paid** tag, with their firm, email, trustee flag, payment method, and a green **PAID** column. |
| **Trustees** | Every contact carrying the **Trustees** tag, plus whether they've also paid dues. |
| **Companies** | All FluentCRM Companies, grouped into **1 / 2–5 / 6+ Paid Members** buckets, with members listed underneath. |
| **Membership by Firm** | Every FluentCRM Company with at least one attached contact, listed alphabetically as a bold heading, with its contacts (First/Last name, Email, Dues, Trustees, Past President, Payment) underneath. Exports to a formatted Excel `.xls`. |
| **Invoicing** | Annual dues invoicing by firm — see [Dues Invoicing by Firm](#dues-invoicing-by-firm) below. |
| **Setup** | Detects whether the required tags exist and offers a one-click button to create any that are missing. |

---

## How status is determined

| Concept | Source |
|---|---|
| Paid / Active member | Contact has the **Dues Paid** tag |
| Trustee | Contact has the **Trustees** tag |
| Payment method = Check | Contact has the **Paid by Check** tag |
| Payment method = Invoice | Contact has the **Paid by Invoice** tag |
| Payment method = Credit Card | Default when neither Check nor Invoice tag is present |
| Firm | The FluentCRM **Company** entity linked to the contact (fall back: `company_name` custom field text) |

The Setup page looks up each required tag by **slug** first, then by exact **title** as a fallback, so a manually-created tag with a non-default slug still matches.

---

## Required FluentCRM tags

| Slug | Title | Required? |
|---|---|---|
| `dues-paid` | Dues Paid | Yes |
| `unpaid-dues` | Unpaid Dues | Optional |
| `trustees` | Trustees | Yes |
| `senior-trustee` | Senior Trustee | Optional |
| `past-president` | Past President | Optional |
| `paid-by-check` | Paid by Check | Optional |
| `paid-by-invoice` | Paid by Invoice | Optional |

On the **Membership by Firm** report, the Dues column shows **Dues Paid** when the `dues-paid` tag is present, **Unpaid Dues** when the `unpaid-dues` tag is present, and is left blank when neither exists. The Payment column shows **Paid by Invoice** / **Paid by Check** when those tags are present, **Paid by Website** when `dues-paid` is present but neither override tag is, and is blank otherwise.

The Setup page can create any of these for you in one click via the FluentCRM Tags API.

---

## Required FluentCRM module

For the Companies report to populate, the **FluentCRM Companies module** must be enabled (FluentCRM → Settings → Modules). Contacts must be linked to their company via FluentCRM's primary-company assignment.

---

## CSV exports

Each list page has its own **Download CSV** button:

| Page | CSV columns |
|---|---|
| Active Paid Members | First Name, Last Name, Email, Firm Name, Trustee, Payment Method, CRM ID |
| Trustees | Trustee, Contact id, Firm, Dues Paid?, Payment Method |
| Companies | Bucket, Company, Paid Members, Total Members, Member, Status (one row per member) |

CSVs are UTF-8 with a BOM so accented firm names render correctly when opened directly in Excel. No PHP version or third-party library requirement — the export uses plain `fputcsv`.

The **Membership by Firm** page instead offers an **Export to Excel** button that streams a formatted `.xls` (an HTML table served with the Excel MIME type). This preserves the bold firm headings and grouped layout that a flat CSV can't — still with no PhpSpreadsheet/third-party dependency. The Dues column is bold green for **Dues Paid** and bold red for **Unpaid Dues**.

The **Reports** landing page offers a **Download Executive Summary (Excel)** button that streams a single formatted `.xls` combining every report — an Overview KPI section, Active Paid Members, Trustees, Companies, and Membership by Firm — using the same HTML-as-`.xls` approach, one section per report separated by a bold banner row.

---

## Dues Invoicing by Firm

An annual, admin-triggered batch process (**My NJILGA → Invoicing**) that reads the FluentCRM Company roster, computes what each firm owes, and creates [FluentCart](https://fluentcart.com/) orders (invoices) for review and manual send. No JS, no build step — same server-rendered PHP forms as the rest of the plugin; the per-firm line-item breakdown uses a native `<details>`/`<summary>` disclosure instead of a script.

**Flow:** Generate Preview → Review & Approve → Create Invoices (FluentCart orders) → Send (email + a FluentCRM Company Note) → Paid (automatic, via FluentCart's `fluent_cart/order_paid_done` hook) → end-of-year Downgrade Sweep (manual button) for anyone who never paid.

**Pricing** (computed fresh each cycle by headcount, no persistent "member #3" designation): 1st *paying* member per firm (alphabetical by last name, then first) = $125, 2nd–5th = $75 each, 6th+ = free. Trustees/Senior Trustees/Past Presidents additionally owe a flat $200 assessment, capped at one per person.

**Senior Trustees and Past Presidents are dues-exempt** (confirmed with NJILGA): they owe $0 base membership dues but still owe the $200 assessment. They still count toward the firm's roster, but are always sorted to the *end* of the billing order — never occupying the paid 1st-member slot — so a firm's actually-paying members are priced 1st/2nd-5th purely among themselves, unaffected by how many exempt members are also on the roster. (A firm with one exempt Past President and one regular associate bills the associate the full $125 1st-member price, not $75 — the exempt member doesn't silently "use up" the cheaper slot.) Plain Trustees (not Senior/Past President) are not dues-exempt — they pay full tier dues plus the $200 assessment.

**Frozen snapshot:** generating a firm's invoice freezes its roster and pricing into `{$wpdb->prefix}njilga_dues_invoices` (`includes/invoicing/class-dues-invoice-table.php`). Every later step — the FluentCart order, the payment webhook, the downgrade sweep, the Company Note — reads that frozen snapshot, never a fresh Company query, so a firm's roster can't drift between "invoiced" and "paid." Re-running "Generate Preview" only ever touches rows still in `draft`/`excluded`; anything already approved or further along is left completely untouched.

**On payment**, every roster member gets a year-specific `Dues Paid {year}` tag (a permanent historical record) **and** the plugin's evergreen `dues-paid` tag (removing `unpaid-dues` if present) — the second part is a deliberate addition beyond a literal reading of the original spec, so the existing reports above (Active Paid Members, Membership by Firm, the Executive Summary) keep reflecting reality instead of only ever seeing the year-suffixed tag. The **Downgrade Sweep** does the mirror image (`Unpaid Dues {year}` + evergreen `unpaid-dues`, `professional` role stripped). The `professional` WordPress role itself is only touched where a contact has a linked WP user — many won't, and that's skipped cleanly rather than erroring.

**One thing to confirm before relying on this for real billing:** the exact FluentCart order-creation call. `includes/invoicing/class-invoice-creator.php` (Customer find-or-create, the custom line-item shape, `OrderResource::updatedPlaceOrder()`) was reconstructed from FluentCart's public developer docs (dev.fluentcart.com), since this plugin doesn't ship with FluentCart's source. It's a best-effort reconstruction, not a verified integration — run one real test invoice against a throwaway test firm on staging before using "Create Invoices" for actual dues billing. Everything upstream of that call (the DB table, the pricing math, the exemption/trustee-fee rules, the admin dashboard) only depends on FluentCRM, which this plugin already talks to directly and confidently elsewhere, and is smoke-tested.

---

## File Structure

```
my-njilga/
├── njilga-membership-report.php         ← Plugin bootstrap + admin-post hooks
├── includes/
│   ├── class-admin-menu.php             ← Top-level menu + sub-pages
│   ├── class-tags.php                   ← Tag resolution + per-subscriber helpers
│   ├── class-members-data.php           ← Builds the three datasets
│   ├── class-page-dashboard.php
│   ├── class-page-members.php
│   ├── class-page-trustees.php
│   ├── class-page-companies.php
│   ├── class-page-firms.php             ← Membership by Firm report
│   ├── class-page-invoicing.php         ← Invoicing dashboard (admin-post handlers + rendering)
│   ├── class-page-setup.php
│   ├── class-report-csv.php             ← fputcsv-based per-report streamer
│   ├── class-report-xls.php             ← HTML-as-.xls formatted export
│   ├── class-report-summary.php         ← Executive Summary — combines every report into one .xls
│   └── invoicing/                       ← Dues Invoicing by Firm (see section above)
│       ├── class-dues-invoice-table.php ← njilga_dues_invoices schema + CRUD
│       ├── class-dues-preview.php       ← Company roster + tier/trustee pricing math
│       ├── class-invoice-creator.php    ← FluentCart order creation
│       ├── class-invoice-sender.php     ← Email + Company Note on send
│       ├── class-payment-listener.php   ← fluent_cart/order_paid_done handler
│       ├── class-downgrade-sweep.php    ← Manual end-of-year downgrade
│       └── class-invoicing-notes.php    ← Shared FluentCRM Company Note helper
├── composer.json                        ← Declares the GitHub update checker
└── README.md
```

---

## Setup with Claude Code

```bash
cd wp-content/plugins/my-njilga
composer install
```

No build step. The `vendor/` directory is committed, so reinstalling is only needed if you bump a dependency.

---

## Updates

The plugin checks **`s-fx-com/MyNJILGA`** on GitHub for new **tagged releases** using [yahnis-elsts/plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker). Cut a release on GitHub whose tag matches the new `Version:` header (e.g. tag `v2.1.0` for `Version: 2.1.0`) and every site running the plugin will see an "Update available" prompt in **WordPress Admin → Plugins** within the normal WP transient window.

### Private repo

If the repository is private, add a GitHub Personal Access Token (with `repo` scope) to `wp-config.php`:

```php
define( 'MY_NJILGA_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' );
```

The update checker uses it for both the version-check call and the zip download. Without the constant, only public-repo access is attempted.

### Cutting a release

1. Bump the `Version:` header in `njilga-membership-report.php`.
2. Commit and push to `main`.
3. On GitHub, **Releases → Draft a new release**, pick a tag like `v2.1.0`, publish.
4. WordPress sites will pick it up on their next plugin-update cron run (force it with `?wp-admin/update-core.php` → "Check Again").
