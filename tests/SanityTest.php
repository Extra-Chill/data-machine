<?php

class SanityTest extends WP_UnitTestCase {
    public function test_wordpress_loaded() {
        $this->assertTrue(function_exists('wp_insert_post'));
    }

    public function test_plugin_loaded() {
        $this->assertTrue(class_exists('DataMachine\DataMachine') || defined('DATAMACHINE_VERSION'));
    }
}
