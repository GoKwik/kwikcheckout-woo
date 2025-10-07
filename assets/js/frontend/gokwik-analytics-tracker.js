/**
 * GoKwik Analytics Frontend Tracker
 */

(function($) {
    'use strict';
    
    const GokwikAnalytics = {
        init: function() {
            this.trackCheckoutStarted();
            this.trackCheckoutSteps();
        },
        
        trackCheckoutStarted: function() {
            if ($('body').hasClass('woocommerce-checkout')) {
                this.sendEvent('checkout_started', {
                    page: 'checkout',
                    timestamp: Date.now()
                });
            }
        },
        
        trackCheckoutSteps: function() {
            $(document).on('click', '.gokwik-checkout-button', function() {
                GokwikAnalytics.sendEvent('gokwik_button_clicked', {
                    button_id: $(this).attr('id'),
                    timestamp: Date.now()
                });
            });
            
            $(document).on('updated_checkout', function() {
                GokwikAnalytics.sendEvent('checkout_updated', {
                    timestamp: Date.now()
                });
            });
        },
        
        sendEvent: function(eventType, data) {
            if (typeof gokwikAnalyticsData === 'undefined') return;
            
            $.ajax({
                url: gokwikAnalyticsData.ajax_url,
                type: 'POST',
                data: {
                    action: 'gokwik_track_event',
                    nonce: gokwikAnalyticsData.nonce,
                    event_type: eventType,
                    data: JSON.stringify(data)
                }
            });
        }
    };
    
    $(document).ready(function() {
        GokwikAnalytics.init();
    });
    
})(jQuery);
