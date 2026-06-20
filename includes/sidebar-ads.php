<?php
/**
 * 模块3：文章侧边栏广告（纯海报形式）
 * 数据 = 广告库（placement=sidebar/both 且填了 poster 海报图）
 * 由 aihub.js 注入 .sidebar.sidebar-tools。无卡片框/背景/标题/角标，只显示可点击的海报图。
 */

if (!defined('ABSPATH')) exit;

/** 渲染侧边栏海报广告 HTML（供 JS 注入） */
function aihub_render_sidebar_ads() {
    $s = get_option('aihub_sidebar_settings', []);
    if (empty($s['enabled'])) return '';

    // 仅保留投放到侧边栏、且填了海报图的广告
    $ads = array_filter(aihub_get_ads_for('sidebar'), function ($a) {
        return !empty($a['poster']);
    });
    if (empty($ads)) return '';

    $out = '';
    foreach ($ads as $ad) {
        $url = esc_url($ad['url'] ?? '#');
        $img = esc_url($ad['poster']);
        $alt = esc_attr($ad['title'] ?? '');
        $tid = !empty($ad['id']) ? ' data-track-id="' . esc_attr($ad['id']) . '"' : '';
        $out .= '<a class="aihub-poster" href="' . $url . '"' . $tid . ' target="_blank" rel="noopener nofollow">'
              . '<img src="' . $img . '" alt="' . $alt . '" loading="lazy"></a>';
    }
    return '<div class="aihub-poster-list">' . $out . '</div>';
}
