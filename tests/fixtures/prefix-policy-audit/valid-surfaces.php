<?php

// Historical dm_ migration notes are prose, not governed identifiers.
$vendor_path = 'vendor/example/dm_package.php';
get_option( 'vendor_dm_option' );

function datamachine_valid_symbol(): void {
}

$datamachine_valid_variable = true;
add_action( 'datamachine_valid_hook', 'datamachine_valid_symbol' );
get_option( 'datamachine_valid_option' );
set_transient( 'datamachine_valid_transient', true );
get_post_meta( 1, 'datamachine_valid_meta', true );
const DATAMACHINE_VALID_OPTION_KEY = 'datamachine_valid_constant';
register_taxonomy( 'datamachine_valid_taxonomy', array( 'post' ) );
$table = $wpdb->prefix . 'datamachine_valid_table';
$wpdb->query( 'CREATE TABLE datamachine_valid_sql_table ( id bigint )' );
