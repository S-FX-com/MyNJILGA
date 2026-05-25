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
- `GET /wp-json/fluent-crm/v2/tags?all_tags=true` — to resolve tag slugs → IDs
- `GET /wp-json/fluent-crm/v2/subscribers?tags[]=<id>&custom_fields=true&per_page=100&page=N` — to fetch contacts per tier

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

| Field Slug | Type | Options |
|---|---|---|
| `dues_status` | Single Select | Paid, Unpaid, Partial |
| `dues_open_balance` | Number | — |
| `dues_amount_paid` | Number | — |

Per the FluentCRM API, these come back on each contact as:
```json
"custom_values": {
  "dues_status": "Paid",
  "dues_open_balance": "0",
  "dues_amount_paid": "125"
}
```

The `company_name` field (the **Firm** column) is a standard FluentCRM contact field — no custom field needed.

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
