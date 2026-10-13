<?php
/**
 * Plugin Name: Kashiwazaki SEO HTML Cleaner
 * Plugin URI: https://www.tsuyoshikashiwazaki.jp
 * Description: 公開ページのHTMLから不要なHTMLコメントと空行を除去します。表示や動作に必要な空白・コメントと、pre・textarea・script・style内の内容は保持します。管理画面でコメント除去・空行除去のON/OFFと除外URLを設定できます。
 * Version: 1.0.0
 * Author: 柏崎剛 (Tsuyoshi Kashiwazaki)
 * Author URI: https://www.tsuyoshikashiwazaki.jp/profile/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: kashiwazaki-seo-html-cleaner
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package Kashiwazaki_SEO_HTML_Cleaner
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

// プラグイン定数
define('KASHIWAZAKI_SEO_HTML_CLEANER_VERSION', '1.0.0');
define('KASHIWAZAKI_SEO_HTML_CLEANER_FILE', __FILE__);
define('KASHIWAZAKI_SEO_HTML_CLEANER_DIR', plugin_dir_path(__FILE__));
define('KASHIWAZAKI_SEO_HTML_CLEANER_URL', plugin_dir_url(__FILE__));
define('KASHIWAZAKI_SEO_HTML_CLEANER_BASENAME', plugin_basename(__FILE__));

/**
 * メインプラグインクラス
 */
final class Kashiwazaki_SEO_HTML_Cleaner {

    /**
     * シングルトンインスタンス
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * 設定インスタンス
     *
     * @var Kashiwazaki_SEO_HTML_Cleaner_Settings
     */
    public Kashiwazaki_SEO_HTML_Cleaner_Settings $settings;

    /**
     * HTMLクリーニングエンジン
     *
     * @var Kashiwazaki_SEO_HTML_Cleaner_Engine
     */
    public Kashiwazaki_SEO_HTML_Cleaner_Engine $engine;

    /**
     * 管理画面インスタンス
     *
     * @var Kashiwazaki_SEO_HTML_Cleaner_Admin|null
     */
    public ?Kashiwazaki_SEO_HTML_Cleaner_Admin $admin = null;

    /**
     * フロントエンドインスタンス
     *
     * @var Kashiwazaki_SEO_HTML_Cleaner_Frontend|null
     */
    public ?Kashiwazaki_SEO_HTML_Cleaner_Frontend $frontend = null;

    /**
     * シングルトンインスタンスを取得
     */
    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * コンストラクタ
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_components();
        $this->register_hooks();
    }

    /**
     * 依存ファイルを読み込み
     */
    private function load_dependencies(): void {
        require_once KASHIWAZAKI_SEO_HTML_CLEANER_DIR . 'includes/class-settings.php';
        require_once KASHIWAZAKI_SEO_HTML_CLEANER_DIR . 'includes/class-engine.php';
        require_once KASHIWAZAKI_SEO_HTML_CLEANER_DIR . 'includes/class-frontend.php';
        require_once KASHIWAZAKI_SEO_HTML_CLEANER_DIR . 'includes/class-admin.php';
    }

    /**
     * コンポーネントを初期化
     */
    private function init_components(): void {
        $this->settings = new Kashiwazaki_SEO_HTML_Cleaner_Settings();
        $this->engine   = new Kashiwazaki_SEO_HTML_Cleaner_Engine();

        if (is_admin()) {
            $this->admin = new Kashiwazaki_SEO_HTML_Cleaner_Admin($this->settings, $this->engine);
        } else {
            $this->frontend = new Kashiwazaki_SEO_HTML_Cleaner_Frontend($this->settings, $this->engine);
        }
    }

    /**
     * フックを登録
     */
    private function register_hooks(): void {
        // プラグイン有効化時
        register_activation_hook(KASHIWAZAKI_SEO_HTML_CLEANER_FILE, [$this, 'activate']);

        // プラグイン一覧にアクションリンクを追加
        add_filter(
            'plugin_action_links_' . KASHIWAZAKI_SEO_HTML_CLEANER_BASENAME,
            [$this, 'add_action_links']
        );

        // 翻訳ファイルの読み込み
        add_action('init', [$this, 'load_textdomain']);
    }

    /**
     * プラグイン有効化
     */
    public function activate(): void {
        if (get_option(Kashiwazaki_SEO_HTML_Cleaner_Settings::OPTION_NAME) === false) {
            update_option(
                Kashiwazaki_SEO_HTML_Cleaner_Settings::OPTION_NAME,
                $this->settings->get_defaults()
            );
        }
    }

    /**
     * プラグイン一覧に「設定」リンクを追加
     *
     * @param array $links 既存のアクションリンク
     * @return array
     */
    public function add_action_links(array $links): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=kashiwazaki-seo-html-cleaner')),
            esc_html__('設定', 'kashiwazaki-seo-html-cleaner')
        );
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * 翻訳ファイルを読み込み
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            'kashiwazaki-seo-html-cleaner',
            false,
            dirname(KASHIWAZAKI_SEO_HTML_CLEANER_BASENAME) . '/languages'
        );
    }
}

/**
 * プラグインインスタンスを取得
 *
 * @return Kashiwazaki_SEO_HTML_Cleaner
 */
function kashiwazaki_seo_html_cleaner(): Kashiwazaki_SEO_HTML_Cleaner {
    return Kashiwazaki_SEO_HTML_Cleaner::get_instance();
}

// プラグインを初期化
kashiwazaki_seo_html_cleaner();
