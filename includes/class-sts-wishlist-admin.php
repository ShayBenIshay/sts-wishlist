<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class STS_Wishlist_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
    }

    public function register_menu() {
        add_submenu_page(
            'woocommerce',
            'ניתוח Wishlist',
            'ניתוח Wishlist',
            'manage_woocommerce',
            'sts-wishlist-analytics',
            array( $this, 'render_page' )
        );
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    private function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'sts_wishlist';

        return $wpdb->get_row(
            "SELECT
                COUNT(*)                   AS total_entries,
                COUNT(DISTINCT user_id)    AS total_users,
                COUNT(DISTINCT product_id) AS total_products,
                MAX(date_added)            AS last_activity
            FROM {$table}"
        );
    }

    private function get_top_products( $limit = 15 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sts_wishlist';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT product_id, COUNT(*) AS wishlist_count
            FROM {$table}
            GROUP BY product_id
            ORDER BY wishlist_count DESC
            LIMIT %d",
            $limit
        ) );
    }

    private function get_recent_activity( $limit = 10 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sts_wishlist';
        $users = $wpdb->users;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT w.user_id, w.product_id, w.date_added,
                    u.display_name, u.user_email
            FROM {$table} w
            LEFT JOIN {$users} u ON w.user_id = u.ID
            ORDER BY w.date_added DESC
            LIMIT %d",
            $limit
        ) );
    }

    private function get_top_users( $limit = 10 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sts_wishlist';
        $users = $wpdb->users;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT w.user_id, COUNT(*) AS wishlist_count,
                    u.display_name, u.user_email
            FROM {$table} w
            LEFT JOIN {$users} u ON w.user_id = u.ID
            GROUP BY w.user_id
            ORDER BY wishlist_count DESC
            LIMIT %d",
            $limit
        ) );
    }

    // -------------------------------------------------------------------------
    // Render helpers
    // -------------------------------------------------------------------------

    private function stat_card( $label, $value, $sub = '' ) {
        ?>
        <div class="btwl-stat-card">
            <div class="btwl-stat-value"><?php echo esc_html( $value ); ?></div>
            <div class="btwl-stat-label"><?php echo esc_html( $label ); ?></div>
            <?php if ( $sub ) : ?>
                <div class="btwl-stat-sub"><?php echo esc_html( $sub ); ?></div>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Page
    // -------------------------------------------------------------------------

    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'אין לך הרשאה לצפות בדף זה.' );
        }

        $stats          = $this->get_stats();
        $top_products   = $this->get_top_products();
        $recent         = $this->get_recent_activity();
        $top_users      = $this->get_top_users();

        $total_entries  = intval( $stats->total_entries ?? 0 );
        $total_users    = intval( $stats->total_users ?? 0 );
        $total_products = intval( $stats->total_products ?? 0 );
        $avg_per_user   = $total_users > 0 ? round( $total_entries / $total_users, 1 ) : 0;
        $last_activity  = $stats->last_activity
            ? date_i18n( 'd/m/Y H:i', strtotime( $stats->last_activity ) )
            : '—';

        $wc_active = class_exists( 'WooCommerce' );
        ?>
        <div class="wrap" dir="rtl">
            <h1>ניתוח מועדפים</h1>
            <p class="btwl-updated">פעילות אחרונה: <strong><?php echo esc_html( $last_activity ); ?></strong></p>

            <?php $this->render_styles(); ?>

            <?php if ( $total_entries === 0 ) : ?>
                <div class="notice notice-info"><p>אין נתוני מועדפים עדיין.</p></div>
            <?php else : ?>

            <!-- כרטיסי סטטיסטיקה -->
            <div class="btwl-stats-row">
                <?php
                $this->stat_card( 'סך הכל שמירות',          number_format( $total_entries ) );
                $this->stat_card( 'משתמשים עם מועדפים',     number_format( $total_users ) );
                $this->stat_card( 'מוצרים במועדפים',        number_format( $total_products ) );
                $this->stat_card( 'ממוצע שמירות למשתמש',   $avg_per_user );
                ?>
            </div>

            <!-- המוצרים הנשמרים ביותר -->
            <h2>המוצרים הנשמרים ביותר</h2>
            <table class="wp-list-table widefat fixed striped btwl-table">
                <thead>
                    <tr>
                        <th class="btwl-col-rank">#</th>
                        <th class="btwl-col-thumb"></th>
                        <th>מוצר</th>
                        <th>קטגוריה</th>
                        <?php if ( $wc_active ) : ?><th>מחיר</th><?php endif; ?>
                        <th class="btwl-col-num">שמירות</th>
                        <th class="btwl-col-num">% ממשתמשים</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                foreach ( $top_products as $rank => $row ) :
                    $pid      = intval( $row->product_id );
                    $count    = intval( $row->wishlist_count );
                    $pct      = $total_users > 0 ? round( $count / $total_users * 100, 1 ) : 0;
                    $title    = get_the_title( $pid ) ?: '(נמחק)';
                    $edit_url = get_edit_post_link( $pid );
                    $thumb    = get_the_post_thumbnail_url( $pid, array( 44, 44 ) );

                    $categories = get_the_terms( $pid, 'product_cat' );
                    $cat_names  = '';
                    if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
                        $cat_names = implode( ', ', wp_list_pluck( $categories, 'name' ) );
                    }

                    $price_html = '';
                    if ( $wc_active ) {
                        $product    = wc_get_product( $pid );
                        $price_html = $product ? $product->get_price_html() : '—';
                    }
                    ?>
                    <tr>
                        <td class="btwl-col-rank"><?php echo esc_html( $rank + 1 ); ?></td>
                        <td class="btwl-col-thumb">
                            <?php if ( $thumb ) : ?>
                                <img src="<?php echo esc_url( $thumb ); ?>" width="44" height="44" style="object-fit:cover;border-radius:4px;">
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $edit_url ) : ?>
                                <a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $title ); ?></a>
                            <?php else : ?>
                                <?php echo esc_html( $title ); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $cat_names ?: '—' ); ?></td>
                        <?php if ( $wc_active ) : ?>
                            <td><?php echo wp_kses_post( $price_html ); ?></td>
                        <?php endif; ?>
                        <td class="btwl-col-num"><strong><?php echo esc_html( $count ); ?></strong></td>
                        <td class="btwl-col-num">
                            <div class="btwl-bar-wrap">
                                <span><?php echo esc_html( $pct ); ?>%</span>
                                <div class="btwl-bar" style="width:<?php echo esc_attr( min( $pct, 100 ) ); ?>%"></div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="btwl-two-col">

                <!-- פעילות אחרונה -->
                <div>
                    <h2>פעילות אחרונה</h2>
                    <table class="wp-list-table widefat fixed striped btwl-table">
                        <thead>
                            <tr>
                                <th>משתמש</th>
                                <th>מוצר</th>
                                <th>תאריך</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $recent as $row ) :
                            $pid      = intval( $row->product_id );
                            $title    = get_the_title( $pid ) ?: '(נמחק)';
                            $edit_url = get_edit_post_link( $pid );
                            $user_url = get_edit_user_link( intval( $row->user_id ) );
                            $user_lbl = $row->display_name ?: $row->user_email ?: 'משתמש #' . $row->user_id;
                            $date     = date_i18n( 'd/m/Y H:i', strtotime( $row->date_added ) );
                            ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url( $user_url ); ?>"><?php echo esc_html( $user_lbl ); ?></a>
                                </td>
                                <td>
                                    <?php if ( $edit_url ) : ?>
                                        <a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $title ); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html( $title ); ?>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space:nowrap;direction:ltr;text-align:right"><?php echo esc_html( $date ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- משתמשים מובילים -->
                <div>
                    <h2>משתמשים מובילים</h2>
                    <table class="wp-list-table widefat fixed striped btwl-table">
                        <thead>
                            <tr>
                                <th class="btwl-col-rank">#</th>
                                <th>משתמש</th>
                                <th class="btwl-col-num">שמירות</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $top_users as $rank => $row ) :
                            $user_url = get_edit_user_link( intval( $row->user_id ) );
                            $user_lbl = $row->display_name ?: $row->user_email ?: 'משתמש #' . $row->user_id;
                            ?>
                            <tr>
                                <td class="btwl-col-rank"><?php echo esc_html( $rank + 1 ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( $user_url ); ?>"><?php echo esc_html( $user_lbl ); ?></a>
                                    <?php if ( $row->user_email ) : ?>
                                        <br><small style="color:#666"><?php echo esc_html( $row->user_email ); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="btwl-col-num"><strong><?php echo esc_html( intval( $row->wishlist_count ) ); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </div><!-- .btwl-two-col -->

            <?php endif; ?>
        </div><!-- .wrap -->
        <?php
    }

    private function render_styles() {
        ?>
        <style>
        .btwl-updated { color: #666; margin-top: -8px; }

        .btwl-stats-row {
            display: flex;
            gap: 16px;
            margin: 20px 0 28px;
            flex-wrap: wrap;
        }
        .btwl-stat-card {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 20px 24px;
            flex: 1;
            min-width: 140px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
        }
        .btwl-stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #1d2327;
            line-height: 1;
        }
        .btwl-stat-label {
            margin-top: 6px;
            font-size: 13px;
            color: #666;
        }
        .btwl-stat-sub {
            margin-top: 2px;
            font-size: 11px;
            color: #999;
        }

        .btwl-table th, .btwl-table td { vertical-align: middle; }
        .btwl-col-rank { width: 36px; text-align: center !important; color: #999; font-weight: 600; }
        .btwl-col-thumb { width: 56px; }
        /* In RTL context numbers sit on the left (end side) */
        .btwl-col-num { width: 80px; text-align: left !important; }

        .btwl-bar-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            justify-content: flex-end;
        }
        .btwl-bar {
            height: 6px;
            background: #2271b1;
            border-radius: 3px;
            min-width: 2px;
            flex-shrink: 0;
            max-width: 60px;
        }

        .btwl-two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-top: 28px;
        }
        @media (max-width: 900px) {
            .btwl-two-col { grid-template-columns: 1fr; }
        }
        </style>
        <?php
    }
}
