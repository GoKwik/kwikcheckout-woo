jQuery(document).ready(function () {
    function updateCodLabels() {
        var codEnableDisable = jQuery("#wc_settings_gokwik_cod_enable_disable").val();
        var actionText = codEnableDisable === "enable" ? "Enable" : "Disable";
        jQuery("#wc_settings_gokwik_cod_products").closest("tr").find("label").text(actionText + " COD for Selected Products");
        jQuery("#wc_settings_gokwik_cod_categories").closest("tr").find("label").text(actionText + " COD for Selected Categories");
    }

    function toggleSettings(settingId, rows) {
        var isEnabled = jQuery(settingId).is(":checked");
        rows.forEach(function (row) {
            if (isEnabled) {
                jQuery(row).closest("tr").fadeIn("fast");
            } else {
                jQuery(row).closest("tr").fadeOut("fast");
            }
        });
    }

    function toggleCouponSettings() {
        toggleSettings("#wc_settings_gokwik_section_show_coupons_list", [
            "#wc_settings_gokwik_selected_coupons",
            "#wc_settings_gokwik_show_valid_coupons_only",
            "#wc_settings_gokwik_show_user_specific_coupons"
        ]);
    }

    function toggleCodExtraFeesSettings() {
        toggleSettings("#wc_settings_gokwik_enable_cod_extra_fees", [
            "#wc_settings_gokwik_cod_extra_fees",
            "#wc_settings_gokwik_cod_extra_fees_type",
            "#wc_settings_gokwik_cod_min_cart_value_fees",
            "#wc_settings_gokwik_cod_max_cart_value_fees"
        ]);
    }

    function toggleCodDiscountSettings() {
        toggleSettings("#wc_settings_gokwik_enable_cod_discount", [
            "#wc_settings_gokwik_cod_discount",
            "#wc_settings_gokwik_cod_discount_type",
            "#wc_settings_gokwik_cod_min_cart_value_discount",
            "#wc_settings_gokwik_cod_max_cart_value_discount"
        ]);
    }

    function togglePrepaidExtraFeesSettings() {
        toggleSettings("#wc_settings_gokwik_enable_prepaid_extra_fees", [
            "#wc_settings_gokwik_prepaid_extra_fees",
            "#wc_settings_gokwik_prepaid_extra_fees_type",
            "#wc_settings_gokwik_prepaid_min_cart_value_fees",
            "#wc_settings_gokwik_prepaid_max_cart_value_fees"
        ]);
    }

    function togglePrepaidDiscountSettings() {
        toggleSettings("#wc_settings_gokwik_enable_prepaid_discount", [
            "#wc_settings_gokwik_prepaid_discount",
            "#wc_settings_gokwik_prepaid_discount_type",
            "#wc_settings_gokwik_prepaid_min_cart_value_discount",
            "#wc_settings_gokwik_prepaid_max_cart_value_discount"
        ]);
    }

    function toggleCheckoutSettings() {
        toggleSettings("#wc_settings_gokwik_section_enable_checkout", [
            "#wc_settings_gokwik_section_enable_checkout_from_cart_page",
            "#wc_settings_gokwik_section_enable_checkout_from_side_cart",
            "#wc_settings_gokwik_section_overwrite_native_checkout_page",
            "#wc_settings_gokwik_section_register_after_checkout",
            "#wc_settings_gokwik_section_enable_buy_now_button"
        ]);
    }

    jQuery("#wc_settings_gokwik_cod_products").select2({
        multiple: true,
        ajax: {
            url: gokwikAdmin.ajaxurl,
            dataType: "json",
            delay: 300,
            data: function (params) {
                return {
                    action: "search_products",
                    q: params.term,
                    security: gokwikAdmin.product_nonce
                };
            },
            processResults: function (data) {
                if (data.success) {
                    return {
                        results: data.data
                    };
                } else {
                    return {
                        results: []
                    };
                }
            },
            transport: function (params, success, failure) {
                var request = jQuery.ajax(params);
                request.then(success);
                request.fail(function (jqXHR, textStatus) {
                    if (textStatus !== 'abort') {
                        failure();
                    }
                });
                return request;
            },
            cache: true
        },
        minimumInputLength: 3,
        templateResult: function (data) {
            if (data.loading) {
                return data.text;
            }
            return jQuery("<span>" + data.text + "</span>");
        },
        templateSelection: function (data) {
            return data.text;
        }
    });

    jQuery("#wc_settings_gokwik_selected_coupons").select2({
        multiple: true,
        ajax: {
            url: gokwikAdmin.ajaxurl,
            dataType: "json",
            delay: 300,
            data: function (params) {
                return {
                    action: "search_coupons",
                    q: params.term,
                    security: gokwikAdmin.nonce
                };
            },
            processResults: function (data) {
                if (data.success) {
                    return {
                        results: data.data
                    };
                } else {
                    return {
                        results: []
                    };
                }
            },
            transport: function (params, success, failure) {
                var request = jQuery.ajax(params);
                request.then(success);
                request.fail(function (jqXHR, textStatus) {
                    if (textStatus !== 'abort') {
                        failure();
                    }
                });
                return request;
            },
            cache: true
        },
        minimumInputLength: 3,
        templateResult: function (data) {
            if (data.loading) {
                return data.text;
            }
            return jQuery("<span>" + data.text + "</span>");
        },
        templateSelection: function (data) {
            return data.text;
        }
    });

    var selectedCoupons = gokwikAdmin.selectedCoupons || [];
    if (selectedCoupons.length > 0) {
        var $select = jQuery("#wc_settings_gokwik_selected_coupons");
        selectedCoupons.forEach(function (coupon) {
            var option = new Option(coupon.text, coupon.id, true, true);
            $select.append(option).trigger("change");
        });
    }

    var selectedProducts = gokwikAdmin.selectedProducts || [];
    if (selectedProducts.length > 0) {
        var $select = jQuery("#wc_settings_gokwik_cod_products");
        selectedProducts.forEach(function (product) {
            var option = new Option(product.text, product.id, true, true);
            $select.append(option).trigger("change");
        });
    }

    const settingsActions = [
        { selector: "#wc_settings_gokwik_cod_enable_disable", action: updateCodLabels },
        { selector: "#wc_settings_gokwik_section_show_coupons_list", action: toggleCouponSettings },
        { selector: "#wc_settings_gokwik_enable_cod_extra_fees", action: toggleCodExtraFeesSettings },
        { selector: "#wc_settings_gokwik_enable_cod_discount", action: toggleCodDiscountSettings },
        { selector: "#wc_settings_gokwik_enable_prepaid_extra_fees", action: togglePrepaidExtraFeesSettings },
        { selector: "#wc_settings_gokwik_enable_prepaid_discount", action: togglePrepaidDiscountSettings },
        { selector: "#wc_settings_gokwik_section_enable_checkout", action: toggleCheckoutSettings }
    ];

    settingsActions.forEach(({ selector, action }) => {
        action();
        jQuery(selector).on("change", action);
    });

    const cartPageCheckbox = jQuery("#wc_settings_gokwik_section_enable_checkout_from_cart_page");
    const sideCartCheckbox = jQuery("#wc_settings_gokwik_section_enable_checkout_from_side_cart");
    const checkoutCheckbox = jQuery("#wc_settings_gokwik_section_enable_checkout");

    function updateCheckoutCheckbox() {
        const isCartChecked = cartPageCheckbox.is(":checked");
        const isSideCartChecked = sideCartCheckbox.is(":checked");
        checkoutCheckbox.prop("checked", isCartChecked || isSideCartChecked);
    }

    function updateCartAndSideCartCheckboxes() {
        const isCheckoutEnabled = checkoutCheckbox.is(":checked");
        cartPageCheckbox.add(sideCartCheckbox).prop("checked", isCheckoutEnabled);
    }

    cartPageCheckbox.add(sideCartCheckbox).on("change", updateCheckoutCheckbox).trigger("change");
    checkoutCheckbox.on("change", updateCartAndSideCartCheckboxes);

    jQuery("#wc_settings_gokwik_section_app_secret").hover(
        function () {
            jQuery(this).attr("type", "text");
        },
        function () {
            jQuery(this).attr("type", "password");
        }
    );
});