<?php
/**
 * My NJILGA → Applications — the enrollment review queue (spec §10).
 */
class MyNJILGA_Page_Applications {

    const ACTION_DECIDE = 'my_njilga_application_decide';

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        MyNJILGA_Applications_Table::maybe_upgrade();

        echo '<div class="wrap"><h1>Membership Applications</h1>';

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            echo '</div>';
            return;
        }

        if ( isset( $_GET['msg'] ) ) {
            $ok   = ! empty( $_GET['ok'] );
            $text = sanitize_text_field( wp_unslash( $_GET['msg'] ) );
            printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', $ok ? 'notice-success' : 'notice-error', esc_html( $text ) );
        }

        $policy = (string) MyNJILGA_Dues_Settings::general( 'mid_year_join_policy' );
        printf(
            '<p style="color:#646970;max-width:860px">Applicants submitted through <code>[njilga_membership_application]</code> land here. They\'re FluentCRM contacts tagged <code>%s</code>, not attached to any firm, so they can\'t be invoiced until approved. On approval: attached to the firm (created if new; made Owner if the firm has none), category tag applied, then the mid-year join policy runs — currently <strong>%s</strong> (<a href="%s">change</a>).</p>',
            esc_html( (string) MyNJILGA_Dues_Settings::general( 'pending_tag' ) ),
            esc_html( MyNJILGA_Dues_Settings::join_policy_labels()[ $policy ] ?? $policy ),
            esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) )
        );

        self::render_pending();
        self::render_decided();

        echo '</div>';
    }

    private static function render_pending(): void {
        $rows = MyNJILGA_Applications_Table::get_pending();
        printf( '<h2 style="margin-top:20px">Pending review <span style="color:#888;font-weight:400;font-size:13px">(%d)</span></h2>', count( $rows ) );
        if ( empty( $rows ) ) {
            echo '<p style="color:#999;font-style:italic">No applications waiting.</p>';
            return;
        }

        foreach ( $rows as $app ) {
            $cat  = MyNJILGA_Dues_Settings::category( (string) $app->category_key );
            $firm = self::firm_label( $app );

            echo '<div style="padding:14px 16px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;margin-bottom:10px">';
            printf(
                '<div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap"><div><strong style="font-size:15px">%s %s</strong> &nbsp;<a href="mailto:%s">%s</a>%s<div style="margin-top:4px;color:#646970">Firm: %s &nbsp;·&nbsp; Category: <strong>%s</strong>%s</div></div><div style="color:#888;font-size:12px;white-space:nowrap">Submitted %s<br>#%d</div></div>',
                esc_html( $app->first_name ), esc_html( $app->last_name ),
                esc_attr( $app->email ), esc_html( $app->email ),
                $app->phone !== '' ? ' &nbsp;·&nbsp; ' . esc_html( $app->phone ) : '',
                $firm,
                esc_html( $cat ? $cat['label'] : $app->category_key ),
                $app->fluentcrm_contact_id ? sprintf( ' &nbsp;·&nbsp; <a href="%s" target="_blank" rel="noopener">FluentCRM contact #%d</a>', esc_url( admin_url( 'admin.php?page=fluentcrm-admin#/subscribers/' . (int) $app->fluentcrm_contact_id ) ), (int) $app->fluentcrm_contact_id ) : ' &nbsp;·&nbsp; <span style="color:#d63638">no FluentCRM contact (will be created on approval)</span>',
                esc_html( (string) $app->created_at ),
                (int) $app->id
            );
            if ( ! empty( $app->message ) ) {
                printf( '<blockquote style="margin:10px 0 0;padding:8px 12px;border-left:3px solid #dcdcde;color:#3c434a">%s</blockquote>', nl2br( esc_html( (string) $app->message ) ) );
            }
            printf(
                '<form method="post" action="%s" style="margin-top:12px;display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap">
                    <input type="hidden" name="action" value="%s"><input type="hidden" name="app_id" value="%d">%s
                    <textarea name="note" rows="1" placeholder="Optional note to the applicant / for the record" style="flex:1;min-width:240px"></textarea>
                    <button type="submit" name="decision" value="approve" class="button button-primary">Approve</button>
                    <button type="submit" name="decision" value="reject" class="button" style="color:#d63638;border-color:#d63638" onclick="return confirm(\'Reject this application?\')">Reject</button>
                 </form>',
                esc_url( admin_url( 'admin-post.php' ) ),
                esc_attr( self::ACTION_DECIDE ),
                (int) $app->id,
                wp_nonce_field( self::ACTION_DECIDE . '_' . (int) $app->id, '_wpnonce', true, false )
            );
            echo '</div>';
        }
    }

    private static function render_decided(): void {
        $rows = MyNJILGA_Applications_Table::get_decided( 50 );
        if ( empty( $rows ) ) {
            return;
        }
        echo '<h2 style="margin-top:28px">Recent decisions</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Applicant</th><th>Firm</th><th>Category</th><th>Decision</th><th>By</th><th>On</th><th>Note</th></tr></thead><tbody>';
        foreach ( $rows as $app ) {
            $cat  = MyNJILGA_Dues_Settings::category( (string) $app->category_key );
            $user = $app->decided_by ? get_user_by( 'id', (int) $app->decided_by ) : null;
            printf(
                '<tr><td>%s %s<br><span style="color:#888;font-size:12px">%s</span></td><td>%s</td><td>%s</td><td><strong style="color:%s">%s</strong></td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html( $app->first_name ), esc_html( $app->last_name ), esc_html( $app->email ),
                self::firm_label( $app ),
                esc_html( $cat ? $cat['label'] : $app->category_key ),
                $app->status === MyNJILGA_Applications_Table::STATUS_APPROVED ? '#1d6f42' : '#d63638',
                esc_html( ucfirst( $app->status ) ),
                $user ? esc_html( $user->display_name ) : '—',
                esc_html( (string) $app->decided_at ),
                esc_html( (string) $app->decision_note )
            );
        }
        echo '</tbody></table>';
    }

    private static function firm_label( object $app ): string {
        if ( $app->fluentcrm_company_id && MyNJILGA_Members_Data::companies_module_active() ) {
            $c = \FluentCrm\App\Models\Company::find( (int) $app->fluentcrm_company_id );
            if ( $c ) {
                return esc_html( (string) $c->name ) . ' <span style="color:#888;font-size:12px">(existing)</span>';
            }
        }
        return esc_html( (string) $app->new_company_name ) . ' <span style="display:inline-block;padding:0 6px;border-radius:10px;font-size:11px;font-weight:600;color:#fff;background:#b26200">NEW FIRM</span>';
    }

    public static function handle_decide(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        $appId = (int) ( $_POST['app_id'] ?? 0 );
        check_admin_referer( self::ACTION_DECIDE . '_' . $appId );

        $decision = sanitize_key( $_POST['decision'] ?? '' );
        $note     = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

        if ( $decision === 'approve' ) {
            $r = MyNJILGA_Application_Review::approve( $appId, get_current_user_id(), $note );
        } elseif ( $decision === 'reject' ) {
            $r = MyNJILGA_Application_Review::reject( $appId, get_current_user_id(), $note );
        } else {
            $r = [ 'ok' => false, 'message' => 'Unknown decision.' ];
        }

        wp_safe_redirect( add_query_arg( [ 'msg' => rawurlencode( $r['message'] ), 'ok' => $r['ok'] ? 1 : 0 ], MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_APPLICATIONS ) ) );
        exit;
    }
}
