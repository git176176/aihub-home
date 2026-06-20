/* AIHub front-end — 跑马灯 / 横版海报 AJAX 加载 + 侧边栏注入 + 展示/点击统计 */
(function () {
  'use strict';

  function getData() {
    var el = document.getElementById('aihub-data');
    if (!el) return null;
    try { return JSON.parse(el.textContent); } catch (e) { return null; }
  }

  // ---------- 统计上报 ----------
  function beacon(base, params) {
    if (!base) return;
    var q = base + (base.indexOf('?') === -1 ? '?' : '&') +
      Object.keys(params).map(function (k) { return k + '=' + encodeURIComponent(params[k]); }).join('&');
    try { if (navigator.sendBeacon) { navigator.sendBeacon(q); return; } } catch (e) {}
    try { fetch(q, { method: 'POST', keepalive: true, cache: 'no-store' }); } catch (e) {}
  }

  // 收集尚未上报的 [data-track-id]，去重后批量上报展示（多次调用只报新增的）
  function reportImpressions(data) {
    if (!data.restTrack) return;
    var els = document.querySelectorAll('[data-track-id]:not([data-tracked])');
    if (!els.length) return;
    var ids = [];
    els.forEach(function (el) {
      el.setAttribute('data-tracked', '1');
      var id = el.getAttribute('data-track-id');
      if (id && ids.indexOf(id) === -1) ids.push(id);
    });
    if (ids.length) beacon(data.restTrack, { imp: ids.join(',') });
  }

  // ---------- 跑马灯 ----------
  function injectTicker(data) {
    if (!data.ticker || !data.ticker.inject) return;
    var anchor = document.querySelector(data.crumbsSelector || '.crumbs');
    if (!anchor) return;
    if (document.querySelector('.aihub-ticker-mount, .aihub-ticker')) return; // 防重复
    var mount = document.createElement('div');
    mount.className = 'aihub-ticker-mount aihub-ticker-inject';
    mount.setAttribute('data-aihub-ticker', '');
    anchor.parentNode.insertBefore(mount, anchor);
  }

  function loadTickers(data) {
    var mounts = document.querySelectorAll('.aihub-ticker-mount[data-aihub-ticker]');
    if (!mounts.length || !data.restTicker) return;
    var url = data.restTicker + (data.restTicker.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now();
    fetch(url, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (res) {
        if (!res || !res.html) return;
        mounts.forEach(function (m) {
          m.innerHTML = res.html;
          m.removeAttribute('data-aihub-ticker');
        });
        reportImpressions(data);
      })
      .catch(function () {});
  }

  // ---------- 横版海报 ----------
  function initBanner(box) {
    var per = parseInt(box.getAttribute('data-per-row'), 10) || 2;
    var rotate = parseInt(box.getAttribute('data-rotate'), 10) || 0;
    var items = Array.prototype.slice.call(box.querySelectorAll('.aihub-banner-item'));
    if (!items.length) return;
    var pages = [];
    for (var i = 0; i < items.length; i += per) pages.push(items.slice(i, i + per));
    box.innerHTML = '';
    box.style.setProperty('--aihub-cols', per);
    pages.forEach(function (pg, idx) {
      var p = document.createElement('div');
      p.className = 'aihub-banner-page' + (pages.length > 1 ? ' is-stacked' : '') + (idx === 0 ? ' is-active' : '');
      pg.forEach(function (it) { p.appendChild(it); });
      box.appendChild(p);
    });
    // 多于一页且设了轮播间隔 → 定时淡入淡出切页
    if (pages.length > 1 && rotate > 0) {
      var cur = 0;
      setInterval(function () {
        var ps = box.querySelectorAll('.aihub-banner-page');
        if (ps.length < 2) return;
        ps[cur].classList.remove('is-active');
        cur = (cur + 1) % ps.length;
        ps[cur].classList.add('is-active');
      }, rotate * 1000);
    }
  }

  function loadBanners(data) {
    var mounts = document.querySelectorAll('.aihub-banner-mount[data-slot]');
    if (!mounts.length || !data.restBanner) return;
    mounts.forEach(function (m) {
      var slot = m.getAttribute('data-slot');
      if (!slot) return;
      var url = data.restBanner + (data.restBanner.indexOf('?') === -1 ? '?' : '&') +
        'slot=' + encodeURIComponent(slot) + '&_=' + Date.now();
      fetch(url, { cache: 'no-store', credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (res) {
          if (!res || !res.html) return;
          m.innerHTML = res.html;
          m.removeAttribute('data-slot');
          var box = m.querySelector('.aihub-banner');
          if (box) initBanner(box);
          reportImpressions(data);
        })
        .catch(function () {});
    });
  }

  // ---------- 首页卡片 ----------
  function loadCards(data) {
    var mounts = document.querySelectorAll('.aihub-cards-mount[data-aihub-cards]');
    if (!mounts.length || !data.restCards) return;
    var url = data.restCards + (data.restCards.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now();
    fetch(url, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (res) {
        if (!res || !res.html) return;
        mounts.forEach(function (m) {
          m.innerHTML = res.html;
          m.removeAttribute('data-aihub-cards');
        });
        reportImpressions(data);
      })
      .catch(function () {});
  }

  // ---------- 侧边栏海报 ----------
  function injectSidebar(data) {
    if (!data.sidebar || !data.sidebar.html) return;
    var sidebar = document.querySelector(data.sidebarSelector || '.sidebar.sidebar-tools');
    if (!sidebar) return;
    if (sidebar.querySelector('.aihub-ad-holder')) return;
    var holder = document.createElement('div');
    holder.className = 'aihub-ad-holder';
    holder.innerHTML = data.sidebar.html;
    if (data.sidebar.position === 'bottom') {
      sidebar.appendChild(holder);
    } else {
      sidebar.insertBefore(holder, sidebar.firstChild);
    }
  }

  // ---------- 短代码兜底（OneNav 自定义模块不执行 do_shortcode）----------
  // 固定 token：[aihub_cards] / [aihub_ticker]
  function replaceShortcodes(data) {
    var map = data.shortcodes;
    if (!map) return;
    Object.keys(map).forEach(function (token) {
      var html = map[token];
      if (typeof html !== 'string') return;
      replaceTextToken(function (v) { return v.indexOf(token) !== -1; },
        function (v) { return v.split(token).join(html); });
    });
  }
  // 带参 token：[aihub_banner slot=xxx]
  function replaceBannerShortcodes() {
    var re = /\[aihub_banner\s+slot=["']?([a-zA-Z0-9_\-]+)["']?\s*\]/g;
    replaceTextToken(function (v) { return v.indexOf('[aihub_banner') !== -1; },
      function (v) {
        return v.replace(re, function (m, slug) {
          return '<div class="aihub-banner-mount" data-slot="' + slug + '"></div>';
        });
      });
  }
  // 通用：遍历文本节点，match 命中则用 transform 结果替换为 DOM
  function replaceTextToken(match, transform) {
    var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);
    var targets = [];
    while (walker.nextNode()) {
      var n = walker.currentNode;
      if (n.nodeValue && match(n.nodeValue)) targets.push(n);
    }
    targets.forEach(function (node) {
      var out = transform(node.nodeValue);
      if (out === node.nodeValue) return;
      var tmp = document.createElement('div');
      tmp.innerHTML = out;
      var frag = document.createDocumentFragment();
      while (tmp.firstChild) frag.appendChild(tmp.firstChild);
      node.parentNode.replaceChild(frag, node);
    });
  }

  function init() {
    var data = getData();
    if (!data) return;
    replaceShortcodes(data);        // [aihub_cards]→占位、[aihub_ticker]→占位
    replaceBannerShortcodes();      // [aihub_banner slot=x]→占位
    injectTicker(data);             // 文章页面包屑前插跑马灯占位
    loadCards(data);                // AJAX 填充卡片
    loadTickers(data);              // AJAX 填充跑马灯
    loadBanners(data);              // AJAX 填充海报 + 轮播
    injectSidebar(data);            // 侧边栏海报
    reportImpressions(data);        // 同步内容先报一次展示（异步内容在各自回调里再报）
    // 点击统计（事件委托，capture 阶段，早于卡片自身 onclick）
    document.addEventListener('click', function (e) {
      var el = e.target && e.target.closest ? e.target.closest('[data-track-id]') : null;
      if (!el || !data.restTrack) return;
      beacon(data.restTrack, { clk: el.getAttribute('data-track-id') });
    }, true);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
