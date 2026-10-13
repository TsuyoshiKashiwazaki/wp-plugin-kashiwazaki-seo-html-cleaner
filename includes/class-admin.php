<?php
/**
 * 管理画面クラス
 *
 * @package Kashiwazaki_SEO_HTML_Cleaner
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 管理画面クラス
 */
class Kashiwazaki_SEO_HTML_Cleaner_Admin {

    /**
     * 管理画面のページスラッグ
     */
    public const MENU_SLUG = 'kashiwazaki-seo-html-cleaner';

    /**
     * 設定フォームの nonce アクション
     */
    private const NONCE_ACTION = 'kashiwazaki_seo_html_cleaner_save_settings';

    /**
     * 設定フォームの nonce フィールド名
     */
    private const NONCE_FIELD = 'kashiwazaki_seo_html_cleaner_nonce';

    /**
     * 設定インスタンス
     *
     * @var Kashiwazaki_SEO_HTML_Cleaner_Settings
     */
    private Kashiwazaki_SEO_HTML_Cleaner_Settings $settings;

    /**
     * クリーニングエンジン
     *
     * @var Kashiwazaki_SEO_HTML_Cleaner_Engine
     */
    private Kashiwazaki_SEO_HTML_Cleaner_Engine $engine;

    /**
     * テスト結果（クリーニング後のHTML）
     *
     * @var string|null
     */
    private ?string $test_result = null;

    /**
     * コンストラクタ
     *
     * @param Kashiwazaki_SEO_HTML_Cleaner_Settings $settings 設定
     * @param Kashiwazaki_SEO_HTML_Cleaner_Engine   $engine   エンジン
     */
    public function __construct(
        Kashiwazaki_SEO_HTML_Cleaner_Settings $settings,
        Kashiwazaki_SEO_HTML_Cleaner_Engine $engine
    ) {
        $this->settings = $settings;
        $this->engine   = $engine;

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'handle_post']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    /**
     * 管理メニューを追加
     */
    public function add_admin_menu(): void {
        add_menu_page(
            __('Kashiwazaki SEO HTML Cleaner', 'kashiwazaki-seo-html-cleaner'),
            __('Kashiwazaki SEO HTML Cleaner', 'kashiwazaki-seo-html-cleaner'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_settings_page'],
            'dashicons-editor-code',
            81
        );
    }

    /**
     * 送信内容を処理する
     */
    public function handle_post(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        // 設定保存
        if (isset($_POST[self::NONCE_FIELD])) {
            $nonce = sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD]));
            if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
                add_settings_error(
                    self::MENU_SLUG,
                    'nonce_failed',
                    __('セキュリティチェックに失敗しました。もう一度お試しください。', 'kashiwazaki-seo-html-cleaner'),
                    'error'
                );
                return;
            }

            $raw = isset($_POST['kashiwazaki_seo_html_cleaner'])
                ? wp_unslash($_POST['kashiwazaki_seo_html_cleaner'])
                : [];
            $this->settings->save(is_array($raw) ? $raw : []);

            add_settings_error(
                self::MENU_SLUG,
                'settings_updated',
                __('設定を保存しました。', 'kashiwazaki-seo-html-cleaner'),
                'updated'
            );
            return;
        }

        // HTMLクリーニング テスト
        if (isset($_POST['kashiwazaki_seo_html_cleaner_test_submit'])) {
            $nonce_field = 'kashiwazaki_seo_html_cleaner_test_nonce';
            if (!isset($_POST[$nonce_field])
                || !wp_verify_nonce(
                    sanitize_text_field(wp_unslash($_POST[$nonce_field])),
                    'kashiwazaki_seo_html_cleaner_test'
                )
            ) {
                add_settings_error(
                    self::MENU_SLUG,
                    'test_nonce_failed',
                    __('テストのセキュリティチェックに失敗しました。', 'kashiwazaki-seo-html-cleaner'),
                    'error'
                );
                return;
            }

            $test_html = isset($_POST['kashiwazaki_seo_html_cleaner_test_html'])
                ? (string) wp_unslash($_POST['kashiwazaki_seo_html_cleaner_test_html'])
                : '';

            $this->test_result = $this->engine->clean($test_html, [
                'comments'    => $this->settings->is_comments_enabled(),
                'blank_lines' => $this->settings->is_blank_lines_enabled(),
            ]);
        }
    }

    /**
     * 管理画面アセットを読み込み
     *
     * @param string $hook 現在の管理ページフック
     */
    public function enqueue_admin_assets(string $hook): void {
        if ('toplevel_page_' . self::MENU_SLUG !== $hook) {
            return;
        }

        wp_enqueue_style(
            'kashiwazaki-seo-html-cleaner-admin',
            KASHIWAZAKI_SEO_HTML_CLEANER_URL . 'assets/css/admin.css',
            [],
            KASHIWAZAKI_SEO_HTML_CLEANER_VERSION
        );
    }

    /**
     * 設定ページを表示
     */
    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings  = $this->settings->get_all();
        $comments  = !empty($settings['comments']);
        $blanks    = !empty($settings['blank_lines']);
        $excluded  = (string) $settings['excluded_urls'];
        ?>
        <div class="wrap kashiwazaki-seo-html-cleaner-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php settings_errors(self::MENU_SLUG); ?>

            <p class="kashiwazaki-seo-html-cleaner-intro">
                <?php
                esc_html_e(
                    '公開ページのHTMLから、不要なHTMLコメントと空行を除去します。表示や動作に必要な空白・コメント、および pre・textarea・script・style 内の内容は保持されます。',
                    'kashiwazaki-seo-html-cleaner'
                );
                ?>
            </p>

            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('コメント除去', 'kashiwazaki-seo-html-cleaner'); ?></th>
                            <td>
                                <label for="kashiwazaki-seo-html-cleaner-comments">
                                    <input
                                        type="checkbox"
                                        id="kashiwazaki-seo-html-cleaner-comments"
                                        name="kashiwazaki_seo_html_cleaner[comments]"
                                        value="1"
                                        <?php checked($comments); ?>
                                    >
                                    <?php esc_html_e('不要なHTMLコメントを除去する', 'kashiwazaki-seo-html-cleaner'); ?>
                                </label>
                                <p class="description">
                                    <?php
                                    esc_html_e(
                                        '条件付きコメント（<!--[if ...]> ... <![endif]-->）は動作に必要な場合があるため保持します。',
                                        'kashiwazaki-seo-html-cleaner'
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('空行除去', 'kashiwazaki-seo-html-cleaner'); ?></th>
                            <td>
                                <label for="kashiwazaki-seo-html-cleaner-blank-lines">
                                    <input
                                        type="checkbox"
                                        id="kashiwazaki-seo-html-cleaner-blank-lines"
                                        name="kashiwazaki_seo_html_cleaner[blank_lines]"
                                        value="1"
                                        <?php checked($blanks); ?>
                                    >
                                    <?php esc_html_e('空行（空白のみの行の連続）を除去する', 'kashiwazaki-seo-html-cleaner'); ?>
                                </label>
                                <p class="description">
                                    <?php
                                    esc_html_e(
                                        '行内のインデントやタグ間の区切りの改行は保持し、空行のみをまとめます。',
                                        'kashiwazaki-seo-html-cleaner'
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="kashiwazaki-seo-html-cleaner-excluded-urls">
                                    <?php esc_html_e('除外URL', 'kashiwazaki-seo-html-cleaner'); ?>
                                </label>
                            </th>
                            <td>
                                <textarea
                                    id="kashiwazaki-seo-html-cleaner-excluded-urls"
                                    name="kashiwazaki_seo_html_cleaner[excluded_urls]"
                                    rows="6"
                                    class="large-text code"
                                    placeholder="/contact/&#10;/blog/*&#10;https://example.com/private/"
                                ><?php echo esc_textarea($excluded); ?></textarea>
                                <p class="description">
                                    <?php
                                    esc_html_e(
                                        '1行に1つ指定します。完全一致（例: /contact/）／末尾が「/」なら前方一致（例: /blog/ は配下すべて）／「*」でワイルドカード（例: /news/*）に対応します。完全なURLで指定した場合はパス部分で判定します。',
                                        'kashiwazaki-seo-html-cleaner'
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(__('設定を保存', 'kashiwazaki-seo-html-cleaner')); ?>
            </form>

            <hr>

            <h2><?php esc_html_e('HTMLクリーニング テスト', 'kashiwazaki-seo-html-cleaner'); ?></h2>
            <p class="description">
                <?php
                esc_html_e(
                    '上記の設定（保存済みの内容）でHTMLがどう処理されるかを確認できます。テスト用のHTMLを貼り付けて「テスト実行」を押してください。',
                    'kashiwazaki-seo-html-cleaner'
                );
                ?>
            </p>

            <form method="post" action="">
                <?php wp_nonce_field('kashiwazaki_seo_html_cleaner_test', 'kashiwazaki_seo_html_cleaner_test_nonce'); ?>

                <p>
                    <label for="kashiwazaki-seo-html-cleaner-test-html">
                        <strong><?php esc_html_e('テスト前のHTML', 'kashiwazaki-seo-html-cleaner'); ?></strong>
                    </label>
                </p>
                <textarea
                    id="kashiwazaki-seo-html-cleaner-test-html"
                    name="kashiwazaki_seo_html_cleaner_test_html"
                    rows="8"
                    class="large-text code"
                ><?php echo esc_textarea($this->get_test_input()); ?></textarea>

                <p>
                    <button type="submit" class="button" name="kashiwazaki_seo_html_cleaner_test_submit" value="1">
                        <?php esc_html_e('テスト実行', 'kashiwazaki-seo-html-cleaner'); ?>
                    </button>
                </p>
            </form>

            <?php if ($this->test_result !== null) : ?>
                <h3><?php esc_html_e('テスト結果（クリーニング後）', 'kashiwazaki-seo-html-cleaner'); ?></h3>
                <textarea rows="8" class="large-text code" readonly><?php echo esc_textarea($this->test_result); ?></textarea>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * テスト入力欄に表示する値（送信された値、なければ例）
     *
     * @return string
     */
    private function get_test_input(): string {
        if (isset($_POST['kashiwazaki_seo_html_cleaner_test_html'])) {
            return (string) wp_unslash($_POST['kashiwazaki_seo_html_cleaner_test_html']);
        }

        return "<div>\n\n    <!-- ここは不要なコメント -->\n\n    <p>本文です。</p>\n\n\n    <pre>\n\n        <!-- pre内は保持 -->\n\n    </pre>\n\n</div>";
    }
}
