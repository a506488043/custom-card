<?php
/**
 * 缓存测试脚本
 * 
 * 用于测试多级缓存的命中与回源场景
 */

// 加载WordPress环境
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';

// 加载缓存管理器
require_once dirname(dirname(__FILE__)) . '/includes/class-cache-manager.php';

/**
 * 缓存测试类
 */
class ChfmCard_Cache_Test {
    /**
     * 测试URL
     */
    private $test_url = 'https://example.com';
    
    /**
     * 测试数据
     */
    private $test_data = [
        'title' => '测试标题',
        'image' => 'https://example.com/image.jpg',
        'description' => '这是一个测试描述'
    ];
    
    /**
     * 缓存管理器实例
     */
    private $cache_manager;
    
    /**
     * 构造函数
     */
    public function __construct() {
        $this->cache_manager = new ChfmCard_Cache_Manager();
        $this->run_tests();
    }
    
    /**
     * 运行所有测试
     */
    public function run_tests() {
        echo "开始缓存测试...\n\n";
        
        // 获取缓存状态
        $this->test_cache_status();
        
        // 测试缓存写入
        $this->test_cache_write();
        
        // 测试缓存命中
        $this->test_cache_hit();
        
        // 测试缓存删除
        $this->test_cache_delete();
        
        // 测试缓存回源
        $this->test_cache_miss();
        
        // 测试缓存清空
        $this->test_cache_flush();
        
        echo "\n所有测试完成！\n";
    }
    
    /**
     * 测试缓存状态
     */
    private function test_cache_status() {
        echo "=== 测试缓存状态 ===\n";
        
        $status = $this->cache_manager->get_cache_status();
        
        echo "Memcached: " . ($status['memcached'] ? '启用' : '禁用') . "\n";
        echo "Opcache: " . ($status['opcache'] ? '启用' : '禁用') . "\n";
        echo "任一缓存可用: " . ($status['any_available'] ? '是' : '否') . "\n";
        
        echo "测试结果: " . ($status['any_available'] ? '通过' : '失败') . "\n\n";
    }
    
    /**
     * 测试缓存写入
     */
    private function test_cache_write() {
        echo "=== 测试缓存写入 ===\n";
        
        $url_hash = md5($this->test_url);
        $result = $this->cache_manager->set($url_hash, $this->test_data);
        
        echo "写入结果: " . ($result ? '成功' : '失败') . "\n";
        echo "测试结果: " . ($result ? '通过' : '失败') . "\n\n";
    }
    
    /**
     * 测试缓存命中
     */
    private function test_cache_hit() {
        echo "=== 测试缓存命中 ===\n";
        
        $url_hash = md5($this->test_url);
        $data = $this->cache_manager->get($url_hash);
        
        echo "读取结果: " . ($data !== false ? '命中' : '未命中') . "\n";
        
        if ($data !== false) {
            $title_match = isset($data['title']) && $data['title'] === $this->test_data['title'];
            $image_match = isset($data['image']) && $data['image'] === $this->test_data['image'];
            $desc_match = isset($data['description']) && $data['description'] === $this->test_data['description'];
            
            echo "标题匹配: " . ($title_match ? '是' : '否') . "\n";
            echo "图片匹配: " . ($image_match ? '是' : '否') . "\n";
            echo "描述匹配: " . ($desc_match ? '是' : '否') . "\n";
            
            $all_match = $title_match && $image_match && $desc_match;
            echo "测试结果: " . ($all_match ? '通过' : '失败') . "\n\n";
        } else {
            echo "测试结果: 失败\n\n";
        }
    }
    
    /**
     * 测试缓存删除
     */
    private function test_cache_delete() {
        echo "=== 测试缓存删除 ===\n";
        
        $url_hash = md5($this->test_url);
        $result = $this->cache_manager->delete($url_hash);
        
        echo "删除结果: " . ($result ? '成功' : '失败') . "\n";
        
        // 验证删除是否成功
        $data = $this->cache_manager->get($url_hash);
        $deleted = ($data === false);
        
        echo "验证删除: " . ($deleted ? '已删除' : '未删除') . "\n";
        echo "测试结果: " . ($deleted ? '通过' : '失败') . "\n\n";
    }
    
    /**
     * 测试缓存回源
     */
    private function test_cache_miss() {
        echo "=== 测试缓存回源 ===\n";
        
        $url_hash = md5($this->test_url . '_not_exists');
        $data = $this->cache_manager->get($url_hash);
        
        echo "读取结果: " . ($data === false ? '未命中（正确）' : '命中（错误）') . "\n";
        echo "测试结果: " . ($data === false ? '通过' : '失败') . "\n\n";
    }
    
    /**
     * 测试缓存清空
     */
    private function test_cache_flush() {
        echo "=== 测试缓存清空 ===\n";
        
        // 先写入一些测试数据
        $url_hash1 = md5($this->test_url . '_1');
        $url_hash2 = md5($this->test_url . '_2');
        
        $this->cache_manager->set($url_hash1, $this->test_data);
        $this->cache_manager->set($url_hash2, $this->test_data);
        
        // 清空缓存
        $result = $this->cache_manager->flush();
        
        echo "清空结果: " . ($result ? '成功' : '失败') . "\n";
        
        // 验证清空是否成功
        $data1 = $this->cache_manager->get($url_hash1);
        $data2 = $this->cache_manager->get($url_hash2);
        
        $flushed = ($data1 === false && $data2 === false);
        
        echo "验证清空: " . ($flushed ? '已清空' : '未清空') . "\n";
        echo "测试结果: " . ($flushed ? '通过' : '失败') . "\n\n";
    }
}

// 运行测试
new ChfmCard_Cache_Test();
