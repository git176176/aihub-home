<?php
/**
 * 后台控制台 —— 单顶级菜单 AIHub，5 个 Tab：卡片 / 跑马灯 / 广告 / 侧边栏 / 设置
 */

if (!defined('ABSPATH')) exit;

final class AIHub_Admin {
    private static $instance = null;
    public static function instance() { if (is_null(self::$instance)) self::$instance = new self(); return self::$instance; }

    private function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_init', [$this, 'handle_forms']);
    }

    public function menu() {
        add_menu_page('AIHub', 'AIHub', 'manage_options', 'aihub', [$this, 'page'], 'dashicons-screenoptions', 58);
    }

    public function assets($hook) {
        if (!isset($_GET['page']) || sanitize_key($_GET['page']) !== 'aihub') return;
        wp_enqueue_style('aihub-admin', AIHUB_URL . 'assets/css/admin.css', [], AIHUB_VERSION);
        wp_enqueue_script('aihub-admin', AIHUB_URL . 'assets/js/admin.js', [], AIHUB_VERSION, true);
    }

    /** 各表单 handler 路由（各函数内部自校验 nonce + 权限，无 nonce 直接 return） */
    public function handle_forms() {
        aihub_handle_ads_form();
        aihub_handle_cards_form();
        aihub_handle_banners_form();
        $this->handle_settings();
        $this->handle_stats_reset();
    }

    private function handle_stats_reset() {
        if (!isset($_POST['aihub_stats_nonce']) || !wp_verify_nonce($_POST['aihub_stats_nonce'], 'aihub_stats')) return;
        if (!current_user_can('manage_options')) return;
        if (isset($_POST['reset_stats'])) {
            aihub_reset_stats();
            wp_safe_redirect(admin_url('admin.php?page=aihub&tab=stats&ok=saved')); exit;
        }
    }

    private function handle_settings() {
        if (!isset($_POST['aihub_set_nonce']) || !wp_verify_nonce($_POST['aihub_set_nonce'], 'aihub_set')) return;
        if (!current_user_can('manage_options')) return;

        if (isset($_POST['save_ticker'])) {
            update_option('aihub_ticker_settings', [
                'enabled'     => !empty($_POST['t_enabled']),
                'show_home'   => !empty($_POST['t_show_home']),
                'show_single' => !empty($_POST['t_show_single']),
                'news_count'  => max(1, (int)($_POST['t_news_count'] ?? 10)),
                'ad_every'    => max(0, (int)($_POST['t_ad_every'] ?? 4)),
                'speed'       => max(10, (int)($_POST['t_speed'] ?? 40)),
                'label'       => sanitize_text_field($_POST['t_label'] ?? '📢 快讯'),
                'news_url'    => esc_url_raw($_POST['t_news_url'] ?? '/newsflash/'),
                'link_blank'  => !empty($_POST['t_link_blank']),
            ]);
            wp_safe_redirect(admin_url('admin.php?page=aihub&tab=ticker&ok=saved')); exit;
        }
        if (isset($_POST['save_sidebar'])) {
            update_option('aihub_sidebar_settings', [
                'enabled'  => !empty($_POST['s_enabled']),
                'position' => in_array($_POST['s_position'] ?? 'top', ['top', 'bottom'], true) ? sanitize_key($_POST['s_position']) : 'top',
            ]);
            wp_safe_redirect(admin_url('admin.php?page=aihub&tab=sidebar&ok=saved')); exit;
        }
        if (isset($_POST['save_settings'])) {
            update_option('aihub_settings', [
                'enabled'          => !empty($_POST['g_enabled']),
                'crumbs_selector'  => sanitize_text_field($_POST['g_crumbs'] ?? '.crumbs'),
                'sidebar_selector' => sanitize_text_field($_POST['g_sidebar'] ?? '.sidebar.sidebar-tools'),
            ]);
            update_option('aihub_cards_settings', ['enabled' => !empty($_POST['g_cards_enabled'])]);
            wp_safe_redirect(admin_url('admin.php?page=aihub&tab=settings&ok=saved')); exit;
        }
    }

    public function page() {
        if (!current_user_can('manage_options')) return;
        $tabs = [
            'cards'   => '🃏 卡片',
            'banners' => '🖼️ 海报',
            'ticker'  => '📢 跑马灯',
            'ads'     => '📣 广告',
            'sidebar' => '📌 侧边栏',
            'stats'   => '📊 统计',
            'settings'=> '⚙️ 设置',
        ];
        $cur = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'cards';
        if (!isset($tabs[$cur])) $cur = 'cards';

        echo '<div class="wrap aihub-admin"><h1>AIHub 首页与广告位 <span class="aihub-ver">v' . esc_html(AIHUB_VERSION) . '</span></h1>';
        $this->notice();
        echo '<nav class="aihub-tabs">';
        foreach ($tabs as $k => $label) {
            $active = $k === $cur ? ' is-active' : '';
            echo '<a class="aihub-tab' . $active . '" href="' . esc_url(admin_url('admin.php?page=aihub&tab=' . $k)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        switch ($cur) {
            case 'cards':    $this->tab_cards();   break;
            case 'banners':  $this->tab_banners(); break;
            case 'ticker':   $this->tab_ticker();  break;
            case 'ads':      $this->tab_ads();      break;
            case 'sidebar':  $this->tab_sidebar(); break;
            case 'stats':    $this->tab_stats();   break;
            case 'settings': $this->tab_settings(); break;
        }
        echo '</div>';
    }

    private function notice() {
        if (empty($_GET['ok'])) return;
        $map = [
            'added' => '✅ 已添加', 'updated' => '✅ 已更新', 'deleted' => '✅ 已删除',
            'toggled' => '✅ 已切换', 'saved' => '✅ 已保存',
            'imported' => '✅ 已导入 ' . (isset($_GET['n']) ? (int)$_GET['n'] : '') . ' 张卡片',
            'import_fail' => '❌ JSON 解析失败，请检查格式',
        ];
        $k = sanitize_key($_GET['ok']);
        if (isset($map[$k])) {
            $cls = $k === 'import_fail' ? 'notice-error' : 'notice-success';
            echo '<div class="notice ' . $cls . ' is-dismissible"><p>' . esc_html($map[$k]) . '</p></div>';
        }
    }

    /* ---------------- Tab: 卡片 ---------------- */
    private function tab_cards() {
        $cards = aihub_get_cards(false);
        $action = esc_url(admin_url('admin.php?page=aihub&tab=cards'));

        // 列表
        echo '<div class="aihub-card-box"><div class="aihub-box-hd">🃏 首页卡片 <span class="aihub-meta">' . count($cards) . ' 张</span></div><div class="aihub-box-bd">';
        echo '<p class="aihub-tip">把短代码 <code>[aihub_cards]</code> 放到首页要显示卡片的位置（替换你原来的手写 HTML）。</p>';
        echo '<table class="aihub-tbl"><thead><tr><th style="width:50px">排序</th><th>标题</th><th>标签</th><th>来源</th><th>链接</th><th style="width:60px">状态</th><th style="width:150px">操作</th></tr></thead><tbody>';
        if (empty($cards)) echo '<tr><td colspan="7" class="aihub-empty">暂无卡片，下方添加，或在「设置」旁从 JSON 导入</td></tr>';
        foreach ($cards as $c) {
            $id = esc_attr($c['id'] ?? '');
            echo '<tr class="' . (empty($c['enabled']) ? 'is-off' : '') . '">';
            echo '<td>' . esc_html($c['order'] ?? 0) . '</td>';
            echo '<td><strong>' . esc_html($c['title'] ?? '') . '</strong><div class="aihub-sub">' . esc_html(wp_trim_words($c['desc'] ?? '', 16, '…')) . '</div></td>';
            echo '<td>' . esc_html($c['badge'] ?? '') . '</td>';
            echo '<td>' . esc_html($c['category'] ?? '') . '</td>';
            echo '<td><a href="' . esc_url($c['url'] ?? '#') . '" target="_blank" class="aihub-url">' . esc_html($c['url'] ?? '') . '</a></td>';
            echo '<td><span class="aihub-bdg ' . (!empty($c['enabled']) ? 'on' : 'off') . '">' . (!empty($c['enabled']) ? '启用' : '禁用') . '</span></td>';
            echo '<td>';
            echo '<a class="aihub-btn aihub-btn-sm" href="javascript:void(0)" onclick="aihubEdit(\'card-' . $id . '\')">编辑</a> ';
            echo '<form method="post" action="' . $action . '" style="display:inline">' . wp_nonce_field('aihub_cards', 'aihub_cards_nonce', true, false) . '<input type="hidden" name="id" value="' . $id . '"><button class="aihub-btn aihub-btn-sm" name="card_toggle">' . (!empty($c['enabled']) ? '禁用' : '启用') . '</button></form> ';
            echo '<form method="post" action="' . $action . '" style="display:inline" onsubmit="return confirm(\'删除？\')">' . wp_nonce_field('aihub_cards', 'aihub_cards_nonce', true, false) . '<input type="hidden" name="id" value="' . $id . '"><button class="aihub-btn aihub-btn-sm aihub-btn-danger" name="card_delete">删</button></form>';
            // 行内编辑表单
            echo '<div id="card-' . $id . '" class="aihub-edit" style="display:none"><form method="post" action="' . $action . '">' . wp_nonce_field('aihub_cards', 'aihub_cards_nonce', true, false) . '<input type="hidden" name="id" value="' . $id . '">';
            echo $this->card_fields($c);
            echo '<button class="aihub-btn aihub-btn-p aihub-btn-sm" name="card_update">保存</button></form></div>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        // 添加
        echo '<h3 class="aihub-h3">＋ 添加卡片</h3><form method="post" action="' . $action . '" class="aihub-form-grid">' . wp_nonce_field('aihub_cards', 'aihub_cards_nonce', true, false);
        echo $this->card_fields([]);
        echo '<button class="aihub-btn aihub-btn-p" name="card_add">添加卡片</button></form>';

        // JSON 导入
        echo '<h3 class="aihub-h3">📥 从 JSON 导入</h3><p class="aihub-tip">兼容现有 card-data.json：粘贴 <code>{"cards":[{"title","description","url","badge","category"}]}</code></p>';
        echo '<form method="post" action="' . $action . '">' . wp_nonce_field('aihub_cards', 'aihub_cards_nonce', true, false);
        echo '<textarea name="json" class="aihub-textarea" placeholder=\'{"cards":[...]}\'></textarea>';
        echo '<p><button class="aihub-btn" name="card_import" onclick="return confirm(\'导入会追加到现有卡片，继续？\')">导入</button></p></form>';

        echo '</div></div>';
    }

    private function card_fields($c) {
        $h  = '<div class="aihub-fields">';
        $h .= '<label>标题 <input type="text" name="title" value="' . esc_attr($c['title'] ?? '') . '" required></label>';
        $h .= '<label>标签 badge <input type="text" name="badge" value="' . esc_attr($c['badge'] ?? '') . '" placeholder="如 AI助手"></label>';
        $h .= '<label>来源 <input type="text" name="category" value="' . esc_attr($c['category'] ?? '') . '" placeholder="如 腾讯"></label>';
        $h .= '<label>链接 <input type="url" name="url" value="' . esc_attr($c['url'] ?? '') . '" required placeholder="https://"></label>';
        $h .= '<label>排序 <input type="number" name="order" value="' . esc_attr($c['order'] ?? 0) . '" style="width:80px"></label>';
        $h .= '<label class="aihub-full">描述 <textarea name="desc" rows="2">' . esc_textarea($c['desc'] ?? '') . '</textarea></label>';
        $h .= '</div>';
        return $h;
    }

    /* ---------------- Tab: 跑马灯 ---------------- */
    private function tab_ticker() {
        $s = get_option('aihub_ticker_settings', []);
        $action = esc_url(admin_url('admin.php?page=aihub&tab=ticker'));
        echo '<div class="aihub-card-box"><div class="aihub-box-hd">📢 快讯跑马灯</div><div class="aihub-box-bd">';
        echo '<p class="aihub-tip">数据来自 NewsFlash 快讯插件 + 下方「广告」中投放到跑马灯的条目。首页用短代码 <code>[aihub_ticker]</code>（放卡片上方）；文章页自动注入到面包屑上方。</p>';
        if (!post_type_exists('newsflash')) echo '<div class="notice notice-warning inline"><p>⚠️ 未检测到 NewsFlash 快讯插件，跑马灯将只显示广告条目。</p></div>';
        echo '<form method="post" action="' . $action . '">' . wp_nonce_field('aihub_set', 'aihub_set_nonce', true, false);
        echo '<div class="aihub-rows">';
        echo $this->row_toggle('t_enabled', '启用跑马灯', !empty($s['enabled']));
        echo $this->row_toggle('t_show_home', '首页输出（短代码）', !empty($s['show_home']));
        echo $this->row_toggle('t_show_single', '文章页自动注入', !empty($s['show_single']));
        echo $this->row_num('t_news_count', '快讯条数', $s['news_count'] ?? 10);
        echo $this->row_num('t_ad_every', '每几条快讯插 1 条广告（0=不混排）', $s['ad_every'] ?? 4);
        echo $this->row_num('t_speed', '滚动周期（秒，越大越慢）', $s['speed'] ?? 40);
        echo $this->row_text('t_label', '左侧标签文字', $s['label'] ?? '📢 快讯');
        echo $this->row_text('t_news_url', '标签跳转链接', $s['news_url'] ?? '/newsflash/');
        echo $this->row_toggle('t_link_blank', '快讯链接在新标签页打开（广告始终新标签页）', !empty($s['link_blank']));
        echo '</div><p><button class="aihub-btn aihub-btn-p" name="save_ticker">保存</button></p></form>';
        echo '</div></div>';
    }

    /* ---------------- Tab: 广告 ---------------- */
    private function tab_ads() {
        $ads = aihub_get_ads();
        $action = esc_url(admin_url('admin.php?page=aihub&tab=ads'));
        $pl = ['ticker' => '跑马灯', 'sidebar' => '侧边栏', 'both' => '都投放'];
        echo '<div class="aihub-card-box"><div class="aihub-box-hd">📣 广告库 <span class="aihub-meta">' . count($ads) . ' 条</span></div><div class="aihub-box-bd">';
        echo '<p class="aihub-tip">广告用于跑马灯和侧边栏卡片。标题 + 链接必填，图标/描述仅侧边栏卡用。</p>';
        echo '<table class="aihub-tbl"><thead><tr><th style="width:50px">排序</th><th>标题</th><th>链接</th><th style="width:90px">投放</th><th style="width:60px">状态</th><th style="width:150px">操作</th></tr></thead><tbody>';
        if (empty($ads)) echo '<tr><td colspan="6" class="aihub-empty">暂无广告，下方添加</td></tr>';
        foreach ($ads as $a) {
            $id = esc_attr($a['id'] ?? '');
            echo '<tr class="' . (empty($a['enabled']) ? 'is-off' : '') . '">';
            echo '<td>' . esc_html($a['order'] ?? 0) . '</td>';
            echo '<td><strong>' . esc_html($a['title'] ?? '') . '</strong></td>';
            echo '<td><a href="' . esc_url($a['url'] ?? '#') . '" target="_blank" class="aihub-url">' . esc_html($a['url'] ?? '') . '</a></td>';
            echo '<td>' . esc_html($pl[$a['placement'] ?? 'both'] ?? '都投放') . '</td>';
            echo '<td><span class="aihub-bdg ' . (!empty($a['enabled']) ? 'on' : 'off') . '">' . (!empty($a['enabled']) ? '启用' : '禁用') . '</span></td>';
            echo '<td>';
            echo '<a class="aihub-btn aihub-btn-sm" href="javascript:void(0)" onclick="aihubEdit(\'ad-' . $id . '\')">编辑</a> ';
            echo '<form method="post" action="' . $action . '" style="display:inline">' . wp_nonce_field('aihub_ads', 'aihub_ads_nonce', true, false) . '<input type="hidden" name="id" value="' . $id . '"><button class="aihub-btn aihub-btn-sm" name="ad_toggle">' . (!empty($a['enabled']) ? '禁用' : '启用') . '</button></form> ';
            echo '<form method="post" action="' . $action . '" style="display:inline" onsubmit="return confirm(\'删除？\')">' . wp_nonce_field('aihub_ads', 'aihub_ads_nonce', true, false) . '<input type="hidden" name="id" value="' . $id . '"><button class="aihub-btn aihub-btn-sm aihub-btn-danger" name="ad_delete">删</button></form>';
            echo '<div id="ad-' . $id . '" class="aihub-edit" style="display:none"><form method="post" action="' . $action . '">' . wp_nonce_field('aihub_ads', 'aihub_ads_nonce', true, false) . '<input type="hidden" name="id" value="' . $id . '">';
            echo $this->ad_fields($a);
            echo '<button class="aihub-btn aihub-btn-p aihub-btn-sm" name="ad_update">保存</button></form></div>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h3 class="aihub-h3">＋ 添加广告</h3><form method="post" action="' . $action . '" class="aihub-form-grid">' . wp_nonce_field('aihub_ads', 'aihub_ads_nonce', true, false);
        echo $this->ad_fields([]);
        echo '<button class="aihub-btn aihub-btn-p" name="ad_add">添加广告</button></form>';
        echo '</div></div>';
    }

    private function ad_fields($a) {
        $pl = $a['placement'] ?? 'both';
        $h  = '<div class="aihub-fields">';
        $h .= '<label>标题 <input type="text" name="title" value="' . esc_attr($a['title'] ?? '') . '" required></label>';
        $h .= '<label>链接 <input type="url" name="url" value="' . esc_attr($a['url'] ?? '') . '" required placeholder="https://"></label>';
        $h .= '<label>投放位置 <select name="placement">'
            . '<option value="both"' . selected($pl, 'both', false) . '>跑马灯 + 侧边栏</option>'
            . '<option value="ticker"' . selected($pl, 'ticker', false) . '>仅跑马灯</option>'
            . '<option value="sidebar"' . selected($pl, 'sidebar', false) . '>仅侧边栏</option></select></label>';
        $h .= '<label>排序 <input type="number" name="order" value="' . esc_attr($a['order'] ?? 0) . '" style="width:80px"></label>';
        $h .= '<label>图标 URL（侧边栏小图标） <input type="url" name="icon" value="' . esc_attr($a['icon'] ?? '') . '"></label>';
        $h .= '<label>海报 URL（侧边栏大图，优先于图标） <input type="url" name="poster" value="' . esc_attr($a['poster'] ?? '') . '"></label>';
        $h .= '<label class="aihub-full">描述（跑马灯一句话特性 / 侧边栏卡） <input type="text" name="desc" value="' . esc_attr($a['desc'] ?? '') . '"></label>';
        $h .= '</div>';
        return $h;
    }

    /* ---------------- Tab: 侧边栏 ---------------- */
    private function tab_sidebar() {
        $s = get_option('aihub_sidebar_settings', []);
        $action = esc_url(admin_url('admin.php?page=aihub&tab=sidebar'));
        echo '<div class="aihub-card-box"><div class="aihub-box-hd">📌 侧边栏海报广告</div><div class="aihub-box-bd">';
        echo '<p class="aihub-tip">在文章页侧边栏注入<strong>纯海报图</strong>（无边框 / 无标题 / 无角标）。只显示「广告」中投放到侧边栏、且<strong>填了「海报 URL」</strong>的广告；没填海报的不显示。仅桌面端显示（跟随主题）。</p>';
        echo '<form method="post" action="' . $action . '">' . wp_nonce_field('aihub_set', 'aihub_set_nonce', true, false);
        echo '<div class="aihub-rows">';
        echo $this->row_toggle('s_enabled', '启用侧边栏海报', !empty($s['enabled']));
        echo '<div class="aihub-row"><label>插入位置</label><select name="s_position"><option value="top"' . selected($s['position'] ?? 'top', 'top', false) . '>侧边栏顶部</option><option value="bottom"' . selected($s['position'] ?? 'top', 'bottom', false) . '>侧边栏底部</option></select></div>';
        echo '</div><p><button class="aihub-btn aihub-btn-p" name="save_sidebar">保存</button></p></form>';
        echo '</div></div>';
    }

    /* ---------------- Tab: 设置 ---------------- */
    private function tab_settings() {
        $s = get_option('aihub_settings', []);
        $cs = get_option('aihub_cards_settings', []);
        $action = esc_url(admin_url('admin.php?page=aihub&tab=settings'));
        echo '<div class="aihub-card-box"><div class="aihub-box-hd">⚙️ 全局设置</div><div class="aihub-box-bd">';
        echo '<form method="post" action="' . $action . '">' . wp_nonce_field('aihub_set', 'aihub_set_nonce', true, false);
        echo '<div class="aihub-rows">';
        echo $this->row_toggle('g_enabled', '插件总开关（关闭则前台全部不显示）', !empty($s['enabled']));
        echo $this->row_toggle('g_cards_enabled', '启用首页卡片短代码', !empty($cs['enabled']));
        echo $this->row_text('g_crumbs', '面包屑锚点选择器（文章页跑马灯插在它前面）', $s['crumbs_selector'] ?? '.crumbs');
        echo $this->row_text('g_sidebar', '侧边栏锚点选择器', $s['sidebar_selector'] ?? '.sidebar.sidebar-tools');
        echo '</div>';
        echo '<p class="aihub-tip">锚点选择器供高级用户在主题改版后自救。OneNav / 一为主题默认值已填好，一般无需改动。</p>';
        echo '<p><button class="aihub-btn aihub-btn-p" name="save_settings">保存</button></p></form>';
        echo '<h3 class="aihub-h3">短代码速查</h3><ul class="aihub-shortcodes"><li><code>[aihub_cards]</code> — 首页工具卡片</li><li><code>[aihub_ticker]</code> — 快讯跑马灯（放卡片上方）</li></ul>';
        echo '</div></div>';
    }

    /* ---------------- Tab: 海报 ---------------- */
    private function tab_banners() {
        $slots = aihub_get_banner_slots();
        $action = esc_url(admin_url('admin.php?page=aihub&tab=banners'));

        echo '<div class="aihub-card-box"><div class="aihub-box-hd">🖼️ 横版海报组 <span class="aihub-meta">' . count($slots) . ' 个海报位</span></div><div class="aihub-box-bd">';
        echo '<p class="aihub-tip">每个海报位用短代码 <code>[aihub_banner slot=标识]</code> 放到页面任意位置。一行 2~3 个，海报多于一行会自动轮播。建议同一海报位用<strong>相同尺寸</strong>的横版长图。</p>';
        echo '<h3 class="aihub-h3">＋ 新建海报位</h3>';
        echo '<form method="post" action="' . $action . '" class="aihub-form-grid">' . wp_nonce_field('aihub_banners', 'aihub_banners_nonce', true, false);
        echo '<div class="aihub-fields">';
        echo '<label>名称 <input type="text" name="name" placeholder="如 首页顶部" required></label>';
        echo '<label>标识 slug（短代码用，留空自动生成） <input type="text" name="slug" placeholder="home-top"></label>';
        echo '<label>每行 <select name="per_row"><option value="2">2 个</option><option value="3">3 个</option></select></label>';
        echo '<label>轮播间隔秒（0=不轮播） <input type="number" name="rotate" value="5" min="0" style="width:90px"></label>';
        echo '</div><button class="aihub-btn aihub-btn-p" name="slot_add">新建海报位</button></form>';
        echo '</div></div>';

        foreach ($slots as $slot) {
            $sid   = esc_attr($slot['id'] ?? '');
            $slug  = esc_html($slot['slug'] ?? '');
            $items = $slot['items'] ?? [];
            echo '<div class="aihub-card-box"><div class="aihub-box-hd">';
            echo esc_html($slot['name'] ?? $slug) . ' &nbsp;<code style="font-size:11px">[aihub_banner slot=' . $slug . ']</code>';
            echo '<span class="aihub-meta">' . count($items) . ' 张 · 每行 ' . (int)($slot['per_row'] ?? 2) . ' · 轮播 ' . (int)($slot['rotate'] ?? 5) . 's · ' . (!empty($slot['enabled']) ? '启用' : '禁用') . '</span>';
            echo '</div><div class="aihub-box-bd">';

            // 海报位设置 + 操作
            echo '<form method="post" action="' . $action . '" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px">' . wp_nonce_field('aihub_banners', 'aihub_banners_nonce', true, false) . '<input type="hidden" name="id" value="' . $sid . '">';
            echo '<label style="font-size:12px;color:var(--ah-mut)">名称<br><input type="text" name="name" value="' . esc_attr($slot['name'] ?? '') . '"></label>';
            echo '<label style="font-size:12px;color:var(--ah-mut)">每行<br><select name="per_row"><option value="2"' . selected($slot['per_row'] ?? 2, 2, false) . '>2</option><option value="3"' . selected($slot['per_row'] ?? 2, 3, false) . '>3</option></select></label>';
            echo '<label style="font-size:12px;color:var(--ah-mut)">轮播秒<br><input type="number" name="rotate" value="' . (int)($slot['rotate'] ?? 5) . '" min="0" style="width:70px"></label>';
            echo '<button class="aihub-btn aihub-btn-sm" name="slot_update">保存设置</button>';
            echo '<button class="aihub-btn aihub-btn-sm" name="slot_toggle">' . (!empty($slot['enabled']) ? '禁用' : '启用') . '</button>';
            echo '<button class="aihub-btn aihub-btn-sm aihub-btn-danger" name="slot_delete" onclick="return confirm(\'删除整个海报位？\')">删除海报位</button>';
            echo '</form>';

            // 海报缩略图列表
            if ($items) {
                echo '<div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px">';
                foreach ($items as $it) {
                    $iid = esc_attr($it['id'] ?? '');
                    echo '<div style="width:190px;border:1px solid var(--ah-brd-soft);border-radius:8px;overflow:hidden">';
                    echo '<img src="' . esc_url($it['image'] ?? '') . '" alt="" style="width:100%;height:70px;object-fit:cover;display:block">';
                    echo '<div style="padding:6px 8px;font-size:11px"><strong>' . esc_html($it['title'] ?: '(无标题)') . '</strong><br><a href="' . esc_url($it['url'] ?? '#') . '" target="_blank" style="color:var(--ah-p);word-break:break-all">' . esc_html($it['url'] ?? '') . '</a></div>';
                    echo '<form method="post" action="' . $action . '" onsubmit="return confirm(\'删除这张海报？\')">' . wp_nonce_field('aihub_banners', 'aihub_banners_nonce', true, false) . '<input type="hidden" name="slot_id" value="' . $sid . '"><input type="hidden" name="item_id" value="' . $iid . '"><button class="aihub-btn aihub-btn-sm aihub-btn-danger" name="item_delete" style="width:100%;border-radius:0;border:none">删除</button></form>';
                    echo '</div>';
                }
                echo '</div>';
            }

            // 添加海报
            echo '<form method="post" action="' . $action . '" class="aihub-form-grid">' . wp_nonce_field('aihub_banners', 'aihub_banners_nonce', true, false) . '<input type="hidden" name="slot_id" value="' . $sid . '">';
            echo '<div class="aihub-fields">';
            echo '<label>海报图 URL <input type="url" name="image" required placeholder="https://…横版长图"></label>';
            echo '<label>跳转链接 <input type="url" name="url" required placeholder="https://…"></label>';
            echo '<label>标题/备注 <input type="text" name="title" placeholder="产品名（可选）"></label>';
            echo '</div><button class="aihub-btn aihub-btn-p" name="item_add">＋ 添加海报</button></form>';

            echo '</div></div>';
        }
    }

    /* ---------------- Tab: 统计 ---------------- */
    private function tab_stats() {
        $stats = aihub_get_all_stats();
        if (!is_array($stats)) $stats = [];
        $entities = aihub_stats_entities();
        $action = esc_url(admin_url('admin.php?page=aihub&tab=stats'));

        $total_imp = 0; $total_clk = 0; $rows = [];
        foreach ($stats as $id => $s) {
            $imp = (int)($s['imp'] ?? 0); $clk = (int)($s['clk'] ?? 0);
            $total_imp += $imp; $total_clk += $clk;
            $ent = $entities[$id] ?? ['name' => '(已删除)', 'type' => '—'];
            $rows[] = ['name' => $ent['name'], 'type' => $ent['type'], 'imp' => $imp, 'clk' => $clk];
        }
        usort($rows, function ($a, $b) { return $b['clk'] - $a['clk']; });
        $ctr = $total_imp > 0 ? round($total_clk / $total_imp * 100, 2) : 0;

        echo '<div class="aihub-card-box"><div class="aihub-box-hd">📊 广告统计 <span class="aihub-meta">累计</span></div><div class="aihub-box-bd">';
        echo '<p class="aihub-tip">展示=被显示次数 · 点击=被点击次数 · CTR=点击/展示。数据由前端实时上报，不受页面缓存影响。覆盖跑马灯广告、侧边栏海报、横版海报。</p>';
        echo '<div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:18px">';
        echo '<div style="flex:1;min-width:150px;padding:18px;border-radius:12px;background:linear-gradient(135deg,#6366f1,#a855f7);color:#fff"><div style="font-size:11px;opacity:.85">总展示</div><div style="font-size:28px;font-weight:800">' . number_format($total_imp) . '</div></div>';
        echo '<div style="flex:1;min-width:150px;padding:18px;border-radius:12px;background:linear-gradient(135deg,#22d3ee,#3b82f6);color:#fff"><div style="font-size:11px;opacity:.85">总点击</div><div style="font-size:28px;font-weight:800">' . number_format($total_clk) . '</div></div>';
        echo '<div style="flex:1;min-width:150px;padding:18px;border-radius:12px;background:linear-gradient(135deg,#fb7185,#ec4899);color:#fff"><div style="font-size:11px;opacity:.85">整体 CTR</div><div style="font-size:28px;font-weight:800">' . $ctr . '%</div></div>';
        echo '</div>';

        if ($rows) {
            echo '<table class="aihub-tbl"><thead><tr><th>广告 / 海报</th><th style="width:120px">类型</th><th style="width:80px;text-align:right">展示</th><th style="width:80px;text-align:right">点击</th><th style="width:70px;text-align:right">CTR</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $rctr = $r['imp'] > 0 ? round($r['clk'] / $r['imp'] * 100, 1) : 0;
                echo '<tr><td>' . esc_html($r['name']) . '</td><td>' . esc_html($r['type']) . '</td><td style="text-align:right">' . number_format($r['imp']) . '</td><td style="text-align:right;font-weight:600">' . number_format($r['clk']) . '</td><td style="text-align:right;color:var(--ah-p);font-weight:700">' . $rctr . '%</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p class="aihub-empty">暂无统计数据。等访客浏览页面、点击广告/海报后，这里会出现数据。</p>';
        }

        echo '<form method="post" action="' . $action . '" style="margin-top:16px">' . wp_nonce_field('aihub_stats', 'aihub_stats_nonce', true, false);
        echo '<button class="aihub-btn aihub-btn-danger" name="reset_stats" onclick="return confirm(\'确定清空所有统计数据？不可恢复\')">重置统计</button></form>';
        echo '</div></div>';
    }

    /* ---------------- 小组件 ---------------- */
    private function row_toggle($name, $label, $checked) {
        return '<div class="aihub-row"><label>' . esc_html($label) . '</label><label class="aihub-toggle"><input type="checkbox" name="' . esc_attr($name) . '" value="1"' . checked($checked, true, false) . '><span class="aihub-tg"></span></label></div>';
    }
    private function row_num($name, $label, $val) {
        return '<div class="aihub-row"><label>' . esc_html($label) . '</label><input type="number" name="' . esc_attr($name) . '" value="' . esc_attr($val) . '" style="width:90px"></div>';
    }
    private function row_text($name, $label, $val) {
        return '<div class="aihub-row"><label>' . esc_html($label) . '</label><input type="text" name="' . esc_attr($name) . '" value="' . esc_attr($val) . '" style="width:220px"></div>';
    }
}
