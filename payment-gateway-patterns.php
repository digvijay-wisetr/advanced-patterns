<?php 

// Gateway Hook System
// Register custom gateway
add_filter('woocommerce_payment_gateways', function($gateways){
    $gateways[] = 'WC_Gateway_Custom';
    return $gateways;
});

// Filter available gateways
add_filter('woocommerce_available_payment_gateways', function($gateways){

    if (is_admin()) return $gateways;

    // Example: disable COD for high cart value
    if (WC()->cart && WC()->cart->total > 5000) {
        unset($gateways['cod']);
    }

    return $gateways;
});

// At checkout
$method = WC()->session->get('chosen_payment_method');

// After order created
$method = $order->get_payment_method();

// Order (final)
add_action('woocommerce_thankyou', function($order_id){
    $order = wc_get_order($order_id);
    error_log('Payment Method: ' . $order->get_payment_method());
    $method = $order->get_payment_method();

    if ($method === 'paypal') {
        error_log('PayPal Standard');
    } elseif ($method === 'ppec_paypal') {
        error_log('PayPal Express');
    } elseif ($method === 'ppcp-gateway') {
        error_log('PayPal PPCP');
    }

});

// Stripe Integration Pattern
// Store Payment Intent ID

add_action('woocommerce_checkout_create_order', function($order){

    if (isset($_POST['payment_intent'])) {
        $order->update_meta_data('_stripe_intent', sanitize_text_field($_POST['payment_intent']));
    }

});

// Handle Webhook
function find_order_by_intent($intent_id){

    $orders = wc_get_orders([
        'meta_key' => '_stripe_intent',
        'meta_value' => $intent_id,
        'limit' => 1
    ]);

    return !empty($orders) ? $orders[0] : false;
}
// Webhook Handler
function handle_webhook($intent_id){

    $order = find_order_by_intent($intent_id);

    if (!$order) {
        // retry after delay
        wp_schedule_single_event(time() + 60, 'retry_webhook', [$intent_id]);
        return;
    }

    $order->payment_complete();
}

// Webhook Idempotency

// 04-idempotency.php

function is_processed($event_id){
    return get_option('event_' . $event_id);
}

function mark_processed($event_id){
    update_option('event_' . $event_id, 1);
}

// usage
if (is_processed($event_id)) return;
mark_processed($event_id);


function is_event_processed($event_id){
    return get_option('stripe_event_' . $event_id);
}

function mark_event_processed($event_id){
    update_option('stripe_event_' . $event_id, 1);
}

//Usage:
if (is_event_processed($event_id)) {
    return; // ignore duplicate
}

mark_event_processed($event_id);

//Refund Reconciliation Problem

function reconcile_refund($order, $refund_id){

    $existing = $order->get_refunds();

    foreach ($existing as $refund) {
        if ($refund->get_meta('_stripe_refund_id') === $refund_id) {
            return; // already recorded
        }
    }

    // create refund manually
    wc_create_refund([
        'amount' => 100,
        'reason' => 'Stripe refund sync',
        'order_id' => $order->get_id(),
    ]);
}

function sync_refund($order, $refund_id){

    foreach ($order->get_refunds() as $refund) {
        if ($refund->get_meta('_gateway_refund_id') === $refund_id) {
            return; // already exists
        }
    }

    wc_create_refund([
        'amount'   => 100,
        'reason'   => 'Gateway sync',
        'order_id' => $order->get_id(),
    ]);
}

//SCA / 3DS Resume Flow
//Store Payment Intent in order

$order->update_meta_data('_stripe_intent', $intent_id);
$order->save();

// PayPal Compatibility (CRITICAL CONFUSION AREA)

// Different PayPal types:

// PayPal Standard
// Redirect-based
// Uses IPN

// Basic hooks
// PayPal Express
// Checkout shortcut
// Different session flow

// PayPal PPCP (PayPal Commerce Platform)
// Modern
// Uses REST APIs + webhooks
// Completely different hooks

$method = $order->get_payment_method();

switch($method){
    case 'paypal':
        // Standard
        break;

    case 'ppec_paypal':
        // Express
        break;

    case 'ppcp-gateway':
        // PPCP
        break;
}


// Save intent
add_action('woocommerce_checkout_create_order', function($order){
    if (isset($_POST['payment_intent'])) {
        $order->update_meta_data('_intent', $_POST['payment_intent']);
    }
});

// Resume
$intent = $order->get_meta('_intent');



