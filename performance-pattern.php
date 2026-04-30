<?php 

/**
 * Process orders in batches using Action Scheduler, with idempotency and checkpointing.
 */
add_action( 'my_process_orders', 'my_process_orders_callback', 10, 1 );

function my_process_orders_callback( $last_id = 0 ) {

    // BASIC LOCK (not perfect — see below)
    if ( get_option( 'my_batch_running' ) ) {
        error_log('Batch already running, exiting...');
        return;
    }

    update_option( 'my_batch_running', time() );

    error_log( "Batch started from ID: $last_id" );

    $orders = get_orders_after( $last_id, 5 ); // small batch for testing

    if ( empty( $orders ) ) {
        error_log( "No more orders. Batch complete." );
        delete_option( 'my_batch_running' );
        return;
    }

    foreach ( $orders as $order ) {

        $order_id = $order->get_id();

        // Idempotency check (MUST stay)
        if ( $order->get_meta('_my_processed') ) {
            $last_id = $order_id;
            continue;
        }

        // PROCESS
        my_custom_processing( $order );

        // MARK PROCESSED
        $order->update_meta_data( '_my_processed', 1 );
        $order->save();

        error_log( "Processed order: $order_id" );

        $last_id = $order_id;
    }

    //  SAVE CHECKPOINT
    update_option( 'my_batch_last_id', $last_id );

    //  RELEASE LOCK BEFORE NEXT BATCH
    delete_option( 'my_batch_running' );

    // CONTINUE
    as_enqueue_async_action( 'my_process_orders', [ 'last_id' => $last_id ] );
}


/**
 * FETCH ORDERS 
 */
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

    return wc_get_orders( [
        'include' => $ids,
        'orderby' => 'ID',
        'order'   => 'ASC',
    ] );
}


/**
 * PROCESSING LOGIC
 */
function my_custom_processing( $order ) {

    $order->add_order_note( 'Processed by Phase C batch job' );

    // simulate work
    usleep(200000); // 0.2 sec
}


/**
 * START BATCH (manual trigger)
 */
add_action( 'admin_init', function() {

    if ( isset( $_GET['start_batch'] ) ) {

        if ( get_option( 'my_batch_running' ) ) {
            echo "Already running";
            exit;
        }

        $last_id = get_option( 'my_batch_last_id', 0 );

        as_enqueue_async_action( 'my_process_orders', [ 'last_id' => $last_id ] );

        echo "Batch started from ID: $last_id";
        exit;
    }
});