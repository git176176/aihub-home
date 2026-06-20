<?php
/**
 * Plugin Name: AIHub 首页与广告位
 * Description: 首页工具卡片（后台可视化管理）+ 快讯跑马灯（读 NewsFlash 数据）+ 文章侧边栏广告卡。专为 OneNav / 一为导航主题适配，不改主题文件，全功能可开关。
 * Version: 1.1.3
 * Author: aiproducthub
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) exit;

define('AIHUB_VERSION', '1.1.3');
define('AIHUB_DB_VERSION', '1');
define('AIHUB_DIR', plugin_dir_path(__FILE__));
define('AIHUB_URL', plugin_dir_url(__FILE__));

require_once AIHUB_DIR . 'includes/ads.php';
require_once AIHUB_DIR . 'includes/cards.php';
require_once AIHUB_DIR . 'includes/ticker.php';
require_once AIHUB_DIR . 'includes/sidebar-ads.php';
require_once AIHUB_DIR . 'includes/banners.php';
require_once AIHUB_DIR . 'includes/stats.php';
require_once AIHUB_DIR . 'includes/admin.php';

final class AIHub_Plugin {
    private static $instance = null;
    public static function instance() { if (is_null(self::$instance)) self::$instance = new self(); return self::$instance; }

    private function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_front']);
        add_action('wp_footer', [$this, 'print_front_data'], 5);
        add_action('rest_api_init', [$this, 'register_rest']);
        add_action('plugins_loaded', [$this, 'maybe_upgrade_db']);
        // 短代码
        add_shortcode('aihub_cards',  'aihub_cards_shortcode');
        add_shortcode('aihub_ticker', 'aihub_ticker_shortcode');
        add_shortcode('aihub_banner', 'aihub_banner_shortcode');
        // 后台
        if (is_admin()) {
            AIHub_Admin::instance();
        }
        register_activation_hook(__FILE__, [$this, 'activate']);
    }

    /** 跑马灯实时数据端点（绕过页面缓存） */
    public function register_rest() {
        register_rest_route('aihub/v1', '/ticker', [
            'methods'             => 'GET',
            'callback'            => 'aihub_rest_ticker',
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('aihub/v1', '/banner', [
            'methods'             => 'GET',
            'callback'            => 'aihub_rest_banner',
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('aihub/v1', '/cards', [
            'methods'             => 'GET',
            'callback'            => 'aihub_rest_cards',
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('aihub/v1', '/track', [
            'methods'             => 'POST',
            'callback'            => 'aihub_rest_track',
            'permission_callback' => '__return_true',
        ]);
    }

    public function activate() {
        $defaults = [
            'aihub_settings' => [
                'enabled'           => true,   // 全局总开关
                'crumbs_selector'   => '.crumbs',
                'sidebar_selector'  => '.sidebar.sidebar-tools',
            ],
            'aihub_cards' => [],
            'aihub_cards_settings' => [
                'enabled' => true,
            ],
            'aihub_ads' => [],
            'aihub_ticker_settings' => [
                'enabled'        => true,
                'show_home'      => true,   // 首页短代码是否输出
                'show_single'    => true,   // 文章页是否 JS 注入
                'news_count'     => 10,
                'ad_every'       => 4,      // 每 N 条快讯插 1 条广告
                'speed'          => 40,     // 滚动周期秒数（越大越慢）
                'label'          => '📢 快讯',
                'news_url'       => '/newsflash/',
                'link_blank'     => true,   // 快讯链接在新标签页打开（广告始终新标签页）
            ],
            'aihub_sidebar_settings' => [
                'enabled'    => true,
                'title'      => '推荐',
                'merge'      => true,       // 多条广告合并为一张卡
                'position'   => 'top',      // top | bottom
            ],
            'aihub_banner_slots' => [],
        ];
        foreach ($defaults as $key => $val) {
            if (get_option($key) === false) add_option($key, $val);
        }
        aihub_create_stats_table();
        update_option('aihub_db_version', AIHUB_DB_VERSION);
    }

    /** 已安装用户升级时建表（activate 不会在升级时重跑） */
    public function maybe_upgrade_db() {
        if (get_option('aihub_db_version') !== AIHUB_DB_VERSION) {
            aihub_create_stats_table();
            update_option('aihub_db_version', AIHUB_DB_VERSION);
        }
    }

    /** 是否在当前页加载前台资源 */
    private function need_front_assets() {
        $s = get_option('aihub_settings', []);
        if (empty($s['enabled'])) return false;
        // 所有前台页面都加载（短代码/海报可能放在任意页面；资源很小且浏览器可缓存）
        return !is_admin();
    }

    public function enqueue_front() {
        if (!$this->need_front_assets()) return;
        wp_enqueue_style('aihub', AIHUB_URL . 'assets/css/aihub.css', [], AIHUB_VERSION);
        wp_enqueue_script('aihub', AIHUB_URL . 'assets/js/aihub.js', [], AIHUB_VERSION, false);
    }

    /** 把注入所需的数据 + 配置以 JSON 注入页面，供 aihub.js 使用 */
    public function print_front_data() {
        if (!$this->need_front_assets()) return;
        $settings = get_option('aihub_settings', []);
        $ticker   = get_option('aihub_ticker_settings', []);
        $sidebar  = get_option('aihub_sidebar_settings', []);

        $data = [
            'crumbsSelector'  => $settings['crumbs_selector'] ?? '.crumbs',
            'sidebarSelector' => $settings['sidebar_selector'] ?? '.sidebar.sidebar-tools',
            'restTicker'      => rest_url('aihub/v1/ticker'),
            'restBanner'      => rest_url('aihub/v1/banner'),
            'restCards'       => rest_url('aihub/v1/cards'),
            'restTrack'       => rest_url('aihub/v1/track'),
            'ticker'  => null,
            'sidebar' => null,
        ];

        // 文章页跑马灯：标记需在面包屑前注入占位（内容由 AJAX 实时填充，绕过页面缓存）
        if (is_singular() && !empty($ticker['enabled']) && !empty($ticker['show_single'])) {
            $data['ticker'] = ['inject' => true];
        }
        // 文章页侧边栏广告卡（JS 注入）
        if (is_singular() && !empty($sidebar['enabled'])) {
            $html = aihub_render_sidebar_ads();
            if ($html !== '') {
                $data['sidebar'] = [
                    'html'     => $html,
                    'position' => $sidebar['position'] ?? 'top',
                ];
            }
        }

        // 短代码兜底：OneNav 等主题的「自定义 HTML 模块」区域不执行 do_shortcode，
        // 这里把渲染结果下发，由 aihub.js 把字面 [aihub_cards]/[aihub_ticker] 替换掉。
        // 三者现在都返回轻量占位（卡片/跑马灯/海报内容均由前端 AJAX 实时拉取，任意页面可用、绕过缓存）。
        $data['shortcodes'] = [
            '[aihub_cards]'  => aihub_cards_shortcode([]),
            '[aihub_ticker]' => aihub_ticker_shortcode([]),
        ];

        echo '<script id="aihub-data" type="application/json">' . wp_json_encode($data) . '</script>' . "\n";
    }
}

function aihub_plugin() { return AIHub_Plugin::instance(); }
aihub_plugin();
