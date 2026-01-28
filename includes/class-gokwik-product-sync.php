<?php
namespace Gokwik_Checkout\Inc;

if (! defined('ABSPATH')) {
    exit;
}

class GokwikProductSync
{
    const SYNC_FLAG_OPTION = 'gokwik_product_sync_done';
    const SYNC_TRANSIENT   = 'gokwik_product_sync_running';
    const API_URL = GOKWIKCHECKOUT_API_BASE_URL . 'v3/product/update-product-details';
    const BATCH_SIZE       = 100;
    const TRANSIENT_TTL    = 600;

    /**
     * Sync all WooCommerce product categories (collections) with the backend.
     *
     * @return array [success, message, total_synced, total_attempted, errors]
     */
    public static function sync_all_categories()
    {
        $logger = class_exists('WC_Logger') ? wc_get_logger() : null;
        $log_context = ['source' => 'gokwik-category-sync'];
        $api_url = GOKWIKCHECKOUT_API_BASE_URL . 'v3/collection/update-collection';
        $app_id = get_option('wc_settings_gokwik_section_app_id');
        $app_secret = get_option('wc_settings_gokwik_section_app_secret');
        $shop_url_host = parse_url(get_site_url(), PHP_URL_HOST);
        $shop_url_scheme = parse_url(get_site_url(), PHP_URL_SCHEME);
        $headers = [
            'Content-Type' => 'application/json',
        ];
        if (!empty($shop_url_host) && !empty($shop_url_scheme)) {
            $headers['x_merchant_shop_domain'] = $shop_url_scheme . '://' . $shop_url_host;
        }
        if (!empty($app_id) && !empty($app_secret)) {
            $headers['gk-app-id'] = $app_id;
            $headers['gk-app-secret'] = $app_secret;
        }
        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ]);
        $total_synced = 0;
        $total_attempted = 0;
        $errors = [];
        if ($logger) {
            $logger->info('Starting category sync...', $log_context);
        }
        foreach ($categories as $cat) {
            $total_attempted++;
            $product_ids = get_posts([
                'post_type' => 'product',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'tax_query' => [
                    [
                        'taxonomy' => 'product_cat',
                        'field' => 'term_id',
                        'terms' => $cat->term_id,
                    ],
                ],
            ]);
            // Format category title with parent name for subcategories
            $category_title = $cat->name;
            if ($cat->parent && $cat->parent > 0) {
                $parent_term = get_term($cat->parent, 'product_cat');
                if ($parent_term && !is_wp_error($parent_term)) {
                    $category_title = $parent_term->name . ' - ' . $cat->name;
                }
            }
            
            $payload = [
                'id' => (string) $cat->term_id,
                'merchant_id' => GOKWIKCHECKOUT_MERCHANT_ID,
                'handle' => $cat->slug,
                'title' => $category_title,
                'product_ids' => array_map('strval', $product_ids),
                'admin_graphql_api_id' => '',
                'published_scope' => '',
                'template_suffix' => '',
                'sort_order' => '',
                'published_at' => '',
                'body_html' => $cat->description ?: '',
            ];
            $body_json = wp_json_encode($payload);
            $cat_headers = $headers;
            if (!empty($app_secret) && !empty($body_json)) {
                $hmac = base64_encode(hash_hmac('sha256', $body_json, $app_secret, true));
                $cat_headers['x_merchant_hmac_sha256'] = $hmac;
            }
            if ($logger) {
                $logger->info('POST payload for category ' . $cat->term_id . ': ' . $body_json, $log_context);
            }
            $response = wp_remote_post($api_url, [
                'method' => 'POST',
                'body' => $body_json,
                'headers' => $cat_headers,
                'timeout' => 30,
            ]);
            if (is_wp_error($response)) {
                $errors[] = [
                    'category_id' => $cat->term_id,
                    'message' => $response->get_error_message(),
                ];
                if ($logger) {
                    $logger->error('Category sync failed for ' . $cat->term_id . ': ' . $response->get_error_message(), $log_context);
                }
                continue;
            }
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            if ($code >= 200 && $code < 300) {
                $total_synced++;
                if ($logger) {
                    $logger->info('Category ' . $cat->term_id . ' synced successfully.', $log_context);
                }
            } else {
                $errors[] = [
                    'category_id' => $cat->term_id,
                    'message' => $body,
                ];
                if ($logger) {
                    $logger->error('Category sync failed for ' . $cat->term_id . ': ' . $body, $log_context);
                }
            }
        }
        $summary = 'Category sync complete. Attempted: ' . $total_attempted . ', Successful: ' . $total_synced;
        if (!empty($errors)) {
            $summary .= ', Errors: ' . count($errors);
        }
        if ($logger) {
            $logger->info($summary, $log_context);
        }
        return [
            $total_synced === $total_attempted,
            $summary,
            'total_synced' => $total_synced,
            'total_attempted' => $total_attempted,
            'errors' => $errors,
        ];
    }

    /**
     * Sync all WooCommerce products with the backend in batches, robust and resumable.
     *
     * @param bool $force Force sync even if already run.
     * @param int $delay_ms Delay between requests in milliseconds.
     * @return array [success, message, total_synced, total_attempted, errors]
     */
    public static function sync_all_products($force = false, $delay_ms = 100)
    {
        $progress_key = 'gokwik_product_sync_progress';
        $logger       = class_exists('WC_Logger') ? wc_get_logger() : null;
        $log_context  = ['source' => 'gokwik-product-sync'];

        if (! $force && get_transient(self::SYNC_TRANSIENT)) {
            if ($logger) {
                $logger->warning('Sync already running. Aborting new sync request.', $log_context);
            }
            return [false, 'Sync already running.'];
        }
        set_transient(self::SYNC_TRANSIENT, 1, self::TRANSIENT_TTL);
        $total_synced    = 0;
        $total_attempted = 0;
        $errors          = [];
        $paged           = 1;
        $synced_ids      = get_transient($progress_key) ?: [];
        try {
            if ($logger) {
                $logger->info('Starting product sync...', $log_context);
            }
            // Sync categories first
            self::sync_all_categories();
            do {
                $args = [
                    'status' => ['publish', 'private', 'draft', 'pending', 'future'],
                    'limit'  => self::BATCH_SIZE,
                    'page'   => $paged,
                    'type'   => ['simple', 'variable'],
                ];
                $products = wc_get_products($args);
                if (empty($products)) {
                    if ($logger) {
                        $logger->info('No more products found for sync.', $log_context);
                    }
                    break;
                }
                foreach ($products as $product) {
                    $payload = self::build_payload($product);
                    if (! empty($payload['variants']) && is_array($payload['variants'])) {
                        $product_id = $payload['id'];
                        if (in_array($product_id, $synced_ids, true)) {
                            if ($logger) {
                                $logger->info('Skipping already-synced product: ' . $product_id, $log_context);
                            }
                            continue;
                        }
                        $total_attempted++;
                        $success    = false;
                        $last_error = '';
                        if ($logger) {
                            $logger->info('Syncing product: ' . $product_id . ' (Single Attempt)', $log_context);
                        }
                        if ($logger) {
                            $logger->info('POST payload for product ' . $product_id . ': ' . wp_json_encode($payload), $log_context);
                        }
                        $result = self::post_payloads($payload);
                        if (! empty($result['success'])) {
                            $success = true;
                            if ($logger) {
                                $logger->info('Product ' . $product_id . ' synced successfully.', $log_context);
                            }
                        } else {
                            $last_error = $result['message'] ?? 'Unknown error';
                            if ($logger) {
                                $logger->error('Sync failed for product ' . $product_id . ': ' . $last_error, $log_context);
                            }
                        }
                        if ($success) {
                            $total_synced++;
                            $synced_ids[] = $product_id;
                            set_transient($progress_key, $synced_ids, self::TRANSIENT_TTL);
                        } else {
                            $errors[] = [
                                'product_id' => $product_id,
                                'message'    => $last_error,
                            ];
                            if ($logger) {
                                $logger->error('Giving up on product ' . $product_id . ' after 1 attempt.', $log_context);
                            }
                        }
                        usleep($delay_ms * 1000);
                    } elseif ($logger) {
                        $logger->warning('Skipping product with empty variants. Product ID: ' . $product->get_id(), $log_context);
                    }
                }
                $paged++;
            } while (count($products) === self::BATCH_SIZE);
            update_option(self::SYNC_FLAG_OPTION, 1);
            delete_transient($progress_key);
            $summary = 'Sync complete. Attempted: ' . $total_attempted . ', Successful: ' . $total_synced;
            if (! empty($errors)) {
                $summary .= ', Errors: ' . count($errors);
            }
            if ($logger) {
                $logger->info($summary, $log_context);
            }
            // Always update last sync time after sync completes
            update_option('gokwik_product_sync_last_time', current_time('timestamp'));
            return [
                $total_synced === $total_attempted,
                $summary,
                'total_synced'    => $total_synced,
                'total_attempted' => $total_attempted,
                'errors'          => $errors,
            ];
        } finally {
            delete_transient(self::SYNC_TRANSIENT);
            if ($logger) {
                $logger->info('Product sync finished. Transient cleared.', $log_context);
            }
        }
    }

    /**
     * Sync a single product (on create/update/delete).
     *
     * @param int $product_id
     * @param string $action 'create'|'update'|'delete'
     * @return void
     */
    public static function sync_single_product($product_id, $action = 'update')
    {
        $logger  = class_exists('WC_Logger') ? wc_get_logger() : null;
        $context = ['source' => 'gokwik-product-sync'];
        $payload = null;
        if ($action === 'delete') {
            $payload = ['id' => (string) $product_id, 'deleted' => true];
            if ($logger) {
                $logger->info('Syncing single product (delete): ' . wp_json_encode($payload), $context);
            }
        } else {
            $product = wc_get_product($product_id);
            if (! $product) {
                if ($logger) {
                    $logger->warning('sync_single_product: Product not found for ID ' . $product_id, $context);
                }
                return;
            }
            $payload = self::build_payload($product);
            if (empty($payload)) {
                if ($logger) {
                    $logger->warning('sync_single_product: Empty payload for product ID ' . $product_id, $context);
                }
                return;
            }
            if ($logger) {
                $logger->info('Syncing single product (update/create): ' . wp_json_encode($payload), $context);
            }
        }
        $result = self::post_payloads($payload);
        if ($logger) {
            if (! empty($result['success'])) {
                $logger->info('Single product sync successful for ID ' . $product_id, $context);
            } else {
                $logger->error('Single product sync failed for ID ' . $product_id . ': ' . ($result['message'] ?? 'Unknown error'), $context);
            }
        }
    }

    /**
     * Build the payload for a product as per ProductDto.
     *
     * @param WC_Product $product
     * @return array
     */
    public static function build_payload($product)
    {
        $logger  = class_exists('WC_Logger') ? wc_get_logger() : null;
        $context = ['source' => 'gokwik-product-sync'];
        $id      = $product->get_id();

        if (empty($id)) {
            if ($logger) {
                $logger->warning('build_payload: Skipping product with empty id', $context);
            }
            return [];
        }

        $title                 = $product->get_name() ?: '';
        $wc_status             = $product->get_status() ?: '';
        $status                = ($wc_status === 'publish') ? 'active' : 'draft';
        $updated_at            = $product->get_date_modified() ? $product->get_date_modified()->date('c') : '';
        $body_html             = $product->get_description() ?: '';
        $handle                = $product->get_slug() ?: sanitize_title($title);
        $product_url           = get_permalink($id) ?: '';
        $product_thumbnail_url = wp_get_attachment_url($product->get_image_id()) ?: '';
        $rating                = null; // WooCommerce does not provide rating by default
        $brand_name            = $product->get_attribute('brand') ?: '';
        $occasion              = $product->get_attribute('occasion') ?: '';
        $type                  = $product->get_type() ?: '';
        $product_type          = $product->get_attribute('product_type') ?: '';
        $material              = $product->get_attribute('material') ?: '';
        $set_of                = $product->get_attribute('set_of') ?: '';
        $country_of_origin     = $product->get_attribute('country_of_origin') ?: '';
        $essentials            = $product->get_attribute('essentials') ?: '';
        $category              = $product->get_attribute('category') ?: '';
        $sub_category          = $product->get_attribute('sub_category') ?: '';
        $target_group          = $product->get_attribute('target_group') ?: '';
        $categories            = null; // Could be built from product categories if needed
        $line_id               = $product->get_attribute('line_id') ?: '';
        $visibility            = $product->get_catalog_visibility() ?: '';
        $is_active             = $status === 'active';
        $is_deleted            = $wc_status === 'trash';
        $metadata              = null; // Add logic if you have custom metadata
        $tags                  = implode(',', (array) wp_get_post_terms($id, 'product_tag', ['fields' => 'names']));
        $options               = self::get_options($product);
        $images                = self::get_images($product);
        $image                 = ! empty($images) ? $images[0] : null;
        $variants              = self::get_variants($product);
        $published_at          = $product->get_date_created() ? $product->get_date_created()->date('c') : '';

        if (empty($variants)) {
            if ($logger) {
                $logger->warning('build_payload: Skipping product with empty variants. Product ID: ' . $id, $context);
            }
            return [];
        }
        if (empty($handle)) {
            if ($logger) {
                $logger->warning('build_payload: Skipping product with empty handle. Product ID: ' . $id, $context);
            }
            return [];
        }

        $payload = [
            'id'                    => (string) $id,
            'title'                 => $title,
            'product_url'           => $product_url,
            'product_thumbnail_url' => $product_thumbnail_url,
            'rating'                => is_null($rating) ? null : floatval($rating),
            'brand_name'            => $brand_name,
            'occasion'              => $occasion,
            'type'                  => $type,
            'product_type'          => $product_type,
            'material'              => $material,
            'set_of'                => $set_of,
            'country_of_origin'     => $country_of_origin,
            'essentials'            => $essentials,
            'category'              => $category,
            'sub_category'          => $sub_category,
            'target_group'          => $target_group,
            'categories'            => $categories,
            'line_id'               => $line_id,
            'variants'              => $variants,
            'visibility'            => $visibility,
            'status'                => $status,
            'is_active'             => (bool) $is_active,
            'is_deleted'            => (bool) $is_deleted,
            'metadata'              => $metadata,
            'tags'                  => $tags,
            'options'               => is_array($options) ? $options : [],
            'updated_at'            => $updated_at,
            'handle'                => $handle,
            'images'                => is_array($images) ? $images : [],
            'image'                 => $image,
            'body_html'             => $body_html,
        ];

        if ($logger) {
            $logger->info('build_payload output: ' . wp_json_encode($payload), $context);
        }
        return $payload;
    }

    /**
     * Get all variants for a product.
     *
     * @param WC_Product $product
     * @return array
     */
    public static function get_variants($product)
    {
        $logger   = class_exists('WC_Logger') ? wc_get_logger() : null;
        $context  = ['source' => 'gokwik-product-sync'];
        $variants = [];

        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $child_id) {
                $variant = wc_get_product($child_id);
                if ($variant) {
                    $variant_payload = self::variant_payload($variant);
                    if (! empty($variant_payload['id']) && is_string($variant_payload['id'])) {
                        $variants[] = $variant_payload;
                    } elseif ($logger) {
                        $logger->warning('get_variants: Skipping variant with empty or invalid id. Variant: ' . print_r($variant_payload, true), $context);
                    }
                }
            }
        } elseif ($product->is_type('simple')) {
            $variant_payload = self::variant_payload($product);
            if (! empty($variant_payload['id']) && is_string($variant_payload['id'])) {
                $variants[] = $variant_payload;
            } elseif ($logger) {
                $logger->warning('get_variants: Skipping simple product variant with empty or invalid id. Variant: ' . print_r($variant_payload, true), $context);
            }
        }
        return $variants;
    }

    /**
     * Build payload for a single variant as per VariantDto.
     *
     * @param WC_Product $variant
     * @return array
     */
    public static function variant_payload($variant)
    {
        $id                 = $variant->get_id();
        $product_id         = (string) ($variant->get_parent_id() ?: $id);
        $thumbnail_url      = wp_get_attachment_url($variant->get_image_id()) ?: '';
        $product_url        = get_permalink($product_id) ?: '';
        $title              = $variant->get_name() ?: '';
        $color              = $variant->get_attribute('color') ?: '';
        $size               = $variant->get_attribute('size') ?: '';
        $weight             = $variant->get_weight() !== '' ? (float) $variant->get_weight() : null;
        $length             = $variant->get_length() !== '' ? (float) $variant->get_length() : null;
        $height             = $variant->get_height() !== '' ? (float) $variant->get_height() : null;
        $width              = $variant->get_width() !== '' ? (float) $variant->get_width() : null;
        $mrp                = $variant->get_regular_price() !== '' ? (float) $variant->get_regular_price() : null;
        $price              = $variant->get_price() !== '' ? (float) $variant->get_price() : null;
        $discount           = ($mrp && $price && $mrp > 0) ? round((($mrp - $price) / $mrp) * 100) : 0;
        $weight_unit        = get_option('woocommerce_weight_unit', 'kg');
        $requires_shipping  = $variant->needs_shipping();
        $inventory_item_id  = $variant->get_id();
        $inventory_policy   = $variant->backorders_allowed() ? 'continue' : 'deny';
        $inventory_quantity = $variant->get_stock_quantity() !== null ? (string) $variant->get_stock_quantity() : '';
        $compare_at_price   = $variant->get_regular_price() !== '' ? (float) $variant->get_regular_price() : null;
        $sku                = $variant->get_sku() ?: '';

        $payload = [
            'id'                 => (string) $id,
            'product_id'         => $product_id,
            'thumbnail_url'      => $thumbnail_url,
            'product_url'        => $product_url,
            'title'              => $title,
            'color'              => $color,
            'size'               => $size,
            'weight'             => $weight,
            'length'             => $length,
            'height'             => $height,
            'width'              => $width,
            'mrp'                => $mrp,
            'price'              => $price,
            'discount'           => $discount,
            'weight_unit'        => $weight_unit,
            'requires_shipping'  => (bool) $requires_shipping,
            'inventory_item_id'  => (string) $inventory_item_id,
            'inventory_policy'   => $inventory_policy,
            'inventory_quantity' => $inventory_quantity,
            'compare_at_price'   => $compare_at_price,
            'sku'                => $sku,
        ];

        foreach ($payload as $key => $value) {
            if ($value === '' || $value === null) {
                if (in_array($key, ['id', 'product_id'], true)) {
                    continue;
                }
                unset($payload[$key]);
            }
        }
        return $payload;
    }

    /**
     * Get product options (attributes).
     *
     * @param WC_Product $product
     * @return array
     */
    public static function get_options($product)
    {
        $options    = [];
        $attributes = $product->get_attributes();
        $position   = 1;
        foreach ($attributes as $attr) {
            $values    = $attr->get_options();
            $options[] = [
                'name'       => wc_attribute_label($attr->get_name()),
                'id'         => $attr->get_id() ?: $position,
                'product_id' => (string) $product->get_id(),
                'position'   => $position++,
                'values'     => $values,
            ];
        }
        return $options;
    }

    /**
     * Get all images for a product.
     *
     * @param WC_Product $product
     * @return array
     */
    public static function get_images($product)
    {
        $images  = [];
        $main_id = $product->get_image_id();
        if ($main_id) {
            $images[] = self::image_payload($main_id, $product->get_id(), 1);
        }
        $gallery_ids = $product->get_gallery_image_ids();
        $pos         = 2;
        foreach ($gallery_ids as $img_id) {
            $images[] = self::image_payload($img_id, $product->get_id(), $pos++);
        }
        return $images;
    }

    /**
     * Build payload for a single image as per ImageDto.
     *
     * @param int $img_id
     * @param int $product_id
     * @param int $position
     * @return array
     */
    public static function image_payload($img_id, $product_id, $position)
    {
        $src                  = wp_get_attachment_url($img_id) ?: '';
        $meta                 = wp_get_attachment_metadata($img_id);
        $created_at           = get_post_field('post_date', $img_id) ? date('c', strtotime(get_post_field('post_date', $img_id))) : '';
        $updated_at           = get_post_field('post_modified', $img_id) ? date('c', strtotime(get_post_field('post_modified', $img_id))) : '';
        $alt                  = get_post_meta($img_id, '_wp_attachment_image_alt', true) ?: '';
        $width                = isset($meta['width']) ? $meta['width'] : 0;
        $height               = isset($meta['height']) ? $meta['height'] : 0;
        $variant_ids          = [];
        $admin_graphql_api_id = '';

        $payload = [
            'id'                   => (string) $img_id,
            'product_id'           => (string) $product_id,
            'position'             => $position,
            'created_at'           => $created_at,
            'updated_at'           => $updated_at,
            'alt'                  => $alt,
            'width'                => $width,
            'height'               => $height,
            'src'                  => $src,
            'variant_ids'          => $variant_ids,
            'admin_graphql_api_id' => $admin_graphql_api_id,
        ];

        return $payload;
    }

    /**
     * POST the payload to the backend API.
     *
     * @param array $payload Single product payload (associative array)
     * @return array [success, message]
     */
    public static function post_payloads($payload)
    {
        $logger  = class_exists('WC_Logger') ? wc_get_logger() : null;
        $context = ['source' => 'gokwik-product-sync'];
        if ($logger) {
            $logger->info('Sending payload to API: ' . wp_json_encode($payload), $context);
        }

        $app_id     = get_option('wc_settings_gokwik_section_app_id');
        $app_secret = get_option('wc_settings_gokwik_section_app_secret');
        $headers    = [
            'Content-Type' => 'application/json',
        ];

        $shop_url_host = parse_url(get_site_url(), PHP_URL_HOST);
        $shop_url_scheme = parse_url(get_site_url(), PHP_URL_SCHEME);
        if (! empty($shop_url_host) && !empty($shop_url_scheme)) {
            $headers['x_merchant_shop_domain'] = $shop_url_scheme . '://' . $shop_url_host;
        }
        if (! empty($app_id) && ! empty($app_secret)) {
            $headers['gk-app-id']     = $app_id;
            $headers['gk-app-secret'] = $app_secret;
        }

        $body_json = wp_json_encode($payload);
        if (! empty($app_secret) && ! empty($body_json)) {
            $hmac                              = base64_encode(hash_hmac('sha256', $body_json, $app_secret, true));
            $headers['x_merchant_hmac_sha256'] = $hmac;
        }

        if ($logger) {
            $logger->info('Request URL ' . self::API_URL);
            $logger->info('Request headers ' . wp_json_encode($headers));
        }

        $response = wp_remote_post(self::API_URL, [
            'method'  => 'POST',
            'body'    => wp_json_encode($payload),
            'headers' => $headers,
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) {
            if ($logger) {
                $logger->error('API call error: ' . $response->get_error_message(), $context);
            }
            return ['success' => false, 'message' => $response->get_error_message()];
        }
        $code             = wp_remote_retrieve_response_code($response);
        $body             = wp_remote_retrieve_body($response);
        $response_headers = isset($response['headers']) ? $response['headers'] : [];
        if ($logger) {
            $logger->info('API response code: ' . $code, $context);
            $logger->info('API response headers: ' . wp_json_encode($response_headers), $context);
            $logger->info('API response body: ' . $body, $context);
        }

        if ($code >= 200 && $code < 300) {
            return ['success' => true, 'message' => 'Sync successful.'];
        }
        return ['success' => false, 'message' => 'Sync failed: ' . $body];
    }

    /**
     * Call the delete-product API when a product is permanently deleted.
     *
     * @param int $product_id
     * @return void
     */
    public static function delete_product_api($product_id)
    {
        $logger  = class_exists('WC_Logger') ? wc_get_logger() : null;
        $context = ['source' => 'gokwik-product-sync'];
        $delete_api_url = GOKWIKCHECKOUT_API_BASE_URL . 'v3/product/delete-product';
        $payload = [ 'id' => (string) $product_id ];

        $app_id     = get_option('wc_settings_gokwik_section_app_id');
        $app_secret = get_option('wc_settings_gokwik_section_app_secret');
        $headers    = [
            'Content-Type' => 'application/json',
        ];
        $shop_url_host = parse_url(get_site_url(), PHP_URL_HOST);
        $shop_url_scheme = parse_url(get_site_url(), PHP_URL_SCHEME);
        if (! empty($shop_url_host) && !empty($shop_url_scheme)) {
            $headers['x_merchant_shop_domain'] = $shop_url_scheme . '://' . $shop_url_host;
        }
        if (! empty($app_id) && ! empty($app_secret)) {
            $headers['gk-app-id']     = $app_id;
            $headers['gk-app-secret'] = $app_secret;
        }
        $body_json = wp_json_encode($payload);
        if (! empty($app_secret) && ! empty($body_json)) {
            $hmac                              = base64_encode(hash_hmac('sha256', $body_json, $app_secret, true));
            $headers['x_merchant_hmac_sha256'] = $hmac;
        }
        if ($logger) {
            $logger->info('Sending DELETE payload to API: ' . wp_json_encode($payload), $context);
            $logger->info('Request URL ' . $delete_api_url);
            $logger->info('Request headers ' . wp_json_encode($headers));
        }
        $response = wp_remote_post($delete_api_url, [
            'method'  => 'POST',
            'body'    => wp_json_encode($payload),
            'headers' => $headers,
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) {
            if ($logger) {
                $logger->error('Delete API call error: ' . $response->get_error_message(), $context);
            }
            return;
        }
        $code   = wp_remote_retrieve_response_code($response);
        $body   = wp_remote_retrieve_body($response);
        if ($logger) {
            $logger->info('Delete API response code: ' . $code, $context);
            $logger->info('Delete API response body: ' . $body, $context);
        }
    }

    /**
     * Call the delete-collection API when a product category is permanently deleted.
     *
     * @param int $category_id
     * @return void
     */
    public static function delete_category_api($category_id)
    {
        $logger  = class_exists('WC_Logger') ? wc_get_logger() : null;
        $context = ['source' => 'gokwik-category-sync'];
        $delete_api_url = GOKWIKCHECKOUT_API_BASE_URL . 'v3/collection/delete-collection';
        $payload = [
            'id' => (string) $category_id,
            'merchant_id' => GOKWIKCHECKOUT_MERCHANT_ID
        ];

        $app_id     = get_option('wc_settings_gokwik_section_app_id');
        $app_secret = get_option('wc_settings_gokwik_section_app_secret');
        $headers    = [
            'Content-Type' => 'application/json',
        ];
        $shop_url_host = parse_url(get_site_url(), PHP_URL_HOST);
        $shop_url_scheme = parse_url(get_site_url(), PHP_URL_SCHEME);
        if (! empty($shop_url_host) && !empty($shop_url_scheme)) {
            $headers['x_merchant_shop_domain'] = $shop_url_scheme . '://' . $shop_url_host;
        }
        if (! empty($app_id) && ! empty($app_secret)) {
            $headers['gk-app-id']     = $app_id;
            $headers['gk-app-secret'] = $app_secret;
        }
        $body_json = wp_json_encode($payload);
        if (! empty($app_secret) && ! empty($body_json)) {
            $hmac                              = base64_encode(hash_hmac('sha256', $body_json, $app_secret, true));
            $headers['x_merchant_hmac_sha256'] = $hmac;
        }
        if ($logger) {
            $logger->info('Sending DELETE CATEGORY payload to API: ' . wp_json_encode($payload), $context);
            $logger->info('Request URL ' . $delete_api_url);
            $logger->info('Request headers ' . wp_json_encode($headers));
        }
        $response = wp_remote_post($delete_api_url, [
            'method'  => 'POST',
            'body'    => wp_json_encode($payload),
            'headers' => $headers,
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) {
            if ($logger) {
                $logger->error('Delete Category API call error: ' . $response->get_error_message(), $context);
            }
            return;
        }
        $code   = wp_remote_retrieve_response_code($response);
        $body   = wp_remote_retrieve_body($response);
        if ($logger) {
            $logger->info('Delete Category API response code: ' . $code, $context);
            $logger->info('Delete Category API response body: ' . $body, $context);
        }
    }
}

add_action('save_post_product', function($post_id, $post, $update) {
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }
    \Gokwik_Checkout\Inc\GokwikProductSync::sync_single_product($post_id, $update ? 'update' : 'create');
}, 10, 3);

add_action('trashed_post', function($post_id) {
    if (get_post_type($post_id) === 'product') {
        \Gokwik_Checkout\Inc\GokwikProductSync::sync_single_product($post_id, 'delete');
    }
});

add_action('untrashed_post', function($post_id) {
    if (get_post_type($post_id) === 'product') {
        \Gokwik_Checkout\Inc\GokwikProductSync::sync_single_product($post_id, 'update');
    }
});

add_action('woocommerce_product_set_stock', function($product_with_stock) {
    if (is_a($product_with_stock, 'WC_Product')) {
        \Gokwik_Checkout\Inc\GokwikProductSync::sync_single_product($product_with_stock->get_id(), 'update');
    }
});

add_action('created_term', function($term_id, $tt_id, $taxonomy) {
    if ($taxonomy === 'product_cat') {
        \Gokwik_Checkout\Inc\GokwikProductSync::sync_all_categories();
    }
}, 10, 3);

add_action('edited_term', function($term_id, $tt_id, $taxonomy) {
    if ($taxonomy === 'product_cat') {
        \Gokwik_Checkout\Inc\GokwikProductSync::sync_all_categories();
    }
}, 10, 3);

add_action('delete_term', function($term_id, $tt_id, $taxonomy, $deleted_term) {
    if ($taxonomy === 'product_cat') {
        \Gokwik_Checkout\Inc\GokwikProductSync::delete_category_api($term_id);
    }
}, 10, 4);
