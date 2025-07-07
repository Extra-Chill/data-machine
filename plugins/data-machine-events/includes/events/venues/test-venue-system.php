<?php
/**
 * Test Venue System
 * 
 * This file can be used to test the venue taxonomy system
 * Run this via WordPress admin or CLI to verify everything works
 */

// Only run if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Test venue system functionality
 */
function test_venue_system() {
    echo "<h2>Testing Venue System</h2>\n";
    
    // Test 1: Check if venue taxonomy exists
    echo "<h3>Test 1: Venue Taxonomy</h3>\n";
    if (taxonomy_exists('venue')) {
        echo "✅ Venue taxonomy exists\n";
    } else {
        echo "❌ Venue taxonomy does not exist\n";
        return;
    }
    
    // Test 2: Check if venue terms exist
    echo "<h3>Test 2: Venue Terms</h3>\n";
    $venue_terms = get_terms([
        'taxonomy' => 'venue',
        'hide_empty' => false,
    ]);
    
    if (is_wp_error($venue_terms)) {
        echo "❌ Error getting venue terms: " . $venue_terms->get_error_message() . "\n";
    } else {
        echo "✅ Found " . count($venue_terms) . " venue terms\n";
        foreach ($venue_terms as $term) {
            echo "  - " . $term->name . " (ID: " . $term->term_id . ")\n";
        }
    }
    
    // Test 3: Check venue term meta
    echo "<h3>Test 3: Venue Term Meta</h3>\n";
    if (class_exists('ChillEvents\Events\Venues\Venue_Term_Meta')) {
        echo "✅ Venue_Term_Meta class exists\n";
        
        if (!empty($venue_terms)) {
            $first_venue = $venue_terms[0];
            $venue_data = \ChillEvents\Events\Venues\Venue_Term_Meta::get_venue_data($first_venue->term_id);
            echo "✅ Venue data for '" . $first_venue->name . "':\n";
            foreach ($venue_data as $key => $value) {
                if (!empty($value)) {
                    echo "  - " . $key . ": " . $value . "\n";
                }
            }
        }
    } else {
        echo "❌ Venue_Term_Meta class does not exist\n";
    }
    
    // Test 4: Check events with venue taxonomy
    echo "<h3>Test 4: Events with Venue Taxonomy</h3>\n";
    $events_with_venues = get_posts([
        'post_type' => 'chill_events',
        'posts_per_page' => 5,
        'tax_query' => [
            [
                'taxonomy' => 'venue',
                'operator' => 'EXISTS',
            ]
        ]
    ]);
    
    echo "✅ Found " . count($events_with_venues) . " events with venue taxonomy\n";
    foreach ($events_with_venues as $event) {
        $venue_terms = get_the_terms($event->ID, 'venue');
        if ($venue_terms && !is_wp_error($venue_terms)) {
            echo "  - '" . $event->post_title . "' → Venue: " . $venue_terms[0]->name . "\n";
        }
    }
    
    echo "<h3>Test Complete!</h3>\n";
}

// Run test if this file is accessed directly
if (isset($_GET['test_venue_system'])) {
    test_venue_system();
} 