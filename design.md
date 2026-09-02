# My NJILGA — design system

Every admin screen in this plugin is built from one stylesheet and one set
of render helpers, both living in `includes/class-admin-ui.php`
(`MyNJILGA_Admin_UI`). The look is modelled on
[shadcn/ui](https://github.com/shadcn-ui/ui) element styles — neutral zinc
palette, 12px-radius cards, 6px-radius controls, soft-tinted status pills,
muted-foreground secondary text — reimplemented as plain CSS.

**No Tailwind, no React, no build step.** The plugin ships PHP; the
stylesheet is emitted inline once per request by
`MyNJILGA_Admin_UI::styles()`.

Everything is scoped under `.njilga-ui`, so the design system can never
leak into WordPress's own admin chrome or another plugin's screens — and
WordPress's styles can't bleed into ours.

---

## 1. Rules

1. **Never write inline styles in a page class.** If you need a look that
   doesn't exist, add a class to the stylesheet and use it. The one
   exception is the Excel exporters (`class-report-xls.php`,
   `class-report-summary.php`) — Excel only understands literal inline
   styles, so those stay as they are.
2. **Never use WordPress admin classes** — `widefat`, `striped`,
   `button`, `button-primary`, `notice notice-*`, `form-table`,
   `nav-tab`, `description`. Each has a `njilga-*` replacement below.
3. **Open and close every page** with `MyNJILGA_Admin_UI::open()` /
   `::close()`, or `::styles()` + `<div class="wrap njilga-ui">` when you
   need something before the title (e.g. a back link).
4. **Full-bleed by default.** `.njilga-ui` sets `max-width:none`, so
   screens use the whole admin content column. Constrain an individual
   block with `style="max-width:…"` on that block, not on the page.
5. **Colour carries meaning**, never decoration: green = good/paid/found,
   amber = needs a human, red = failed/unpaid/destructive, blue =
   in-flight or informational, grey = not applicable.
6. **A rule that restyles an input must outrank the generic input rule
   and come after it.** The base control styles are written as
   `.njilga-ui input[type=text]` — a class *and* an attribute selector —
   so a plain `.njilga-search input` or `.njilga-input-sm` silently loses
   and its padding/height is dropped. Write `.njilga-ui input.njilga-…`
   (or `.njilga-ui .wrapper input`) and place it below the generic block.
   Reach for that, never `!important`: both places that once needed this
   were bugs, one visible (the search icon overlapping its placeholder),
   one papered over with three `!important`s.

---

## 2. Tokens

Declared as CSS custom properties on `.njilga-ui`. Use the token, never
the literal hex.

| Token | Value | Use |
|---|---|---|
| `--bg` | `#ffffff` | Card and table backgrounds |
| `--fg` | `#09090b` | Primary text |
| `--muted` | `#f4f4f5` | Table headers, chips, inset panels |
| `--muted-fg` | `#71717a` | Secondary text, labels, placeholders |
| `--border` | `#e4e4e7` | All borders and rules |
| `--primary` | `#18181b` | Primary buttons, active tab, progress fill |
| `--primary-fg` | `#fafafa` | Text on primary |
| `--accent` | `#f4f4f5` | Hover fill on outline/ghost buttons |
| `--ring` | `#a1a1aa` | Focus ring |
| `--hover` | `#fafafa` | Table row hover |
| `--radius-card` | `12px` | Cards, stat tiles, boxed tables |
| `--radius-control` | `6px` | Buttons, inputs, selects |

**Status ramps** — each has a background, foreground and border so tinted
surfaces stay legible:

| Meaning | `-bg` | `-fg` | `-bd` |
|---|---|---|---|
| `--success-*` | `#ecfdf3` | `#067647` | `#abefc6` |
| `--info-*` | `#eff6ff` | `#1d4ed8` | `#bfdbfe` |
| `--warn-*` | `#fff7ed` | `#c2410c` | `#fed7aa` |
| `--danger-*` | `#fef2f2` | `#b42318` | `#fecdca` |

**Type scale:** 26px/700 page title · 17px/600 section title · 15px/600
card title · 14px body · 13.5px controls and table cells · 12.5px
secondary · 11.5px pills.

---

## 3. Components

### Page shell

```php
MyNJILGA_Admin_UI::open( 'Reports', 'One-line description of the screen.' );
// … page body …
MyNJILGA_Admin_UI::close();
```

Pass a third argument to put actions in the header:

```php
MyNJILGA_Admin_UI::open( 'Invoicing', 'Subtitle.', $yearPicker . $createButton );
```

When something must precede the title (a back link), open manually:

```php
MyNJILGA_Admin_UI::styles();
echo '<div class="wrap njilga-ui">';
MyNJILGA_Admin_Menu::render_back_to_reports();
MyNJILGA_Admin_UI::page_header( 'Trustees', 'Subtitle.' );
```

### Section heading

```php
MyNJILGA_Admin_UI::section( 'Pending review', 'Optional description.', 12 );
```

Renders a 17px title, an optional count bubble, and an optional
description (accepts inline links — passed through `wp_kses_post`).

### Buttons

`.njilga-btn` plus one variant, and optionally `.njilga-btn-sm` / `-lg`.

| Class | Use |
|---|---|
| `njilga-btn-primary` | The one main action on the screen |
| `njilga-btn-outline` | Secondary actions, exports, links-as-buttons |
| `njilga-btn-ghost` | Tertiary actions inside a dense row |
| `njilga-btn-danger` | Destructive, confirmed action |
| `njilga-btn-danger-outline` | Destructive but not the primary path (Reject) |

For a form whose only control is one button, use the helper — it builds
the form, the nonce and the hidden fields:

```php
echo MyNJILGA_Admin_UI::action_form(
    'my_njilga_export_csv',            // admin-post action (also the nonce)
    'Download CSV',                    // label
    [ 'type' => 'members' ],           // extra hidden fields
    'outline',                         // button variant
    'download',                        // icon name
    'Are you sure?'                    // optional confirm() text
);
```

### Stat cards

```php
MyNJILGA_Admin_UI::stat_cards( [
    [ 'label' => 'Law Firms',        'value' => 84, 'variant' => 'default', 'icon' => 'users' ],
    [ 'label' => 'Ready to Invoice', 'value' => 61, 'variant' => 'success', 'icon' => 'check-circle' ],
    [ 'label' => 'Needs Attention',  'value' => 6,  'variant' => 'warning', 'icon' => 'alert' ],
] );
```

Variants: `default`, `success`, `info`, `warning`, `destructive`. Add
`'url' => …` to make a card a link (it gets a hover state).

### Badges / pills

```php
MyNJILGA_Admin_UI::pill( 'Ready', 'success' );
```

Variants: `success`, `info`, `warning`, `destructive`, `muted`,
`outline`. Use a pill for a **state** (Paid, Blocked, Invoice Created);
use `::status()` for a **coloured value** inside prose, and `::blank()`
for an empty cell (`—`).

### Validation lines

```php
MyNJILGA_Admin_UI::validation( 'Complete', true );   // green check
MyNJILGA_Admin_UI::validation( 'No firm owner', false ); // amber triangle
```

### Callouts

```php
MyNJILGA_Admin_UI::callout( '<strong>Saved.</strong> …', 'success' );
```

Variants: `success`, `info`, `warning`, `error`. This replaces
`notice notice-*` everywhere. The HTML argument is trusted — escape the
interpolated values yourself.

### Tables

Standard pattern — a boxed card wrapping a horizontally scrollable table:

```php
echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap">';
echo '<table class="njilga-table"><thead><tr><th>Firm</th><th class="njilga-col-num">Total</th></tr></thead><tbody>';
// rows …
echo '</tbody></table></div></div>';
```

Modifiers: `njilga-table-compact` (tighter padding), `njilga-kv`
(key/value — `<th>` becomes a 300px label column). Cell helpers:
`njilga-col-num` (right-aligned tabular figures), `njilga-col-check`,
`njilga-col-actions`, `njilga-col-center`, `njilga-col-expand`. Empty
state: `<tr class="njilga-emptyrow"><td colspan="…">No rows yet.</td></tr>`.

### Forms

Label-left / control-right rows replace `form-table`:

```php
echo '<div class="njilga-card njilga-card-pad"><table class="njilga-formtable"><tbody>';
echo '<tr><th scope="row"><label for="x">Label</label></th><td>';
echo '<input type="text" id="x" name="x" class="regular-text">';
echo '<p class="njilga-help">What this does.</p>';
echo '</td></tr>';
echo '</tbody></table></div>';
```

Inputs, textareas and selects inside `.njilga-ui` are styled
automatically — no class needed. Helpers: `njilga-help` (description
text), `njilga-field` (stacked label + control), `njilga-check-label`
(checkbox/radio + wrapped text), `njilga-radio-list` (vertical group),
`njilga-input-sm`, `njilga-full`, `njilga-newrow` (the "add a row" row),
`njilga-note-ok` / `-warn` / `-bad` (11.5px inline validation under a
field).

### Link cards

Navigation grids (Dashboard, Reports):

```php
printf(
  '<a class="njilga-linkcard" href="%s"><span class="njilga-linkcard-icon">%s</span>
   <span><span class="njilga-linkcard-title">%s &rarr;</span>
   <span class="njilga-linkcard-desc">%s</span></span></a>',
  $url, MyNJILGA_Admin_UI::icon( 'users' ), $title, $desc
);
```

Wrap them in `.njilga-linkcards`. `.njilga-banner` is the sibling
pattern: copy on the left, one action on the right.

### Tabs

Server-rendered (links):

```php
MyNJILGA_Admin_UI::nav_tabs( [
  [ 'label' => 'All Membership', 'url' => $urlAll, 'active' => true ],
  [ 'label' => 'Active Only',    'url' => $urlActive, 'active' => false ],
] );
```

Add `njilga-tabs-bare` to the container when the tabs aren't sitting on a
card. Client-side tabs (Invoicing) use `<button class="njilga-tab" data-tab="…">`.

`nav_tabs()` also works one level up, as a **section switch inside a
single page class** rather than a filter on one list — Settings uses a
`?tab=dues` / `?tab=payments` query arg to render two entirely different
forms from one `render()` (never both at once), the same mechanism
`MyNJILGA_Page_Firms::render_scope_tabs()` uses for a filter. Reach for
this shape when a page grows a second, unrelated screen (a connection/
credentials tab next to a settings form) rather than splitting it into a
second menu page.

### Danger zone

```php
echo '<div class="njilga-danger-card">';
echo '<div class="njilga-danger-head">' . MyNJILGA_Admin_UI::icon( 'alert' ) . '<h2>Reset settings</h2></div>';
echo '<p>What will happen, in plain language.</p>';
echo MyNJILGA_Admin_UI::action_form( $action, 'Reset', [], 'danger', '', 'Are you sure?' );
echo '</div>';
```

Destructive actions that touch many records get a full confirmation
screen (see the Downgrade Sweep), not just a `confirm()`.

### Empty states

```php
echo '<div class="njilga-card njilga-empty">';
echo '<div class="njilga-empty-icon">' . MyNJILGA_Admin_UI::icon( 'file' ) . '</div>';
echo '<h2 class="njilga-empty-title">Nothing here yet</h2>';
echo '<p class="njilga-empty-text">Why it is empty and what to do.</p>';
// … a primary action …
echo '</div>';
```

### Icons

`MyNJILGA_Admin_UI::icon( $name )` returns a 16px inline SVG in
`currentColor` (lucide geometry). Available: `chevron`, `check`,
`check-circle`, `alert`, `users`, `user`, `file`, `search`, `sliders`,
`calendar`, `refresh`, `download`, `building`, `tag`, `inbox`, `award`,
`external`. Add new ones to the `$paths` map in `icon()` — never inline
an SVG in a page class.

---

## 4. The Invoicing workspace

The Invoicing screen adds a client-side data-table on top of the system:
tabs, search, a status filter, bulk selection with a running total,
pagination, and per-row expansion. Its CSS lives in the shared stylesheet
under *Data-table workspace* so any future list screen can reuse it; its
JavaScript lives in `MyNJILGA_Page_Invoicing::scripts()` because it is
specific to that page's markup.

Key classes: `njilga-bulkbar`, `njilga-firmcell` / `njilga-firmname` /
`njilga-subline`, `njilga-chevron` (rotates via `.njilga-row.open`),
`njilga-preview*` and `njilga-roster*` (the expanded invoice preview),
`njilga-tablefoot` / `njilga-pager` / `njilga-pgbtn` (pagination).

**Payments** reuses this same workspace (search/filter/paginate CSS +
JS pattern, its own `scripts()`), but its tabs mean something different:
on Invoicing a tab is a status filter over *one* table shape, while on
Payments each tab (By Invoice / By Firm / By Member / Aging) is a
**separately-rendered table with its own columns** — four `render_by_*()`
methods, each into its own `<div data-panel="…">` toggled by
`[hidden]`, all fed by one shared row-builder rather than each running
its own query. Reach for that shape — several independently-shaped
`<table>`s behind one tab strip, one query — when a screen's "views"
aren't just filtered slices of the same columns.

---

## 5. Adding a screen

1. `MyNJILGA_Admin_UI::open( $title, $subtitle )`.
2. Guard dependencies with `MyNJILGA_Admin_Menu::require_fluentcrm()` and
   emit a `callout( …, 'warning' )` for anything else missing.
3. KPIs → `stat_cards()`. Body → `section()` + a boxed
   `njilga-table`, a `njilga-formtable`, or `njilga-linkcards`.
4. Actions → `njilga-btn-*`, or `action_form()` for single-button posts.
5. `MyNJILGA_Admin_UI::close()`.
6. Run `php -l` on the file and bump the plugin `Version:` header — see
   `CLAUDE.md`.

## 6. Not covered

The two public shortcodes — `[njilga_membership_application]` and
`[njilga_firm_dues_status]` — render on the **front end**, inside the
site's own theme, and keep their own small scoped stylesheets. They
deliberately do not load this admin stylesheet: matching the theme
matters more there than matching the admin. If they are ever unified,
the tokens in §2 are the place to start.
