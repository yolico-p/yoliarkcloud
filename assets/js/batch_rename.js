/**
 * 批量重命名 / 拖拽排序 / 应用初始化
 *
 * 从 views/pages/_app_script.php 抽取到外部 JS，便于：
 *  - Service Worker 预缓存（sw.js STATIC_ASSETS 已纳入清单）
 *  - 静态分析 / lint 检查
 *  - 颜色统一走 CSS 变量，主题切换实时响应（不再需要 isDark 三元判断）
 *
 * 依赖的全局（由 store.js / utils.js / core.js / files.js 等更早加载的脚本提供）：
 *   selectedFiles, currentFileList, currentParentId, currentSort,
 *   escapeHtml, showModal, closeModal, showToast, api,
 *   updateBatchButtons, loadFiles, loadStorageInfo,
 *   uploadManager, initViewFromStorage,
 *   setupFileListDelegation, setupBreadcrumbDelegation, setupAppDelegation,
 *   setupModalDelegation, setupUploadDelegation, setupPagesDelegation, setupAIDelegation
 */

// 防止浏览器自动填充
(function preventAutofill() {
    setTimeout(function() {
        var searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.value = '';
            searchInput.setAttribute('autocomplete', 'off');
        }
        var fakeInput = document.getElementById('fakeInput');
        if (fakeInput) fakeInput.value = '';
        var fakePassword = document.getElementById('fakePassword');
        if (fakePassword) fakePassword.value = '';
    }, 100);

    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            var searchInput = document.getElementById('searchInput');
            if (searchInput) searchInput.value = '';
        }
    });
})();

function bindRenameFieldEvents() {
    var ids = ['renamePrefix', 'renameSuffix', 'renameStartNum', 'renamePadLength', 'renameFind', 'renameReplace'];
    for (var i = 0; i < ids.length; i++) {
        var el = document.getElementById(ids[i]);
        if (el) el.addEventListener('input', updateRenamePreview);
    }
    var keepExt = document.getElementById('renameKeepExt');
    if (keepExt) keepExt.addEventListener('change', updateRenamePreview);
}

function showBatchRenameDialog() {
    if (selectedFiles.size === 0) { showToast('请先选择文件', 'error'); return; }

    // 颜色统一走 CSS 变量，主题切换实时响应（无需 isDark 判断）
    var textColor = 'var(--text-primary)';
    var textColorMuted = 'var(--text-muted)';
    var borderColor = 'var(--bg-glass-border)';
    var inputBg = 'var(--bg-surface)';
    var accentColor = 'var(--accent-primary)';
    var successColor = 'var(--accent-success)';

    var selectedFilesList = Array.from(selectedFiles);
    var fileIdToFile = {};
    if (typeof currentFileList !== 'undefined') {
        currentFileList.forEach(function(f) { fileIdToFile[f.id] = f; });
    }

    var previewText = '<div style="margin-bottom:8px;font-weight:500;color:' + accentColor + '"><i class="fas fa-eye"></i> 预览（前 5 个）：</div>';
    var idx = 0;
    for (var fi = 0; fi < selectedFilesList.length; fi++) {
        var fileId = selectedFilesList[fi];
        if (!fileIdToFile[fileId]) continue;
        var f = fileIdToFile[fileId];
        if (idx >= 5) break;
        idx++;
        previewText += '<div style="margin:4px 0;font-size:13px"><span style="color:' + textColorMuted + ';text-decoration:line-through">' + escapeHtml(f.filename || '未知文件') + '</span> <span style="color:' + accentColor + '">→</span> <span style="color:' + successColor + '">待重命名</span></div>';
    }
    if (selectedFiles.size > 5) {
        previewText += '<div style="margin-top:8px;color:' + textColorMuted + ';font-size:12px"><i class="fas fa-ellipsis-h"></i> 还有 ' + (selectedFiles.size - 5) + ' 个文件</div>';
    }

    showModal('批量重命名',
        '<div style="margin-bottom:16px;padding:12px;background:' + inputBg + ';border:1px solid ' + borderColor + ';border-radius:8px">' +
            '<div style="display:flex;align-items:center;gap:8px;color:' + textColor + ';font-size:14px">' +
                '<i class="fas fa-check-circle" style="color:' + accentColor + '"></i>' +
                '<span>已选择 <strong style="color:' + accentColor + ';font-size:16px">' + selectedFiles.size + '</strong> 个文件</span>' +
            '</div>' +
        '</div>' +
        '<div class="form-group" style="margin-bottom:16px">' +
            '<label class="batch-dialog-label" style="color:' + textColor + ';font-size:13px;margin-bottom:8px;display:block;font-weight:500">' +
                '<i class="fas fa-palette"></i> 重命名方式' +
            '</label>' +
            '<select id="renameMode" class="form-control batch-dialog-select" style="width:100%;padding:10px 12px;border:1px solid ' + borderColor + ';border-radius:8px;background:' + inputBg + ';color:' + textColor + ';font-size:14px">' +
                '<option value="prefix">添加前缀</option>' +
                '<option value="suffix">添加后缀</option>' +
                '<option value="prefix_suffix">前缀 + 后缀</option>' +
                '<option value="number">序号命名</option>' +
                '<option value="replace">查找替换</option>' +
            '</select>' +
        '</div>' +
        '<div id="renameFields" class="form-group" style="margin-bottom:16px"></div>' +
        '<div id="renamePreview" class="batch-dialog-preview" style="margin-bottom:16px;padding:12px;background:' + inputBg + ';border:1px solid ' + borderColor + ';border-radius:8px;max-height:200px;overflow-y:auto">' +
            previewText +
        '</div>' +
        '<div style="display:flex;gap:10px;justify-content:flex-end">' +
            '<button class="btn" data-action="close-modal"><i class="fas fa-times"></i> 取消</button>' +
            '<button class="btn btn-primary" data-action="execute-batch-rename"><i class="fas fa-font"></i> 执行重命名</button>' +
        '</div>',
        {html: true}
    );
    updateRenamePreview();
    var renameModeEl = document.getElementById('renameMode');
    if (renameModeEl) renameModeEl.addEventListener('change', function() {
        var fld = document.getElementById('renameFields');
        if (fld) fld.innerHTML = '';
        updateRenamePreview();
    });
}

function updateRenamePreview() {
    var modeEl = document.getElementById('renameMode');
    var fieldsEl = document.getElementById('renameFields');
    var preview = document.getElementById('renamePreview');
    if (!preview || !modeEl) return;
    var mode = modeEl.value;

    // 颜色统一走 CSS 变量
    var textColor = 'var(--text-primary)';
    var textColorMuted = 'var(--text-muted)';
    var borderColor = 'var(--bg-glass-border)';
    var inputBg = 'var(--bg-surface)';
    var accentColor = 'var(--accent-primary)';
    var successColor = 'var(--accent-success)';

    if (fieldsEl && fieldsEl.children.length === 0) {
        var fieldsHtml = '';
        if (mode === 'prefix') {
            fieldsHtml = '<input type="text" id="renamePrefix" class="batch-dialog-input" placeholder="输入前缀，例如：IMG_" style="width:100%;padding:10px 12px;border:1px solid ' + borderColor + ';border-radius:8px;background:' + inputBg + ';color:' + textColor + ';font-size:14px">';
        } else if (mode === 'suffix') {
            fieldsHtml = '<input type="text" id="renameSuffix" class="batch-dialog-input" placeholder="输入后缀，例如：_backup" style="width:100%;padding:10px 12px;border:1px solid ' + borderColor + ';border-radius:8px;background:' + inputBg + ';color:' + textColor + ';font-size:14px">';
        } else if (mode === 'prefix_suffix') {
            fieldsHtml = '<div style="display:flex;gap:10px"><input type="text" id="renamePrefix" class="batch-dialog-input" placeholder="前缀" style="flex:1;padding:10px 12px;border:1px solid ' + borderColor + ';border-radius:8px;background:' + inputBg + ';color:' + textColor + ';font-size:14px"><input type="text" id="renameSuffix" class="batch-dialog-input" placeholder="后缀" style="flex:1;padding:10px 12px;border:1px solid ' + borderColor + ';border-radius:8px;background:' + inputBg + ';color:' + textColor + ';font-size:14px"></div>';
        } else if (mode === 'number') {
            fieldsHtml = '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><div style="display:flex;align-items:center;gap:6px"><label class="batch-dialog-label" style="color:' + textColor + ';font-size:13px">起始:</label><input type="number" id="renameStartNum" class="batch-dialog-input" value="1" min="1" style="width:70px;padding:8px 10px;border:1px solid ' + borderColor + ';border-radius:8px;background:' + inputBg + ';color:' + textColor + ';font-size:14px"></div><div style="display:flex;align-items:center;gap:6px"><label class="batch-dialog-label" style="color:' + textColor + ';font-size:13px">位数:</label><input type="number" id="renamePadLength" class="batch-dialog-input" value="3" min="0" max="10" style="width:60px;padding:8px 10px;border:1px solid ' + borderColor + ';border-radius:8px;background:' + inputBg + ';color:' + textColor + ';font-size:14px"></div><div style="display:flex;align-items:center;gap:6px"><label class="batch-dialog-label" style="color:' + textColor + ';font-size:13px;white-space:nowrap"><input type="checkbox" id="renameKeepExt" checked style="margin-right:4px">保留扩展名</label></div></div>';
        } else if (mode === 'replace') {
            fieldsHtml = '<div style="display:flex;gap:10px"><input type="text" id="renameFind" class="batch-dialog-input" placeholder="查找内容" style="flex:1;padding:10px 12px;border:1px solid ' + borderColor + ';border-radius:8px;background:' + inputBg + ';color:' + textColor + ';font-size:14px"><input type="text" id="renameReplace" class="batch-dialog-input" placeholder="替换为" style="flex:1;padding:10px 12px;border:1px solid ' + borderColor + ';border-radius:8px;background:' + inputBg + ';color:' + textColor + ';font-size:14px"></div>';
        }
        fieldsEl.innerHTML = fieldsHtml;
        bindRenameFieldEvents();
    }

    var previewHtml = '<div style="margin-bottom:8px;font-weight:500;color:' + accentColor + '"><i class="fas fa-eye"></i> 预览（前 5 个）：</div>';
    var idx = 0;
    var changedCount = 0;
    var selectedFilesList = Array.from(selectedFiles);
    var fileIdToFile = {};
    if (typeof currentFileList !== 'undefined') {
        currentFileList.forEach(function(f) { fileIdToFile[f.id] = f; });
    }

    for (var fi = 0; fi < selectedFilesList.length; fi++) {
        var fileId = selectedFilesList[fi];
        if (!fileIdToFile[fileId]) continue;
        var f = fileIdToFile[fileId];
        if (idx >= 5) break;
        idx++;

        var pathParts = f.filename.split('.');
        var ext = pathParts.length > 1 ? '.' + pathParts.pop() : '';
        var nameOnly = pathParts.join('.');
        var newName = f.filename;

        if (mode === 'prefix') {
            var p = (document.getElementById('renamePrefix') || {}).value || '';
            if (p) newName = p + nameOnly + ext;
        }
        else if (mode === 'suffix') {
            var s = (document.getElementById('renameSuffix') || {}).value || '';
            if (s) newName = nameOnly + s + ext;
        }
        else if (mode === 'prefix_suffix') {
            var p = (document.getElementById('renamePrefix') || {}).value || '';
            var s = (document.getElementById('renameSuffix') || {}).value || '';
            if (p || s) newName = p + nameOnly + s + ext;
        }
        else if (mode === 'number') {
            var start = parseInt((document.getElementById('renameStartNum') || {}).value || 1);
            var pad = parseInt((document.getElementById('renamePadLength') || {}).value || 3);
            var keepExt = (document.getElementById('renameKeepExt') || {}).checked !== false;
            var num = start + idx - 1;
            var numStr = pad > 0 ? String(num).padStart(pad, '0') : String(num);
            newName = keepExt ? (numStr + ext) : numStr;
        }
        else if (mode === 'replace') {
            var find = (document.getElementById('renameFind') || {}).value || '';
            var repl = (document.getElementById('renameReplace') || {}).value || '';
            if (find) newName = f.filename.split(find).join(repl);
        }

        if (newName !== f.filename) {
            changedCount++;
            previewHtml += '<div style="margin:4px 0;font-size:13px"><span style="color:' + textColorMuted + ';text-decoration:line-through">' + escapeHtml(f.filename) + '</span> <span style="color:' + accentColor + '">→</span> <span style="color:' + successColor + '">' + escapeHtml(newName) + '</span></div>';
        }
    }

    if (changedCount === 0) {
        previewHtml += '<div style="color:' + textColorMuted + '">当前设置下文件名将无变化</div>';
    }
    if (selectedFiles.size > 5) {
        previewHtml += '<div style="margin-top:8px;color:' + textColorMuted + ';font-size:12px"><i class="fas fa-ellipsis-h"></i> 还有 ' + (selectedFiles.size - 5) + ' 个文件</div>';
    }

    preview.innerHTML = previewHtml;
}

// 修复原 _app_script.php 中直接引用全局 event 的 bug：
// 旧代码 var btn = event.target 在非事件回调上下文中可能抛 ReferenceError。
// 改为从触发元素（document.activeElement 或 data-action 委托）解析，
// 与 setupModalDelegation 的事件委托机制保持一致。
function executeBatchRename(triggerEl) {
    var modeEl = document.getElementById('renameMode');
    if (!modeEl) return;
    var mode = modeEl.value;
    var params = { file_ids: JSON.stringify(Array.from(selectedFiles)), mode: mode };

    if (mode === 'prefix') {
        var prefix = (document.getElementById('renamePrefix') || {}).value || '';
        if (!prefix) { showToast('请输入前缀', 'error'); return; }
        params.prefix = prefix;
    }
    else if (mode === 'suffix') {
        var suffix = (document.getElementById('renameSuffix') || {}).value || '';
        if (!suffix) { showToast('请输入后缀', 'error'); return; }
        params.suffix = suffix;
    }
    else if (mode === 'prefix_suffix') {
        params.prefix = (document.getElementById('renamePrefix') || {}).value || '';
        params.suffix = (document.getElementById('renameSuffix') || {}).value || '';
        if (!params.prefix && !params.suffix) { showToast('前缀和后缀至少填写一项', 'error'); return; }
    }
    else if (mode === 'number') {
        params.start_num = parseInt((document.getElementById('renameStartNum') || {}).value || 1);
        params.pad_length = parseInt((document.getElementById('renamePadLength') || {}).value || 3);
        params.keep_ext = (document.getElementById('renameKeepExt') || {}).checked !== false;
    }
    else if (mode === 'replace') {
        var find = (document.getElementById('renameFind') || {}).value || '';
        if (!find) { showToast('请输入查找内容', 'error'); return; }
        params.find = find;
        params.replace = (document.getElementById('renameReplace') || {}).value || '';
    }

    // 从触发元素取按钮，便于回填文本与禁用态
    var btn = triggerEl || document.querySelector('[data-action="execute-batch-rename"]');
    var originalText = btn ? btn.textContent : '';
    if (btn) { btn.textContent = '处理中...'; btn.disabled = true; }

    api('batch_rename', params).then(function(data) {
        if (btn) { btn.textContent = originalText; btn.disabled = false; }
        if (data.success) {
            closeModal();
            Store.mutate('files.selectedIds', function (s) { s.clear(); });
            if (typeof invalidateFileListCache === 'function') invalidateFileListCache(currentParentId);
            loadFiles(currentParentId, true);
            showToast(data.message);
        }
        else {
            showToast(data.message, 'error');
        }
    }).catch(function(err) {
        if (btn) { btn.textContent = originalText; btn.disabled = false; }
        showToast('操作失败', 'error');
    });
}

function initDragSort() {
    var container = document.getElementById('fileList');
    if (!container) return;
    if (container._dragSortInit) return;
    container._dragSortInit = true;
    var draggedRow = null;
    container.addEventListener('dragstart', function(e) {
        if (currentSort !== 'custom') { e.preventDefault(); return; }
        var row = e.target.closest('.file-row');
        if (!row) return;
        draggedRow = row;
        row.style.opacity = '0.4';
        e.dataTransfer.effectAllowed = 'move';
    });
    container.addEventListener('dragend', function(e) {
        if (draggedRow) draggedRow.style.opacity = '';
        draggedRow = null;
        document.querySelectorAll('.file-row').forEach(function(r) { r.style.borderTop = ''; });
    });
    container.addEventListener('dragover', function(e) {
        if (currentSort !== 'custom') return;
        e.preventDefault();
        var row = e.target.closest('.file-row');
        if (!row || row === draggedRow) return;
        e.dataTransfer.dropEffect = 'move';
        document.querySelectorAll('.file-row').forEach(function(r) { r.style.borderTop = ''; });
        // 拖拽指示线走 CSS 变量，主题切换时颜色跟随
        row.style.borderTop = '2px solid var(--accent-secondary)';
    });
    container.addEventListener('drop', function(e) {
        if (currentSort !== 'custom') return;
        e.preventDefault();
        var targetRow = e.target.closest('.file-row');
        if (!targetRow || !draggedRow || targetRow === draggedRow) return;
        document.querySelectorAll('.file-row').forEach(function(r) { r.style.borderTop = ''; });
        container.insertBefore(draggedRow, targetRow);
        var rows = Array.from(container.querySelectorAll('.file-row'));
        var orders = [];
        rows.forEach(function(row, index) {
            orders.push({ id: parseInt(row.dataset.id), sort_order: index });
        });
        api('update_sort_order', { orders: JSON.stringify(orders) }).then(function(data) {
            if (data.success) { if (typeof invalidateFileListCache === 'function') invalidateFileListCache(currentParentId); loadFiles(currentParentId, true); }
        });
    });
}

// ── 应用初始化（在所有依赖脚本加载后执行） ──
uploadManager.loadFromStorage();
initViewFromStorage();
setupFileListDelegation();
setupBreadcrumbDelegation();
setupAppDelegation();
setupModalDelegation();
setupUploadDelegation();
setupPagesDelegation();
setupAIDelegation();
loadFiles(0);
loadStorageInfo();
loadConfig();

// 订阅 files.selectedIds 变化：统一驱动批量按钮可用态 + 全选 checkbox 同步。
// 业务代码（toggleSelect / toggleSelectAll / 框选 / batchDelete 等）只需调用
// Store.mutate('files.selectedIds', ...)，UI 同步由订阅自动完成，
// 避免散落的 updateBatchButtons()/syncMasterCheckboxes() 显式调用造成遗漏。
if (typeof Store !== 'undefined') {
    Store.subscribe('files.selectedIds', function () {
        if (typeof updateBatchButtons === 'function') updateBatchButtons();
        if (typeof syncMasterCheckboxes === 'function') syncMasterCheckboxes();
    });
}
