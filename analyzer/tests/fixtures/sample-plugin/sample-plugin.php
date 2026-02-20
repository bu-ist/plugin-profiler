<?php

/**
 * Plugin Name: Sample Plugin
 * Plugin URI:  https://example.com/sample-plugin
 * Description: A test fixture plugin for plugin-profiler.
 * Version:     1.0.0
 * Author:      Test Author
 * License:     GPL-2.0+
 */

declare(strict_types=1);

use SamplePlugin\Sample;

// Boot the plugin
add_action('init', [Sample::class, 'init']);
add_action('wp_ajax_sample_action', 'sample_ajax_handler');

// Register REST route
add_action('rest_api_init', function (): void {
    register_rest_route('sample/v1', '/items', [
        'methods'  => 'GET',
        'callback' => 'sample_get_items',
    ]);
});

// Read an option
$setting = get_option('sample_plugin_settings', []);

// Register a shortcode
add_shortcode('sample', 'sample_shortcode_handler');

function sample_ajax_handler(): void
{
    wp_send_json_success(['ok' => true]);
}

function sample_get_items(): array
{
    return [];
}

function sample_shortcode_handler(array $atts): string
{
    return '';
}
