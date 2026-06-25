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

The plugin registers a top-level **My NJILGA** menu with six sub-pages:

| Page | What it shows |
|---|---|
| **Dashboard** | Summary counts (paid members, trustees, companies with paid members), bucket distribution, and the Excel download. |
| **Active Members** | Every contact carrying the **Dues Paid** tag, with their firm, trustee flag, and payment method. |
| **Trustees** | Every contact carrying the **Trustees** tag, plus whether they've also paid dues. |
| **Companies** | All FluentCRM Companies, grouped into **1 / 2–5 / 6+ Paid Members** buckets, with members listed underneath. |
| **Membership by Firm** | Every FluentCRM Company with at least one attached contact, listed alphabetically as a bold heading, with its contacts (First/Last name, Email, Dues, Trustees, Past President, Payment) underneath. Exports to a formatted Excel `.xls`. |
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
| Active Members | Member, Contact id, Firm, Trustee?, Payment Method |
| Trustees | Trustee, Contact id, Firm, Dues Paid?, Payment Method |
| Companies | Bucket, Company, Paid Members, Total Members, Member, Status (one row per member) |

CSVs are UTF-8 with a BOM so accented firm names render correctly when opened directly in Excel. No PHP version or third-party library requirement — the export uses plain `fputcsv`.

The **Membership by Firm** page instead offers an **Export to Excel** button that streams a formatted `.xls` (an HTML table served with the Excel MIME type). This preserves the bold firm headings and grouped layout that a flat CSV can't — still with no PhpSpreadsheet/third-party dependency.

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
│   ├── class-page-setup.php
│   ├── class-report-csv.php             ← fputcsv-based per-report streamer
│   └── class-report-xls.php             ← HTML-as-.xls formatted export
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
