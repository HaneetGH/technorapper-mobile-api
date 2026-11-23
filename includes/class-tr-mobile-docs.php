<?php
if (!defined('ABSPATH')) exit;

/**
 * Ships API documentation in-repo (docs/API.md) and in WP admin.
 */
final class TR_Mobile_Docs {
    public static function api_doc_path(): string {
        return dirname(__DIR__) . '/docs/API.md';
    }

    public static function load_api_markdown(): string {
        $path = self::api_doc_path();
        if (!is_readable($path)) {
            return '';
        }
        $raw = file_get_contents($path);
        return is_string($raw) ? $raw : '';
    }

    /**
     * Minimal Markdown → HTML for admin display (headings, fences, tables, lists, inline code).
     */
    public static function markdown_to_html(string $markdown): string {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $parts = preg_split('/(```[\s\S]*?```)/', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return '<p>' . esc_html($markdown) . '</p>';
        }

        $html = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^```(\w*)\n([\s\S]*?)```$/', $part, $m)) {
                $code = rtrim($m[2], "\n");
                $html .= '<pre class="tr-mobile-doc-code"><code>' . esc_html($code) . '</code></pre>';
                continue;
            }
            $html .= self::format_block($part);
        }
        return $html;
    }

    private static function format_block(string $text): string {
        $lines = explode("\n", $text);
        $out = '';
        $in_list = false;
        $in_table = false;
        $table_rows = [];

        $flush_list = static function () use (&$out, &$in_list): void {
            if ($in_list) {
                $out .= '</ul>';
                $in_list = false;
            }
        };
        $flush_table = function () use (&$out, &$in_table, &$table_rows): void {
            if (!$in_table) {
                return;
            }
            $out .= '<table class="widefat striped tr-mobile-doc-table"><tbody>';
            foreach ($table_rows as $i => $cells) {
                $tag = $i === 0 ? 'th' : 'td';
                $out .= '<tr>';
                foreach ($cells as $cell) {
                    $out .= '<' . $tag . '>' . self::inline(trim($cell)) . '</' . $tag . '>';
                }
                $out .= '</tr>';
            }
            $out .= '</tbody></table>';
            $in_table = false;
            $table_rows = [];
        };

        foreach ($lines as $line) {
            $trim = trim($line);

            if ($trim === '') {
                $flush_list();
                $flush_table();
                continue;
            }

            if (str_starts_with($trim, '|') && str_ends_with($trim, '|')) {
                $flush_list();
                // Skip markdown separator rows: |---|---|
                if (preg_match('/^\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)+\|?$/', $trim)) {
                    continue;
                }
                $in_table = true;
                $cells = array_map('trim', explode('|', trim($trim, '|')));
                $table_rows[] = $cells;
                continue;
            }

            $flush_table();

            if (preg_match('/^(#{1,3})\s+(.+)$/', $trim, $m)) {
                $flush_list();
                $level = strlen($m[1]) + 1; // h2–h4 in admin wrap
                $out .= '<h' . $level . '>' . self::inline($m[2]) . '</h' . $level . '>';
                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/', $trim, $m)) {
                if (!$in_list) {
                    $out .= '<ul>';
                    $in_list = true;
                }
                $out .= '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }

            if (preg_match('/^\d+\.\s+(.+)$/', $trim, $m)) {
                $flush_list();
                $out .= '<p>' . self::inline($m[1]) . '</p>';
                continue;
            }

            $flush_list();
            if ($trim === '---') {
                $out .= '<hr />';
                continue;
            }
            $out .= '<p>' . self::inline($trim) . '</p>';
        }

        $flush_list();
        $flush_table();
        return $out;
    }

    private static function inline(string $text): string {
        $text = esc_html($text);
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;
        return $text;
    }

    public static function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $md = self::load_api_markdown();
        $base = esc_url(rest_url(TR_MOBILE_API_NAMESPACE));
        ?>
        <div class="wrap tr-mobile-api-docs">
            <h1><?php echo esc_html__('Mobile API documentation', 'technorapper-mobile-api'); ?></h1>
            <p>
                <?php echo esc_html__('Live REST base on this site:', 'technorapper-mobile-api'); ?>
                <code><?php echo esc_html(untrailingslashit($base)); ?></code>
            </p>
            <p>
                <a class="button" href="<?php echo esc_url(admin_url('options-general.php?page=tr-mobile-api')); ?>">
                    <?php echo esc_html__('Back to token settings', 'technorapper-mobile-api'); ?>
                </a>
            </p>
            <style>
                .tr-mobile-api-docs .tr-mobile-doc-code {
                    background: #1d2327;
                    color: #f0f0f1;
                    padding: 12px 14px;
                    overflow: auto;
                    border-radius: 4px;
                }
                .tr-mobile-api-docs .tr-mobile-doc-table { max-width: 960px; margin: 12px 0; }
                .tr-mobile-api-docs code { font-size: 12px; }
            </style>
            <?php if ($md === ''): ?>
                <div class="notice notice-error"><p><?php echo esc_html__('docs/API.md is missing from this plugin install.', 'technorapper-mobile-api'); ?></p></div>
            <?php else: ?>
                <div class="tr-mobile-doc-body">
                    <?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built via esc_html / tags in markdown_to_html
                    echo self::markdown_to_html($md);
                    ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
