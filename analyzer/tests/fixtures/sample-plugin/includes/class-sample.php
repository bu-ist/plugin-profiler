<?php

declare(strict_types=1);

namespace SamplePlugin;

/**
 * Primary class for the Sample Plugin.
 *
 * Handles initialisation, data retrieval, and hook registration.
 */
class Sample extends \WP_Widget
{
    private string $optionKey = 'sample_data';

    public static function init(): void
    {
        add_filter('the_content', [self::class, 'filterContent'], 10, 1);
        do_action('sample_plugin_loaded');
    }

    public function getData(): array
    {
        $cached = get_transient('sample_cache');
        if ($cached !== false) {
            return $cached;
        }

        $data = get_post_meta(get_the_ID(), $this->optionKey, true);
        set_transient('sample_cache', $data, HOUR_IN_SECONDS);

        return is_array($data) ? $data : [];
    }

    public static function filterContent(string $content): string
    {
        return $content;
    }

    public function saveData(int $postId, array $data): void
    {
        update_post_meta($postId, $this->optionKey, $data);
        update_option('sample_last_saved', time());
    }
}
