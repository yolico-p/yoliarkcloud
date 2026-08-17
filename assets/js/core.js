/**
 * 基础设施模块 - API通信、UI组件、通用工具
 */

// ===== CDN 熔断器（Circuit Breaker）— 纯前端，localStorage =====
// 通过三状态机智能判断 CDN 是否可用，区分临时网络波动与真实故障
var CDN_FAILURE_THRESHOLD = 3;       // 连续失败 3 次才熔断，容忍 1-2 次波动
var CDN_BASE_COOLDOWN_MS = 300000;   // 首次熔断后 5 分钟重试
var CDN_MAX_COOLDOWN_MS = 3600000;   // 最长退避 1 小时
var CDN_COOLDOWN_MULTIPLIER = 2;     // 每次探测失败冷却时间翻倍

function _cdnDomain(url) {
    try { return url.split('/')[2]; } catch (e) { return ''; }
}

function getCdnHealthAll() {
    try { return JSON.parse(localStorage.getItem('cdn_health') || '{}'); }
    catch (e) { return {}; }
}

function getCdnEntry(url) {
    var domain = _cdnDomain(url);
    if (!domain) return null;
    return getCdnHealthAll()[domain] || null;
}

function saveCdnEntry(url, entry) {
    var domain = _cdnDomain(url);
    if (!domain) return;
    try {
        var health = getCdnHealthAll();
        health[domain] = entry;
        localStorage.setItem('cdn_health', JSON.stringify(health));
    } catch (e) { /* localStorage 不可用时静默降级 */ }
}

function getCooldownMs(step) {
    var ms = CDN_BASE_COOLDOWN_MS * Math.pow(CDN_COOLDOWN_MULTIPLIER, step);
    return Math.min(ms, CDN_MAX_COOLDOWN_MS);
}

function shouldTryCdn(url) {
    if (url.indexOf('http') !== 0) return true; // 本地始终尝试
    var entry = getCdnEntry(url);
    if (!entry) return true; // 无记录，首次尝试

    switch (entry.state) {
        case 'open':
            // 冷却期到 → 转为 half-open，允许试探
            if (Date.now() >= entry.cooldownUntil) {
                entry.state = 'half-open';
                saveCdnEntry(url, entry);
                return true;
            }
            return false;
        default: // closed / half-open / 未知状态
            return true;
    }
}

function recordCdnSuccess(url) {
    if (url.indexOf('http') !== 0) return;
    var entry = getCdnEntry(url);
    if (!entry) return; // 无记录说明本来就没问题
    entry.state = 'closed';
    entry.consecutiveFails = 0;
    entry.cooldownStep = 0;
    entry.cooldownUntil = 0;
    saveCdnEntry(url, entry);
}

function recordCdnFailure(url) {
    if (url.indexOf('http') !== 0) return;
    var entry = getCdnEntry(url);
    if (!entry) entry = { state: 'closed', consecutiveFails: 0, cooldownUntil: 0, cooldownStep: 0 };

    entry.consecutiveFails++;

    if (entry.state === 'half-open') {
        // 探测失败 → 回到 OPEN，退避翻倍
        entry.state = 'open';
        entry.cooldownStep = Math.min(entry.cooldownStep + 1, 5);
        entry.cooldownUntil = Date.now() + getCooldownMs(entry.cooldownStep);
    } else if (entry.consecutiveFails >= CDN_FAILURE_THRESHOLD) {
        // 连续失败达阈值 → 熔断
        entry.state = 'open';
        entry.cooldownStep = 0;
        entry.cooldownUntil = Date.now() + getCooldownMs(0);
    }
    saveCdnEntry(url, entry);
}

var CDN_LIBS = {
    qrcode: [
        'https://cdn.bootcdn.net/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
        'https://cdn.staticfile.org/qrcodejs/1.0.0/qrcode.min.js',
        'assets/vendor/qrcode.min.js'
    ],
    crypto: [
        'https://cdn.bootcdn.net/ajax/libs/crypto-js/4.1.1/crypto-js.min.js',
        'https://cdn.staticfile.org/crypto-js/4.1.1/crypto-js.min.js',
        'assets/vendor/crypto-js.min.js'
    ],
    highlight: [
        'https://cdn.bootcdn.net/ajax/libs/highlight.js/11.9.0/highlight.min.js',
        'https://cdn.staticfile.org/highlight.js/11.9.0/highlight.min.js',
        'assets/vendor/highlight.min.js'
    ],
    xlsx: [
        'https://cdn.bootcdn.net/ajax/libs/xlsx/0.18.5/xlsx.full.min.js',
        'https://cdn.staticfile.org/xlsx/0.18.5/xlsx.full.min.js',
        'assets/vendor/xlsx.full.min.js'
    ],
    jszip: [
        'https://cdn.bootcdn.net/ajax/libs/jszip/3.10.1/jszip.min.js',
        'https://cdn.staticfile.org/jszip/3.10.1/jszip.min.js',
        'assets/vendor/jszip.min.js'
    ],
    docx: [
        'https://cdn.jsdelivr.net/npm/docx-preview@0.4.0/dist/docx-preview.min.js',
        'https://unpkg.com/docx-preview@0.4.0/dist/docx-preview.min.js',
        'assets/vendor/docx-preview.min.js'
    ],
    marked: [
        'https://cdn.bootcdn.net/ajax/libs/marked/9.1.6/marked.min.js?v=9.1.6',
        'https://cdn.staticfile.org/marked/9.1.6/marked.min.js?v=9.1.6',
        'https://cdn.jsdelivr.net/npm/marked@9.1.6/marked.min.js?v=9.1.6',
        'assets/vendor/marked.min.js'
    ],
    markedFootnote: [
        'https://cdn.jsdelivr.net/npm/marked-footnote@1.2.2/dist/index.umd.min.js?v=1.2.2',
        'assets/vendor/marked-footnote.min.js'
    ],
    markedEmoji: [
        'https://cdn.jsdelivr.net/npm/marked-emoji@1.4.3/lib/index.umd.min.js?v=1.4.3',
        'assets/vendor/marked-emoji.min.js'
    ],
    markedAlert: [
        'https://cdn.jsdelivr.net/npm/marked-alert@2.1.2/dist/index.umd.min.js?v=2.1.2',
        'assets/vendor/marked-alert.min.js'
    ],
    katex: [
        'https://cdn.bootcdn.net/ajax/libs/KaTeX/0.16.9/katex.min.js?v=0.16.9',
        'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js?v=0.16.9',
        'assets/vendor/katex.min.js'
    ],
    mermaid: [
        'https://cdn.bootcdn.net/ajax/libs/mermaid/10.6.1/mermaid.min.js?v=10.6.1',
        'https://cdn.jsdelivr.net/npm/mermaid@10.6.1/dist/mermaid.min.js?v=10.6.1',
        'assets/vendor/mermaid.min.js'
    ]
};

var loadedLibs = {};

// 各库加载完成后应暴露的全局对象/方法校验
var LIB_GLOBALS = {
    qrcode: function() { return typeof QRCode !== 'undefined'; },
    crypto: function() { return typeof CryptoJS !== 'undefined'; },
    highlight: function() { return typeof hljs !== 'undefined'; },
    xlsx: function() { return typeof XLSX !== 'undefined'; },
    jszip: function() { return typeof JSZip !== 'undefined'; },
    docx: function() { return typeof docx !== 'undefined' && typeof docx.renderAsync === 'function'; },
    marked: function() { return typeof marked !== 'undefined'; },
    markedFootnote: function() { return typeof markedFootnote !== 'undefined'; },
    markedEmoji: function() { return typeof markedEmoji !== 'undefined'; },
    markedAlert: function() { return typeof markedAlert !== 'undefined'; },
    katex: function() { return typeof katex !== 'undefined'; },
    mermaid: function() { return typeof mermaid !== 'undefined'; }
};

function loadScript(libName) {
    if (loadedLibs[libName]) {
        return Promise.resolve();
    }
    var urls = CDN_LIBS[libName];
    if (!urls || urls.length === 0) {
        return Promise.reject(new Error('未知的库: ' + libName));
    }
    var checkGlobal = LIB_GLOBALS[libName];
    // 逐个尝试 URL，失败或超时后自动切换备用源
    function tryUrl(index) {
        return new Promise(function(resolve, reject) {
            if (index >= urls.length) {
                reject(new Error('加载 ' + libName + ' 失败（所有源均不可用）'));
                return;
            }
            var url = urls[index];

            // 熔断器检查：不健康的 CDN 直接跳过
            if (!shouldTryCdn(url)) {
                tryUrl(index + 1).then(resolve, reject);
                return;
            }

            var isLocal = url.indexOf('http') !== 0;
            // CDN 超时 8 秒，本地超时 3 秒
            var timeoutMs = isLocal ? 3000 : 8000;
            var settled = false;
            var script = document.createElement('script');
            script.src = url;

            var timer = setTimeout(function() {
                if (settled) return;
                settled = true;
                script.remove();
                recordCdnFailure(url);
                tryUrl(index + 1).then(resolve, reject);
            }, timeoutMs);

            script.onload = function() {
                if (settled) return;
                // 脚本标签加载不代表库已初始化，校验全局对象避免 404 页面或执行异常被误判为成功
                if (!checkGlobal || checkGlobal()) {
                    settled = true;
                    clearTimeout(timer);
                    recordCdnSuccess(url);
                    loadedLibs[libName] = true;
                    resolve();
                } else {
                    settled = true;
                    clearTimeout(timer);
                    script.remove();
                    recordCdnFailure(url);
                    tryUrl(index + 1).then(resolve, reject);
                }
            };
            script.onerror = function() {
                if (settled) return;
                settled = true;
                clearTimeout(timer);
                script.remove();
                recordCdnFailure(url);
                tryUrl(index + 1).then(resolve, reject);
            };
            document.head.appendChild(script);
        });
    }
    return tryUrl(0);
}

function loadScripts(libNames) {
    var promises = libNames.map(function(name) { return loadScript(name); });
    return Promise.all(promises);
}

function loadHighlightCSS() {
    if (document.getElementById('hljs-css')) return;
    var candidates = [
        'https://cdn.bootcdn.net/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css',
        'https://cdn.staticfile.org/highlight.js/11.9.0/styles/atom-one-dark.min.css',
        'assets/vendor/atom-one-dark.min.css'
    ];
    var link = document.createElement('link');
    link.id = 'hljs-css';
    link.rel = 'stylesheet';
    var timer = null;

    function tryCandidate(index) {
        if (index >= candidates.length) return;
        var url = candidates[index];
        if (!shouldTryCdn(url)) { tryCandidate(index + 1); return; }
        var done = false;
        link.href = url;
        var isLocal = url.indexOf('http') !== 0;
        if (timer) clearTimeout(timer);
        timer = setTimeout(function() {
            if (done) return;
            done = true;
            recordCdnFailure(url);
            tryCandidate(index + 1);
        }, isLocal ? 3000 : 8000);
        link.onerror = function() {
            if (done) return;
            done = true;
            clearTimeout(timer);
            recordCdnFailure(url);
            tryCandidate(index + 1);
        };
        link.onload = function() {
            if (done) return;
            done = true;
            clearTimeout(timer);
            recordCdnSuccess(url);
            link.onload = null;
            link.onerror = null;
        };
    }
    tryCandidate(0);
    document.head.appendChild(link);
}

function loadKatexCSS() {
    if (document.getElementById('katex-css')) return;
    var candidates = [
        'https://cdn.bootcdn.net/ajax/libs/KaTeX/0.16.9/katex.min.css',
        'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css',
        'assets/vendor/katex.min.css'
    ];
    var link = document.createElement('link');
    link.id = 'katex-css';
    link.rel = 'stylesheet';
    var timer = null;

    function tryCandidate(index) {
        if (index >= candidates.length) return;
        var url = candidates[index];
        if (!shouldTryCdn(url)) { tryCandidate(index + 1); return; }
        var done = false;
        link.href = url;
        var isLocal = url.indexOf('http') !== 0;
        if (timer) clearTimeout(timer);
        timer = setTimeout(function() {
            if (done) return;
            done = true;
            recordCdnFailure(url);
            tryCandidate(index + 1);
        }, isLocal ? 3000 : 8000);
        link.onerror = function() {
            if (done) return;
            done = true;
            clearTimeout(timer);
            recordCdnFailure(url);
            tryCandidate(index + 1);
        };
        link.onload = function() {
            if (done) return;
            done = true;
            clearTimeout(timer);
            recordCdnSuccess(url);
            link.onload = null;
            link.onerror = null;
        };
    }
    tryCandidate(0);
    document.head.appendChild(link);
}

function api(actionOrOpts, data, method, abortSignal) {
    var action, opts;
    if (typeof actionOrOpts === 'object' && actionOrOpts !== null && !Array.isArray(actionOrOpts)) {
        opts = actionOrOpts;
        action = opts.action;
        data = opts.data || {};
        method = opts.method || 'POST';
        abortSignal = opts.signal || null;
    } else {
        action = actionOrOpts;
        if (data === undefined) data = {};
        if (method === undefined) method = 'POST';
    }
    var isGet = method === 'GET';
    var url = 'index.php?action=' + encodeURIComponent(action);
    var options = {
        method: method,
        credentials: 'same-origin',
        headers: {
            'X-CSRF-TOKEN': APP_CONFIG.csrfToken
        }
    };
    if (abortSignal) {
        options.signal = abortSignal;
    }

    if (isGet) {
        var params = new URLSearchParams(data);
        url += '&' + params.toString();
    } else {
        if (data instanceof FormData) {
            options.body = data;
        } else {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(Object.assign({}, data, {_csrf_token: APP_CONFIG.csrfToken}));
        }
    }

    return fetch(url, options).then(function (r) {
        if (r.status === 401) {
            window.location.href = 'index.php?page=login';
            throw new Error('\u672a\u767b\u5f55');
        }
        var contentType = r.headers.get('content-type');
        if (contentType && contentType.includes('application/json')) {
            return r.json();
        }
        return r;
    });
}

function showToast(message, type) {
    if (type === undefined) type = 'info';
    var container = document.getElementById('toastContainer');
    var toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    var icons = { info: 'fa-info-circle', success: 'fa-check-circle', error: 'fa-times-circle', warning: 'fa-exclamation-triangle' };
    toast.innerHTML = '<i class="fas ' + (icons[type] || icons.info) + '"></i> ' + escapeHtml(message);
    container.appendChild(toast);
    setTimeout(function() { toast.classList.add('show'); }, 10);
    setTimeout(function() {
        toast.classList.remove('show');
        setTimeout(function() { toast.remove(); }, 400);
    }, 3000);
}

function showModal(title, body, options) {
    var opts = options || {};
    var overlay = document.getElementById('modalOverlay');
    var titleEl = document.getElementById('modalTitle');
    var bodyEl = document.getElementById('modalBody');
    titleEl.textContent = title;
    if (opts.html === true) {
        bodyEl.innerHTML = body;
    } else {
        bodyEl.textContent = body;
    }
    if (!overlay.getAttribute('role')) {
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
    }
    overlay.classList.add('active');
}

function closeModal() {
    document.getElementById('modalOverlay').classList.remove('active');
}

function showLicense() {
    api('license', {}, 'GET')
        .then(function(d) {
            if (d.success) {
                showModal('许可协议', '<pre style="font-size:12px;line-height:1.6;max-height:60vh;overflow-y:auto;white-space:pre-wrap;word-break:break-word;background:var(--bg-secondary);padding:16px;border-radius:8px;margin:0">' + escapeHtml(d.content) + '</pre>', {html: true});
            } else {
                showToast('无法加载许可协议', 'error');
            }
        })
        .catch(function() {
            showToast('无法加载许可协议', 'error');
        });
}

function showConfirm(message, onConfirm, onCancel) {
    var overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.innerHTML =
        '<div class="modal-box glass-strong">' +
            '<div class="modal-header">' +
                '<h3>确认操作</h3>' +
                '<button class="modal-close" data-action="confirm-cancel" aria-label="关闭"><i class="fas fa-times"></i></button>' +
            '</div>' +
            '<div class="modal-body">' +
                '<p style="margin-bottom:24px;color:var(--text-secondary);font-size:15px;line-height:1.7">' + escapeHtml(message) + '</p>' +
                '<div style="display:flex;gap:12px">' +
                    '<button class="btn btn-glass" style="flex:1" data-action="confirm-cancel">取消</button>' +
                    '<button class="btn btn-danger" style="flex:1" data-action="confirm-ok">确定</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);

    var closed = false;
    function close(confirmed) {
        if (closed) return;
        closed = true;
        overlay.classList.remove('active');
        setTimeout(function() { if (overlay.parentNode) overlay.remove(); }, 300);
        try {
            if (confirmed && typeof onConfirm === 'function') onConfirm();
            else if (!confirmed && typeof onCancel === 'function') onCancel();
        } catch (e) {
            console.error('[showConfirm] callback error:', e);
        }
    }

    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) { close(false); return; }
        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        if (btn.dataset.action === 'confirm-cancel') close(false);
        else if (btn.dataset.action === 'confirm-ok') close(true);
    });

    requestAnimationFrame(function() { overlay.classList.add('active'); });

    overlay._close = close;
    return overlay;
}

function closeConfirm(confirmed) {
    var overlays = document.querySelectorAll('.modal-overlay');
    for (var i = 0; i < overlays.length; i++) {
        if (typeof overlays[i]._close === 'function') {
            overlays[i]._close(confirmed);
        }
    }
}

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}

function copyText(text) {
    navigator.clipboard.writeText(text).then(function() { showToast('已复制'); })
        .catch(function() {
            // 降级：兼容旧浏览器或不安全上下文
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showToast('已复制');
        });
}

function formatSize(bytes) {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
    return bytes + ' B';
}
