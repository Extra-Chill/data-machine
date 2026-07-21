<?php

function dm_legacy_symbol(): void {
}

$dm_legacy_variable = true;
add_action( 'dm_legacy_hook', 'dm_legacy_symbol' );
get_option( 'dm_' . 'legacy_option' );
set_transient( 'dm_legacy_transient', true );
get_post_meta( 1, 'dm_legacy_meta', true );
const LEGACY_OPTION_KEY = 'dm_legacy_constant';
register_taxonomy( 'dm_legacy_taxonomy', array( 'post' ) );
$table = $wpdb->prefix . 'dm_legacy_table';
$wpdb->query( 'CREATE TABLE dm_legacy_sql_table ( id bigint )' );
