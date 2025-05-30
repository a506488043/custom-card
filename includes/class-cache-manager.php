<?php
/**
 * 缓存管理器类
 * 
 * 提供多级缓存支持，包括Opcache和Memcached
 */

// 安全检查：防止直接访问PHP文件
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

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
            return false;
        }
        
        try {
            // 创建Memcached实例
            $this->memcached = new Memcached();
            
            // 添加服务器（默认本地）
            // 可以通过常量或配置文件自定义
            $memcached_host = defined('WP_MEMCACHED_HOST') ? WP_MEMCACHED_HOST : '127.0.0.1';
            $memcached_port = defined('WP_MEMCACHED_PORT') ? WP_MEMCACHED_PORT : 11211;
            
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
            error_log('ChfmCard: Memcached初始化失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 记录缓存状态
     */
    private function log_cache_status() {
        error_log(sprintf(
            'ChfmCard: 缓存状态 - Memcached: %s, Opcache: %s',
            $this->memcached_enabled ? '启用' : '禁用',
            $this->opcache_enabled ? '启用' : '禁用'
        ));
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
            error_log(sprintf('ChfmCard: 缓存命中 [%s] - %s', $cache_source, $url_hash));
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
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/chfm-card-cache';
        
        // 确保目录存在
        if (!is_dir($cache_dir)) {
            wp_mkdir_p($cache_dir);
            
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
            error_log('ChfmCard: Opcache读取错误: ' . $e->getMessage());
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
