<?php
/**
 * 缓存测试脚本（独立版本）
 * 
 * 用于测试多级缓存的命中与回源场景
 * 不依赖WordPress环境，可直接运行
 */

// 直接包含缓存管理器类定义
class ChfmCard_Cache_Manager {
    /**
     * 缓存前缀
     */
    const CACHE_PREFIX = 'chfm_card_';
    
    /**
     * 缓存过期时间（秒）
     */
    const CACHE_EXPIRE = 259200; // 72小时，与数据库缓存保持一致
    
    /**
     * Memcached实例
     */
    private $memcached = null;
    
    /**
     * 是否启用Memcached
     */
    private $memcached_enabled = false;
    
    /**
     * 是否启用Opcache
     */
    private $opcache_enabled = false;
    
    /**
     * 构造函数
     */
    public function __construct() {
        // 检查Memcached是否可用
        $this->memcached_enabled = $this->init_memcached();
        
        // 检查Opcache是否可用
        $this->opcache_enabled = function_exists('opcache_invalidate') && ini_get('opcache.enable');
        
        // 记录缓存初始化状态
        $this->log_cache_status();
    }
    
    /**
     * 初始化Memcached连接
     * 
     * @return bool 是否成功初始化
     */
    private function init_memcached() {
        // 检查Memcached扩展是否可用
        if (!class_exists('Memcached')) {
            echo "Memcached扩展未安装\n";
            return false;
        }
        
        try {
            // 创建Memcached实例
            $this->memcached = new Memcached();
            
            // 添加服务器（默认本地）
            $memcached_host = '127.0.0.1';
            $memcached_port = 11211;
            
            // 检查是否已添加服务器
            $servers = $this->memcached->getServerList();
            if (empty($servers)) {
                $this->memcached->addServer($memcached_host, $memcached_port);
            }
            
            // 测试连接
            $test_key = self::CACHE_PREFIX . 'test';
            $test_value = 'test_' . time();
            $this->memcached->set($test_key, $test_value, 60);
            $result = $this->memcached->get($test_key);
            
            return ($result === $test_value);
        } catch (Exception $e) {
            echo "Memcached初始化失败: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * 记录缓存状态
     */
    private function log_cache_status() {
        echo sprintf(
            "缓存状态 - Memcached: %s, Opcache: %s\n",
            $this->memcached_enabled ? '启用' : '禁用',
            $this->opcache_enabled ? '启用' : '禁用'
        );
    }
    
    /**
     * 从缓存获取数据
     * 
     * @param string $url_hash URL哈希值
     * @return array|false 缓存数据或false（未命中）
     */
    public function get($url_hash) {
        $cache_key = $this->get_cache_key($url_hash);
        $data = false;
        $cache_source = '';
        
        // 1. 尝试从Opcache获取
        if ($this->opcache_enabled) {
            $opcache_file = $this->get_opcache_file($url_hash);
            if (file_exists($opcache_file) && is_readable($opcache_file)) {
                $data = $this->get_from_opcache($url_hash);
                if ($data !== false) {
                    $cache_source = 'opcache';
                }
            }
        }
        
        // 2. 如果Opcache未命中，尝试从Memcached获取
        if ($data === false && $this->memcached_enabled) {
            $data = $this->memcached->get($cache_key);
            if ($data !== false) {
                $cache_source = 'memcached';
                
                // 同步到Opcache以保持一致性
                if ($this->opcache_enabled) {
                    $this->save_to_opcache($url_hash, $data);
                }
            }
        }
        
        // 记录缓存命中情况
        if ($data !== false) {
            echo sprintf("缓存命中 [%s] - %s\n", $cache_source, $url_hash);
        }
        
        return $data;
    }
    
    /**
     * 保存数据到缓存
     * 
     * @param string $url_hash URL哈希值
     * @param array $data 要缓存的数据
     * @return bool 是否成功
     */
    public function set($url_hash, $data) {
        $cache_key = $this->get_cache_key($url_hash);
        $success = false;
        
        // 1. 保存到Memcached
        if ($this->memcached_enabled) {
            $success = $this->memcached->set($cache_key, $data, self::CACHE_EXPIRE);
        }
        
        // 2. 保存到Opcache
        if ($this->opcache_enabled) {
            $success = $this->save_to_opcache($url_hash, $data) || $success;
        }
        
        return $success;
    }
    
    /**
     * 删除缓存
     * 
     * @param string $url_hash URL哈希值
     * @return bool 是否成功
     */
    public function delete($url_hash) {
        $cache_key = $this->get_cache_key($url_hash);
        $success = false;
        
        // 1. 从Memcached删除
        if ($this->memcached_enabled) {
            $success = $this->memcached->delete($cache_key);
        }
        
        // 2. 从Opcache删除
        if ($this->opcache_enabled) {
            $opcache_file = $this->get_opcache_file($url_hash);
            if (file_exists($opcache_file)) {
                @unlink($opcache_file);
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($opcache_file, true);
                }
                $success = true;
            }
        }
        
        return $success;
    }
    
    /**
     * 清空所有缓存
     * 
     * @return bool 是否成功
     */
    public function flush() {
        $success = false;
        
        // 1. 清空Memcached
        if ($this->memcached_enabled) {
            $success = $this->memcached->flush();
        }
        
        // 2. 清空Opcache文件
        if ($this->opcache_enabled) {
            $cache_dir = $this->get_opcache_dir();
            if (is_dir($cache_dir)) {
                $files = glob($cache_dir . '/*.php');
                foreach ($files as $file) {
                    @unlink($file);
                    if (function_exists('opcache_invalidate')) {
                        opcache_invalidate($file, true);
                    }
                }
            }
            $success = true;
        }
        
        return $success;
    }
    
    /**
     * 获取缓存键名
     * 
     * @param string $url_hash URL哈希值
     * @return string 缓存键名
     */
    private function get_cache_key($url_hash) {
        return self::CACHE_PREFIX . $url_hash;
    }
    
    /**
     * 获取Opcache缓存目录
     * 
     * @return string 缓存目录路径
     */
    private function get_opcache_dir() {
        $cache_dir = __DIR__ . '/cache';
        
        // 确保目录存在
        if (!is_dir($cache_dir)) {
            mkdir($cache_dir, 0755, true);
            
            // 创建index.php防止目录列表
            $index_file = $cache_dir . '/index.php';
            if (!file_exists($index_file)) {
                file_put_contents($index_file, '<?php // Silence is golden');
            }
        }
        
        return $cache_dir;
    }
    
    /**
     * 获取Opcache缓存文件路径
     * 
     * @param string $url_hash URL哈希值
     * @return string 缓存文件路径
     */
    private function get_opcache_file($url_hash) {
        return $this->get_opcache_dir() . '/' . $url_hash . '.php';
    }
    
    /**
     * 从Opcache获取数据
     * 
     * @param string $url_hash URL哈希值
     * @return array|false 缓存数据或false（未命中）
     */
    private function get_from_opcache($url_hash) {
        $opcache_file = $this->get_opcache_file($url_hash);
        
        if (!file_exists($opcache_file) || !is_readable($opcache_file)) {
            return false;
        }
        
        // 检查文件是否过期
        $file_time = filemtime($opcache_file);
        if ($file_time === false || (time() - $file_time) > self::CACHE_EXPIRE) {
            // 文件过期，删除并返回false
            @unlink($opcache_file);
            return false;
        }
        
        // 包含PHP文件并获取数据
        try {
            $data = include $opcache_file;
            return is_array($data) ? $data : false;
        } catch (Exception $e) {
            echo "Opcache读取错误: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * 保存数据到Opcache
     * 
     * @param string $url_hash URL哈希值
     * @param array $data 要缓存的数据
     * @return bool 是否成功
     */
    private function save_to_opcache($url_hash, $data) {
        $opcache_file = $this->get_opcache_file($url_hash);
        
        // 准备PHP代码
        $php_code = "<?php\n// Generated: " . date('Y-m-d H:i:s') . "\n";
        $php_code .= "// Expires: " . date('Y-m-d H:i:s', time() + self::CACHE_EXPIRE) . "\n";
        $php_code .= "return " . var_export($data, true) . ";\n";
        
        // 写入文件
        $result = file_put_contents($opcache_file, $php_code);
        
        // 如果写入成功且Opcache可用，使其失效以便重新缓存
        if ($result && function_exists('opcache_invalidate')) {
            opcache_invalidate($opcache_file, true);
        }
        
        return ($result !== false);
    }
    
    /**
     * 检查缓存是否可用
     * 
     * @return bool 是否有任一缓存可用
     */
    public function is_cache_available() {
        return $this->memcached_enabled || $this->opcache_enabled;
    }
    
    /**
     * 获取缓存状态信息
     * 
     * @return array 缓存状态信息
     */
    public function get_cache_status() {
        return [
            'memcached' => $this->memcached_enabled,
            'opcache' => $this->opcache_enabled,
            'any_available' => $this->is_cache_available(),
        ];
    }
}

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
