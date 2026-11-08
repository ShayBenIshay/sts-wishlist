# STS Wishlist

A database-backed wishlist for WooCommerce, with AJAX add/remove and an analytics screen for the store owner.

## Important: this plugin needs theme work
It provides the data layer, endpoints and button markup, not a complete front end. Your theme must:
- Output the button with `echo sts_wishlist_button_html( $product_id );` (for example in the product card loop).
- Supply a wishlist page template that lists the products.
- Provide the heart icons the button uses: `wp-content/uploads/2025/icons/empty-heart.webp` and `filled-heart.webp`. These paths are hard-coded in `includes/functions.php`; change them to suit.

## Helper functions
`sts_wishlist_get_user_products( $user_id )`, `sts_wishlist_get_count( $user_id )`, `sts_wishlist_is_in_wishlist( $product_id, $user_id )`.

## Admin
**WooCommerce > ניתוח Wishlist** shows analytics.

## Requirements
WordPress 5.0+, WooCommerce 5.0+, PHP 7.4+.

## Status

Version 1.0.0. Provided as-is, under GPL-2.0-or-later (see `LICENSE`).

Setup, customization and support are available from [Shay Tech Solutions](https://shaytechsolutions.com).
