<?php
if (!defined('ABSPATH')) exit;// Exit if accessed directly

class CCP_Installer {

    const DB_VERSION = '2.0';
    const TABLE_NAME = 'cards';

    public static function activate() {
        self::create_tables();
        self::update_db_version();
    }

    public static function deactivate() {
        // 按需添加清理代码
    }

    private static function create_tables() {
        global $wpdb;

        $table_name = $wpdb->prefix . CCP_Custom_Card::PLUGIN_PREFIX . self::TABLE_NAME;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) UNSIGNED NOT NULL AUTO_INCREMENT,
            title varchar(200) NOT NULL DEFAULT '',
            content longtext NOT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function update_db_version() {
        update_option(CCP_Custom_Card::PLUGIN_PREFIX . 'db_version', self::DB_VERSION);
    }
}