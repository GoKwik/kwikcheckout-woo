jQuery(document).ready(function () {

    function toggleSettings(settingId, rows) {
        var isEnabled = jQuery(settingId).is(":checked");
        rows.forEach(function (row) {
            var rowElem = jQuery(row).closest("tr");
            if (isEnabled) {
                rowElem.fadeIn("fast");
            } else {
                rowElem.fadeOut("fast");
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

    function toggleCheckoutSettings() {
        toggleSettings("#wc_settings_gokwik_section_enable_checkout", [
            "#wc_settings_gokwik_section_enable_checkout_from_cart_page",
            "#wc_settings_gokwik_section_enable_checkout_from_side_cart",
            "#wc_settings_gokwik_section_overwrite_native_checkout_page",
            "#wc_settings_gokwik_section_register_after_checkout",
            "#wc_settings_gokwik_section_enable_buy_now_button"
        ]);
    }

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
                    return { results: data.data };
                } else {
                    return { results: [] };
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
        var selectElem = jQuery("#wc_settings_gokwik_selected_coupons");
        selectedCoupons.forEach(function (coupon) {
            var option = new Option(coupon.text, coupon.id, true, true);
            selectElem.append(option).trigger("change");
        });
    }

    var settingsActions = [
        {
            selector: "#wc_settings_gokwik_section_show_coupons_list",
            action: toggleCouponSettings
        },
        {
            selector: "#wc_settings_gokwik_section_enable_checkout",
            action: toggleCheckoutSettings
        }
    ];

    settingsActions.forEach(function (item) {
        item.action();
        jQuery(item.selector).on("change", item.action);
    });

    var cartPageCheckbox = jQuery("#wc_settings_gokwik_section_enable_checkout_from_cart_page");
    var sideCartCheckbox = jQuery("#wc_settings_gokwik_section_enable_checkout_from_side_cart");
    var checkoutCheckbox = jQuery("#wc_settings_gokwik_section_enable_checkout");

    function updateCheckoutCheckbox() {
        var isCartChecked = cartPageCheckbox.is(":checked");
        var isSideCartChecked = sideCartCheckbox.is(":checked");
        checkoutCheckbox.prop("checked", isCartChecked || isSideCartChecked);
    }

    function updateCartAndSideCartCheckboxes() {
        var isCheckoutEnabled = checkoutCheckbox.is(":checked");
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

    jQuery(".gokwik-password-toggle").on("click", function () {
        var inputElem = jQuery(this).siblings("input");
        if (inputElem.attr("type") === "password") {
            inputElem.attr("type", "text");
        } else {
            inputElem.attr("type", "password");
        }
    });

    var syncBtn = jQuery('#gokwik-sync-products-now');
    var resultDiv = jQuery('#gokwik-sync-products-result');

    function setSyncBtnDisabled(disabled) {
        if (disabled) {
            syncBtn.addClass('gokwik-sync-disabled')
                .attr('aria-disabled', 'true')
                .attr('tabindex', '-1');
            syncBtn.text('Syncing...');
        } else {
            syncBtn.removeClass('gokwik-sync-disabled')
                .removeAttr('aria-disabled')
                .removeAttr('tabindex');
            syncBtn.text('Sync Products Now');
        }
    }

    if (
        typeof gokwikSyncStatus !== 'undefined' &&
        (gokwikSyncStatus.isSyncing || gokwikSyncStatus.isScheduled)
    ) {
        setSyncBtnDisabled(true);
    } else {
        setSyncBtnDisabled(false);
    }

    syncBtn.on('click', function (e) {
        if (syncBtn.hasClass('gokwik-sync-disabled')) {
            e.preventDefault();
            return false;
        }
        setSyncBtnDisabled(true);
        setTimeout(function () {
            window.location.reload();
        }, 60000);
    });

});