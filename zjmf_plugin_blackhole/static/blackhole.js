/* 黑洞查询插件 - 前台脚本（无依赖，纯 textContent 输出，天然防 XSS） */
(function () {
    var roots = document.querySelectorAll('.bh-root[data-url]');
    if (!roots.length) {
        return;
    }

    function mk(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text !== undefined && text !== null) { e.textContent = text; }
        return e;
    }

    // "2026-10-06 01:55:10" 按北京时间(UTC+8)解析为毫秒
    function parseCST(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/.exec(String(s == null ? '' : s));
        if (!m) { return null; }
        return Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4] - 8, +m[5], +m[6]);
    }

    function remainText(endStr) {
        var t = parseCST(endStr);
        if (!t) { return '—'; }
        var diff = t - Date.now();
        if (diff <= 0) { return '即将解封'; }
        var h = Math.floor(diff / 3600000);
        var m = Math.floor((diff % 3600000) / 60000);
        return '剩余 ' + h + ' 小时 ' + m + ' 分';
    }

    function load(root) {
        if (root.getAttribute('data-done')) { return; }
        root.setAttribute('data-done', '1');

        var url = root.getAttribute('data-url');
        var q = url + (url.indexOf('?') > -1 ? '&' : '?')
            + 'hostid=' + encodeURIComponent(root.getAttribute('data-hostid'))
            + '&ip=' + encodeURIComponent(root.getAttribute('data-ip'))
            + '&sig=' + encodeURIComponent(root.getAttribute('data-sig'));

        var xhr = new XMLHttpRequest();
        xhr.open('GET', q, true);
        xhr.timeout = 8000;
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) { return; }
            if (xhr.status !== 200) { root.innerHTML = ''; return; }
            var res;
            try { res = JSON.parse(xhr.responseText); } catch (e) { root.innerHTML = ''; return; }
            if (!res || res.code !== 0 || !res.data) { root.innerHTML = ''; return; }
            render(root, res.data);
        };
        xhr.ontimeout = function () { root.innerHTML = ''; };
        xhr.onerror = function () { root.innerHTML = ''; };
        xhr.send();
    }

    function render(root, d) {
        root.innerHTML = '';
        if (d.blackhole) {
            var badge = mk('span', 'bh-badge bh-danger');
            badge.textContent = d.end_time ? ('黑洞中 · 解封 ' + d.end_time) : '黑洞中';
            var btn = mk('button', 'bh-detail-btn', '查看详情');
            btn.type = 'button';
            btn.onclick = function () { openModal(d); };
            root.appendChild(badge);
            root.appendChild(btn);
        } else {
            var ok = mk('span', 'bh-badge bh-ok', 'IP 正常');
            root.appendChild(ok);
        }
    }

    function openModal(d) {
        var mask = mk('div', 'bh-mask');
        var modal = mk('div', 'bh-modal');

        var head = mk('div', 'bh-modal-head');
        head.appendChild(mk('b', null, '黑洞封禁详情'));
        var close = mk('span', 'bh-modal-close', '×');
        close.onclick = function () { closeModal(mask); };
        head.appendChild(close);
        modal.appendChild(head);

        var rows = [
            ['IP 地址', String(d.ip || '') + (d.region_name ? '（' + d.region_name + '线路）' : '')],
            ['封堵时间', d.start_time || '—'],
            ['解封时间', d.end_time || '—'],
            ['剩余时间', remainText(d.end_time)],
            ['封堵时长', d.duration || '—'],
            ['封堵次数', d.num || '—'],
            ['攻击流量', d.bw || '—'],
            ['攻击包数', d.pps || '—']
        ];
        var table = mk('table', 'bh-table');
        var tb = mk('tbody');
        for (var i = 0; i < rows.length; i++) {
            var tr = mk('tr');
            tr.appendChild(mk('td', null, rows[i][0]));
            tr.appendChild(mk('td', null, rows[i][1]));
            tb.appendChild(tr);
        }
        table.appendChild(tb);
        modal.appendChild(table);
        modal.appendChild(mk('div', 'bh-modal-tip', '如对封禁有疑问，请提交工单联系客服。'));

        mask.appendChild(modal);
        mask.onclick = function (ev) { if (ev.target === mask) { closeModal(mask); } };
        document.body.appendChild(mask);
    }

    function closeModal(mask) {
        if (mask && mask.parentNode) { mask.parentNode.removeChild(mask); }
    }

    for (var i = 0; i < roots.length; i++) {
        load(roots[i]);
    }
})();
