<?php
/*
Plugin Name: 网站卡片
Version: 5.0.0 
Tested up to: 6.5.1
Description: 完全支持URL存储的卡片插件终极版 | 修复Final版
*/
#if (!defined('ABSPATH')) exit;

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

// === 原核心类 ===
class Chf_Card_Plugin_Core {
    public function __construct() {
        add_shortcode('custom_card', [$this, 'handle_shortcode']);
        add_action('wp_ajax_nopriv_load_custom_card', [$this, 'handle_ajax_request']); // 新增这行
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
    
    
    private function is_rate_limited() {
        // ✅ IP地址标识（支持代理场景）
        $client_ip = $this->get_client_ip();

        // ✅ 使用 WordPress 瞬态API
        $cache_key = 'chf_card_rate_limit_' . $client_ip;
        $request_count = get_transient($cache_key) ?: 0;

        // 📦 限流阈值：10 次/分钟（可按需调整）
        if ($request_count >= 10) {
            return true;
        }

        // ⏳ 设置过期时间（60秒）并自动累加
        set_transient($cache_key, $request_count + 1, 60);
        return false;
    }

    private function get_client_ip() {
        // ✅ 多重代理环境支持（需要结合CDN设定）
        return $_SERVER['HTTP_X_REAL_IP'] ?? 
               $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 
               $_SERVER['REMOTE_ADDR'] ?? 
               '0.0.0.0';
    }    
    

    public function retrieve_card_data($user_input) {
        // ✅ 限流机制（示例）
        if ($this->is_rate_limited()) {
            return ['error' => '请求过于频繁'];
        }
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
            // ✅ 新增title空值刷新机制
            if (empty($cache->title) && strtotime($cache->expires_at) < time()) {
                $metadata = $this->fetch_url_metadata($raw_url);
                $merged = array_merge($base_data, ['title' => $cache->title, 'image' => $cache->image, 'description' => $cache->description]);

                    // 强制更新缓存
                $this->update_cache($url_hash, $merged); 
            } else {
                $merged = array_merge($base_data, ['title' => $cache->title, 'image' => $cache->image, 'description' => $cache->description]);
            }
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
    private function update_cache($url_hash, $data) {
        global $wpdb;
        $wpdb->replace($wpdb->prefix . 'chf_card_cache', [
            'url_hash' => $url_hash,
            'title' => sanitize_text_field($data['title']),
            'image' => esc_url_raw($data['image']),
            'description' => sanitize_textarea_field($data['description']),
            'expires_at' => date('Y-m-d H:i:s', time() + (ChfmCard_DBManager::CACHE_EXPIRE_HOURS * 3600))
        ]);
    }
    // 🛠️ 新增清洗方法（保持现有结构最小改动）
    private function sanitize_field($value) {
        return preg_replace('/^https?:\/\/\S+\s+/', '', $value);
    }

    // 修改 fetch_url_metadata 方法（约第60行）
    private function fetch_url_metadata($url) {
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'sslverify' => true,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36',
            'headers' => ['Accept-Language' => 'en-US,en;q=0.9']
        ]);

        if (is_wp_error($response)) {
            return ['title' => parse_url($url, PHP_URL_HOST), 'image' => '', 'description' => ''];
        }

        $html = wp_remote_retrieve_body($response);
        return $this->parse_html_metadata($html, $url); // 新增参数$url用于处理相对路径
    }


    // 完全替换 parse_html_metadata 方法
    private function parse_html_metadata($html, $base_url) {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    
        $xpath = new DOMXPath($dom);
    
        // 多来源标题抓取
        $title = $this->get_meta_content($dom, [
            'og:title', 'twitter:title', 'itemprop=name'
        ]) ?: $this->get_dom_text($dom, 'title') ?: parse_url($base_url, PHP_URL_HOST);

        // 多来源描述抓取
        $description = $this->get_meta_content($dom, [
            'og:description', 'twitter:description', 'itemprop=description', 'description'
        ]) ?: '';

        // 多来源图片抓取
        $image = $this->get_meta_content($dom, [
            'og:image', 'twitter:image:src', 'itemprop=image'
        ]);
        $image = $image ? $this->resolve_relative_url($image, $base_url) : '';

        return [
            'title' => $this->sanitize_field($title),
            'description' => $this->sanitize_field($description),
            'image' => esc_url_raw($image)
        ];
    }
    
    // 新增辅助方法（放在类内任意位置）
     private function get_meta_content($dom, $properties) {
        $xpath = new DOMXPath($dom);
        foreach ($properties as $prop) {
            $query = isset(explode('=', $prop)[1]) ? 
                "//meta[contains(@itemprop, '".str_replace('itemprop=', '', $prop)."')]" : 
                "//meta[contains(@property, '$prop') or contains(@name, '$prop')]";
        
            $meta = $xpath->query($query);
            if ($meta->length > 0 && $content = $meta->item(0)->getAttribute('content')) {
                return $content;
            }
        }
        return null;
    }

    private function get_dom_text($dom, $tagName) {
        $elements = $dom->getElementsByTagName($tagName);
        return $elements->length > 0 ? $elements->item(0)->nodeValue : null;
    }

    private function resolve_relative_url($path, $base) {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }
        $baseParts = parse_url($base);
        return $baseParts['scheme'].'://'.$baseParts['host'].(isset($baseParts['port'])?':'.$baseParts['port']:'').'/'.ltrim($path, '/');
    }    
    
    
    
    }

    
    
// ==============================
// 修改 custom-plugin.php 添加区块注册逻辑
// ==============================
// 加在插件初始化钩子或 installer 里注册：
function custom_card_register_block() {
    register_block_type(__DIR__ . '/blocks/custom-card', array(
    'render_callback' => 'custom_card_render_callback',
    ));
}
add_action('init', 'custom_card_register_block');


// ==============================
// 渲染卡片的 PHP 回调（可放入 template/card.php）
// ==============================

function custom_card_render_callback($attributes, $content) {
    $default = ['url' => '', 'title' => '', 'image' => '', 'description' => ''];
    $atts = shortcode_atts($default, $attributes, 'custom_card');

    if (empty($atts['url']) || !filter_var($atts['url'], FILTER_VALIDATE_URL)) {
        return '<div class="card-error">✖️ 无效的URL参数</div>';
    }

    // ✅ 复用核心逻辑
    $plugin_core = new Chf_Card_Plugin_Core();
    $data = $plugin_core->retrieve_card_data($atts);
    
    
    ob_start();
    include plugin_dir_path(__FILE__) . 'template/card.php';
    return ob_get_clean();
}

function custom_card_enqueue_block_editor_assets() {
    wp_enqueue_script(
        'custom-card-block-editor',
        plugins_url('blocks/custom-card/index.js', __FILE__),
        array('wp-blocks', 'wp-i18n', 'wp-element', 'wp-block-editor'),
        filemtime(plugin_dir_path(__FILE__) . 'blocks/custom-card/index.js')
    );
}
add_action('enqueue_block_editor_assets', 'custom_card_enqueue_block_editor_assets');



new Chf_Card_Plugin_Core();