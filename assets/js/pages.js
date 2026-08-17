/**
 * 功能页面 - 近期访问、收藏、分享列表、回收站、操作日志、系统设置
 */

// 子页面 AbortController 管理
var _pageAbort = {
    recent: null,
    favorites: null,
    shares: null,
    inbox: null,
    trash: null,
    logs: null
};

function _abortPage(pageName) {
    if (_pageAbort[pageName]) {
        _pageAbort[pageName].abort();
        _pageAbort[pageName] = null;
    }
}

function setupPagesDelegation() {
    var recentList = document.getElementById('recentList');
    if (recentList) {
        recentList.addEventListener('click', function (e) {
            var actionBtn = e.target.closest('[data-action]');
            if (actionBtn) {
                var fileId = parseInt(actionBtn.dataset.fileId);
                var action = actionBtn.dataset.action;
                if (action === 'download' && fileId) downloadFile(fileId);
                else if (action === 'delete' && fileId) deleteFile(fileId);
                return;
            }
            var row = e.target.closest('.file-row');
            if (row) {
                var fid = parseInt(row.dataset.id);
                var isDir = row.dataset.isDir === 'true';
                handleFileRowClick(fid, isDir);
            }
        });
        recentList.addEventListener('dblclick', function (e) {
            if (isTouchDevice) return;
            var row = e.target.closest('.file-row');
            if (!row) return;
            var fileId = parseInt(row.dataset.id);
            var isDir = row.dataset.isDir === 'true';
            if (isDir) navigateTo(fileId);
            else previewFile(fileId);
        });
    }

    var favoriteList = document.getElementById('favoriteList');
    if (favoriteList) {
        favoriteList.addEventListener('click', function (e) {
            var row = e.target.closest('.file-row');
            if (row) {
                var fid = parseInt(row.dataset.id);
                handleFileRowClick(fid, true);
            }
        });
        favoriteList.addEventListener('dblclick', function (e) {
            if (isTouchDevice) return;
            var row = e.target.closest('.file-row');
            if (!row) return;
            navigateTo(parseInt(row.dataset.id));
        });
    }

    var shareList = document.getElementById('shareList');
    if (shareList) {
        shareList.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) return;
            var action = btn.dataset.action;
            if (action === 'show-qr') showShareQR(btn.dataset.shareUrl);
            else if (action === 'copy-url') copyText(btn.dataset.shareUrl);
            else if (action === 'toggle-share') toggleShare(parseInt(btn.dataset.shareId));
            else if (action === 'delete-share') deleteShare(parseInt(btn.dataset.shareId));
        });
    }

    var inboxList = document.getElementById('inboxList');
    if (inboxList) {
        inboxList.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) return;
            var action = btn.dataset.action;
            var fileId = parseInt(btn.dataset.fileId);
            if (action === 'download-inbox' && fileId) downloadInboxFile(fileId);
            else if (action === 'move-inbox' && fileId) moveInboxToDisk(fileId);
            else if (action === 'delete-inbox' && fileId) deleteInboxFile(fileId);
        });
    }

    var trashList = document.getElementById('trashList');
    if (trashList) {
        trashList.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) return;
            var action = btn.dataset.action;
            var fileId = parseInt(btn.dataset.fileId);
            if (action === 'restore') restoreFile(fileId);
            else if (action === 'permanent-delete') permanentDelete(fileId);
        });
    }

    var logContainer = document.getElementById('logsContainer');
    if (logContainer) {
        logContainer.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action="toggle-log"]');
            if (btn) {
                var item = btn.closest('.fluent-log-item');
                if (item) toggleLogDetail(item);
            }
        });
    }
}

function loadRecent() {
    _abortPage('recent');
    _pageAbort.recent = new AbortController();
    api('recent_access', {}, 'GET', _pageAbort.recent.signal).then(function(data) {
        var container = document.getElementById('recentList');
        if (data && data.success) {
            if (data.items.length === 0) {
                container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-clock"></i></div><h3>暂无最近访问</h3><p>打开文件后会自动记录</p></div>';
                return;
            }
            var html = '<div class="file-table-header"><div class="col-name">名称</div><div class="col-size">大小</div><div class="col-time">访问时间</div><div class="col-actions">操作</div></div>';
            data.items.forEach(function(f) {
                html += '<div class="file-row" data-id="' + f.file_id + '" data-is-dir="' + (f.is_dir ? 'true' : 'false') + '">' +
                    '<div class="col-name"><div class="file-name-wrap"><span class="file-icon icon-' + f.icon + '">' + getFileIcon(f.icon) + '</span><span class="file-name-text">' + escapeHtml(f.filename) + '</span></div></div>' +
                    '<div class="col-size">' + (f.is_dir ? '-' : f.filesize_formatted) + '</div>' +
                    '<div class="col-time">' + f.accessed_at_formatted + '</div>' +
                    '<div class="col-actions">' +
                        '<button class="btn-icon" style="width:30px;height:30px;font-size:13px" data-action="download" data-file-id="' + f.file_id + '" title="下载"><i class="fas fa-download"></i></button>' +
                        '<button class="btn-icon" style="width:30px;height:30px;font-size:13px" data-action="delete" data-file-id="' + f.file_id + '" title="删除"><i class="fas fa-trash-alt"></i></button>' +
                    '</div>' +
                '</div>';
            });
            container.innerHTML = html;
        } else {
            container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-exclamation-circle"></i></div><h3>加载失败</h3><p>' + escapeHtml((data && data.message) || '未知错误') + '</p></div>';
        }
    }).catch(function(err) {
        if (err.name === 'AbortError') return;
        var container = document.getElementById('recentList');
        if (container) {
            container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-exclamation-circle"></i></div><h3>加载失败</h3><p>' + escapeHtml(err.message || '网络错误') + '</p></div>';
        }
        showToast('操作失败：' + (err.message || err)); console.error(err);
    });
}

function loadStorageInfo() {
    api('storage_info', {}, 'GET').then(function(data) {
        if (data.success) {
            var s = data.storage;
            var storageText = document.getElementById('storageText');
            var storageFill = document.getElementById('storageFill');
            if (storageText) storageText.textContent = s.used_formatted + ' / ' + s.total_formatted;
            if (storageFill) {
                storageFill.style.width = s.percentage + '%';
                storageFill.parentElement.classList.add('storage-updating');
                setTimeout(function() { storageFill.parentElement.classList.remove('storage-updating'); }, 2000);
            }

            var currentStorageLimit = document.getElementById('currentStorageLimit');
            var currentStorageUsed = document.getElementById('currentStorageUsed');
            var currentStorageRemaining = document.getElementById('currentStorageRemaining');
            var lastUpdateTime = document.getElementById('lastUpdateTime');
            if (currentStorageLimit) currentStorageLimit.textContent = s.total_formatted;
            if (currentStorageUsed) currentStorageUsed.textContent = s.used_formatted;
            if (currentStorageRemaining) currentStorageRemaining.textContent = s.available_formatted || s.total_formatted;
            if (lastUpdateTime) lastUpdateTime.textContent = new Date().toLocaleString('zh-CN');
        }
    }).catch(function(err) { showToast('操作失败：' + (err.message || err)); console.error(err); });
}

function loadSettings() {
    loadStorageInfo();
    loadConfig();
    loadCacheInfo();
    loadAIConfig();
    if (typeof loadUpdateConfig === 'function') loadUpdateConfig();
    if (typeof refreshUpdateStatus === 'function') refreshUpdateStatus();
    if (typeof updateThemeModeSelect === 'function') updateThemeModeSelect();
    if (typeof loadAdConfig === 'function') loadAdConfig();
}

function switchSettingsTab(tabName, btn) {
    document.querySelectorAll('.settings-tab-btn').forEach(function(b) { b.classList.remove('active'); });
    document.querySelectorAll('.settings-tab-content').forEach(function(c) { c.classList.remove('active'); });
    btn.classList.add('active');
    var tabMap = {
        'general': 'settingsTabGeneral',
        'upload': 'settingsTabUpload',
        'security': 'settingsTabSecurity',
        'api': 'settingsTabApi',
        'share': 'settingsTabShare',
        'cache': 'settingsTabCache',
        'storage': 'settingsTabStorage',
        'account': 'settingsTabAccount',
        'update': 'settingsTabUpdate',
        'about': 'settingsTabAbout'
    };
    var tabId = tabMap[tabName];
    var tabEl = document.getElementById(tabId);
    if (tabEl) {
        tabEl.classList.add('active');
    }

    if (tabName === 'cache') {
        loadCacheInfo();
    } else if (tabName === 'update') {
        if (typeof loadUpdateConfig === 'function') loadUpdateConfig();
        if (typeof refreshUpdateStatus === 'function') refreshUpdateStatus();
        if (typeof loadUpdateBackups === 'function') loadUpdateBackups();
    }
}

function loadFavorites() {
    _abortPage('favorites');
    _pageAbort.favorites = new AbortController();
    api('get_favorites', {}, 'GET', _pageAbort.favorites.signal).then(function(data) {
        if (data.success) {
            var container = document.getElementById('favoriteList');
            if (data.files.length === 0) {
                container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-star"></i></div><h3>暂无收藏</h3><p>右键点击文件添加到收藏</p></div>';
                return;
            }
            var html = '<div class="file-table-header"><div class="col-name">名称</div><div class="col-size">大小</div><div class="col-time">收藏时间</div></div>';
            data.files.forEach(function(f) {
                html += '<div class="file-row" data-id="' + f.id + '" data-is-dir="' + (f.is_dir ? 'true' : 'false') + '">' +
                    '<div class="col-name"><div class="file-name-wrap"><span class="file-icon icon-' + f.icon + '">' + getFileIcon(f.icon) + '</span><span class="file-name-text">' + escapeHtml(f.filename) + '</span></div></div>' +
                    '<div class="col-size">' + (f.is_dir ? '-' : f.filesize_formatted) + '</div>' +
                    '<div class="col-time">' + f.created_at_formatted + '</div>' +
                '</div>';
            });
            container.innerHTML = html;
        }
    }).catch(function(err) {
        if (err.name === 'AbortError') return;
        showToast('操作失败：' + (err.message || err)); console.error(err);
    });
}

function loadShares() {
    _abortPage('shares');
    _pageAbort.shares = new AbortController();
    api('list_shares', {}, 'GET', _pageAbort.shares.signal).then(function(data) {
        if (data.success) {
            var container = document.getElementById('shareList');
            if (data.shares.length === 0) {
                container.innerHTML = '<div class="fluent-empty-state"><div class="fluent-empty-icon"><i class="fas fa-link"></i></div><h3 class="fluent-empty-title">暂无分享</h3><p class="fluent-empty-desc">右键点击文件创建分享链接</p></div>';
                return;
            }
            var html = '';
            data.shares.forEach(function(s) {
                var iconClass = s.is_dir ? 'fa-folder' : 'fa-file';
                var statusBadge = s.is_expired
                    ? '<span class="fluent-share-badge fluent-badge-expired"><i class="fas fa-clock"></i> 已过期</span>'
                    : (!s.is_active
                        ? '<span class="fluent-share-badge fluent-badge-expired"><i class="fas fa-ban"></i> 已禁用</span>'
                        : '<span class="fluent-share-badge fluent-badge-active"><i class="fas fa-check"></i> 正常</span>');
                var passwordBadge = s.has_password
                    ? '<span class="fluent-share-badge fluent-badge-password"><i class="fas fa-lock"></i> 有密码</span>'
                    : '';

                html += '<div class="fluent-share-list-item ' + (s.is_expired ? 'expired' : '') + ' ' + (!s.is_active ? 'disabled' : '') + '">' +
                    '<div class="fluent-share-item-info">' +
                        '<div class="fluent-share-item-icon">' +
                            '<i class="fas ' + iconClass + '"></i>' +
                        '</div>' +
                        '<div class="fluent-share-item-content">' +
                            '<div class="fluent-share-item-name">' + escapeHtml(s.filename || '已删除') + '</div>' +
                            '<div class="fluent-share-item-meta">' +
                                '<span><i class="fas fa-download" style="margin-right:4px"></i>' + s.download_count + (s.max_downloads > 0 ? ' / ' + s.max_downloads : '') + ' 次</span>' +
                                '<span>有效期至 ' + s.expire_at_formatted + '</span>' +
                                statusBadge +
                                passwordBadge +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="fluent-share-item-actions">' +
                        '<button class="fluent-action-btn" title="二维码" data-action="show-qr" data-share-url="' + escapeHtml(s.share_url) + '">' +
                            '<i class="fas fa-qrcode"></i>' +
                        '</button>' +
                        '<button class="fluent-action-btn" title="复制链接" data-action="copy-url" data-share-url="' + escapeHtml(s.share_url) + '">' +
                            '<i class="fas fa-link"></i>' +
                        '</button>' +
                        '<button class="fluent-action-btn" title="' + (s.is_active ? '禁用' : '启用') + '" data-action="toggle-share" data-share-id="' + s.id + '">' +
                            '<i class="fas ' + (s.is_active ? 'fa-pause' : 'fa-play') + '"></i>' +
                        '</button>' +
                        '<button class="fluent-action-btn danger" title="删除" data-action="delete-share" data-share-id="' + s.id + '">' +
                            '<i class="fas fa-trash"></i>' +
                        '</button>' +
                    '</div>' +
                '</div>';
            });
            container.innerHTML = html;
        }
    }).catch(function(err) {
        if (err.name === 'AbortError') return;
        showToast('操作失败：' + (err.message || err)); console.error(err);
    });
}

function toggleShare(shareId) {
    api('toggle_share', {share_id: shareId}).then(function(data) {
        if (data.success) { loadShares(); showToast(data.message); }
    }).catch(function(err) { showToast('操作失败：' + (err.message || err)); console.error(err); });
}

function deleteShare(shareId) {
    showConfirm('确定要删除此分享吗？', function() {
        api('delete_share', {share_id: shareId}).then(function(data) {
            if (data.success) { loadShares(); showToast('分享已删除'); }
        }).catch(function(err) { showToast('操作失败：' + (err.message || err)); console.error(err); });
    });
}

function loadTrash() {
    _abortPage('trash');
    _pageAbort.trash = new AbortController();
    api('list_trash', {}, 'GET', _pageAbort.trash.signal).then(function(data) {
        if (data.success) {
            var container = document.getElementById('trashList');
            if (data.items.length === 0) {
                container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-trash-alt"></i></div><h3>回收站为空</h3><p>删除的文件会在这里保留30天</p></div>';
                return;
            }
            var html = '';
            data.items.forEach(function(item) {
                html += '<div class="trash-row">' +
                    '<div class="trash-info">' +
                        '<span class="file-icon icon-' + (item.is_dir ? 'folder' : 'file') + '">' + (item.is_dir ? '<i class="fas fa-folder"></i>' : '<i class="fas fa-file"></i>') + '</span>' +
                        '<span style="font-weight:600">' + escapeHtml(item.filename) + '</span>' +
                        '<span class="trash-meta">' + item.filesize_formatted + ' · 删除于 ' + item.deleted_at_formatted + ' · 剩余 ' + item.remaining_days + ' 天</span>' +
                    '</div>' +
                    '<div class="trash-actions">' +
                        '<button class="btn btn-glass btn-sm" data-action="restore" data-file-id="' + item.id + '">恢复</button>' +
                        '<button class="btn btn-danger btn-sm" data-action="permanent-delete" data-file-id="' + item.id + '">永久删除</button>' +
                    '</div>' +
                '</div>';
            });
            container.innerHTML = html;
        }
    }).catch(function(err) {
        if (err.name === 'AbortError') return;
        showToast('操作失败：' + (err.message || err)); console.error(err);
    });
}

function restoreFile(trashId) {
    api('restore', {trash_id: trashId}).then(function(data) {
        if (data.success) { loadTrash(); showToast('文件已恢复'); loadStorageInfo(); }
        else { showToast(data.message, 'error'); }
    }).catch(function(err) { showToast('操作失败：' + (err.message || err)); console.error(err); });
}

function permanentDelete(trashId) {
    showConfirm('永久删除后将无法恢复，确定吗？', function() {
        api('permanent_delete', {trash_id: trashId}).then(function(data) {
            if (data.success) { loadTrash(); showToast('已永久删除'); loadStorageInfo(); }
            else { showToast(data.message, 'error'); }
        }).catch(function(err) { showToast('操作失败：' + (err.message || err)); console.error(err); });
    });
}

function emptyTrash() {
    showConfirm('确定要清空回收站吗？此操作不可恢复！', function() {
        api('empty_trash').then(function(data) {
            if (data.success) { loadTrash(); showToast('回收站已清空'); loadStorageInfo(); }
            else { showToast(data.message, 'error'); }
        }).catch(function(err) { showToast('操作失败：' + (err.message || err)); console.error(err); });
    });
}

function loadOperationLogs(page) {
    if (page === undefined) page = 1;
    _abortPage('logs');
    _pageAbort.logs = new AbortController();
    var filter = (document.getElementById('logFilterCategory') || {}).value || 'all';
    var severity = (document.getElementById('logFilterSeverity') || {}).value || 'all';
    var search = (document.getElementById('logSearchInput') || {}).value || '';
    var dateFrom = (document.getElementById('logDateFrom') || {}).value || '';
    var dateTo = (document.getElementById('logDateTo') || {}).value || '';

    var params = new URLSearchParams({
        page: page,
        category: filter === 'all' ? '' : filter,
        severity: severity === 'all' ? '' : severity,
        keyword: search,
        start_date: dateFrom,
        end_date: dateTo
    });

    api('operation_logs', params, 'GET', _pageAbort.logs.signal).then(function(data) {
        if (data.success) {
            var container = document.getElementById('logsContainer');
            if (data.logs.length === 0) {
                container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-history"></i></div><h3>暂无操作日志</h3><p>您的操作记录将在这里显示</p></div>';
                return;
            }

            var actionInfo = {
                login: { label: '登录', icon: 'fa-sign-in-alt' },
                logout: { label: '登出', icon: 'fa-sign-out-alt' },
                upload: { label: '上传文件', icon: 'fa-cloud-upload-alt' },
                upload_chunk: { label: '分片上传', icon: 'fa-cloud-upload-alt' },
                download: { label: '下载文件', icon: 'fa-cloud-download-alt' },
                download_folder: { label: '下载文件夹', icon: 'fa-cloud-download-alt' },
                delete: { label: '删除文件', icon: 'fa-trash-alt' },
                batch_delete: { label: '批量删除', icon: 'fa-trash-alt' },
                batch_rename: { label: '批量重命名', icon: 'fa-font' },
                toggle_lock: { label: '切换锁定', icon: 'fa-lock' },
                toggle_encryption: { label: '切换加密', icon: 'fa-shield-alt' },
                rename: { label: '重命名文件', icon: 'fa-edit' },
                move: { label: '移动文件', icon: 'fa-arrows-alt' },
                copy: { label: '复制文件', icon: 'fa-copy' },
                create_folder: { label: '创建文件夹', icon: 'fa-folder-plus' },
                toggle_favorite: { label: '切换收藏', icon: 'fa-star' },
                create_share: { label: '创建分享', icon: 'fa-link' },
                delete_share: { label: '删除分享', icon: 'fa-unlink' },
                toggle_share: { label: '切换分享', icon: 'fa-toggle-on' },
                restore: { label: '恢复文件', icon: 'fa-trash-restore' },
                permanent_delete: { label: '永久删除', icon: 'fa-bomb' },
                empty_trash: { label: '清空回收站', icon: 'fa-broom' },
                change_password: { label: '修改密码', icon: 'fa-key' },
                update_profile: { label: '更新资料', icon: 'fa-user-edit' },
                update_config: { label: '更新配置', icon: 'fa-cogs' },
                clear_cache: { label: '清理缓存', icon: 'fa-brush' },
                clear_logs: { label: '清理日志', icon: 'fa-broom' },
                update_tags: { label: '更新标签', icon: 'fa-tags' },
                update_description: { label: '更新描述', icon: 'fa-comment-dots' },
                register: { label: '注册', icon: 'fa-user-plus' }
            };

            var categoryInfo = {
                file: { label: '文件', icon: 'fa-file' },
                auth: { label: '认证', icon: 'fa-shield-alt' },
                share: { label: '分享', icon: 'fa-share-alt' },
                account: { label: '账户', icon: 'fa-user' },
                system: { label: '系统', icon: 'fa-server' },
                other: { label: '其他', icon: 'fa-ellipsis-h' }
            };

            var severityIcon = {
                info: { icon: 'fa-info-circle', color: '#2563eb' },
                warning: { icon: 'fa-exclamation-triangle', color: '#f59e0b' },
                critical: { icon: 'fa-radiation', color: '#ef4444' }
            };

            var groupedByDate = {};
            data.logs.forEach(function(log) {
                var date = new Date(log.created_at * 1000);
                var dateKey = date.toISOString().split('T')[0];
                if (!groupedByDate[dateKey]) {
                    groupedByDate[dateKey] = [];
                }
                groupedByDate[dateKey].push(log);
            });

            var html = '';

            var _entries = Object.entries(groupedByDate);
            for (var _i = 0; _i < _entries.length; _i++) {
                var date = _entries[_i][0];
                var logs = _entries[_i][1];
                var dateObj = new Date(date);
                var today = new Date();
                var yesterday = new Date(today);
                yesterday.setDate(yesterday.getDate() - 1);

                var dateLabel;
                if (date === today.toISOString().split('T')[0]) {
                    dateLabel = '今天';
                } else if (date === yesterday.toISOString().split('T')[0]) {
                    dateLabel = '昨天';
                } else {
                    dateLabel = dateObj.toLocaleDateString('zh-CN', { year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' });
                }

                html += '<div class="fluent-date-header">' +
                    '<span class="fluent-date-header-text">' + dateLabel + '</span>' +
                    '<span class="fluent-date-count">' + logs.length + ' 条记录</span>' +
                '</div>';

                html += '<div class="fluent-logs-list">';
                logs.forEach(function(log, index) {
                    var info = actionInfo[log.action] || { label: log.action, icon: 'fa-circle' };
                    var cat = categoryInfo[log.category || 'other'] || categoryInfo.other;
                    var sev = severityIcon[log.severity || 'info'] || severityIcon.info;
                    var severityClass = 'severity-' + (log.severity || 'info');

                    html += '<div class="fluent-log-item ' + severityClass + '" style="animation-delay: ' + Math.min(index * 0.04, 0.4) + 's">' +
                        '<div class="fluent-log-icon">' +
                            '<i class="fas ' + info.icon + '"></i>' +
                        '</div>' +
                        '<div class="fluent-log-content">' +
                            '<div class="fluent-log-header">' +
                                '<div class="fluent-log-title-row">' +
                                    '<span class="fluent-log-title">' + escapeHtml(info.label) + '</span>' +
                                    '<span class="fluent-log-category category-' + escapeHtml(log.category || 'other') + '">' + escapeHtml(cat.label) + '</span>' +
                                    '<span class="fluent-log-severity severity-dot-' + (log.severity || 'info') + '"></span>' +
                                '</div>' +
                                '<span class="fluent-log-time">' + log.created_at_formatted + '</span>' +
                            '</div>' +
                            '<div class="fluent-log-detail">' + escapeHtml(log.target || log.detail || '无详细信息') + '</div>' +
                            '<div class="fluent-log-meta">' +
                                '<span class="fluent-log-meta-item"><i class="fas fa-globe"></i>' + escapeHtml(log.ip || '未知') + '</span>' +
                                '<span class="fluent-log-meta-item"><i class="fas fa-clock"></i>' + (log.created_at_relative || '') + '</span>' +
                            '</div>' +
                            '<div class="fluent-log-expanded">' +
                                '<div class="fluent-log-expanded-grid">' +
                                    '<div class="fluent-log-expanded-item">' +
                                        '<label><i class="fas fa-calendar-alt"></i>时间</label>' +
                                        '<span>' + log.created_at_formatted + ' (' + (log.created_at_relative || '') + ')</span>' +
                                    '</div>' +
                                    '<div class="fluent-log-expanded-item">' +
                                        '<label><i class="fas fa-globe"></i>IP 地址</label>' +
                                        '<span>' + escapeHtml(log.ip || '未知') + '</span>' +
                                    '</div>' +
                                    '<div class="fluent-log-expanded-item">' +
                                        '<label><i class="fas fa-shield-alt"></i>严重级别</label>' +
                                        '<span>' + escapeHtml(log.severity || 'info') + '</span>' +
                                    '</div>' +
                                    '<div class="fluent-log-expanded-item">' +
                                        '<label><i class="fas fa-folder"></i>操作类别</label>' +
                                        '<span>' + escapeHtml(cat.label) + '</span>' +
                                    '</div>' +
                                    (log.user_agent ? '<div class="fluent-log-expanded-item full-width"><label><i class="fas fa-laptop"></i>用户代理</label><span>' + escapeHtml(log.user_agent) + '</span></div>' : '') +
                                '</div>' +
                            '</div>' +
                            '<button class="fluent-log-expand-btn" data-action="toggle-log">' +
                                '<i class="fas fa-chevron-down"></i>' +
                            '</button>' +
                        '</div>' +
                    '</div>';
                });
                html += '</div>';
            }

            container.innerHTML = html;
        }
    }).catch(function(err) {
        if (err.name === 'AbortError') return;
        showToast('操作失败：' + (err.message || err)); console.error(err);
    });
}

function toggleLogDetail(row) {
    row.classList.toggle('expanded');
}

function applyLogFilters() {
    loadOperationLogs(1);
}

function resetLogFilters() {
    document.getElementById('logFilterCategory').value = 'all';
    document.getElementById('logFilterSeverity').value = 'all';
    document.getElementById('logSearchInput').value = '';
    document.getElementById('logDateFrom').value = '';
    document.getElementById('logDateTo').value = '';
    loadOperationLogs(1);
}

var logSearchTimeout;
function debounceLogSearch() {
    clearTimeout(logSearchTimeout);
    logSearchTimeout = setTimeout(function() {
        loadOperationLogs(1);
    }, 500);
}

function toggleLogStatistics() {
    var panel = document.getElementById('logStatisticsPanel');
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        loadLogStatistics();
    } else {
        panel.style.display = 'none';
    }
}

function loadLogStatistics() {
    api('log_statistics', { days: 7 }, 'GET').then(function(data) {
        if (data.success) {
            var grid = document.getElementById('statsGrid');

            var html = '' +
                '<div class="stat-card">' +
                    '<div class="stat-value">' + (data.total || 0) + '</div>' +
                    '<div class="stat-label">总操作数</div>' +
                '</div>' +
                '<div class="stat-card">' +
                    '<div class="stat-value">' + (data.recent || 0) + '</div>' +
                    '<div class="stat-label">近 7 天操作数</div>' +
                '</div>';

            if (data.by_severity && data.by_severity.length > 0) {
                data.by_severity.forEach(function(s) {
                    html += '<div class="stat-card severity-' + s.severity + '">' +
                        '<div class="stat-value">' + s.count + '</div>' +
                        '<div class="stat-label">' + escapeHtml(s.severity.toUpperCase()) + ' 操作</div>' +
                    '</div>';
                });
            }

            if (data.by_category && data.by_category.length > 0) {
                data.by_category.slice(0, 4).forEach(function(s) {
                    var categoryLabels = { file: '文件', auth: '认证', share: '分享', account: '账户', system: '系统', other: '其他' };
                    html += '<div class="stat-card">' +
                        '<div class="stat-value">' + s.count + '</div>' +
                        '<div class="stat-label">' + (categoryLabels[s.category] || escapeHtml(s.category)) + ' 操作</div>' +
                    '</div>';
                });
            }

            grid.innerHTML = html;
        }
    }).catch(function(err) { showToast('操作失败：' + (err.message || err)); console.error(err); });
}

function exportLogs() {
    var filter = (document.getElementById('logFilterCategory') || {}).value || 'all';
    var severity = (document.getElementById('logFilterSeverity') || {}).value || 'all';
    var search = (document.getElementById('logSearchInput') || {}).value || '';
    var dateFrom = (document.getElementById('logDateFrom') || {}).value || '';
    var dateTo = (document.getElementById('logDateTo') || {}).value || '';

    var params = new URLSearchParams({
        page: 1,
        page_size: 200,
        category: filter === 'all' ? '' : filter,
        severity: severity === 'all' ? '' : severity,
        keyword: search,
        start_date: dateFrom,
        end_date: dateTo
    });

    api('operation_logs', params, 'GET').then(function(data) {
        if (data.success && data.logs.length > 0) {
            var csv = '时间,操作,类别,严重级别,目标,IP,详情,用户代理\n';
            data.logs.forEach(function(log) {
                csv += '"' + log.created_at_formatted + '","' + log.action + '","' + (log.category || '') + '","' + (log.severity || 'info') + '","' + (log.target || '').replace(/"/g, '""') + '","' + (log.ip || '') + '","' + (log.detail || '').replace(/"/g, '""') + '","' + (log.user_agent || '').replace(/"/g, '""') + '"\n';
            });

            var blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
            var url = URL.createObjectURL(blob);
            var link = document.createElement('a');
            link.href = url;
            link.download = 'operation_logs_' + new Date().toISOString().split('T')[0] + '.csv';
            link.click();
            URL.revokeObjectURL(url);
            showToast('日志已导出', 'success');
        } else {
            showToast('没有可导出的日志', 'error');
        }
    }).catch(function(err) { showToast('操作失败：' + (err.message || err)); console.error(err); });
}

function clearLogs() {
    showConfirm('确定要清理所有操作日志吗？此操作不可撤销！', function() {
        api('clear_logs', {}).then(function(data) {
            if (data.success) {
                showToast(data.message, 'success');
                loadOperationLogs();
            } else {
                showToast(data.message, 'error');
            }
        }).catch(function(err) { showToast('操作失败：' + (err.message || err)); console.error(err); });
    });
}

function loadConfig() {
    api('get_config', {}, 'GET').then(function(data) {
        if (data.success) {
            var c = data.config;
            document.getElementById('cfg_app_name').value = c.app_name || '';
            document.getElementById('cfg_session_lifetime').value = c.session_lifetime || 7200;
            document.getElementById('cfg_max_upload_size').value = c.max_upload_size || 524288000;
            document.getElementById('cfg_chunk_size').value = c.chunk_size || 5242880;
            document.getElementById('cfg_trash_retention_days').value = c.trash_retention_days || 30;
            document.getElementById('cfg_thumbnail_size').value = c.thumbnail_size || 64;
            document.getElementById('cfg_share_default_expire').value = (c.share_default_expire || 604800) / 86400;
            document.getElementById('cfg_share_link_length').value = c.share_link_length || 12;
            document.getElementById('cfg_login_max_attempts').value = c.login_max_attempts || 5;
            document.getElementById('cfg_login_lockout_time').value = c.login_lockout_time || 900;
            document.getElementById('cfg_password_min_length').value = c.password_min_length || 8;
            document.getElementById('cfg_download_rate_limit').value = c.download_rate_limit || 30;
            document.getElementById('cfg_download_rate_window').value = c.download_rate_window || 60;
            document.getElementById('cfg_delete_rate_limit').value = c.delete_rate_limit || 20;
            document.getElementById('cfg_delete_rate_window').value = c.delete_rate_window || 60;
            document.getElementById('cfg_blocked_extensions').value = (c.blocked_extensions || []).join(' ');
            document.getElementById('cfg_api_rate_limit').value = c.api_rate_limit || 60;
            document.getElementById('cfg_api_rate_window').value = c.api_rate_window || 60;
            var apiEnabled = document.getElementById('cfg_api_enabled');
            if (apiEnabled) {
                apiEnabled.checked = c.api_enabled === true || c.api_enabled === '1' || c.api_enabled === 1;
                updateApiSections(apiEnabled.checked);
            }

            // 文件信箱设置
            var inboxEnabled = c.inbox_enabled === true || c.inbox_enabled === '1' || c.inbox_enabled === 1;
            var inboxCheckbox = document.getElementById('cfg_inbox_enabled');
            if (inboxCheckbox) inboxCheckbox.checked = inboxEnabled;
            var inboxUrlInput = document.getElementById('cfg_inbox_url');
            var inboxCopyBtn = document.getElementById('cfgInboxCopyBtn');
            if (inboxUrlInput && c.inbox_url) inboxUrlInput.value = c.inbox_url;
            if (inboxCopyBtn) inboxCopyBtn.style.display = (inboxEnabled && inboxUrlInput && inboxUrlInput.value) ? '' : 'none';
            toggleInboxNav(inboxEnabled);
        }
    }).catch(function(err) { showToast('操作失败：' + (err.message || err)); console.error(err); });
}

function saveConfig() {
    if (APP_CONFIG.debug) {
        console.log('[DEBUG] saveConfig called');
    }

    var data = {
        app_name: document.getElementById('cfg_app_name').value,
        debug: document.getElementById('cfg_debug').checked,
        session_lifetime: document.getElementById('cfg_session_lifetime').value,
        max_upload_size: document.getElementById('cfg_max_upload_size').value,
        chunk_size: document.getElementById('cfg_chunk_size').value,
        trash_retention_days: document.getElementById('cfg_trash_retention_days').value,
        thumbnail_size: document.getElementById('cfg_thumbnail_size').value,
        share_default_expire: document.getElementById('cfg_share_default_expire').value * 86400,
        share_link_length: document.getElementById('cfg_share_link_length').value,
        password_min_length: document.getElementById('cfg_password_min_length').value,
        login_max_attempts: document.getElementById('cfg_login_max_attempts').value,
        login_lockout_time: document.getElementById('cfg_login_lockout_time').value,
        download_rate_limit: document.getElementById('cfg_download_rate_limit').value,
        download_rate_window: document.getElementById('cfg_download_rate_window').value,
        delete_rate_limit: document.getElementById('cfg_delete_rate_limit').value,
        delete_rate_window: document.getElementById('cfg_delete_rate_window').value,
    };

    if (APP_CONFIG.debug) {
        console.log('[DEBUG] saveConfig data:', data);
    }

    api('update_config', data).then(function(res) {
        if (APP_CONFIG.debug) {
            console.log('[DEBUG] saveConfig response:', res);
        }
        if (res.success) {
            showToast('设置已保存');
        } else {
            showToast(res.message || '保存失败', 'error');
        }
    });
}

function saveBlockedExtensions() {
    var blocked = document.getElementById('cfg_blocked_extensions').value.trim().split(/\s+/).filter(function(e) { return e; });
    var password = document.getElementById('cfg_blocked_password').value;
    if (!password) {
        showToast('请输入当前密码确认身份', 'warning');
        return;
    }
    api('update_config', {
        blocked_extensions: blocked,
        _password: password
    }).then(function(res) {
        if (res.success) {
            showToast('黑名单已更新');
            document.getElementById('cfg_blocked_password').value = '';
        } else {
            showToast(res.message || '保存失败', 'error');
        }
    });
}

function loadCacheInfo() {
    var dirs = [
        { dir: 'thumbnails', id: 'thumbCacheSize' },
        { dir: 'covers', id: 'coverCacheSize' }
    ];

    dirs.forEach(function(d) {
        api('get_cache_size', {dir: d.dir})
            .then(function(data) {
                var el = document.getElementById(d.id);
                if (el && data.size) {
                    el.textContent = data.size;
                } else if (el) {
                    el.textContent = '0 B';
                }
            })
            .catch(function(err) {
                var el = document.getElementById(d.id);
                if (el) el.textContent = '加载失败';
            });
    });
}

function clearCache(type) {
    showConfirm('确定要清理此缓存吗？', function() {
        api('clear_cache', {type: type}).then(function(data) {
            if (data.success) {
                showToast(data.message);
                loadCacheInfo();
            } else {
                showToast(data.message || '清理失败', 'error');
            }
        });
    });
}

function clearAllCache() {
    showConfirm('确定要清理所有缓存吗？', function() {
        api('clear_cache').then(function(data) {
            if (data.success) {
                showToast(data.message);
                loadCacheInfo();
            } else {
                showToast(data.message || '清理失败', 'error');
            }
        });
    });
}

function loadDiskInfo() {
    api('get_disk_info', {}, 'GET')
        .then(function(data) {
            if (data.success) {
                var disk = data.disk;
                document.getElementById('storageTotalSpace').textContent = disk.total_formatted;
                document.getElementById('storageFreeSpace').textContent = disk.free_formatted;
                document.getElementById('storageUsagePercent').textContent = disk.usage_percentage + '%';

                if (disk.reserve_mb) {
                    document.getElementById('cfg_storage_reserve_mb').value = disk.reserve_mb;
                }
                if (disk.update_threshold) {
                    document.getElementById('cfg_storage_update_threshold').value = disk.update_threshold;
                }
            }
        })
        .catch(function() {
            showToast('获取磁盘信息失败', 'error');
        });
}

function saveStorageSettings() {
    var reserveMb = document.getElementById('cfg_storage_reserve_mb').value;
    var threshold = document.getElementById('cfg_storage_update_threshold').value;

    if (reserveMb < 100 || reserveMb > 10240) {
        showToast('预留空间应为 100-10240 MB', 'error');
        return;
    }
    if (threshold < 0.1 || threshold > 50) {
        showToast('更新阈值应为 0.1-50%', 'error');
        return;
    }

    api('update_storage_settings', {
        storage_reserve_mb: reserveMb,
        storage_update_threshold: threshold
    }).then(function(res) {
        if (res.success) {
            showToast(res.message);
            loadDiskInfo();
        } else {
            showToast(res.message || '保存失败', 'error');
        }
    });
}

function manualUpdateStorage() {
    showConfirm('确定要根据当前磁盘状态重新计算并更新存储限额吗？', function() {
        api('manual_update_storage').then(function(res) {
            if (res.success) {
                showToast(res.message);
                loadStorageInfo();
                loadDiskInfo();
            } else {
                showToast(res.message || '更新失败', 'error');
            }
        });
    });
}

function applyDefaultStorage() {
    showConfirm('确定要恢复默认 10GB 存储限额吗？', function() {
        api('update_storage_settings', {
            storage_reserve_mb: 500,
            storage_update_threshold: 1,
            reset_to_default: true,
            confirm: 1
        }).then(function(res) {
            if (res.success) {
                showToast(res.message);
                loadStorageInfo();
                loadDiskInfo();
            } else {
                showToast(res.message || '操作失败', 'error');
            }
        });
    });
}

function exportSettings() {
    window.location.href = 'index.php?action=export_settings';
}

function importSettings() {
    var input = document.createElement('input');
    input.type = 'file';
    input.accept = '.json';
    input.onchange = function() {
        var file = input.files[0];
        if (!file) return;
        var formData = new FormData();
        formData.append('config_file', file);
        formData.append('_csrf_token', APP_CONFIG.csrfToken);
        showToast('正在导入设置...', 'info');
        fetch('index.php?action=import_config', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': APP_CONFIG.csrfToken },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showToast(data.message, 'success');
                loadConfig();
            } else {
                showToast(data.message || '导入失败', 'error');
            }
        })
        .catch(function() { showToast('导入失败，请重试', 'error'); });
    };
    input.click();
}

function updateProfile(e) {
    e.preventDefault();
    var email = document.getElementById('settingEmail').value;
    api('update_profile', { email: email }).then(function(data) {
        if (data.success) {
            showToast(data.message || '资料已更新');
        } else {
            showToast(data.message || '更新失败', 'error');
        }
    });
    return false;
}

function changePassword(e) {
    e.preventDefault();
    var oldPwd = document.getElementById('oldPassword').value;
    var newPwd = document.getElementById('newPassword').value;
    var confirmPwd = document.getElementById('confirmPassword').value;
    if (newPwd !== confirmPwd) {
        showToast('两次输入的密码不一致', 'error');
        return false;
    }
    api('change_password', {
        old_password: oldPwd,
        new_password: newPwd,
        confirm_password: confirmPwd
    }).then(function(data) {
        if (data.success) {
            showToast(data.message || '密码已修改');
            document.getElementById('passwordForm').reset();
        } else {
            showToast(data.message || '修改失败', 'error');
        }
    });
    return false;
}

function handleLogout() {
    showConfirm('确定要退出登录吗？', function() {
        api('logout').then(function(data) {
            window.location.href = 'index.php';
        });
    });
}

// ── 开放 API 管理 ──

function updateApiSections(enabled) {
    var sections = ['apiTokenSection', 'apiRateSection', 'apiDocSection'];
    sections.forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = enabled ? '' : 'none';
    });
    var revokeBtn = document.getElementById('revokeTokenBtn');
    if (revokeBtn) revokeBtn.style.display = 'none';
    var tokenDisplay = document.getElementById('apiTokenDisplay');
    if (tokenDisplay) tokenDisplay.style.display = 'none';
}

function toggleApi(btn) {
    var enabled = document.getElementById('cfg_api_enabled').checked;
    if (enabled) {
        showConfirm('开启 API 功能会降低安全性（外部可通过 Token 访问网盘）。确认开启？', function() {
            api('api_toggle', { enabled: true }).then(function(data) {
                if (data.success) {
                    showToast(data.message);
                    updateApiSections(true);
                } else {
                    showToast(data.message || '操作失败', 'error');
                    document.getElementById('cfg_api_enabled').checked = false;
                }
            });
        });
    } else {
        api('api_toggle', { enabled: false }).then(function(data) {
            if (data.success) {
                showToast(data.message);
                updateApiSections(false);
            } else {
                showToast(data.message || '操作失败', 'error');
                document.getElementById('cfg_api_enabled').checked = true;
            }
        });
    }
}

function generateApiToken() {
    showConfirm('生成新 Token 需要验证当前密码，旧 Token 将立即失效。继续？', function() {
        var password = prompt('请输入当前登录密码：');
        if (!password) return;
        api('api_generate_token', { _password: password }).then(function(data) {
            if (data.success) {
                var tokenDisplay = document.getElementById('apiTokenDisplay');
                var tokenValue = document.getElementById('apiTokenValue');
                tokenValue.textContent = data.token;
                tokenDisplay.style.display = 'block';
                document.getElementById('revokeTokenBtn').style.display = '';
                // 更新状态
                var statusEl = document.getElementById('apiTokenStatus');
                statusEl.innerHTML = '<div class="settings-row"><label>当前状态</label><span style="color:var(--success-color)"><i class="fas fa-check-circle"></i> 已激活</span></div>' +
                    '<div class="settings-row"><label>创建时间</label><span>' + new Date(data.created_at * 1000).toLocaleString() + '</span></div>';
                showToast('Token 已生成，请立即复制保存');
            } else {
                showToast(data.message || '生成失败', 'error');
            }
        });
    });
}

function revokeApiToken() {
    showConfirm('确定要撤销当前 Token？撤销后使用该 Token 的所有程序将无法访问。', function() {
        api('api_revoke_token', {}).then(function(data) {
            if (data.success) {
                showToast('Token 已撤销');
                document.getElementById('apiTokenDisplay').style.display = 'none';
                document.getElementById('revokeTokenBtn').style.display = 'none';
                var statusEl = document.getElementById('apiTokenStatus');
                statusEl.innerHTML = '<div class="settings-row"><label>当前状态</label><span style="color:var(--warning-color)"><i class="fas fa-exclamation-circle"></i> 未生成 Token</span></div>';
            } else {
                showToast(data.message || '撤销失败', 'error');
            }
        });
    });
}

function copyApiToken() {
    var token = document.getElementById('apiTokenValue').textContent;
    copyText(token);
}

function saveApiConfig() {
    var rateLimit = parseInt(document.getElementById('cfg_api_rate_limit').value) || 60;
    var rateWindow = parseInt(document.getElementById('cfg_api_rate_window').value) || 60;
    api('api_update_config', {
        api_rate_limit: rateLimit,
        api_rate_window: rateWindow
    }).then(function(data) {
        if (data.success) {
            showToast('API 配置已保存');
        } else {
            showToast(data.message || '保存失败', 'error');
        }
    });
}

// ── 文件信箱 ──

function toggleInboxNav(enabled) {
    var nav = document.getElementById('navInbox');
    if (!nav) return;
    nav.style.display = enabled ? '' : 'none';
}

function loadInboxBox() {
    _abortPage('inbox');
    _pageAbort.inbox = new AbortController();

    var urlInput = document.getElementById('inboxUrlInput');
    var countEl = document.getElementById('inboxCount');
    var sizeEl = document.getElementById('inboxSize');
    var listEl = document.getElementById('inboxList');

    api('inbox_info', {}, 'GET', _pageAbort.inbox.signal).then(function(data) {
        if (data && data.success) {
            if (urlInput) urlInput.value = data.inbox_url || '';
            if (countEl) countEl.textContent = (data.total_files || 0).toString();
            if (sizeEl) sizeEl.textContent = data.total_size_formatted || '0 B';
            renderInboxList(data.files || []);
        } else {
            if (listEl) {
                listEl.innerHTML = '<div class="inbox-disabled-hint"><i class="fas fa-inbox"></i><h3>文件信箱暂未开启</h3><p>前往「系统设置 → 分享设置」开启后即可收取朋友投递的文件</p></div>';
            }
        }
    }).catch(function(err) {
        if (err.name === 'AbortError') return;
        if (listEl) {
            listEl.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-exclamation-circle"></i></div><h3>加载失败</h3><p>' + escapeHtml(err.message || '网络错误') + '</p></div>';
        }
    });
}

function renderInboxList(files) {
    var container = document.getElementById('inboxList');
    if (!container) return;
    if (files.length === 0) {
        container.innerHTML = '<div class="inbox-empty-state"><div class="inbox-empty-icon"><i class="fas fa-inbox"></i></div><h3>信箱为空</h3><p>将收件链接分享给朋友，他们投递的文件会出现在这里</p></div>';
        return;
    }

    var html = '';
    files.forEach(function(f, index) {
        var icon = getFileIcon(f.icon || 'file');
        var messageHtml = f.sender_message
            ? '<span class="inbox-item-message" title="' + escapeHtml(f.sender_message) + '"><i class="fas fa-comment-alt"></i> ' + escapeHtml(f.sender_message) + '</span>'
            : '';
        html += '<div class="inbox-item" style="animation-delay:' + Math.min(index * 0.05, 0.4) + 's">' +
            '<div class="inbox-item-info">' +
                '<div class="inbox-item-icon">' + icon + '</div>' +
                '<div class="inbox-item-content">' +
                    '<div class="inbox-item-name" title="' + escapeHtml(f.filename) + '">' + escapeHtml(f.filename) + '</div>' +
                    '<div class="inbox-item-meta">' +
                        '<span><i class="fas fa-weight-hanging"></i>' + (f.filesize_formatted || '-') + '</span>' +
                        '<span><i class="fas fa-clock"></i>' + (f.created_at_formatted || '-') + '</span>' +
                        (f.sender_name ? '<span class="inbox-item-sender"><i class="fas fa-user"></i>来自 ' + escapeHtml(f.sender_name) + '</span>' : '') +
                    '</div>' +
                    messageHtml +
                '</div>' +
            '</div>' +
            '<div class="inbox-item-actions">' +
                '<button class="btn btn-glass btn-sm" data-action="download-inbox" data-file-id="' + f.id + '" title="下载"><i class="fas fa-download"></i></button>' +
                '<button class="btn btn-primary btn-sm" data-action="move-inbox" data-file-id="' + f.id + '" title="转存到网盘"><i class="fas fa-folder-open"></i> 转存</button>' +
                '<button class="btn btn-icon" style="width:32px;height:32px;font-size:13px" data-action="delete-inbox" data-file-id="' + f.id + '" title="删除"><i class="fas fa-trash-alt"></i></button>' +
            '</div>' +
        '</div>';
    });
    container.innerHTML = html;
}

function copyInboxUrl() {
    var input = document.getElementById('inboxUrlInput') || document.getElementById('cfg_inbox_url');
    if (!input || !input.value) {
        showToast('暂无可复制的链接', 'warning');
        return;
    }
    copyText(input.value);
}

function regenerateInboxUrl() {
    showConfirm('重新生成链接后，旧链接将立即失效，确定吗？', function() {
        api('inbox_regenerate', {}).then(function(data) {
            if (data.success) {
                var input = document.getElementById('inboxUrlInput');
                if (input && data.inbox_url) input.value = data.inbox_url;
                showToast('收件链接已更新');
            } else {
                showToast(data.message || '操作失败', 'error');
            }
        }).catch(function(err) { showToast('操作失败：' + (err.message || err)); console.error(err); });
    });
}

function downloadInboxFile(fileId) {
    var a = document.createElement('a');
    a.href = 'index.php?action=inbox_download&file_id=' + fileId;
    a.download = '';
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    setTimeout(function() { document.body.removeChild(a); }, 1000);
}

function moveInboxToDisk(fileId) {
    // 复用现有文件夹树选择对话框，仅修改标题与提交行为
    showFileOpDialog('inbox-move', [fileId]);
}

function deleteInboxFile(fileId) {
    showConfirm('确定从信箱中删除此文件吗？删除后无法恢复。', function() {
        api('inbox_delete', { file_id: fileId }).then(function(data) {
            if (data.success) {
                showToast('已删除');
                loadInboxBox();
                loadStorageInfo();
            } else {
                showToast(data.message || '删除失败', 'error');
            }
        }).catch(function(err) { showToast('操作失败：' + (err.message || err)); console.error(err); });
    });
}

function saveInboxSettings() {
    var enabled = document.getElementById('cfg_inbox_enabled').checked;
    api('inbox_toggle', { enabled: enabled }).then(function(data) {
        if (data.success) {
            showToast('信箱设置已保存');
            toggleInboxNav(enabled);
            var urlInput = document.getElementById('cfg_inbox_url');
            var copyBtn = document.getElementById('cfgInboxCopyBtn');
            if (urlInput && data.inbox_url) urlInput.value = data.inbox_url;
            if (copyBtn) copyBtn.style.display = (enabled && urlInput && urlInput.value) ? '' : 'none';
        } else {
            showToast(data.message || '保存失败', 'error');
        }
    }).catch(function(err) { showToast('操作失败：' + (err.message || err)); console.error(err); });
}

// ── 广告管理 ──

function loadAdConfig() {
    api('get_ad_config', {}, 'GET').then(function(data) {
        if (!data || !data.success) return;
        var card = document.getElementById('adSettingsCard');
        if (!card) return;
        card.style.display = '';
        var checkbox = document.getElementById('cfg_ad_enabled');
        if (checkbox) {
            checkbox.checked = !!data.ad_enabled;
            if (!window._adToggleInit) {
                window._adToggleInit = true;
                checkbox.addEventListener('change', function() {
                    toggleAdEnabled(checkbox.checked);
                });
            }
        }
        if (!data.ad_prompt_dismissed) {
            showAdPrompt();
        }
    }).catch(function(err) {
        // 非管理员或后端未实现：保持卡片隐藏，静默处理
        if (typeof APP_CONFIG !== 'undefined' && APP_CONFIG.debug) {
            console.log('[Ad] loadAdConfig skipped:', err);
        }
    });
}

function toggleAdEnabled(enabled) {
    api('toggle_ad_enabled', { enabled: enabled }).then(function(data) {
        if (data && data.success) {
            var checkbox = document.getElementById('cfg_ad_enabled');
            if (checkbox) checkbox.checked = !!data.ad_enabled;
            showToast(data.ad_enabled ? '广告已启用' : '广告已关闭');
        } else {
            showToast((data && data.message) || '操作失败', 'error');
            loadAdConfig();
        }
    }).catch(function(err) {
        showToast('操作失败：' + (err.message || err), 'error');
        loadAdConfig();
    });
}

function dismissAdPrompt() {
    return api('dismiss_ad_prompt', {}).then(function(data) {
        return !!(data && data.success);
    }).catch(function() {
        return false;
    });
}

function showAdPrompt() {
    var overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.innerHTML =
        '<div class="modal-box glass-strong">' +
            '<div class="modal-header">' +
                '<h3><i class="fas fa-mug-hot" style="margin-right:8px;color:var(--accent-secondary)"></i>支持开发者</h3>' +
                '<button class="modal-close" data-action="ad-prompt-dismiss" aria-label="关闭"><i class="fas fa-times"></i></button>' +
            '</div>' +
            '<div class="modal-body">' +
                '<p style="margin-bottom:24px;color:var(--text-secondary);font-size:15px;line-height:1.7">YoliArkCloud 是开源项目，您可以在分享页展示原生广告，请开发者一瓶矿泉水。广告默认关闭，可随时开启或关闭。是否现在启用？</p>' +
                '<div style="display:flex;gap:12px">' +
                    '<button class="btn btn-glass" style="flex:1" data-action="ad-prompt-dismiss">下次再说</button>' +
                    '<button class="btn btn-primary" style="flex:1" data-action="ad-prompt-go-settings">去设置</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    document.body.appendChild(overlay);

    var closed = false;
    function close() {
        if (closed) return;
        closed = true;
        overlay.classList.remove('active');
        setTimeout(function() { if (overlay.parentNode) overlay.remove(); }, 300);
    }

    function goToSettings() {
        close();
        dismissAdPrompt();
        var card = document.getElementById('adSettingsCard');
        if (card) {
            card.style.display = '';
            card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            card.classList.remove('fluent-ad-highlight');
            void card.offsetWidth;
            card.classList.add('fluent-ad-highlight');
        }
    }

    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) { close(); dismissAdPrompt(); return; }
        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        var action = btn.dataset.action;
        if (action === 'ad-prompt-dismiss') { close(); dismissAdPrompt(); }
        else if (action === 'ad-prompt-go-settings') { goToSettings(); }
    });

    requestAnimationFrame(function() { overlay.classList.add('active'); });
}
