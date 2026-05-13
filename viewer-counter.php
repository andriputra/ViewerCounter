<?php
/**
 * Plugin Name: Viewer Counter
 * Plugin URI: https://flexbox.my.id/viewer-counter
 * Description: Menghitung jumlah pengunjung unik (harian, mingguan, bulanan) dan statistik ringkas di halaman.
 * Version: 1.0.0
 * Author: Agus Andri Putra
 * Author URI: https://flexbox.my.id
 * License: GPL v2 or later
 * Text Domain: viewer-counter
 */

if (!defined('ABSPATH')) {
    exit;
}

define('VIEWER_COUNTER_VERSION', '1.0.0');
define('VIEWER_COUNTER_COOKIE', 'wp_vc_vid');
define('VIEWER_COUNTER_COOKIE_DAYS', 400);

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
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_shortcode('viewer_counter', array($this, 'shortcode'));
        add_action('widgets_init', array($this, 'register_widget'));
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'viewer_counter_visits';
    }

    /**
     * Elakkan bot mudah daripada menggoncang statistik.
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
        $ua = strtolower($_SERVER['HTTP_USER_AGENT']);
        $bots = array('bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'facebookexternalhit', 'embedly', 'preview', 'lighthouse', 'pingdom', 'uptime');
        foreach ($bots as $b) {
            if (strpos($ua, $b) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Cap jari ringkas (bukan storan peribadi) untuk pelawat belum ada kuki — elak beberapa ID sebelum kuki tiba.
     */
    private function get_request_fingerprint() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if (function_exists('wp_privacy_anonymize_ip')) {
            $ip = wp_privacy_anonymize_ip($ip);
        }
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        return substr(md5($ip . "\n" . $ua), 0, 32);
    }

    private function new_visitor_uuid() {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    private function get_or_create_visitor_id() {
        if (isset($_COOKIE[VIEWER_COUNTER_COOKIE]) && is_string($_COOKIE[VIEWER_COUNTER_COOKIE])) {
            $id = preg_replace('/[^a-f0-9\-]/', '', strtolower($_COOKIE[VIEWER_COUNTER_COOKIE]));
            if (strlen($id) === 36) {
                return $id;
            }
        }
        $tkey = 'viewer_counter_vid_' . $this->get_request_fingerprint();
        $cached = get_transient($tkey);
        if (is_string($cached)) {
            $cached = preg_replace('/[^a-f0-9\-]/', '', strtolower($cached));
            if (strlen($cached) === 36) {
                return $cached;
            }
        }
        $id = $this->new_visitor_uuid();
        set_transient($tkey, $id, 2 * DAY_IN_SECONDS);
        return $id;
    }

    private function set_visitor_cookie($visitor_id) {
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
        $this->set_visitor_cookie($visitor_id);

        global $wpdb;
        $table = self::table_name();
        $today = current_time('Y-m-d');

        // Satu baris maksimum setiap pelawat setiap hari (unik harian).
        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$table} (visitor_id, visit_date) VALUES (%s, %s)",
                $visitor_id,
                $today
            )
        );
    }

    public function get_counts() {
        global $wpdb;
        $table = self::table_name();
        $today = current_time('Y-m-d');
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(wp_timezone_string());
        $week_start = (new DateTimeImmutable($today, $tz))->modify('-6 days')->format('Y-m-d');

        $month_start = current_time('Y-m-01');

        $daily = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE visit_date = %s", $today)
        );

        $weekly = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT visitor_id) FROM {$table} WHERE visit_date >= %s AND visit_date <= %s",
                $week_start,
                $today
            )
        );

        $monthly = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT visitor_id) FROM {$table} WHERE visit_date >= %s AND visit_date <= %s",
                $month_start,
                $today
            )
        );

        return array(
            'daily'   => $daily,
            'weekly'  => $weekly,
            'monthly' => $monthly,
        );
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
            ),
            $atts,
            'viewer_counter'
        );
        wp_enqueue_style('viewer-counter');
        $c = $this->get_counts();
        $html = sprintf(
            '<span class="%s">%s</span>',
            esc_attr($atts['class']),
            sprintf(
                /* translators: 1: daily count, 2: weekly count, 3: monthly count */
                esc_html__('Jumlah Pelawat Harian : %1$d Mingguan : %2$d Bulanan : %3$d', 'viewer-counter'),
                $c['daily'],
                $c['weekly'],
                $c['monthly']
            )
        );
        return $html;
    }

    public function register_widget() {
        register_widget('Viewer_Counter_Widget');
    }
}

/**
 * Widget paparan ringkas.
 */
class Viewer_Counter_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'viewer_counter_widget',
            __('Viewer Counter', 'viewer-counter'),
            array('description' => __('Paparkan jumlah pelawat unik.', 'viewer-counter'))
        );
    }

    public function widget($args, $instance) {
        echo $args['before_widget'];
        if (!empty($instance['title'])) {
            echo $args['before_title'] . apply_filters('widget_title', $instance['title']) . $args['after_title'];
        }
        wp_enqueue_style('viewer-counter');
        $c = Viewer_Counter::instance()->get_counts();
        echo '<p class="viewer-counter">';
        printf(
            esc_html__('Jumlah Pelawat Harian : %1$d Mingguan : %2$d Bulanan : %3$d', 'viewer-counter'),
            (int) $c['daily'],
            (int) $c['weekly'],
            (int) $c['monthly']
        );
        echo '</p>';
        echo $args['after_widget'];
    }

    public function form($instance) {
        $title = isset($instance['title']) ? $instance['title'] : '';
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Tajuk:', 'viewer-counter'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                   name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text"
                   value="<?php echo esc_attr($title); ?>">
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['title'] = sanitize_text_field($new_instance['title']);
        return $instance;
    }
}

/**
 * Pasang jadual semasa pengaktifan.
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
}

register_activation_hook(__FILE__, 'viewer_counter_activate');

Viewer_Counter::instance();
