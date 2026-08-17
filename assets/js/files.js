/**
 * 文件管理核心 - 列表渲染、导航、操作
 */

// 文件列表内存缓存（key = parentId:sort:sortOrder）
var _fileListCache = {};
var FILE_LIST_CACHE_TTL = 30000; // 缓存有效期 30 秒
var _fileListCacheTimers = {};
var fileOpSourceIds = [];

function _setFileListCache(key, files) {
    _fileListCache[key] = files;
    if (_fileListCacheTimers[key]) clearTimeout(_fileListCacheTimers[key]);
    _fileListCacheTimers[key] = setTimeout(function() {
        delete _fileListCache[key];
        delete _fileListCacheTimers[key];
    }, FILE_LIST_CACHE_TTL);
}

function invalidateFileListCache(parentId) {
    // 使指定目录（或所有目录）的缓存失效
    if (parentId !== undefined) {
        var keys = Object.keys(_fileListCache);
        for (var i = 0; i < keys.length; i++) {
            if (keys[i].indexOf(parentId + ':') === 0) {
                delete _fileListCache[keys[i]];
                if (_fileListCacheTimers[keys[i]]) clearTimeout(_fileListCacheTimers[keys[i]]);
                delete _fileListCacheTimers[keys[i]];
            }
        }
    } else {
        _fileListCache = {};
        var tKeys = Object.keys(_fileListCacheTimers);
        for (var j = 0; j < tKeys.length; j++) clearTimeout(_fileListCacheTimers[tKeys[j]]);
        _fileListCacheTimers = {};
    }
}

function findFileById(id) {
    if (!currentFileList) return null;
    for (var i = 0; i < currentFileList.length; i++) {
        if (currentFileList[i].id === id) return currentFileList[i];
    }
    return null;
}

function setupFileListDelegation() {
    var container = document.getElementById('fileList');
    if (!container) return;

    container.addEventListener('click', function (e) {
        var tagBtn = e.target.closest('[data-action="edit-tags"]');
        if (tagBtn) {
            var tagFileId = parseInt(tagBtn.dataset.fileId);
            var tagFile = findFileById(tagFileId);
            if (tagFile) showTagDialog(tagFileId, tagFile.tags || []);
            return;
        }

        var actionBtn = e.target.closest('[data-action]');
        if (actionBtn) {
            var row = actionBtn.closest('.file-row, .grid-item');
            var fileId = row ? parseInt(row.dataset.id) : 0;
            var action = actionBtn.dataset.action;
            if (action === 'download' && fileId) downloadFile(fileId);
            else if (action === 'share' && fileId) showShareDialog(fileId);
            else if (action === 'delete' && fileId) deleteFile(fileId);
            else if (action === 'mobile-more' && fileId) {
                e.stopPropagation();
                var file = findFileById(fileId);
                if (file) {
                    var rect = actionBtn.getBoundingClientRect();
                    showContextMenu({
                        clientX: rect.left + rect.width / 2,
                        clientY: rect.top + rect.height / 2,
                        preventDefault: function () {},
                        stopPropagation: function () {}
                    }, fileId, file);
                }
            }
            return;
        }

        var checkbox = e.target.closest('input[type="checkbox"]');
        if (checkbox) {
            var row2 = checkbox.closest('.file-row, .grid-item');
            if (row2 && !checkbox.closest('.file-table-header')) {
                toggleSelect(parseInt(row2.dataset.id), checkbox);
            }
            return;
        }

        var row3 = e.target.closest('.file-row, .grid-item');
        if (row3) {
            var fid = parseInt(row3.dataset.id);
            var isDir = row3.dataset.isDir === 'true';
            handleFileRowClick(fid, isDir);
        }
    });

    container.addEventListener('dblclick', function (e) {
        if (isTouchDevice) return;
        var row = e.target.closest('.file-row, .grid-item');
        if (!row) return;
        var fileId = parseInt(row.dataset.id);
        var isDir = row.dataset.isDir === 'true';
        if (isDir) navigateTo(fileId);
        else previewFile(fileId);
    });

    container.addEventListener('contextmenu', function (e) {
        var row = e.target.closest('.file-row, .grid-item');
        if (!row) return;
        var fileId = parseInt(row.dataset.id);
        var file = findFileById(fileId);
        if (file) showContextMenu(e, fileId, file);
    });

    container.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var row = e.target.closest('.file-row, .grid-item');
        if (!row) return;
        e.preventDefault();
        var fileId = parseInt(row.dataset.id);
        var isDir = row.dataset.isDir === 'true';
        if (isDir) navigateTo(fileId);
        else previewFile(fileId);
    });

    var selectAllHeader = document.getElementById('selectAllCheck');
    if (selectAllHeader) {
        var selectAllInput = selectAllHeader.querySelector('input[type="checkbox"]');
        if (selectAllInput) {
            selectAllInput.addEventListener('change', function () {
                toggleSelectAll(this);
            });
        }
    }
}

function setupBreadcrumbDelegation() {
    var bc = document.getElementById('breadcrumb');
    if (!bc) return;
    bc.addEventListener('click', function (e) {
        var item = e.target.closest('.breadcrumb-item');
        if (!item) return;
        var pid = parseInt(item.dataset.parentId);
        if (!isNaN(pid)) navigateTo(pid);
    });
}

function setupAppDelegation() {
    var sidebarNav = document.querySelector('.sidebar-nav');
    if (sidebarNav) {
        sidebarNav.addEventListener('click', function (e) {
            var item = e.target.closest('.nav-item');
            if (item && item.dataset.page) switchPage(item.dataset.page, item);
        });
    }

    var sidebarOverlay = document.getElementById('sidebarOverlay');
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', toggleSidebar);

    var menuToggle = document.getElementById('menuToggle');
    if (menuToggle) menuToggle.addEventListener('click', toggleSidebar);

    var topBar = document.querySelector('.top-bar');
    if (topBar) {
        topBar.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) return;
            var action = btn.dataset.action;
            if (action === 'perform-search') performSearch();
            else if (action === 'toggle-theme') toggleTheme();
            else if (action === 'handle-logout') handleLogout();
        });
    }

    var toolbar = document.querySelector('.toolbar');
    if (toolbar) {
        toolbar.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) return;
            var action = btn.dataset.action;
            if (action === 'show-upload-dialog') showUploadDialog();
            else if (action === 'show-new-folder-dialog') showNewFolderDialog();
            else if (action === 'batch-delete') batchDelete();
            else if (action === 'batch-rename') showBatchRenameDialog();
            else if (action === 'batch-move') showMoveDialog();
            else if (action === 'batch-copy') showCopyDialog();
        });
    }

    var viewToggle = document.getElementById('viewToggle');
    if (viewToggle) {
        viewToggle.addEventListener('click', function (e) {
            var btn = e.target.closest('.view-toggle-btn');
            if (btn && btn.dataset.view) switchView(btn.dataset.view);
        });
    }

    var contextMenu = document.getElementById('contextMenu');
    if (contextMenu) {
        contextMenu.addEventListener('click', function (e) {
            var link = e.target.closest('[data-action]');
            if (!link) return;
            var action = link.dataset.action;
            if (action && action.indexOf('ctx-') === 0) contextAction(action.substring(4));
        });
    }

    var settingsTabs = document.querySelector('.settings-tabs');
    if (settingsTabs) {
        settingsTabs.addEventListener('click', function (e) {
            var btn = e.target.closest('.settings-tab-btn');
            if (btn && btn.dataset.tab) switchSettingsTab(btn.dataset.tab, btn);
        });
    }

    var uploadOverlay = document.getElementById('uploadOverlay');
    if (uploadOverlay) {
        uploadOverlay.addEventListener('click', function (e) {
            if (e.target === uploadOverlay) { closeUploadDialog(); return; }
            var btn = e.target.closest('[data-action]');
            if (!btn) return;
            if (btn.dataset.action === 'close-upload-dialog') closeUploadDialog();
            else if (btn.dataset.action === 'select-files') document.getElementById('fileInput').click();
            else if (btn.dataset.action === 'select-folder') document.getElementById('folderInput').click();
        });
    }

    var floatWidget = document.getElementById('uploadFloatWidget');
    if (floatWidget) {
        floatWidget.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) return;
            if (btn.dataset.action === 'toggle-float-widget') toggleFloatWidget();
        });
    }

    var interruptOverlay = document.getElementById('uploadInterruptOverlay');
    if (interruptOverlay) {
        interruptOverlay.addEventListener('click', function (e) {
            if (e.target === interruptOverlay) { closeInterruptDialog(); return; }
            var btn = e.target.closest('[data-action]');
            if (btn && btn.dataset.action === 'close-interrupt-dialog') closeInterruptDialog();
        });
    }

    var contentArea = document.getElementById('contentArea');
    if (contentArea) {
        contentArea.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) return;
            var action = btn.dataset.action;
            if (action === 'refresh-recent') loadRecent();
            else if (action === 'empty-trash') emptyTrash();
            else if (action === 'toggle-log-stats') toggleLogStatistics();
            else if (action === 'export-logs') exportLogs();
            else if (action === 'clear-logs') clearLogs();
            else if (action === 'refresh-logs') loadOperationLogs();
            else if (action === 'reset-log-filters') resetLogFilters();
            else if (action === 'clear-ai-chat') clearAIChat();
            else if (action === 'send-ai-message') sendAIMessage();
            else if (action === 'ai-quick') sendAIQuick(btn.dataset.prompt);
            else if (action === 'refresh-config') loadConfig();
            else if (action === 'import-settings') importSettings();
            else if (action === 'export-settings') exportSettings();
            else if (action === 'save-config') saveConfig();
            else if (action === 'save-ai-config') saveAIConfig();
            else if (action === 'fetch-ai-models') fetchAIModels();
            else if (action === 'test-ai-connection') testAIConnection();
            else if (action === 'save-blocked-extensions') saveBlockedExtensions();
            else if (action === 'toggle-api') toggleApi(btn);
            else if (action === 'generate-api-token') generateApiToken();
            else if (action === 'revoke-api-token') revokeApiToken();
            else if (action === 'copy-api-token') copyApiToken();
            else if (action === 'save-api-config') saveApiConfig();
            else if (action === 'refresh-inbox') loadInboxBox();
            else if (action === 'copy-inbox-url') copyInboxUrl();
            else if (action === 'regenerate-inbox-url') regenerateInboxUrl();
            else if (action === 'save-inbox-settings') saveInboxSettings();
            else if (action === 'save-storage-settings') saveStorageSettings();
            else if (action === 'load-disk-info') loadDiskInfo();
            else if (action === 'manual-update-storage') manualUpdateStorage();
            else if (action === 'apply-default-storage') applyDefaultStorage();
            else if (action === 'clear-cache-thumbnails') clearCache('thumbnails');
            else if (action === 'clear-cache-covers') clearCache('covers');
            else if (action === 'clear-all-cache') clearAllCache();
            // 系统更新相关
            else if (action === 'save-update-config') saveUpdateConfig();
            else if (action === 'check-update-now') checkUpdateNow();
            else if (action === 'refresh-update-status') refreshUpdateStatus();
            else if (action === 'show-update-history') showUpdateHistory();
            else if (action === 'apply-update') applyUpdate();
            else if (action === 'dismiss-update') dismissUpdate();
            else if (action === 'rollback-update') rollbackUpdate();
            else if (action === 'rollback-to-backup') rollbackToBackup(btn.dataset.backup);
            else if (action === 'delete-backup') deleteBackup(btn.dataset.backup);
            else if (action === 'force-rollback-update') forceRollbackUpdate();
            else if (action === 'clear-update-failed') clearUpdateFailed();
            else if (action === 'reset-update-subsystem') resetUpdateSubsystem();
        });
    }

    var searchInput = document.getElementById('searchInput');
    if (searchInput) searchInput.addEventListener('keyup', handleSearch);

    var sortSelect = document.getElementById('sortSelect');
    if (sortSelect) sortSelect.addEventListener('change', function () { changeSort(this.value); });

    var apiEnabledCheckbox = document.getElementById('cfg_api_enabled');
    if (apiEnabledCheckbox) {
        apiEnabledCheckbox.addEventListener('change', function() {
            toggleApi(this);
        });
    }

    var fileInput = document.getElementById('fileInput');
    if (fileInput) fileInput.addEventListener('change', function () { handleFileSelect(this.files); });

    var folderInput = document.getElementById('folderInput');
    if (folderInput) folderInput.addEventListener('change', function () { handleFileSelect(this.files); });

    var logFilterCategory = document.getElementById('logFilterCategory');
    if (logFilterCategory) logFilterCategory.addEventListener('change', applyLogFilters);

    var logFilterSeverity = document.getElementById('logFilterSeverity');
    if (logFilterSeverity) logFilterSeverity.addEventListener('change', applyLogFilters);

    var logSearchInput = document.getElementById('logSearchInput');
    if (logSearchInput) logSearchInput.addEventListener('input', debounceLogSearch);

    var logDateFrom = document.getElementById('logDateFrom');
    if (logDateFrom) logDateFrom.addEventListener('change', applyLogFilters);

    var logDateTo = document.getElementById('logDateTo');
    if (logDateTo) logDateTo.addEventListener('change', applyLogFilters);

    var aiChatInput = document.getElementById('aiChatInput');
    if (aiChatInput) {
        aiChatInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendAIMessage();
            }
        });
    }

    var aiProvider = document.getElementById('aiProvider');
    if (aiProvider) aiProvider.addEventListener('change', onAIProviderChange);

    var themeModeSelect = document.getElementById('themeModeSelect');
    if (themeModeSelect) themeModeSelect.addEventListener('change', function () { onThemeModeChange(this.value); });

    var profileForm = document.getElementById('profileForm');
    if (profileForm) profileForm.addEventListener('submit', function (e) { updateProfile(e); });

    var passwordForm = document.getElementById('passwordForm');
    if (passwordForm) passwordForm.addEventListener('submit', function (e) { changePassword(e); });
}

function setupModalDelegation() {
    var overlay = document.getElementById('modalOverlay');
    if (!overlay) return;

    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) {
            closeModal();
            return;
        }

        if (e.target.closest('.modal-close')) {
            closeModal();
            return;
        }

        // 路径面包屑点击导航
        var pathPart = e.target.closest('.path-root, .path-part, .path-current');
        if (pathPart && pathPart.dataset.folderId !== undefined) {
            var pid = parseInt(pathPart.dataset.folderId);
            var searchInput = document.getElementById('folderTreeSearchInput');
            if (searchInput && searchInput.value.trim() !== '') {
                searchInput.value = '';
                filterFolderTree('');
            }
            var targetRow = document.querySelector('#folderTreeBox .node-row[data-folder-id="' + pid + '"]');
            if (targetRow) {
                selectFolderTarget(pid, targetRow);
                expandToSelected();
                targetRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            return;
        }

        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        var action = btn.dataset.action;

        switch (action) {
            case 'close-modal':
                closeModal();
                break;
            case 'create-folder':
                createFolder();
                break;
            case 'rename-file':
                renameFile(parseInt(btn.dataset.fileId));
                break;
            case 'add-tag':
                addTagToEditor();
                break;
            case 'remove-tag':
                removeTagFromEditor(btn);
                break;
            case 'save-tags':
                saveFileTags(parseInt(btn.dataset.fileId));
                break;
            case 'execute-file-op':
                executeFileOp(btn.dataset.mode);
                break;
            case 'execute-batch-rename':
                executeBatchRename(btn);
                break;
            case 'select-folder':
                selectFolderTarget(parseInt(btn.dataset.folderId), btn);
                break;
            case 'toggle-folder-expand':
                e.stopPropagation();
                toggleFolderExpand(btn.closest('.folder-tree-node'), e);
                break;
            case 'create-share':
                createShare(parseInt(btn.dataset.fileId));
                break;
            case 'copy-share-url':
                copyShareUrl();
                break;
            case 'select-input':
                btn.select();
                break;
            case 'switch-excel-tab':
                switchExcelTab(btn);
                break;
        }
    });
}

function handleFileRowClick(fileId, isDir) {
    if (!isTouchDevice) return;
    if (isDir) {
        navigateTo(fileId);
    } else {
        previewFile(fileId);
    }
}

var _loadFilesDebounce = null;

function loadFiles(parentId, immediate) {
    if (parentId === undefined) parentId = 0;

    // 防抖：250ms 内重复调用只执行最后一次（操作回调中 immediate=true 跳过防抖）
    if (!immediate) {
        if (_loadFilesDebounce) clearTimeout(_loadFilesDebounce);
        var _pid = parentId;
        _loadFilesDebounce = setTimeout(function() { _doLoadFiles(_pid); }, 250);
        return;
    }
    _doLoadFiles(parentId);
}

function _doLoadFiles(parentId) {
    if (fileListAbort) {
        fileListAbort.abort();
    }
    fileListAbort = new AbortController();

    // 释放旧容器中的缩略图请求，释放网络连接
    var oldContainer = document.getElementById('fileList');
    if (oldContainer) {
        var oldImgs = oldContainer.querySelectorAll('img.file-thumbnail, img.grid-thumbnail');
        for (var i = 0; i < oldImgs.length; i++) {
            oldImgs[i].src = '';
        }
    }

    // 响应版本号：用于丢弃过期响应
    var requestParentId = parentId;
    currentParentId = parentId;

    // 通过 Store.mutate 触发订阅：updateBatchButtons 与 syncMasterCheckboxes
    Store.mutate('files.selectedIds', function (s) { s.clear(); });

    var container = document.getElementById('fileList');

    // 网格模式：清空容器，加载后渐显
    if (currentView === 'grid') {
        if (container) {
            container.classList.add('file-grid-mode');
            container.innerHTML = '';
        }
    }

    // 两种模式都显示 loading 指示器
    if (container) {
        var loadingEl = document.createElement('div');
        loadingEl.className = 'loading';
        loadingEl.id = 'fileListLoading';
        loadingEl.innerHTML = '<div class="spinner"></div>加载中...';
        container.appendChild(loadingEl);
    }

    // 面包屑和文件列表同时请求，互不等待
    loadBreadcrumb(parentId, fileListAbort.signal);

    // 检查缓存
    var cacheKey = parentId + ':' + currentSort + ':' + currentSortOrder;
    if (_fileListCache[cacheKey]) {
        var cached = _fileListCache[cacheKey];
        if (currentParentId === requestParentId) {
            renderFileList(cached);
            initDragSort();
        }
        return;
    }

    api('list_files', {parent_id: parentId, sort_by: currentSort, sort_order: currentSortOrder, page_size: 0}, 'GET', fileListAbort.signal)
        .then(function(data) {
            // 响应版本验证：丢弃过期响应
            if (currentParentId !== requestParentId) return;
            if (data.success) {
                _setFileListCache(cacheKey, data.files);
                renderFileList(data.files);
                initDragSort();
            }
        }).catch(function(err) {
            if (err.name === 'AbortError') return;
            if (currentParentId !== requestParentId) return;
            showToast('加载文件失败：' + (err.message || err), 'error');
            console.error('[loadFiles] error:', err);
        });
}

function renderTags(tags, fileId) {
    if (!tags || tags.length === 0) {
        return '<span class="tag-placeholder" data-action="edit-tags" data-file-id="' + fileId + '"><i class="fas fa-plus"></i> 添加标签</span>';
    }
    var tagColors = [
        'tag-blue', 'tag-green', 'tag-orange', 'tag-purple',
        'tag-pink', 'tag-cyan', 'tag-red', 'tag-teal'
    ];
    var html = '';
    for (var i = 0; i < tags.length; i++) {
        var colorClass = tagColors[i % tagColors.length];
        html += '<span class="tag ' + colorClass + '" title="点击编辑">' + escapeHtml(tags[i]) + '</span>';
    }
    html += '<button class="tag-add-btn" data-action="edit-tags" data-file-id="' + fileId + '" title="编辑标签"><i class="fas fa-pen"></i></button>';
    return html;
}

function showTagDialog(fileId, currentTags) {
    var tagListHtml = currentTags.map(function(tag) { return '<div class="tag-edit-item"><span>' + escapeHtml(tag) + '</span><button class="tag-remove-btn" data-action="remove-tag"><i class="fas fa-times"></i></button></div>'; }).join('');
    showModal('编辑标签',
        '<div class="tag-editor">' +
            '<div class="tag-current-list" id="tagCurrentList">' +
                tagListHtml +
                (currentTags.length === 0 ? '<p class="tag-empty-hint">暂无标签</p>' : '') +
            '</div>' +
            '<div class="tag-input-row">' +
                '<input type="text" id="tagInput" placeholder="输入标签名，回车添加">' +
                '<button class="btn btn-primary btn-sm" data-action="add-tag">添加</button>' +
            '</div>' +
            '<div style="display:flex;gap:8px;margin-top:8px">' +
                '<button class="btn btn-primary" style="flex:1" data-action="save-tags" data-file-id="' + fileId + '"><i class="fas fa-save"></i> 保存</button>' +
                '<button class="btn btn-glass" style="flex:1" data-action="close-modal">取消</button>' +
            '</div>' +
        '</div>',
        {html: true}
    );
    var tagInput = document.getElementById('tagInput');
    if (tagInput) {
        tagInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addTagToEditor();
            }
        });
        tagInput.focus();
    }
}

function handleTagInput(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        addTagToEditor();
    }
}

function addTagToEditor() {
    var input = document.getElementById('tagInput');
    var tag = input.value.trim();
    if (!tag) return;

    var list = document.getElementById('tagCurrentList');
    var emptyHint = list.querySelector('.tag-empty-hint');
    if (emptyHint) emptyHint.remove();

    var item = document.createElement('div');
    item.className = 'tag-edit-item';
    item.innerHTML = '<span>' + escapeHtml(tag) + '</span><button class="tag-remove-btn" data-action="remove-tag"><i class="fas fa-times"></i></button>';
    list.appendChild(item);
    input.value = '';
    input.focus();
}

function removeTagFromEditor(btn) {
    var item = btn.closest('.tag-edit-item');
    item.remove();
    var list = document.getElementById('tagCurrentList');
    if (list.children.length === 0) {
        list.innerHTML = '<p class="tag-empty-hint">暂无标签</p>';
    }
}

function saveFileTags(fileId) {
    var items = document.querySelectorAll('#tagCurrentList .tag-edit-item span');
    var tags = Array.from(items).map(function(el) { return el.textContent.trim(); }).filter(function(t) { return t.length > 0; });
    var snapshotParentId = currentParentId;
    api('update_tags', { file_id: fileId, tags: tags }).then(function(data) {
        if (data.success) {
            closeModal();
            invalidateFileListCache(snapshotParentId);
            loadFiles(snapshotParentId, true);
            showToast('标签已更新');
        } else {
            showToast(data.message, 'error');
        }
    });
}

function getFileIcon(icon) {
    var map = {
        image: '<i class="fas fa-image"></i>',
        video: '<i class="fas fa-film"></i>',
        audio: '<i class="fas fa-music"></i>',
        pdf: '<i class="fas fa-file-pdf"></i>',
        word: '<i class="fas fa-file-word"></i>',
        excel: '<i class="fas fa-file-excel"></i>',
        ppt: '<i class="fas fa-file-powerpoint"></i>',
        text: '<i class="fas fa-file-alt"></i>',
        archive: '<i class="fas fa-file-archive"></i>',
        code: '<i class="fas fa-code"></i>',
        file: '<i class="fas fa-file"></i>'
    };
    return map[icon] || '<i class="fas fa-file"></i>';
}

function showNewFolderDialog() {
    showModal('新建文件夹', '<div class="form-group"><label>文件夹名称</label><input type="text" id="newFolderName" placeholder="请输入文件夹名称" autofocus></div><button class="btn btn-primary" data-action="create-folder">创建</button>', {html: true});
    setTimeout(function() { var inp = document.getElementById('newFolderName'); if (inp) inp.focus(); }, 100);
}

function createFolder() {
    var name = document.getElementById('newFolderName').value.trim();
    if (!name) { showToast('请输入文件夹名称', 'error'); return; }
    var snapshotParentId = currentParentId;
    api('create_folder', {parent_id: snapshotParentId, folder_name: name}).then(function(data) {
        if (data.success) { closeModal(); invalidateFileListCache(snapshotParentId); loadFiles(snapshotParentId, true); showToast('文件夹创建成功'); }
        else { showToast(data.message, 'error'); }
    });
}

function downloadFile(fileId) {
    var file = null;
    for (var i = 0; i < currentFileList.length; i++) {
        if (currentFileList[i].id === fileId) { file = currentFileList[i]; break; }
    }
    if (file && !file.is_dir) {
        // 超过 500MB 的文件直接下载，避免浏览器内存 OOM
        if (file.filesize > 500 * 1024 * 1024) {
            window.location.href = 'index.php?action=download&file_id=' + fileId;
        } else {
            resumableDownload(fileId, file.filename, file.filesize);
        }
    } else {
        window.location.href = 'index.php?action=download&file_id=' + fileId;
    }
}

var DOWNLOAD_STORE_KEY = 'pancloud_dl_';

function resumableDownload(fileId, filename, totalSize) {
    var storeKey = DOWNLOAD_STORE_KEY + fileId;
    var saved = null;
    try {
        var raw = localStorage.getItem(storeKey);
        if (raw) { saved = JSON.parse(raw); }
    } catch (e) {}

    var startByte = 0;
    if (saved && saved.totalSize === totalSize && saved.received < totalSize) {
        startByte = saved.received;
    }

    var url = 'index.php?action=download&file_id=' + fileId;
    var headers = {};
    if (startByte > 0) {
        headers['Range'] = 'bytes=' + startByte + '-';
    }

    var received = startByte;
    var chunks = [];
    var contentHash = '';
    var aborted = false;

    var lastPersist = 0;
    function persist() {
        var now = Date.now();
        if (now - lastPersist < 500) return; // 节流：最多每 500ms 写一次
        lastPersist = now;
        try {
            localStorage.setItem(storeKey, JSON.stringify({
                fileId: fileId, filename: filename, totalSize: totalSize,
                received: received, contentHash: contentHash, updated: now
            }));
        } catch (e) {}
    }

    function clearStore() {
        try { localStorage.removeItem(storeKey); } catch (e) {}
    }

    function makeBlobAndSave() {
        var blob = new Blob(chunks);
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function() { URL.revokeObjectURL(a.href); }, 60000);
    }

    function verifyAndSave() {
        clearStore();
        if (contentHash && chunks.length > 0) {
            var reader = new FileReader();
            reader.onload = function() {
                var arrayBuffer = reader.result;
                crypto.subtle.digest('SHA-256', arrayBuffer).then(function(hashBuffer) {
                    var hashArray = Array.from(new Uint8Array(hashBuffer));
                    var hashHex = hashArray.map(function(b) { return b.toString(16).padStart(2, '0'); }).join('');
                    if (hashHex !== contentHash) {
                        showToast('文件完整性校验失败，请重新下载', 'error');
                        return;
                    }
                    makeBlobAndSave();
                }).catch(function() {
                    makeBlobAndSave();
                });
            };
            reader.onerror = function() { makeBlobAndSave(); };
            reader.readAsArrayBuffer(new Blob(chunks));
        } else {
            makeBlobAndSave();
        }
    }

    function cleanup() {
        aborted = true;
    }

    fetch(url, { headers: headers }).then(function(response) {
        if (startByte > 0 && response.status === 206) {
            received = startByte;
            chunks = [];
        } else if (startByte > 0 && response.status !== 206) {
            received = 0;
            chunks = [];
        } else {
            received = 0;
            chunks = [];
        }

        var ch = response.headers.get('X-Content-SHA256');
        if (ch) { contentHash = ch; }

        if (!response.body) {
            // Streams API 不可用，回退到一次性下载
            verifyAndSave();
            return;
        }
        var reader = response.body.getReader();

        function readChunk() {
            return reader.read().then(function(result) {
                if (result.done) {
                    if (received >= totalSize || totalSize === 0) {
                        verifyAndSave();
                    } else {
                        persist();
                        showToast('下载中断，已保存进度（' + formatSize(received) + '/' + formatSize(totalSize) + '），下次可续传', 'warning');
                    }
                    return;
                }
                if (aborted) { reader.cancel(); return; }
                chunks.push(result.value);
                received += result.value.byteLength;
                persist();
                return readChunk();
            }).catch(function() {
                persist();
                showToast('下载中断，已保存进度（' + formatSize(received) + '/' + formatSize(totalSize) + '），下次可续传', 'warning');
            });
        }

        return readChunk();
    }).catch(function(err) {
        persist();
        showToast('下载失败：' + (err.message || '网络错误'), 'error');
    });

    return { abort: cleanup };
}

function deleteFile(fileId) {
    var snapshotParentId = currentParentId;
    showConfirm('确定要删除此文件吗？文件将移至回收站。', function() {
        api('delete', {file_id: fileId}).then(function(data) {
            if (data.success) { invalidateFileListCache(snapshotParentId); loadFiles(snapshotParentId, true); showToast('已移至回收站'); }
            else { showToast(data.message, 'error'); }
        });
    });
}

function batchDelete() {
    if (selectedFiles.size === 0) return;
    var snapshotParentId = currentParentId;
    showConfirm('确定要删除选中的 ' + selectedFiles.size + ' 个文件吗？', function() {
        api('batch_delete', {file_ids: Array.from(selectedFiles)}).then(function(data) {
            if (data.success) { Store.mutate('files.selectedIds', function (s) { s.clear(); }); invalidateFileListCache(snapshotParentId); loadFiles(snapshotParentId, true); showToast('批量删除完成'); }
        });
    });
}

var _listRowTpl = null;
var _gridItemTpl = null;

function getListRowTemplate() {
    if (_listRowTpl) return _listRowTpl;
    _listRowTpl = document.createElement('template');
    _listRowTpl.innerHTML =
        '<div class="file-row" draggable="true" tabindex="0" role="button">' +
            '<div class="col-check"><input type="checkbox"></div>' +
            '<div class="col-name"><div class="file-name-wrap">' +
                '<span class="file-icon"><img class="file-thumbnail" style="display:none" loading="lazy" decoding="async"><i class="file-type-icon"></i></span>' +
                '<div class="file-name-main">' +
                    '<span class="file-name-text"></span>' +
                    '<div class="file-meta-mobile">' +
                        '<span class="file-meta-size"></span>' +
                        '<span class="file-meta-time"></span>' +
                    '</div>' +
                '</div>' +
                '<span class="file-fav" style="display:none"><i class="fas fa-star"></i></span>' +
                '<span class="file-lock" style="display:none;color:#ef4444"><i class="fas fa-lock"></i></span>' +
                '<span class="file-encrypt" style="display:none;color:#8b5cf6"><i class="fas fa-shield-alt"></i></span>' +
            '</div></div>' +
            '<div class="col-size"></div>' +
            '<div class="col-time"></div>' +
            '<div class="col-tags"></div>' +
            '<div class="col-actions">' +
                '<button class="btn-icon" style="width:30px;height:30px;font-size:13px" data-action="download" title="下载"><i class="fas fa-download"></i></button>' +
                '<button class="btn-icon" style="width:30px;height:30px;font-size:13px" data-action="share" title="分享"><i class="fas fa-link"></i></button>' +
                '<button class="btn-icon" style="width:30px;height:30px;font-size:13px" data-action="delete" title="删除"><i class="fas fa-trash-alt"></i></button>' +
                '<button class="btn-icon mobile-more-btn" data-action="mobile-more" title="更多"><i class="fas fa-ellipsis-v"></i></button>' +
            '</div>' +
        '</div>';
    return _listRowTpl;
}

function getGridItemTemplate() {
    if (_gridItemTpl) return _gridItemTpl;
    _gridItemTpl = document.createElement('template');
    _gridItemTpl.innerHTML =
        '<div class="grid-item" tabindex="0" role="button">' +
            '<div class="grid-check"><input type="checkbox"></div>' +
            '<div class="grid-icon"><img class="grid-thumbnail" style="display:none" loading="lazy" decoding="async"><i class="file-type-icon"></i></div>' +
            '<div class="grid-info">' +
                '<div class="grid-name"></div>' +
                '<div class="grid-size"></div>' +
            '</div>' +
            '<div class="grid-actions">' +
                '<button class="grid-action-btn" data-action="download" title="下载"><i class="fas fa-download"></i></button>' +
                '<button class="grid-action-btn" data-action="share" title="分享"><i class="fas fa-link"></i></button>' +
                '<button class="grid-action-btn" data-action="delete" title="删除"><i class="fas fa-trash-alt"></i></button>' +
                '<button class="grid-action-btn mobile-more-btn" data-action="mobile-more" title="更多"><i class="fas fa-ellipsis-v"></i></button>' +
            '</div>' +
        '</div>';
    return _gridItemTpl;
}

function renderFileList(files) {
    currentFileList = files;
    var container = document.getElementById('fileList');
    if (currentView === 'grid') {
        container.classList.add('file-grid-mode');
    } else {
        container.classList.remove('file-grid-mode');
    }
    var selectAllCheck = document.getElementById('selectAllCheck');
    if (selectAllCheck) {
        selectAllCheck.style.display = files.length > 0 ? '' : 'none';
    }
    if (files.length === 0) {
        container.innerHTML = '<div class="empty-state"><div class="empty-icon"><i class="fas fa-folder-open"></i></div><h3>暂无文件</h3><p>点击上传按钮添加文件</p></div>';
        return;
    }

    if (currentView === 'list') {
        var frag = document.createDocumentFragment();
        var header = document.createElement('div');
        header.className = 'file-table-header';
        header.innerHTML = '<div class="col-check"><input type="checkbox" id="headerSelectAll"></div><div class="col-name">名称</div><div class="col-size">大小</div><div class="col-time">修改时间</div><div class="col-tags">标签</div><div class="col-actions">操作</div>';
        frag.appendChild(header);

        var tpl = getListRowTemplate();
        for (var i = 0; i < files.length; i++) {
            var file = files[i];
            var row = tpl.content.cloneNode(true).firstElementChild;
            row.dataset.id = file.id;
            row.dataset.isDir = file.is_dir ? 'true' : 'false';
            if (selectedFiles.has(file.id)) row.classList.add('selected');

            var cb = row.querySelector('.col-check input');
            if (selectedFiles.has(file.id)) cb.checked = true;

            var iconSpan = row.querySelector('.file-icon');
            iconSpan.classList.add('icon-' + file.icon);
            var thumbImg = iconSpan.querySelector('.file-thumbnail');
            var typeIcon = iconSpan.querySelector('.file-type-icon');
            if (file.thumbnail_url && !file.is_dir) {
                thumbImg.src = file.thumbnail_url;
                thumbImg.style.display = '';
                typeIcon.innerHTML = getFileIcon(file.icon);
                typeIcon.style.display = 'none';
                (function(img, icon) {
                    img.addEventListener('error', function() { img.style.display = 'none'; icon.style.display = ''; });
                })(thumbImg, typeIcon);
            } else {
                thumbImg.style.display = 'none';
                typeIcon.innerHTML = file.is_dir ? '<i class="fas fa-folder"></i>' : getFileIcon(file.icon);
            }

            row.querySelector('.file-name-text').textContent = file.filename;

            if (file.is_favorite) {
                var fav = row.querySelector('.file-fav');
                fav.style.display = '';
            }
            if (file.is_locked) {
                var lock = row.querySelector('.file-lock');
                lock.style.display = '';
            }
            if (file.is_encrypted) {
                var enc = row.querySelector('.file-encrypt');
                enc.style.display = '';
            }

            row.querySelector('.col-size').textContent = file.is_dir ? '-' : file.filesize_formatted;
            row.querySelector('.col-time').textContent = file.updated_at_formatted;
            var metaMobile = row.querySelector('.file-meta-mobile');
            if (metaMobile) {
                if (file.is_dir) {
                    metaMobile.classList.add('folder-meta');
                    metaMobile.querySelector('.file-meta-size').textContent = '';
                    metaMobile.querySelector('.file-meta-time').textContent = file.updated_at_formatted;
                } else {
                    metaMobile.classList.remove('folder-meta');
                    metaMobile.querySelector('.file-meta-size').textContent = file.filesize_formatted;
                    metaMobile.querySelector('.file-meta-time').textContent = file.updated_at_formatted;
                }
            }
            row.querySelector('.col-tags').innerHTML = renderTags(file.tags || [], file.id);

            frag.appendChild(row);
        }
        container.innerHTML = '';
        container.appendChild(frag);

        var headerCb = document.getElementById('headerSelectAll');
        if (headerCb) {
            headerCb.addEventListener('change', function () { toggleSelectAll(this); });
        }
    } else {
        var frag = document.createDocumentFragment();
        var gridWrap = document.createElement('div');
        gridWrap.className = 'file-grid';

        var tpl = getGridItemTemplate();
        for (var i = 0; i < files.length; i++) {
            var file = files[i];
            var item = tpl.content.cloneNode(true).firstElementChild;
            item.dataset.id = file.id;
            item.dataset.isDir = file.is_dir ? 'true' : 'false';
            if (selectedFiles.has(file.id)) item.classList.add('selected');

            var cb = item.querySelector('.grid-check input');
            if (selectedFiles.has(file.id)) cb.checked = true;

            var iconDiv = item.querySelector('.grid-icon');
            iconDiv.classList.add('icon-' + file.icon);
            var thumbImg = iconDiv.querySelector('.grid-thumbnail');
            var typeIcon = iconDiv.querySelector('.file-type-icon');
            if (file.thumbnail_url && !file.is_dir) {
                thumbImg.src = file.thumbnail_url;
                thumbImg.style.display = '';
                typeIcon.innerHTML = getFileIcon(file.icon);
                typeIcon.style.display = 'none';
                (function(img, icon) {
                    img.addEventListener('error', function() { img.style.display = 'none'; icon.style.display = ''; });
                })(thumbImg, typeIcon);
            } else {
                thumbImg.style.display = 'none';
                typeIcon.innerHTML = file.is_dir ? '<i class="fas fa-folder"></i>' : getFileIcon(file.icon);
            }

            var nameEl = item.querySelector('.grid-name');
            nameEl.textContent = file.filename;
            nameEl.title = file.filename;
            item.querySelector('.grid-size').textContent = file.is_dir ? '' : file.filesize_formatted;

            gridWrap.appendChild(item);
        }
        frag.appendChild(gridWrap);
        container.innerHTML = '';
        container.appendChild(frag);
        initGridRubberBand();
    }
}

function navigateTo(parentId) {
    loadFiles(parentId);
}

function loadBreadcrumb(parentId, abortSignal) {
    if (parentId === 0) {
        document.getElementById('breadcrumb').innerHTML = '<span class="breadcrumb-item" data-parent-id="0">全部文件</span>';
        return;
    }
    api('breadcrumb', {parent_id: parentId}, 'GET', abortSignal).then(function (data) {
        if (data && data.success) {
            var html = '<span class="breadcrumb-item" data-parent-id="0">全部文件</span>';
            data.breadcrumb.forEach(function (item) {
                html += '<span class="breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>';
                html += '<span class="breadcrumb-item" data-parent-id="' + item.id + '">' + escapeHtml(item.filename) + '</span>';
            });
            document.getElementById('breadcrumb').innerHTML = html;
        }
    }).catch(function(err) {
        if (err && err.name === 'AbortError') return;
    });
}

function showMoveDialog() { showFileOpDialog('move'); }
function showCopyDialog() { showFileOpDialog('copy'); }

function showFileOpDialog(mode, fileIds) {
    var targetIds = fileIds || (contextFileId ? [contextFileId] : Array.from(selectedFiles));
    if (targetIds.length === 0) return;
    fileOpSourceIds = targetIds;

    var isMove = mode === 'move';
    var isInboxMove = mode === 'inbox-move';
    var title = isInboxMove ? '转存到网盘' : (isMove ? '移动到' : '复制到');
    var icon = isInboxMove ? 'fa-folder-open' : (isMove ? 'fa-arrows-alt' : 'fa-copy');
    var opClass = isInboxMove ? 'move' : (isMove ? 'move' : 'copy');
    var actionLabel = isInboxMove ? '转存' : (isMove ? '移动' : '复制');
    var opTitle = isInboxMove ? '转存' : (isMove ? '移动' : '复制');

    fileOpTargetId = 0;
    fileOpTargetName = '根目录';

    showModal(title,
        '<div class="file-op-dialog">' +
            '<div class="file-op-summary ' + opClass + '">' +
                '<div class="op-icon"><i class="fas ' + icon + '"></i></div>' +
                '<div class="op-info">' +
                    '<div class="op-title">' + opTitle + ' <span class="op-count">' + targetIds.length + '</span> 个文件</div>' +
                    '<div class="op-hint">选择目标文件夹后点击"' + actionLabel + '"</div>' +
                '</div>' +
            '</div>' +
            '<div class="folder-tree-toolbar">' +
                '<div class="folder-tree-path" id="folderTreePath">' +
                    '<i class="fas fa-folder-open"></i>' +
                    '<span class="path-current" data-folder-id="0">根目录</span>' +
                '</div>' +
                '<div class="folder-tree-search">' +
                    '<i class="fas fa-search"></i>' +
                    '<input type="text" id="folderTreeSearchInput" placeholder="搜索文件夹...">' +
                '</div>' +
            '</div>' +
            '<div class="folder-tree-box" id="folderTreeBox">' +
                '<div class="folder-tree-loading"><i class="fas fa-spinner fa-spin"></i> 加载中...</div>' +
            '</div>' +
            '<input type="hidden" id="fileOpTargetId" value="0">' +
            '<div class="file-op-footer">' +
                '<button class="btn btn-cancel" data-action="close-modal">取消</button>' +
                '<button class="btn btn-primary" id="fileOpExecuteBtn" data-action="execute-file-op" data-mode="' + mode + '">' +
                    '<i class="fas ' + icon + '"></i> ' + actionLabel +
                '</button>' +
            '</div>' +
        '</div>',
        {html: true}
    );

    var searchInput = document.getElementById('folderTreeSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            filterFolderTree(this.value);
        });
    }

    loadFolderTree();
}

function loadFolderTree(excludeIds) {
    excludeIds = excludeIds || [];
    var targetIds = contextFileId ? [contextFileId] : Array.from(selectedFiles);
    var allExclude = new Set(excludeIds.concat(targetIds));

    api('list_all_folders', {}, 'GET').then(function(data) {
        if (data.success) {
            folderTreeData = data.folders;
            document.getElementById('folderTreeBox').innerHTML = buildRootNodeHTML() + buildFolderTreeHTML(folderTreeData, allExclude);
            expandToSelected();
        } else {
            document.getElementById('folderTreeBox').innerHTML = '<div class="folder-tree-empty">加载失败</div>';
        }
    }).catch(function() {
        document.getElementById('folderTreeBox').innerHTML = '<div class="folder-tree-empty">加载失败</div>';
    });
}

function buildRootNodeHTML() {
    return '<div class="folder-tree-node"><div class="node-row selected" data-action="select-folder" data-folder-id="0"><i class="fas fa-chevron-right node-toggle leaf"></i><i class="fas fa-folder node-icon"></i><span class="node-name">根目录</span></div></div>';
}

function buildFolderTreeHTML(folders, excludeIds, depth) {
    depth = depth || 0;
    var html = '';
    folders.forEach(function(f) {
        if (excludeIds.has(f.id)) return;
        var childHTML = (f.children && f.children.length > 0) ? buildFolderTreeHTML(f.children, excludeIds, depth + 1) : '';
        var hasChildren = childHTML !== '';
        html += '<div class="folder-tree-node" data-depth="' + depth + '" data-folder-id="' + f.id + '" data-folder-name="' + escapeHtml(f.filename) + '">';
        html += '<div class="node-row" style="padding-left:' + (10 + (depth + 1) * 20) + 'px" data-action="select-folder" data-folder-id="' + f.id + '">';
        html += '<i class="fas fa-chevron-right node-toggle ' + (hasChildren ? '' : 'leaf') + '" data-action="toggle-folder-expand"></i>';
        html += '<i class="fas fa-folder node-icon"></i>';
        html += '<span class="node-name">' + escapeHtml(f.filename) + '</span>';
        html += '</div>';
        if (hasChildren) {
            html += '<div class="folder-tree-children">' + childHTML + '</div>';
        }
        html += '</div>';
    });
    return html;
}

function toggleFolderExpand(nodeEl, event) {
    if (event) {
        event.stopPropagation();
    }
    nodeEl.classList.toggle('expanded');
}

function selectFolderTarget(id, el) {
    document.getElementById('fileOpTargetId').value = id;
    fileOpTargetId = id;

    document.querySelectorAll('#folderTreeBox .node-row.selected').forEach(function(e) { e.classList.remove('selected'); });
    el.classList.add('selected');

    renderFolderPath(id);
}

function renderFolderPath(id) {
    var pathEl = document.getElementById('folderTreePath');
    if (!pathEl) return;

    if (id === 0) {
        fileOpTargetName = '根目录';
        pathEl.innerHTML = '<i class="fas fa-folder-open"></i><span class="path-current" data-folder-id="0">根目录</span>';
        return;
    }

    var pathInfo = getFolderPathInfoById(folderTreeData, id);
    var pathParts = pathInfo.names;
    var pathIds = pathInfo.ids;
    fileOpTargetName = pathParts[pathParts.length - 1] || '';

    var pathHtml = '<i class="fas fa-folder-open"></i>' +
        '<span class="path-root" data-folder-id="0">根目录</span>';
    pathParts.forEach(function(name, i) {
        var fid = pathIds[i] !== undefined ? pathIds[i] : id;
        var isLast = i === pathParts.length - 1;
        pathHtml += '<i class="fas fa-chevron-right path-sep"></i><span class="' + (isLast ? 'path-current' : 'path-part') + '" data-folder-id="' + fid + '">' + escapeHtml(name) + '</span>';
    });
    pathEl.innerHTML = pathHtml;
}

function getFolderPathInfoById(folders, id) {
    function find(folders, targetId, names, ids) {
        for (var i = 0; i < folders.length; i++) {
            var f = folders[i];
            if (f.id === targetId) {
                names.push(f.filename);
                ids.push(f.id);
                return true;
            }
            if (f.children && f.children.length > 0) {
                names.push(f.filename);
                ids.push(f.id);
                if (find(f.children, targetId, names, ids)) return true;
                names.pop();
                ids.pop();
            }
        }
        return false;
    }
    var names = [], ids = [];
    find(folders, id, names, ids);
    return { names: names, ids: ids };
}

function filterFolderTree(query) {
    var box = document.getElementById('folderTreeBox');
    if (!box) return;
    var nodes = box.querySelectorAll('.folder-tree-node');
    var normalizedQuery = query.toLowerCase().trim();

    if (normalizedQuery === '') {
        // 清除搜索：恢复所有节点显示，并折叠非选中路径的文件夹
        nodes.forEach(function(node) {
            node.style.display = '';
            node.classList.remove('expanded');
        });
        box.querySelectorAll('.folder-tree-children').forEach(function(child) {
            child.style.display = '';
        });
        // 保持当前选中目标及其父级展开，确保可见
        expandToSelected();
        removeFolderTreeEmptyState();
        return;
    }

    var matchCount = 0;
    nodes.forEach(function(node) {
        var name = (node.dataset.folderName || '').toLowerCase();
        if (name.indexOf(normalizedQuery) !== -1) {
            matchCount++;
            node.style.display = '';
            var parent = node.parentElement;
            while (parent) {
                if (parent.classList && parent.classList.contains('folder-tree-node')) {
                    parent.classList.add('expanded');
                    parent.style.display = '';
                }
                if (parent.classList && parent.classList.contains('folder-tree-children')) {
                    parent.style.display = 'block';
                }
                parent = parent.parentElement;
            }
        } else {
            node.style.display = 'none';
        }
    });

    if (matchCount === 0) {
        showFolderTreeEmptyState('未找到匹配的文件夹');
    } else {
        removeFolderTreeEmptyState();
    }
}

function expandToSelected() {
    var selected = document.querySelector('#folderTreeBox .node-row.selected');
    if (!selected) return;
    var node = selected.closest('.folder-tree-node');
    while (node) {
        node.classList.add('expanded');
        var parent = node.parentElement;
        if (parent && parent.classList && parent.classList.contains('folder-tree-children')) {
            parent.style.display = 'block';
        }
        node = parent ? parent.closest('.folder-tree-node') : null;
    }
}

function showFolderTreeEmptyState(message) {
    removeFolderTreeEmptyState();
    var box = document.getElementById('folderTreeBox');
    if (!box) return;
    var empty = document.createElement('div');
    empty.className = 'folder-tree-empty folder-tree-search-empty';
    empty.innerHTML = '<i class="fas fa-search"></i> ' + (message || '暂无数据');
    box.appendChild(empty);
}

function removeFolderTreeEmptyState() {
    var box = document.getElementById('folderTreeBox');
    if (!box) return;
    var empty = box.querySelector('.folder-tree-search-empty');
    if (empty) empty.remove();
}

function executeFileOp(mode) {
    var targetId = document.getElementById('fileOpTargetId').value;
    var fileIds = fileOpSourceIds && fileOpSourceIds.length > 0 ? fileOpSourceIds : (contextFileId ? [contextFileId] : Array.from(selectedFiles));

    if (targetId === undefined || targetId === null || targetId === '') {
        showToast('请选择目标文件夹', 'warning');
        return;
    }

    var targetIdNum = parseInt(targetId);
    var isInboxMove = mode === 'inbox-move';
    var action = isInboxMove ? 'inbox_move' : (mode === 'move' ? 'batch_move' : 'batch_copy');

    var btn = document.getElementById('fileOpExecuteBtn');
    var originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 处理中...';
    btn.disabled = true;

    var apiData = isInboxMove
        ? { file_id: fileIds[0], target_parent_id: targetIdNum }
        : { file_ids: JSON.stringify(fileIds), target_parent_id: targetIdNum };

    api(action, apiData).then(function(data) {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
        if (data.success) {
            closeModal();
            Store.mutate('files.selectedIds', function (s) { s.clear(); });
            contextFileId = null;
            if (isInboxMove) {
                loadInboxBox();
                loadStorageInfo();
            } else {
                invalidateFileListCache(currentParentId);
                loadFiles(currentParentId, true);
            }
            showToast(data.message);
        } else {
            // 显示具体失败原因
            var errMsg = data.message;
            if (data.errors && data.errors.length > 0) {
                errMsg += '：' + data.errors.slice(0, 3).join('；');
                if (data.errors.length > 3) errMsg += '…';
            }
            showToast(errMsg, 'error');
            // 如果有部分成功，刷新文件列表
            if (!isInboxMove && data.succeeded > 0) {
                invalidateFileListCache(currentParentId);
                loadFiles(currentParentId, true);
            }
        }
    }).catch(function() {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
        showToast('操作失败', 'error');
    });
}

function syncMasterCheckboxes() {
    var total = document.querySelectorAll('.file-row input[type="checkbox"], .grid-item input[type="checkbox"]').length;
    var checked = selectedFiles.size;
    var allChecked = total > 0 && checked === total;
    var noneChecked = checked === 0;
    document.querySelectorAll('.select-all-check input, .file-table-header .col-check input[type="checkbox"]').forEach(function (cb) {
        cb.checked = allChecked;
        cb.indeterminate = !allChecked && !noneChecked;
    });
}

function toggleSelect(fileId, checkbox) {
    Store.mutate('files.selectedIds', function (s) {
        if (checkbox.checked) s.add(fileId);
        else s.delete(fileId);
    });
    var row = checkbox.closest('.file-row, .grid-item');
    if (row) row.classList.toggle('selected', checkbox.checked);
}

function toggleSelectAll(checkbox) {
    var boxes = document.querySelectorAll('.file-row input[type="checkbox"], .grid-item input[type="checkbox"]');
    if (checkbox.checked) {
        boxes.forEach(function (b) { b.checked = true; });
        Store.mutate('files.selectedIds', function (s) {
            boxes.forEach(function (b) { s.add(parseInt(b.closest('.file-row, .grid-item').dataset.id)); });
        });
    } else {
        boxes.forEach(function (b) { b.checked = false; });
        Store.mutate('files.selectedIds', function (s) { s.clear(); });
    }
    document.querySelectorAll('.file-row, .grid-item').forEach(function (row) {
        row.classList.toggle('selected', checkbox.checked);
    });
}

function updateBatchButtons() {
    var show = selectedFiles.size > 0;
    var batchDeleteBtn = document.getElementById('batchDeleteBtn');
    var batchRenameBtn = document.getElementById('batchRenameBtn');
    var batchMoveBtn = document.getElementById('batchMoveBtn');
    var batchCopyBtn = document.getElementById('batchCopyBtn');
    if (batchDeleteBtn) batchDeleteBtn.style.display = show ? '' : 'none';
    if (batchRenameBtn) batchRenameBtn.style.display = show ? '' : 'none';
    if (batchMoveBtn) batchMoveBtn.style.display = show ? '' : 'none';
    if (batchCopyBtn) batchCopyBtn.style.display = show ? '' : 'none';
    var sep = document.querySelector('.toolbar-sep');
    if (sep) sep.style.display = show ? '' : 'none';

    // 同步移动端批量操作栏
    var mobileBatchBar = document.getElementById('mobileBatchBar');
    var mobileBatchInfo = document.getElementById('mobileBatchInfo');
    if (mobileBatchBar && mobileBatchInfo) {
        mobileBatchBar.classList.toggle('active', show);
        mobileBatchInfo.textContent = '已选择 ' + selectedFiles.size + ' 项';
        document.body.classList.toggle('batch-active', show);
    }
}

function showContextMenu(event, fileId, fileData) {
    event.preventDefault();
    event.stopPropagation();
    contextFileId = fileId;
    contextFileData = fileData;
    var menu = document.getElementById('contextMenu');
    menu.style.display = 'block';

    var x = event.clientX;
    var y = event.clientY;
    var menuWidth = menu.offsetWidth || 180;
    var menuHeight = menu.offsetHeight || 280;

    if (x + menuWidth > window.innerWidth) {
        x = window.innerWidth - menuWidth - 8;
    }
    if (y + menuHeight > window.innerHeight) {
        y = window.innerHeight - menuHeight - 8;
    }
    if (x < 8) x = 8;
    if (y < 8) y = 8;

    menu.style.left = x + 'px';
    menu.style.top = y + 'px';
}

function contextAction(action) {
    hideContextMenu();
    var fileId = contextFileId;
    var file = contextFileData;

    switch(action) {
        case 'download': downloadFile(fileId); break;
        case 'preview': previewFile(fileId); break;
        case 'share': showShareDialog(fileId); break;
        case 'favorite':
            var favParentId = currentParentId;
            api('toggle_favorite', {file_id: fileId}).then(function(data) {
                if (data.success) { invalidateFileListCache(favParentId); loadFiles(favParentId, true); showToast(data.message); }
            }); break;
        case 'lock':
            var lockParentId = currentParentId;
            api('toggle_lock', {file_id: fileId}).then(function(data) {
                if (data.success) { invalidateFileListCache(lockParentId); loadFiles(lockParentId, true); showToast(data.is_locked ? '已锁定' : '已解锁'); }
                else { showToast(data.message, 'error'); }
            }); break;
        case 'encrypt':
            if (file.is_dir) { showToast('文件夹不支持加密', 'error'); break; }
            var encParentId = currentParentId;
            api('toggle_encryption', {file_id: fileId}).then(function(data) {
                if (data.success) { invalidateFileListCache(encParentId); loadFiles(encParentId, true); showToast(data.message); }
                else { showToast(data.message, 'error'); }
            }); break;
        case 'tags':
            showTagDialog(fileId, file.tags || []);
            break;
        case 'rename':
            showModal('重命名', '<div class="form-group"><input type="text" id="renameInput" value="' + escapeHtml(file.filename) + '"></div><button class="btn btn-primary" data-action="rename-file" data-file-id="' + fileId + '">确定</button>', {html: true});
            setTimeout(function() { var inp = document.getElementById('renameInput'); if (inp) { inp.focus(); inp.select(); } }, 100);
            break;
        case 'move': showFileOpDialog('move'); break;
        case 'copy': showFileOpDialog('copy'); break;
        case 'info':
            api('file_info', {file_id: fileId}, 'GET').then(function(data) {
                if (data.success) {
                    var f = data.file;
                    showModal('文件详情',
                        '<div class="detail-row"><span>文件名</span><span>' + escapeHtml(f.filename) + '</span></div>' +
                        '<div class="detail-row"><span>大小</span><span>' + f.filesize_formatted + '</span></div>' +
                        '<div class="detail-row"><span>类型</span><span>' + (f.file_type || '文件夹') + '</span></div>' +
                        '<div class="detail-row"><span>创建时间</span><span>' + f.created_at_formatted + '</span></div>' +
                        '<div class="detail-row"><span>修改时间</span><span>' + f.updated_at_formatted + '</span></div>' +
                        '<div class="detail-row"><span>路径</span><span>' + escapeHtml(f.filepath) + '</span></div>',
                        {html: true}
                    );
                }
            }); break;
        case 'delete': deleteFile(fileId); break;
    }
}

function renameFile(fileId) {
    var newName = document.getElementById('renameInput').value.trim();
    if (!newName) { showToast('文件名不能为空', 'error'); return; }
    var snapshotParentId = currentParentId;
    api('rename', {file_id: fileId, new_name: newName}).then(function(data) {
        if (data.success) { closeModal(); invalidateFileListCache(snapshotParentId); loadFiles(snapshotParentId, true); showToast('重命名成功'); }
        else { showToast(data.message, 'error'); }
    });
}

function hideContextMenu() {
    var menu = document.getElementById('contextMenu');
    if (menu) {
        menu.style.display = 'none';
    }
}

document.addEventListener('click', hideContextMenu);

function cleanupFileListeners() {
    document.removeEventListener('click', hideContextMenu);
}

window.FilesCleanup = cleanupFileListeners;

function changeSort(sort) {
    currentSort = sort;
    if (sort === 'custom') currentSortOrder = 'asc';
    loadFiles(currentParentId, true);
}

function switchView(view) {
    if (view === currentView) return;
    currentView = view;
    try { localStorage.setItem('pancloud_view', view); } catch (e) {}
    document.querySelectorAll('#viewToggle .view-toggle-btn').forEach(function (b) {
        b.classList.toggle('active', b.dataset.view === view);
    });
    // 只重新渲染，不发请求
    renderFileList(currentFileList);
}

function initViewFromStorage() {
    try {
        var saved = localStorage.getItem('pancloud_view');
        if (saved === 'grid' || saved === 'list') {
            currentView = saved;
        }
    } catch (e) {}
    // 仅同步按钮状态，不触发重新渲染
    document.querySelectorAll('#viewToggle .view-toggle-btn').forEach(function (b) {
        b.classList.toggle('active', b.dataset.view === currentView);
    });
}

function handleSearch(event) {
    if (event.key === 'Enter') performSearch();
}

var _searchAbort = null;

function performSearch() {
    var keyword = document.getElementById('searchInput').value.trim();
    if (!keyword) { loadFiles(currentParentId, true); return; }
    if (_searchAbort) _searchAbort.abort();
    _searchAbort = new AbortController();
    api('search', {keyword: keyword}, 'GET', _searchAbort.signal).then(function(data) {
        if (data.success) renderFileList(data.files);
    }).catch(function(err) {
        if (err.name === 'AbortError') return;
    });
}

function switchPage(page, el) {
    // 切换页面时取消所有子页面的在途请求
    if (typeof _pageAbort !== 'undefined') {
        Object.keys(_pageAbort).forEach(function(k) {
            if (_pageAbort[k]) { _pageAbort[k].abort(); _pageAbort[k] = null; }
        });
    }

    document.querySelectorAll('.page').forEach(function(p) { p.classList.remove('active'); });
    document.querySelectorAll('.nav-item').forEach(function(n) { n.classList.remove('active'); });
    document.getElementById('page' + page.charAt(0).toUpperCase() + page.slice(1)).classList.add('active');
    if (el) el.classList.add('active');

    // 上传悬浮按钮仅在"全部文件"页面显示（桌面端；移动端由 CSS 隐藏）
    var floatWidget = document.getElementById('uploadFloatWidget');
    if (floatWidget) {
        floatWidget.style.display = (page === 'files') ? '' : 'none';
    }

    // 移动端浮动上传按钮仅在文件页面显示
    var mobileFab = document.getElementById('mobileFab');
    if (mobileFab) {
        mobileFab.style.display = (page === 'files') ? '' : 'none';
    }

    // 切换页面后关闭侧边栏抽屉（移动端汉堡菜单展开时）
    var sidebar = document.getElementById('sidebar');
    if (sidebar && sidebar.classList.contains('open')) {
        toggleSidebar();
    }

    var breadcrumb = document.getElementById('breadcrumb');
    var pageTitleMap = {
        'files': '全部文件',
        'favorites': '我的收藏',
        'recent': '最近访问',
        'shares': '我的分享',
        'inbox': '文件信箱',
        'trash': '回收站',
        'logs': '操作日志',
        'ai': 'AI 助手',
        'settings': '系统设置'
    };

    if (page === 'files') {
        breadcrumb.style.cursor = 'pointer';
        // 从其他页面返回文件页时，恢复用户离开前浏览的目录（currentParentId 仍保留着）
        if (breadcrumb.querySelector('.breadcrumb-page-title')) {
            loadBreadcrumb(currentParentId);
            if (!isFirstLoad) {
                loadFiles(currentParentId, true);
            }
            isFirstLoad = false;
            return;
        }
    } else {
        breadcrumb.innerHTML = '<span class="breadcrumb-page-title">' + (pageTitleMap[page] || '我的文件') + '</span>';
        breadcrumb.style.cursor = 'default';
    }

    switch(page) {
        case 'files':
            if (!isFirstLoad) {
                loadFiles(currentParentId, true);
            }
            isFirstLoad = false;
            break;
        case 'favorites':
            loadFavorites();
            break;
        case 'recent':
            loadRecent();
            break;
        case 'shares':
            loadShares();
            break;
        case 'inbox':
            loadInboxBox();
            break;
        case 'trash':
            loadTrash();
            break;
        case 'logs':
            loadOperationLogs();
            break;
        case 'ai':
            if (typeof AIChat !== 'undefined') {
                AIChat.renderSessionList();
                AIChat.updateContextBar();
            }
            break;
        case 'settings':
            loadSettings();
            break;
    }
}

function initGridRubberBand() {
    if (window._gridRbInit) return;
    window._gridRbInit = true;

    function getGrid() { return document.querySelector('.file-grid'); }

    var sx = 0, sy = 0;
    var _rubberBandCtrl = false;
    var scrollInterval = null;
    var scrollContainer = null;
    var band = null;

    function ensureBand() {
        var g = getGrid();
        if (!g) return null;
        band = g.querySelector('.rubber-band');
        if (!band) {
            band = document.createElement('div');
            band.className = 'rubber-band';
            g.appendChild(band);
        }
        return g;
    }

    // 找到可滚动的父容器
    function findScrollContainer(el) {
        while (el && el !== document.body) {
            var style = window.getComputedStyle(el);
            if (style.overflowY === 'auto' || style.overflowY === 'scroll') return el;
            el = el.parentElement;
        }
        return document.querySelector('.content-area') || null;
    }

    function startAutoScroll() {
        if (!scrollContainer) scrollContainer = findScrollContainer(getGrid());
        if (scrollInterval) return;
        scrollInterval = setInterval(function() {
            if (!_rubberBand.active) { stopAutoScroll(); return; }
            var my = window._lastMouseY;
            if (my === undefined) return;
            var rect = scrollContainer.getBoundingClientRect();
            var edgeSize = 40;
            var topDist = my - rect.top;
            var botDist = rect.bottom - my;
            var speed = 0;
            if (topDist < edgeSize) {
                speed = -Math.max(30, (edgeSize - topDist) * 1.5);
            } else if (botDist < edgeSize) {
                speed = Math.max(30, (edgeSize - botDist) * 1.5);
            } else {
                stopAutoScroll();
                return;
            }
            scrollContainer.scrollTop += speed;
            // 鼠标不动时也更新框选
            var gr = getGrid().getBoundingClientRect();
            var mx = window._lastMouseX;
            if (mx !== undefined && my !== undefined) {
                var ncx = mx - gr.left, ncy = my - gr.top;
                var nl = Math.min(sx, ncx), nt = Math.min(sy, ncy);
                var nw = Math.abs(ncx - sx), nh = Math.abs(ncy - sy);
                updateBox({ l: nl, t: nt, r: nl + nw, b: nt + nh }, gr);
            }
        }, 20);
    }

    function stopAutoScroll() {
        if (scrollInterval) {
            clearInterval(scrollInterval);
            scrollInterval = null;
        }
    }

    document.addEventListener("mousedown", function (e) {
        if (e.button !== 0) return;
        if (!e.target.closest('.file-grid')) return;
        if (e.target.closest('.grid-check') || e.target.closest('.grid-action-btn')) return;
        var g = ensureBand();
        if (!g) return;
        var r = g.getBoundingClientRect();
        sx = e.clientX - r.left;
        sy = e.clientY - r.top;
        _rubberBand.active = true;
        _rubberBand.drag = false;
        _rubberBandCtrl = e.ctrlKey || e.metaKey;
        band.style.display = 'none';
        scrollContainer = null;

        // 非 Ctrl 框选：点下时清空已有选中
        if (!_rubberBandCtrl) {
            document.querySelectorAll('.grid-item').forEach(function(item) {
                item.classList.remove('selected');
                var c = item.querySelector('input[type="checkbox"]');
                if (c) c.checked = false;
            });
            // 如果点在文件上，把它加入选中（拖拽时以此为基础）
            var startItem = e.target.closest('.grid-item');
            var sid = startItem ? parseInt(startItem.dataset.id) : 0;
            // 把 clear + 可选 add 合并到一次 mutate，仅触发一次订阅
            Store.mutate('files.selectedIds', function (s) {
                s.clear();
                if (startItem) s.add(sid);
            });
            if (startItem) {
                startItem.classList.add('selected');
                var sc = startItem.querySelector('input[type="checkbox"]');
                if (sc) sc.checked = true;
            }
        }

        e.preventDefault();
    });

    function updateBox(rect, gridRect) {
        var sr = { left: rect.l + gridRect.left, top: rect.t + gridRect.top, right: rect.r + gridRect.left, bottom: rect.b + gridRect.top };
        // 拖拽过程中可能命中多个 item，合并为一次 mutate 触发，避免重复刷新 UI
        Store.mutate('files.selectedIds', function (s) {
            document.querySelectorAll('.grid-item').forEach(function (item) {
                var ir = item.getBoundingClientRect();
                var hit = !(ir.right < sr.left || ir.left > sr.right || ir.bottom < sr.top || ir.top > sr.bottom);
                var cb = item.querySelector('input[type="checkbox"]');
                if (cb) {
                    cb.checked = hit;
                    item.classList.toggle('selected', hit);
                    if (hit) s.add(parseInt(item.dataset.id));
                    else s.delete(parseInt(item.dataset.id));
                }
            });
            if (!_rubberBandCtrl) {
                document.querySelectorAll('.grid-item').forEach(function (item) {
                    var ir = item.getBoundingClientRect();
                    var inside = !(ir.right < sr.left || ir.left > sr.right || ir.bottom < sr.top || ir.top > sr.bottom);
                    if (!inside) {
                        var cb = item.querySelector('input[type="checkbox"]');
                        if (cb && cb.checked) {
                            cb.checked = false;
                            item.classList.remove('selected');
                            s.delete(parseInt(item.dataset.id));
                        }
                    }
                });
            }
        });
    }

    function _onMouseMove(e) {
        if (!_rubberBand.active) return;
        if (!band) { endRubber(); return; }
        
        window._lastMouseX = e.clientX;
        window._lastMouseY = e.clientY;
        
        var g = getGrid();
        if (!g) return;
        var r = g.getBoundingClientRect();
        var cx = e.clientX - r.left;
        var cy = e.clientY - r.top;
        var dx = cx - sx, dy = cy - sy;

        // 自动滚动画布
        if (scrollContainer) {
            var scr = scrollContainer.getBoundingClientRect();
            var edgeSize = 40;
            if (e.clientY < scr.top + edgeSize || e.clientY > scr.bottom - edgeSize) {
                startAutoScroll();
            } else {
                stopAutoScroll();
            }
        } else {
            scrollContainer = findScrollContainer(getGrid());
        }

        if (band.style.display === 'none' && Math.abs(dx) <= 5 && Math.abs(dy) <= 5) return;

        if (band.style.display === 'none') {
            band.style.display = 'block';
            band.style.left = sx + 'px';
            band.style.top = sy + 'px';
            band.style.width = '0px';
            band.style.height = '0px';
            _rubberBand.drag = true;
        }

        var l = Math.min(sx, cx), t = Math.min(sy, cy);
        var w = Math.abs(dx), h = Math.abs(dy);
        band.style.left = l + 'px';
        band.style.top = t + 'px';
        band.style.width = w + 'px';
        band.style.height = h + 'px';

        updateBox({ l: l, t: t, r: l + w, b: t + h }, r);
        e.preventDefault();
    }

    function _onMouseUp(e) {
        if (_rubberBand.active) endRubber();
    }

    // 拖拽期间全局监听，鼠标移出 grid 也能继续工作
    document.addEventListener('mousemove', _onMouseMove);
    document.addEventListener('mouseup', _onMouseUp);

    function endRubber() {
        _rubberBand.active = false;
        band.style.display = 'none';
        stopAutoScroll();
    }

    // 鼠标拖出浏览器窗口后释放的兜底
    window.addEventListener('blur', function _winBlur() {
        if (_rubberBand.active) endRubber();
    });

    // 框选拖动过则阻止后续 click 事件
    document.addEventListener('click', function (e) {
        if (_rubberBand.drag) {
            e.stopPropagation();
            _rubberBand.drag = false;
            return;
        }

        var g = getGrid();
        if (!g) return;
        if (!e.target.closest('.file-grid')) return;

        // 点空白区域 → 取消全选（Ctrl 按下时不取消，留待框选补充）
        if ((e.target === g || e.target.classList.contains('file-grid')) && !e.ctrlKey && !e.metaKey) {
            document.querySelectorAll('.grid-item').forEach(function(item) {
                item.classList.remove('selected');
                var cb = item.querySelector('input[type="checkbox"]');
                if (cb) cb.checked = false;
            });
            Store.mutate('files.selectedIds', function (s) { s.clear(); });
            return;
        }

        // 修饰键 + 点击：选中操作，不触发预览/导航
        var item = e.target.closest('.grid-item');
        if (item && (e.shiftKey || e.ctrlKey || e.metaKey) && !e.target.closest('.grid-check') && !e.target.closest('.grid-action-btn')) {
            var id = parseInt(item.dataset.id);
            if (e.shiftKey) {
                // Shift + 点击：范围选择
                e.stopPropagation();
                var items = document.querySelectorAll('.grid-item');
                var lastId = window._gridLastClicked || id;
                var selecting = false, done = false;
                Store.mutate('files.selectedIds', function (s) {
                    s.clear();
                    items.forEach(function(g) {
                        if (done) return;
                        var gid = parseInt(g.dataset.id);
                        if (gid === lastId || gid === id) {
                            selecting = !selecting;
                            if (!selecting) done = true;
                        }
                        g.classList.toggle('selected', selecting || gid === id || gid === lastId);
                        var c = g.querySelector('input[type="checkbox"]');
                        if (c) c.checked = selecting || gid === id || gid === lastId;
                        if (selecting || gid === id || gid === lastId) s.add(gid);
                    });
                });
            } else if (e.ctrlKey || e.metaKey) {
                // Ctrl + 点击：切换当前项，不取消其他
                e.stopPropagation();
                var cb = item.querySelector('input[type="checkbox"]');
                if (cb) {
                    cb.checked = !cb.checked;
                    item.classList.toggle('selected', cb.checked);
                    Store.mutate('files.selectedIds', function (s) {
                        if (cb.checked) s.add(id);
                        else s.delete(id);
                    });
                }
            }
            window._gridLastClicked = id;
        }
    }, true);
}
