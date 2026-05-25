# NJILGA Membership Report Plugin

A WordPress plugin that generates a formatted **Member Dues Report** from FluentCRM data — structured to mirror the existing `2026_Member_Dues_Report.xlsx`.

The plugin reads FluentCRM and Paid Memberships Pro **directly from the local WordPress install** — no REST API, no API keys, no credentials.

---

## Installation

1. Copy the `njilga-membership-report/` folder into `/wp-content/plugins/`
2. Run `composer install` inside the plugin folder (requires PHP 7.4+ and Composer)
3. Activate the plugin in **WordPress Admin → Plugins**
4. Make sure **FluentCRM** is also active on the same site
5. Navigate to **Tools → Membership Report** to view and export the report

---

## How It Reads Data

**FluentCRM (local PHP API):**
- Tags via `FluentCrm\App\Models\Tag::all()` — resolves dues-* slugs to IDs.
- Contacts via `FluentCrm\App\Models\Subscriber::filterByTags([id])->where('status','subscribed')->get()`.
- Custom fields per contact via `$subscriber->custom_fields()` — used to read the **Firm** column (`company_name`) and the dues_* fallback values.

**Paid Memberships Pro (direct DB):**
- `wp_pmpro_memberships_users` — locates the active membership (`status='active'`).
- `wp_pmpro_membership_levels` — `initial_payment` is treated as the expected dues for the report year.
- `wp_pmpro_membership_orders` — sums `total` where `status='success'` and `YEAR(timestamp)` matches the current year. This becomes `amount_paid`.

`open_balance = max(0, initial_payment − amount_paid)` and status is derived (`Paid` / `Partial` / `Unpaid`). Contacts without a linked WP user, or without an active PMPro membership, fall back to the FluentCRM dues_* custom fields. The active source is shown on the Tools → Membership Report page.

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

## Required FluentCRM Custom Fields

Add these under **FluentCRM → Settings → Custom Fields**:

| Field Slug | Type | Options | Purpose |
|---|---|---|---|
| `company_name` | Text | — | The **Firm** column on the report. FluentCRM has no built-in `company_name` property on the Contact schema — it must be a custom field. |
| `dues_status` | Single Select | Paid, Unpaid, Partial | Fallback only — used when a contact is not linked to a WordPress user / has no PMPro membership. |
| `dues_open_balance` | Number | — | Fallback only — see above. |
| `dues_amount_paid` | Number | — | Fallback only — see above. |

When PMPro is the source for a member, the dues_* custom fields are ignored.

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
│   ├── class-report-data.php        ← FluentCRM reader + grouping
│   ├── class-pmpro-data.php         ← PMPro payment data lookup
│   ├── class-report-xlsx.php        ← Excel generation (PhpSpreadsheet)
│   └── class-admin-page.php         ← Tools page UI
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
