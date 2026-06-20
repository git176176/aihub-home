<?php
/**
 * 模块4：横版海报组（banner slots）
 * option: aihub_banner_slots = [{ id, slug, name, per_row(2|3), rotate(秒), enabled, items:[{id,image,url,title}] }]
 * 短代码: [aihub_banner slot=slug] → 占位，前端按 slot AJAX 拉取（绕过页面缓存）+ 分页轮播
 */

if (!defined('ABSPATH')) exit;

/** 取所有海报位 */
function aihub_get_banner_slots() {
    $slots = get_option('aihub_banner_slots', []);
    return is_array($slots) ? $slots : [];
}

/** 按 slug 取海报位 */
function aihub_get_banner_slot($slug) {
    foreach (aihub_get_banner_slots() as $slot) {
        if (($slot['slug'] ?? '') === $slug) return $slot;
    }
    return null;
}

/** 渲染海报位 HTML（平铺全部 items，前端 JS 按 per_row 分页轮播） */
function aihub_render_banner($slug) {
    $slot = aihub_get_banner_slot($slug);
    if (!$slot || empty($slot['enabled'])) return '';
    $items = array_filter($slot['items'] ?? [], function ($it) { return !empty($it['image']); });
    if (empty($items)) return '';

    $per    = in_array((int)($slot['per_row'] ?? 2), [2, 3], true) ? (int)$slot['per_row'] : 2;
    $rotate = max(0, (int)($slot['rotate'] ?? 5));

    $h = '<div class="aihub-banner" data-per-row="' . $per . '" data-rotate="' . $rotate . '" style="--aihub-cols:' . $per . '">';
    foreach ($items as $it) {
        $url = esc_url($it['url'] ?? '#');
        $img = esc_url($it['image']);
        $alt = esc_attr($it['title'] ?? '');
        $tid = !empty($it['id']) ? ' data-track-id="' . esc_attr($it['id']) . '"' : '';
        $h .= '<a class="aihub-banner-item" href="' . $url . '"' . $tid . ' target="_blank" rel="noopener nofollow">'
            . '<img src="' . $img . '" alt="' . $alt . '" loading="lazy"></a>';
    }
    $h .= '</div>';
    return $h;
}

/** 短代码 [aihub_banner slot=xxx] → 占位 */
function aihub_banner_shortcode($atts) {
    $a = shortcode_atts(['slot' => ''], $atts);
    $slug = sanitize_title($a['slot']);
    if ($slug === '') return '';
    return '<div class="aihub-banner-mount" data-slot="' . esc_attr($slug) . '"></div>';
}

/** REST 回调：按 slot 返回渲染 HTML */
function aihub_rest_banner($req) {
    nocache_headers();
    $slug = sanitize_title((string)$req->get_param('slot'));
    return ['html' => $slug ? aihub_render_banner($slug) : ''];
}

/** 后台 CRUD（admin_init 调用） */
function aihub_handle_banners_form() {
    if (!isset($_POST['aihub_banners_nonce']) || !wp_verify_nonce($_POST['aihub_banners_nonce'], 'aihub_banners')) return;
    if (!current_user_can('manage_options')) return;

    $slots = aihub_get_banner_slots();

    // 新增海报位
    if (isset($_POST['slot_add'])) {
        $slug = sanitize_title($_POST['slug'] ?? '');
        if ($slug === '') $slug = 'slot-' . substr(uniqid(), -5);
        // slug 去重
        foreach ($slots as $s) { if (($s['slug'] ?? '') === $slug) { $slug .= '-' . substr(uniqid(), -3); break; } }
        $slots[] = [
            'id'      => uniqid('slot_'),
            'slug'    => $slug,
            'name'    => sanitize_text_field($_POST['name'] ?? $slug),
            'per_row' => in_array((int)($_POST['per_row'] ?? 2), [2, 3], true) ? (int)$_POST['per_row'] : 2,
            'rotate'  => max(0, (int)($_POST['rotate'] ?? 5)),
            'enabled' => true,
            'items'   => [],
        ];
        update_option('aihub_banner_slots', $slots);
        wp_safe_redirect(admin_url('admin.php?page=aihub&tab=banners&ok=added')); exit;
    }

    // 更新海报位设置
    if (isset($_POST['slot_update'])) {
        $id = sanitize_text_field($_POST['id'] ?? '');
        foreach ($slots as &$s) {
            if (($s['id'] ?? '') === $id) {
                $s['name']    = sanitize_text_field($_POST['name'] ?? $s['name']);
                $s['per_row'] = in_array((int)($_POST['per_row'] ?? 2), [2, 3], true) ? (int)$_POST['per_row'] : 2;
                $s['rotate']  = max(0, (int)($_POST['rotate'] ?? 5));
            }
        }
        unset($s);
        update_option('aihub_banner_slots', $slots);
        wp_safe_redirect(admin_url('admin.php?page=aihub&tab=banners&ok=updated')); exit;
    }

    // 启用/禁用
    if (isset($_POST['slot_toggle'])) {
        $id = sanitize_text_field($_POST['id'] ?? '');
        foreach ($slots as &$s) { if (($s['id'] ?? '') === $id) $s['enabled'] = empty($s['enabled']); }
        unset($s);
        update_option('aihub_banner_slots', $slots);
        wp_safe_redirect(admin_url('admin.php?page=aihub&tab=banners&ok=toggled')); exit;
    }

    // 删除海报位
    if (isset($_POST['slot_delete'])) {
        $id = sanitize_text_field($_POST['id'] ?? '');
        $slots = array_values(array_filter($slots, function ($s) use ($id) { return ($s['id'] ?? '') !== $id; }));
        update_option('aihub_banner_slots', $slots);
        wp_safe_redirect(admin_url('admin.php?page=aihub&tab=banners&ok=deleted')); exit;
    }

    // 给海报位添加海报
    if (isset($_POST['item_add'])) {
        $sid = sanitize_text_field($_POST['slot_id'] ?? '');
        foreach ($slots as &$s) {
            if (($s['id'] ?? '') === $sid) {
                if (!isset($s['items']) || !is_array($s['items'])) $s['items'] = [];
                $s['items'][] = [
                    'id'    => uniqid('bi_'),
                    'image' => esc_url_raw($_POST['image'] ?? ''),
                    'url'   => esc_url_raw($_POST['url'] ?? ''),
                    'title' => sanitize_text_field($_POST['title'] ?? ''),
                ];
            }
        }
        unset($s);
        update_option('aihub_banner_slots', $slots);
        wp_safe_redirect(admin_url('admin.php?page=aihub&tab=banners&ok=added')); exit;
    }

    // 删除海报
    if (isset($_POST['item_delete'])) {
        $sid = sanitize_text_field($_POST['slot_id'] ?? '');
        $iid = sanitize_text_field($_POST['item_id'] ?? '');
        foreach ($slots as &$s) {
            if (($s['id'] ?? '') === $sid && !empty($s['items'])) {
                $s['items'] = array_values(array_filter($s['items'], function ($it) use ($iid) { return ($it['id'] ?? '') !== $iid; }));
            }
        }
        unset($s);
        update_option('aihub_banner_slots', $slots);
        wp_safe_redirect(admin_url('admin.php?page=aihub&tab=banners&ok=deleted')); exit;
    }
}
