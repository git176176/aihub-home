<?php
/**
 * 模块1：首页工具卡片
 * option: aihub_cards = [{id, title, desc, url, badge, category, enabled, order}]
 * 短代码: [aihub_cards]
 */

if (!defined('ABSPATH')) exit;

/** 取卡片，按 order 升序 */
function aihub_get_cards($only_enabled = false) {
    $cards = get_option('aihub_cards', []);
    if (!is_array($cards)) $cards = [];
    if ($only_enabled) {
        $cards = array_filter($cards, function ($c) { return !empty($c['enabled']); });
    }
    usort($cards, function ($a, $b) {
        return intval($a['order'] ?? 0) - intval($b['order'] ?? 0);
    });
    return array_values($cards);
}

/** 实际渲染卡片 HTML：复刻原 .novatools-cards 样式（class 加 aihub- 前缀） */
function aihub_render_cards() {
    $settings = get_option('aihub_cards_settings', []);
    if (empty($settings['enabled'])) return '';
    $cards = aihub_get_cards(true);
    if (empty($cards)) return '';

    $html = '<div class="aihub-cards">';
    foreach ($cards as $c) {
        $title = esc_html($c['title'] ?? '');
        $desc  = esc_html($c['desc'] ?? '');
        $url   = esc_url($c['url'] ?? '#');
        $badge = esc_html($c['badge'] ?? '');
        $cat   = esc_html($c['category'] ?? '');
        $tid   = !empty($c['id']) ? ' data-track-id="' . esc_attr($c['id']) . '"' : '';
        $html .= '<a class="aihub-card" href="' . $url . '"' . $tid . ' target="_blank" rel="noopener">';
        $html .= '<span class="aihub-pin-shadow"></span><span class="aihub-fold-corner"></span>';
        if ($badge !== '') $html .= '<span class="aihub-card-badge">' . $badge . '</span>';
        $html .= '<h3 class="aihub-card-title">' . $title . '</h3>';
        if ($desc !== '') $html .= '<p class="aihub-card-desc">' . $desc . '</p>';
        $html .= '<div class="aihub-card-meta"><span>' . $cat . '</span><span class="aihub-card-link">体验</span></div>';
        $html .= '</a>';
    }
    $html .= '</div>';
    return $html;
}

/** 短代码 [aihub_cards] → 占位，前端 AJAX 填充（任意页面可用，绕过页面缓存，不在每页 JSON 塞卡片 HTML） */
function aihub_cards_shortcode($atts) {
    $settings = get_option('aihub_cards_settings', []);
    if (empty($settings['enabled'])) return '';
    return '<div class="aihub-cards-mount" data-aihub-cards></div>';
}

/** REST 回调：返回实时渲染的卡片 HTML */
function aihub_rest_cards() {
    nocache_headers();
    return ['html' => aihub_render_cards()];
}

/** 处理卡片 CRUD + JSON 导入（admin_init 时调用） */
function aihub_handle_cards_form() {
    if (!isset($_POST['aihub_cards_nonce']) || !wp_verify_nonce($_POST['aihub_cards_nonce'], 'aihub_cards')) return;
    if (!current_user_can('manage_options')) return;

    $cards = get_option('aihub_cards', []);
    if (!is_array($cards)) $cards = [];

    // 添加
    if (isset($_POST['card_add'])) {
        $max = 0; foreach ($cards as $c) { $max = max($max, intval($c['order'] ?? 0)); }
        $cards[] = [
            'id'       => uniqid('card_'),
            'title'    => sanitize_text_field($_POST['title'] ?? ''),
            'desc'     => sanitize_textarea_field($_POST['desc'] ?? ''),
            'url'      => esc_url_raw($_POST['url'] ?? ''),
            'badge'    => sanitize_text_field($_POST['badge'] ?? ''),
            'category' => sanitize_text_field($_POST['category'] ?? ''),
            'enabled'  => true,
            'order'    => $max + 10,
        ];
        update_option('aihub_cards', $cards);
        wp_safe_redirect(admin_url('admin.php?page=aihub&tab=cards&ok=added')); exit;
    }

    // 更新
    if (isset($_POST['card_update'])) {
        $id = sanitize_text_field($_POST['id'] ?? '');
        foreach ($cards as &$c) {
            if (($c['id'] ?? '') === $id) {
                $c['title']    = sanitize_text_field($_POST['title'] ?? $c['title']);
                $c['desc']     = sanitize_textarea_field($_POST['desc'] ?? ($c['desc'] ?? ''));
                $c['url']      = esc_url_raw($_POST['url'] ?? $c['url']);
                $c['badge']    = sanitize_text_field($_POST['badge'] ?? ($c['badge'] ?? ''));
                $c['category'] = sanitize_text_field($_POST['category'] ?? ($c['category'] ?? ''));
                $c['order']    = intval($_POST['order'] ?? ($c['order'] ?? 0));
            }
        }
        unset($c);
        update_option('aihub_cards', $cards);
        wp_safe_redirect(admin_url('admin.php?page=aihub&tab=cards&ok=updated')); exit;
    }

    // 切换启用
    if (isset($_POST['card_toggle'])) {
        $id = sanitize_text_field($_POST['id'] ?? '');
        foreach ($cards as &$c) {
            if (($c['id'] ?? '') === $id) $c['enabled'] = empty($c['enabled']);
        }
        unset($c);
        update_option('aihub_cards', $cards);
        wp_safe_redirect(admin_url('admin.php?page=aihub&tab=cards&ok=toggled')); exit;
    }

    // 删除
    if (isset($_POST['card_delete'])) {
        $id = sanitize_text_field($_POST['id'] ?? '');
        $cards = array_values(array_filter($cards, function ($c) use ($id) {
            return ($c['id'] ?? '') !== $id;
        }));
        update_option('aihub_cards', $cards);
        wp_safe_redirect(admin_url('admin.php?page=aihub&tab=cards&ok=deleted')); exit;
    }

    // 从 JSON 导入（兼容现有 card-data.json 结构：{cards:[{title,description,url,badge,category}]}）
    if (isset($_POST['card_import'])) {
        $raw = wp_unslash($_POST['json'] ?? '');
        $data = json_decode($raw, true);
        $list = [];
        if (is_array($data) && isset($data['cards']) && is_array($data['cards'])) {
            $list = $data['cards'];
        } elseif (is_array($data)) {
            $list = $data; // 容错：直接是数组
        }
        if (empty($list)) {
            wp_safe_redirect(admin_url('admin.php?page=aihub&tab=cards&ok=import_fail')); exit;
        }
        $max = 0; foreach ($cards as $c) { $max = max($max, intval($c['order'] ?? 0)); }
        $n = 0;
        foreach ($list as $item) {
            if (!is_array($item)) continue;
            $max += 10; $n++;
            $cards[] = [
                'id'       => uniqid('card_'),
                'title'    => sanitize_text_field($item['title'] ?? ''),
                'desc'     => sanitize_textarea_field($item['description'] ?? ($item['desc'] ?? '')),
                'url'      => esc_url_raw($item['url'] ?? ''),
                'badge'    => sanitize_text_field($item['badge'] ?? ''),
                'category' => sanitize_text_field($item['category'] ?? ''),
                'enabled'  => true,
                'order'    => $max,
            ];
        }
        update_option('aihub_cards', $cards);
        wp_safe_redirect(admin_url('admin.php?page=aihub&tab=cards&ok=imported&n=' . $n)); exit;
    }
}
