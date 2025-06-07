<?php
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

