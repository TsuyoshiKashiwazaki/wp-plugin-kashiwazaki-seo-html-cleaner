<?php
/**
 * プラグインアンインストール時の処理
 *
 * @package Kashiwazaki_SEO_HTML_Cleaner
 */

// アンインストール以外からのアクセスを禁止
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// オプションを削除
delete_option('kashiwazaki_seo_html_cleaner_settings');
