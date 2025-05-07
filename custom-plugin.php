<?php
/*
Plugin Name: Card
Version: 4.2.0 
Tested up to: 6.5.1  // 更新为当前版本的 WordPress
Description: 完全支持URL存储的卡片插件终极版 | 修复Final版
*/
if (!defined('ABSPATH')) exit;

// === 原封不动的数据库管家 ===
class ChfmCard_DBManager {
    const CACHE_EXPIRE_HOURS = 72;

    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php'; // 🛠️ 修复dbDelta不存在的问题

        $table = $wpdb->prefix . 'chf_card_cache';
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            url_hash CHAR(32) NOT NULL COMMENT 'URL的MD5哈希',
            url VARCHAR(512) NOT NULL COMMENT '原始URL',
            title VARCHAR(255) NOT NULL DEFAULT '' COMMENT '卡片标题',
            image VARCHAR(512) NOT NULL DEFAULT '' COMMENT '图片URL',
            description TEXT NOT NULL COMMENT '描述内容',
            expires_at DATETIME NOT NULL COMMENT '缓存失效时间',
            PRIMARY KEY (url_hash),
            INDEX url_index (url(191))
        ) " . $wpdb->get_charset_collate();

        dbDelta($sql);
    }
}
register_activation_hook(__FILE__, ['ChfmCard_DBManager', 'create_tables']);

// === 原核心类（完全保留原有结构+新增清洗） ===
class Chf_Card_Plugin_Core {
    public function __construct() {
        add_shortcode('custom_card', [$this, 'handle_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'load_assets']);
    }

    public function load_assets() {
        $base_path = plugin_dir_path(__FILE__);
        $css_path = $base_path . 'assets/chf-card.css';
        if (file_exists($css_path)) {
            wp_enqueue_style('custom-card-style', plugins_url('assets/chf-card.css', __FILE__), array(), filemtime($css_path));
        }
        $js_path = $base_path . 'assets/chf-card.js';
        if (file_exists($js_path)) {
            wp_enqueue_script('custom-card-script', plugins_url('assets/chf-card.js', __FILE__), array('jquery'), filemtime($js_path), true);
        }
    }

    public function handle_shortcode($atts) {
        $atts = shortcode_atts(['url' => '','title' => '','image' => '','description' => ''], $atts, 'chf_card');
        if (empty($atts['url']) || !filter_var($atts['url'], FILTER_VALIDATE_URL)) {
            return '<div class="card-error">✖️ 无效的URL参数</div>';
        }
        $data = $this->retrieve_card_data($atts);
        ob_start();
        include plugin_dir_path(__FILE__) . 'template/card.php';
        return ob_get_clean();
    }

    private function retrieve_card_data($user_input) {
        global $wpdb;
        $raw_url = esc_url_raw($user_input['url']);
        $url_hash = md5($raw_url);
        $table = $wpdb->prefix . 'chf_card_cache';

        // 🛠️ 修复字段清洗逻辑（新增以下4行）
        $user_input['title'] = $this->sanitize_field($user_input['title'] ?? '');
        $user_input['image'] = $this->sanitize_field($user_input['image'] ?? '');
        $user_input['description'] = $this->sanitize_field($user_input['description'] ?? '');

        $cache = $wpdb->get_row($wpdb->prepare("SELECT title, image, description FROM $table WHERE url_hash = %s AND expires_at > NOW() LIMIT 1", $url_hash));
        $base_data = ['url' => $raw_url, 'title' => $user_input['title'], 'image' => $user_input['image'], 'description' => $user_input['description']];

        if ($cache) {
            $merged = array_merge($base_data, ['title' => $cache->title, 'image' => $cache->image, 'description' => $cache->description]);
        } else {
            $metadata = $this->fetch_url_metadata($raw_url);
            $merged = array_merge($base_data, [
                'title' => $metadata['title'],
                'image' => $metadata['image'],
                'description' => $metadata['description']
            ]);
            $wpdb->replace($table, [
                'url_hash' => $url_hash,
                'url' => $raw_url,
                'title' => sanitize_text_field($merged['title']),
                'image' => esc_url_raw($merged['image']),
                'description' => sanitize_textarea_field($merged['description']),
                'expires_at' => date('Y-m-d H:i:s', time() + (ChfmCard_DBManager::CACHE_EXPIRE_HOURS * 3600))
            ]);
        }
        return $merged;
    }

    // 🛠️ 新增清洗方法（保持现有结构最小改动）
    private function sanitize_field($value) {
        return preg_replace('/^https?:\/\/\S+\s+/', '', $value);
    }

    private function fetch_url_metadata($url) {
        $response = wp_remote_get($url, ['timeout' => 10, 'sslverify' => false, 'user-agent' => 'Mozilla/5.0 (compatible; CustomCardBot/1.0)']);
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return ['title' => parse_url($url, PHP_URL_HOST), 'image' => '', 'description' => ''];
        }
        return $this->parse_html_metadata(wp_remote_retrieve_body($response), $url);
    }

    private function parse_html_metadata($html, $base_url) {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $parse_rules = [
            'title' => ['tags' => ['og:title','twitter:title'], 'chain' => [['dom:title']]],
            'image' => ['tags' => ['og:image','twitter:image'], 'chain' => []],
            'description' => ['tags' => ['og:description','twitter:description'], 'chain' => [['meta[name="description"]','content']]]
        ];
        $results = [];
        foreach ($parse_rules as $field => $rule) {
            $value = '';
            foreach ($rule['tags'] as $tag) {
                foreach ($dom->getElementsByTagName('meta') as $meta) {
                    if (($meta->getAttribute('property') == $tag || $meta->getAttribute('name') == $tag) && $meta->getAttribute('content')) {
                        $value = $meta->getAttribute('content');
                        break 2;
                    }
                }
            }
            // 🛠️ 元数据清洗
            $results[$field] = $this->sanitize_field($value);
        }
        return $results;
    }
}

new Chf_Card_Plugin_Core();