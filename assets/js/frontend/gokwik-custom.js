jQuery(document).ready(function () {
    let sessionIdPromise = null;
    let manualCloseInitiated = false;
    let skipGoKwikCheckout = localStorage.getItem('skipGoKwikCheckout') === 'true';

    function fetchUserSessionId() {
        if (gc_data.session_id && gc_data.session_id !== '') {
            return Promise.resolve(gc_data.session_id);
        }
        if (sessionIdPromise) {
            return sessionIdPromise;
        }
        sessionIdPromise = new Promise((resolve, reject) => {
            jQuery.ajax({
                type: 'POST',
                dataType: 'json',
                url: `${gc_data.ajaxurl}?action=gc_fetch_user_session_id`,
                data: { action: 'gc_fetch_user_session_id' }
            }).done(data => {
                resolve(data.session_id);
            }).fail((jqXHR, textStatus, errorThrown) => {
                console.error('Failed to fetch session ID:', { status: jqXHR.status, textStatus, errorThrown });
                reject(errorThrown);
            }).always(() => {
                sessionIdPromise = null;
            });
        });
        return sessionIdPromise;
    }

    function handleCheckoutFailure() {
        if (typeof gokwikSdk !== 'undefined' && typeof gokwikSdk.close === 'function') {
            manualCloseInitiated = true;
            gokwikSdk.close();
        }
        if (!gc_data.is_checkout_page) {
            skipGoKwikCheckout = true;
            localStorage.setItem('skipGoKwikCheckout', 'true');
            window.location.href = gc_data.checkout_url;
        } else {
            jQuery('#gcCustomCSS-inline-css').remove();
            jQuery('body').append('<style>.woocommerce form.woocommerce-checkout { display: block !important; }</style>');
        }
    }

    function processGoKwikCheckout(sessionId) {
        if (skipGoKwikCheckout) {
            jQuery('#gcCustomCSS-inline-css').remove();
            jQuery('body').append('<style>.woocommerce form.woocommerce-checkout { display: block !important; }</style>');
            return;
        }

        if (typeof gokwikSdk === 'undefined' || typeof gokwikSdk.initCheckout !== 'function') {
            console.error("GoKwik SDK or initCheckout function not available", { gokwikSdk, initCheckout: gokwikSdk?.initCheckout });
            handleCheckoutFailure();
            return;
        }

        const gcObj = {
            environment: gc_data.environment,
            type: "merchantInfo",
            mid: gc_data.mid,
            merchantParams: {
                merchantCheckoutId: sessionId
            }
        };

        if (!gc_data.is_cart_empty) {
            gokwikSdk.initCheckout(gcObj);
            return;
        }

        const cartItemsCookie = document.cookie.split('; ').find(row => row.startsWith('woocommerce_items_in_cart='));

        if (cartItemsCookie && parseInt(cartItemsCookie.split('=')[1]) > 0) {
            gokwikSdk.initCheckout(gcObj);
        } else {
            jQuery.ajax({
                type: 'POST',
                dataType: 'json',
                url: `${gc_data.ajaxurl}?action=gc_check_cart_status`,
                data: { action: 'gc_check_cart_status' }
            }).done(response => {
                if (response.status === 'success' && !response.cart_empty) {
                    gokwikSdk.initCheckout(gcObj);
                } else {
                    handleCheckoutFailure();
                }
            }).fail((jqXHR, textStatus, errorThrown) => {
                console.error('Failed to check cart status:', { status: jqXHR.status, textStatus, errorThrown });
                handleCheckoutFailure();
            });
        }
    }

    function initGoKwikCheckout(sessionId) {
        if (!sessionId) {
            fetchUserSessionId()
                .then(newSessionId => {
                    if (newSessionId && newSessionId.trim() !== '') {
                        processGoKwikCheckout(newSessionId);
                    } else {
                        throw new Error('Empty or invalid session ID received.');
                    }
                })
                .catch(error => {
                    console.error("Failed to fetch session ID:", error);
                    handleCheckoutFailure();
                });
        } else {
            processGoKwikCheckout(sessionId);
        }
    }

    function setupMiniCartCheckoutButton(selector, sessionId) {
        const gcButtonClass = jQuery(selector).attr("class") ?? '';
        const gcButtonId = jQuery(selector).attr("id") ?? '';
        const gcButton = jQuery('<a>', {
            href: "#",
            class: gcButtonClass,
            id: gcButtonId,
            text: 'Checkout'
        });

        jQuery(selector).replaceWith(gcButton);
        gcButton.on('click', function (event) {
            event.preventDefault();
            gcButton.html('Loading...');
            setTimeout(() => {
                gcButton.html('Checkout');
            }, 3000);
            initGoKwikCheckout(sessionId);
        });
    }

    function renderCheckoutButtons() {
        if (gc_data.is_international_user) {
            return;
        }

        if (gc_data.enable_gokwik_checkout_on_cart_page) {
            const gcCheckoutBtnText = 'Place Order <img src="https://cdn.gokwik.co/v4/images/upi-icons.svg" class="gokwik_logo">';
            const gcCheckoutBtn = jQuery('.wc-proceed-to-checkout .checkout-button').not('.native_checkout_button');
            if (gcCheckoutBtn.length) {
                const newGcCheckoutBtn = jQuery('<a>', {
                    href: "#",
                    class: gcCheckoutBtn.attr("class"),
                    id: gcCheckoutBtn.attr("id"),
                    html: gcCheckoutBtnText
                });
                gcCheckoutBtn.replaceWith(newGcCheckoutBtn);
                fetchUserSessionId().then(sessionId => {
                    newGcCheckoutBtn.on('click', e => {
                        e.preventDefault();
                        newGcCheckoutBtn.html('Loading...');
                        setTimeout(() => {
                            newGcCheckoutBtn.html(gcCheckoutBtnText);
                        }, 3000);
                        initGoKwikCheckout(sessionId);
                    });
                });
            }
        }

        if (gc_data.enable_gokwik_checkout_on_side_cart) {
            const sideCartSelectors = [
                ".xoo-wsc-ft-btn-checkout",
                ".elementor-button--checkout",
                "a.button.checkout",
                "a.btn.checkout",
                "#fkcart-checkout-button",
                ".xoo-wsc-ft-btn.xoo-wsc-cart",
                "a.button.checkout-button:not(.wc-proceed-to-checkout .button.checkout-button)"
            ];
            for (const selector of sideCartSelectors) {
                if (jQuery(selector).length) {
                    fetchUserSessionId().then(sessionId => {
                        setupMiniCartCheckoutButton(selector, sessionId);
                    });
                    break;
                }
            }
        }
    }

    if (gc_data.is_checkout_page && !gc_data.is_order_received && !gc_data.is_cart_empty && gc_data.overwrite_native_checkout && !gc_data.is_international_user) {
        fetchUserSessionId().then(sessionId => {
            initGoKwikCheckout(sessionId);
        });
        gokwikSdk.on('checkout-close', () => {
            if (!manualCloseInitiated) {
                window.location = gc_data.cart_url;
            }
            manualCloseInitiated = false;
        });
    } else {
        jQuery(document.body).on('wc_fragments_loaded wc_fragments_refreshed added_to_cart removed_from_cart updated_cart_totals updated_wc_div updated_checkout fkcart_fragments_refreshed', renderCheckoutButtons);
        renderCheckoutButtons();
    }

    gokwikSdk.on('order-complete', order => {
        jQuery.ajax({
            type: 'POST',
            dataType: 'json',
            url: `${gc_data.ajaxurl}?action=gc_cart_clear_and_redirect`,
            data: {
                action: 'gc_cart_clear_and_redirect',
                merchant_order_id: order.merchant_order_id
            }
        }).done(data => {
            if (data.status === 'success') {
                window.location = data.url;
            } else {
                console.error('Failed to clear cart:', data);
            }
        }).fail((jqXHR, textStatus, errorThrown) => {
            console.error('Order-Completion Ajax request failed:', { status: jqXHR.status, textStatus, errorThrown });
        });
    });

    gokwikSdk.on('checkout-initiation-failure', function (error) {
        handleCheckoutFailure();
    });

    if (!skipGoKwikCheckout) {
        localStorage.removeItem('skipGoKwikCheckout');
    } else {
        localStorage.setItem('skipGoKwikCheckout', 'false');
    }

    if (!gc_data.gokwik_buy_now_enabled) {
        return;
    }

    const quantitySelector = 'input.qty';
    const buttonSelector = 'button.gk-buy-now-btn';

    const buyNowButton = jQuery(buttonSelector);
    if (buyNowButton.length === 0) return;

    const updateButton = () => {
        const form = jQuery('form.cart');
        const allSelected = form.hasClass('variations_form')
            ? jQuery('.variations_form select').toArray().every(select => jQuery(select).val() !== '')
            : true;
        buyNowButton.toggleClass('disabled', !allSelected).attr('aria-disabled', !allSelected);
    };

    jQuery(document).on('input change', quantitySelector, updateButton);
    jQuery('.variations_form').on('woocommerce_variation_has_changed show_variation change', 'select', updateButton);

    buyNowButton.on('click', function (e) {
        if (jQuery(this).hasClass('disabled')) {
            alert('Please select all product options before purchasing.');
            return;
        }

        buyNowButton.text('Loading...').addClass('disabled').attr('aria-disabled', true);
        setTimeout(() => {
            buyNowButton.text("Buy Now").removeClass('disabled').attr('aria-disabled', false);
        }, 3000);

        const form = jQuery('form.cart');
        const formData = new FormData(form.get(0));
        let productID = null;

        const submitButton = form.find('button[type="submit"][name="add-to-cart"]');
        if (submitButton.length > 0) {
            productID = submitButton.val();
        }

        if (!productID) {
            const productData = form.serializeArray();
            for (const item of productData) {
                if (item.name === 'productID' || item.name === 'add-to-cart') {
                    if (item.value) {
                        productID = item.value;
                        break;
                    }
                }
            }
        }

        if (!productID && form.attr('action')) {
            const match = form.attr('action').match(/add-to-cart=([0-9]+)/);
            productID = match ? match[1] : null;
        }

        if (buyNowButton.attr('name') && buyNowButton.attr('value')) {
            formData.append(buyNowButton.attr('name'), buyNowButton.attr('value'));
        }

        if (productID) {
            formData.append('add-to-cart', productID);
        }

        formData.append('action', 'gc_add_to_cart');
        jQuery(document.body).trigger('adding_to_cart', [buyNowButton, formData]);

        jQuery.ajax({
            type: 'POST',
            url: wc_add_to_cart_params.wc_ajax_url.replace('%%endpoint%%', 'gc_add_to_cart'),
            data: formData,
            cache: false,
            processData: false,
            contentType: false,
            success: function (response) {
                if (response.fragments) {
                    fetchUserSessionId()
                        .then(sessionId => {
                            processGoKwikCheckout(sessionId);
                        })
                        .catch(error => {
                            console.error('Failed to fetch user session ID:', error);
                            handleCheckoutFailure();
                        });
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error('Failed to add product to cart:', { status: jqXHR.status, textStatus, errorThrown });
                handleCheckoutFailure();
            }
        });
    });

    updateButton();
});