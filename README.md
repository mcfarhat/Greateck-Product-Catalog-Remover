# Woo Product Remover

A WordPress / WooCommerce plugin that removes every product from your store in one click. It clears products, variations, their metadata and term relationships, and then cleans up the lookup tables WooCommerce keeps alongside them.

Orders and customers are never touched.

**Requires:** WordPress 6.5+ · PHP 7.4+ · Tested to WordPress 7.1 and WooCommerce 11

## Why

WooCommerce has no built-in way to clear a catalog, and doing it by hand is slow enough to be impractical past a few hundred products. Deleting rows straight from the database is fast, but modern WooCommerce keeps several tables keyed by product ID — miss one and deleted products keep showing up in search, filtering and reports.

This plugin does the fast thing and the complete thing at the same time.

## What it removes

Always:

- `product` and `product_variation` posts, their postmeta and term relationships
- `wc_product_meta_lookup`
- `wc_product_attributes_lookup` (matching both `product_id` and `product_or_parent_id`)
- `wc_reserved_stock`
- `wc_stock_notifications` and `wc_stock_notificationmeta`
- Product transients and the object cache

Optional, off by default:

- Product categories, tags and `pa_*` attribute terms (plus `wc_category_lookup`)
- Product images, including the files and generated thumbnails on disk
- Product reviews and their comment meta
- Customer download permissions and download logs

## What it deliberately keeps

- **Orders, customers, coupons and settings.** Never touched.
- **WooCommerce's own terms.** The `product_type` terms (simple, grouped, variable, external) and `product_visibility` terms (featured, outofstock, rated-1…5) are infrastructure that WooCommerce attaches to every product. Deleting them along with the products breaks the store, so they are always preserved.
- **The default product category.** WooCommerce needs one to fall back on.
- **Media used elsewhere.** Only attachments whose `post_parent` is a product are removed, so an image uploaded to the media library and merely referenced by a product survives.

## How it runs

Removal is batched over AJAX — 200 products per request by default — with a progress bar. Large catalogs no longer depend on one PHP request outliving the server's timeout.

## Hooks

Because deletion happens in SQL, `before_delete_post` and `woocommerce_delete_product` never fire. The plugin exposes its own instead:

| Hook | Type | Passed |
| --- | --- | --- |
| `wpr_before_delete_batch` | action | `int[]` IDs about to be deleted |
| `wpr_after_delete_batch` | action | `int[]` IDs that were deleted |
| `wpr_removal_complete` | action | final counters |
| `wpr_batch_size` | filter | products per request (default 200) |
| `wpr_product_post_types` | filter | post types treated as products |
| `wpr_term_taxonomies` | filter | taxonomies the category option clears |

```php
add_action( 'wpr_after_delete_batch', function ( $ids ) {
	// Clean up your own product-keyed tables here.
} );
```

## Install

Install from the [WordPress plugin directory](https://wordpress.org/plugins/woo-product-remover/), or drop this repository into `wp-content/plugins/woo-product-remover` and activate it.

Then go to **Woo Product Remover** in the admin menu.

## Warning

Product removal is permanent. There is no undo. Back up your database first.

## License

GPLv2 or later. By [Mohammad Farhat](https://www.greateck.com).
