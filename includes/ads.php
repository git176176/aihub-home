<?php
/**
 * 广告库 —— 跑马灯与侧边栏共用
 * option: aihub_ads = [{id, title, url, icon, desc, enabled, placement, order}]
 *   placement ∈ ticker | sidebar | both
 */

if (!defined('ABSPATH')) exit;

/** 取所有广告（含禁用），按 order 升序 */
function aihub_get_ads() {
    $ads = get_option('aihub_ads', []);
    if (!is_array($ads)) $ads = [];
    usort($ads, function ($a, $b) {
        return intval($a['order'] ?? 0) - intval($b['order'] ?? 0);
    });
    return $ads;
}

/** 取投放到指定位置的启用广告 */
function aihub_get_ads_for($placement) {
    $out = [];
    foreach (aihub_get_ads() as $ad) {
        if (empty($ad['enabled'])) continue;
        $p = $ad['placement'] ?? 'both';
        if ($p === $placement || $p === 'both') $out[] = $ad;
    }
    return $out;
}

/** 处理广告 CRUD 表单（admin_init 时调用） */
function aihub_handle_ads_form() {
    if (!isset($_POST['aihub_ads_nonce']) || !wp_verify_nonce($_POST['aihub_ads_nonce'], 'aihub_ads')) return;
    if (!current_user_can('manage_options')) return;

    $ads = get_option('aihub_ads', []);
    if (!is_array($ads)) $ads = [];

    // 添加
    if (isset($_POST['ad_add'])) {
        $max = 0;
        foreach ($ads as $a) { $max = max($max, intval($a['order'] ?? 0)); }
        $ads[] = [
            'id'        => uniqid('ad_'),
            'title'     => sanitize_text_field($_POST['title'] ?? ''),
            'url'       => esc_url_raw($_POST['url'] ?? ''),
            'icon'      => esc_url_raw($_POST['icon'] ?? ''),
            'poster'    => esc_url_raw($_POST['poster'] ?? ''),
            'desc'      => sanitize_text_field($_POST['desc'] ?? ''),
            'placement' => aihub_sanitize_placement($_POST['placement'] ?? 'both'),
            'enabled'   => true,
            'order'     => $max + 10,
        ];
        update_option('aihub_ads', $ads);
        wp_safe_redirect(admin_url('admin.php?page=aihub&tab=ads&ok=added')); exit;
    }

    // 更新
    if (isset($_POST['ad_update'])) {
        $id = sanitize_text_field($_POST['id'] ?? '');
        foreach ($ads as &$a) {
            if (($a['id'] ?? '') === $id) {
                $a['title']     = sanitize_text_field($_POST['title'] ?? $a['title']);
                $a['url']       = esc_url_raw($_POST['url'] ?? $a['url']);
                $a['icon']      = esc_url_raw($_POST['icon'] ?? ($a['icon'] ?? ''));
                $a['poster']    = esc_url_raw($_POST['poster'] ?? ($a['poster'] ?? ''));
                $a['desc']      = sanitize_text_field($_POST['desc'] ?? ($a['desc'] ?? ''));
                $a['placement'] = aihub_sanitize_placement($_POST['placement'] ?? ($a['placement'] ?? 'both'));
                $a['order']     = intval($_POST['order'] ?? ($a['order'] ?? 0));
            }
        }
        unset($a);
        update_option('aihub_ads', $ads);
        wp_safe_redirect(admin_url('admin.php?page=aihub&tab=ads&ok=updated')); exit;
    }

    // 启用/禁用切换
    if (isset($_POST['ad_toggle'])) {
        $id = sanitize_text_field($_POST['id'] ?? '');
        foreach ($ads as &$a) {
            if (($a['id'] ?? '') === $id) $a['enabled'] = empty($a['enabled']);
        }
        unset($a);
        update_option('aihub_ads', $ads);
        wp_safe_redirect(admin_url('admin.php?page=aihub&tab=ads&ok=toggled')); exit;
    }

    // 删除
    if (isset($_POST['ad_delete'])) {
        $id = sanitize_text_field($_POST['id'] ?? '');
        $ads = array_values(array_filter($ads, function ($a) use ($id) {
            return ($a['id'] ?? '') !== $id;
        }));
        update_option('aihub_ads', $ads);
        wp_safe_redirect(admin_url('admin.php?page=aihub&tab=ads&ok=deleted')); exit;
    }
}

function aihub_sanitize_placement($p) {
    $p = sanitize_text_field($p);
    return in_array($p, ['ticker', 'sidebar', 'both'], true) ? $p : 'both';
}
