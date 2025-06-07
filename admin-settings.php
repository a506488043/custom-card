<?php
/**
 * 网站卡片插件设置页面
 */

// 安全检查：防止直接访问PHP文件
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * 渲染设置页面
 */
public function render_settings_page() {
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
            
            <div class="card">
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
            
            <div class="card" style="margin-top: 20px;">
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
            
            <p class="submit">
                <input type="submit" name="chfm_save_settings" class="button button-primary" value="保存设置">
            </p>
        </form>
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

