<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Returns the wishlist toggle button HTML for a product card.
 * Theme calls this in woocommerce/content-product.php.
 */
function sts_wishlist_button_html( $product_id ) {
    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return '';
    }

    ob_start();
    ?>
    <button class="product-card-wishlist-toggle"
            data-product-id="<?php echo esc_attr( $product_id ); ?>"
            data-product-type="<?php echo esc_attr( $product->get_type() ); ?>">
        <div class="product-card-wishlist-toggle-container">
            <img src="<?php echo esc_url( get_site_url() . '/wp-content/uploads/2025/icons/empty-heart.webp' ); ?>" alt="Empty Heart" class="product-card-wishlist-toggle-empty">
            <img src="<?php echo esc_url( get_site_url() . '/wp-content/uploads/2025/icons/filled-heart.webp' ); ?>" alt="Filled Heart" class="product-card-wishlist-toggle-filled">
        </div>
    </button>
    <?php
    return ob_get_clean();
}

/**
 * Returns product IDs in the user's wishlist.
 * Used as the existence guard in template-wishlist.php.
 */
function sts_wishlist_get_user_products( $user_id = null ) {
    if ( null === $user_id ) {
        $user_id = get_current_user_id();
    }
    return STS_Wishlist::get_instance()->get_products( $user_id );
}

/**
 * Returns the wishlist item count for a user.
 */
function sts_wishlist_get_count( $user_id = null ) {
    if ( null === $user_id ) {
        $user_id = get_current_user_id();
    }
    return count( STS_Wishlist::get_instance()->get_products( $user_id ) );
}

/**
 * Returns true if a product is in the user's wishlist.
 */
function sts_wishlist_is_in_wishlist( $product_id, $user_id = null ) {
    if ( null === $user_id ) {
        $user_id = get_current_user_id();
    }
    return in_array( absint( $product_id ), STS_Wishlist::get_instance()->get_products( $user_id ), true );
}
