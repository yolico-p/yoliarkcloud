/**
 * 上传子系统 - 分片上传、并发控制、进度追踪、中断感知
 */

var MAX_CONCURRENT_UPLOADS = 1;

function setupUploadDelegation() {
    var floatList = document.getElementById('uploadFloatList');
    if (floatList) {
        floatList.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action="cancel-upload"]');
            if (btn) uploadManager.cancelTask(btn.dataset.taskId);
        });
    }

    var queue = document.getElementById('uploadQueue');
    if (queue) {
        queue.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action="cancel-upload"]');
            if (btn) uploadManager.cancelTask(btn.dataset.taskId);
        });
    }
}

function _requestUploadRefresh() {
    _uploadRefreshNeeded = true;
}

function _removeQueueItem(id) {
    setTimeout(function () {
        var el = document.getElementById(id);
        if (el) el.remove();
    }, 3000);
}

var uploadSession = {
    totalFiles: 0,
    totalSize: 0,
    active: false,
    allFiles: [],

    start(files) {
        var filesArray = Array.from(files);
        this.totalFiles = filesArray.length;
        this.totalSize = filesArray.reduce(function(sum, f) { return sum + f.size; }, 0);
        this.active = true;
        this.allFiles = filesArray.map(function(f) { return { name: f.name, size: f.size }; });
        uploadManager.resetProgress();
        uploadConflict.reset();
        try {
            localStorage.removeItem('uploadTasks');
            localStorage.removeItem('uploadSession');
            localStorage.removeItem('uploadAllFiles');
        } catch (e) {}
    },

    reset() {
        this.totalFiles = 0;
        this.totalSize = 0;
        this.active = false;
        this.allFiles = [];
    },

    getProgressInfo() {
        if (!this.active || this.totalSize === 0) {
            return null;
        }

        var totalUploaded = Array.from(uploadManager.tasks.values()).reduce(function(sum, t) {
            if (t.status === 'success') return sum + t.size;
            if (t.status === 'error') return sum + 0;
            return sum + Math.round((t.progress / 100) * t.size);
        }, 0);

        var overallProgress = Math.round((totalUploaded / this.totalSize) * 100);
        var uploadedMB = (totalUploaded / (1024 * 1024)).toFixed(1);
        var totalMB = (this.totalSize / (1024 * 1024)).toFixed(1);

        return {
            overallProgress: overallProgress,
            uploadedMB: uploadedMB,
            totalMB: totalMB,
            totalUploaded: totalUploaded,
            totalSize: this.totalSize
        };
    }
};

var uploadManager = {
    tasks: new Map(),
    isPanelOpen: false,
    _persistTimer: null,

    _flush() {
        this._persistTimer = null;
        this.saveToStorage();
        this.updateFloatWidget();
    },

    _schedulePersist() {
        if (this._persistTimer) return;
        this._persistTimer = setTimeout(function() { this._flush(); }.bind(this), 2000);
    },

    _taskList() {
        return Array.from(this.tasks.values());
    },

    resetProgress() {
        this.tasks.clear();
        if (this._persistTimer) {
            clearTimeout(this._persistTimer);
            this._persistTimer = null;
        }
        this.saveToStorage();
        this.updateFloatWidget();
    },

    addTask(id, filename, size) {
        this.tasks.set(id, {
            id: id,
            filename: filename,
            size: size,
            uploaded: 0,
            progress: 0,
            status: 'uploading'
        });
        this.saveToStorage();
        this.updateFloatWidget();
        this.showFloatWidget();
    },

    removeTask(id) {
        this.tasks.delete(id);
        this.saveToStorage();
        this.updateFloatWidget();
    },

    updateTask(id, progress, status) {
        var task = this.tasks.get(id);
        if (task) {
            task.progress = progress;
            task.uploaded = Math.round((progress / 100) * task.size);
            if (status) task.status = status;
            this._schedulePersist();
        }
    },

    cancelTask(id) {
        if (activeUploads[id]) {
            if (typeof activeUploads[id].abort === 'function') {
                activeUploads[id].abort();
            } else if (activeUploads[id].abort) {
                activeUploads[id].abort();
            }
            delete activeUploads[id];
        }

        var queueItem = document.getElementById(id);
        if (queueItem) queueItem.remove();

        var statusEl = document.getElementById(id + '_status');
        if (statusEl) {
            statusEl.innerHTML = '<i class="fas fa-ban" style="color:var(--text-muted)"></i> 已取消';
        }

        uploadManager.updateTask(id, (uploadManager.tasks.get(id) || {}).progress || 0, 'error');
        uploadFinished();

        api('cancel_upload', {upload_id: id}).then(function() {}).catch(function() {});
    },

    updateFloatWidget() {
        var widget = document.getElementById('uploadFloatWidget');
        var mini = document.getElementById('uploadFloatMini');
        if (this.tasks.size === 0) {
            mini.classList.add('idle');
            return;
        }

        var list = this._taskList();
        var sessionInfo = uploadSession.getProgressInfo();
        var uploadingTasks = list.filter(function(t) { return t.status === 'uploading'; });

        if (sessionInfo) {
            document.getElementById('uploadFloatText').textContent = '上传中 ' + sessionInfo.overallProgress + '% (' + sessionInfo.uploadedMB + '/' + sessionInfo.totalMB + 'MB)';
        } else {
            document.getElementById('uploadFloatText').textContent = '全部完成';
        }

        if (uploadingTasks.length === 0 && list.length > 0) {
            setTimeout(function() {
                if (this._taskList().every(function(t) { return t.status !== 'uploading'; })) {
                    mini.classList.add('idle');
                    this.tasks.clear();
                    uploadSession.reset();
                    this.saveToStorage();
                }
            }.bind(this), 3000);
        }

        this.updateFloatList();
    },

    updateFloatList() {
        var list = document.getElementById('uploadFloatList');
        if (!list) return;

        var count = 0;
        var html = '';
        this.tasks.forEach(function(task) {
            if (task.status === 'success') return;
            var statusText = task.status === 'uploading' ? '上传中...' :
                              task.status === 'success' ? '完成' :
                              task.status === 'error' ? '上传失败' : '';
            var statusClass = task.status === 'success' ? 'success' :
                               task.status === 'error' ? 'error' : '';
            var showCancel = task.status === 'uploading';

            html += '<div class="upload-float-item">' +
                '<div class="upload-float-item-header">' +
                    '<span class="upload-float-item-name" title="' + escapeHtml(task.filename) + '">' + escapeHtml(task.filename) + '</span>' +
                    '<span class="upload-float-item-percent">' + task.progress + '%</span>' +
                    (showCancel ? '<button class="upload-float-cancel-btn" data-action="cancel-upload" data-task-id="' + task.id + '" title="取消上传"><i class="fas fa-times"></i></button>' : '') +
                '</div>' +
                '<div class="upload-float-item-bar">' +
                    '<div class="upload-float-item-fill" style="width:' + task.progress + '%"></div>' +
                '</div>' +
                '<div class="upload-float-item-status ' + statusClass + '">' + statusText + '</div>' +
            '</div>';
        });
        list.innerHTML = html;
    },

    showFloatWidget() {
        var mini = document.getElementById('uploadFloatMini');
        if (mini) mini.classList.remove('idle');
    },

    hideFloatWidget() {
        var mini = document.getElementById('uploadFloatMini');
        if (mini) mini.classList.add('idle');
    },

    saveToStorage() {
        try {
            localStorage.setItem('uploadTasks', JSON.stringify(this._taskList()));
            if (uploadSession.active) {
                localStorage.setItem('uploadSession', JSON.stringify({
                    totalFiles: uploadSession.totalFiles,
                    totalSize: uploadSession.totalSize,
                    active: uploadSession.active
                }));
                localStorage.setItem('uploadAllFiles', JSON.stringify(uploadSession.allFiles));
            } else {
                localStorage.removeItem('uploadSession');
                localStorage.removeItem('uploadAllFiles');
            }
        } catch (e) {}
    },

    loadFromStorage() {
        try {
            var saved = localStorage.getItem('uploadTasks');
            if (saved) {
                var arr = JSON.parse(saved);
                this.tasks = new Map(arr.map(function(t) { return [t.id, t]; }));
                var sessionSaved = localStorage.getItem('uploadSession');
                var allFilesSaved = localStorage.getItem('uploadAllFiles');
                var allFilesList = allFilesSaved ? JSON.parse(allFilesSaved) : [];
                if (sessionSaved) {
                    var sessionData = JSON.parse(sessionSaved);
                    uploadSession.totalFiles = sessionData.totalFiles || 0;
                    uploadSession.totalSize = sessionData.totalSize || 0;
                    uploadSession.active = sessionData.active || false;
                    uploadSession.allFiles = allFilesList;
                }
                var list = this._taskList();
                var hasUploading = list.some(function(t) { return t.status === 'uploading'; });
                if (hasUploading || uploadSession.active) {
                    var incomplete = uploadSession.allFiles.filter(function(f) {
                        var matchingTask = uploadManager.tasks.get(f.name);
                        return !matchingTask || matchingTask.status !== 'success';
                    }).map(function(f) {
                        var matchingTask = uploadManager.tasks.get(f.name);
                        return {
                            filename: f.name,
                            size: f.size,
                            progress: matchingTask ? matchingTask.progress : 0,
                            status: matchingTask ? matchingTask.status : 'pending'
                        };
                    });
                    if (incomplete.length > 0) {
                        this.tasks.clear();
                        uploadSession.reset();
                        localStorage.removeItem('uploadTasks');
                        localStorage.removeItem('uploadSession');
                        localStorage.removeItem('uploadAllFiles');
                        showInterruptDialog(incomplete);
                        return;
                    }
                }
                this.tasks.clear();
                uploadSession.reset();
                localStorage.removeItem('uploadTasks');
                localStorage.removeItem('uploadSession');
                localStorage.removeItem('uploadAllFiles');
            }
        } catch (e) {}
    }
};

/**
 * 文件冲突处理器。
 *
 * 把原本散落的全局函数（addUploadConflict / renderBatchConflictDialog /
 * resolveConflictItem / resolveAllConflicts / closeBatchConflictDialog /
 * resolveSingleConflict / retryUploadWithResolution / resolveChunkedConflict）
 * 收敛为一个对象，减少全局函数污染；内部状态 pendingConflicts /
 * batchResolution 也封装在对象内，避免被外部意外篡改。
 *
 * 调用方（uploadFile / uploadChunked / uploadSession.start）通过
 * uploadConflict.add(...) / uploadConflict.reset() 访问。
 */
const uploadConflict = {
    pendingConflicts: [],
    batchResolution: null,

    reset() {
        this.pendingConflicts = [];
        this.batchResolution = null;
    },

    add(conflictData, itemId, type, extra) {
        if (this.batchResolution) {
            this.resolveSingle(itemId, this.batchResolution, type, extra);
            return;
        }

        this.pendingConflicts.push({
            itemId: itemId,
            filename: conflictData.duplicate_filename || '',
            message: conflictData.message,
            type: type,
            file: extra.file || null,
            parentId: extra.parentId || null,
            uploadId: extra.uploadId || conflictData.upload_id || null,
            relativePath: extra.relativePath || '',
        });

        this.renderDialog();
    },

    renderDialog() {
        let overlay = document.getElementById('uploadConflictOverlay');

        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.id = 'uploadConflictOverlay';
            document.body.appendChild(overlay);
            requestAnimationFrame(function() { overlay.classList.add('active'); });
        }

        const count = this.pendingConflicts.length;

        let listHtml = '';
        this.pendingConflicts.forEach(function(c, i) {
            const ext = c.filename.split('.').pop().toLowerCase();
            const iconMap = {
                jpg: 'fa-image', jpeg: 'fa-image', png: 'fa-image', gif: 'fa-image', webp: 'fa-image', svg: 'fa-image', bmp: 'fa-image',
                mp4: 'fa-film', avi: 'fa-film', mkv: 'fa-film', mov: 'fa-film', flv: 'fa-film', webm: 'fa-film',
                mp3: 'fa-music', wav: 'fa-music', flac: 'fa-music', aac: 'fa-music', ogg: 'fa-music',
                pdf: 'fa-file-pdf', doc: 'fa-file-word', docx: 'fa-file-word', xls: 'fa-file-excel', xlsx: 'fa-file-excel',
                zip: 'fa-file-archive', rar: 'fa-file-archive', '7z': 'fa-file-archive',
                txt: 'fa-file-alt', md: 'fa-file-alt', log: 'fa-file-alt',
            };
            const icon = iconMap[ext] || 'fa-file';

            listHtml += '<div class="conflict-file-item" id="conflict_' + i + '" style="display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:8px;background:var(--bg-glass);margin-bottom:8px">' +
                    '<i class="fas ' + icon + '" style="color:var(--accent-warning);font-size:18px;flex-shrink:0"></i>' +
                    '<div style="flex:1;min-width:0">' +
                        '<div style="font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="' + escapeHtml(c.filename) + '">' + escapeHtml(c.filename) + '</div>' +
                        '<div style="font-size:12px;color:var(--text-muted);margin-top:2px">' + escapeHtml(c.message) + '</div>' +
                    '</div>' +
                    '<div style="display:flex;gap:6px;flex-shrink:0">' +
                        '<button class="btn btn-sm" style="padding:4px 10px;font-size:12px;background:var(--accent-primary);color:#fff;border:none;border-radius:6px;cursor:pointer" data-action="resolve-conflict" data-index="' + i + '" data-resolution="overwrite">覆盖</button>' +
                        '<button class="btn btn-sm" style="padding:4px 10px;font-size:12px;background:var(--bg-glass);color:var(--text-secondary);border:1px solid var(--bg-glass-border);border-radius:6px;cursor:pointer" data-action="resolve-conflict" data-index="' + i + '" data-resolution="keep_both">副本</button>' +
                        '<button class="btn btn-sm" style="padding:4px 10px;font-size:12px;background:transparent;color:var(--text-muted);border:1px solid var(--bg-glass-border);border-radius:6px;cursor:pointer" data-action="resolve-conflict" data-index="' + i + '" data-resolution="cancel">取消</button>' +
                    '</div>' +
                '</div>';
        });

        overlay.innerHTML =
            '<div class="modal-box glass-strong" style="max-width:560px">' +
                '<div class="modal-header">' +
                    '<h3>文件冲突 <span style="font-size:13px;color:var(--text-muted);font-weight:400">' + count + ' 个文件</span></h3>' +
                    '<button class="modal-close" data-action="close-conflict-dialog"><i class="fas fa-times"></i></button>' +
                '</div>' +
                '<div class="modal-body" style="max-height:50vh;overflow-y:auto">' +
                    listHtml +
                '</div>' +
                '<div style="padding:12px 20px 16px;border-top:1px solid var(--bg-glass-border)">' +
                    '<div style="display:flex;gap:8px;margin-bottom:10px">' +
                        '<button class="btn btn-primary" style="flex:1" data-action="resolve-all-conflicts" data-resolution="overwrite">' +
                            '<i class="fas fa-sync-alt"></i> 全部覆盖' +
                        '</button>' +
                        '<button class="btn btn-glass" style="flex:1" data-action="resolve-all-conflicts" data-resolution="keep_both">' +
                            '<i class="fas fa-copy"></i> 全部保留副本' +
                        '</button>' +
                        '<button class="btn btn-glass" style="flex:1;color:var(--text-muted)" data-action="resolve-all-conflicts" data-resolution="cancel">' +
                            '<i class="fas fa-ban"></i> 全部取消' +
                        '</button>' +
                    '</div>' +
                    '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--text-secondary)">' +
                        '<input type="checkbox" id="conflictAutoApply" style="accent-color:var(--accent-primary)">' +
                        '对后续冲突自动应用相同操作' +
                    '</label>' +
                '</div>' +
            '</div>';

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) { this.closeDialog(); return; }
            const btn = e.target.closest('[data-action]');
            if (!btn) return;
            const action = btn.dataset.action;
            if (action === 'close-conflict-dialog') { this.closeDialog(); }
            else if (action === 'resolve-conflict') { this.resolveItem(parseInt(btn.dataset.index), btn.dataset.resolution); }
            else if (action === 'resolve-all-conflicts') { this.resolveAll(btn.dataset.resolution); }
        });
    },

    resolveItem(index, resolution) {
        const conflict = this.pendingConflicts[index];
        if (!conflict) return;

        const el = document.getElementById('conflict_' + index);
        if (el) {
            const labelMap = { overwrite: '已覆盖', keep_both: '保留副本', cancel: '已取消' };
            const colorMap = { overwrite: 'var(--accent-primary)', keep_both: 'var(--accent-success)', cancel: 'var(--text-muted)' };
            el.innerHTML = '<i class="fas fa-check" style="color:' + colorMap[resolution] + ';font-size:16px;flex-shrink:0"></i>' +
                '<div style="flex:1;min-width:0">' +
                    '<div style="font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + escapeHtml(conflict.filename) + '</div>' +
                '</div>' +
                '<span style="font-size:12px;color:' + colorMap[resolution] + '">' + labelMap[resolution] + '</span>';
        }

        this.resolveSingle(conflict.itemId, resolution, conflict.type, conflict);

        this.pendingConflicts[index] = null;

        const remaining = this.pendingConflicts.filter(function(c) { return c !== null; });
        if (remaining.length === 0) {
            this.closeDialog();
        }
    },

    resolveAll(resolution) {
        const autoApply = document.getElementById('conflictAutoApply');
        if (autoApply && autoApply.checked) {
            this.batchResolution = resolution;
        }

        this.pendingConflicts.forEach(function(conflict) {
            if (!conflict) return;
            uploadConflict.resolveSingle(conflict.itemId, resolution, conflict.type, conflict);
        });

        this.pendingConflicts = [];
        this.closeDialog();
    },

    closeDialog() {
        const overlay = document.getElementById('uploadConflictOverlay');
        if (overlay) {
            overlay.classList.remove('active');
            setTimeout(function() { overlay.remove(); }, 300);
        }

        // 不自动取消冲突中的上传，保留待处理队列
        // 用户在对话框中选择「取消所有」时通过 resolveAll('cancel') 处理
    },

    resolveSingle(itemId, resolution, type, extra) {
        if (resolution === 'cancel') {
            const statusEl = document.getElementById(itemId + '_status');
            if (statusEl) {
                statusEl.innerHTML = '<i class="fas fa-ban" style="color:var(--text-muted)"></i> 已取消';
            }
            uploadManager.updateTask(itemId, (uploadManager.tasks.get(itemId) || {}).progress || 0, 'error');
            if (extra.uploadId) {
                api('cancel_upload', { upload_id: extra.uploadId }).catch(function() {});
            }
            return;
        }

        if (type === 'regular' && extra.file) {
            this.retryUpload(extra.file, itemId, extra.parentId, resolution, extra.relativePath || '');
        } else if (type === 'chunked' && extra.uploadId) {
            this.resolveChunked(itemId, extra.uploadId, resolution);
        }
    },

    retryUpload(file, itemId, parentId, conflictResolution, relativePath) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('parent_id', parentId);
        formData.append('conflict_resolution', conflictResolution);
        formData.append('_csrf_token', APP_CONFIG.csrfToken);
        if (relativePath) formData.append('relative_path', relativePath);

        const statusEl = document.getElementById(itemId + '_status');
        if (statusEl) {
            statusEl.innerHTML = '重新上传...';
        }

        const xhr = new XMLHttpRequest();
        activeUploads[itemId] = xhr;
        xhr.open('POST', 'index.php?action=upload');
        xhr.setRequestHeader('X-CSRF-TOKEN', APP_CONFIG.csrfToken);

        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                const pct = Math.round((e.loaded / e.total) * 100);
                const bar = document.getElementById(itemId + '_bar');
                if (bar) bar.style.width = pct + '%';
                uploadManager.updateTask(itemId, pct);
            }
        };

        xhr.onload = function() {
            delete activeUploads[itemId];
            try {
                const data = JSON.parse(xhr.responseText);
                if (data.success) {
                    const s = document.getElementById(itemId + '_status');
                    const b = document.getElementById(itemId + '_bar');
                    if (s) s.innerHTML = '<i class="fas fa-check" style="color:var(--accent-success)"></i> 完成';
                    if (b) b.style.width = '100%';
                    uploadManager.updateTask(itemId, 100, 'success');
                    _removeQueueItem(itemId);
                    _requestUploadRefresh();
                } else {
                    const s = document.getElementById(itemId + '_status');
                    if (s) s.innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> ' + escapeHtml(data.message);
                    uploadManager.updateTask(itemId, (uploadManager.tasks.get(itemId) || {}).progress || 0, 'error');
                }
                uploadFinished();
            } catch (e) {
                const s = document.getElementById(itemId + '_status');
                if (s) s.innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> 上传失败';
                uploadManager.updateTask(itemId, (uploadManager.tasks.get(itemId) || {}).progress || 0, 'error');
                uploadFinished();
            }
        };

        xhr.onerror = function() {
            delete activeUploads[itemId];
            const s = document.getElementById(itemId + '_status');
            if (s) s.innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> 网络错误';
            uploadManager.updateTask(itemId, (uploadManager.tasks.get(itemId) || {}).progress || 0, 'error');
            uploadFinished();
        };

        xhr.send(formData);
    },

    resolveChunked(itemId, uploadId, conflictResolution) {
        const statusEl = document.getElementById(itemId + '_status');
        if (statusEl) {
            statusEl.innerHTML = '处理中...';
        }

        api('resolve_upload_conflict', {
            upload_id: uploadId,
            conflict_resolution: conflictResolution
        }).then(function(data) {
            if (data.success) {
                const s = document.getElementById(itemId + '_status');
                const b = document.getElementById(itemId + '_bar');
                if (s) s.innerHTML = '<i class="fas fa-check" style="color:var(--accent-success)"></i> 完成';
                if (b) b.style.width = '100%';
                uploadManager.updateTask(itemId, 100, 'success');
                    _removeQueueItem(itemId);
                _requestUploadRefresh();
            } else {
                const s = document.getElementById(itemId + '_status');
                if (s) s.innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> ' + escapeHtml(data.message);
                uploadManager.updateTask(itemId, (uploadManager.tasks.get(itemId) || {}).progress || 0, 'error');
            }
            uploadFinished();
        }).catch(function() {
            const s = document.getElementById(itemId + '_status');
            if (s) s.innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> 处理失败';
            uploadManager.updateTask(itemId, (uploadManager.tasks.get(itemId) || {}).progress || 0, 'error');
            uploadFinished();
        });
    }
};

function showInterruptDialog(tasks) {
    interruptedFiles = tasks;

    var count = tasks.length;
    var totalSize = tasks.reduce(function(sum, t) { return sum + t.size; }, 0);
    var hint = document.getElementById('interruptHint');
    hint.textContent = '页面已刷新，' + count + ' 个文件（' + formatSize(totalSize) + '）未成功上传。';

    var list = document.getElementById('interruptFileList');
    var MAX_VISIBLE = 50;
    var displayTasks = tasks.slice(0, MAX_VISIBLE);
    var hasMore = tasks.length > MAX_VISIBLE;

    var iconMap = {
        jpg: 'fa-image', jpeg: 'fa-image', png: 'fa-image', gif: 'fa-image', webp: 'fa-image', svg: 'fa-image', bmp: 'fa-image',
        mp4: 'fa-film', avi: 'fa-film', mkv: 'fa-film', mov: 'fa-film', flv: 'fa-film', webm: 'fa-film',
        mp3: 'fa-music', wav: 'fa-music', flac: 'fa-music', aac: 'fa-music', ogg: 'fa-music',
        pdf: 'fa-file-pdf', doc: 'fa-file-word', docx: 'fa-file-word', xls: 'fa-file-excel', xlsx: 'fa-file-excel', ppt: 'fa-file-powerpoint', pptx: 'fa-file-powerpoint',
        zip: 'fa-file-archive', rar: 'fa-file-archive', '7z': 'fa-file-archive', tar: 'fa-file-archive', gz: 'fa-file-archive',
        txt: 'fa-file-alt', md: 'fa-file-alt', log: 'fa-file-alt', csv: 'fa-file-alt',
        js: 'fa-code', ts: 'fa-code', py: 'fa-code', php: 'fa-code', html: 'fa-code', css: 'fa-code', java: 'fa-code',
    };

    var html = '';
    displayTasks.forEach(function(task) {
        var ext = task.filename.split('.').pop().toLowerCase();
        var icon = iconMap[ext] || 'fa-file';
        var statusText = task.status === 'success' ? '完成' :
                           task.status === 'error' ? '失败' :
                           task.status === 'pending' ? '未开始' :
                           task.progress + '%';
        var statusClass = (task.status === 'error' || task.status === 'pending') ? 'failed' : '';
        html += '<div class="interrupt-file-item">' +
            '<div class="interrupt-file-icon"><i class="fas ' + icon + '"></i></div>' +
            '<div class="interrupt-file-info">' +
                '<div class="interrupt-file-name" title="' + escapeHtml(task.filename) + '">' + escapeHtml(task.filename) + '</div>' +
                '<div class="interrupt-file-meta">' + formatSize(task.size) + ' · .' + ext + '</div>' +
            '</div>' +
            '<div class="interrupt-file-progress ' + statusClass + '">' + statusText + '</div>' +
        '</div>';
    });

    if (hasMore) {
        html += '<div class="interrupt-more-hint">…以及 ' + (tasks.length - MAX_VISIBLE) + ' 个文件</div>';
    }

    list.innerHTML = html;
    document.getElementById('uploadInterruptOverlay').style.display = 'flex';
}

function closeInterruptDialog() {
    document.getElementById('uploadInterruptOverlay').style.display = 'none';
    interruptedFiles = [];
}

function toggleFloatWidget() {
    uploadManager.isPanelOpen = !uploadManager.isPanelOpen;
    var panel = document.getElementById('uploadFloatPanel');
    if (panel) {
        panel.style.display = uploadManager.isPanelOpen ? 'block' : 'none';
    }
}

function showUploadDialog() {
    document.getElementById('uploadOverlay').style.display = 'flex';
    // 不清空队列 DOM：关闭对话框后后台 XHR 仍在运行，
    // 清空会导致回调中 getElementById 返回 null → TypeError → uploadFinished() 不被调用 → 队列永久阻塞
}

function closeUploadDialog() {
    var hasUploadingTasks = Array.from(uploadManager.tasks.values()).some(function(t) { return t.status === 'uploading'; });
    if (hasUploadingTasks) {
        document.getElementById('uploadOverlay').style.display = 'none';
        uploadManager.showFloatWidget();
    } else {
        document.getElementById('uploadOverlay').style.display = 'none';
    }
}

function handleFileSelect(files) {
    if (!files || files.length === 0) return;

    uploadSession.start(files);

    var uploadParentId = currentParentId;

    for (var i = 0; i < files.length; i++) {
        var file = files[i];
        // 从 webkitRelativePath 提取目录部分作为 relative_path
        // 如 "Wallpapers/Nature/sunset.jpg" → relativePath = "Wallpapers/Nature"
        var relativePath = '';
        if (file.webkitRelativePath) {
            var parts = file.webkitRelativePath.split('/');
            if (parts.length > 1) {
                relativePath = parts.slice(0, -1).join('/');
            }
        }

        uploadQueue.push({
            file: file,
            index: i,
            itemId: null,
            parentId: uploadParentId,
            relativePath: relativePath
        });
    }

    processUploadQueue();
}

function processUploadQueue() {
    while (currentUploadCount < MAX_CONCURRENT_UPLOADS && uploadQueue.length > 0) {
        var queueItem = uploadQueue.shift();
        currentUploadCount++;
        uploadFile(queueItem.file, queueItem.index, queueItem.parentId, queueItem.relativePath || '');
    }
}

function uploadFinished() {
    currentUploadCount = Math.max(0, currentUploadCount - 1);
    processUploadQueue();
    // 上传批次全部完成后一次性刷新文件列表
    if (_uploadRefreshNeeded && uploadQueue.length === 0 && currentUploadCount === 0) {
        _uploadRefreshNeeded = false;
        if (typeof invalidateFileListCache === 'function') invalidateFileListCache(currentParentId);
        loadFiles(currentParentId, true);
        loadStorageInfo();
    }
}

function uploadFile(file, fileIndex, parentId, relativePath) {
    if (fileIndex === undefined) fileIndex = 0;
    if (!relativePath) relativePath = '';
    var queue = document.getElementById('uploadQueue');
    var itemId = 'upload_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    var maxSize = APP_CONFIG.chunkSize;

    queue.insertAdjacentHTML('beforeend',
        '<div class="queue-item" id="' + itemId + '">' +
            '<div class="queue-info"><span class="queue-filename">' + escapeHtml(file.name) + '</span><span class="queue-size">' + formatSize(file.size) + '</span></div>' +
            '<div class="queue-bar"><div class="queue-fill" id="' + itemId + '_bar" style="width:0%"></div></div>' +
            '<span class="queue-status" id="' + itemId + '_status">上传中...</span>' +
            '<button class="queue-cancel-btn" data-action="cancel-upload" data-task-id="' + itemId + '" title="取消上传"><i class="fas fa-times"></i></button>' +
        '</div>'
    );

    uploadManager.addTask(itemId, file.name, file.size);

    if (file.size > maxSize) {
        uploadChunked(file, itemId, parentId, relativePath);
    } else {
        var formData = new FormData();
        formData.append('file', file);
        formData.append('parent_id', parentId);
        formData.append('_csrf_token', APP_CONFIG.csrfToken);
        if (relativePath) formData.append('relative_path', relativePath);

        var xhr = new XMLHttpRequest();
        activeUploads[itemId] = xhr;
        xhr.open('POST', 'index.php?action=upload');
        xhr.setRequestHeader('X-CSRF-TOKEN', APP_CONFIG.csrfToken);

        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                var pct = Math.round((e.loaded / e.total) * 100);
                var bar = document.getElementById(itemId + '_bar');
                if (bar) bar.style.width = pct + '%';
                uploadManager.updateTask(itemId, pct);
            }
        };

        xhr.onload = function() {
            delete activeUploads[itemId];
            var statusEl = document.getElementById(itemId + '_status');
            var barEl = document.getElementById(itemId + '_bar');
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.success) {
                    if (statusEl) statusEl.innerHTML = '<i class="fas fa-check" style="color:var(--accent-success)"></i> 完成';
                    if (barEl) barEl.style.width = '100%';
                    uploadManager.updateTask(itemId, 100, 'success');
                    _removeQueueItem(itemId);
                    _requestUploadRefresh();
                    uploadFinished();
                } else if (data.duplicate_conflict) {
                    if (statusEl) statusEl.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:var(--accent-warning)"></i> 文件冲突';
                    uploadManager.updateTask(itemId, 100, 'error');
                    uploadConflict.add(data, itemId, 'regular', { file: file, parentId: parentId, relativePath: relativePath });
                    uploadFinished();
                } else {
                    if (statusEl) statusEl.innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> ' + escapeHtml(data.message);
                    uploadManager.updateTask(itemId, (uploadManager.tasks.get(itemId) || {}).progress || 0, 'error');
                    uploadFinished();
                }
            } catch (e) {
                if (statusEl) statusEl.innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> 上传失败';
                uploadManager.updateTask(itemId, (uploadManager.tasks.get(itemId) || {}).progress || 0, 'error');
                uploadFinished();
            }
        };

        xhr.onerror = function() {
            delete activeUploads[itemId];
            var statusEl = document.getElementById(itemId + '_status');
            if (statusEl) statusEl.innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> 网络错误';
            uploadManager.updateTask(itemId, (uploadManager.tasks.get(itemId) || {}).progress || 0, 'error');
            uploadFinished();
        };

        xhr.onabort = function() {
            delete activeUploads[itemId];
            var statusEl = document.getElementById(itemId + '_status');
            if (statusEl) statusEl.innerHTML = '<i class="fas fa-ban" style="color:var(--text-muted)"></i> 已取消';
            uploadManager.updateTask(itemId, (uploadManager.tasks.get(itemId) || {}).progress || 0, 'error');
            uploadFinished();
        };

        xhr.send(formData);
    }
}

function uploadChunked(file, itemId, parentId, relativePath) {
    if (!relativePath) relativePath = '';
    var chunkSize = APP_CONFIG.chunkSize;
    var totalChunks = Math.ceil(file.size / chunkSize);
    // 断点续传：uploadId 持久化到 localStorage，刷新后可恢复。
    // key 同时纳入文件名与大小，避免不同文件相互覆盖；超过 24h 自动失效。
    var resumeKey = 'uploadId:' + file.name + ':' + file.size;
    var uploadId = null;
    try {
        var cached = JSON.parse(localStorage.getItem(resumeKey) || 'null');
        if (cached && cached.uploadId && (Date.now() - cached.ts < 86400000)) {
            uploadId = cached.uploadId;
        }
    } catch (e) {}
    if (!uploadId) {
        uploadId = Date.now().toString(36) + Math.random().toString(36).substr(2, 9);
    }
    // 立即持久化（覆盖式），保证刷新后能查回
    try { localStorage.setItem(resumeKey, JSON.stringify({ uploadId: uploadId, ts: Date.now() })); } catch (e) {}

    var currentChunk = 0;
    var uploadedSet = {}; // 已上传分片集合，用于跳过
    var maxRetries = 3;
    var retryCount = 0;
    var isCancelled = false;

    function clearResume() {
        try { localStorage.removeItem(resumeKey); } catch (e) {}
    }

    // 上传完成后清理 resume 记录（成功 / 失败 / 取消均清）
    function finishClear() {
        clearResume();
    }

    function uploadNextChunk() {
        if (isCancelled) return;
        if (currentChunk >= totalChunks) return;

        var start = currentChunk * chunkSize;
        var end = Math.min(start + chunkSize, file.size);
        var chunk = file.slice(start, end);

        var formData = new FormData();
        formData.append('parent_id', parentId);
        formData.append('upload_id', uploadId);
        formData.append('chunk_index', currentChunk);
        formData.append('total_chunks', totalChunks);
        formData.append('filename', file.name);
        formData.append('total_size', file.size);
        formData.append('_csrf_token', APP_CONFIG.csrfToken);
        formData.append('chunk_data', chunk);
        if (relativePath) formData.append('relative_path', relativePath);

            var xhr = new XMLHttpRequest();
            activeUploads[itemId] = xhr;
            xhr.open('POST', 'index.php?action=upload_chunk');
            xhr.setRequestHeader('X-CSRF-TOKEN', APP_CONFIG.csrfToken);

            xhr.onload = function() {
                delete activeUploads[itemId];
                if (isCancelled) return;

                var barEl = document.getElementById(itemId + '_bar');
                var statusEl = document.getElementById(itemId + '_status');

                // 429 限流响应：按 retry_after 延迟后重试当前分片，不跳过
                if (xhr.status === 429) {
                    var retryAfter = 5;
                    try {
                        var errData = JSON.parse(xhr.responseText);
                        retryAfter = errData.retry_after || 5;
                    } catch(e) {}
                    if (retryCount < maxRetries) {
                        retryCount++;
                        var delay = Math.min(retryAfter * 1000, 30000);
                        setTimeout(function() { uploadNextChunk(); }, delay);
                    } else {
                        if (statusEl) statusEl.innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> 限流重试耗尽';
                        uploadManager.updateTask(itemId, Math.round((currentChunk / totalChunks) * 100), 'error');
                        finishClear();
                        uploadFinished();
                    }
                    return;
                }

                try {
                    var data = JSON.parse(xhr.responseText);
                    var pct = Math.round(((currentChunk + 1) / totalChunks) * 100);
                    if (barEl) barEl.style.width = pct + '%';
                    uploadManager.updateTask(itemId, pct);

                    if (data.success && data.merged) {
                        if (statusEl) statusEl.innerHTML = '<i class="fas fa-check" style="color:var(--accent-success)"></i> 完成';
                        uploadManager.updateTask(itemId, 100, 'success');
                        _removeQueueItem(itemId);
                        _requestUploadRefresh();
                        finishClear();
                        uploadFinished();
                    } else if (data.success) {
                        uploadedSet[currentChunk] = true;
                        currentChunk++;
                        retryCount = 0;
                        // 安全兜底：全部分片已上传但未触发合并（如 skipped 场景后端已修复会返回 merged，
                        // 但此处防御极端情况），主动调 resolve_upload_conflict 触发合并
                        if (currentChunk >= totalChunks) {
                            if (statusEl) statusEl.innerHTML = '<i class="fas fa-sync fa-spin" style="color:var(--accent-info)"></i> 合并中...';
                            api('resolve_upload_conflict', {
                                upload_id: uploadId,
                                conflict_resolution: 'overwrite'
                            }).then(function(mergeData) {
                                if (mergeData.success) {
                                    if (statusEl) statusEl.innerHTML = '<i class="fas fa-check" style="color:var(--accent-success)"></i> 完成';
                                    uploadManager.updateTask(itemId, 100, 'success');
                                    _removeQueueItem(itemId);
                                    _requestUploadRefresh();
                                } else if (mergeData.duplicate_conflict) {
                                    uploadConflict.add(mergeData, itemId, 'chunked', { uploadId: uploadId });
                                } else {
                                    if (statusEl) statusEl.innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> ' + escapeHtml(mergeData.message || '合并失败');
                                    uploadManager.updateTask(itemId, pct, 'error');
                                }
                                finishClear();
                                uploadFinished();
                            }).catch(function() {
                                if (statusEl) statusEl.innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> 合并请求失败';
                                uploadManager.updateTask(itemId, pct, 'error');
                                finishClear();
                                uploadFinished();
                            });
                            return; // 阻止继续 uploadNextChunk
                        }
                        uploadNextChunk();
                    } else if (data.duplicate_conflict) {
                        if (statusEl) statusEl.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:var(--accent-warning)"></i> 文件冲突';
                        uploadManager.updateTask(itemId, pct, 'error');
                        uploadConflict.add(data, itemId, 'chunked', { uploadId: data.upload_id || uploadId });
                        finishClear();
                        uploadFinished();
                    } else {
                        // 分片缺失：后端保留已上传分片，可重试缺失分片而非放弃整个文件
                        if (data.message && data.message.indexOf('分片文件缺失') >= 0 && retryCount < maxRetries) {
                            retryCount++;
                            var missingChunk = data.missing_chunk != null ? data.missing_chunk : currentChunk;
                            if (statusEl) statusEl.innerHTML = '<i class="fas fa-sync fa-spin" style="color:var(--accent-info)"></i> 重传分片 ' + missingChunk + '...';
                            // 延迟后重传当前分片（上传端可能仍在发后续分片，给后端喘息时间）
                            setTimeout(function() { uploadNextChunk(); }, 1000 * retryCount);
                            return;
                        }
                        if (statusEl) statusEl.innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> ' + escapeHtml(data.message);
                        uploadManager.updateTask(itemId, pct, 'error');
                        finishClear();
                        uploadFinished();
                    }
                } catch (e) {
                    handleUploadError(itemId, pct, currentChunk, totalChunks);
                }
            };

            xhr.onerror = function() {
                delete activeUploads[itemId];
                if (isCancelled) return;
                var pct = Math.round((currentChunk / totalChunks) * 100);
                handleUploadError(itemId, pct, currentChunk, totalChunks);
            };

            xhr.onabort = function() {
                delete activeUploads[itemId];
            };

            xhr.send(formData);
    }

    function handleUploadError(itemId, pct, chunk, total) {
        if (isCancelled) return;
        if (retryCount < maxRetries) {
            retryCount++;
            var delay = Math.min(1000 * Math.pow(2, retryCount - 1), 5000);
            setTimeout(function() { uploadNextChunk(); }, delay);
        } else {
            var statusEl = document.getElementById(itemId + '_status');
            if (statusEl) statusEl.innerHTML = '<i class="fas fa-times" style="color:var(--accent-danger)"></i> 网络错误（已重试' + maxRetries + '次）';
            uploadManager.updateTask(itemId, pct, 'error');
            // 网络失败不清除 resume 记录：刷新后可从断点恢复
            uploadFinished();
        }
    }

    activeUploads[itemId] = {
        abort: function() {
            isCancelled = true;
            var activeXhr = activeUploads[itemId];
            if (activeXhr && activeXhr.abort) {
                activeXhr.abort();
            }
            finishClear();
        }
    };

    // 真正断点续传：开始上传前先查询服务端已上传分片
    // 跳过已上传分片，从下一个未上传分片继续，避免从 0 重复上传
    function startUpload() {
        fetch('index.php?action=get_uploaded_chunks&upload_id=' + encodeURIComponent(uploadId), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        }).then(function(data) {
            if (data.success && Array.isArray(data.uploaded_chunks)) {
                data.uploaded_chunks.forEach(function(idx) {
                    uploadedSet[idx] = true;
                });
                while (currentChunk < totalChunks && uploadedSet[currentChunk]) {
                    currentChunk++;
                }
                if (currentChunk > 0) {
                    var pct = Math.round((currentChunk / totalChunks) * 100);
                    var bar = document.getElementById(itemId + '_bar');
                    if (bar) bar.style.width = pct + '%';
                    uploadManager.updateTask(itemId, pct);
                    var status = document.getElementById(itemId + '_status');
                    if (status) status.innerHTML = '<i class="fas fa-redo" style="color:var(--accent-info)"></i> 断点续传 ' + currentChunk + '/' + totalChunks;
                }
                if (currentChunk >= totalChunks) {
                    // 服务端已记录全部分片，但未触发合并——重新发一次最后一个分片
                    // 让服务端 merge；若服务端已合并则返回 merged:true
                    currentChunk = totalChunks - 1;
                    delete uploadedSet[currentChunk];
                }
            }
            uploadNextChunk();
        }).catch(function() {
            // 查询失败：从 0 开始上传
            uploadNextChunk();
        });
    }

    startUpload();
}

// 拖放上传：对话框内 dropzone 的拖放处理
function initDropzone() {
    var dropzone = document.getElementById('uploadDropzone');
    if (!dropzone) return;
    if (dropzone.dataset.dropzoneBound === '1') return;
    dropzone.dataset.dropzoneBound = '1';

    var dragCounter = 0;
    dropzone.addEventListener('dragenter', function(e) {
        if (!e.dataTransfer || !e.dataTransfer.types || e.dataTransfer.types.indexOf('Files') === -1) return;
        e.preventDefault();
        dragCounter++;
        dropzone.classList.add('dragover');
    });
    dropzone.addEventListener('dragover', function(e) {
        if (e.dataTransfer && e.dataTransfer.types && e.dataTransfer.types.indexOf('Files') !== -1) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
        }
    });
    dropzone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        dragCounter = Math.max(0, dragCounter - 1);
        if (dragCounter === 0) dropzone.classList.remove('dragover');
    });
    dropzone.addEventListener('drop', function(e) {
        e.preventDefault();
        dragCounter = 0;
        dropzone.classList.remove('dragover');
        _handleDrop(e);
    });
}

/**
 * 全局拖拽捕获：在文件管理页面，拖拽文件到页面任意位置即弹出上传对话框。
 * 用计数器避免子元素 dragleave 误触发的闪烁；dragenter 时打开对话框，
 * 拖离页面后若对话框内没有上传任务则自动关闭。
 */
var _globalDragState = {
    counter: 0,              // 全局 drag 计数器
    dialogAutoOpened: false,  // 标记对话框是否由全局拖拽自动打开
    hintTimer: null           // 提示自动消失的定时器
};

function initGlobalDrag() {
    var contentArea = document.getElementById('contentArea');
    if (!contentArea) return;
    if (contentArea.dataset.globalDragBound === '1') return;
    contentArea.dataset.globalDragBound = '1';

    document.addEventListener('dragenter', function(e) {
        if (!e.dataTransfer || !e.dataTransfer.types || e.dataTransfer.types.indexOf('Files') === -1) return;
        e.preventDefault();
        _globalDragState.counter++;

        if (_globalDragState.counter === 1) {
            // 弹出上传对话框
            var uploadOverlay = document.getElementById('uploadOverlay');
            if (uploadOverlay && uploadOverlay.style.display !== 'flex') {
                showUploadDialog();
                _globalDragState.dialogAutoOpened = true;
            }
            // 短暂闪现"松开即可上传"提示，1.2s 后自动消失
            _showDragHint();
        }
    });

    document.addEventListener('dragover', function(e) {
        if (!e.dataTransfer || !e.dataTransfer.types || e.dataTransfer.types.indexOf('Files') !== -1) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
        }
    });

    document.addEventListener('dragleave', function(e) {
        if (!e.dataTransfer || !e.dataTransfer.types || e.dataTransfer.types.indexOf('Files') === -1) return;
        e.preventDefault();
        _globalDragState.counter = Math.max(0, _globalDragState.counter - 1);

        if (_globalDragState.counter === 0) {
            _hideDragHint();
            // 如果对话框是自动打开的，且没有任何上传任务，则自动关闭
            if (_globalDragState.dialogAutoOpened) {
                var hasTasks = Array.from(uploadManager.tasks.values()).some(function(t) { return t.status === 'uploading'; });
                var hasQueueItems = document.getElementById('uploadQueue') &&
                    document.getElementById('uploadQueue').children.length > 0;
                if (!hasTasks && !hasQueueItems) {
                    closeUploadDialog();
                    _globalDragState.dialogAutoOpened = false;
                }
            }
        }
    });

    document.addEventListener('drop', function(e) {
        if (!e.dataTransfer || !e.dataTransfer.types || e.dataTransfer.types.indexOf('Files') === -1) return;
        // dropzone 内部由 initDropzone 处理，此处不拦截
        var dropzone = document.getElementById('uploadDropzone');
        if (dropzone && dropzone.contains(e.target)) return;

        e.preventDefault();
        _globalDragState.counter = 0;
        _hideDragHint();

        _handleDrop(e);
        _globalDragState.dialogAutoOpened = false;
    });
}

// 短暂显示拖拽提示，自动消失
function _showDragHint() {
    if (_globalDragState.hintTimer) clearTimeout(_globalDragState.hintTimer);

    var hint = document.getElementById('globalDragHint');
    if (!hint) {
        hint = document.createElement('div');
        hint.className = 'global-drag-hint';
        hint.id = 'globalDragHint';
        hint.innerHTML = '<i class="fas fa-cloud-upload-alt"></i><p>松开即可上传</p>';
        document.body.appendChild(hint);
    }

    // 触发显示
    hint.classList.remove('hiding');
    hint.classList.add('active');

    // 1.2s 后开始淡出
    _globalDragState.hintTimer = setTimeout(function() {
        hint.classList.add('hiding');
        hint.classList.remove('active');
    }, 1200);
}

function _hideDragHint() {
    if (_globalDragState.hintTimer) clearTimeout(_globalDragState.hintTimer);
    var hint = document.getElementById('globalDragHint');
    if (hint) {
        hint.classList.remove('active');
        hint.classList.add('hiding');
    }
}

/**
 * 处理拖放：优先使用 webkitGetAsEntry 遍历文件夹，回退到 files 列表。
 */
function _handleDrop(e) {
    if (!e.dataTransfer) return;

    var items = e.dataTransfer.items;
    if (items && items.length > 0 && typeof items[0].webkitGetAsEntry === 'function') {
        // 支持 webkitGetAsEntry：递归遍历文件夹
        var entries = [];
        for (var i = 0; i < items.length; i++) {
            var entry = items[i].webkitGetAsEntry();
            if (entry) entries.push(entry);
        }
        _traverseEntries(entries, function(files) {
            if (files.length > 0) handleFileSelect(files);
        });
    } else {
        // 回退：只取 files
        if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
            handleFileSelect(e.dataTransfer.files);
        }
    }
}

/**
 * 递归遍历文件系统 entry（文件 + 文件夹），收集所有 File 对象。
 * 保留相对路径信息（file.webkitRelativePath 或自定义 path 属性）。
 * 注意：Chrome 的 DirectoryReader.readEntries 每次最多返回 100 条，
 * 需要循环读取直到返回空数组才能获取目录下所有文件。
 */
function _traverseEntries(entries, callback) {
    var result = [];
    var pending = 0;

    function done() {
        if (pending === 0) callback(result);
    }

    function readAllEntries(dirReader, path, onDone) {
        var allSubEntries = [];

        function readBatch() {
            dirReader.readEntries(function(subEntries) {
                if (subEntries.length === 0) {
                    // 所有子条目已读完
                    onDone(allSubEntries);
                } else {
                    for (var i = 0; i < subEntries.length; i++) {
                        allSubEntries.push(subEntries[i]);
                    }
                    // 继续读取下一批
                    readBatch();
                }
            }, function() {
                // 读取出错，返回已收集的条目
                onDone(allSubEntries);
            });
        }

        readBatch();
    }

    function processEntry(entry, path) {
        pending++;
        if (entry.isFile) {
            entry.file(function(file) {
                // 给文件附加相对路径信息，供后端创建目录结构
                if (!file.webkitRelativePath) {
                    Object.defineProperty(file, 'webkitRelativePath', {
                        value: path + file.name,
                        writable: false
                    });
                }
                result.push(file);
                pending--;
                done();
            }, function() {
                pending--;
                done();
            });
        } else if (entry.isDirectory) {
            var dirReader = entry.createReader();
            readAllEntries(dirReader, path + entry.name + '/', function(subEntries) {
                for (var i = 0; i < subEntries.length; i++) {
                    processEntry(subEntries[i], path + entry.name + '/');
                }
                pending--;
                done();
            });
        } else {
            pending--;
            done();
        }
    }

    for (var i = 0; i < entries.length; i++) {
        processEntry(entries[i], '');
    }
    done();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        initDropzone();
        initGlobalDrag();
    });
} else {
    initDropzone();
    initGlobalDrag();
}

(function initFloatButton() {
    var mini = document.getElementById('uploadFloatMini');
    if (mini) {
        mini.addEventListener('click', function() {
            if (mini.classList.contains('idle')) {
                showUploadDialog();
            } else {
                toggleFloatWidget();
            }
        });
    }
})();
