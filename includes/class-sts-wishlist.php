<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class STS_Wishlist {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', array( $this, 'init' ) );
    }

    public function init() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return;
        }

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        add_action( 'wp_ajax_sts_wishlist_add',                array( $this, 'ajax_add' ) );
        add_action( 'wp_ajax_sts_wishlist_remove',             array( $this, 'ajax_remove' ) );
        add_action( 'wp_ajax_sts_wishlist_toggle',             array( $this, 'ajax_toggle' ) );
        add_action( 'wp_ajax_sts_wishlist_get_products',       array( $this, 'ajax_get_products' ) );
        add_action( 'wp_ajax_nopriv_sts_wishlist_get_products', array( $this, 'ajax_get_products' ) );
    }

    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p>STS Wishlist requires WooCommerce to be active.</p></div>';
    }

    // Called by register_activation_hook — idempotent, never drops data.
    public static function create_table() {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'sts_wishlist';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            date_added DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_product (user_id, product_id),
            KEY user_id (user_id),
            KEY product_id (product_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function enqueue_scripts() {
        wp_enqueue_script(
            'sts-wishlist',
            STS_WL_PLUGIN_URL . 'assets/js/wishlist.js',
            array( 'jquery' ),
            STS_WL_VERSION,
            true
        );

        wp_localize_script( 'sts-wishlist', 'wishlistData', array(
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'wishlist_nonce' ),
            'userId'     => get_current_user_id(),
            'isLoggedIn' => is_user_logged_in(),
        ) );
    }

    public function get_products( $user_id ) {
        global $wpdb;

        if ( ! is_user_logged_in() ) {
            return array();
        }

        $user_id   = absint( $user_id );
        $cache_key = 'sts_wishlist_' . $user_id;

        $cached = wp_cache_get( $cache_key, 'sts_wishlist' );
        if ( false !== $cached ) {
            return $cached;
        }

        $table_name  = $wpdb->prefix . 'sts_wishlist';
        $product_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT product_id FROM $table_name WHERE user_id = %d ORDER BY date_added DESC",
            $user_id
        ) );

        $product_ids = array_map( 'absint', $product_ids );

        wp_cache_set( $cache_key, $product_ids, 'sts_wishlist', HOUR_IN_SECONDS );

        return $product_ids;
    }

    private function add_product( $user_id, $product_id ) {
        global $wpdb;

        if ( ! is_user_logged_in() || $user_id != get_current_user_id() ) {
            return new WP_Error( 'unauthorized', 'You must be logged in to add items to wishlist' );
        }

        $table_name = $wpdb->prefix . 'sts_wishlist';
        $product_id = absint( $product_id );
        $user_id    = absint( $user_id );

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return new WP_Error( 'invalid_product', 'Product not found' );
        }

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table_name WHERE user_id = %d AND product_id = %d",
            $user_id,
            $product_id
        ) );

        if ( $exists ) {
            return new WP_Error( 'already_exists', 'Product already in wishlist' );
        }

        $result = $wpdb->insert(
            $table_name,
            array(
                'user_id'    => $user_id,
                'product_id' => $product_id,
                'date_added' => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%s' )
        );

        if ( false === $result ) {
            return new WP_Error( 'database_error', 'Failed to add product to wishlist' );
        }

        wp_cache_delete( 'sts_wishlist_' . $user_id, 'sts_wishlist' );

        return true;
    }

    private function remove_product( $user_id, $product_id ) {
        global $wpdb;

        if ( ! is_user_logged_in() || $user_id != get_current_user_id() ) {
            return new WP_Error( 'unauthorized', 'You must be logged in to remove items from wishlist' );
        }

        $table_name = $wpdb->prefix . 'sts_wishlist';
        $product_id = absint( $product_id );
        $user_id    = absint( $user_id );

        $result = $wpdb->delete(
            $table_name,
            array(
                'user_id'    => $user_id,
                'product_id' => $product_id,
            ),
            array( '%d', '%d' )
        );

        if ( false === $result ) {
            return new WP_Error( 'database_error', 'Failed to remove product from wishlist' );
        }

        wp_cache_delete( 'sts_wishlist_' . $user_id, 'sts_wishlist' );

        return true;
    }

    public function ajax_add() {
        check_ajax_referer( 'wishlist_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in to add items to wishlist' ) );
        }

        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $user_id    = get_current_user_id();

        if ( ! $product_id ) {
            wp_send_json_error( array( 'message' => 'Invalid product ID' ) );
        }

        $result = $this->add_product( $user_id, $product_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => 'Product added to wishlist',
            'count'   => count( $this->get_products( $user_id ) ),
        ) );
    }

    public function ajax_remove() {
        check_ajax_referer( 'wishlist_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in to remove items from wishlist' ) );
        }

        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $user_id    = get_current_user_id();

        if ( ! $product_id ) {
            wp_send_json_error( array( 'message' => 'Invalid product ID' ) );
        }

        $result = $this->remove_product( $user_id, $product_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => 'Product removed from wishlist',
            'count'   => count( $this->get_products( $user_id ) ),
        ) );
    }

    public function ajax_toggle() {
        check_ajax_referer( 'wishlist_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in to modify wishlist' ) );
        }

        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $user_id    = get_current_user_id();

        if ( ! $product_id ) {
            wp_send_json_error( array( 'message' => 'Invalid product ID' ) );
        }

        $is_in_wishlist = in_array( $product_id, $this->get_products( $user_id ), true );

        if ( $is_in_wishlist ) {
            $result = $this->remove_product( $user_id, $product_id );
            $action = 'removed';
        } else {
            $result = $this->add_product( $user_id, $product_id );
            $action = 'added';
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message'     => 'Product ' . $action . ' to wishlist',
            'in_wishlist' => ! $is_in_wishlist,
            'count'       => count( $this->get_products( $user_id ) ),
        ) );
    }

    public function ajax_get_products() {
        check_ajax_referer( 'wishlist_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in' ) );
        }

        $user_id     = get_current_user_id();
        $product_ids = $this->get_products( $user_id );

        if ( empty( $product_ids ) ) {
            wp_send_json_success( array( 'products' => array(), 'count' => 0 ) );
        }

        global $post, $product;
        $products = array();

        foreach ( $product_ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product || ! $product->is_visible() ) {
                continue;
            }

            $post = get_post( $product_id );
            setup_postdata( $post );

            ob_start();
            wc_get_template_part( 'content', 'product' );
            $html = ob_get_clean();

            $products[] = array(
                'id'   => $product_id,
                'html' => $html,
            );
        }

        wp_reset_postdata();

        wp_send_json_success( array(
            'products' => $products,
            'count'    => count( $products ),
        ) );
    }
}
