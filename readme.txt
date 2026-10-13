=== Kashiwazaki SEO HTML Cleaner ===
Contributors: tsuyoshikashiwazaki
Tags: html, minify, comments, performance, seo
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

公開ページのHTMLから、不要なHTMLコメントと空行を除去します。表示や動作に必要な空白・コメントと、pre・textarea・script・style内の内容は保持します。

== Description ==

Kashiwazaki SEO HTML Cleaner は、公開ページのHTML出力から不要なHTMLコメントと空行を除去する軽量なプラグインです。

* **HTMLコメントの除去** … ソースに残った不要なコメント（`<!-- ... -->`）を除去します。条件付きコメント（`<!--[if ...]> ... <![endif]-->`）は動作に必要な場合があるため保持します。
* **空行の除去** … 空白のみの行が連続する「空行」をまとめます。行内のインデントやタグ間の区切りとしての改行は保持します。
* **内容の保護** … `pre`・`textarea`・`script`・`style` の内容は一切変更しません。コード表示やスクリプト、スタイルはそのまま維持されます。
* **除外URL** … クリーニングを適用しないURLを指定できます。

= 設定 =

* コメント除去の ON / OFF
* 空行除去の ON / OFF
* 除外URL（1行に1つ）

除外URLの指定方法：

* 完全一致 … 例: `/contact/`
* 前方一致 … 末尾を「/」にすると配下すべてが対象になります。例: `/blog/`
* ワイルドカード … `*` は任意の文字列に一致します。例: `/news/*`
* 完全なURLで指定した場合は、パス部分のみで判定します。

= 動作 =

クリーニングは公開ページ（フロントエンド）の表示時のみ行われます。管理画面、REST API、フィード、サイトマップ、robots.txt、AJAX は対象外です。

== Installation ==

1. プラグインのフォルダ `wp-plugin-kashiwazaki-seo-html-cleaner` を `wp-content/plugins/` に配置します。
2. 管理画面の「プラグイン」から「Kashiwazaki SEO HTML Cleaner」を有効化します。
3. 左メニューの「Kashiwazaki SEO HTML Cleaner」から設定を行います。

== Frequently Asked Questions ==

= 表示崩れは起きませんか？ =

HTMLの表示に影響する空白は保持し、`pre`・`textarea`・`script`・`style` の内容も変更しません。ただし、設定変更後は必ず「HTMLクリーニング テスト」や実際のページで表示を確認してください。

= 条件付きコメントも消えますか？ =

いいえ。`<!--[if ...]>` で始まるコメント、および `<![endif]` を含むコメントは保持します。

== Changelog ==

= 1.0.0 =
* 初回リリース。
