<?php 

//YITH Multi Currency
// Mostly follows: base → display conversion
// Uses filters like:
yith_wcmcs_convert_price
yith_wcmcs_get_price
woocommerce_product_get_price


//CURCY (WooCommerce Currency Switcher)
// Uses its own system
// Often modifies:
woocommerce_product_get_price
woocommerce_cart_calculate_fees
Stores selected currency in session

// Detect Current Currency
function my_get_current_currency() {

    // YITH
    if ( function_exists('yith_wcmcs_get_current_currency') ) {
        return yith_wcmcs_get_current_currency();
    }

    // CURCY
    if ( isset($_COOKIE['wmc_current_currency']) ) {
        return sanitize_text_field($_COOKIE['wmc_current_currency']);
    }

    // Default
    return get_woocommerce_currency();
}

// Convert Price (YITH)
function my_convert_price_yith($price) {
    if ( function_exists('yith_wcmcs_convert_price') ) {
        return yith_wcmcs_convert_price($price);
    }
    return $price;
}

// Convert Price (CURCY)
function my_convert_price_curcy($price) {
    if ( class_exists('WOOMULTI_CURRENCY_F') ) {
        global $WOOMULTI_CURRENCY_F;
        return $WOOMULTI_CURRENCY_F->convert($price);
    }
    return $price;
}


//Rounding Issue
//€13.33 × 1.21 = €16.1293 → €16.13
//But conversion might produce mismatch.

add_action('woocommerce_before_calculate_totals', function($cart){

    foreach ($cart->get_cart() as $item) {
        $price = 13.33;
        $tax_rate = 1.21;

        $taxed_price = $price * $tax_rate;

        error_log('Raw: ' . $taxed_price);
        error_log('Rounded: ' . wc_format_decimal($taxed_price, 2));
    }

});


///. Coupon Issue
Problem:
// Coupon = ₹100 OFF
// User sees $ currency → mismatch

add_filter('woocommerce_coupon_get_amount', function($amount, $coupon){

    $currency = my_get_current_currency();

    if ($currency !== get_woocommerce_currency()) {
        // Convert coupon amount
        $amount = my_convert_price_yith($amount);
    }

    return $amount;

}, 10, 2);

// Store Exchange Rate in Order
add_action('woocommerce_checkout_create_order', function($order){

    $currency = my_get_current_currency();
    $rate = 1.0;

    if ( function_exists('yith_wcmcs_get_rate') ) {
        $rate = yith_wcmcs_get_rate($currency);
    }

    $order->update_meta_data('_exchange_rate', $rate);
    $order->update_meta_data('_order_currency_used', $currency);

});

// Refund Problem
function my_refund_amount($order, $amount){

    $rate = $order->get_meta('_exchange_rate');

    if ($rate) {
        return $amount * $rate;
    }

    return $amount;
}

// Tax Before vs After Conversion
// Wrong:
// Convert → then apply tax
// Correct:
// Apply tax in base → then convert
$base_price = 100;
$tax = 18;

// Correct
$price_with_tax = $base_price + ($base_price * $tax / 100);
$converted = my_convert_price_yith($price_with_tax);

// Wrong
$converted_first = my_convert_price_yith($base_price);
$wrong_total = $converted_first + ($converted_first * $tax / 100);
