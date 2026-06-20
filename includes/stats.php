<?php
/**
 * 模块5：广告统计（展示 + 点击）—— 自建数据表，原子递增，无并发丢失
 * 表 {prefix}aihub_stats: track_id(PK varchar) / imp(bigint) / clk(bigint)
 * 前端通过 REST /aihub/v1/track 上报（绕过页面缓存）。
 */

if (!defined('ABSPATH')) exit;

function aihub_stats_table() {
    global $wpdb;
    return $wpdb->prefix . 'aihub_stats';
}

/** 建表（dbDelta 幂等）+ 迁移 v1.1.0 的 aihub_stats option 旧数据 */
function aihub_create_stats_table() {
    global $wpdb;
    $table = aihub_stats_table();
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $sql = "CREATE TABLE $table (
        track_id varchar(64) NOT NULL,
        imp bigint(20) unsigned NOT NULL DEFAULT 0,
        clk bigint(20) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (track_id)
    ) $charset;";
    dbDelta($sql);

    // 迁移旧 option（如有），迁移后删除
    $old = get_option('aihub_stats', null);
    if (is_array($old) && !empty($old)) {
        foreach ($old as $id => $s) {
            $id = substr((string)$id, 0, 64);
            if ($id === '') continue;
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (track_id, imp, clk) VALUES (%s, %d, %d)
                 ON DUPLICATE KEY UPDATE imp = imp + VALUES(imp), clk = clk + VALUES(clk)",
                $id, (int)($s['imp'] ?? 0), (int)($s['clk'] ?? 0)
            ));
        }
        delete_option('aihub_stats');
    }
}

/** REST 回调：原子递增展示/点击（ON DUPLICATE KEY UPDATE 行级原子，并发安全） */
function aihub_rest_track($req) {
    nocache_headers();
    global $wpdb;
    $table = aihub_stats_table();

    // 展示：批量 id（逗号分隔或数组），单次最多 50 个防滥用
    $imp = $req->get_param('imp');
    if (!empty($imp)) {
        if (is_string($imp)) $imp = explode(',', $imp);
        if (is_array($imp)) {
            foreach (array_slice($imp, 0, 50) as $id) {
                $id = substr(sanitize_text_field((string)$id), 0, 64);
                if ($id === '') continue;
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO $table (track_id, imp, clk) VALUES (%s, 1, 0)
                     ON DUPLICATE KEY UPDATE imp = imp + 1", $id
                ));
            }
        }
    }

    // 点击：单个 id
    $clk = $req->get_param('clk');
    if (!empty($clk)) {
        $id = substr(sanitize_text_field((string)$clk), 0, 64);
        if ($id !== '') {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (track_id, imp, clk) VALUES (%s, 0, 1)
                 ON DUPLICATE KEY UPDATE clk = clk + 1", $id
            ));
        }
    }

    return ['ok' => true];
}

/** 读全部统计：track_id => {imp,clk} */
function aihub_get_all_stats() {
    global $wpdb;
    $table = aihub_stats_table();
    $rows = $wpdb->get_results("SELECT track_id, imp, clk FROM $table", ARRAY_A);
    $out = [];
    if ($rows) {
        foreach ($rows as $r) {
            $out[$r['track_id']] = ['imp' => (int)$r['imp'], 'clk' => (int)$r['clk']];
        }
    }
    return $out;
}

/** 清空统计 */
function aihub_reset_stats() {
    global $wpdb;
    $wpdb->query('TRUNCATE TABLE ' . aihub_stats_table());
}

/**
 * 「名称 + 类型」映射表：trackId => [name, type]，后台统计展示用。
 */
function aihub_stats_entities() {
    $map = [];
    // 首页卡片
    if (function_exists('aihub_get_cards')) {
        foreach (aihub_get_cards(false) as $c) {
            $id = $c['id'] ?? '';
            if ($id === '') continue;
            $map[$id] = ['name' => ($c['title'] ?: '(卡片)'), 'type' => '首页卡片'];
        }
    }
    // 广告库（跑马灯 / 侧边栏海报）
    foreach (aihub_get_ads() as $ad) {
        $id = $ad['id'] ?? '';
        if ($id === '') continue;
        $pl = $ad['placement'] ?? 'both';
        $type = ($pl === 'sidebar') ? '侧边栏海报' : (($pl === 'ticker') ? '跑马灯' : '跑马灯+侧边栏');
        $map[$id] = ['name' => $ad['title'] ?? '(广告)', 'type' => $type];
    }
    if (function_exists('aihub_get_banner_slots')) {
        foreach (aihub_get_banner_slots() as $slot) {
            $sname = $slot['name'] ?? ($slot['slug'] ?? '');
            foreach (($slot['items'] ?? []) as $it) {
                $id = $it['id'] ?? '';
                if ($id === '') continue;
                $map[$id] = ['name' => ($it['title'] ?: '(海报)') . ' · ' . $sname, 'type' => '横版海报'];
            }
        }
    }
    return $map;
}
