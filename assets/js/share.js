/**
 * 分享功能 - 分享对话框、下载、二维码
 */

function showShareDialog(fileId) {
    showModal('创建分享链接',
        '<div class="form-group"><label>提取密码（可选）</label><input type="text" id="sharePassword" placeholder="留空则无需密码"></div>' +
        '<div class="form-group"><label>有效期（天）</label><select id="shareExpire" class="sort-select" style="width:100%"><option value="1">1天</option><option value="7" selected>7天</option><option value="30">30天</option><option value="0">永久</option></select></div>' +
        '<div class="form-group"><label>最大下载次数（0为不限）</label><input type="number" id="shareMaxDownloads" value="0" min="0"></div>' +
        '<button class="btn btn-primary" data-action="create-share" data-file-id="' + fileId + '">创建分享</button>',
        {html: true}
    );
}

function createShare(fileId) {
    api('create_share', {
        file_id: fileId,
        password: document.getElementById('sharePassword').value,
        expire_days: document.getElementById('shareExpire').value,
        max_downloads: document.getElementById('shareMaxDownloads').value,
    }).then(function(data) {
        if (data.success) {
            closeModal();
            showModal('分享成功',
                '<div class="share-result">' +
                    '<p>分享链接已创建：</p>' +
                    '<div class="share-url-wrap"><input type="text" id="shareUrlInput" value="' + data.share_url + '" readonly data-action="select-input"><button class="btn btn-primary btn-sm" data-action="copy-share-url">复制</button></div>' +
                    (data.has_password ? '<p class="share-pwd-hint"><i class="fas fa-lock"></i> 已设置提取密码</p>' : '') +
                    '<div class="share-qr-wrap">' +
                        '<div id="shareQrCode"></div>' +
                        '<p class="share-qr-hint"><i class="fas fa-qrcode"></i> 扫码访问</p>' +
                    '</div>' +
                    '<button class="btn btn-glass btn-block" style="margin-top:16px" data-action="close-modal">关闭</button>' +
                '</div>',
                {html: true}
            );
            generateShareQR(data.share_url);
        } else {
            showToast(data.message, 'error');
        }
    });
}

function copyShareUrl() {
    var input = document.getElementById('shareUrlInput');
    input.select();
    navigator.clipboard.writeText(input.value).then(function() { showToast('链接已复制'); });
}

function generateShareQR(url) {
    var container = document.getElementById('shareQrCode');
    if (!container) return;
    
    container.innerHTML = '<div class="loading-indicator"><i class="fas fa-spinner fa-spin"></i><p>正在生成二维码...</p></div>';
    
    loadScript('qrcode').then(function() {
        container.innerHTML = '';
        try {
            new QRCode(container, {
                text: url,
                width: 180,
                height: 180,
                colorDark: '#1e293b',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M,
            });
        } catch (e) {
            container.innerHTML = '<p class="error-message">二维码生成失败</p>';
        }
    }).catch(function() {
        container.innerHTML = '<p class="error-message">二维码组件加载失败</p>';
    });
}

function startDownload(token, password) {
    if (password === undefined) password = '';
    fetch('index.php?action=record_share_visit', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'token=' + encodeURIComponent(token) + '&visit_type=download'
    }).catch(function() {});
    
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'index.php?action=share_download';
    form.target = '_self';
    
    function addHiddenField(name, value) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }
    
    addHiddenField('token', token);
    addHiddenField('password', password);
    addHiddenField('_csrf_token', APP_CONFIG.csrfToken);
    
    document.body.appendChild(form);
    form.submit();
    
    setTimeout(function() {
        if (form.parentNode) {
            form.parentNode.removeChild(form);
        }
    }, 2000);
}

function handleSharePasswordSubmit(e) {
    e.preventDefault();
    
    var token = new URLSearchParams(window.location.search).get('token');
    var password = document.getElementById('sharePassword').value;
    var errorEl = document.getElementById('shareError');
    
    if (!token) {
        if (errorEl) {
            errorEl.textContent = '分享链接无效';
            errorEl.style.display = 'block';
        }
        return false;
    }
    
    if (!password) {
        if (errorEl) {
            errorEl.textContent = '请输入提取密码';
            errorEl.style.display = 'block';
        }
        return false;
    }
    
    startDownload(token, password);
    return false;
}

function downloadShare(token, password) {
    if (password === undefined) password = '';
    startDownload(token, password);
}

function handleShareDownloadWithPassword(e) {
    handleSharePasswordSubmit(e);
}

function showShareQR(shareUrl) {
    if (!shareUrl) {
        showToast('分享链接不可用', 'error');
        return;
    }

    // 颜色统一走 CSS 变量：主题切换时弹窗实时响应，无需 isDark 三元判断。
    // QRCode 库的 colorDark/colorLight 需要 JS 字符串值（canvas 不支持 var()），
    // 用 getComputedStyle 读取 CSS 变量当前值，保证与全局主题同步。
    var bg = 'var(--bg-surface)';
    var fg = 'var(--text-primary)';
    var muted = 'var(--text-muted)';
    var border = 'var(--bg-glass-border)';
    var accent = 'var(--accent-secondary)';
    var inputBg = 'var(--bg-glass)';

    var modal = document.createElement('div');
    modal.className = 'modal-overlay';
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:9999;opacity:1;visibility:visible';
    modal.innerHTML = '<div class="modal-box" style="background:' + bg + ';color:' + fg + ';max-width:400px;width:90%;border-radius:16px;padding:0;overflow:hidden">' +
        '<div class="modal-header" style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid ' + border + '">' +
            '<h3 style="margin:0;font-size:16px;font-weight:600"><i class="fas fa-qrcode" style="color:' + accent + ';margin-right:8px"></i>分享二维码</h3>' +
            '<button class="modal-close" data-action="close-qr-modal" style="width:32px;height:32px;border-radius:8px;border:1px solid ' + border + ';background:transparent;cursor:pointer;font-size:18px;color:' + muted + '">&times;</button>' +
        '</div>' +
        '<div style="padding:24px;display:flex;flex-direction:column;align-items:center">' +
            '<div id="shareQrCodeModal" style="display:flex;justify-content:center;align-items:center;min-height:180px"></div>' +
            '<p style="color:' + muted + ';font-size:12px;margin-top:12px;display:flex;align-items:center;gap:6px"><i class="fas fa-qrcode"></i> 扫码访问</p>' +
            '<div style="display:flex;gap:8px;width:100%;margin-top:16px">' +
                '<input type="text" value="' + escapeHtml(shareUrl) + '" readonly data-action="select-input" style="flex:1;padding:8px 12px;border:1px solid ' + border + ';border-radius:8px;font-size:12px;background:' + inputBg + ';color:' + fg + ';min-width:0">' +
                '<button data-share-url="' + escapeHtml(shareUrl) + '" data-action="copy-qr-url" style="padding:8px 16px;border:1px solid ' + accent + ';background:' + accent + ';color:#fff;border-radius:8px;font-size:12px;cursor:pointer;white-space:nowrap">复制</button>' +
            '</div>' +
        '</div>' +
    '</div>';
    document.body.appendChild(modal);

    modal.addEventListener('click', function(e) {
        if (e.target === modal) { modal.remove(); return; }
        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        if (btn.dataset.action === 'close-qr-modal') { modal.remove(); }
        else if (btn.dataset.action === 'select-input') { btn.select(); }
        else if (btn.dataset.action === 'copy-qr-url') {
            copyText(btn.dataset.shareUrl);
            btn.textContent = '已复制';
            setTimeout(function() { btn.textContent = '复制'; }, 1500);
        }
    });

    var container = document.getElementById('shareQrCodeModal');
    container.innerHTML = '<div class="loading-indicator"><i class="fas fa-spinner fa-spin" style="font-size:24px;color:' + muted + '"></i><p style="color:' + muted + ';margin-top:8px;font-size:13px">正在生成二维码...</p></div>';

    // 读取 CSS 变量当前值供 QRCode 着色（canvas 不支持 var()）
    function readCssVar(name, fallback) {
        var val = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return val || fallback;
    }

    loadScript('qrcode').then(function() {
        container.innerHTML = '';
        try {
            var isDark = document.documentElement.classList.contains('dark');
            new QRCode(container, {
                text: shareUrl,
                width: 180,
                height: 180,
                colorDark: readCssVar('--text-primary', isDark ? '#fafafa' : '#1f2937'),
                colorLight: readCssVar('--bg-surface', isDark ? '#242424' : '#ffffff'),
                correctLevel: QRCode.CorrectLevel.M,
            });
        } catch (e) {
            container.innerHTML = '<p style="color:' + muted + '">二维码生成失败</p>';
        }
    }).catch(function() {
        container.innerHTML = '<p style="color:' + muted + '">二维码组件加载失败</p>';
    });
}
