<?php
/**
 * Plugin Name: Viewer Counter
 * Plugin URI: https://flexbox.my.id/viewer-counter
 * Description: Unique visitors (daily, weekly, monthly, all-time), settings, shortcode, admin charts. Includes rate limiting and strict UUID validation for abuse mitigation.
 * Version: 1.2.2
 * Author: Agus Andri Putra
 * Author URI: https://flexbox.my.id
 * License: GPL v2 or later
 * Text Domain: viewer-counter
 */

if (!defined('ABSPATH')) {
    exit;
}

define('VIEWER_COUNTER_VERSION', '1.2.2');
define('VIEWER_COUNTER_OPTION', 'viewer_counter_settings');
define('VIEWER_COUNTER_COOKIE', 'wp_vc_vid');
define('VIEWER_COUNTER_COOKIE_DAYS', 400);
/** Max User-Agent length accepted for counting (mitigation against oversized header abuse). */
define('VIEWER_COUNTER_MAX_UA_LENGTH', 512);

final class Viewer_Counter {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'maybe_record_visit'), 5);
        add_action('admin_menu', array($this, 'register_admin_menus'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'maybe_redirect_legacy_options_page'), 1);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_dashboard'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_shortcode('viewer_counter', array($this, 'shortcode'));
        add_action('widgets_init', array($this, 'register_widget'));
    }

    /**
     * Legacy Settings → Viewer Counter URL (options-general.php?page=viewer-counter).
     */
    public function maybe_redirect_legacy_options_page() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        if (empty($GLOBALS['pagenow']) || $GLOBALS['pagenow'] !== 'options-general.php') {
            return;
        }
        $page = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (is_string($page) && sanitize_key($page) === 'viewer-counter') {
            wp_safe_redirect(admin_url('admin.php?page=viewer-counter-settings'));
            exit;
        }
    }

    /**
     * Allowed display keys (output order).
     */
    public static function display_keys() {
        return array('daily', 'weekly', 'monthly', 'total');
    }

    /**
     * Short labels for each display key.
     */
    public static function display_labels() {
        return array(
            'daily'   => __('Daily', 'viewer-counter'),
            'weekly'  => __('Weekly', 'viewer-counter'),
            'monthly' => __('Monthly', 'viewer-counter'),
            'total'   => __('All-time', 'viewer-counter'),
        );
    }

    public static function default_settings() {
        return array(
            'show_daily'   => 1,
            'show_weekly'  => 1,
            'show_monthly' => 1,
            'show_total'   => 0,
        );
    }

    public function get_settings() {
        $defaults = self::default_settings();
        $saved = get_option(VIEWER_COUNTER_OPTION, array());
        if (!is_array($saved)) {
            $saved = array();
        }
        return array_merge($defaults, $saved);
    }

    /**
     * Resolve settings / shortcode into an ordered list of display keys.
     *
     * @param string|null $show_attr Shortcode `show` attribute, e.g. "daily,total". Null = use site settings.
     * @param array|null  $widget_flags Widget instance show_* keys; null = ignore.
     */
    public function resolve_display_keys($show_attr = null, $widget_flags = null) {
        $keys = array();
        if ($widget_flags !== null && is_array($widget_flags)) {
            foreach (self::display_keys() as $k) {
                $f = 'show_' . $k;
                if (!empty($widget_flags[$f])) {
                    $keys[] = $k;
                }
            }
            if ($keys !== array()) {
                return $keys;
            }
        }
        if (is_string($show_attr) && $show_attr !== '') {
            $parts = preg_split('/\s*,\s*/', strtolower(trim($show_attr)));
            $allowed = array_flip(self::display_keys());
            $parsed = array();
            foreach ($parts as $p) {
                if (isset($allowed[$p])) {
                    $parsed[] = $p;
                }
            }
            $parsed = array_values(array_unique($parsed));
            if ($parsed !== array()) {
                return $parsed;
            }
        }
        $settings = $this->get_settings();
        $keys = array();
        foreach (self::display_keys() as $k) {
            if (!empty($settings['show_' . $k])) {
                $keys[] = $k;
            }
        }
        if ($keys === array()) {
            return array('daily', 'weekly', 'monthly');
        }
        return $keys;
    }

    public function register_admin_menus() {
        add_menu_page(
            __('Viewer Counter', 'viewer-counter'),
            __('Viewer Counter', 'viewer-counter'),
            'manage_options',
            'viewer-counter',
            array($this, 'render_dashboard_page'),
            'dashicons-chart-area',
            68
        );
        add_submenu_page(
            'viewer-counter',
            __('Dashboard', 'viewer-counter'),
            __('Dashboard', 'viewer-counter'),
            'manage_options',
            'viewer-counter',
            array($this, 'render_dashboard_page')
        );
        add_submenu_page(
            'viewer-counter',
            __('Settings', 'viewer-counter'),
            __('Settings', 'viewer-counter'),
            'manage_options',
            'viewer-counter-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Date range for the dashboard report (WordPress site timezone).
     *
     * @return array{from:string,to:string}
     */
    public function parse_dashboard_date_range() {
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(wp_timezone_string());
        $today = current_time('Y-m-d');
        $today_dt = new DateTimeImmutable($today, $tz);
        $def_from = $today_dt->modify('-29 days')->format('Y-m-d');

        $nonce = isset($_GET['_vc_nonce']) ? sanitize_text_field(wp_unslash($_GET['_vc_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'viewer_counter_dashboard_filter')) {
            return array('from' => $def_from, 'to' => $today);
        }

        if (!empty($_GET['vc_preset'])) {
            $preset = sanitize_key(wp_unslash($_GET['vc_preset']));
            switch ($preset) {
                case '7d':
                    return array(
                        'from' => $today_dt->modify('-6 days')->format('Y-m-d'),
                        'to'   => $today,
                    );
                case '30d':
                    return array(
                        'from' => $today_dt->modify('-29 days')->format('Y-m-d'),
                        'to'   => $today,
                    );
                case '90d':
                    return array(
                        'from' => $today_dt->modify('-89 days')->format('Y-m-d'),
                        'to'   => $today,
                    );
                case 'month':
                    return array(
                        'from' => $today_dt->modify('first day of this month')->format('Y-m-d'),
                        'to'   => $today,
                    );
                case 'year':
                    return array(
                        'from' => $today_dt->modify('first day of january this year')->format('Y-m-d'),
                        'to'   => $today,
                    );
            }
        }

        $from_in = isset($_GET['vc_from']) ? sanitize_text_field(wp_unslash($_GET['vc_from'])) : '';
        $to_in = isset($_GET['vc_to']) ? sanitize_text_field(wp_unslash($_GET['vc_to'])) : '';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_in)) {
            $from_in = $def_from;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_in)) {
            $to_in = $today;
        }

        try {
            $from = (new DateTimeImmutable($from_in, $tz))->format('Y-m-d');
            $to = (new DateTimeImmutable($to_in, $tz))->format('Y-m-d');
        } catch (Exception $e) {
            return array('from' => $def_from, 'to' => $today);
        }

        if ($from > $to) {
            $swap = $from;
            $from = $to;
            $to = $swap;
        }

        $max_days = (int) apply_filters('viewer_counter_max_report_days', 366);
        $from_dt = new DateTimeImmutable($from, $tz);
        $to_dt = new DateTimeImmutable($to, $tz);
        $span = (int) floor(($to_dt->getTimestamp() - $from_dt->getTimestamp()) / DAY_IN_SECONDS) + 1;
        if ($span < 1) {
            $span = 1;
        }
        if ($span > $max_days) {
            $from = (clone $to_dt)->modify('-' . ($max_days - 1) . ' days')->format('Y-m-d');
        }

        return array('from' => $from, 'to' => $to);
    }

    /**
     * Daily unique series (one point per calendar day in range).
     *
     * @return array{labels:string[],datasets:array<int,array>}
     */
    public function get_series_daily($from, $to) {
        global $wpdb;
        $table = self::table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT visit_date, COUNT(*) AS c FROM {$table} WHERE visit_date >= %s AND visit_date <= %s GROUP BY visit_date ORDER BY visit_date ASC",
                $from,
                $to
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $map = array();
        foreach ($rows as $r) {
            $map[$r['visit_date']] = (int) $r['c'];
        }

        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(wp_timezone_string());
        $cur = new DateTimeImmutable($from, $tz);
        $end = new DateTimeImmutable($to, $tz);
        $labels = array();
        $data = array();
        while ($cur <= $end) {
            $d = $cur->format('Y-m-d');
            $labels[] = date_i18n('j M', $cur->getTimestamp());
            $data[] = isset($map[$d]) ? $map[$d] : 0;
            $cur = $cur->modify('+1 day');
        }

        return array(
            'labels'   => $labels,
            'datasets' => array(
                array(
                    'label'            => __('Unique visitors (daily)', 'viewer-counter'),
                    'data'             => $data,
                    'borderColor'      => 'rgb(34, 113, 177)',
                    'backgroundColor'  => 'rgba(34, 113, 177, 0.08)',
                    'tension'          => 0.25,
                    'fill'             => true,
                    'pointRadius'      => 2,
                    'pointHitRadius'   => 8,
                ),
            ),
        );
    }

    /**
     * Weekly unique series (distinct visitors per ISO week).
     *
     * @return array{labels:string[],datasets:array<int,array>}
     */
    public function get_series_weekly($from, $to) {
        global $wpdb;
        $table = self::table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT YEARWEEK(visit_date, 3) AS yw, COUNT(DISTINCT visitor_id) AS c FROM {$table} WHERE visit_date >= %s AND visit_date <= %s GROUP BY yw ORDER BY yw ASC",
                $from,
                $to
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $map = array();
        foreach ($rows as $r) {
            $map[(int) $r['yw']] = (int) $r['c'];
        }

        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(wp_timezone_string());
        $from_dt = new DateTimeImmutable($from, $tz);
        $to_dt = new DateTimeImmutable($to, $tz);
        $n = (int) $from_dt->format('N');
        $week_cursor = $from_dt->modify('-' . ($n - 1) . ' days');

        $labels = array();
        $data = array();
        while ($week_cursor <= $to_dt) {
            $y = (int) $week_cursor->format('o');
            $w = (int) $week_cursor->format('W');
            $key = $y * 100 + $w;
            $week_end = $week_cursor->modify('+6 days');
            $labels[] = sprintf(
                /* translators: 1: start date, 2: end date */
                __('%1$s – %2$s', 'viewer-counter'),
                date_i18n('j M', $week_cursor->getTimestamp()),
                date_i18n('j M Y', $week_end->getTimestamp())
            );
            $data[] = isset($map[$key]) ? $map[$key] : 0;
            $week_cursor = $week_cursor->modify('+7 days');
        }

        return array(
            'labels'   => $labels,
            'datasets' => array(
                array(
                    'label'            => __('Unique visitors (weekly)', 'viewer-counter'),
                    'data'             => $data,
                    'backgroundColor'  => 'rgba(34, 113, 177, 0.55)',
                    'borderColor'      => 'rgb(34, 113, 177)',
                    'borderWidth'      => 1,
                ),
            ),
        );
    }

    /**
     * Monthly unique series (calendar months).
     *
     * @return array{labels:string[],datasets:array<int,array>}
     */
    public function get_series_monthly($from, $to) {
        global $wpdb;
        $table = self::table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE_FORMAT(visit_date, '%%Y-%%m') AS ym, COUNT(DISTINCT visitor_id) AS c FROM {$table} WHERE visit_date >= %s AND visit_date <= %s GROUP BY ym ORDER BY ym ASC",
                $from,
                $to
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $map = array();
        foreach ($rows as $r) {
            $map[$r['ym']] = (int) $r['c'];
        }

        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(wp_timezone_string());
        $month_cursor = new DateTimeImmutable(substr($from, 0, 7) . '-01', $tz);
        $end_month = new DateTimeImmutable(substr($to, 0, 7) . '-01', $tz);

        $labels = array();
        $data = array();
        while ($month_cursor <= $end_month) {
            $ym = $month_cursor->format('Y-m');
            $labels[] = date_i18n('M Y', $month_cursor->getTimestamp());
            $data[] = isset($map[$ym]) ? $map[$ym] : 0;
            $month_cursor = $month_cursor->modify('first day of next month');
        }

        return array(
            'labels'   => $labels,
            'datasets' => array(
                array(
                    'label'            => __('Unique visitors (monthly)', 'viewer-counter'),
                    'data'             => $data,
                    'backgroundColor'  => 'rgba(0, 128, 96, 0.55)',
                    'borderColor'      => 'rgb(0, 128, 96)',
                    'borderWidth'      => 1,
                ),
            ),
        );
    }

    /**
     * Chart.js payload (wp_localize_script).
     *
     * @return array{daily:array,weekly:array,monthly:array,from:string,to:string}
     */
    public function get_dashboard_chart_payload() {
        $range = $this->parse_dashboard_date_range();
        $from = $range['from'];
        $to = $range['to'];
        return array(
            'daily'   => $this->get_series_daily($from, $to),
            'weekly'  => $this->get_series_weekly($from, $to),
            'monthly' => $this->get_series_monthly($from, $to),
            'from'    => $from,
            'to'      => $to,
        );
    }

    public function enqueue_admin_dashboard($hook) {
        if ($hook !== 'toplevel_page_viewer-counter') {
            return;
        }
        $payload = $this->get_dashboard_chart_payload();
        wp_enqueue_script(
            'viewer-counter-admin-dash',
            plugins_url('assets/admin-dashboard.js', __FILE__),
            array(),
            VIEWER_COUNTER_VERSION,
            true
        );
        wp_localize_script('viewer-counter-admin-dash', 'viewerCounterDash', $payload);
        wp_enqueue_style(
            'viewer-counter-admin-dash',
            plugins_url('assets/admin-dashboard.css', __FILE__),
            array(),
            VIEWER_COUNTER_VERSION
        );
    }

    public function render_dashboard_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $range = $this->parse_dashboard_date_range();
        $base = admin_url('admin.php?page=viewer-counter');
        $dashboard_nonce = wp_create_nonce('viewer_counter_dashboard_filter');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Viewer Counter — Dashboard', 'viewer-counter'); ?></h1>
            <p class="description">
                <?php esc_html_e('Charts are built from stored visit records. Filters use the site timezone (Settings → General).', 'viewer-counter'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=viewer-counter-settings')); ?>"><?php esc_html_e('Shortcode display settings', 'viewer-counter'); ?></a>
            </p>

            <div class="vc-dash-presets">
                <strong><?php esc_html_e('Quick ranges:', 'viewer-counter'); ?></strong>
                <?php
                $presets = array(
                    '7d'    => __('Last 7 days', 'viewer-counter'),
                    '30d'   => __('Last 30 days', 'viewer-counter'),
                    '90d'   => __('Last 90 days', 'viewer-counter'),
                    'month' => __('This month', 'viewer-counter'),
                    'year'  => __('This year', 'viewer-counter'),
                );
                foreach ($presets as $key => $label) {
                    $url = add_query_arg(
                        array(
                            'vc_preset'  => $key,
                            '_vc_nonce' => $dashboard_nonce,
                        ),
                        $base
                    );
                    echo '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a> ';
                }
                ?>
            </div>

            <form class="vc-dash-filters" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="viewer-counter">
                <?php wp_nonce_field('viewer_counter_dashboard_filter', '_vc_nonce'); ?>
                <div>
                    <label for="vc_from"><?php esc_html_e('From', 'viewer-counter'); ?></label>
                    <input type="date" id="vc_from" name="vc_from" value="<?php echo esc_attr($range['from']); ?>" required>
                </div>
                <div>
                    <label for="vc_to"><?php esc_html_e('To', 'viewer-counter'); ?></label>
                    <input type="date" id="vc_to" name="vc_to" value="<?php echo esc_attr($range['to']); ?>" required>
                </div>
                <div>
                    <?php submit_button(__('Apply filter', 'viewer-counter'), 'secondary', 'submit', false); ?>
                </div>
            </form>

            <div class="vc-dash-grid">
                <div class="vc-dash-card">
                    <h2><?php esc_html_e('Daily', 'viewer-counter'); ?></h2>
                    <p class="description"><?php esc_html_e('Unique visitor count for each day.', 'viewer-counter'); ?></p>
                    <div class="vc-chart-wrap">
                        <canvas id="vc-chart-daily" aria-label="<?php esc_attr_e('Daily chart', 'viewer-counter'); ?>"></canvas>
                    </div>
                </div>
                <div class="vc-dash-card">
                    <h2><?php esc_html_e('Weekly', 'viewer-counter'); ?></h2>
                    <p class="description"><?php esc_html_e('Unique visitors per week (Monday–Sunday, ISO).', 'viewer-counter'); ?></p>
                    <div class="vc-chart-wrap">
                        <canvas id="vc-chart-weekly" aria-label="<?php esc_attr_e('Weekly chart', 'viewer-counter'); ?>"></canvas>
                    </div>
                </div>
                <div class="vc-dash-card">
                    <h2><?php esc_html_e('Monthly', 'viewer-counter'); ?></h2>
                    <p class="description"><?php esc_html_e('Unique visitors per calendar month in the selected range.', 'viewer-counter'); ?></p>
                    <div class="vc-chart-wrap">
                        <canvas id="vc-chart-monthly" aria-label="<?php esc_attr_e('Monthly chart', 'viewer-counter'); ?>"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function register_settings() {
        register_setting(
            'viewer_counter',
            VIEWER_COUNTER_OPTION,
            array(
                'type'              => 'array',
                'sanitize_callback' => array($this, 'sanitize_settings'),
                'default'           => self::default_settings(),
            )
        );
    }

    public function sanitize_settings($input) {
        $input = is_array($input) ? $input : array();
        $out = array();
        foreach (self::display_keys() as $k) {
            $out['show_' . $k] = (!empty($input['show_' . $k]) && (int) $input['show_' . $k] === 1) ? 1 : 0;
        }
        return $out;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $labels = self::display_labels();
        $settings = $this->get_settings();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=viewer-counter')); ?>">&larr; <?php esc_html_e('Dashboard & charts', 'viewer-counter'); ?></a>
            </p>
            <p class="description"><?php esc_html_e('Choose default statistics for the shortcode (when the show attribute is omitted) and for the widget when no boxes are checked. Open charts from Viewer Counter → Dashboard.', 'viewer-counter'); ?></p>
            <form action="options.php" method="post">
                <?php settings_fields('viewer_counter'); ?>
                <table class="form-table" role="presentation">
                    <?php foreach (self::display_keys() as $key) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($labels[$key]); ?></th>
                            <td>
                                <label>
                                    <input name="<?php echo esc_attr(VIEWER_COUNTER_OPTION); ?>[show_<?php echo esc_attr($key); ?>]" type="checkbox" value="1" <?php checked(!empty($settings['show_' . $key])); ?>>
                                    <?php esc_html_e('Show', 'viewer-counter'); ?>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button(); ?>
            </form>
            <hr>
            <h2><?php esc_html_e('Shortcode', 'viewer-counter'); ?></h2>
            <p><code>[viewer_counter]</code> — <?php esc_html_e('uses the options above.', 'viewer-counter'); ?></p>
            <p><code>[viewer_counter show="daily"]</code> — <?php esc_html_e('daily only.', 'viewer-counter'); ?></p>
            <p><code>[viewer_counter show="daily,weekly,total"]</code> — <?php esc_html_e('custom mix (overrides site defaults).', 'viewer-counter'); ?></p>
            <p class="description"><?php esc_html_e('show values: daily, weekly, monthly, total (comma-separated).', 'viewer-counter'); ?></p>
            <p class="description"><?php esc_html_e('Security: visit recording uses prepared SQL, strict visitor UUID validation, shortcode attribute sanitization, oversized User-Agent rejection, and a per-IP hourly rate limit (adjustable via filters).', 'viewer-counter'); ?></p>
        </div>
        <?php
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'viewer_counter_visits';
    }

    /**
     * Strict visitor UUID (cookie / transient value) — rejects forged or malformed IDs before DB or Set-Cookie.
     *
     * @param string $id Lowercase canonical form.
     */
    private function is_valid_visitor_uuid($id) {
        if (!is_string($id) || $id === '') {
            return false;
        }
        $id = strtolower($id);
        if (function_exists('wp_is_uuid') && wp_is_uuid($id)) {
            return true;
        }
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
    }

    /**
     * Rolling per-IP cap on visit recording to mitigate DB spam / fake visitor floods.
     *
     * @return bool True when over limit (caller should skip INSERT).
     */
    private function is_visit_record_rate_limited() {
        if (apply_filters('viewer_counter_skip_rate_limit', false)) {
            return false;
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        if ($ip === '') {
            return true;
        }
        if (function_exists('wp_privacy_anonymize_ip')) {
            $ip = wp_privacy_anonymize_ip($ip);
        }
        $key = 'vc_iprl_' . md5($ip . '|viewer_counter_v1');
        $state = get_transient($key);
        if (!is_array($state) || empty($state['reset']) || time() > (int) $state['reset']) {
            $state = array(
                'reset' => time() + HOUR_IN_SECONDS,
                'count' => 0,
            );
        }
        $max = (int) apply_filters('viewer_counter_max_visits_per_ip_per_hour', 360);
        if ($max < 60) {
            $max = 60;
        }
        if ((int) $state['count'] >= $max) {
            set_transient($key, $state, max(1, (int) $state['reset'] - time()));
            return true;
        }
        $state['count'] = (int) $state['count'] + 1;
        set_transient($key, $state, max(60, (int) $state['reset'] - time()));
        return false;
    }

    /**
     * Sanitize shortcode "class" attribute (alphanumeric, hyphen, underscore, spaces only).
     *
     * @param string $class Raw attribute.
     * @return string Safe class string.
     */
    private function sanitize_viewer_counter_class_attr($class) {
        $class = is_string($class) ? wp_strip_all_tags($class) : '';
        $class = preg_replace('/[^A-Za-z0-9_\- ]/', '', $class);
        $class = preg_replace('/\s+/', ' ', trim($class));
        if ($class === '') {
            return 'viewer-counter';
        }
        $parts = preg_split('/\s+/', $class, -1, PREG_SPLIT_NO_EMPTY);
        $out = array();
        foreach ($parts as $p) {
            $s = sanitize_html_class($p);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return $out ? implode(' ', $out) : 'viewer-counter';
    }

    /**
     * Skip common bots so stats are not skewed.
     */
    private function is_likely_bot() {
        if (is_admin() || (defined('DOING_CRON') && DOING_CRON) || (defined('REST_REQUEST') && REST_REQUEST)) {
            return true;
        }
        if (wp_doing_ajax()) {
            return true;
        }
        if (!isset($_SERVER['HTTP_USER_AGENT']) || $_SERVER['HTTP_USER_AGENT'] === '') {
            return true;
        }
        $ua_raw = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']));
        $max_ua = (int) apply_filters('viewer_counter_max_user_agent_length', VIEWER_COUNTER_MAX_UA_LENGTH);
        if ($max_ua > 0 && strlen($ua_raw) > $max_ua) {
            return true;
        }
        $ua = strtolower($ua_raw);
        $bots = array('bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'facebookexternalhit', 'embedly', 'preview', 'lighthouse', 'pingdom', 'uptime');
        foreach ($bots as $b) {
            if (strpos($ua, $b) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Lightweight fingerprint (not personal data) for visitors without a cookie yet.
     */
    private function get_request_fingerprint() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        if (function_exists('wp_privacy_anonymize_ip')) {
            $ip = wp_privacy_anonymize_ip($ip);
        }
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $max_ua = (int) apply_filters('viewer_counter_max_user_agent_length', VIEWER_COUNTER_MAX_UA_LENGTH);
        if ($max_ua > 0 && strlen($ua) > $max_ua) {
            $ua = substr($ua, 0, $max_ua);
        }
        return substr(md5($ip . "\n" . $ua), 0, 32);
    }

    private function new_visitor_uuid() {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            wp_rand(0, 0xffff),
            wp_rand(0, 0xffff),
            wp_rand(0, 0xffff),
            wp_rand(0, 0x0fff) | 0x4000,
            wp_rand(0, 0x3fff) | 0x8000,
            wp_rand(0, 0xffff),
            wp_rand(0, 0xffff),
            wp_rand(0, 0xffff)
        );
    }

    private function get_or_create_visitor_id() {
        if (isset($_COOKIE[VIEWER_COUNTER_COOKIE]) && is_string($_COOKIE[VIEWER_COUNTER_COOKIE])) {
            $cookie_value = sanitize_text_field(wp_unslash($_COOKIE[VIEWER_COUNTER_COOKIE]));
            $id = preg_replace('/[^a-f0-9\-]/', '', strtolower($cookie_value));
            if ($this->is_valid_visitor_uuid($id)) {
                return $id;
            }
        }
        $tkey = 'viewer_counter_vid_' . $this->get_request_fingerprint();
        $cached = get_transient($tkey);
        if (is_string($cached)) {
            $cached = preg_replace('/[^a-f0-9\-]/', '', strtolower($cached));
            if ($this->is_valid_visitor_uuid($cached)) {
                return $cached;
            }
        }
        $id = $this->new_visitor_uuid();
        set_transient($tkey, $id, 2 * DAY_IN_SECONDS);
        return $id;
    }

    private function set_visitor_cookie($visitor_id) {
        if (!$this->is_valid_visitor_uuid((string) $visitor_id)) {
            return;
        }
        if (headers_sent()) {
            return;
        }
        $expire = time() + (DAY_IN_SECONDS * VIEWER_COUNTER_COOKIE_DAYS);
        $secure = is_ssl();
        $params = array(
            'expires'  => $expire,
            'path'     => COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => COOKIE_DOMAIN,
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        );
        if (PHP_VERSION_ID >= 70300) {
            setcookie(VIEWER_COUNTER_COOKIE, $visitor_id, $params);
        } else {
            setcookie(VIEWER_COUNTER_COOKIE, $visitor_id, $expire, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, $secure, true);
        }
    }

    public function maybe_record_visit() {
        if ($this->is_likely_bot() || wp_is_json_request()) {
            return;
        }
        if (is_user_logged_in() && current_user_can('manage_options') && apply_filters('viewer_counter_skip_admin', true)) {
            return;
        }

        $visitor_id = $this->get_or_create_visitor_id();
        if (!$this->is_valid_visitor_uuid($visitor_id)) {
            return;
        }

        $this->set_visitor_cookie($visitor_id);

        if ($this->is_visit_record_rate_limited()) {
            return;
        }

        global $wpdb;
        $table = self::table_name();
        $today = current_time('Y-m-d');

        // At most one row per visitor per calendar day.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$table} (visitor_id, visit_date) VALUES (%s, %s)",
                $visitor_id,
                $today
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    public function get_counts($keys = null) {
        global $wpdb;
        $table = self::table_name();
        $all_keys = array('daily', 'weekly', 'monthly', 'total');
        $want = $keys === null ? $all_keys : array_values(array_intersect($all_keys, (array) $keys));
        $result = array_fill_keys($all_keys, 0);
        if ($want === array()) {
            return $result;
        }

        $today = current_time('Y-m-d');
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(wp_timezone_string());
        $week_start = (new DateTimeImmutable($today, $tz))->modify('-6 days')->format('Y-m-d');
        $month_start = current_time('Y-m-01');

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if (in_array('daily', $want, true)) {
            $result['daily'] = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE visit_date = %s", $today)
            );
        }
        if (in_array('weekly', $want, true)) {
            $result['weekly'] = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT visitor_id) FROM {$table} WHERE visit_date >= %s AND visit_date <= %s",
                    $week_start,
                    $today
                )
            );
        }
        if (in_array('monthly', $want, true)) {
            $result['monthly'] = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT visitor_id) FROM {$table} WHERE visit_date >= %s AND visit_date <= %s",
                    $month_start,
                    $today
                )
            );
        }
        if (in_array('total', $want, true)) {
            $result['total'] = (int) $wpdb->get_var("SELECT COUNT(DISTINCT visitor_id) FROM {$table}");
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        return $result;
    }

    /**
     * Build one line of text, e.g. "Visitors Daily : 3 Weekly : 25 …"
     *
     * @param array $counts Result of get_counts().
     * @param array $display_keys Ordered keys to include.
     */
    public function format_stats_line($counts, $display_keys) {
        $display_keys = array_values(array_intersect(self::display_keys(), (array) $display_keys));
        if ($display_keys === array()) {
            return '';
        }
        $labels = self::display_labels();
        $chunks = array(__('Visitors', 'viewer-counter'));
        foreach ($display_keys as $key) {
            $chunks[] = $labels[$key] . ' : ' . (int) $counts[$key];
        }
        return implode(' ', $chunks);
    }

    public function enqueue_assets() {
        wp_register_style(
            'viewer-counter',
            plugins_url('assets/viewer-counter.css', __FILE__),
            array(),
            VIEWER_COUNTER_VERSION
        );
    }

    public function shortcode($atts) {
        $atts = shortcode_atts(
            array(
                'class' => 'viewer-counter',
                'show'  => '',
            ),
            $atts,
            'viewer_counter'
        );
        wp_enqueue_style('viewer-counter');
        $show_param = strtolower(preg_replace('/[^a-z,]/', '', sanitize_text_field(trim((string) $atts['show']))));
        $display_keys = $this->resolve_display_keys($show_param !== '' ? $show_param : null, null);
        $counts = $this->get_counts($display_keys);
        $text = $this->format_stats_line($counts, $display_keys);
        if ($text === '') {
            return '';
        }
        $class = $this->sanitize_viewer_counter_class_attr((string) $atts['class']);
        return sprintf(
            '<span class="%s">%s</span>',
            esc_attr($class),
            esc_html($text)
        );
    }

    public function register_widget() {
        register_widget('Viewer_Counter_Widget');
    }
}

/**
 * Simple widget output.
 */
class Viewer_Counter_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'viewer_counter_widget',
            __('Viewer Counter', 'viewer-counter'),
            array('description' => __('Show unique visitor counts.', 'viewer-counter'))
        );
    }

    public function widget($args, $instance) {
        echo wp_kses_post($args['before_widget']);
        if (!empty($instance['title'])) {
            $title = apply_filters('widget_title', $instance['title'], $instance, $this->id_base);
            echo wp_kses_post($args['before_title']) . esc_html($title) . wp_kses_post($args['after_title']);
        }
        wp_enqueue_style('viewer-counter');
        $flags = array();
        foreach (Viewer_Counter::display_keys() as $k) {
            $flags['show_' . $k] = !empty($instance['show_' . $k]);
        }
        $vc = Viewer_Counter::instance();
        $display_keys = $vc->resolve_display_keys(null, $flags);
        $counts = $vc->get_counts($display_keys);
        $text = $vc->format_stats_line($counts, $display_keys);
        if ($text !== '') {
            echo '<p class="viewer-counter">' . esc_html($text) . '</p>';
        }
        echo wp_kses_post($args['after_widget']);
    }

    public function form($instance) {
        $title = isset($instance['title']) ? $instance['title'] : '';
        $settings = Viewer_Counter::instance()->get_settings();
        $labels = Viewer_Counter::display_labels();
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title:', 'viewer-counter'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                   name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text"
                   value="<?php echo esc_attr($title); ?>">
        </p>
        <p class="description"><?php esc_html_e('If none are checked, the widget follows Viewer Counter → Settings.', 'viewer-counter'); ?></p>
        <?php foreach (Viewer_Counter::display_keys() as $key) : ?>
            <?php
            $field = 'show_' . $key;
            $val = array_key_exists($field, $instance) ? (int) $instance[$field] : (int) $settings[$field];
            ?>
            <p>
                <label>
                    <input type="checkbox" id="<?php echo esc_attr($this->get_field_id($field)); ?>"
                           name="<?php echo esc_attr($this->get_field_name($field)); ?>" value="1" <?php checked($val, 1); ?>>
                    <?php echo esc_html($labels[$key]); ?>
                </label>
            </p>
        <?php endforeach; ?>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['title'] = sanitize_text_field($new_instance['title']);
        foreach (Viewer_Counter::display_keys() as $k) {
            $instance['show_' . $k] = !empty($new_instance['show_' . $k]) ? 1 : 0;
        }
        return $instance;
    }
}

/**
 * Create database table on activation.
 */
function viewer_counter_activate() {
    global $wpdb;
    $table = $wpdb->prefix . 'viewer_counter_visits';
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        visitor_id varchar(64) NOT NULL,
        visit_date date NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY visitor_day (visitor_id, visit_date),
        KEY visit_date (visit_date)
    ) {$charset};";
    dbDelta($sql);
    if (false === get_option(VIEWER_COUNTER_OPTION)) {
        add_option(VIEWER_COUNTER_OPTION, Viewer_Counter::default_settings());
    }
}

register_activation_hook(__FILE__, 'viewer_counter_activate');

Viewer_Counter::instance();
