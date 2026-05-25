# NJILGA Membership Report Plugin

A WordPress plugin that generates a formatted **Member Dues Report** from FluentCRM data — structured to mirror the existing `2026_Member_Dues_Report.xlsx`.

---

## Installation

1. Copy the `njilga-membership-report/` folder into `/wp-content/plugins/`
2. Run `composer install` inside the plugin folder (requires PHP 7.4+ and Composer)
3. Activate the plugin in **WordPress Admin → Plugins**
4. Navigate to **Tools → Membership Report** to enter credentials

---

## FluentCRM Setup

### Step 1 — Create an API Manager Account

1. Go to **FluentCRM → Settings → Managers**
2. Click **Add New Manager**
3. Grant at minimum: **View Contacts** permission
4. Save. (Do NOT use an Administrator account per FluentCRM security guidance.)

### Step 2 — Generate an API Key

1. Go to **FluentCRM → Settings → Rest API**
2. Click **Create New API Key**, select your manager account
3. Copy the **Username** and **Application Password** — you won't see the password again

### Step 3 — Enter Credentials in the Plugin

1. Go to **WordPress Admin → Tools → Membership Report**
2. Enter your Site URL, API Username, and Application Password
3. Click **Save Credentials**

The plugin calls:
- `GET /wp-json/fluent-crm/v2/tags?all_tags=true` — to resolve tag slugs → IDs (reads the flat `all_tags` array, not the paginated `tags.data`)
- `GET /wp-json/fluent-crm/v2/subscribers?tags[]=<id>&statuses[]=subscribed&with[]=subscriber.custom_values&per_page=100&page=N` — to fetch contacts per tier with custom field values embedded

---

## Required FluentCRM Tags (Membership Tiers)

Create these exact tag **slugs** in **FluentCRM → Tags**. The title can be anything; the slug must match exactly:

| Tag Slug | Report Section |
|---|---|
| `dues-1-4-year` | 1-4 Year Admission |
| `dues-1st-member` | 1st Member of Firm |
| `dues-2-5-member` | 2-5 Member of Firm |
| `dues-6-plus-member` | 6+ Member of Firm |
| `dues-past-president-active` | Past President Active |
| `dues-past-president-inactive` | Past President Inactive |
| `dues-senior-trustee-active` | Senior Trustee Active |
| `dues-senior-trustee-inactive` | Senior Trustee Inactive |
| `dues-subscription` | Subscription $125 |
| `dues-trustee-1st` | Trustee – 1st Member of Firm |
| `dues-trustee-2-5` | Trustee – 2-5 Member of Firm |

Tags that don't exist yet will render as empty sections — no errors.

---

## Required Custom Fields

Add these under **FluentCRM → Settings → Custom Fields**:

| Field Slug | Type | Options | Purpose |
|---|---|---|---|
| `company_name` | Text | — | The **Firm** column on the report. FluentCRM has no built-in `company_name` property on the Contact schema — it must be a custom field. |
| `dues_status` | Single Select | Paid, Unpaid, Partial | Fallback only — used when a contact is not linked to a WordPress user / has no PMPro membership. |
| `dues_open_balance` | Number | — | Fallback only — see above. |
| `dues_amount_paid` | Number | — | Fallback only — see above. |

Per the FluentCRM API, these come back on each contact (when `with[]=subscriber.custom_values` is passed) as:
```json
"custom_values": {
  "company_name": "Acme & Associates",
  "dues_status": "Paid",
  "dues_open_balance": "0",
  "dues_amount_paid": "125"
}
```

## Paid Memberships Pro Integration

When PMPro is installed on the same WordPress site, the plugin overrides
the FluentCRM dues custom fields with live data from the PMPro tables for
any FluentCRM contact that is linked to a WordPress user:

- `wp_pmpro_memberships_users` — locates the active membership (`status='active'`).
- `wp_pmpro_membership_levels` — `initial_payment` is treated as the expected dues for the report year.
- `wp_pmpro_membership_orders` — sums `total` where `status='success'` and `YEAR(timestamp)` matches the current year. This becomes `amount_paid`.

`open_balance = max(0, initial_payment − amount_paid)` and status is
derived (`Paid` / `Partial` / `Unpaid`). Contacts without a linked WP
user, or without an active PMPro membership, fall back to the FluentCRM
dues custom fields above. The active source is shown on the Tools →
Membership Report page.

---

## Report Output

The exported `.xlsx` mirrors the existing dues report:
- Grouped by membership tier (same order as the original)
- Running **Invoiced Total** column per row
- **Subtotals** per tier: Open Balance, Amount Paid, Qty
- **Grand totals** row
- **Summary block**: Total / Paid / Unpaid / Partial / $0 member counts
- Status cells color-coded: green (Paid), red (Unpaid), orange (Partial)

---

## File Structure

```
njilga-membership-report/
├── njilga-membership-report.php     ← Plugin bootstrap
├── includes/
│   ├── class-report-data.php        ← FluentCRM REST API + data grouping
│   ├── class-report-xlsx.php        ← Excel generation (PhpSpreadsheet)
│   └── class-admin-page.php         ← Tools page UI + credential management
├── composer.json                    ← Declares PhpSpreadsheet dependency
└── README.md
```

---

## Setup with Claude Code

```bash
cd wp-content/plugins/njilga-membership-report
composer install
```

That's it. No build step.
