<?php
/**
 * HTMLクリーニングエンジン
 *
 * 公開ページのHTML文字列から：
 *  - 不要なHTMLコメント（表示・動作に必要な条件付きコメントは保持）
 *  - 空行（空白のみの行の連続）
 * を除去する。pre・textarea・script・style の内容は保護し、一切変更しない。
 *
 * 実装方針:
 *  HTMLを1パスで走査し、開始タグを専用パーサ（属性名・属性値の引用符・
 *  非引用値・'=' 後の空白を考慮）で解析する。script は script data の
 *  escaped/double-escaped 状態も考慮し、style は RAWTEXT、textarea/title は
 *  RCDATA として終了タグまで保護する。pre と white-space 保持を指定した要素は
 *  入れ子を考慮して対応する終了タグまで保護する。
 *
 *  タグ・保持コメント・保護ブロックはすべて改行を含まないプレースホルダに
 *  退避し、テキスト部分だけに空行除去を適用してから復元する。これにより
 *  属性値やタグの中身（引用符内の改行を含む）は一切変更しない。
 *
 * @package Kashiwazaki_SEO_HTML_Cleaner
 */

// 直接アクセス禁止
if (!defined('ABSPATH')) {
    exit;
}

/**
 * HTMLクリーニングエンジンクラス
 */
class Kashiwazaki_SEO_HTML_Cleaner_Engine {

    /**
     * 内容を保護する通常要素（内容を一切変更しない）
     *
     * @var string[]
     */
    private const PROTECTED_ELEMENTS = ['pre'];

    /**
     * 内容がマークアップとして解釈されない要素（終了タグまで保護）
     *
     * @var string[]
     */
    private const RAWTEXT_ELEMENTS = ['xmp', 'iframe', 'noembed', 'noframes'];

    /**
     * void 要素（終了タグを持たない。white-space 保持の保護対象にしない）
     *
     * @var string[]
     */
    private const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * 空白保持を意味する CSS 宣言（style 属性で検出）
     *
     * 宣言の区切り（先頭または ';'）を要求し、url(...) 等に紛れた
     * 'white-space:pre' の部分一致を拾わない。
     */
    private const WHITESPACE_PRESERVE_PATTERN = '/(?:^|;)\s*white-space\s*:\s*(?:pre-wrap|pre-line|break-spaces|pre)\b/i';

    /**
     * HTMLをクリーニングする
     *
     * @param string $html    対象HTML
     * @param array  $options {
     *     @type bool $comments    不要なHTMLコメントを除去するか
     *     @type bool $blank_lines 空行を除去するか
     * }
     * @return string クリーニング後のHTML
     */
    public function clean(string $html, array $options = []): string {
        $remove_comments    = !empty($options['comments']);
        $remove_blank_lines = !empty($options['blank_lines']);

        if ((!$remove_comments && !$remove_blank_lines) || $html === '') {
            return $html;
        }

        return $this->render($html, $remove_comments, $remove_blank_lines);
    }

    /**
     * 1パス走査でHTMLを処理する
     *
     * @param string $html            対象HTML
     * @param bool   $remove_comments コメントを除去するか
     * @param bool   $remove_blank    空行を除去するか
     * @return string
     */
    private function render(string $html, bool $remove_comments, bool $remove_blank): string {
        $length = strlen($html);
        $seed   = $this->create_seed($html);
        $tokens = [];
        $index  = 0;
        $out    = '';
        $pos    = 0;

        while ($pos < $length) {
            $lt = strpos($html, '<', $pos);

            if ($lt === false) {
                $out .= substr($html, $pos);
                break;
            }
            if ($lt > $pos) {
                $out .= substr($html, $pos, $lt - $pos);
            }
            $pos  = $lt;
            $next = ($pos + 1 < $length) ? $html[$pos + 1] : '';

            // コメント <!-- ... -->
            if ($next === '!' && substr($html, $pos, 4) === '<!--') {
                $end = $this->comment_end($html, $pos);
                if ($end === false) {
                    $out .= substr($html, $pos);
                    break;
                }
                $comment = substr($html, $pos, $end - $pos);
                if ($this->keep_comment($comment, $remove_comments)) {
                    $token           = "\x01" . $seed . '_' . $index . "\x01";
                    $index++;
                    $tokens[$token] = $comment;
                    $out           .= $token;
                }
                $pos = $end;
                continue;
            }

            // CDATA セクション <![CDATA[ ... ]]>（SVG 等）
            if ($next === '!' && substr($html, $pos, 9) === '<![CDATA[') {
                $end = strpos($html, ']]>', $pos + 9);
                if ($end === false) {
                    $out .= substr($html, $pos);
                    break;
                }
                $token           = "\x01" . $seed . '_' . $index . "\x01";
                $index++;
                $tokens[$token] = substr($html, $pos, $end + 3 - $pos);
                $out           .= $token;
                $pos            = $end + 3;
                continue;
            }

            // 宣言・bogus コメント <! ... >
            if ($next === '!') {
                $end = strpos($html, '>', $pos + 1);
                if ($end === false) {
                    $out .= substr($html, $pos);
                    break;
                }
                $token           = "\x01" . $seed . '_' . $index . "\x01";
                $index++;
                $tokens[$token] = substr($html, $pos, $end + 1 - $pos);
                $out           .= $token;
                $pos            = $end + 1;
                continue;
            }

            // 終了タグ </ ... >
            if ($next === '/') {
                $end = strpos($html, '>', $pos);
                if ($end === false) {
                    $out .= substr($html, $pos);
                    break;
                }
                $token           = "\x01" . $seed . '_' . $index . "\x01";
                $index++;
                $tokens[$token] = substr($html, $pos, $end + 1 - $pos);
                $out           .= $token;
                $pos            = $end + 1;
                continue;
            }

            // 開始タグ <name ...>
            if (($next >= 'a' && $next <= 'z') || ($next >= 'A' && $next <= 'Z')) {
                $parsed = $this->parse_tag($html, $pos);
                if ($parsed['end'] === false) {
                    $out .= '<';
                    $pos++;
                    continue;
                }

                $tag_end = $parsed['end'];
                $name    = strtolower($this->read_name($html, $pos + 1));

                // HTML の要素（pre/textarea/script/style/title）は void ではないため、
                // 末尾の '/'（自己終了マーカー）があっても要素は閉じない。
                // したがって自己終了かどうかに関わらず対応する終了タグまで保護する。
                $protect = false;
                $close   = false;
                if ($name === 'script') {
                    $protect = true;
                    $close   = $this->find_script_close($html, $tag_end);
                } elseif ($name === 'style') {
                    $protect = true;
                    $close   = $this->find_raw_close($html, $tag_end, 'style');
                } elseif ($name === 'textarea' || $name === 'title') {
                    $protect = true;
                    $close   = $this->find_raw_close($html, $tag_end, $name);
                } elseif (in_array($name, self::RAWTEXT_ELEMENTS, true)) {
                    // xmp / iframe / noembed / noframes: 内容はマークアップとして解釈されない
                    $protect = true;
                    $close   = $this->find_raw_close($html, $tag_end, $name);
                } elseif ($name === 'plaintext') {
                    // plaintext: 以降すべてがテキスト（終了タグは無い）
                    $protect = true;
                    $close   = false;
                } elseif ($name !== ''
                    && !in_array($name, self::VOID_ELEMENTS, true)
                    && (in_array($name, self::PROTECTED_ELEMENTS, true)
                        || $this->attrs_whitespace_preserving($parsed['attrs']))
                ) {
                    // void 要素は終了タグを持たないため、white-space 保持の対象にしない
                    $protect = true;
                    $close   = $this->find_matching_close($html, $tag_end, $name);
                }

                $token = "\x01" . $seed . '_' . $index . "\x01";
                $index++;

                if ($protect) {
                    // 終了タグが無い場合は残り全体を保護（変更しない）
                    $tokens[$token] = ($close === false)
                        ? substr($html, $pos)
                        : substr($html, $pos, $close - $pos);
                    $out .= $token;
                    $pos  = ($close === false) ? $length : $close;
                    continue;
                }

                // 通常のタグ（属性値ごとそのまま退避）
                $tokens[$token] = substr($html, $pos, $tag_end - $pos);
                $out           .= $token;
                $pos            = $tag_end;
                continue;
            }

            // '<' がタグ開始でない
            $out .= '<';
            $pos++;
        }

        // 空行を除去する（タグ・コメント・保護ブロックはプレースホルダで改行を含まない）
        if ($remove_blank) {
            $result = preg_replace('/\r?\n(?:[ \t]*\r?\n)+/', "\n", $out);
            if ($result !== null) {
                $out = $result;
            }
        }

        // 退避したタグ・コメント・保護ブロックを復元する
        if (!empty($tokens)) {
            $out = strtr($out, $tokens);
        }

        return $out;
    }

    /**
     * プレースホルダ用のシード文字列を生成する
     *
     * @param string $html 対象HTML
     * @return string
     */
    private function create_seed(string $html): string {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $seed = substr(md5(uniqid((string) mt_rand(), true)), 0, 10);
            if (strpos($html, $seed) === false && strpos($html, "\x01") === false) {
                return $seed;
            }
        }
        return substr(md5(uniqid((string) mt_rand(), true)), 0, 10);
    }

    /**
     * コメントの終端（'<!--' の位置から、コメントが閉じる次の位置）を返す
     *
     * HTML仕様のコメント終了は '-->' 以外に '--!>'、および空コメントの即時終了
     * '<!-->' '<!--->' がある。これらを取りこぼすと、後続の本文まで
     * コメントとして削除してしまう。
     *
     * @param string $html 対象HTML
     * @param int    $start '<!--' の位置
     * @return int|false コメント終了の次の位置（未終端なら false）
     */
    private function comment_end(string $html, int $start) {
        $length = strlen($html);
        $p      = $start + 4; // '<!--' の直後

        // 空コメントの即時終了: <!--> は '>' で、<!---> は '- >' で閉じる
        if ($p < $length && $html[$p] === '>') {
            return $p + 1;
        }
        if ($p + 1 < $length && $html[$p] === '-' && $html[$p + 1] === '>') {
            return $p + 2;
        }

        $pos = $p;
        while ($pos < $length) {
            if (substr($html, $pos, 3) === '-->') {
                return $pos + 3;
            }
            if (substr($html, $pos, 4) === '--!>') {
                return $pos + 4;
            }
            $pos++;
        }
        return false;
    }

    /**
     * コメントを保持すべきか判定する
     *
     * @param string $comment         コメント全体（<!-- ... -->）
     * @param bool   $remove_comments コメント除去が有効か
     * @return bool
     */
    private function keep_comment(string $comment, bool $remove_comments): bool {
        if (!$remove_comments) {
            return true;
        }
        if (preg_match('#^<!--\s*\[if\b#i', $comment)) {
            return true;
        }
        if (stripos($comment, '<![endif]') !== false) {
            return true;
        }
        return false;
    }

    /**
     * 開始タグを解析する
     *
     * 返す連想配列:
     *  - end  : '>' の次の位置（解析できなければ false）
     *  - self : '/' の直後が '>' の場合だけ true（HTML仕様の自己終了フラグ）
     *  - attrs: 属性名(小文字) => 値 の配列
     *
     * 属性値は引用符付き（"..." / '...'）と非引用（空白まで）に対応し、
     * '=' の直後の空白も飛ばす（HTML仕様の before attribute value state）。
     *
     * @param string $html 対象HTML
     * @param int    $from '<' の位置
     * @return array{end:int|false,self:bool,attrs:array<string,string>}
     */
    private function parse_tag(string $html, int $from): array {
        $length = strlen($html);
        $i      = $from + 1;
        $self   = false;
        $attrs  = [];

        // タグ名をスキップ
        while ($i < $length) {
            $c = $html[$i];
            if (($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z')
                || ($c >= '0' && $c <= '9') || $c === '-' || $c === ':' || $c === '_'
            ) {
                $i++;
                continue;
            }
            break;
        }

        while ($i < $length) {
            $c = $html[$i];

            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if ($c === '>') {
                return ['end' => $i + 1, 'self' => $self, 'attrs' => $attrs];
            }
            if ($c === '/') {
                // '/' の直後が '>' のときだけ自己終了フラグが立つ
                if ($i + 1 < $length && $html[$i + 1] === '>') {
                    return ['end' => $i + 2, 'self' => true, 'attrs' => $attrs];
                }
                // それ以外の '/' は解析エラーとして無視する（自己終了にしない）
                $i++;
                continue;
            }

            // 属性名
            $name_start = $i;
            while ($i < $length) {
                $c = $html[$i];
                if ($c === '=' || $c === '>' || $c === '/' || ctype_space($c)) {
                    break;
                }
                $i++;
            }
            $name = strtolower(substr($html, $name_start, $i - $name_start));

            // '=' の手前の空白を許容する
            $j = $i;
            while ($j < $length && ctype_space($html[$j])) {
                $j++;
            }

            if ($j < $length && $html[$j] === '=') {
                $i = $j + 1;
                // '=' の直後の空白を飛ばす（before attribute value state）
                while ($i < $length && ctype_space($html[$i])) {
                    $i++;
                }
                if ($i >= $length) {
                    return ['end' => false, 'self' => false, 'attrs' => []];
                }
                $c = $html[$i];
                if ($c === '"' || $c === "'") {
                    $quote = $c;
                    $i++;
                    $vstart = $i;
                    while ($i < $length && $html[$i] !== $quote) {
                        $i++;
                    }
                    $value = substr($html, $vstart, $i - $vstart);
                    if ($i < $length) {
                        $i++;
                    }
                } else {
                    $vstart = $i;
                    while ($i < $length) {
                        $c = $html[$i];
                        if ($c === '>' || ctype_space($c)) {
                            break;
                        }
                        $i++;
                    }
                    $value = substr($html, $vstart, $i - $vstart);
                }
                if ($name !== '') {
                    $attrs[$name] = $value;
                }
                continue;
            }

            // '=' が無い属性（真偽値属性）
            if ($name !== '') {
                $attrs[$name] = '';
            }
            // $i は区切り文字の位置のままループ先頭へ
        }

        return ['end' => false, 'self' => false, 'attrs' => []];
    }

    /**
     * タグ名を読み取る
     *
     * @param string $html  対象HTML
     * @param int    $start 名前の先頭位置
     * @return string
     */
    private function read_name(string $html, int $start): string {
        $length = strlen($html);
        $i      = $start;
        // HTML のタグ名は空白・'/'・'>' 以外の文字を含む（'.' なども名前の一部）。
        // これにより </pre.foo> を </pre> と誤認しない。
        while ($i < $length) {
            $c = $html[$i];
            if ($c === '>' || $c === '/' || ctype_space($c)) {
                break;
            }
            $i++;
        }
        return substr($html, $start, $i - $start);
    }

    /**
     * style / textarea / title の終了タグ直後の位置を返す（RAWTEXT / RCDATA）
     *
     * @param string $html 対象HTML
     * @param int    $from 開始タグの '>' の次の位置
     * @param string $tag  タグ名（小文字）
     * @return int|false
     */
    private function find_raw_close(string $html, int $from, string $tag) {
        $needle = '</' . $tag;
        $len    = strlen($needle);
        $length = strlen($html);
        $pos    = $from;

        while (($p = stripos($html, $needle, $pos)) !== false) {
            $after = $p + $len;
            $ch    = ($after < $length) ? $html[$after] : '';
            if ($ch === '' || $ch === '>' || $ch === '/' || ctype_space($ch)) {
                $gt = strpos($html, '>', $p);
                if ($gt === false) {
                    return false;
                }
                return $gt + 1;
            }
            $pos = $p + $len;
        }
        return false;
    }

    /**
     * script 要素の終了タグ直後の位置を返す（script data 状態）
     *
     * `<!--` による escaped、その中の `<script` による double-escaped を追跡し、
     * double-escaped 中でない `</script` を終了とする。
     *
     * @param string $html 対象HTML
     * @param int    $from 開始タグの '>' の次の位置
     * @return int|false
     */
    private function find_script_close(string $html, int $from) {
        $length  = strlen($html);
        $i       = $from;
        $escaped = false;
        $double  = false;

        while ($i < $length) {
            if (!$double && $this->tag_at($html, $i, '</script')) {
                $gt = strpos($html, '>', $i);
                if ($gt === false) {
                    return false;
                }
                return $gt + 1;
            }

            if (!$escaped) {
                if (substr($html, $i, 4) === '<!--') {
                    $escaped = true;
                    $i      += 4;
                    continue;
                }
                $i++;
                continue;
            }

            if (!$double) {
                if (substr($html, $i, 3) === '-->') {
                    $escaped = false;
                    $i      += 3;
                    continue;
                }
                if ($this->tag_at($html, $i, '<script')) {
                    $double = true;
                    $i     += 7;
                    continue;
                }
                $i++;
                continue;
            }

            if (substr($html, $i, 3) === '-->') {
                $escaped = false;
                $double  = false;
                $i      += 3;
                continue;
            }
            if ($this->tag_at($html, $i, '</script')) {
                $double = false;
                $i     += 8; // '</script' は 8 文字
                continue;
            }
            $i++;
        }

        return false;
    }

    /**
     * $pos の位置に指定のタグ文字列（小文字・先頭 '<' を含む）が始まるか判定する
     *
     * 直後が空白・'/'・'>'・終端であることを要求する。
     * 正規表現の '^' はオフセット付き preg_match では指定位置にマッチしないため
     * 使わず、部分文字列の比較で判定する。
     *
     * @param string $html   対象HTML
     * @param int    $pos    判定位置
     * @param string $needle 例: '</script' / '<script'
     * @return bool
     */
    private function tag_at(string $html, int $pos, string $needle): bool {
        if ($html[$pos] !== '<') {
            return false;
        }
        $len = strlen($needle);
        if (strncasecmp(substr($html, $pos, $len), $needle, $len) !== 0) {
            return false;
        }
        $after = $pos + $len;
        if ($after >= strlen($html)) {
            return true;
        }
        $ch = $html[$after];
        return $ch === '>' || $ch === '/' || ctype_space($ch);
    }

    /**
     * 通常要素の対応する終了タグ直後の位置を返す
     *
     * 走査中に、コメント・タグ（属性値を考慮）・入れ子の RAW/RCDATA 要素を
     * 飛ばしながら、深さ 0 になる終了タグを探す。HTML の非 void 要素は
     * 自己終了しないため、'/' の有無に関わらず入れ子として数える。
     *
     * @param string $html 対象HTML
     * @param int    $from 開始タグの '>' の次の位置
     * @param string $tag  タグ名（小文字）
     * @return int|false
     */
    private function find_matching_close(string $html, int $from, string $tag) {
        $length = strlen($html);
        $pos    = $from;
        $depth  = 1;

        while ($pos < $length) {
            $lt = strpos($html, '<', $pos);
            if ($lt === false) {
                return false;
            }

            if (substr($html, $lt, 4) === '<!--') {
                $end = $this->comment_end($html, $lt);
                if ($end === false) {
                    return false;
                }
                $pos = $end;
                continue;
            }

            // CDATA セクション <![CDATA[ ... ]]>（SVG 等）
            if (substr($html, $lt, 9) === '<![CDATA[') {
                $end = strpos($html, ']]>', $lt + 9);
                if ($end === false) {
                    return false;
                }
                $pos = $end + 3;
                continue;
            }

            $next = ($lt + 1 < $length) ? $html[$lt + 1] : '';

            if ($next === '!') {
                $end = strpos($html, '>', $lt + 1);
                if ($end === false) {
                    return false;
                }
                $pos = $end + 1;
                continue;
            }

            if ($next === '/') {
                $name = strtolower($this->read_name($html, $lt + 2));
                $end  = strpos($html, '>', $lt);
                if ($end === false) {
                    return false;
                }
                if ($name === $tag) {
                    $depth--;
                    if ($depth === 0) {
                        return $end + 1;
                    }
                }
                $pos = $end + 1;
                continue;
            }

            if (($next >= 'a' && $next <= 'z') || ($next >= 'A' && $next <= 'Z')) {
                $parsed = $this->parse_tag($html, $lt);
                if ($parsed['end'] === false) {
                    return false;
                }
                $name    = strtolower($this->read_name($html, $lt + 1));
                $tag_end = $parsed['end'];

                if ($name === $tag) {
                    $depth++;
                    $pos = $tag_end;
                    continue;
                }
                if ($name === 'script') {
                    $close = $this->find_script_close($html, $tag_end);
                    if ($close === false) {
                        return false;
                    }
                    $pos = $close;
                    continue;
                }
                if ($name === 'style' || $name === 'textarea' || $name === 'title'
                    || in_array($name, self::RAWTEXT_ELEMENTS, true)
                ) {
                    $close = $this->find_raw_close($html, $tag_end, $name);
                    if ($close === false) {
                        return false;
                    }
                    $pos = $close;
                    continue;
                }
                if (in_array($name, self::PROTECTED_ELEMENTS, true)) {
                    $close = $this->find_matching_close($html, $tag_end, $name);
                    if ($close === false) {
                        return false;
                    }
                    $pos = $close;
                    continue;
                }
                $pos = $tag_end;
                continue;
            }

            $pos = $lt + 1;
        }

        return false;
    }

    /**
     * 解析済みの属性から、空白保持の指定（white-space: pre 系）を持つか判定する
     *
     * 属性を正しく解析した結果を使うため、他の属性値の中に紛れた
     * 偽の 'style=' を拾わない。インライン style のみを対象とする。
     *
     * @param array<string,string> $attrs 属性名 => 値
     * @return bool
     */
    private function attrs_whitespace_preserving(array $attrs): bool {
        if (!isset($attrs['style']) || $attrs['style'] === '') {
            return false;
        }
        // 属性値の実体参照（&#112; など）を復号してから判定する
        $style = html_entity_decode($attrs['style'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_match(self::WHITESPACE_PRESERVE_PATTERN, $style) === 1;
    }
}
