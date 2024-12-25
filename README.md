# GoKwik Checkout for WooCommerce

GoKwik Checkout is a WordPress plugin designed to enhance the checkout experience for WooCommerce stores. It streamlines the process, reduces cart abandonment, and ensures a smooth transaction flow, ultimately supercharging your business with an optimized checkout experience.

## Description

GoKwik Checkout plugin integrates seamlessly with WooCommerce to provide a superior checkout experience. It offers various features such as custom checkout flows, additional payment gateway options, and improved order management. The plugin is designed to be lightweight and efficient, ensuring that it does not impact the site's performance.

## Installation

1. Download the plugin files from the GitHub repository.
2. Upload the plugin files to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen directly.
3. Activate the plugin through the 'Plugins' screen in WordPress.
4. Use the WooCommerce Settings -> GoKwik Checkout screen to configure the plugin.

## Requirements

- WordPress 5.4 or higher
- WooCommerce 4.3 or higher
- PHP 7.0 or higher

## Compatibility

- Tested up to WooCommerce version: 9.3
- Tested up to WordPress version: 6.6

## Changelog

### Version 1.0.9
- [Feature] Implemented pending order creation on address page and updated order upon successful payment.
- [Feature] Added plugin configuration to determine if all cart items or just a single item must meet COD criteria.
- [Fix] Ensured shipping options selected in the cart are retained during checkout.
- [Feature] Implemented buy-now button functionality.
- [Enhancement] Enabled passing of fbq, fbp, and external_id from frontend to backend.
- [Fix] Addressed issue where personalized text data was not updated after a pending order was created and the cart was updated.
- [Enhancement] Hid checkout button and opened native checkout for international IPs.
- [Feature] Created and exposed orderStatusUpdate API to modify order status.

### Version 1.0.5
- [Feature] Added PPCOD Support.
- [Feature] Implemented User registration after order creation.

### Version 1.0.4
- [Feature] Add support for custom-formatted coupons.
- [Feature] Show the “Freebie” tag for freebies in the cart.
- [Enhancement] Updated coupon configs.
- [Feature] Support multiple checkout buttons.
- [Feature] New Plugin settings page.

### Version 1.0.3
- [Feature] GST details should be passed on to WooCommerce along with the order details.
- [Feature] Added support for GKP side prepaid discounts.
- [Feature] Added app_id and app_secret based authentication in all APIs.
- [Enhancement] Added fallback mechanism which will open Native Checkout if GoKwik Checkout doesn’t open.
- [Feature] UTM Source is to be passed along with the Order in WooCommerce.
- [Feature] Added support for third-party Buy One Get One plugin.
- [Feature] Added support for product add-ons.
- [Feature] Added support for personalized products with custom-uploaded images.

### Version 1.0.1
- [Fix] Resolved "Coupon usage limit has been reached." issue.
- [Enhancement] Added check for old "GoKwik Payment Gateway" plugin before enabling new Checkout to avoid JS conflicts.
- [Feature] Fetch and send customer ID in getCart API if a customer exists for the provided email.
- [Feature] Added support for maximum fees/discounts and applicability criteria based on cart total.
- [Enhancement] No JS injection if checkout is disabled or on "Thank-You" page.
- [Feature] Created "get-wallet-balance" API for TerraWallet Integration.
- [Enhancement] Reset cache and return fresh cart data in Get-Cart API.
- [Enhancement] Hide default checkout form on checkout page if GoKwik checkout is enabled.

### Version 1.0.0
- Initial release of GoKwik Checkout plugin.

## Code References

- Main plugin file and constants definition:
  - `gokwik-checkout.php`

- GoKwik Prepaid Gateway class:
  - `includes/class-gokwik-prepaid-gateway.php`

- Frontend custom JavaScript:
  - `assets/js/frontend/gokwik-custom.js`

- Admin settings JavaScript:
  - `assets/js/admin/gokwik-settings.js`

- REST API endpoints for cart management:
  - `includes/api/class-gokwik-cart.php`

- Utility functions for order processing:
  - `includes/class-gokwik-utilities.php`

- Frontend custom CSS:
  - `assets/css/frontend/gokwik-custom.css`

## Contributing

Contributions are welcome from the community. Please fork the repository, make your changes, and submit a pull request.

## Support

For support, please visit [GoKwik Support](https://gokwik.co/contact) or raise an issue on the GitHub repository.

## License

The GoKwik Checkout plugin is open-source software licensed under the GPL v2.0 license.

## Author Information

The GoKwik Checkout plugin is developed and maintained by Team GoKwik, with contributions from the WordPress and WooCommerce communities.

For more information about the plugin, visit [GoKwik](https://gokwik.co).