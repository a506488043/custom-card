<?php
/**
 * 卸载钩子文件
 * 
 * 当插件被删除时执行此文件
 */

// 安全检查：防止直接访问PHP文件
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit; // Exit if accessed directly
}

// 加载缓存管理器
require_once plugin_dir_path(__FILE__) . 'includes/class-cache-manager.php';

/**
 * 插件卸载清理类
 */
class ChfmCard_Uninstaller {
    /**
     * 执行卸载清理
     */
    public static function uninstall() {
        // 删除数据库表
        self::drop_tables();
        
        // 清理缓存
        self::clean_cache();
        
        // 删除缓存目录
        self::delete_cache_directory();
        
        // 记录卸载日志
        error_log('ChfmCard: Plugin completely uninstalled');
    }
    
    /**
     * 删除数据库表
     */
    private static function drop_tables() {
        global $wpdb;
        $table = $wpdb->prefix . 'chf_card_cache';
        $wpdb->query("DROP TABLE IF EXISTS $table");
    }
    
    /**
     * 清理缓存
     */
    private static function clean_cache() {
        // 实例化缓存管理器
        $cache_manager = new ChfmCard_Cache_Manager();
        
        // 清空所有缓存
        $cache_manager->flush();
    }
    
    /**
     * 递归删除缓存目录
     */
    private static function delete_cache_directory() {
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/chfm-card-cache';
        
        if (is_dir($cache_dir)) {
            self::recursive_rmdir($cache_dir);
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

// 执行卸载清理
ChfmCard_Uninstaller::uninstall();
