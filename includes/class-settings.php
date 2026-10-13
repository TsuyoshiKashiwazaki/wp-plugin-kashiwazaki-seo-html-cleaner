<?php
/**
 * 設定管理クラス
 *
 * @package Kashiwazaki_SEO_HTML_Cleaner
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 設定管理クラス
 */
class Kashiwazaki_SEO_HTML_Cleaner_Settings {

    /**
     * オプション名
     */
    public const OPTION_NAME = 'kashiwazaki_seo_html_cleaner_settings';

    /**
     * 設定値のキャッシュ
     *
     * @var array|null
     */
    private ?array $settings = null;

    /**
     * デフォルト設定を取得
     *
     * @return array
     */
    public function get_defaults(): array {
        return [
            'comments'      => true,  // 不要なHTMLコメントを除去する
            'blank_lines'   => true,  // 空行を除去する
            'excluded_urls' => '',    // 除外URL（1行に1つ）
        ];
    }

    /**
     * 全設定を取得
     *
     * @return array
     */
    public function get_all(): array {
        if ($this->settings === null) {
            $saved          = get_option(self::OPTION_NAME, []);
            $this->settings = wp_parse_args(is_array($saved) ? $saved : [], $this->get_defaults());
        }
        return $this->settings;
    }

    /**
     * 設定値を取得
     *
     * @param string $key     キー
     * @param mixed  $default 既定値
     * @return mixed
     */
    public function get(string $key, $default = null) {
        $settings = $this->get_all();
        return $settings[$key] ?? $default;
    }

    /**
     * コメント除去が有効か
     *
     * @return bool
     */
    public function is_comments_enabled(): bool {
        return !empty($this->get('comments'));
    }

    /**
     * 空行除去が有効か
     *
     * @return bool
     */
    public function is_blank_lines_enabled(): bool {
        return !empty($this->get('blank_lines'));
    }

    /**
     * 除外URLパターンを行配列で取得
     *
     * @return string[]
     */
    public function get_excluded_urls(): array {
        $raw = $this->get('excluded_urls', '');
        if (is_array($raw)) {
            // 不正な型（配列）で保存されていた場合は文字列に正規化する
            $raw = implode("\n", array_filter($raw, 'is_string'));
        }
        $raw = (string) $raw;
        if (trim($raw) === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw);
        if (!is_array($lines)) {
            return [];
        }

        $patterns = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $patterns[] = $line;
        }
        return $patterns;
    }

    /**
     * 指定URLが除外対象か判定する
     *
     * 指定方法：
     *  - 完全一致（例: /contact/）… 末尾スラッシュの有無を同一視して一致
     *  - 前方一致（末尾が「/」の場合。例: /blog/ は /blog/ 配下すべて）
     *  - ワイルドカード（「*」を含む場合。例: /news/*）
     *  - 完全なURLで指定した場合はパス部分のみで判定
     *
     * @param string $request_uri リクエストURI（$_SERVER['REQUEST_URI']）
     * @return bool
     */
    public function is_excluded_url(string $request_uri): bool {
        $patterns = $this->get_excluded_urls();
        if (empty($patterns)) {
            return false;
        }

        $path = wp_parse_url($request_uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $request_uri;
        }

        foreach ($patterns as $pattern) {
            // 完全なURLが指定された場合はパス部分を取り出す
            if (preg_match('#^[a-z]+://#i', $pattern)) {
                $parsed = wp_parse_url($pattern, PHP_URL_PATH);
                if (is_string($parsed) && $parsed !== '') {
                    $pattern = $parsed;
                }
            }

            if ($pattern === '') {
                continue;
            }

            // ワイルドカード対応
            if (strpos($pattern, '*') !== false) {
                $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
                if (preg_match($regex, $path)) {
                    return true;
                }
                continue;
            }

            // '/' 単独はホーム（'/' ちょうど）を指す（全パスを除外しない）
            if ($pattern === '/') {
                if (rtrim($path, '/') === '') {
                    return true;
                }
                continue;
            }

            // 完全一致（末尾スラッシュは同一視）
            $normalized_pattern = rtrim($pattern, '/');
            $normalized_path    = rtrim($path, '/');
            if ($normalized_pattern !== '' && $normalized_pattern === $normalized_path) {
                return true;
            }

            // 前方一致（末尾が「/」で、'/' 単独ではない場合）。
            // '/' 単独はホーム（'/' ちょうど）を指すものとして扱い、全パスを除外しない。
            if ($pattern !== '/' && substr($pattern, -1) === '/' && strpos($path, $pattern) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 設定を保存する（サニタイズ込み）
     *
     * @param array $input 画面から送信された値（unslash 済み）
     * @return bool
     */
    public function save(array $input): bool {
        $sanitized = [
            'comments'      => !empty($input['comments']),
            'blank_lines'   => !empty($input['blank_lines']),
            'excluded_urls' => $this->sanitize_excluded_urls($input['excluded_urls'] ?? ''),
        ];

        $this->settings = $sanitized;
        return update_option(self::OPTION_NAME, $sanitized);
    }

    /**
     * 除外URL文字列をサニタイズする
     *
     * 1行に1つのURL（またはパス）とし、タグと制御文字を除去し改行を保持する。
     * パーセントエンコード（%xx）は URL の一部であり得るため保持する
     * （sanitize_textarea_field は %xx を「オクテット」として削除するため使わない）。
     *
     * @param mixed $raw 生の入力値
     * @return string
     */
    private function sanitize_excluded_urls($raw): string {
        if (is_array($raw)) {
            $raw = implode("\n", array_filter($raw, 'is_string'));
        }
        $raw = (string) $raw;

        // タグのみ除去する（改行は保持）
        if (function_exists('wp_strip_all_tags')) {
            $raw = wp_strip_all_tags($raw, false);
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw);
        if (!is_array($lines)) {
            return '';
        }

        $clean = [];
        foreach ($lines as $line) {
            $line = (string) $line;
            // 制御文字を除去（%xx は URL の一部なので残す）
            $line = preg_replace('/[\x00-\x1F\x7F]/', '', $line);
            $line = trim($line);
            if ($line !== '') {
                $clean[] = $line;
            }
        }

        return implode("\n", array_unique($clean));
    }

    /**
     * 設定をリセット（削除）する
     *
     * @return bool
     */
    public function reset(): bool {
        $this->settings = null;
        return delete_option(self::OPTION_NAME);
    }
}
