<?php 

/**
 * CONFIG
 */
define( 'MY_BATCH_SIZE', 5 );
define( 'MY_LOCK_TTL', 300 ); // 5 min


/**
 * ENTRY HOOK
 */
add_action( 'my_process_orders', 'my_process_orders_callback', 10, 1 );

function my_process_orders_callback( $last_id = 0 ) {

    global $wpdb;

    // BATCH LOCK (MySQL)
    $lock = $wpdb->get_var("SELECT GET_LOCK('my_batch_lock', 0)");

    if ( $lock != 1 ) {
        error_log('Another batch is running. Exit.');
        return;
    }

    try {

        error_log("Batch start from ID: $last_id");

        wp_suspend_cache_addition( true );

        $orders = get_orders_after( $last_id, MY_BATCH_SIZE );

        if ( empty( $orders ) ) {
            error_log("Batch complete");
            return;
        }

        foreach ( $orders as $i => $order ) {

            $order_id = $order->get_id();

            
            // CLAIM (idempotent entry)
            
            if ( ! claim_order( $order_id ) ) {
                $last_id = $order_id;
                continue;
            }

            try {

                
                // PROCESS
                
                my_process_logic( $order );

                
                // MARK PROCESSED
                
                mark_processed( $order_id );

                error_log("Processed: $order_id");

            } catch ( Exception $e ) {

                handle_failure( $order_id, $e );

            }

            $last_id = $order_id;

            
            //MEMORY CONTROL
            
            unset( $order );

            if ( $i % 20 === 0 ) {
                wp_cache_flush_runtime();
            }
        }

        
        //CHECKPOINT
        
        update_option( 'my_batch_last_id', $last_id );

        
        //NEXT BATCH
        
        as_enqueue_async_action( 'my_process_orders', [ 'last_id' => $last_id ] );

    } finally {

        
        //RELEASE LOCK
        
        $wpdb->query("SELECT RELEASE_LOCK('my_batch_lock')");

        wp_suspend_cache_addition( false );
    }
}



// FETCH ORDERS (ID-based, stable)
function get_orders_after( $last_id, $limit ) {

    global $wpdb;

    $ids = $wpdb->get_col( $wpdb->prepare("
        SELECT ID FROM {$wpdb->posts}
        WHERE post_type = 'shop_order'
        AND ID > %d
        ORDER BY ID ASC
        LIMIT %d
    ", $last_id, $limit ) );

    if ( empty( $ids ) ) return [];

    return wc_get_orders([
        'include' => $ids,
        'orderby' => 'ID',
        'order'   => 'ASC',
    ]);
}



// CLAIM PATTERN (practical WooCommerce version)

function claim_order( $order_id ) {

    global $wpdb;

    $now = time();
    $ttl = MY_LOCK_TTL;

    // check existing claim
    $claimed_at = get_post_meta( $order_id, '_claimed_at', true );

    // already claimed & not expired
    if ( $claimed_at && ( time() - $claimed_at ) < $ttl ) {
        return false;
    }

    // attempt claim
    update_post_meta( $order_id, '_claimed_at', $now );

    return true;
}



// MARK PROCESSED
function mark_processed( $order_id ) {

    update_post_meta( $order_id, '_processed_at', time() );

}


// PROCESS LOGIC

function my_process_logic( $order ) {

    // simulate work
    usleep(200000);

    // avoid email storm example:
    remove_all_actions( 'woocommerce_order_status_completed' );

    $order->add_order_note( 'Processed via Phase C system' );
}



// FAILURE HANDLING
function handle_failure( $order_id, $e ) {

    error_log("Failed order $order_id: " . $e->getMessage());

    $retry_count = (int) get_post_meta( $order_id, '_retry_count', true );

    if ( $retry_count < 3 ) {

        update_post_meta( $order_id, '_retry_count', $retry_count + 1 );

        // exponential backoff
        $delay = pow(2, $retry_count) * 60;

        as_schedule_single_action(
            time() + $delay,
            'my_retry_order',
            [ 'order_id' => $order_id ]
        );

    } else {

        update_post_meta( $order_id, '_failed_permanently', 1 );
    }
}



//RETRY HANDLER

add_action( 'my_retry_order', function( $order_id ) {

    $order = wc_get_order( $order_id );

    if ( ! $order ) return;

    if ( ! claim_order( $order_id ) ) return;

    try {

        my_process_logic( $order );
        mark_processed( $order_id );

    } catch ( Exception $e ) {

        handle_failure( $order_id, $e );
    }

});



 // MANUAL TRIGGER

add_action( 'admin_init', function() {

    if ( isset( $_GET['start_batch'] ) ) {

        $last_id = get_option( 'my_batch_last_id', 0 );

        as_enqueue_async_action( 'my_process_orders', [
            'last_id' => $last_id
        ]);

        echo "Batch started from ID: $last_id";
        exit;
    }
});