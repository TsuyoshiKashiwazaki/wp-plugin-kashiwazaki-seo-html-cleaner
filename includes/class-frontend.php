<?php
/**
 * フロントエンド処理クラス
 *
 * 公開ページのレンダリング出力を出力バッファリングで受け取り、
 * 不要なHTMLコメントと空行を除去する。
 *
 * @package Kashiwazaki_SEO_HTML_Cleaner
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * フロントエンド処理クラス
 */
class Kashiwazaki_SEO_HTML_Cleaner_Frontend {

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
     * 応答が途中 flush で断片化したか
     *
     * @var bool
     */
    private bool $fragmented = false;

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

        add_action('template_redirect', [$this, 'start_output_buffer'], 1);
    }

    /**
     * 出力バッファリングを開始する
     */
    public function start_output_buffer(): void {
        if (!$this->should_process()) {
            return;
        }

        ob_start([$this, 'filter_output']);
    }

    /**
     * このリクエストでクリーニングを行うべきか判定する
     *
     * @return bool
     */
    private function should_process(): bool {
        // 管理画面・AJAX・REST・cron では実行しない
        if (is_admin()) {
            return false;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return false;
        }
        if (defined('DOING_CRON') && DOING_CRON) {
            return false;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }
        if (function_exists('wp_is_json_request') && wp_is_json_request()) {
            return false;
        }

        // フィード・robots・トラックバックは対象外
        if (is_feed() || is_robots() || is_trackback()) {
            return false;
        }

        // 設定が両方とも無効なら何もしない
        if (!$this->settings->is_comments_enabled() && !$this->settings->is_blank_lines_enabled()) {
            return false;
        }

        $request_uri = isset($_SERVER['REQUEST_URI'])
            ? (string) wp_unslash($_SERVER['REQUEST_URI'])
            : '';

        // 非HTMLの拡張子・パスは対象外
        if ($this->is_non_html_request($request_uri)) {
            return false;
        }

        // 除外URL
        if ($this->settings->is_excluded_url($request_uri)) {
            return false;
        }

        return true;
    }

    /**
     * HTML以外の出力が想定されるリクエストか判定する
     *
     * @param string $request_uri リクエストURI
     * @return bool
     */
    private function is_non_html_request(string $request_uri): bool {
        $path = wp_parse_url($request_uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $request_uri;
        }
        $path = strtolower($path);

        // REST API など
        if (strpos($request_uri, '/wp-json/') !== false) {
            return true;
        }

        $non_html_extensions = [
            '.xml', '.xsl', '.json', '.txt', '.csv', '.rss', '.atom',
            '.ics', '.webmanifest', '.kml', '.svg', '.rdf',
        ];
        foreach ($non_html_extensions as $ext) {
            if (substr($path, -strlen($ext)) === $ext) {
                return true;
            }
        }

        return false;
    }

    /**
     * 出力バッファを処理するコールバック
     *
     * @param string $buffer 出力バッファの内容
     * @param int    $phase  PHP_OUTPUT_HANDLER_* のビットマスク
     * @return string クリーニング後の内容
     */
    public function filter_output($buffer, $phase = 0) {
        if (!is_string($buffer) || $buffer === '') {
            return $buffer;
        }

        $is_final = defined('PHP_OUTPUT_HANDLER_FINAL') && ($phase & PHP_OUTPUT_HANDLER_FINAL);
        $is_clean = defined('PHP_OUTPUT_HANDLER_CLEAN') && ($phase & PHP_OUTPUT_HANDLER_CLEAN);

        // 途中 flush（ob_flush 等）で渡された断片は処理しない。
        // 断片はHTMLとして不完全で、保護要素（script 等）が分断されている
        // 可能性があるため、変更せずそのまま通す（破壊を避ける）。
        // 一度でも断片が来たら、以降は断片化した応答とみなす。
        if (!$is_final) {
            $this->fragmented = true;
            return $is_clean ? '' : $buffer;
        }

        // 最終的なバッファ破棄（ob_end_clean 等）: 何も出力しない
        if ($is_clean) {
            return '';
        }

        // 応答が断片化している場合、最終断片も文脈を持たないため処理しない
        if ($this->fragmented) {
            return $buffer;
        }

        // HTML以外（JSON・XML等）が出力されている場合はそのまま返す
        if (!$this->is_html_response()) {
            return $buffer;
        }

        return $this->engine->clean($buffer, [
            'comments'    => $this->settings->is_comments_enabled(),
            'blank_lines' => $this->settings->is_blank_lines_enabled(),
        ]);
    }

    /**
     * レスポンスがHTMLか判定する（Content-Type を確認）
     *
     * @return bool
     */
    private function is_html_response(): bool {
        $headers = headers_list();
        if (!is_array($headers)) {
            return true;
        }

        foreach ($headers as $header) {
            if (stripos($header, 'content-type:') === 0) {
                $value = strtolower($header);
                if (strpos($value, 'text/html') !== false
                    || strpos($value, 'application/xhtml') !== false) {
                    return true;
                }
                return false;
            }
        }

        // Content-Type が未設定の場合は HTML とみなす
        return true;
    }
}
