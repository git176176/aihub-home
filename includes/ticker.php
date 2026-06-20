<?php
/**
 * 模块2：快讯跑马灯（横向连续滚动）
 * 数据 = NewsFlash 快讯（CPT newsflash）+ 广告库（placement=ticker/both）混排
 * 短代码: [aihub_ticker]（首页）；文章页由 aihub.js 注入 .crumbs 前
 */

if (!defined('ABSPATH')) exit;

/** 聚合跑马灯条目：[{text, url, type:news|ad}] */
function aihub_get_ticker_items() {
    $s = get_option('aihub_ticker_settings', []);
    $news_count = max(1, intval($s['news_count'] ?? 10));
    $ad_every   = max(0, intval($s['ad_every'] ?? 4));

    // 快讯（NewsFlash 未启用则降级为空）
    $news = [];
    if (post_type_exists('newsflash')) {
        $posts = get_posts([
            'post_type'      => 'newsflash',
            'posts_per_page' => $news_count,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ]);
        foreach ($posts as $p) {
            $news[] = ['text' => get_the_title($p), 'url' => get_permalink($p), 'type' => 'news'];
        }
    }

    $ads = [];
    foreach (aihub_get_ads_for('ticker') as $ad) {
        $ads[] = ['text' => $ad['title'] ?? '', 'desc' => $ad['desc'] ?? '', 'url' => $ad['url'] ?? '#', 'type' => 'ad', 'id' => $ad['id'] ?? ''];
    }

    // 混排：每 ad_every 条快讯后插一条广告（轮流取广告）
    if (empty($ads)) return $news;
    if (empty($news)) return $ads;
    if ($ad_every <= 0) {
        // 不混排，广告全部追加到末尾
        return array_merge($news, $ads);
    }
    $items = [];
    $ad_i = 0; $count = count($ads);
    foreach ($news as $i => $item) {
        $items[] = $item;
        if (($i + 1) % $ad_every === 0) {
            $items[] = $ads[$ad_i % $count];
            $ad_i++;
        }
    }
    // 剩余没轮到的广告补在末尾
    while ($ad_i < $count) { $items[] = $ads[$ad_i]; $ad_i++; }
    return $items;
}

/** 渲染跑马灯 HTML（首页短代码 / 文章页注入 共用） */
function aihub_render_ticker() {
    $s = get_option('aihub_ticker_settings', []);
    if (empty($s['enabled'])) return '';
    $items = aihub_get_ticker_items();
    if (empty($items)) return '';

    $label    = esc_html($s['label'] ?? '📢 快讯');
    $news_url = esc_url($s['news_url'] ?? '/newsflash/');
    $speed    = max(10, intval($s['speed'] ?? 40));
    $blank    = !empty($s['link_blank']);  // 快讯链接是否新标签页（广告始终新标签页）

    // 给每条分配一个深色字体（按索引循环调色板，保证复制的两份一致）
    $palette = ['#1e293b', '#7c2d12', '#14532d', '#1e3a8a', '#581c87', '#831843', '#134e4a', '#713f12', '#3730a3', '#9f1239', '#0f766e', '#92400e'];
    foreach ($items as $i => $it) {
        $items[$i]['color'] = $palette[$i % count($palette)];
    }

    // 一组条目 HTML（广告与快讯外观一致，不加「广告」角标）
    $build = function () use ($items, $blank) {
        $h = '';
        foreach ($items as $it) {
            $text = esc_html($it['text'] ?? '');
            if ($text === '') continue;
            $url   = esc_url($it['url'] ?? '#');
            $is_ad = ($it['type'] ?? '') === 'ad';
            $color = $it['color'] ?? '#1e293b';
            $desc  = !empty($it['desc']) ? '<span class="aihub-ticker-desc">' . esc_html($it['desc']) . '</span>' : '';
            if ($is_ad) {
                $target = ' target="_blank" rel="noopener nofollow"';
            } else {
                $target = $blank ? ' target="_blank" rel="noopener"' : '';
            }
            $track_attr = ($is_ad && !empty($it['id'])) ? ' data-track-id="' . esc_attr($it['id']) . '"' : '';
            $h .= '<a class="aihub-ticker-item" href="' . $url . '"' . $target . $track_attr . '><span class="aihub-ticker-text" style="color:' . $color . '">' . $text . '</span>' . $desc . '</a>';
        }
        return $h;
    };

    // 复制两组实现无缝循环
    $track = $build() . $build();

    $html  = '<div class="aihub-ticker" style="--aihub-ticker-speed:' . $speed . 's">';
    $html .= '<a class="aihub-ticker-label" href="' . $news_url . '">' . $label . '</a>';
    $html .= '<div class="aihub-ticker-viewport"><div class="aihub-ticker-track">' . $track . '</div></div>';
    $html .= '</div>';
    return $html;
}

/** 跑马灯占位 —— 实际内容由前端 AJAX 拉取填充（绕过页面缓存） */
function aihub_ticker_placeholder() {
    return '<div class="aihub-ticker-mount" data-aihub-ticker></div>';
}

/** 短代码 [aihub_ticker]（首页用）→ 输出占位，前端实时填充 */
function aihub_ticker_shortcode($atts) {
    $s = get_option('aihub_ticker_settings', []);
    if (empty($s['enabled']) || empty($s['show_home'])) return '';
    return aihub_ticker_placeholder();
}

/** REST 回调：返回实时渲染的跑马灯 HTML（不走页面缓存） */
function aihub_rest_ticker() {
    nocache_headers();
    return ['html' => aihub_render_ticker()];
}
