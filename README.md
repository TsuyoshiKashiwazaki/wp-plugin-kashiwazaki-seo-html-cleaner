# Kashiwazaki SEO HTML Cleaner

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)
![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)

公開ページのHTMLから、不要なHTMLコメントと空行を除去するWordPressプラグインです。
表示や動作に必要な空白・コメント、および `pre`・`textarea`・`script`・`style` 内の内容は保持します。

- Plugin URI: https://www.tsuyoshikashiwazaki.jp
- Author: 柏崎剛 (Tsuyoshi Kashiwazaki) — https://www.tsuyoshikashiwazaki.jp/profile/
- Version: 1.0.0
- License: GPL v2 or later

## 機能

| 機能 | 説明 |
| --- | --- |
| コメント除去 | 不要なHTMLコメント（`<!-- ... -->`）を除去します。条件付きコメントは保持します。 |
| 空行除去 | 空白のみの行が連続する空行をまとめます。行内インデントやタグ間の改行は保持します。 |
| 内容の保護 | `pre`・`textarea`・`script`・`style` の内容は変更しません。 |
| 除外URL | クリーニングを適用しないURLを指定できます。 |

## 設定

管理画面の左メニュー「Kashiwazaki SEO HTML Cleaner」（位置 81）から設定します。

- コメント除去 ON / OFF
- 空行除去 ON / OFF
- 除外URL（1行に1つ）

### 除外URLの指定方法

- 完全一致: `/contact/`
- 前方一致（末尾を `/`）: `/blog/` は `/blog/` 配下すべて
- ワイルドカード: `/news/*`（`*` は任意の文字列に一致）
- 完全なURLを指定した場合はパス部分のみで判定

## 動作対象

公開ページ（フロントエンド）の表示時のみ動作します。管理画面・REST API・フィード・サイトマップ・robots.txt・AJAX は対象外です。

## ディレクトリ構成

```
wp-plugin-kashiwazaki-seo-html-cleaner/
├── kashiwazaki-seo-html-cleaner.php   # メインファイル
├── includes/
│   ├── class-settings.php             # 設定管理
│   ├── class-engine.php               # HTMLクリーニングエンジン
│   ├── class-frontend.php             # フロントエンド（出力バッファリング）
│   └── class-admin.php                # 管理画面
├── assets/css/admin.css               # 管理画面スタイル
├── languages/                         # 翻訳
├── readme.txt
└── uninstall.php
```

## インストール

1. `wp-plugin-kashiwazaki-seo-html-cleaner` を `wp-content/plugins/` に配置します。
2. プラグインを有効化します。
3. 左メニューから設定を行います。
