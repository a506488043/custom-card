<?php
// 这是一个测试文件，用于验证短代码功能

// 模拟WordPress环境
define('ABSPATH', dirname(__FILE__) . '/');

// 包含插件文件
require_once 'custom-plugin.php';

// 创建一个测试函数
function test_shortcode() {
    // 创建插件实例
    $plugin = new Chf_Card_Plugin_Core();
    
    // 测试短代码
    $atts = array('url' => 'https://www.example.com');
    $output = $plugin->handle_shortcode($atts);
    
    // 输出结果
    echo "短代码测试结果：\n";
    echo $output;
}

// 运行测试
test_shortcode();

