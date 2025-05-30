<?php
/*
Plugin Name: 网站卡片
Version: 5.1.0 
Tested up to: 6.5.1
Description: 完全支持URL存储的卡片插件终极版 | 安全增强版
*/
// 安全检查：防止直接访问PHP文件
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class ChfmCard_DBManager {
    const CACHE_EXPIRE_HOURS = 72;

    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php'; // 🛠️ 修复dbDelta不存在的问题

        $table = $wpdb->prefix . 'chf_card_cache';
        $charset_collate = $wpdb->get_charset_collate(); // 使用WordPress推荐的字符集和排序规则

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            url_hash CHAR(32) NOT NULL COMMENT 'URL的MD5哈希',
            url VARCHAR(512) NOT NULL COMMENT '原始URL',
            title VARCHAR(255) NOT NULL DEFAULT '' COMMENT '卡片标题',
            image VARCHAR(512) NOT NULL DEFAULT '' COMMENT '图片URL',
            description TEXT NOT NULL COMMENT '描述内容',
            expires_at DATETIME NOT NULL COMMENT '缓存失效时间',
            PRIMARY KEY (url_hash),
            INDEX url_index (url(191))
        ) $charset_collate";

        dbDelta($sql);
        
        // 记录数据库操作日志
        error_log('ChfmCard: Database tables created or updated');
    }
    
    // 安全删除表方法
    public static function uninstall_tables() {
        // 仅在插件卸载时执行，且需要管理员权限
        if (!current_user_can('activate_plugins')) {
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'chf_card_cache';
        $wpdb->query("DROP TABLE IF EXISTS $table");
        
        // 记录卸载日志
        error_log('ChfmCard: Database tables removed during uninstall');
    }
}

// 注册激活和卸载钩子
register_activation_hook(__FILE__, ['ChfmCard_DBManager', 'create_tables']);
register_uninstall_hook(__FILE__, ['ChfmCard_DBManager', 'uninstall_tables']);

// === 核心类 ===
class Chf_Card_Plugin_Core {
    // 定义插件常量
    const PLUGIN_VERSION = '5.1.0';
    const NONCE_ACTION = 'chf_card_security';
    const RATE_LIMIT_THRESHOLD = 10; // 每分钟请求限制
    
    public function __construct() {
        add_shortcode('custom_card', [$this, 'handle_shortcode']);
        
        // 分离AJAX处理，增加nonce验证
        add_action('wp_ajax_load_custom_card', [$this, 'handle_ajax_request']);
        add_action('wp_ajax_nopriv_load_custom_card', [$this, 'handle_ajax_request']);
        
        add_action('wp_enqueue_scripts', [$this, 'load_assets']);
    }

    public function load_assets() {
        $base_path = plugin_dir_path(__FILE__);
        $css_path = $base_path . 'assets/chf-card.css';
        if (file_exists($css_path)) {
            wp_enqueue_style(
                'custom-card-style', 
                plugins_url('assets/chf-card.css', __FILE__), 
                array(), 
                filemtime($css_path)
            );
        }
        
        $js_path = $base_path . 'assets/chf-card.js';
        if (file_exists($js_path)) {
            wp_enqueue_script(
                'custom-card-script', 
                plugins_url('assets/chf-card.js', __FILE__), 
                array('jquery'), 
                filemtime($js_path), 
                true
            );
            
            // 添加安全nonce到JS
            wp_localize_script('custom-card-script', 'customCardAjax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(self::NONCE_ACTION),
            ));
        }
    }

    /**
     * 处理短代码
     * 
     * @param array $atts 短代码属性
     * @return string 渲染后的HTML
     */
    public function handle_shortcode($atts) {
        $atts = shortcode_atts(
            [
                'url' => '',
                'title' => '',
                'image' => '',
                'description' => ''
            ], 
            $atts, 
            'chf_card'
        );
        
        // 严格URL验证
        if (empty($atts['url']) || !$this->is_valid_url($atts['url'])) {
            return '<div class="card-error">✖️ 无效的URL参数</div>';
        }
        
        // 获取卡片数据
        $data = $this->retrieve_card_data($atts);
        
        // 检查是否有错误
        if (isset($data['error'])) {
            return '<div class="card-error">✖️ ' . esc_html($data['error']) . '</div>';
        }
        
        // 渲染模板
        ob_start();
        include plugin_dir_path(__FILE__) . 'template/card.php';
        return ob_get_clean();
    }
    
    /**
     * 处理AJAX请求
     */
    public function handle_ajax_request() {
        // 验证nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], self::NONCE_ACTION)) {
            wp_send_json_error([
                'message' => '安全验证失败',
            ]);
            return;
        }
        
        // 验证URL参数
        if (empty($_POST['url']) || !$this->is_valid_url($_POST['url'])) {
            wp_send_json_error([
                'message' => '无效的URL参数',
            ]);
            return;
        }
        
        // 限流检查
        if ($this->is_rate_limited()) {
            wp_send_json_error([
                'message' => '请求过于频繁，请稍后再试',
            ]);
            return;
        }
        
        // 获取卡片数据
        $atts = [
            'url' => sanitize_url($_POST['url']),
            'title' => '',
            'image' => '',
            'description' => '',
        ];
        
        $data = $this->retrieve_card_data($atts);
        
        // 检查是否有错误
        if (isset($data['error'])) {
            wp_send_json_error([
                'message' => $data['error'],
            ]);
            return;
        }
        
        // 渲染卡片HTML
        ob_start();
        include plugin_dir_path(__FILE__) . 'template/card.php';
        $html = ob_get_clean();
        
        wp_send_json_success([
            'html' => $html,
        ]);
    }
    
    /**
     * 验证URL是否有效且安全
     * 
     * @param string $url 要验证的URL
     * @return bool 是否有效
     */
    private function is_valid_url($url) {
        // 基本URL格式验证
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // 解析URL
        $parsed_url = parse_url($url);
        
        // 检查必要的URL组件
        if (!isset($parsed_url['scheme']) || !isset($parsed_url['host'])) {
            return false;
        }
        
        // 只允许http和https协议
        if (!in_array($parsed_url['scheme'], ['http', 'https'])) {
            return false;
        }
        
        // 防止本地主机和内网IP访问（SSRF防护）
        $host = $parsed_url['host'];
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return false;
        }
        
        // 检查是否为内网IP
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ip_segments = explode('.', $host);
            // 检查常见内网IP范围
            if (
                $ip_segments[0] == 10 || 
                ($ip_segments[0] == 172 && $ip_segments[1] >= 16 && $ip_segments[1] <= 31) || 
                ($ip_segments[0] == 192 && $ip_segments[1] == 168)
            ) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * 检查是否超出请求频率限制
     * 
     * @return bool 是否被限流
     */
    private function is_rate_limited() {
        // 获取客户端IP
        $client_ip = $this->get_client_ip();

        // 使用WordPress瞬态API
        $cache_key = 'chf_card_rate_limit_' . md5($client_ip);
        $request_count = get_transient($cache_key) ?: 0;

        // 检查是否超出限制阈值
        if ($request_count >= self::RATE_LIMIT_THRESHOLD) {
            // 记录限流日志
            error_log('ChfmCard: Rate limit exceeded for IP: ' . $client_ip);
            return true;
        }

        // 设置过期时间（60秒）并自动累加
        set_transient($cache_key, $request_count + 1, 60);
        return false;
    }

    /**
     * 获取客户端IP地址
     * 
     * @return string 客户端IP
     */
    private function get_client_ip() {
        // 安全获取IP地址
        $ip_keys = array(
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );
        
        foreach ($ip_keys as $key) {
            if (isset($_SERVER[$key]) && filter_var($_SERVER[$key], FILTER_VALIDATE_IP)) {
                return $_SERVER[$key];
            }
        }
        
        return '0.0.0.0';
    }    
    
    /**
     * 获取卡片数据
     * 
     * @param array $user_input 用户输入数据
     * @return array 卡片数据
     */
    public function retrieve_card_data($user_input) {
        // 限流检查
        if ($this->is_rate_limited()) {
            return ['error' => '请求过于频繁，请稍后再试'];
        }
        
        global $wpdb;
        $raw_url = esc_url_raw($user_input['url']);
        $url_hash = md5($raw_url);
        $table = $wpdb->prefix . 'chf_card_cache';

        // 清洗输入字段
        $user_input['title'] = $this->sanitize_field($user_input['title'] ?? '');
        $user_input['image'] = $this->sanitize_field($user_input['image'] ?? '');
        $user_input['description'] = $this->sanitize_field($user_input['description'] ?? '');

        // 使用参数化查询获取缓存数据
        $cache = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT title, image, description, expires_at FROM $table WHERE url_hash = %s AND expires_at > NOW() LIMIT 1", 
                $url_hash
            )
        );
        
        $base_data = [
            'url' => $raw_url, 
            'title' => $user_input['title'], 
            'image' => $user_input['image'], 
            'description' => $user_input['description']
        ];

        if ($cache) {
            // 检查缓存是否需要刷新
            if (empty($cache->title) && strtotime($cache->expires_at) < time()) {
                try {
                    $metadata = $this->fetch_url_metadata($raw_url);
                    $merged = array_merge(
                        $base_data, 
                        [
                            'title' => $cache->title, 
                            'image' => $cache->image, 
                            'description' => $cache->description
                        ]
                    );

                    // 更新缓存
                    $this->update_cache($url_hash, $raw_url, $merged); 
                } catch (Exception $e) {
                    error_log('ChfmCard: Error refreshing cache: ' . $e->getMessage());
                }
            } else {
                $merged = array_merge(
                    $base_data, 
                    [
                        'title' => $cache->title, 
                        'image' => $cache->image, 
                        'description' => $cache->description
                    ]
                );
            }
        } else {
            try {
                $metadata = $this->fetch_url_metadata($raw_url);
                $merged = array_merge(
                    $base_data, 
                    [
                        'title' => $metadata['title'],
                        'image' => $metadata['image'],
                        'description' => $metadata['description']
                    ]
                );
                
                // 保存到缓存
                $this->update_cache($url_hash, $raw_url, $merged);
            } catch (Exception $e) {
                error_log('ChfmCard: Error fetching metadata: ' . $e->getMessage());
                return [
                    'error' => '无法获取URL元数据',
                    'url' => $raw_url,
                    'title' => parse_url($raw_url, PHP_URL_HOST),
                    'image' => '',
                    'description' => ''
                ];
            }
        }
        
        return $merged;
    }
    
    /**
     * 更新缓存数据
     * 
     * @param string $url_hash URL哈希
     * @param string $raw_url 原始URL
     * @param array $data 卡片数据
     */
    private function update_cache($url_hash, $raw_url, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'chf_card_cache';
        
        // 使用参数化查询更新缓存
        $result = $wpdb->replace(
            $table,
            [
                'url_hash' => $url_hash,
                'url' => $raw_url,
                'title' => sanitize_text_field($data['title']),
                'image' => esc_url_raw($data['image']),
                'description' => sanitize_textarea_field($data['description']),
                'expires_at' => date('Y-m-d H:i:s', time() + (ChfmCard_DBManager::CACHE_EXPIRE_HOURS * 3600))
            ],
            [
                '%s', // url_hash
                '%s', // url
                '%s', // title
                '%s', // image
                '%s', // description
                '%s'  // expires_at
            ]
        );
        
        // 记录数据库错误
        if ($result === false) {
            error_log('ChfmCard: Database error: ' . $wpdb->last_error);
        }
    }
    
    /**
     * 清洗字段数据
     * 
     * @param string $value 输入值
     * @return string 清洗后的值
     */
    private function sanitize_field($value) {
        // 移除URL前缀
        $value = preg_replace('/^https?:\/\/\S+\s+/', '', $value);
        
        // 移除潜在的XSS向量
        $value = wp_kses($value, []);
        
        return $value;
    }

    /**
     * 获取URL元数据
     * 
     * @param string $url 目标URL
     * @return array 元数据
     * @throws Exception 请求失败时抛出异常
     */
    private function fetch_url_metadata($url) {
        // 设置请求参数
        $args = [
            'timeout' => 10, // 减少超时时间
            'sslverify' => true,
            'user-agent' => 'Mozilla/5.0 (compatible; WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url') . ')',
            'headers' => ['Accept-Language' => 'en-US,en;q=0.9'],
            'redirection' => 3, // 限制重定向次数
            'blocking' => true,
        ];
        
        // 发送请求
        $response = wp_remote_get($url, $args);

        // 检查请求是否成功
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        // 检查HTTP状态码
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            throw new Exception('HTTP错误: ' . $status_code);
        }

        // 获取响应内容
        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            throw new Exception('空响应内容');
        }
        
        // 解析HTML元数据
        return $this->parse_html_metadata($html, $url);
    }

    /**
     * 解析HTML元数据
     * 
     * @param string $html HTML内容
     * @param string $base_url 基础URL
     * @return array 解析后的元数据
     */
    private function parse_html_metadata($html, $base_url) {
        // 使用libxml错误处理
        $prev_libxml_use = libxml_use_internal_errors(true);
        
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        
        $xpath = new DOMXPath($dom);
    
        // 多来源标题抓取
        $title = $this->get_meta_content($xpath, [
            'og:title', 'twitter:title', 'itemprop=name'
        ]) ?: $this->get_dom_text($dom, 'title') ?: parse_url($base_url, PHP_URL_HOST);

        // 多来源描述抓取
        $description = $this->get_meta_content($xpath, [
            'og:description', 'twitter:description', 'itemprop=description', 'description'
        ]) ?: '';

        // 多来源图片抓取
        $image = $this->get_meta_content($xpath, [
            'og:image', 'twitter:image:src', 'itemprop=image'
        ]);
        $image = $image ? $this->resolve_relative_url($image, $base_url) : '';
        
        // 恢复libxml错误处理
        libxml_use_internal_errors($prev_libxml_use);

        return [
            'title' => $this->sanitize_field($title),
            'description' => $this->sanitize_field($description),
            'image' => esc_url_raw($image)
        ];
    }
    
    /**
     * 获取元标签内容
     * 
     * @param DOMXPath $xpath XPath对象
     * @param array $properties 属性列表
     * @return string|null 元标签内容
     */
    private function get_meta_content($xpath, $properties) {
        foreach ($properties as $prop) {
            // 安全构建XPath查询
            if (strpos($prop, 'itemprop=') === 0) {
                $attr_name = 'itemprop';
                $attr_value = str_replace('itemprop=', '', $prop);
                $query = "//meta[@{$attr_name}='{$attr_value}']";
            } else {
                $query = "//meta[@property='{$prop}' or @name='{$prop}']";
            }
            
            $meta = $xpath->query($query);
            if ($meta->length > 0 && $content = $meta->item(0)->getAttribute('content')) {
                return $content;
            }
        }
        return null;
    }

    /**
     * 获取DOM元素文本
     * 
     * @param DOMDocument $dom DOM对象
     * @param string $tagName 标签名
     * @return string|null 元素文本
     */
    private function get_dom_text($dom, $tagName) {
        $elements = $dom->getElementsByTagName($tagName);
        return $elements->length > 0 ? $elements->item(0)->nodeValue : null;
    }

    /**
     * 解析相对URL
     * 
     * @param string $path 路径
     * @param string $base 基础URL
     * @return string 完整URL
     */
    private function resolve_relative_url($path, $base) {
        // 检查是否已经是完整URL
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }
        
        // 解析基础URL
        $baseParts = parse_url($base);
        if (!isset($baseParts['scheme']) || !isset($baseParts['host'])) {
            return '';
        }
        
        // 构建完整URL
        $scheme = $baseParts['scheme'];
        $host = $baseParts['host'];
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
        
        // 处理不同类型的相对路径
        if (strpos($path, '/') === 0) {
            // 绝对路径
            return "{$scheme}://{$host}{$port}{$path}";
        } else {
            // 相对路径
            $basePath = isset($baseParts['path']) ? $baseParts['path'] : '/';
            $basePath = preg_replace('#/[^/]*$#', '/', $basePath);
            return "{$scheme}://{$host}{$port}{$basePath}{$path}";
        }
    }    
}

// ==============================
// 区块注册逻辑
// ==============================
function custom_card_register_block() {
    // 检查区块编辑器是否可用
    if (!function_exists('register_block_type')) {
        return;
    }
    
    // 注册区块
    register_block_type(__DIR__ . '/blocks/custom-card', array(
        'render_callback' => 'custom_card_render_callback',
        'attributes' => array(
            'url' => array(
                'type' => 'string',
                'default' => '',
            ),
            'title' => array(
                'type' => 'string',
                'default' => '',
            ),
            'image' => array(
                'type' => 'string',
                'default' => '',
            ),
            'description' => array(
                'type' => 'string',
                'default' => '',
            ),
        ),
    ));
}
add_action('init', 'custom_card_register_block');

/**
 * 区块渲染回调
 * 
 * @param array $attributes 区块属性
 * @param string $content 区块内容
 * @return string 渲染后的HTML
 */
function custom_card_render_callback($attributes, $content) {
    $default = ['url' => '', 'title' => '', 'image' => '', 'description' => ''];
    $atts = shortcode_atts($default, $attributes, 'custom_card');

    // 创建插件核心实例
    $plugin_core = new Chf_Card_Plugin_Core();
    
    // 验证URL
    if (empty($atts['url']) || !$plugin_core->is_valid_url($atts['url'])) {
        return '<div class="card-error">✖️ 无效的URL参数</div>';
    }

    // 获取卡片数据
    $data = $plugin_core->retrieve_card_data($atts);
    
    // 检查是否有错误
    if (isset($data['error'])) {
        return '<div class="card-error">✖️ ' . esc_html($data['error']) . '</div>';
    }
    
    // 渲染模板
    ob_start();
    include plugin_dir_path(__FILE__) . 'template/card.php';
    return ob_get_clean();
}

/**
 * 注册区块编辑器资源
 */
function custom_card_enqueue_block_editor_assets() {
    wp_enqueue_script(
        'custom-card-block-editor',
        plugins_url('blocks/custom-card/index.js', __FILE__),
        array('wp-blocks', 'wp-i18n', 'wp-element', 'wp-block-editor'),
        filemtime(plugin_dir_path(__FILE__) . 'blocks/custom-card/index.js')
    );
}
add_action('enqueue_block_editor_assets', 'custom_card_enqueue_block_editor_assets');

// 初始化插件
new Chf_Card_Plugin_Core();
