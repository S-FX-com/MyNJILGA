<?php
/**
 * MyNJILGA_Admin_UI — the plugin's design system.
 *
 * One stylesheet, one set of render helpers, used by every My NJILGA admin
 * page so they read as one product instead of ten differently-styled
 * screens. Modelled on shadcn/ui element styles (neutral zinc palette,
 * 12px-radius cards, 6px-radius controls, soft-tinted status pills,
 * muted-foreground secondary text) reimplemented as plain CSS — no
 * Tailwind, no React, no build step.
 *
 * Everything is scoped under `.njilga-ui`, so nothing here can leak into
 * WordPress's own admin chrome or another plugin's screens. Pages open
 * with MyNJILGA_Admin_UI::open() and close with ::close(); the CSS is
 * emitted once per request no matter how many helpers are called.
 *
 * See design.md at the repo root for the token reference, the component
 * catalogue, and the conventions for adding a new screen.
 */
class MyNJILGA_Admin_UI {

    /** @var bool Guards against emitting the stylesheet more than once. */
    private static $printed = false;

    // -------------------------------------------------------------------------
    // Page shell
    // -------------------------------------------------------------------------

    /**
     * Open a page: stylesheet (once), the .wrap container, and the title
     * block. $actions is raw HTML dropped into the header's right side
     * (buttons, a year picker, an export form).
     */
    public static function open( string $title, string $subtitle = '', string $actions = '' ): void {
        self::styles();
        echo '<div class="wrap njilga-ui">';
        self::page_header( $title, $subtitle, $actions );
    }

    public static function close(): void {
        echo '</div>';
    }

    public static function page_header( string $title, string $subtitle = '', string $actions = '' ): void {
        self::styles();
        echo '<div class="njilga-header"><div class="njilga-header-text">';
        printf( '<h1 class="njilga-title">%s</h1>', esc_html( $title ) );
        if ( $subtitle !== '' ) {
            printf( '<p class="njilga-subtitle">%s</p>', esc_html( $subtitle ) );
        }
        echo '</div>';
        if ( $actions !== '' ) {
            echo '<div class="njilga-header-actions">' . $actions . '</div>';
        }
        echo '</div>';
    }

    /**
     * Section heading with an optional count bubble and description.
     */
    public static function section( string $title, string $desc = '', ?int $count = null ): void {
        printf(
            '<div class="njilga-section"><h2 class="njilga-section-title">%s%s</h2>%s</div>',
            esc_html( $title ),
            $count !== null ? sprintf( ' <span class="njilga-section-count">%d</span>', $count ) : '',
            $desc !== '' ? sprintf( '<p class="njilga-section-desc">%s</p>', wp_kses_post( $desc ) ) : ''
        );
    }

    public static function back_link( string $url, string $label ): void {
        printf( '<p class="njilga-back"><a href="%s">&larr; %s</a></p>', esc_url( $url ), esc_html( $label ) );
    }

    // -------------------------------------------------------------------------
    // Components
    // -------------------------------------------------------------------------

    /**
     * A tinted notice. $variant: success | info | warning | error.
     * $html is trusted markup (callers pass escaped text).
     */
    public static function callout( string $html, string $variant = 'info' ): void {
        self::styles();
        printf( '<div class="njilga-callout njilga-callout-%s"><p>%s</p></div>', esc_attr( $variant ), $html );
    }

    /**
     * Status pill. $variant: success | info | warning | destructive | muted | outline.
     */
    public static function pill( string $text, string $variant = 'muted' ): string {
        return sprintf(
            '<span class="njilga-badge njilga-badge-%s">%s</span>',
            esc_attr( $variant ),
            esc_html( $text )
        );
    }

    /**
     * KPI tiles. Each card: [ label, value, variant?, icon? ].
     *
     * @param array<int,array{label:string,value:int|string,variant?:string,icon?:string,url?:string}> $cards
     */
    public static function stat_cards( array $cards ): void {
        self::styles();
        echo '<div class="njilga-stats">';
        foreach ( $cards as $card ) {
            $inner = sprintf(
                '<div class="njilga-stat-icon">%s</div><div class="njilga-stat-body"><div class="njilga-stat-label">%s</div><div class="njilga-stat-value">%s</div></div>',
                self::icon( (string) ( $card['icon'] ?? 'users' ) ),
                esc_html( (string) $card['label'] ),
                esc_html( (string) $card['value'] )
            );
            $class = 'njilga-stat njilga-stat-' . esc_attr( (string) ( $card['variant'] ?? 'default' ) );
            if ( ! empty( $card['url'] ) ) {
                printf( '<a class="%s njilga-stat-link" href="%s">%s</a>', $class, esc_url( (string) $card['url'] ), $inner );
            } else {
                printf( '<div class="%s">%s</div>', $class, $inner );
            }
        }
        echo '</div>';
    }

    /**
     * Value cell with a semantic colour: ok (green), warn (amber),
     * bad (red), or muted. Used for Paid/Unpaid, Found/Missing, etc.
     */
    public static function status( string $text, string $tone = 'muted' ): string {
        return sprintf( '<span class="njilga-status njilga-status-%s">%s</span>', esc_attr( $tone ), esc_html( $text ) );
    }

    /** Muted em-dash placeholder for an empty cell. */
    public static function blank(): string {
        return '<span class="njilga-dim">&mdash;</span>';
    }

    /**
     * Validation line: a check or a warning triangle plus a label.
     */
    public static function validation( string $label, bool $ok ): string {
        return sprintf(
            '<span class="njilga-valid njilga-valid-%s">%s%s</span>',
            $ok ? 'ok' : 'warn',
            self::icon( $ok ? 'check' : 'alert' ),
            esc_html( $label )
        );
    }

    /**
     * Underlined tab bar rendered as links (server-side navigation).
     *
     * @param array<int,array{label:string,url:string,active:bool,count?:int}> $tabs
     */
    public static function nav_tabs( array $tabs ): void {
        echo '<div class="njilga-tabs">';
        foreach ( $tabs as $t ) {
            printf(
                '<a class="njilga-tab%s" href="%s">%s%s</a>',
                ! empty( $t['active'] ) ? ' active' : '',
                esc_url( $t['url'] ),
                esc_html( $t['label'] ),
                isset( $t['count'] ) ? sprintf( ' <span class="njilga-tab-count">%d</span>', (int) $t['count'] ) : ''
            );
        }
        echo '</div>';
    }

    /**
     * A form whose only control is one button — the plugin's standard way
     * of posting a single admin-post action (exports, "create tag", …).
     * $size optionally adds a `.njilga-btn-{$size}` modifier (e.g. 'sm')
     * alongside the variant class, for a single-button post embedded in a
     * dense row (a table's Actions column) rather than standing alone.
     */
    public static function action_form( string $action, string $label, array $fields = [], string $style = 'outline', string $icon = '', string $confirm = '', string $size = '' ): string {
        $hidden = sprintf( '<input type="hidden" name="action" value="%s">', esc_attr( $action ) );
        foreach ( $fields as $k => $v ) {
            $hidden .= sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( (string) $k ), esc_attr( (string) $v ) );
        }
        return sprintf(
            '<form method="post" action="%s" class="njilga-actionform"%s>%s%s<button type="submit" class="njilga-btn njilga-btn-%s%s">%s%s</button></form>',
            esc_url( admin_url( 'admin-post.php' ) ),
            $confirm !== '' ? sprintf( ' onsubmit="return confirm(%s)"', esc_attr( "'" . esc_js( $confirm ) . "'" ) ) : '',
            $hidden,
            wp_nonce_field( $action, '_wpnonce', true, false ),
            esc_attr( $style ),
            $size !== '' ? ' njilga-btn-' . esc_attr( $size ) : '',
            $icon !== '' ? self::icon( $icon ) : '',
            esc_html( $label )
        );
    }

    // -------------------------------------------------------------------------
    // Icons (lucide-style inline SVG, 16px, currentColor)
    // -------------------------------------------------------------------------

    public static function icon( string $name ): string {
        static $paths = [
            'chevron'      => '<path d="m6 9 6 6 6-6"/>',
            'check'        => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
            'alert'        => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
            'users'        => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'user'         => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
            'file'         => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v5h5"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
            'search'       => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
            'sliders'      => '<path d="M20 7h-9"/><path d="M14 17H5"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/>',
            'calendar'     => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="M8 2v4"/><path d="M16 2v4"/>',
            'refresh'      => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
            'download'     => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
            'building'     => '<rect width="16" height="20" x="4" y="2" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M8 11h.01"/><path d="M16 11h.01"/>',
            'tag'          => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
            'inbox'        => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
            'award'        => '<path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/>',
            'external'     => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        ];
        $body = $paths[ $name ] ?? '';
        return '<svg class="njilga-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
    }

    // -------------------------------------------------------------------------
    // The stylesheet
    // -------------------------------------------------------------------------

    public static function styles(): void {
        if ( self::$printed ) {
            return;
        }
        self::$printed = true;

        echo <<<'CSS'
<style>
/* ---------------------------------------------------------------------------
   My NJILGA design system — see design.md. Scoped to .njilga-ui.
   --------------------------------------------------------------------------- */
.njilga-ui{
  /* Tokens */
  --bg:#ffffff; --fg:#09090b; --muted:#f4f4f5; --muted-fg:#71717a;
  --border:#e4e4e7; --primary:#18181b; --primary-fg:#fafafa; --accent:#f4f4f5;
  --ring:#a1a1aa; --hover:#fafafa;
  --success-bg:#ecfdf3; --success-fg:#067647; --success-bd:#abefc6;
  --info-bg:#eff6ff;    --info-fg:#1d4ed8;    --info-bd:#bfdbfe;
  --warn-bg:#fff7ed;    --warn-fg:#c2410c;    --warn-bd:#fed7aa;
  --danger-bg:#fef2f2;  --danger-fg:#b42318;  --danger-bd:#fecdca;
  --radius-card:12px; --radius-control:6px;

  color:var(--fg);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  font-size:14px;
  /* Full-bleed: use the whole admin content column. */
  max-width:none; width:auto; margin-right:20px;
}
.njilga-ui *{box-sizing:border-box}
.njilga-ui [hidden]{display:none!important}
.njilga-ui tr[hidden]{display:none}
.njilga-ui a{color:var(--info-fg);text-decoration:none}
.njilga-ui a:hover{text-decoration:underline}
.njilga-ui code{background:var(--muted);padding:1px 5px;border-radius:4px;font-size:12px;
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.njilga-ui p{font-size:14px}
.njilga-icon{width:16px;height:16px;flex:0 0 auto;vertical-align:middle}
.njilga-dim{color:var(--muted-fg)}
.njilga-nowrap{white-space:nowrap}

/* --- Page header --------------------------------------------------------- */
.njilga-header{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;
  flex-wrap:wrap;margin:6px 0 18px}
.njilga-title{font-size:26px;font-weight:700;line-height:1.2;margin:0;padding:0;color:var(--fg)}
.njilga-title-danger{color:var(--danger-fg)}
.njilga-subtitle{color:var(--muted-fg);font-size:14px;margin:6px 0 0;max-width:820px}
.njilga-header-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.njilga-header-note{color:var(--muted-fg);font-size:12.5px;margin:8px 0 18px;text-align:right}
.njilga-back{margin:4px 0 12px;font-size:13px}

/* --- Section headings ---------------------------------------------------- */
.njilga-section{margin:28px 0 12px}
.njilga-section-title{font-size:17px;font-weight:600;margin:0;padding:0;display:flex;
  align-items:center;gap:9px;color:var(--fg)}
.njilga-section-count{display:inline-flex;align-items:center;justify-content:center;min-width:22px;
  height:20px;padding:0 7px;border-radius:999px;background:var(--muted);color:var(--muted-fg);
  font-size:11.5px;font-weight:600}
.njilga-section-desc{color:var(--muted-fg);font-size:13.5px;margin:6px 0 0;max-width:900px;line-height:1.55}

/* --- Buttons ------------------------------------------------------------- */
.njilga-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;
  height:38px;padding:0 15px;border-radius:var(--radius-control);border:1px solid transparent;
  font-size:13.5px;font-weight:500;line-height:1;cursor:pointer;text-decoration:none;
  transition:background .12s,border-color .12s,opacity .12s;white-space:nowrap;background:none;
  font-family:inherit}
.njilga-btn:hover{text-decoration:none}
.njilga-btn:focus-visible{outline:2px solid var(--ring);outline-offset:2px}
.njilga-btn-sm{height:32px;padding:0 11px;font-size:12.5px}
.njilga-btn-lg{height:42px;padding:0 20px;font-size:14px}
.njilga-btn-primary{background:var(--primary);color:var(--primary-fg);border-color:var(--primary)}
.njilga-btn-primary:hover{background:#27272a;color:var(--primary-fg)}
.njilga-btn-outline{background:var(--bg);color:var(--fg);border-color:var(--border)}
.njilga-btn-outline:hover{background:var(--accent);color:var(--fg)}
.njilga-btn-ghost{background:transparent;color:var(--fg)}
.njilga-btn-ghost:hover{background:var(--accent);color:var(--fg)}
.njilga-btn-danger{background:var(--danger-fg);color:#fff;border-color:var(--danger-fg)}
.njilga-btn-danger:hover{background:#912018;color:#fff}
.njilga-btn-danger-outline{background:var(--bg);color:var(--danger-fg);border-color:var(--danger-bd)}
.njilga-btn-danger-outline:hover{background:var(--danger-bg);color:var(--danger-fg)}
.njilga-btn[disabled]{opacity:.5;cursor:not-allowed;pointer-events:none}
.njilga-actionform{margin:0;display:inline-block}
.njilga-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:12px 0}

/* --- Stat cards ---------------------------------------------------------- */
.njilga-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin:16px 0 22px}
.njilga-stat{display:flex;align-items:center;gap:14px;padding:16px 18px;background:var(--bg);
  border:1px solid var(--border);border-radius:var(--radius-card)}
.njilga-stat-link{color:inherit;transition:border-color .12s,background .12s}
.njilga-stat-link:hover{border-color:var(--ring);background:var(--hover);text-decoration:none}
.njilga-stat-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;
  border-radius:10px;background:var(--muted);color:var(--muted-fg);flex:0 0 auto}
.njilga-stat-icon .njilga-icon{width:20px;height:20px}
.njilga-stat-label{color:var(--muted-fg);font-size:13px;font-weight:500}
.njilga-stat-value{font-size:26px;font-weight:700;line-height:1.15;margin-top:2px}
.njilga-stat-success .njilga-stat-icon{background:var(--success-bg);color:var(--success-fg)}
.njilga-stat-success .njilga-stat-value{color:var(--success-fg)}
.njilga-stat-info .njilga-stat-icon{background:var(--info-bg);color:var(--info-fg)}
.njilga-stat-warning .njilga-stat-icon{background:var(--warn-bg);color:var(--warn-fg)}
.njilga-stat-warning .njilga-stat-value{color:var(--warn-fg)}
.njilga-stat-destructive .njilga-stat-icon{background:var(--danger-bg);color:var(--danger-fg)}
.njilga-stat-destructive .njilga-stat-value{color:var(--danger-fg)}
.njilga-stat-muted .njilga-stat-icon{background:var(--muted);color:var(--muted-fg)}
.njilga-stat-muted .njilga-stat-value{color:var(--muted-fg)}

/* --- Progress ------------------------------------------------------------ */
.njilga-progress-wrap{margin:6px 0 22px}
.njilga-progress-top{display:flex;justify-content:space-between;align-items:baseline;gap:12px;
  flex-wrap:wrap;margin-bottom:8px}
.njilga-progress-label{font-size:13px;color:var(--muted-fg);font-weight:500}
.njilga-money-line{font-size:12.5px;color:var(--muted-fg)}
.njilga-progress{height:8px;background:var(--muted);border-radius:999px;overflow:hidden}
.njilga-progress-bar{height:100%;background:var(--primary);border-radius:999px;transition:width .3s}

/* --- Cards --------------------------------------------------------------- */
.njilga-card{background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-card);
  overflow:hidden;margin-bottom:20px}
.njilga-card-head{padding:16px 18px 0}
.njilga-card-title{font-size:17px;font-weight:600;margin:0}
.njilga-card-pad{padding:18px}

/* Generic layout utilities — a responsive two-up card grid (Payments tab's
   Test/Live cards) and a flex row that splits its children to the edges
   (a card's title + status pills). Kept generic rather than one-off so
   any future screen needing the same shapes can reuse them. */
.njilga-cols-2{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:16px;margin-bottom:20px}
.njilga-row-between{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px}

/* Banner: an inline card with copy on the left, an action on the right. */
.njilga-banner{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;
  padding:16px 20px;margin:0 0 20px;background:var(--bg);border:1px solid var(--border);
  border-radius:var(--radius-card)}
.njilga-banner-title{font-size:15px;font-weight:600;margin-bottom:3px}
.njilga-banner-desc{color:var(--muted-fg);font-size:13px;line-height:1.5;max-width:760px}
.njilga-banner-total{font-size:17px;font-weight:700;color:var(--fg)}

/* Link cards: the Reports / Dashboard navigation grid. */
.njilga-linkcards{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin:8px 0 20px}
.njilga-linkcard{display:flex;gap:14px;padding:18px 20px;background:var(--bg);border:1px solid var(--border);
  border-radius:var(--radius-card);color:inherit;transition:border-color .12s,background .12s}
.njilga-linkcard:hover{border-color:var(--ring);background:var(--hover);text-decoration:none;color:inherit}
.njilga-linkcard-icon{display:flex;align-items:center;justify-content:center;width:38px;height:38px;
  border-radius:10px;background:var(--muted);color:var(--muted-fg);flex:0 0 auto}
.njilga-linkcard-title{font-size:15px;font-weight:600;margin-bottom:4px}
.njilga-linkcard-desc{color:var(--muted-fg);font-size:13px;line-height:1.5}

/* --- Tabs ---------------------------------------------------------------- */
.njilga-tabs{display:flex;gap:4px;padding:10px 12px 0;border-bottom:1px solid var(--border);flex-wrap:wrap}
.njilga-tabs-bare{padding-left:0;padding-right:0;margin-bottom:18px}
.njilga-tab{display:inline-flex;align-items:center;gap:7px;padding:8px 12px;background:none;border:none;
  border-bottom:2px solid transparent;color:var(--muted-fg);font-size:13.5px;font-weight:500;
  cursor:pointer;margin-bottom:-1px;font-family:inherit;text-decoration:none}
.njilga-tab:hover{color:var(--fg);text-decoration:none}
.njilga-tab.active{color:var(--fg);border-bottom-color:var(--primary)}
.njilga-tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;
  padding:0 6px;border-radius:999px;background:var(--muted);color:var(--muted-fg);font-size:11.5px;font-weight:600}
.njilga-tab.active .njilga-tab-count{background:var(--primary);color:var(--primary-fg)}

/* --- Toolbar / form controls --------------------------------------------- */
.njilga-toolbar{display:flex;align-items:center;gap:10px;padding:14px 18px;flex-wrap:wrap}
.njilga-toolbar-spacer{flex:1 1 auto}
.njilga-search{position:relative;flex:1 1 260px;max-width:380px}
.njilga-search .njilga-icon{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted-fg)}
.njilga-search input{width:100%;height:38px;padding:0 12px 0 34px;border:1px solid var(--border);
  border-radius:var(--radius-control);font-size:13.5px;background:var(--bg);color:var(--fg);font-family:inherit}
.njilga-ui input[type=text],.njilga-ui input[type=email],.njilga-ui input[type=number],
.njilga-ui input[type=url],.njilga-ui input[type=search],.njilga-ui input[type=date],.njilga-ui textarea{
  height:38px;padding:0 12px;border:1px solid var(--border);border-radius:var(--radius-control);
  font-size:13.5px;background:var(--bg);color:var(--fg);font-family:inherit;max-width:100%}
.njilga-ui textarea{height:auto;padding:9px 12px;line-height:1.5;resize:vertical}
.njilga-ui input:focus,.njilga-ui textarea:focus,.njilga-ui select:focus{
  outline:none;border-color:var(--ring);box-shadow:0 0 0 3px rgba(161,161,170,.25)}
.njilga-select,.njilga-ui select{height:38px;padding:0 30px 0 12px;border:1px solid var(--border);
  border-radius:var(--radius-control);font-size:13.5px;color:var(--fg);cursor:pointer;font-family:inherit;
  -webkit-appearance:none;appearance:none;max-width:100%;
  background:var(--bg) url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") no-repeat right 9px center}
.njilga-select-sm,.njilga-ui select.njilga-select-sm{height:32px;font-size:12.5px}
/* A real <select multiple> (Payments' Years filter) needs to show several
   rows at once rather than collapse to the single-select's fixed height —
   the dropdown chevron background is meaningless on a multi-select too. */
.njilga-select[multiple]{height:auto;min-height:84px;padding:6px 10px;background-image:none}
.njilga-ui input[type=checkbox],.njilga-ui input[type=radio]{width:16px;height:16px;
  accent-color:var(--primary);cursor:pointer;margin:0}
.njilga-input-sm{height:32px!important;font-size:12.5px!important;padding:0 9px!important}
.njilga-full{width:100%}
.njilga-help{color:var(--muted-fg);font-size:12.5px;margin:6px 0 0;line-height:1.5}
.njilga-check-label{display:inline-flex;align-items:flex-start;gap:9px;font-size:13.5px;line-height:1.45}
.njilga-radio-list{display:flex;flex-direction:column;gap:8px;margin-bottom:10px}
.njilga-field{margin-bottom:14px}
.njilga-field>label{display:block;font-size:13px;font-weight:500;margin-bottom:6px}

/* Label-left / control-right settings rows (replaces WP .form-table). */
.njilga-formtable{width:100%;border-collapse:collapse;margin:4px 0 8px}
.njilga-formtable>tbody>tr>th{width:280px;text-align:left;vertical-align:top;padding:14px 20px 14px 0;
  font-size:13.5px;font-weight:600;color:var(--fg)}
.njilga-formtable>tbody>tr>td{padding:12px 0;vertical-align:top;border-bottom:1px solid var(--border)}
.njilga-formtable>tbody>tr:last-child>td{border-bottom:none}
.njilga-formtable .regular-text{width:340px}
.njilga-formtable .large-text{width:100%;max-width:640px}

/* --- Tables -------------------------------------------------------------- */
.njilga-tablewrap{overflow-x:auto}
.njilga-table{width:100%;border-collapse:collapse;font-size:13.5px;background:var(--bg)}
.njilga-table thead th{text-align:left;padding:11px 14px;color:var(--muted-fg);font-weight:500;
  font-size:12.5px;border-bottom:1px solid var(--border);white-space:nowrap;background:var(--bg)}
.njilga-table tbody td{padding:12px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
.njilga-table tbody tr:last-child td{border-bottom:none}
.njilga-table tbody tr:hover td{background:var(--hover)}
.njilga-table-boxed{border:1px solid var(--border);border-radius:var(--radius-card);overflow:hidden;margin-bottom:18px}
.njilga-table-compact tbody td{padding:8px 12px}
.njilga-table-compact thead th{padding:8px 12px}
.njilga-col-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.njilga-col-check{width:40px}
.njilga-col-actions{white-space:nowrap}
.njilga-col-expand{width:44px;text-align:center}
.njilga-col-center{text-align:center}
.njilga-emptyrow td{color:var(--muted-fg);font-style:italic}
.njilga-rowhead{vertical-align:top!important;background:var(--bg)}
.njilga-newrow td{background:var(--muted)}

/* Key/value table (Environment, Shortcodes). */
.njilga-kv th{width:300px;text-align:left;font-weight:500;color:var(--fg)}

/* --- Badges & status ----------------------------------------------------- */
.njilga-badge{display:inline-flex;align-items:center;padding:2px 9px;border-radius:999px;
  font-size:11.5px;font-weight:600;line-height:1.5;border:1px solid transparent;white-space:nowrap}
.njilga-badge-success{background:var(--success-bg);color:var(--success-fg);border-color:var(--success-bd)}
.njilga-badge-info{background:var(--info-bg);color:var(--info-fg);border-color:var(--info-bd)}
.njilga-badge-warning{background:var(--warn-bg);color:var(--warn-fg);border-color:var(--warn-bd)}
.njilga-badge-destructive{background:var(--danger-bg);color:var(--danger-fg);border-color:var(--danger-bd)}
.njilga-badge-muted{background:var(--muted);color:var(--muted-fg);border-color:var(--border)}
.njilga-badge-outline{background:var(--bg);color:var(--muted-fg);border-color:var(--border)}

.njilga-status{font-weight:600;font-size:13px}
.njilga-status-ok{color:var(--success-fg)}
.njilga-status-warn{color:var(--warn-fg)}
.njilga-status-bad{color:var(--danger-fg)}
.njilga-status-muted{color:var(--muted-fg);font-weight:400}

.njilga-valid{display:inline-flex;align-items:center;gap:6px;font-size:13px}
.njilga-valid .njilga-icon{width:15px;height:15px}
.njilga-valid-ok{color:var(--success-fg)}
.njilga-valid-warn{color:var(--warn-fg)}

.njilga-note-ok{color:var(--success-fg);font-size:11.5px;margin-top:4px}
.njilga-note-warn{color:var(--warn-fg);font-size:11.5px;margin-top:4px}
.njilga-note-bad{color:var(--danger-fg);font-size:11.5px;margin-top:4px}

/* --- Callouts ------------------------------------------------------------ */
.njilga-callout{border:1px solid var(--border);border-radius:var(--radius-control);padding:2px 14px;
  margin:12px 0;background:var(--bg)}
.njilga-callout p{margin:10px 0;font-size:13.5px}
.njilga-callout-success{background:var(--success-bg);border-color:var(--success-bd);color:var(--success-fg)}
.njilga-callout-info{background:var(--info-bg);border-color:var(--info-bd);color:var(--info-fg)}
.njilga-callout-warning{background:var(--warn-bg);border-color:var(--warn-bd);color:var(--warn-fg)}
.njilga-callout-error{background:var(--danger-bg);border-color:var(--danger-bd);color:var(--danger-fg)}
.njilga-callout a{color:inherit;text-decoration:underline}
.njilga-list{list-style:disc;padding-left:22px;margin:6px 0;font-size:13.5px}
.njilga-list li{margin:3px 0}

/* --- Disclosure ---------------------------------------------------------- */
.njilga-details{margin:16px 0}
.njilga-details>summary{display:inline-flex;align-items:center;gap:7px;cursor:pointer;font-size:13.5px;
  font-weight:500;color:var(--info-fg);list-style:none;padding:4px 0}
.njilga-details>summary::-webkit-details-marker{display:none}
.njilga-details>summary .njilga-icon{width:14px;height:14px}

/* --- Empty state --------------------------------------------------------- */
.njilga-empty{padding:52px 24px;text-align:center}
.njilga-empty-icon{display:inline-flex;align-items:center;justify-content:center;width:52px;height:52px;
  border-radius:12px;background:var(--muted);color:var(--muted-fg);margin-bottom:14px}
.njilga-empty-icon .njilga-icon{width:26px;height:26px}
.njilga-empty-title{font-size:18px;font-weight:600;margin:0 0 8px}
.njilga-empty-text{color:var(--muted-fg);font-size:13.5px;max-width:520px;margin:0 auto 18px;line-height:1.55}
.njilga-empty .njilga-actionform{display:flex;justify-content:center}

/* --- Danger zone --------------------------------------------------------- */
.njilga-danger-card{background:var(--danger-bg);border:1px solid var(--danger-bd);
  border-radius:var(--radius-card);padding:18px 20px;margin:24px 0;max-width:900px}
.njilga-danger-card p{margin:0 0 12px;font-size:13.5px}
.njilga-danger-head{display:flex;align-items:center;gap:9px;color:var(--danger-fg);margin-bottom:8px}
.njilga-danger-head h2{margin:0;font-size:17px;font-weight:600}
.njilga-danger-head .njilga-icon{width:19px;height:19px}
.njilga-ack{display:flex;align-items:center;gap:9px;margin:14px 0;font-size:13.5px}
.njilga-confirm-actions{display:flex;gap:10px;flex-wrap:wrap}
.njilga-confirm-form{margin-top:16px}

/* --- Data-table workspace (Invoicing, Payments) ---------------------------
   Row expansion, bulk bar, client-side pagination. Shared by any list
   screen built on this pattern (Invoicing, Payments). */
/* A wrapping row of small chips inside one table cell (Payments' by-firm/
   by-member year chips) — flex + wrap rather than relying on inline-flex
   badges to wrap unassisted inside a table cell. */
.njilga-chips{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.njilga-year{display:flex;align-items:center;gap:6px;margin:0}
.njilga-year-label{display:inline-flex;align-items:center;gap:5px;color:var(--muted-fg);
  font-size:12.5px;font-weight:500}
.njilga-bulkbar{display:flex;align-items:center;gap:14px;padding:10px 18px;background:var(--muted);
  border-top:1px solid var(--border);border-bottom:1px solid var(--border);flex-wrap:wrap}
.njilga-bulkbar-check{display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:500}
.njilga-bulkbar-sep{width:1px;height:18px;background:var(--border)}
.njilga-bulkbar-total{font-size:13px;color:var(--muted-fg)}
.njilga-bulkbar-total strong{color:var(--fg)}
.njilga-bulkbar #njilga-bulkcreate{margin-left:auto}
.njilga-firmcell{min-width:220px}
.njilga-firmname{display:inline-flex;align-items:center;gap:8px;background:none;border:none;padding:0;
  font-size:14px;font-weight:600;color:var(--fg);cursor:pointer;text-align:left;flex-wrap:wrap;
  font-family:inherit}
.njilga-firmname:hover .njilga-firm-label{text-decoration:underline}
.njilga-subline{display:block;color:var(--muted-fg);font-size:12px;margin-top:3px}
.njilga-subline-warn{color:var(--warn-fg);display:inline-flex;align-items:center;gap:4px}
.njilga-subline-warn .njilga-icon{width:13px;height:13px}
.njilga-inline-status{display:inline-flex;align-items:center;gap:6px;font-size:12.5px}
.njilga-chevron{background:none;border:none;cursor:pointer;color:var(--muted-fg);padding:6px;
  border-radius:var(--radius-control);display:inline-flex;transition:transform .18s,background .12s}
.njilga-chevron:hover{background:var(--accent);color:var(--fg)}
.njilga-row.open .njilga-chevron{transform:rotate(180deg)}

.njilga-preview>td{background:var(--hover);padding:0!important}
.njilga-preview-card{padding:18px 20px;border-top:1px dashed var(--border)}
.njilga-preview-head{display:flex;justify-content:space-between;gap:16px;margin-bottom:12px}
.njilga-preview-title{font-size:15px;font-weight:600}
.njilga-preview-sub{color:var(--muted-fg);font-size:12.5px;margin-top:2px}
.njilga-preview-note{display:flex;align-items:center;gap:7px;font-size:12.5px;color:var(--warn-fg);margin-bottom:8px}
.njilga-preview-note .njilga-icon{width:14px;height:14px}
.njilga-preview-error{color:var(--danger-fg)}
.njilga-preview-empty{margin:4px 0}
.njilga-preview-table{width:100%;max-width:640px;border-collapse:collapse;font-size:13px;
  background:var(--bg);border:1px solid var(--border);border-radius:8px;overflow:hidden}
.njilga-preview-table th{text-align:left;padding:9px 12px;color:var(--muted-fg);font-weight:500;
  font-size:12px;background:var(--muted);border-bottom:1px solid var(--border)}
.njilga-preview-table td{padding:9px 12px;border-bottom:1px solid var(--border)}
.njilga-preview-table tbody tr:last-child td{border-bottom:none}
.njilga-preview-total td{font-weight:700;background:var(--muted)}
.njilga-roster{margin-top:12px;max-width:640px}
.njilga-roster>summary{display:inline-flex;align-items:center;gap:7px;cursor:pointer;font-size:12.5px;
  font-weight:500;color:var(--info-fg);list-style:none;padding:4px 0}
.njilga-roster>summary::-webkit-details-marker{display:none}
.njilga-roster>summary .njilga-icon{width:14px;height:14px}
.njilga-roster-table{margin-top:8px}
.njilga-roster-unbilled td{color:var(--muted-fg)}

.njilga-tablefoot{display:flex;justify-content:space-between;align-items:center;gap:14px;
  padding:14px 18px;flex-wrap:wrap}
.njilga-showing{color:var(--muted-fg);font-size:12.5px}
.njilga-pagectl{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.njilga-per-label{display:inline-flex;align-items:center;gap:8px;color:var(--muted-fg);font-size:12.5px}
.njilga-pager{display:flex;gap:4px}
.njilga-pgbtn{min-width:32px;height:32px;padding:0 9px;border:1px solid var(--border);background:var(--bg);
  color:var(--fg);border-radius:var(--radius-control);font-size:12.5px;cursor:pointer;font-family:inherit}
.njilga-pgbtn:hover:not([disabled]):not(.cur){background:var(--accent)}
.njilga-pgbtn.cur{background:var(--primary);color:var(--primary-fg);border-color:var(--primary)}
.njilga-pgbtn[disabled]{opacity:.4;cursor:not-allowed}
.njilga-pgellip{display:inline-flex;align-items:center;padding:0 4px;color:var(--muted-fg)}
.njilga-noresults{padding:40px 18px;text-align:center;color:var(--muted-fg);font-size:13.5px}

/* --- Review cards (Applications) ----------------------------------------- */
.njilga-reviewcard{padding:16px 18px;background:var(--bg);border:1px solid var(--border);
  border-radius:var(--radius-card);margin-bottom:12px}
.njilga-reviewcard-top{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap}
.njilga-reviewcard-name{font-size:15px;font-weight:600}
.njilga-reviewcard-meta{margin-top:5px;color:var(--muted-fg);font-size:13px}
.njilga-reviewcard-stamp{color:var(--muted-fg);font-size:12px;white-space:nowrap;text-align:right}
.njilga-quote{margin:12px 0 0;padding:9px 13px;border-left:3px solid var(--border);
  background:var(--muted);border-radius:0 6px 6px 0;color:#3f3f46;font-size:13px}
.njilga-reviewform{margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.njilga-reviewform textarea{flex:1;min-width:260px;height:38px}
</style>
CSS;
    }
}
