/**
 * 系统更新模块 v2 - 配置、检查、应用、进度、回滚、备份、历史
 * 依赖：core.js（api / showToast / showConfirm / escapeHtml / formatSize）、pages.js（switchSettingsTab）
 */

// ===== 常量 =====
var UPDATE_SOURCE_URL = 'https://yoliarkupdate.yoliark.com/';
var UPDATE_GRACE_PERIOD = 30000;   // 更新启动宽限期 30 秒
var UPDATE_POLL_INTERVAL = 2000;   // 进度轮询间隔 2 秒

var PHASE_LABELS = {
    'idle': '空闲', 'checking': '检查更新中', 'downloading': '下载更新包中',
    'verifying': '校验完整性中', 'backing_up': '备份当前版本中',
    'maintenance_on': '启用维护模式中', 'stopping_worker': '停止后台进程中',
    'applying': '应用更新文件中', 'restarting': '重启后台进程中',
    'health_check': '健康检查中', 'rolling_back': '回滚中',
    'completed': '更新完成', 'completed_rolled_back': '已回滚', 'failed': '更新失败'
};

var PHASE_PROGRESS = {
    'idle': 0, 'checking': 5, 'downloading': 20, 'verifying': 35,
    'backing_up': 50, 'maintenance_on': 55, 'stopping_worker': 60,
    'applying': 70, 'restarting': 80, 'health_check': 90,
    'rolling_back': 75, 'completed': 100, 'completed_rolled_back': 100, 'failed': 100
};

var TERMINAL_PHASES = ['idle', 'completed', 'completed_rolled_back', 'failed'];

// ===== 运行时状态 =====
var updateTriggeredAt = 0;       // 更新触发时间戳，用于宽限期判断
var updatePollingTimer = null;   // 轮询定时器
var currentAvailableUpdate = null; // 当前可用更新信息

// ===== 1. 配置加载 =====
function loadUpdateConfig() {
    api('get_update_config', {}, 'GET').then(function (data) {
        if (!data || !data.success) return;
        var c = data.config || {};
        var enabledEl = document.getElementById('cfg_update_enabled');
        if (enabledEl) enabledEl.checked = !!c.update_enabled;
        var channelEl = document.getElementById('cfg_update_channel');
        if (channelEl) channelEl.value = c.update_channel || 'stable';
        var intervalEl = document.getElementById('cfg_update_check_interval');
        if (intervalEl) intervalEl.value = c.check_interval || 3600;
        var strategyEl = document.getElementById('cfg_update_strategy');
        if (strategyEl) strategyEl.value = c.update_strategy || 'notify_only';

        // 更新源地址硬编码不可修改
        var srcEl = document.getElementById('updateSourceUrl');
        if (srcEl) srcEl.value = UPDATE_SOURCE_URL;

        // 版本信息
        if (data.current_version !== undefined) {
            var cvEl = document.getElementById('updateCurrentVersion');
            if (cvEl) cvEl.textContent = data.current_version || '-';
        }
        if (data.latest_version !== undefined) {
            var lvEl = document.getElementById('updateLatestVersion');
            if (lvEl) lvEl.textContent = data.latest_version || '-';
        }
        if (data.last_check_time !== undefined) {
            var lcEl = document.getElementById('updateLastCheckTime');
            if (lcEl) lcEl.textContent = data.last_check_time || '从未检查';
        }
    }).catch(function (err) {
        showToast('加载更新配置失败：' + (err.message || err), 'error');
        console.error('[update] loadUpdateConfig:', err);
    });
}

// ===== 2. 配置保存 =====
function saveUpdateConfig() {
    var interval = parseInt(document.getElementById('cfg_update_check_interval').value, 10) || 0;
    if (interval < 3600) {
        showToast('检查间隔不能小于 3600 秒（1 小时）', 'error');
        return;
    }
    var payload = {
        update_enabled: document.getElementById('cfg_update_enabled').checked,
        update_channel: document.getElementById('cfg_update_channel').value,
        check_interval: interval,
        update_strategy: document.getElementById('cfg_update_strategy').value
    };
    api('save_update_config', payload).then(function (res) {
        if (res && res.success) {
            showToast('更新配置已保存');
        } else {
            showToast((res && res.message) || '保存失败', 'error');
        }
    }).catch(function (err) {
        showToast('保存失败：' + (err.message || err), 'error');
        console.error('[update] saveUpdateConfig:', err);
    });
}

// ===== 3. 检查更新 =====
function checkUpdateNow() {
    showToast('正在检查更新...', 'info');
    api('check_update', {}).then(function (data) {
        if (!data || !data.success) {
            showToast((data && data.message) || '检查更新失败', 'error');
            return;
        }
        // 更新版本信息
        if (data.latest_version !== undefined) {
            var lvEl = document.getElementById('updateLatestVersion');
            if (lvEl) lvEl.textContent = data.latest_version || '-';
        }
        if (data.last_check_time !== undefined) {
            var lcEl = document.getElementById('updateLastCheckTime');
            if (lcEl) lcEl.textContent = data.last_check_time || '从未检查';
        }
        if (data.has_update && data.update_info) {
            currentAvailableUpdate = data.update_info;
            renderAvailableUpdate(data.update_info);
            showToast('发现新版本：' + (data.update_info.version || ''), 'success');
        } else {
            var card = document.getElementById('updateAvailableCard');
            if (card) card.style.display = 'none';
            currentAvailableUpdate = null;
            showToast(data.message || '当前已是最新版本', 'success');
        }
    }).catch(function (err) {
        showToast('检查更新失败：' + (err.message || err), 'error');
        console.error('[update] checkUpdateNow:', err);
    });
}

// ===== 4. 渲染可用更新卡片 =====
function renderAvailableUpdate(info) {
    var card = document.getElementById('updateAvailableCard');
    if (!card || !info) return;
    card.style.display = '';

    var setText = function (id, val) {
        var el = document.getElementById(id);
        if (el) el.textContent = val;
    };
    setText('updateAvailableVersion', info.version || '-');
    setText('updateAvailableSize', info.size_formatted || (info.size ? formatSize(info.size) : '-'));
    setText('updateAvailableTime', info.release_time || info.release_at || '-');
    setText('updateAvailablePhp', info.php_requirement || '无特殊要求');

    // 强制 / 安全更新徽章
    var badgesEl = document.getElementById('updateAvailableBadges');
    if (badgesEl) {
        var badges = '';
        if (info.force_update) {
            badges += '<span class="badge badge-danger"><i class="fas fa-exclamation-circle"></i> 强制更新</span>';
        }
        if (info.security_update) {
            badges += '<span class="badge badge-warning"><i class="fas fa-shield-alt"></i> 安全更新</span>';
        }
        badgesEl.innerHTML = badges;
    }

    // 新特性列表
    var featuresEl = document.getElementById('updateFeaturesList');
    if (featuresEl) {
        var features = info.features || [];
        if (features.length === 0) {
            featuresEl.innerHTML = '<li class="update-features-empty"><span>暂无特性说明</span></li>';
        } else {
            var html = '';
            for (var i = 0; i < features.length; i++) {
                html += '<li><i class="fas fa-check"></i><span>' + escapeHtml(features[i]) + '</span></li>';
            }
            featuresEl.innerHTML = html;
        }
    }

    // 发布说明
    var notesEl = document.getElementById('updateReleaseNotesBody');
    if (notesEl) {
        notesEl.innerHTML = escapeHtml(info.release_notes || '暂无发布说明').replace(/\n/g, '<br>');
    }
}

// ===== 5. 应用更新 =====
function applyUpdate() {
    if (!currentAvailableUpdate) {
        showToast('没有可用的更新', 'warning');
        return;
    }
    var version = currentAvailableUpdate.version || '新版本';
    showConfirm('确定要立即更新到 ' + version + ' 吗？更新过程中系统将短暂不可用。', function () {
        doApplyUpdate();
    });
}

function doApplyUpdate() {
    api('apply_update', {}).then(function (data) {
        if (data && data.success) {
            updateTriggeredAt = Date.now();
            // 隐藏可用更新卡片，显示进度卡片
            var availCard = document.getElementById('updateAvailableCard');
            if (availCard) availCard.style.display = 'none';
            var failedCard = document.getElementById('updateFailedCard');
            if (failedCard) failedCard.style.display = 'none';
            var progCard = document.getElementById('updateProgressCard');
            if (progCard) progCard.style.display = '';
            showToast('更新已启动', 'success');
            startProgressPolling();
        } else {
            showToast((data && data.message) || '启动更新失败', 'error');
        }
    }).catch(function (err) {
        showToast('启动更新失败：' + (err.message || err), 'error');
        console.error('[update] doApplyUpdate:', err);
    });
}

// 稍后提醒（隐藏可用更新卡片）
function dismissUpdate() {
    var card = document.getElementById('updateAvailableCard');
    if (card) card.style.display = 'none';
    currentAvailableUpdate = null;
}

// ===== 6. 进度轮询 =====
function startProgressPolling() {
    stopProgressPolling();
    // 立即拉取一次
    refreshUpdateStatus();
    updatePollingTimer = setInterval(function () {
        refreshUpdateStatus();
    }, UPDATE_POLL_INTERVAL);
}

function stopProgressPolling() {
    if (updatePollingTimer) {
        clearInterval(updatePollingTimer);
        updatePollingTimer = null;
    }
}

// ===== 7. 状态渲染 =====
function refreshUpdateStatus() {
    api('get_update_status', {}, 'GET').then(function (data) {
        if (data && data.success) {
            renderUpdateStatus(data);
        }
    }).catch(function (err) {
        console.error('[update] refreshUpdateStatus:', err);
    });
}

function renderUpdateStatus(data) {
    var phase = data.phase || 'idle';
    var progress = PHASE_PROGRESS[phase] !== undefined ? PHASE_PROGRESS[phase] : 0;
    var label = PHASE_LABELS[phase] || phase;
    var isTerminal = TERMINAL_PHASES.indexOf(phase) !== -1;

    // 更新版本信息区域的更新状态徽章
    var statusEl = document.getElementById('updateStatusBadge');
    if (statusEl) {
        var statusClass = 'update-status-active';
        if (phase === 'completed') statusClass = 'update-status-success';
        else if (phase === 'failed') statusClass = 'update-status-danger';
        else if (phase === 'completed_rolled_back') statusClass = 'update-status-warning';
        else if (phase === 'idle') statusClass = 'update-status-active';
        statusEl.className = 'update-version-value ' + statusClass;
        statusEl.textContent = label;
    }

    var progCard = document.getElementById('updateProgressCard');
    var failedCard = document.getElementById('updateFailedCard');

    if (isTerminal) {
        if (phase === 'idle') {
            // 宽限期处理：刚触发更新但后端仍为 idle
            if (updateTriggeredAt > 0) {
                if ((Date.now() - updateTriggeredAt) < UPDATE_GRACE_PERIOD) {
                    // 宽限期内，不停止轮询，保持进度卡片可见
                    if (progCard) progCard.style.display = '';
                    var stageEl = document.getElementById('updateProgressStage');
                    if (stageEl) stageEl.textContent = '正在启动更新...';
                    var barEl = document.getElementById('updateProgressBar');
                    if (barEl) barEl.style.width = '3%';
                } else {
                    // 宽限期超时
                    stopProgressPolling();
                    updateTriggeredAt = 0;
                    if (progCard) progCard.style.display = 'none';
                    showToast('更新未启动，请稍后重试或检查服务器配置', 'error');
                }
            } else {
                // 正常空闲状态
                stopProgressPolling();
                if (progCard) progCard.style.display = 'none';
            }
        } else if (phase === 'completed') {
            stopProgressPolling();
            updateTriggeredAt = 0;
            if (progCard) progCard.style.display = 'none';
            if (failedCard) failedCard.style.display = 'none';
            showToast('更新完成！', 'success');
            loadUpdateConfig();
            loadUpdateBackups();
            loadHistory();
        } else if (phase === 'completed_rolled_back') {
            stopProgressPolling();
            updateTriggeredAt = 0;
            if (progCard) progCard.style.display = 'none';
            showToast('更新已回滚', 'warning');
            loadUpdateConfig();
            loadUpdateBackups();
        } else if (phase === 'failed') {
            stopProgressPolling();
            updateTriggeredAt = 0;
            if (progCard) progCard.style.display = 'none';
            if (failedCard) failedCard.style.display = '';
            var failedMsg = document.getElementById('updateFailedMessage');
            if (failedMsg) {
                failedMsg.textContent = data.error || data.message || '更新失败，请稍后重试';
            }
            loadUpdateBackups();
        }
    } else {
        // 进行中：显示进度卡片
        if (progCard) progCard.style.display = '';
        if (failedCard) failedCard.style.display = 'none';

        var stageEl2 = document.getElementById('updateProgressStage');
        if (stageEl2) stageEl2.textContent = label;
        var barEl2 = document.getElementById('updateProgressBar');
        if (barEl2) barEl2.style.width = progress + '%';
        var detailEl = document.getElementById('updateProgressDetail');
        if (detailEl) {
            var detail = data.detail || '';
            if (data.current_version && data.target_version) {
                detail = (detail ? detail + ' · ' : '') + data.current_version + ' → ' + data.target_version;
            }
            if (data.progress && data.progress > 0) {
                detail = (detail ? detail + ' · ' : '') + data.progress + '%';
            }
            detailEl.textContent = detail || label;
        }
    }
}

// ===== 8. 备份列表 =====
function loadUpdateBackups() {
    api('get_update_backups', {}, 'GET').then(function (data) {
        var container = document.getElementById('updateBackupsList');
        if (!container) return;
        if (!data || !data.success) {
            container.innerHTML = '<div class="update-empty"><i class="fas fa-archive"></i><span>暂无备份</span></div>';
            return;
        }
        var backups = data.backups || [];
        if (backups.length === 0) {
            container.innerHTML = '<div class="update-empty"><i class="fas fa-archive"></i><span>暂无备份记录</span></div>';
            return;
        }
        var html = '';
        for (var i = 0; i < backups.length; i++) {
            var b = backups[i];
            var bid = escapeHtml(String(b.id));
            html += '<div class="update-backup-row">' +
                '<div class="update-backup-info">' +
                    '<div class="update-backup-version"><i class="fas fa-code-branch"></i> v' + escapeHtml(b.version || '-') + '</div>' +
                    '<div class="update-backup-time"><i class="fas fa-clock"></i> ' + escapeHtml(b.created_at || b.time || '-') + '</div>' +
                    '<div class="update-backup-size"><i class="fas fa-weight-hanging"></i> ' + escapeHtml(b.size_formatted || (b.size ? formatSize(b.size) : '-')) + '</div>' +
                '</div>' +
                '<div class="update-backup-actions">' +
                    '<button class="btn btn-glass btn-sm" data-action="rollback-to-backup" data-backup="' + bid + '"><i class="fas fa-undo"></i> 回滚</button>' +
                    '<button class="btn btn-icon" data-action="delete-backup" data-backup="' + bid + '" title="删除备份"><i class="fas fa-trash-alt"></i></button>' +
                '</div>' +
            '</div>';
        }
        container.innerHTML = html;
    }).catch(function (err) {
        var container = document.getElementById('updateBackupsList');
        if (container) container.innerHTML = '<div class="update-empty"><i class="fas fa-exclamation-circle"></i><span>加载备份失败</span></div>';
        console.error('[update] loadUpdateBackups:', err);
    });
}

// ===== 9. 回滚 =====
// 回滚到指定备份（dispatcher: rollback-to-backup，data-backup=ID）
function rollbackToBackup(backupId) {
    if (!backupId) {
        showToast('备份 ID 无效', 'error');
        return;
    }
    showConfirm('确定要回滚到此备份版本吗？当前版本将被替换，操作期间系统短暂不可用。', function () {
        doRollbackUpdate({ backup_id: backupId });
    });
}

// 回滚当前失败更新（dispatcher: rollback-update，无备份 ID）
function rollbackUpdate() {
    showConfirm('确定要回滚当前更新吗？将恢复到最近一次备份版本。', function () {
        doRollbackUpdate({});
    });
}

// 强制回滚（dispatcher: force-rollback-update）
function forceRollbackUpdate() {
    showConfirm('强制回滚将忽略健康检查直接恢复到备份版本，确定继续吗？', function () {
        doRollbackUpdate({ force: true });
    });
}

function doRollbackUpdate(params) {
    api('rollback_update', params || {}).then(function (data) {
        if (data && data.success) {
            updateTriggeredAt = Date.now();
            var failedCard = document.getElementById('updateFailedCard');
            if (failedCard) failedCard.style.display = 'none';
            var progCard = document.getElementById('updateProgressCard');
            if (progCard) progCard.style.display = '';
            showToast('回滚已启动', 'success');
            startProgressPolling();
        } else {
            showToast((data && data.message) || '回滚失败', 'error');
        }
    }).catch(function (err) {
        showToast('回滚失败：' + (err.message || err), 'error');
        console.error('[update] doRollbackUpdate:', err);
    });
}

// ===== 10. 删除备份 =====
function deleteBackup(backupId) {
    if (!backupId) {
        showToast('备份 ID 无效', 'error');
        return;
    }
    showConfirm('确定要删除此备份吗？删除后无法恢复。', function () {
        api('delete_update_backup', { backup_id: backupId }).then(function (data) {
            if (data && data.success) {
                showToast('备份已删除');
                loadUpdateBackups();
            } else {
                showToast((data && data.message) || '删除失败', 'error');
            }
        }).catch(function (err) {
            showToast('删除失败：' + (err.message || err), 'error');
            console.error('[update] deleteBackup:', err);
        });
    });
}

// ===== 11. 历史记录 =====
function loadHistory() {
    api('get_update_history', {}, 'GET').then(function (data) {
        var container = document.getElementById('updateHistoryList');
        if (!container) return;
        if (!data || !data.success) {
            container.innerHTML = '<div class="update-empty"><i class="fas fa-history"></i><span>暂无历史记录</span></div>';
            return;
        }
        var history = data.history || [];
        if (history.length === 0) {
            container.innerHTML = '<div class="update-empty"><i class="fas fa-history"></i><span>暂无更新历史</span></div>';
            return;
        }
        var html = '';
        for (var i = 0; i < history.length; i++) {
            var h = history[i];
            var result = h.result || 'unknown';
            var resultClass = 'update-history-warning';
            var resultIcon = 'fa-info-circle';
            var resultLabel = '未知';
            if (result === 'success' || result === 'completed') {
                resultClass = 'update-history-success';
                resultIcon = 'fa-check-circle';
                resultLabel = '成功';
            } else if (result === 'failed') {
                resultClass = 'update-history-danger';
                resultIcon = 'fa-times-circle';
                resultLabel = '失败';
            } else if (result === 'rolled_back' || result === 'completed_rolled_back') {
                resultClass = 'update-history-warning';
                resultIcon = 'fa-undo';
                resultLabel = '已回滚';
            }
            var versionChange = escapeHtml(h.from_version || h.current_version || '?') + ' → ' + escapeHtml(h.to_version || h.target_version || '?');
            html += '<div class="update-history-item ' + resultClass + '">' +
                '<div class="update-history-icon"><i class="fas ' + resultIcon + '"></i></div>' +
                '<div class="update-history-content">' +
                    '<div class="update-history-versions">' + versionChange + ' · <span class="update-history-result">' + escapeHtml(resultLabel) + '</span></div>' +
                    '<div class="update-history-time"><i class="fas fa-clock"></i> ' + escapeHtml(h.created_at || h.time || '-') + '</div>' +
                    (h.error ? '<div class="update-history-error"><i class="fas fa-exclamation-triangle"></i> ' + escapeHtml(h.error) + '</div>' : '') +
                '</div>' +
            '</div>';
        }
        container.innerHTML = html;
    }).catch(function (err) {
        var container = document.getElementById('updateHistoryList');
        if (container) container.innerHTML = '<div class="update-empty"><i class="fas fa-exclamation-circle"></i><span>加载历史失败</span></div>';
        console.error('[update] loadHistory:', err);
    });
}

// dispatcher 别名：show-update-history
function showUpdateHistory() {
    loadHistory();
}

// ===== 12. 清除失败标志 =====
function clearUpdateFailed() {
    api('clear_update_failed', {}).then(function (data) {
        if (data && data.success) {
            showToast('失败状态已清除');
            var failedCard = document.getElementById('updateFailedCard');
            if (failedCard) failedCard.style.display = 'none';
            refreshUpdateStatus();
        } else {
            showToast((data && data.message) || '清除失败', 'error');
        }
    }).catch(function (err) {
        showToast('清除失败：' + (err.message || err), 'error');
        console.error('[update] clearUpdateFailed:', err);
    });
}

// 重置更新子系统（dispatcher: reset-update-subsystem）
function resetUpdateSubsystem() {
    showConfirm('确定要重置更新子系统吗？将清除失败状态并重新加载所有数据。', function () {
        api('clear_update_failed', {}).then(function (data) {
            if (data && data.success) {
                showToast('更新子系统已重置');
                var failedCard = document.getElementById('updateFailedCard');
                if (failedCard) failedCard.style.display = 'none';
                var progCard = document.getElementById('updateProgressCard');
                if (progCard) progCard.style.display = 'none';
                updateTriggeredAt = 0;
                stopProgressPolling();
                loadUpdateConfig();
                refreshUpdateStatus();
                loadUpdateBackups();
                loadHistory();
            } else {
                showToast((data && data.message) || '重置失败', 'error');
            }
        }).catch(function (err) {
            showToast('重置失败：' + (err.message || err), 'error');
            console.error('[update] resetUpdateSubsystem:', err);
        });
    });
}

// ===== 13. 切换到更新标签时加载全部数据 =====
function switchToUpdateTab() {
    loadUpdateConfig();
    refreshUpdateStatus();
    loadUpdateBackups();
    loadHistory();
}

// ===== 兼容别名（对齐任务规格命名）=====
var clearFailedUpdate = clearUpdateFailed;
var rollbackBackup = rollbackToBackup;
var loadBackups = loadUpdateBackups;
