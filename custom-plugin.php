<?php
/*
Plugin Name: 网站卡片
Version: 5.3.1 
Tested up to: 6.5.1
Description: 完全支持URL存储的卡片插件终极版 | 安全增强版 | 多级缓存版 | 修复URL拼接问题
*/
// 安全检查：防止直接访问PHP文件
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// 加载缓存管理器
require_once plugin_dir_path(__FILE__) . 'includes/class-cache-manager.php';

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
        
        // 清理缓存目录
        $cache_manager = new ChfmCard_Cache_Manager();
        $cache_manager->flush();
        
        // 彻底删除缓存目录
        self::delete_cache_directory();
    }
    
    /**
     * 递归删除缓存目录
     */
    private static function delete_cache_directory() {
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/chfm-card-cache';
        
        if (is_dir($cache_dir)) {
            self::recursive_rmdir($cache_dir);
            error_log('ChfmCard: Cache directory removed during uninstall');
        }
    }
    
    /**
     * 递归删除目录及其内容
     * 
     * @param string $dir 要删除的目录
     * @return bool 是否成功
     */
    private static function recursive_rmdir($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        
        $files = array_diff(scandir($dir), array('.', '..'));
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            
            if (is_dir($path)) {
                self::recursive_rmdir($path);
            } else {
                @unlink($path);
            }
        }
        
        return @rmdir($dir);
    }
}

// 注册激活和卸载钩子
register_activation_hook(__FILE__, ['ChfmCard_DBManager', 'create_tables']);
register_uninstall_hook(__FILE__, ['ChfmCard_DBManager', 'uninstall_tables']);

// 添加删除插件前的清理钩子
register_deactivation_hook(__FILE__, ['ChfmCard_DBManager', 'uninstall_tables']);

// === 核心类 ===
class Chf_Card_Plugin_Core {
    // 定义插件常量
    const PLUGIN_VERSION = '5.3.1';
    const NONCE_ACTION = 'chf_card_security';
    const RATE_LIMIT_THRESHOLD = 10; // 每分钟请求限制
    const ITEMS_PER_PAGE = 10; // 每页显示的缓存项数量
    
    /**
     * 缓存管理器实例
     */
    private $cache_manager;
    
    public function __construct() {
        // 初始化缓存管理器
        $this->cache_manager = new ChfmCard_Cache_Manager();
        
        add_shortcode('custom_card', [$this, 'handle_shortcode']);
        
        // 分离AJAX处理，增加nonce验证
        add_action('wp_ajax_load_custom_card', [$this, 'handle_ajax_request']);
        add_action('wp_ajax_nopriv_load_custom_card', [$this, 'handle_ajax_request']);
        
        // 处理卡片编辑AJAX请求
        add_action('wp_ajax_edit_card_cache', [$this, 'handle_edit_card_ajax']);
        
        add_action('wp_enqueue_scripts', [$this, 'load_assets']);
        
        // 添加管理菜单
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // 添加管理页面样式
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);
    }

    /**
     * 短代码处理函数
     * 
     * @param array $atts 短代码属性
     * @return string 渲染后的HTML
     */
    public function handle_shortcode($atts) {
        // 设置默认值并合并属性
        $default = ['url' => '', 'title' => '', 'image' => '', 'description' => ''];
        $atts = shortcode_atts($default, $atts, 'custom_card');
        
        // 验证URL
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
     * 加载前端资源
     */
    public function load_assets() {
        // 加载CSS样式
        wp_enqueue_style(
            'chfm-card-style',
            plugins_url('assets/chf-card.css', __FILE__),
            array(),
            self::PLUGIN_VERSION
        );

        // 加载JavaScript
        wp_enqueue_script(
            'chfm-card-script',
            plugins_url('assets/chf-card.js', __FILE__),
            array('jquery'),
            self::PLUGIN_VERSION,
            true
        );

        // 本地化脚本
        wp_localize_script('chfm-card-script', 'chfmCard', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION)
        ));
    }
    
    /**
     * 添加管理页面样式
     */
    public function admin_enqueue_scripts($hook) {
        // 检查是否是网站卡片相关页面
        if (!in_array($hook, ['toplevel_page_toolbox-main', 'toolbox_page_toolbox-function-cards', 'toolbox_page_toolbox-website-cards'])) {
            return;
        }
        
        wp_enqueue_style(
            'chfm-card-admin-style',
            plugins_url('assets/admin-style.css', __FILE__),
            array(),
            self::PLUGIN_VERSION
        );
        
        // 添加管理页面JavaScript
        wp_enqueue_script(
            'chfm-card-admin-script',
            plugins_url('assets/admin-script.js', __FILE__),
            array('jquery'),
            self::PLUGIN_VERSION,
            true
        );
        
        // 本地化脚本
        wp_localize_script('chfm-card-admin-script', 'chfmCardAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'editSuccess' => '卡片数据已成功更新！',
            'editError' => '更新失败，请重试。'
        ));
    }
    
    /**
     * 处理卡片编辑AJAX请求
     */
    public function handle_edit_card_ajax() {
        // 验证nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], self::NONCE_ACTION)) {
            wp_send_json_error(['message' => '安全验证失败']);
            return;
        }
        
        // 验证权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '权限不足']);
            return;
        }
        
        // 验证必要参数
        if (!isset($_POST['url_hash']) || !isset($_POST['title']) || !isset($_POST['image']) || !isset($_POST['description'])) {
            wp_send_json_error(['message' => '参数不完整']);
            return;
        }
        
        $url_hash = sanitize_text_field($_POST['url_hash']);
        $title = sanitize_text_field($_POST['title']);
        $image = esc_url_raw($_POST['image']);
        $description = sanitize_textarea_field($_POST['description']);
        
        // 更新数据
        $data = [
            'title' => $title,
            'image' => $image,
            'description' => $description
        ];
        
        // 使用缓存管理器更新数据
        $result = $this->cache_manager->update($url_hash, $data);
        
        if ($result) {
            wp_send_json_success(['message' => '卡片数据已成功更新']);
        } else {
            wp_send_json_error(['message' => '更新失败，请重试']);
        }
    }
    
    /**
     * 添加管理菜单
     */
    public function add_admin_menu() {
        // 添加网站卡片主菜单
        add_menu_page(
            '网站卡片',                    // 页面标题
            '网站卡片',                    // 菜单标题
            'manage_options',            // 权限
            'toolbox-main',              // 菜单slug
            [$this, 'render_settings_page'], // 回调函数
            'dashicons-admin-tools',     // 图标
            30                           // 位置
        );
        
        // 添加卡片列表子菜单（主菜单的别名）
        add_submenu_page(
            'toolbox-main',              // 父菜单slug
            '卡片列表',                    // 页面标题
            '卡片列表',                    // 菜单标题
            'manage_options',            // 权限
            'toolbox-main',              // 菜单slug（与主菜单相同）
            [$this, 'render_settings_page'] // 回调函数
        );
        
        // 添加设置子菜单
        add_submenu_page(
            'toolbox-main',              // 父菜单slug
            '插件设置',                    // 页面标题
            '插件设置',                    // 菜单标题
            'manage_options',            // 权限
            'toolbox-settings',          // 菜单slug
            [$this, 'render_plugin_settings_page'] // 回调函数
        );
        
        // 添加缓存状态子菜单
        add_submenu_page(
            'toolbox-main',              // 父菜单slug
            '缓存状态',                    // 页面标题
            '缓存状态',                    // 菜单标题
            'manage_options',            // 权限
            'toolbox-function-cards',    // 菜单slug
            [$this, 'render_cache_usage_page'] // 回调函数
        );
        
        // 添加帮助子菜单
        add_submenu_page(
            'toolbox-main',              // 父菜单slug
            '使用帮助',                    // 页面标题
            '使用帮助',                    // 菜单标题
            'manage_options',            // 权限
            'toolbox-help',              // 菜单slug
            [$this, 'render_help_page']  // 回调函数
        );
    }
    
    /**
     * 渲染缓存状态页面
     */
    public function render_cache_usage_page() {
        // 处理缓存清理操作
        if (isset($_POST['chfm_clear_cache']) && check_admin_referer('chfm_clear_cache_nonce')) {
            $this->cache_manager->flush();
            echo '<div class="notice notice-success"><p>缓存已清理！</p></div>';
        }
        
        // 获取缓存状态
        $cache_status = $this->cache_manager->get_cache_status();
        $total_items = $this->cache_manager->get_items_count();
        
        // 显示缓存状态页面
        ?>
        <div class="wrap">
            <h1>缓存状态</h1>
            
            <!-- 缓存状态 -->
            <div class="card">
                <h2>缓存状态</h2>
                <table class="form-table">
                    <tr>
                        <th>Memcached 缓存:</th>
                        <td><?php echo $cache_status['memcached'] ? '<span style="color:green">✓ 已启用</span>' : '<span style="color:red">✗ 未启用</span>'; ?></td>
                    </tr>
                    <tr>
                        <th>Opcache 缓存:</th>
                        <td><?php echo $cache_status['opcache'] ? '<span style="color:green">✓ 已启用</span>' : '<span style="color:red">✗ 未启用</span>'; ?></td>
                    </tr>
                    <tr>
                        <th>缓存项总数:</th>
                        <td><?php echo $total_items; ?></td>
                    </tr>
                </table>
                
                <form method="post">
                    <?php wp_nonce_field('chfm_clear_cache_nonce'); ?>
                    <p><input type="submit" name="chfm_clear_cache" class="button button-primary" value="清理所有缓存"></p>
                </form>
            </div>
        </div>
        <?php
    }
    
    /**
     * 渲染插件设置页面
     */
    public function render_plugin_settings_page() {
        // 处理设置保存
        if (isset($_POST['chfm_save_settings']) && check_admin_referer('chfm_settings_nonce')) {
            // 保存设置
            $this->save_settings();
            echo '<div class="notice notice-success"><p>设置已保存！</p></div>';
        }
        
        // 获取当前设置
        $settings = $this->get_settings();
        
        // 显示设置页面
        ?>
        <div class="wrap">
            <h1>网站卡片设置</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('chfm_settings_nonce'); ?>
                
                <!-- 将两个卡片放在同一行 -->
                <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 20px;">
                    <div class="card" style="flex: 1; min-width: 300px;">
                        <h2>基本设置</h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="cache_time">缓存时间（小时）</label>
                                </th>
                                <td>
                                    <input type="number" name="cache_time" id="cache_time" value="<?php echo esc_attr($settings['cache_time']); ?>" min="1" max="720" class="regular-text">
                                    <p class="description">设置卡片数据的缓存时间，默认为72小时。</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="lazy_load">启用懒加载</label>
                                </th>
                                <td>
                                    <input type="checkbox" name="lazy_load" id="lazy_load" <?php checked($settings['lazy_load'], 1); ?> value="1">
                                    <p class="description">启用后，卡片将在滚动到可见区域时才加载。</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="open_in_new_tab">在新标签页打开链接</label>
                                </th>
                                <td>
                                    <input type="checkbox" name="open_in_new_tab" id="open_in_new_tab" <?php checked($settings['open_in_new_tab'], 1); ?> value="1">
                                    <p class="description">启用后，点击卡片将在新标签页打开链接。</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="card" style="flex: 1; min-width: 300px;">
                        <h2>显示设置</h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="show_title">显示标题</label>
                                </th>
                                <td>
                                    <input type="checkbox" name="show_title" id="show_title" <?php checked($settings['show_title'], 1); ?> value="1">
                                    <p class="description">是否显示卡片标题。</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="show_description">显示描述</label>
                                </th>
                                <td>
                                    <input type="checkbox" name="show_description" id="show_description" <?php checked($settings['show_description'], 1); ?> value="1">
                                    <p class="description">是否显示卡片描述。</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="show_image">显示图片</label>
                                </th>
                                <td>
                                    <input type="checkbox" name="show_image" id="show_image" <?php checked($settings['show_image'], 1); ?> value="1">
                                    <p class="description">是否显示卡片图片。</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="card_width">卡片宽度</label>
                                </th>
                                <td>
                                    <select name="card_width" id="card_width">
                                        <option value="full" <?php selected($settings['card_width'], 'full'); ?>>全宽</option>
                                        <option value="wide" <?php selected($settings['card_width'], 'wide'); ?>>宽（80%）</option>
                                        <option value="medium" <?php selected($settings['card_width'], 'medium'); ?>>中等（60%）</option>
                                        <option value="narrow" <?php selected($settings['card_width'], 'narrow'); ?>>窄（40%）</option>
                                    </select>
                                    <p class="description">设置卡片的宽度。</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <p class="submit">
                    <input type="submit" name="chfm_save_settings" class="button button-primary" value="保存设置">
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * 渲染帮助页面
     */
    public function render_help_page() {
        ?>
        <div class="wrap">
            <h1>网站卡片使用帮助</h1>
            
            <!-- 三个方框放在第一行 -->
            <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 20px;">
                <div class="card" style="flex: 1; min-width: 300px;">
                    <h2>基本用法</h2>
                    
                    <h3>短代码用法</h3>
                    <p>您可以使用以下短代码在文章或页面中插入网站卡片：</p>
                    <pre style="overflow-x: auto; white-space: pre-wrap; word-break: break-word;"><code>[custom_card url="https://example.com"]</code></pre>
                    
                    <h3>区块编辑器</h3>
                    <p>在区块编辑器中，您可以直接添加"网站卡片"区块，然后输入URL。</p>
                    
                    <h3>高级用法</h3>
                    <p>您可以使用以下参数自定义卡片：</p>
                    <div style="max-width: 100%; overflow-x: auto;">
                        <pre style="white-space: pre-wrap; word-break: break-word; font-size: 12px; max-width: 100%;"><code>[custom_card url="https://example.com" title="自定义标题" description="自定义描述" image="https://example.com/image.jpg"]</code></pre>
                    </div>
                    <p>注意：如果提供了自定义参数，插件将优先使用这些参数，而不是从URL获取的元数据。</p>
                </div>
                
                <div class="card" style="flex: 1; min-width: 300px;">
                    <h2>常见问题</h2>
                    
                    <div class="chfm-faq-item">
                        <h3>为什么我的卡片没有显示图片？</h3>
                        <p>可能的原因：</p>
                        <ul>
                            <li>目标网站没有提供图片元数据（og:image 或类似标签）</li>
                            <li>图片URL无效或无法访问</li>
                            <li>您在设置中禁用了图片显示</li>
                        </ul>
                    </div>
                    
                    <div class="chfm-faq-item">
                        <h3>如何清除卡片缓存？</h3>
                        <p>您可以在"缓存状态"页面中点击"清理所有缓存"按钮，或者在卡片列表中删除特定的缓存项。</p>
                    </div>
                    
                    <div class="chfm-faq-item">
                        <h3>如何自定义卡片样式？</h3>
                        <p>您可以在主题的 style.css 文件中添加自定义CSS来覆盖默认样式。主要的CSS类包括：</p>
                        <ul>
                            <li><code>.strict-card</code> - 卡片容器</li>
                            <li><code>.strict-inner</code> - 卡片内部容器</li>
                            <li><code>.strict-media</code> - 图片容器</li>
                            <li><code>.strict-content</code> - 内容容器</li>
                            <li><code>.strict-title</code> - 标题</li>
                            <li><code>.strict-desc</code> - 描述</li>
                        </ul>
                    </div>
                </div>
                
                <div class="card" style="flex: 1; min-width: 300px;">
                    <h2>技术支持</h2>
                    
                    <p>如果您遇到任何问题或需要帮助，请联系插件作者。</p>
                    
                    <h3>联系方式</h3>
                    <ul>
                        <li>插件作者：17376592083</li>
                        <li>作者网站：<a href="https://www.saita.top" target="_blank">https://www.saita.top</a></li>
                        <li>支持邮箱：chenghoufeng@saiita.top</li>
                    </ul>
                    
                    <h3>调试信息</h3>
                    <p>在寻求支持时，请提供以下信息：</p>
                    <ul>
                        <li>WordPress版本：<?php echo get_bloginfo('version'); ?></li>
                        <li>PHP版本：<?php echo phpversion(); ?></li>
                        <li>插件版本：<?php echo self::PLUGIN_VERSION; ?></li>
                        <li>服务器信息：<?php echo $_SERVER['SERVER_SOFTWARE']; ?></li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * 获取插件设置
     * 
     * @return array 设置数组
     */
    private function get_settings() {
        // 默认设置
        $defaults = array(
            'cache_time' => 72, // 默认72小时
            'lazy_load' => 1, // 默认启用懒加载
            'open_in_new_tab' => 1, // 默认在新标签页打开
            'show_title' => 1, // 默认显示标题
            'show_description' => 1, // 默认显示描述
            'show_image' => 1, // 默认显示图片
            'card_width' => 'full', // 默认全宽
        );
        
        // 获取保存的设置
        $settings = get_option('chfm_card_settings', array());
        
        // 合并默认设置和保存的设置
        return wp_parse_args($settings, $defaults);
    }
    
    /**
     * 保存插件设置
     */
    private function save_settings() {
        // 验证权限
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // 获取并清理设置值
        $settings = array(
            'cache_time' => isset($_POST['cache_time']) ? intval($_POST['cache_time']) : 72,
            'lazy_load' => isset($_POST['lazy_load']) ? 1 : 0,
            'open_in_new_tab' => isset($_POST['open_in_new_tab']) ? 1 : 0,
            'show_title' => isset($_POST['show_title']) ? 1 : 0,
            'show_description' => isset($_POST['show_description']) ? 1 : 0,
            'show_image' => isset($_POST['show_image']) ? 1 : 0,
            'card_width' => isset($_POST['card_width']) ? sanitize_text_field($_POST['card_width']) : 'full',
        );
        
        // 确保缓存时间在合理范围内
        $settings['cache_time'] = max(1, min(720, $settings['cache_time']));
        
        // 保存设置
        update_option('chfm_card_settings', $settings);
    }
    
    /**
     * 截断文本并添加省略号
     * 
     * @param string $text 要截断的文本
     * @param int $length 最大长度
     * @return string 截断后的文本
     */
    private function truncate_text($text, $length) {
        if (mb_strlen($text) > $length) {
            return mb_substr($text, 0, $length) . '...';
        }
        return $text;
    }

    /**
     * 渲染设置页面
     */
    public function render_settings_page() {
        // 处理缓存清理操作
        if (isset($_POST['chfm_clear_cache']) && check_admin_referer('chfm_clear_cache_nonce')) {
            $this->cache_manager->flush();
            echo '<div class="notice notice-success"><p>缓存已清理！</p></div>';
        }
        
        // 处理单个缓存删除操作
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['url_hash']) && check_admin_referer('chfm_delete_cache_' . $_GET['url_hash'])) {
            $url_hash = sanitize_text_field($_GET['url_hash']);
            $this->cache_manager->delete($url_hash);
            
            echo '<div class="notice notice-success"><p>已删除指定缓存项！</p></div>';
        }
        
        // 获取当前页码
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        
        // 获取搜索关键词
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        
        // 获取缓存列表
        $cache_items = $this->cache_manager->get_all_items($current_page, self::ITEMS_PER_PAGE, $search);
        $total_items = $this->cache_manager->get_items_count($search);
        $total_pages = ceil($total_items / self::ITEMS_PER_PAGE);
        
        // 显示设置页面
        ?>
        <div class="wrap">
            <h1>网站卡片</h1>
            
            <!-- 第一行：已缓存的网站卡片 -->
            <div class="card chfm-full-width-card">
                <h2>已缓存的网站卡片</h2>
                
                <!-- 搜索表单 -->
                <form method="get" action="<?php echo admin_url('admin.php'); ?>" class="search-form">
                    <input type="hidden" name="page" value="<?php echo isset($_GET['page']) ? esc_attr($_GET['page']) : 'toolbox-main'; ?>">
                    <p class="search-box">
                        <label class="screen-reader-text" for="card-search-input">搜索卡片:</label>
                        <input type="search" id="card-search-input" name="s" value="<?php echo esc_attr($search); ?>" placeholder="搜索URL、标题或描述...">
                        <input type="submit" id="search-submit" class="button" value="搜索">
                        <?php if (!empty($search)): ?>
                            <a href="<?php echo admin_url('admin.php?page=' . (isset($_GET['page']) ? $_GET['page'] : 'toolbox-main')); ?>" class="button">清除搜索</a>
                        <?php endif; ?>
                    </p>
                </form>
                
                <div class="chfm-responsive-container">
                    <?php if (!empty($search)): ?>
                        <div class="search-result-info">
                            <p>搜索结果: "<?php echo esc_html($search); ?>" (找到 <?php echo $total_items; ?> 项)</p>
                        </div>
                    <?php endif; ?>
                    <?php if (empty($cache_items)): ?>
                        <p>当前没有缓存的卡片数据。</p>
                    <?php else: ?>
                        <table class="wp-list-table widefat fixed striped chfm-responsive-table">
                            <thead>
                                <tr>
                                    <th width="5%">ID</th>
                                    <th width="25%">URL</th>
                                    <th width="20%">标题</th>
                                    <th width="15%">图片</th>
                                    <th width="15%">过期时间</th>
                                    <th width="20%">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cache_items as $index => $item): ?>
                                    <tr data-url-hash="<?php echo esc_attr($item->url_hash); ?>">
                                        <td data-label="ID"><?php echo ($current_page - 1) * self::ITEMS_PER_PAGE + $index + 1; ?></td>
                                        <td data-label="URL">
                                            <a href="<?php echo esc_url($item->url); ?>" target="_blank" title="<?php echo esc_attr($item->url); ?>">
                                                <?php echo esc_html($this->truncate_text($item->url, 50)); ?>
                                            </a>
                                        </td>
                                        <td data-label="标题" class="card-title-cell"><?php echo esc_html($this->truncate_text($item->title, 30)); ?></td>
                                        <td data-label="图片" class="card-image-cell">
                                            <?php if (!empty($item->image)): ?>
                                                <a href="<?php echo esc_url($item->image); ?>" target="_blank">
                                                    <img src="<?php echo esc_url($item->image); ?>" alt="缩略图" class="card-thumbnail">
                                                </a>
                                            <?php else: ?>
                                                <span>无图片</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="过期时间">
                                            <?php 
                                            $expires_at = strtotime($item->expires_at);
                                            $now = time();
                                            $is_expired = $expires_at < $now;
                                            $time_class = $is_expired ? 'expired' : 'valid';
                                            echo '<span class="cache-time ' . $time_class . '">' . date('Y-m-d H:i', $expires_at) . '</span>';
                                            ?>
                                        </td>
                                        <td data-label="操作" class="card-actions-cell">
                                            <?php
                                            $delete_url = wp_nonce_url(
                                                add_query_arg(
                                                    array(
                                                        'page' => isset($_GET['page']) ? $_GET['page'] : 'toolbox-main',
                                                        'action' => 'delete',
                                                        'url_hash' => $item->url_hash,
                                                        'paged' => $current_page,
                                                        's' => $search, // 保留搜索参数
                                                    ),
                                                    admin_url('admin.php')
                                                ),
                                                'chfm_delete_cache_' . $item->url_hash
                                            );
                                            ?>
                                            <button type="button" class="button button-small edit-card-btn" data-url-hash="<?php echo esc_attr($item->url_hash); ?>">编辑</button>
                                            <a href="<?php echo esc_url($delete_url); ?>" class="button button-small" onclick="return confirm('确定要删除此缓存项吗？');">删除</a>
                                            
                                            <!-- 隐藏的完整数据字段 -->
                                            <div class="hidden-card-data" style="display: none;">
                                                <input type="hidden" class="card-full-title" value="<?php echo esc_attr($item->title); ?>">
                                                <input type="hidden" class="card-full-image" value="<?php echo esc_attr($item->image); ?>">
                                                <input type="hidden" class="card-full-description" value="<?php echo esc_attr($item->description); ?>">
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <!-- 分页导航 -->
                        <?php if ($total_pages > 1): ?>
                            <div class="tablenav">
                                <div class="tablenav-pages">
                                    <?php
                                    // 构建基础URL，保留搜索参数
                                    $base_url = add_query_arg(
                                        array(
                                            'page' => isset($_GET['page']) ? $_GET['page'] : 'toolbox-main',
                                            's' => $search,
                                        ),
                                        admin_url('admin.php')
                                    );
                                    
                                    $page_links = paginate_links(array(
                                        'base' => add_query_arg('paged', '%#%', $base_url),
                                        'format' => '',
                                        'prev_text' => '&laquo; 上一页',
                                        'next_text' => '下一页 &raquo;',
                                        'total' => $total_pages,
                                        'current' => $current_page,
                                        'type' => 'plain',
                                    ));
                                    echo $page_links;
                                    ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- 编辑卡片模态框 -->
            <div id="edit-card-modal" class="chfm-modal" style="display: none;">
                <div class="chfm-modal-content">
                    <span class="chfm-modal-close">&times;</span>
                    <h2>编辑卡片数据</h2>
                    
                    <form id="edit-card-form">
                        <input type="hidden" id="edit-url-hash" name="url_hash">
                        
                        <table class="form-table">
                            <tr>
                                <th><label for="edit-title">标题:</label></th>
                                <td><input type="text" id="edit-title" name="title" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th><label for="edit-image">图片URL:</label></th>
                                <td>
                                    <input type="url" id="edit-image" name="image" class="regular-text">
                                    <div style="margin-top: 10px;">
                                        <img id="image-preview" src="" alt="图片预览" style="max-width: 200px; max-height: 150px; display: none;">
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="edit-description">描述:</label></th>
                                <td><textarea id="edit-description" name="description" rows="4" class="large-text"></textarea></td>
                            </tr>
                        </table>
                        
                        <div id="edit-status" style="margin: 10px 0; display: none;"></div>
                        
                        <p class="submit">
                            <input type="submit" class="button button-primary" value="保存更改">
                            <button type="button" class="button chfm-modal-close">取消</button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
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
        
        // 验证URL
        if (!isset($_POST['url']) || !$this->is_valid_url($_POST['url'])) {
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
    public function is_valid_url($url) {
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
     * 检查是否超出请求频率限制 - 已禁用
     * 
     * @return bool 始终返回false，不进行限流
     */
    private function is_rate_limited() {
        // 已禁用限流功能，始终返回false
        return false;
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
        
        $raw_url = esc_url_raw($user_input['url']);
        $url_hash = md5($raw_url);
        
        // 清洗输入字段
        $user_input['title'] = $this->sanitize_field($user_input['title'] ?? '');
        $user_input['image'] = $this->sanitize_field($user_input['image'] ?? '');
        $user_input['description'] = $this->sanitize_field($user_input['description'] ?? '');
        
        // 基础数据
        $base_data = [
            'url' => $raw_url, 
            'title' => $user_input['title'], 
            'image' => $user_input['image'], 
            'description' => $user_input['description']
        ];
        
        // 1. 首先尝试从多级缓存获取数据
        $cache_data = $this->cache_manager->get($url_hash);
        if ($cache_data !== false) {
            // 缓存命中，合并基础数据和缓存数据
            return array_merge($base_data, $cache_data);
        }
        
        // 2. 缓存未命中，需要获取新数据
        try {
            // 尝试获取元数据
            $metadata = $this->fetch_url_metadata($raw_url);
            $merged_data = array_merge(
                $base_data, 
                [
                    'title' => $metadata['title'],
                    'image' => $metadata['image'],
                    'description' => $metadata['description']
                ]
            );
            
            // 3. 先保存到数据库，再同步到缓存层
            $this->cache_manager->set($url_hash, $raw_url, [
                'title' => $metadata['title'],
                'image' => $metadata['image'],
                'description' => $metadata['description']
            ]);
            
            return $merged_data;
        } catch (Exception $e) {
            // 记录错误日志
            error_log('ChfmCard: Error fetching metadata: ' . $e->getMessage());
            
            // 创建默认数据
            $fallback_data = [
                'title' => parse_url($raw_url, PHP_URL_HOST),
                'image' => '',
                'description' => ''
            ];
            
            // 即使获取失败，也将默认数据写入数据库和缓存
            $this->cache_manager->set($url_hash, $raw_url, $fallback_data);
            
            // 返回合并后的数据
            return array_merge($base_data, $fallback_data);
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
     * 解析相对URL - 修复版本
     * 
     * @param string $path 路径
     * @param string $base 基础URL
     * @return string 完整URL
     */
    private function resolve_relative_url($path, $base) {
        // 输入验证
        if (empty($path)) {
            return '';
        }
        
        // 去除路径前后的空白字符
        $path = trim($path);
        $base = trim($base);
        
        // 多重检查：判断是否已经是完整URL
        if ($this->is_absolute_url($path)) {
            // 如果已经是绝对URL，直接返回
            return $path;
        }
        
        // 检查是否以//开头（协议相对URL）
        if (strpos($path, '//') === 0) {
            // 从基础URL获取协议
            $baseParts = parse_url($base);
            if (isset($baseParts['scheme'])) {
                return $baseParts['scheme'] . ':' . $path;
            }
            return 'https:' . $path; // 默认使用https
        }
        
        // 解析基础URL
        $baseParts = parse_url($base);
        if (!isset($baseParts['scheme']) || !isset($baseParts['host'])) {
            // 如果基础URL无效，直接返回原路径
            return $path;
        }
        
        // 构建完整URL的基础部分
        $scheme = $baseParts['scheme'];
        $host = $baseParts['host'];
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
        
        // 处理不同类型的相对路径
        if (strpos($path, '/') === 0) {
            // 绝对路径（相对于域名根目录）
            return "{$scheme}://{$host}{$port}{$path}";
        } else {
            // 相对路径（相对于当前目录）
            $basePath = isset($baseParts['path']) ? $baseParts['path'] : '/';
            
            // 确保基础路径以斜杠结尾
            if (substr($basePath, -1) !== '/') {
                $basePath = dirname($basePath) . '/';
            }
            
            // 规范化路径，移除末尾的文件名
            $basePath = preg_replace('#/[^/]*$#', '/', $basePath);
            
            // 处理../和./的相对路径
            $path = str_replace('../', '', $path);
            $path = str_replace('./', '', $path);
            
            return "{$scheme}://{$host}{$port}{$basePath}{$path}";
        }
    }
    
    /**
     * 检查是否为绝对URL - 增强版
     * 
     * @param string $url 要检查的URL
     * @return bool 是否为绝对URL
     */
    private function is_absolute_url($url) {
        // 检查1：使用PHP内置函数验证
        if (filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return true;
        }
        
        // 检查2：使用正则表达式检查协议
        if (preg_match('/^https?:\/\//i', $url)) {
            return true;
        }
        
        // 检查3：检查是否以//开头（协议相对URL）
        if (strpos($url, '//') === 0) {
            return true;
        }
        
        // 检查4：检查data:和blob:等特殊协议
        if (preg_match('/^(data|blob|mailto|tel):/i', $url)) {
            return true;
        }
        
        // 检查5：检查是否包含协议分隔符
        if (strpos($url, '://') !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 安全的URL验证函数 - 新增
     * 
     * @param string $url 要验证的URL
     * @return bool 是否为安全的URL
     */
    private function is_safe_url($url) {
        // 基本URL格式验证
        if (!$this->is_absolute_url($url)) {
            return false;
        }
        
        // 解析URL
        $parsed_url = parse_url($url);
        
        // 检查必要的URL组件
        if (!isset($parsed_url['scheme']) || !isset($parsed_url['host'])) {
            return false;
        }
        
        // 只允许http和https协议
        if (!in_array(strtolower($parsed_url['scheme']), ['http', 'https'])) {
            return false;
        }
        
        // 防止本地主机和内网IP访问（SSRF防护）
        $host = strtolower($parsed_url['host']);
        
        // 检查本地主机
        if (in_array($host, ['localhost', '127.0.0.1', '::1'])) {
            return false;
        }
        
        // 检查是否为IP地址
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            // 检查是否为私有IP地址
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        
        return true;
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
