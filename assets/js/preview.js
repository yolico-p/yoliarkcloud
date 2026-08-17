/**
 * 统一预览子系统（Fluent Design 风格重构版）
 * 所有文件类型共享同一个预览容器，统一头部、加载态、错误态
 */

var PreviewShell = {
    _docListeners: [],
    _currentType: null,
    _isDragging: false,
    _islandCollapseTimer: null,
    _islandExpanded: true,

    _addDocListener: function (type, handler, useCapture) {
        document.addEventListener(type, handler, useCapture || false);
        this._docListeners.push([type, handler, useCapture || false]);
    },

    _removeDocListeners: function () {
        for (var i = 0; i < this._docListeners.length; i++) {
            var h = this._docListeners[i];
            document.removeEventListener(h[0], h[1], h[2]);
        }
        this._docListeners = [];
    },

    init: function () {
        var self = this;
        var overlay = document.getElementById('previewOverlay');
        if (!overlay) return;

        document.getElementById('previewCloseBtn').onclick = function () { self.close(); };
        document.getElementById('previewDownloadBtn').onclick = function () { self.download(); };
        document.getElementById('previewPrevBtn').onclick = function () { self.prev(); };
        document.getElementById('previewNextBtn').onclick = function () { self.next(); };

        var errorCloseBtn = document.getElementById('previewErrorClose');
        if (errorCloseBtn) errorCloseBtn.onclick = function () { self.close(); };

        // 拖拽检测：只在按住鼠标移动时才标记为拖拽
        var mouseDownPos = null;
        overlay.addEventListener('mousedown', function (e) {
            mouseDownPos = { x: e.clientX, y: e.clientY };
            self._isDragging = false;
        });
        overlay.addEventListener('mousemove', function (e) {
            if (!mouseDownPos) return;
            var dx = e.clientX - mouseDownPos.x;
            var dy = e.clientY - mouseDownPos.y;
            if (Math.abs(dx) > 3 || Math.abs(dy) > 3) {
                self._isDragging = true;
            }
        });
        overlay.addEventListener('mouseup', function () {
            mouseDownPos = null;
        });

        // 点击事件：处理关闭和 data-action 委托
        overlay.addEventListener('click', function (e) {
            // 1. 处理 data-action 委托（如 Excel 标签切换）
            var actionEl = e.target.closest('[data-action]');
            if (actionEl) {
                var action = actionEl.dataset.action;
                if (action === 'switch-excel-tab') {
                    switchExcelTab(actionEl);
                }
                return;
            }
            // 2. 拖拽过则不关闭
            if (self._isDragging) return;
            // 3. 点击交互内容区（导航箭头、播放器卡片、文档卡片、视频区、图片区、头部、底部）不关闭
            if (e.target.closest('.preview-nav-arrow, .pv-audio-modern, .pv-doc-frame, .pv-video-wrap, .pv-image-container, .pv-image-wrap, .preview-header, .preview-footer, .pv-media-footer, .pv-tool-btn, .pv-icon-btn, .preview-action-btn')) {
                return;
            }
            // 4. 其他所有区域（背景空白区）均关闭
            self.close();
        });

        // 全局键盘分发
        document.addEventListener('keydown', function (e) {
            if (!overlay.classList.contains('active')) return;
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

            if (e.key === 'Escape') {
                // 全屏状态下 Escape 仅退出全屏，不关闭预览
                if (document.fullscreenElement) { document.exitFullscreen(); return; }
                self.close();
                return;
            }

            var type = self._currentType;
            if (type === 'video') onVideoKeyDown(e);
            else if (type === 'audio') onAudioKeyDown(e);
            else if (type === 'image') onImageKeyDown(e);
            else {
                if (e.key === 'ArrowLeft') self.prev();
                if (e.key === 'ArrowRight') self.next();
            }
        });

        this.overlay = overlay;
        this.header = document.getElementById('previewHeader');
        this.footer = document.getElementById('previewFooter');
        this.body = document.getElementById('previewBody');
        this._setupIslandBehavior();
    },

    _setupIslandBehavior: function () {
        var self = this;
        if (!self.overlay || !self.header) return;

        // 创建顶部感应热区（若不存在）
        var hotzone = document.getElementById('previewIslandHotzone');
        if (!hotzone) {
            hotzone = document.createElement('div');
            hotzone.id = 'previewIslandHotzone';
            hotzone.className = 'preview-island-hotzone';
            self.overlay.insertBefore(hotzone, self.header);
        }
        self.islandHotzone = hotzone;
        self.prevBtn = document.getElementById('previewPrevBtn');
        self.nextBtn = document.getElementById('previewNextBtn');
        self._islandMouseInHotzone = false;
        self._islandMouseInLeftArrow = false;
        self._islandMouseInRightArrow = false;

        function expandHeader() {
            if (!self._islandExpanded) {
                self._islandExpanded = true;
                self.header.classList.remove('island-collapsed');
            }
        }

        function collapseHeader() {
            self._islandExpanded = false;
            if (self.header) self.header.classList.add('island-collapsed');
        }

        function expandArrow(el) {
            if (el) el.classList.add('island-expanded');
        }

        function collapseArrow(el) {
            if (el) el.classList.remove('island-expanded');
        }

        function scheduleCollapse() {
            self._resetIslandCollapseTimer();
            self._islandCollapseTimer = setTimeout(function () {
                if (!self.overlay.classList.contains('active')) return;
                collapseHeader();
                collapseArrow(self.prevBtn);
                collapseArrow(self.nextBtn);
            }, 3000);
        }

        // 鼠标在预览区域内移动：
        // - 进入顶部 80px → 展开 header
        // - 进入左右边缘 80px → 展开对应箭头
        // - 离开所有热区 → 启动 3s 统一收起倒计时
        self.overlay.addEventListener('mousemove', function (e) {
            var inTop = e.clientY <= 50;
            var inLeft = e.clientX <= 50;
            var inRight = e.clientX >= (window.innerWidth - 50);
            var wasInAnyHotzone = self._islandMouseInHotzone || self._islandMouseInLeftArrow || self._islandMouseInRightArrow;

            if (inTop) expandHeader();
            else if (self._islandMouseInHotzone) collapseHeader();

            if (inLeft) expandArrow(self.prevBtn);
            else if (self._islandMouseInLeftArrow) collapseArrow(self.prevBtn);

            if (inRight) expandArrow(self.nextBtn);
            else if (self._islandMouseInRightArrow) collapseArrow(self.nextBtn);

            self._islandMouseInHotzone = inTop;
            self._islandMouseInLeftArrow = inLeft;
            self._islandMouseInRightArrow = inRight;

            if (inTop || inLeft || inRight) {
                self._resetIslandCollapseTimer();
            } else if (wasInAnyHotzone) {
                // 刚从任意热区移出：启动一次倒计时
                scheduleCollapse();
            }
            // 在所有热区外移动不重置倒计时，避免一直移动就永远不收起
        });

        // 鼠标离开整个预览层时同样触发延迟收起
        self.overlay.addEventListener('mouseleave', function () {
            self._islandMouseInHotzone = false;
            self._islandMouseInLeftArrow = false;
            self._islandMouseInRightArrow = false;
            scheduleCollapse();
        });

        // 鼠标进入 header 时保持展开
        self.header.addEventListener('mouseenter', function () {
            expandHeader();
            self._resetIslandCollapseTimer();
        });
    },

    _resetIslandCollapseTimer: function () {
        if (this._islandCollapseTimer) {
            clearTimeout(this._islandCollapseTimer);
            this._islandCollapseTimer = null;
        }
    },

    open: function (fileId, fileList, index) {
        if (this._pdfLoadTimer) { clearTimeout(this._pdfLoadTimer); this._pdfLoadTimer = null; }
        if (this.overlay.classList.contains('active')) {
            this.cleanupMedia();
            this.cleanupImageLoad();
            this._removeDocListeners(); // 清理上一次预览的 document 监听器
        }
        previewState.fileId = fileId;
        previewState.fileList = fileList || null;
        previewState.fileIndex = index != null ? index : -1;
        this._currentType = null;
        this.updateNavButtons();
        this.setFooter('');
        this.showLoading();
        this.overlay.classList.add('active');
        if (this.body) this.body.classList.remove('pdf-mode', 'word-mode', 'excel-mode');
        document.body.style.overflow = 'hidden';

        // 灵动岛：打开时先完整展示，3s 无鼠标靠近后自动收起
        this._islandExpanded = true;
        if (this.header) this.header.classList.remove('island-collapsed');
        if (this.prevBtn) this.prevBtn.classList.add('island-expanded');
        if (this.nextBtn) this.nextBtn.classList.add('island-expanded');
        var self = this;
        this._resetIslandCollapseTimer();
        this._islandCollapseTimer = setTimeout(function () {
            if (!self.overlay.classList.contains('active')) return;
            self._islandExpanded = false;
            if (self.header) self.header.classList.add('island-collapsed');
            if (self.prevBtn) self.prevBtn.classList.remove('island-expanded');
            if (self.nextBtn) self.nextBtn.classList.remove('island-expanded');
        }, 3000);
    },

    close: function () {
        if (this._pdfLoadTimer) { clearTimeout(this._pdfLoadTimer); this._pdfLoadTimer = null; }
        this._resetIslandCollapseTimer();
        this._islandExpanded = true;
        if (this.header) this.header.classList.remove('island-collapsed');
        if (this.prevBtn) this.prevBtn.classList.remove('island-expanded');
        if (this.nextBtn) this.nextBtn.classList.remove('island-expanded');
        this.overlay.classList.remove('active');
        document.body.style.overflow = '';
        var body = document.getElementById('previewBody');
        if (body) body.classList.remove('pdf-mode', 'word-mode', 'excel-mode');
        this.cleanupMedia();
        this.cleanupImageLoad();
        this._removeDocListeners();
        this._currentType = null;
        this.setFooter('');
        previewState.fileList = null;
        previewState.fileIndex = -1;
    },

    cleanupMedia: function () {
        if (previewState.audio) {
            previewState.audio.pause();
            previewState.audio.src = '';
            previewState.audio = null;
        }
        if (previewState.video) {
            previewState.video.pause();
            previewState.video.removeAttribute('src');
            previewState.video.load();
            previewState.video = null;
        }
    },

    cleanupImageLoad: function () {
        var loads = previewState.imageLoads;
        if (loads && loads.length) {
            for (var i = 0; i < loads.length; i++) {
                if (typeof loads[i].cleanup === 'function') {
                    loads[i].cleanup();
                }
            }
            previewState.imageLoads = [];
        }
    },

    prev: function () {
        if (!previewState.fileList) return;
        var idx = previewState.fileIndex - 1;
        while (idx >= 0) {
            var target = previewState.fileList[idx];
            if (target && !target.is_dir) {
                previewState.fileIndex = idx;
                openPreviewById(target.id);
                return;
            }
            idx--;
        }
    },

    next: function () {
        if (!previewState.fileList) return;
        var idx = previewState.fileIndex + 1;
        while (idx < previewState.fileList.length) {
            var target = previewState.fileList[idx];
            if (target && !target.is_dir) {
                previewState.fileIndex = idx;
                openPreviewById(target.id);
                return;
            }
            idx++;
        }
    },

    download: function () {
        if (previewState.fileId) {
            downloadFile(previewState.fileId);
        }
    },

    updateNavButtons: function () {
        var prevBtn = document.getElementById('previewPrevBtn');
        var nextBtn = document.getElementById('previewNextBtn');
        if (!previewState.fileList) {
            prevBtn.style.display = 'none';
            nextBtn.style.display = 'none';
            return;
        }
        prevBtn.style.display = '';
        nextBtn.style.display = '';
        var hasPrev = false, hasNext = false;
        for (var i = previewState.fileIndex - 1; i >= 0; i--) {
            if (!previewState.fileList[i].is_dir) { hasPrev = true; break; }
        }
        for (var j = previewState.fileIndex + 1; j < previewState.fileList.length; j++) {
            if (!previewState.fileList[j].is_dir) { hasNext = true; break; }
        }
        prevBtn.disabled = !hasPrev;
        nextBtn.disabled = !hasNext;
    },

    setHeader: function (file) {
        var iconMap = {
            'image': 'fa-file-image', 'video': 'fa-file-video', 'audio': 'fa-file-audio',
            'text': 'fa-file-code', 'code': 'fa-file-code', 'pdf': 'fa-file-pdf',
            'excel': 'fa-file-excel', 'word': 'fa-file-word', 'markdown': 'fa-file-alt',
            'csv': 'fa-file-csv', 'file': 'fa-file', 'folder': 'fa-folder',
            'archive': 'fa-file-archive', 'unknown': 'fa-file'
        };
        var iconClass = iconMap[file.icon] || iconMap[file.file_type] || 'fa-file';
        document.getElementById('previewFileIcon').innerHTML = '<i class="fas ' + iconClass + '"></i>';
        document.getElementById('previewFileName').textContent = file.filename || '';
        document.getElementById('previewFileSize').textContent = file.filesize_formatted || '';
    },

    showLoading: function () {
        document.getElementById('previewLoading').style.display = '';
        document.getElementById('previewContent').style.display = 'none';
        document.getElementById('previewError').style.display = 'none';
    },

    hideLoading: function () {
        document.getElementById('previewLoading').style.display = 'none';
        document.getElementById('previewContent').style.display = '';
        document.getElementById('previewError').style.display = 'none';
    },

    showError: function (msg, title) {
        document.getElementById('previewLoading').style.display = 'none';
        document.getElementById('previewContent').style.display = 'none';
        var err = document.getElementById('previewError');
        err.style.display = 'flex';
        document.getElementById('previewErrorMessage').textContent = msg || '无法加载文件';
        var titleEl = err.querySelector('.preview-error-title');
        if (titleEl) titleEl.textContent = title || '无法加载文件';
    },

    setContent: function (html, cssClass) {
        var content = document.getElementById('previewContent');
        content.innerHTML = html;
        content.className = 'preview-content' + (cssClass ? ' ' + cssClass : '');
        var body = document.getElementById('previewBody');
        if (body) {
            body.classList.remove('pdf-mode', 'word-mode', 'excel-mode');
            if (cssClass === 'pdf') body.classList.add('pdf-mode');
            else if (cssClass === 'word') body.classList.add('word-mode');
            else if (cssClass === 'excel') body.classList.add('excel-mode');
        }
        this.hideLoading();
    },

    setFooter: function (html, cssClass) {
        var footer = document.getElementById('previewFooter');
        footer.innerHTML = html;
        footer.className = 'preview-footer' + (cssClass ? ' ' + cssClass : '');
        footer.style.display = html ? '' : 'none';
    },

    setType: function (type) {
        this._currentType = type;
    }
};

PreviewShell.init();

// ===== 入口 =====

function previewFile(fileId) {
    api('record_access', { file_id: fileId });

    var fileList = typeof currentFileList !== 'undefined' && Array.isArray(currentFileList) ? currentFileList : null;
    var index = -1;
    if (fileList) {
        for (var i = 0; i < fileList.length; i++) {
            if (fileList[i].id === fileId) { index = i; break; }
        }
    }

    PreviewShell.open(fileId, fileList, index);

    api('file_info', { file_id: fileId }, 'GET').then(function (infoData) {
        if (!infoData.success) {
            PreviewShell.showError('无法获取文件信息');
            return;
        }
        var file = infoData.file;
        PreviewShell.setHeader(file);
        if (index >= 0 && fileList) fileList[index] = file;
        loadPreviewByType(file, fileId);
    }).catch(function () {
        PreviewShell.showError('网络错误，无法加载文件信息');
    });
}

function openPreviewById(fileId) {
    // 导航切换：复用已有 fileList，不重新计算
    var fileList = previewState.fileList;
    var index = -1;
    if (fileList) {
        for (var i = 0; i < fileList.length; i++) {
            if (fileList[i].id === fileId) { index = i; break; }
        }
    }
    PreviewShell.open(fileId, fileList, index);
    api('file_info', { file_id: fileId }, 'GET').then(function (infoData) {
        if (!infoData.success) { PreviewShell.showError('无法获取文件信息'); return; }
        var file = infoData.file;
        PreviewShell.setHeader(file);
        if (index >= 0 && fileList) fileList[index] = file;
        loadPreviewByType(file, fileId);
    }).catch(function () {
        PreviewShell.showError('网络错误，无法加载文件信息');
    });
}

// 扩展名常量（保持与后端 PreviewService 同步）
var PREVIEW_EXTS = {
    image: ['jpg','jpeg','png','gif','bmp','webp','svg','ico','tiff','tif'],
    video: ['mp4','webm','avi','mkv','mov','wmv','flv','m4v','3gp','mpg','mpeg','ts','f4v','ogv','rm','rmvb','vob','mts','m2ts'],
    audio: ['mp3','wav','ogg','flac','aac','wma','aiff','aif','m4a','opus','ape','alac','ra','ram','ac3','amr','mid','midi'],
    text:  ['txt','json','xml','html','css','js','log','ini','cfg','yml','yaml','py','rb','java','c','cpp','h','go','rs','sql','ts','jsx','tsx','vue','sh','bash','bat','ps1','r','m','swift','kt','scala','php'],
    md:    ['md'],
    csv:   ['csv'],
    pdf:   ['pdf'],
    excel: ['xlsx','xls'],
    word:  ['docx', 'doc'],
    zip:   ['zip']
};

var PREVIEW_LIMITS = {
    image: 10*1024*1024,
    media: 150*1024*1024,
    text:  2*1024*1024,
    office: 150*1024*1024,
    pdf:   150*1024*1024,
    zip:   100*1024*1024
};

function extIn(group, ext) {
    return PREVIEW_EXTS[group].indexOf(ext) >= 0;
}

function loadPreviewByType(file, fileId) {
    var ext = (file.file_type || '').toLowerCase();
    var size = file.filesize || 0;
    // 用内容哈希/更新时间作为版本号，让浏览器可以长期缓存预览资源，文件变更后 URL 自动失效
    var hash = (file.content_hash || '').substring(0, 16) || (file.updated_at ? String(file.updated_at).replace(/\D/g, '') : '');
    var previewUrl = 'index.php?action=preview&file_id=' + fileId + (hash ? '&v=' + encodeURIComponent(hash) : '');

    if (extIn('image', ext)) {
        if (size > PREVIEW_LIMITS.image) { PreviewShell.showError('图片过大，无法预览'); return; }
        renderImageViewer(file, previewUrl, fileId);
        return;
    }
    if (extIn('video', ext)) {
        if (size > PREVIEW_LIMITS.media) { PreviewShell.showError('视频过大，无法预览'); return; }
        renderVideoPlayer(file, previewUrl, fileId);
        return;
    }
    if (extIn('audio', ext)) {
        if (size > PREVIEW_LIMITS.media) { PreviewShell.showError('音频过大，无法预览'); return; }
        renderAudioPlayer(file, previewUrl, fileId);
        return;
    }
    if (extIn('pdf', ext)) {
        if (size > PREVIEW_LIMITS.pdf) { PreviewShell.showError('PDF 过大，无法预览'); return; }
        renderPdfViewer(file, previewUrl, fileId);
        return;
    }
    if (extIn('text', ext) || extIn('md', ext) || extIn('csv', ext)) {
        if (size > PREVIEW_LIMITS.text) { PreviewShell.showError('文件过大，无法预览'); return; }
        PreviewShell.showLoading();
        api('preview', { file_id: fileId }, 'GET').then(function (data) {
            if (!data.success) { PreviewShell.showError(data.message || '无法预览'); return; }
            if (extIn('md', ext)) renderMarkdownPreviewInShell(data.content, data.filename);
            else if (extIn('csv', ext)) renderCsvPreviewInShell(data.content, data.filename);
            else renderTextPreviewInShell(data.content, data.filename);
        }).catch(function () { PreviewShell.showError('网络错误，无法加载文件内容'); });
        return;
    }
    if (extIn('excel', ext)) {
        if (size > PREVIEW_LIMITS.office) { PreviewShell.showError('文件过大，无法预览'); return; }
        PreviewShell.showLoading();
        loadScript('xlsx').then(function () {
            renderExcelPreviewInShell(previewUrl, file.filename);
        }).catch(function () { PreviewShell.showError('Excel 预览组件加载失败'); });
        return;
    }
    if (extIn('word', ext)) {
        if (size > PREVIEW_LIMITS.office) { PreviewShell.showError('文件过大，无法预览'); return; }
        // .doc 格式 docx-preview 不支持，提示用户
        if (ext === 'doc') {
            PreviewShell.setContent(
                '<div class="pv-doc-frame">' +
                    '<div class="pv-doc-header">' +
                        '<div class="pv-doc-title"><i class="fas fa-file-word"></i><span>' + escapeHtml(file.filename) + '</span></div>' +
                    '</div>' +
                    '<div class="pv-doc-body" style="display:flex;align-items:center;justify-content:center;flex-direction:column;gap:16px;text-align:center;">' +
                        '<i class="fas fa-exclamation-circle" style="font-size:48px;color:#9ca3af;"></i>' +
                        '<p style="font-size:16px;color:#374151;">旧版 .doc 格式不支持在线预览</p>' +
                        '<p style="font-size:13px;color:#9ca3af;">建议转换为 .docx 格式后预览，或下载查看</p>' +
                        '<button id="docDownloadBtn" class="pv-tool-btn" style="margin-top:8px;padding:10px 24px;background:#2563eb;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:14px;">下载文件</button>' +
                    '</div>' +
                '</div>',
                'text'
            );
            var docDlBtn = document.getElementById('docDownloadBtn');
            if (docDlBtn) docDlBtn.onclick = function () { downloadFile(fileId); };
            return;
        }
        PreviewShell.showLoading();
        // docx-preview 依赖 JSZip 全局变量，必须先加载 jszip
        loadScript('jszip').then(function () {
            return loadScript('docx');
        }).then(function () {
            renderWordPreviewInShell(previewUrl, file.filename);
        }).catch(function (err) {
            var msg = err && err.message ? err.message : '';
            PreviewShell.showError('Word 预览组件加载失败' + (msg ? '：' + msg : ''), '无法加载文件');
        });
        return;
    }
    if (extIn('zip', ext)) {
        if (size > PREVIEW_LIMITS.zip) { PreviewShell.showError('压缩包过大，无法预览'); return; }
        PreviewShell.showLoading();
        api('preview', { file_id: fileId }, 'GET').then(function (data) {
            if (!data.success) { PreviewShell.showError(data.message || '无法预览'); return; }
            renderZipPreviewInShell(data);
        }).catch(function () { PreviewShell.showError('网络错误，无法加载压缩包内容'); });
        return;
    }

    PreviewShell.showError('暂不支持该文件类型的预览');
}

// ===== 通用工具函数 =====

function escapeAttr(str) {
    return escapeHtml(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function buildToolbarButton(id, icon, title, extraClass) {
    return '<button class="pv-tool-btn' + (extraClass ? ' ' + extraClass : '') + '" id="' + id + '" title="' + escapeAttr(title) + '">' +
        '<i class="fas ' + icon + '"></i></button>';
}

function buildIconButton(id, icon, title, extraClass) {
    return '<button class="pv-icon-btn' + (extraClass ? ' ' + extraClass : '') + '" id="' + id + '" title="' + escapeAttr(title) + '">' +
        '<i class="fas ' + icon + '"></i></button>';
}

// ===== 文本 / 代码预览 =====

function renderTextPreviewInShell(content, filename) {
    PreviewShell.setType('text');
    var lines = content.split('\n');
    var lineCount = lines.length;
    var lineNumWidth = String(lineCount).length;
    var lineNumbersHtml = '';
    for (var i = 0; i < lines.length; i++) {
        lineNumbersHtml += '<span class="line-num">' + (i + 1) + '</span>';
    }
    var ext = (filename.split('.').pop() || '').toLowerCase();
    var langClass = 'language-' + ext;

    // 先转义整个内容，hljs 高亮后再按行包裹
    var escapedCode = escapeHtml(content);

    PreviewShell.setContent(
        '<div class="pv-doc-frame">' +
            '<div class="pv-doc-body code-preview" id="codePreviewBody">' +
                '<div class="code-line-numbers" style="--line-num-width:' + (lineNumWidth * 9 + 28) + 'px">' + lineNumbersHtml + '</div>' +
                '<pre id="codePre"><code class="' + langClass + '" id="codeBlock">' + escapedCode + '</code></pre>' +
            '</div>' +
        '</div>',
        'text'
    );

    PreviewShell.setFooter(
        '<div class="pv-image-toolbar">' +
            buildIconButton('codeWrapBtn', 'fa-text-width', '切换自动换行') +
            buildIconButton('codeCopyBtn', 'fa-copy', '复制全部') +
            '<span class="pv-toolbar-divider"></span>' +
            '<span class="pv-footer-meta">' + lineCount + ' 行</span>' +
        '</div>'
    );

    setTimeout(function () {
        var block = document.getElementById('codeBlock');
        loadScript('highlight').then(function () {
            loadHighlightCSS();
            if (block && typeof hljs !== 'undefined') {
                // 直接高亮整个代码块，不拆分行（避免破坏跨行 span）
                hljs.highlightElement(block);
            }
        }).catch(function () {});

        var copyBtn = document.getElementById('codeCopyBtn');
        if (copyBtn) {
            copyBtn.onclick = function () {
                var block = document.getElementById('codeBlock');
                if (!block) return;
                navigator.clipboard.writeText(block.textContent).then(function () {
                    copyBtn.innerHTML = '<i class="fas fa-check"></i>';
                    setTimeout(function () { copyBtn.innerHTML = '<i class="fas fa-copy"></i>'; }, 1500);
                }).catch(function () {});
            };
        }
        var wrapBtn = document.getElementById('codeWrapBtn');
        var pre = document.getElementById('codePre');
        if (wrapBtn && pre) {
            wrapBtn.onclick = function () {
                var wrapped = pre.style.whiteSpace === 'pre-wrap';
                pre.style.whiteSpace = wrapped ? 'pre' : 'pre-wrap';
                wrapBtn.classList.toggle('active', !wrapped);
            };
        }
    }, 50);
}

// ===== Markdown 预览 =====

// Markdown 常用 emoji 简码映射（marked-emoji 需要）
var MARKDOWN_EMOJIS = {
    '+1': '👍', '-1': '👎', 'smile': '😄', 'heart': '❤️', 'fire': '🔥',
    'rocket': '🚀', 'star': '⭐', 'warning': '⚠️', 'x': '❌', 'white_check_mark': '✅',
    'bug': '🐛', 'bulb': '💡', 'memo': '📝', 'tada': '🎉', 'sparkles': '✨',
    'books': '📚', 'link': '🔗', 'globe': '🌐', 'lock': '🔒', 'key': '🔑',
    'mag': '🔍', 'package': '📦', 'tag': '🏷️', 'chart': '📊', 'calendar': '📅'
};

// 从 Markdown 中提取数学公式，替换为占位元素（DOMPurify 会保留 data-tex，清洗后再渲染）
function extractMathPlaceholders(content) {
    // 块级公式 $$...$$
    content = content.replace(/\$\$([\s\S]*?)\$\$/g, function (match, tex) {
        return '<div class="katex-math-block" data-tex="' + escapeHtml(tex.trim()) + '"></div>';
    });

    // 行内公式 $...$，排除转义符 \$ 和纯数字金额
    content = content.replace(/(^|[^\\])\$([^\$\n]+?)\$([^\d]|$)/g, function (match, before, tex, after) {
        return before + '<span class="katex-math-inline" data-tex="' + escapeHtml(tex.trim()) + '"></span>' + after;
    });

    return content;
}

// 渲染单个数学公式
function renderKatexFormula(tex, displayMode) {
    if (typeof katex === 'undefined') return escapeHtml(tex);
    try {
        return katex.renderToString(tex, {
            throwOnError: false,
            displayMode: displayMode,
            strict: false
        });
    } catch (e) {
        return '<span class="katex-error">' + escapeHtml(tex) + '</span>';
    }
}

// 配置并返回 marked 解析器
function setupMarkedParser() {
    // 启用 GFM、自动换行与安全 HTML（后续由 DOMPurify 清洗）
    marked.setOptions({
        breaks: true,
        gfm: true,
        headerIds: true,
        mangle: false,
        html: true
    });

    // 注册扩展（若加载成功）
    try {
        if (typeof markedFootnote === 'function') {
            marked.use(markedFootnote({
                description: '脚注',
                placement: 'bottom'
            }));
        }
    } catch (e) { console.warn('[Markdown] footnote 扩展注册失败', e); }

    try {
        var emojiExt = (typeof markedEmoji === 'function') ? markedEmoji : (markedEmoji && typeof markedEmoji.markedEmoji === 'function' ? markedEmoji.markedEmoji : null);
        if (emojiExt) {
            marked.use(emojiExt({
                emojis: MARKDOWN_EMOJIS,
                unicode: true
            }));
        }
    } catch (e) { console.warn('[Markdown] emoji 扩展注册失败', e); }

    try {
        if (typeof markedAlert === 'function') {
            marked.use(markedAlert());
        }
    } catch (e) { console.warn('[Markdown] alert 扩展注册失败', e); }
}

// 执行 Markdown 解析与后处理
function renderMarkdownContent(content, filename, fallbackBase) {
    fallbackBase = fallbackBase === true;
    var md = extractMathPlaceholders(content);

    var html;
    try {
        if (!fallbackBase && (typeof marked.parse === 'function')) {
            html = marked.parse(md);
        } else if (typeof marked === 'function') {
            html = marked(md);
        } else {
            throw new Error('marked 不可用');
        }
    } catch (e) {
        renderTextPreviewInShell(content, filename);
        return;
    }

    // 代码高亮
    if (typeof hljs !== 'undefined') {
        var tempDiv = document.createElement('div');
        tempDiv.innerHTML = html;
        tempDiv.querySelectorAll('pre code').forEach(function (block) {
            var cls = block.className || '';
            var langMatch = cls.match(/language-([^\s]+)/);
            var lang = langMatch ? langMatch[1] : '';
            // mermaid 交由后续处理，这里不高亮
            if (lang === 'mermaid') return;
            if (lang && hljs.getLanguage(lang)) {
                hljs.highlightElement(block);
            } else if (!lang) {
                hljs.highlightElement(block);
            }
        });
        html = tempDiv.innerHTML;
    }

    // DOMPurify 清洗：允许更多语义标签，保留数学公式占位 data-tex
    if (window.DOMPurify) {
        html = window.DOMPurify.sanitize(html, {
            ADD_TAGS: ['details', 'summary', 'kbd', 'mark', 'sub', 'sup', 'abbr', 'dfn', 'figure', 'figcaption', 'del', 'ins'],
            ADD_ATTR: ['target', 'rel', 'title', 'class', 'id', 'data-tex'],
            KEEP_CONTENT: true
        });
    }

    // 渲染数学公式占位元素
    var wrap = document.createElement('div');
    wrap.innerHTML = html;
    wrap.querySelectorAll('.katex-math-inline').forEach(function (el) {
        el.outerHTML = renderKatexFormula(el.getAttribute('data-tex') || '', false);
    });
    wrap.querySelectorAll('.katex-math-block').forEach(function (el) {
        el.outerHTML = '<div class="katex-block">' + renderKatexFormula(el.getAttribute('data-tex') || '', true) + '</div>';
    });
    html = wrap.innerHTML;

    PreviewShell.setContent(
        '<div class="pv-doc-frame">' +
            '<div class="pv-doc-body markdown-body"><div class="markdown-content">' + html + '</div></div>' +
        '</div>',
        'text'
    );

    // 渲染 Mermaid 图表
    setTimeout(function () {
        renderMermaidDiagrams();
    }, 50);
}

// 将 code.language-mermaid 替换为 mermaid div 并渲染
function renderMermaidDiagrams() {
    if (typeof mermaid === 'undefined') return;
    var container = document.querySelector('.markdown-content');
    if (!container) return;

    var blocks = container.querySelectorAll('pre code.language-mermaid');
    if (blocks.length === 0) return;

    try {
        mermaid.initialize({ startOnLoad: false, theme: 'default' });
    } catch (e) {}

    blocks.forEach(function (block) {
        var pre = block.parentNode;
        var code = block.textContent || '';
        var id = 'mermaid-' + Math.random().toString(36).slice(2, 10);
        var div = document.createElement('div');
        div.className = 'mermaid';
        div.id = id;
        div.textContent = code;
        if (pre && pre.parentNode) {
            pre.parentNode.replaceChild(div, pre);
        }
    });

    try {
        mermaid.run({ querySelector: '.mermaid' });
    } catch (e) {
        console.warn('[Markdown] mermaid 渲染失败', e);
    }
}

function renderMarkdownPreviewInShell(content, filename) {
    PreviewShell.setType('markdown');

    // 优先加载 marked；扩展、公式、图表库失败不阻断基础渲染
    loadScript('marked').then(function () {
        var optional = ['markedFootnote', 'markedEmoji', 'markedAlert', 'highlight', 'katex', 'mermaid'];
        return loadScripts(optional).catch(function (err) {
            console.warn('[Markdown] 部分扩展库加载失败，将使用基础能力', err);
        });
    }).then(function () {
        loadHighlightCSS();
        loadKatexCSS();
        setupMarkedParser();
        renderMarkdownContent(content, filename);
    }).catch(function () {
        renderTextPreviewInShell(content, filename);
    });
}

// ===== CSV 预览 =====

/**
 * 解析 CSV 文本，正确处理引号内的分隔符和换行
 * @param {string} text CSV 文本
 * @param {string} delimiter 分隔符，默认逗号
 * @returns {string[][]} 二维数组，每个元素是一行的单元格数组
 */
function parseCsv(text, delimiter) {
    delimiter = delimiter || ',';
    // 移除 UTF-8 BOM
    if (text.charCodeAt(0) === 0xFEFF) text = text.slice(1);
    var rows = [];
    var currentRow = [];
    var currentCell = '';
    var inQuotes = false;
    var i = 0;
    while (i < text.length) {
        var ch = text[i];
        if (inQuotes) {
            if (ch === '"') {
                if (text[i + 1] === '"') { currentCell += '"'; i += 2; continue; }
                inQuotes = false; i++; continue;
            }
            currentCell += ch; i++; continue;
        }
        if (ch === '"') { inQuotes = true; i++; continue; }
        if (ch === delimiter) { currentRow.push(currentCell); currentCell = ''; i++; continue; }
        if (ch === '\r') { i++; continue; }
        if (ch === '\n') {
            currentRow.push(currentCell);
            if (currentRow.some(function (c) { return c.trim(); })) rows.push(currentRow);
            currentRow = []; currentCell = ''; i++; continue;
        }
        currentCell += ch; i++;
    }
    if (currentCell || currentRow.length) {
        currentRow.push(currentCell);
        if (currentRow.some(function (c) { return c.trim(); })) rows.push(currentRow);
    }
    return rows;
}

function renderCsvPreviewInShell(content, filename) {
    PreviewShell.setType('csv');
    // 更稳健的分隔符检测：统计前 5 行中各分隔符的出现频率
    var firstLines = content.split('\n').slice(0, 5).join('\n');
    var tabCount = (firstLines.match(/\t/g) || []).length;
    var commaCount = (firstLines.match(/,/g) || []).length;
    var delimiter = tabCount > commaCount ? '\t' : ',';
    var rows = parseCsv(content, delimiter);
    if (rows.length === 0) { PreviewShell.showError('CSV 文件为空'); return; }
    var headers = rows[0];
    var html = '<table class="pv-table"><thead><tr>';
    for (var i = 0; i < headers.length; i++) html += '<th>' + escapeHtml(headers[i].trim()) + '</th>';
    html += '</tr></thead><tbody>';
    var maxRows = Math.min(rows.length, 500);
    for (var r = 1; r < maxRows; r++) {
        var cells = rows[r];
        html += '<tr>';
        for (var c = 0; c < cells.length; c++) html += '<td>' + escapeHtml(cells[c].trim()) + '</td>';
        html += '</tr>';
    }
    html += '</tbody></table>';
    if (rows.length > 500) html += '<p class="pv-note">文件过大，仅显示前 500 行</p>';

    PreviewShell.setContent(
        '<div class="pv-doc-frame">' +
            '<div class="pv-doc-body csv-body">' + html + '</div>' +
        '</div>',
        'text'
    );

    PreviewShell.setFooter('<span class="pv-footer-meta"><i class="fas fa-info-circle"></i> ' + rows.length + ' 行 × ' + headers.length + ' 列</span>');
}

// ===== ZIP 预览 =====

function renderZipPreviewInShell(data) {
    PreviewShell.setType('zip');

    var filename = data.filename || '';
    var totalCount = data.total_count || 0;
    var displayCount = data.display_count || 0;
    var hasMore = data.has_more || false;
    var totalSize = data.total_size || 0;
    var compressedSize = data.compressed_size || 0;
    var entries = data.entries || [];
    var compressionRatio = totalSize > 0 ? ((totalSize - compressedSize) / totalSize * 100) : 0;

    function formatBytes(bytes) {
        if (!bytes && bytes !== 0) return '—';
        if (bytes === 0) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var k = 1024;
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        if (i >= units.length) i = units.length - 1;
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + units[i];
    }

    function getFileIconClass(name, isDir) {
        if (isDir) return 'fa-folder';
        var ext = (name.split('.').pop() || '').toLowerCase();
        if (['jpg','jpeg','png','gif','bmp','webp','svg','ico','tiff','tif'].indexOf(ext) >= 0) return 'fa-file-image';
        if (['mp4','webm','avi','mkv','mov','wmv','flv','m4v','3gp','mpg','mpeg','ts','f4v','ogv','rm','rmvb','vob','mts','m2ts'].indexOf(ext) >= 0) return 'fa-file-video';
        if (['mp3','wav','ogg','flac','aac','wma','aiff','aif','m4a','opus','ape','alac','ra','ram','ac3','amr','mid','midi'].indexOf(ext) >= 0) return 'fa-file-audio';
        if (ext === 'pdf') return 'fa-file-pdf';
        if (['xlsx','xls','csv'].indexOf(ext) >= 0) return 'fa-file-excel';
        if (['docx','doc'].indexOf(ext) >= 0) return 'fa-file-word';
        if (['zip','rar','7z','tar','gz','bz2','xz'].indexOf(ext) >= 0) return 'fa-file-archive';
        if (['txt','json','xml','html','css','js','log','ini','cfg','yml','yaml','py','rb','java','c','cpp','h','go','rs','sql','ts','jsx','tsx','vue','sh','bash','bat','ps1','r','m','swift','kt','scala','php','md'].indexOf(ext) >= 0) return 'fa-file-code';
        return 'fa-file';
    }

    function buildZipTree(entries) {
        var root = { name: '', children: [], isDir: true, entry: null, expanded: true, depth: -1 };
        for (var i = 0; i < entries.length; i++) {
            var entry = entries[i];
            var name = entry.name || '';
            var parts = name.split('/');
            var node = root;
            for (var j = 0; j < parts.length; j++) {
                var part = parts[j];
                if (!part && j === parts.length - 1) continue;
                if (!part) continue;
                var isLast = j === parts.length - 1;
                var isDir = !isLast || entry.is_dir;
                var found = null;
                for (var k = 0; k < node.children.length; k++) {
                    if (node.children[k].name === part && node.children[k].isDir === isDir) {
                        found = node.children[k];
                        break;
                    }
                }
                if (!found) {
                    var newNode = {
                        name: part,
                        isDir: isDir,
                        children: [],
                        entry: isLast ? entry : null,
                        expanded: true,
                        depth: node.depth + 1
                    };
                    node.children.push(newNode);
                    found = newNode;
                }
                node = found;
            }
        }
        return root;
    }

    function sortTree(node) {
        node.children.sort(function (a, b) {
            if (a.isDir && !b.isDir) return -1;
            if (!a.isDir && b.isDir) return 1;
            return a.name.localeCompare(b.name);
        });
        for (var i = 0; i < node.children.length; i++) sortTree(node.children[i]);
    }

    function renderTree(node) {
        if (!node || !node.children.length) return '';
        var html = '';
        for (var i = 0; i < node.children.length; i++) {
            var child = node.children[i];
            var entry = child.entry || {};
            var size = entry.size != null ? entry.size : 0;
            var compSize = entry.compressed_size != null ? entry.compressed_size : 0;
            var date = entry.date || '';
            var isDir = child.isDir;
            var iconClass = getFileIconClass(child.name, isDir);
            var paddingLeft = 12 + child.depth * 18;
            if (isDir) {
                html += '<div class="pv-zip-node pv-zip-dir" data-dir="true" style="padding-left:' + paddingLeft + 'px">' +
                    '<span class="pv-zip-toggle"><i class="fas fa-chevron-down"></i></span>' +
                    '<span class="pv-zip-icon"><i class="fas ' + iconClass + '"></i></span>' +
                    '<span class="pv-zip-name">' + escapeHtml(child.name) + '</span>' +
                    '<span class="pv-zip-size">—</span>' +
                    '<span class="pv-zip-size">—</span>' +
                    '<span class="pv-zip-date">' + escapeHtml(date) + '</span>' +
                    '</div>' +
                    '<div class="pv-zip-children">' + renderTree(child) + '</div>';
            } else {
                html += '<div class="pv-zip-node pv-zip-file" style="padding-left:' + paddingLeft + 'px">' +
                    '<span class="pv-zip-toggle"></span>' +
                    '<span class="pv-zip-icon"><i class="fas ' + iconClass + '"></i></span>' +
                    '<span class="pv-zip-name">' + escapeHtml(child.name) + '</span>' +
                    '<span class="pv-zip-size">' + formatBytes(size) + '</span>' +
                    '<span class="pv-zip-size">' + formatBytes(compSize) + '</span>' +
                    '<span class="pv-zip-date">' + escapeHtml(date) + '</span>' +
                    '</div>';
            }
        }
        return html;
    }

    var root = buildZipTree(entries);
    sortTree(root);
    var treeHtml = renderTree(root);

    if (!treeHtml) {
        treeHtml = '<div class="pv-zip-empty"><i class="fas fa-folder-open"></i><p>压缩包为空</p></div>';
    }

    var summaryHtml = '<div class="pv-zip-summary">' +
        '<div class="pv-zip-stat"><span class="pv-zip-stat-label">总条目</span><span class="pv-zip-stat-value">' + totalCount + '</span></div>' +
        '<div class="pv-zip-stat"><span class="pv-zip-stat-label">已显示</span><span class="pv-zip-stat-value">' + displayCount + '</span></div>' +
        '<div class="pv-zip-stat"><span class="pv-zip-stat-label">原始大小</span><span class="pv-zip-stat-value">' + formatBytes(totalSize) + '</span></div>' +
        '<div class="pv-zip-stat"><span class="pv-zip-stat-label">压缩大小</span><span class="pv-zip-stat-value">' + formatBytes(compressedSize) + '</span></div>' +
        '<div class="pv-zip-stat"><span class="pv-zip-stat-label">压缩率</span><span class="pv-zip-stat-value">' + compressionRatio.toFixed(1) + '%</span></div>' +
        '</div>';

    var moreHtml = hasMore ? '<div class="pv-zip-more"><i class="fas fa-exclamation-triangle"></i> 条目过多，仅显示前 ' + displayCount + ' 项</div>' : '';

    var html = '<div class="pv-doc-frame pv-zip-frame">' +
        '<div class="pv-doc-header pv-zip-header">' +
            '<div class="pv-doc-title"><i class="fas fa-file-archive"></i><span>' + escapeHtml(filename) + '</span></div>' +
        '</div>' +
        summaryHtml +
        moreHtml +
        '<div class="pv-doc-body pv-zip-body">' +
            '<div class="pv-zip-tree">' +
                '<div class="pv-zip-node pv-zip-header-row">' +
                    '<span class="pv-zip-toggle"></span>' +
                    '<span class="pv-zip-icon"></span>' +
                    '<span class="pv-zip-name">名称</span>' +
                    '<span class="pv-zip-size">原始大小</span>' +
                    '<span class="pv-zip-size">压缩大小</span>' +
                    '<span class="pv-zip-date">修改时间</span>' +
                '</div>' +
                treeHtml +
            '</div>' +
        '</div>' +
    '</div>';

    PreviewShell.setContent(html, 'zip');
    PreviewShell.setFooter(
        '<span class="pv-footer-meta"><i class="fas fa-file-archive"></i> ' + totalCount + ' 项</span>' +
        '<span class="pv-toolbar-divider"></span>' +
        '<span class="pv-footer-meta">原始 ' + formatBytes(totalSize) + '</span>' +
        '<span class="pv-footer-meta">压缩 ' + formatBytes(compressedSize) + '</span>' +
        '<span class="pv-footer-meta">压缩率 ' + compressionRatio.toFixed(1) + '%</span>'
    );

    var frame = document.querySelector('.pv-zip-frame');
    if (frame) {
        frame.addEventListener('click', function (e) {
            var dirRow = e.target.closest('.pv-zip-dir');
            if (!dirRow) return;
            var children = dirRow.nextElementSibling;
            if (!children || !children.classList.contains('pv-zip-children')) return;
            var isExpanded = children.style.display !== 'none';
            children.style.display = isExpanded ? 'none' : '';
            var icon = dirRow.querySelector('.pv-zip-toggle i');
            if (icon) icon.className = 'fas ' + (isExpanded ? 'fa-chevron-right' : 'fa-chevron-down');
            e.stopPropagation();
        });
    }
}

// ===== Excel 预览 =====

function renderExcelPreviewInShell(downloadUrl, filename) {
    PreviewShell.setType('excel');
    fetch(downloadUrl).then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.arrayBuffer();
    }).then(function (data) {
        var workbook = XLSX.read(data, { type: 'array', cellStyles: true, cellDates: true, cellNF: true });
        window._currentWorkbook = workbook;

        var tabsHtml = '';
        for (var i = 0; i < workbook.SheetNames.length; i++) {
            var name = workbook.SheetNames[i];
            tabsHtml += '<button class="pv-tab' + (i === 0 ? ' active' : '') + '" data-sheet="' + escapeAttr(name) + '" data-action="switch-excel-tab">' + escapeHtml(name) + '</button>';
        }

        PreviewShell.setContent(
            '<div class="pv-doc-frame excel-frame">' +
                '<div class="pv-tabs-bar">' +
                    '<div class="pv-tabs-left">' + tabsHtml + '</div>' +
                    '<div class="pv-tabs-right"><span class="pv-tab-hint">' + workbook.SheetNames.length + ' 个工作表 · 方向键切换</span></div>' +
                '</div>' +
                '<div class="pv-doc-body excel-body" id="excelSheetContent">' + renderExcelSheet(workbook, workbook.SheetNames[0]) + '</div>' +
            '</div>',
            'excel'
        );

        PreviewShell.setFooter('');
    }).catch(function (err) {
        PreviewShell.showError('Excel 加载失败：' + (err.message || ''));
    });
}

function switchExcelTab(btn) {
    document.querySelectorAll('.pv-tab').forEach(function (b) { b.classList.remove('active'); });
    btn.classList.add('active');
    var name = btn.getAttribute('data-sheet');
    var wb = window._currentWorkbook;
    if (wb && wb.Sheets[name]) {
        document.getElementById('excelSheetContent').innerHTML = renderExcelSheet(wb, name);
    }
}

// ── Excel 颜色解析 ──

// 主题色缓存
var _excelThemeColors = null;

function _buildThemeColors(wb) {
    if (_excelThemeColors) return _excelThemeColors;
    // 默认 Office 主题色
    _excelThemeColors = [
        '#FFFFFF', '#000000', '#E7E6E6', '#44546A', '#4472C4', '#ED7D31',
        '#A5A5A5', '#FFC000', '#5B9BD5', '#70AD47', '#264478', '#9B59B6',
        '#1F4E79', '#4472C4', '#D6DCE4', '#9DC3E6'
    ];
    return _excelThemeColors;
}

function _parseExcelColor(c) {
    if (!c) return '';
    // RGB 十六进制
    if (c.rgb) {
        var rgb = String(c.rgb);
        if (rgb.length === 8) return '#' + rgb.substr(2); // 前2位是ARGB的A
        if (rgb.length === 6) return '#' + rgb;
        return '#' + rgb;
    }
    // 主题色
    if (c.theme !== undefined) {
        var theme = _excelThemeColors || _buildThemeColors();
        var idx = c.theme < theme.length ? c.theme : 0;
        var base = theme[idx] || '#000000';
        if (c.tint !== undefined && c.tint !== 0) return _applyTint(base, c.tint);
        return base;
    }
    // 索引色
    if (c.indexed !== undefined) {
        var indexedColors = [
            '#000000','#FFFFFF','#FF0000','#00FF00','#0000FF','#FFFF00','#FF00FF','#00FFFF',
            '#000000','#FFFFFF','#FF0000','#00FF00','#0000FF','#FFFF00','#FF00FF','#00FFFF',
            '#800000','#008000','#000080','#808000','#800080','#008080','#C0C0C0','#808080',
            '#9999FF','#993366','#FFFFCC','#CCFFFF','#660066','#FF8080','#0066CC','#CCCCFF',
            '#000080','#FF00FF','#FFFF00','#00FFFF','#800080','#800000','#008080','#0000FF',
            '#00CCFF','#CCFFFF','#CCFFCC','#FFFF99','#99CCFF','#FF99CC','#CC99FF','#FFCC99',
            '#3366FF','#33CCCC','#99CC00','#FFCC00','#FF9900','#FF6600','#666699','#969696',
            '#003366','#339966','#003300','#333300','#993300','#993366','#333399','#333333',
            '#000000','#FFFFFF'
        ];
        return c.indexed < indexedColors.length ? indexedColors[c.indexed] : '';
    }
    return '';
}

function _applyTint(hexColor, tint) {
    if (!hexColor || hexColor[0] !== '#' || tint === 0) return hexColor;
    var r = parseInt(hexColor.substr(1, 2), 16) / 255;
    var g = parseInt(hexColor.substr(3, 2), 16) / 255;
    var b = parseInt(hexColor.substr(5, 2), 16) / 255;
    if (tint < 0) { r = r * (1 + tint); g = g * (1 + tint); b = b * (1 + tint); }
    else { r = r + (1 - r) * tint; g = g + (1 - g) * tint; b = b + (1 - b) * tint; }
    var toHex = function (v) { var h = Math.round(Math.max(0, Math.min(1, v)) * 255).toString(16); return h.length < 2 ? '0' + h : h; };
    return '#' + toHex(r) + toHex(g) + toHex(b);
}

// ── Excel 单元格样式 → CSS ──

var _borderStyleMap = {
    thin: '1px solid', medium: '2px solid', thick: '3px solid',
    dashed: '1px dashed', dotted: '1px dotted', hair: '1px solid',
    mediumDashed: '2px dashed', dashDot: '1px dashed', mediumDashDot: '2px dashed',
    dashDotDot: '1px dashed', mediumDashDotDot: '2px dashed', slantDashDot: '1px dashed',
    double: '3px double'
};

function _cellStyleToCSS(s) {
    if (!s) return '';
    var parts = [];

    // 字体
    if (s.font) {
        var f = s.font;
        if (f.bold) parts.push('font-weight:700');
        if (f.italic) parts.push('font-style:italic');
        if (f.underline) parts.push('text-decoration:underline');
        if (f.strike) parts.push('text-decoration:line-through');
        if (f.sz) parts.push('font-size:' + f.sz + 'pt');
        if (f.color) { var fc = _parseExcelColor(f.color); if (fc) parts.push('color:' + fc); }
        if (f.name) parts.push('font-family:"' + f.name + '",sans-serif');
    }

    // 填充（背景色）
    if (s.fill && s.fill.fgColor) {
        var bg = _parseExcelColor(s.fill.fgColor);
        if (bg && bg !== '#FFFFFF' && bg !== '#ffffff') parts.push('background-color:' + bg);
    }

    // 对齐
    if (s.alignment) {
        var a = s.alignment;
        var hMap = { left: 'left', center: 'center', right: 'right', fill: 'left', justify: 'justify', centerContinuous: 'center', distributed: 'justify' };
        var vMap = { top: 'top', center: 'middle', bottom: 'bottom', justify: 'middle', distributed: 'middle' };
        if (a.horizontal && hMap[a.horizontal]) parts.push('text-align:' + hMap[a.horizontal]);
        if (a.vertical && vMap[a.vertical]) parts.push('vertical-align:' + vMap[a.vertical]);
        if (a.wrapText) parts.push('white-space:pre-wrap;word-wrap:break-word');
        if (a.indent) parts.push('padding-left:' + (a.indent * 12) + 'px');
        if (a.textRotation) {
            if (a.textRotation === 255) parts.push('writing-mode:vertical-lr');
            else parts.push('transform:rotate(-' + a.textRotation + 'deg)');
        }
    }

    // 边框
    if (s.border) {
        ['top', 'bottom', 'left', 'right'].forEach(function (side) {
            var b = s.border[side];
            if (b && b.style && b.style !== 'none') {
                var bs = _borderStyleMap[b.style] || '1px solid';
                var bc = b.color ? _parseExcelColor(b.color) : '#000000';
                if (!bc) bc = '#000000';
                parts.push('border-' + side + ':' + bs + ' ' + bc);
            }
        });
    }

    return parts.join(';');
}

// ── 列标号生成 ──

function _colLabel(c) {
    var label = '', n = c;
    do {
        label = String.fromCharCode(65 + (n % 26)) + label;
        n = Math.floor(n / 26) - 1;
    } while (n >= 0);
    return label;
}

// ── 核心：渲染 Excel 工作表 ──

function renderExcelSheet(workbook, sheetName) {
    var ws = workbook.Sheets[sheetName];
    if (!ws || !ws['!ref']) return '<p class="pv-empty-tip">工作表为空</p>';

    // 初始化主题色
    _buildThemeColors(workbook);

    var range = XLSX.utils.decode_range(ws['!ref']);
    var merges = ws['!merges'] || [];
    var cols = ws['!cols'] || [];
    var rows = ws['!rows'] || [];

    // 构建合并单元格映射
    var mergeMap = {};
    var skipMap = {};
    for (var mi = 0; mi < merges.length; mi++) {
        var m = merges[mi];
        var key = m.s.r + '_' + m.s.c;
        mergeMap[key] = { rowspan: m.e.r - m.s.r + 1, colspan: m.e.c - m.s.c + 1 };
        for (var mr = m.s.r; mr <= m.e.r; mr++) {
            for (var mc = m.s.c; mc <= m.e.c; mc++) {
                if (mr !== m.s.r || mc !== m.s.c) skipMap[mr + '_' + mc] = true;
            }
        }
    }

    // 限制显示范围
    var maxRow = Math.min(range.e.r, 999);
    var maxCol = Math.min(range.e.c, 49); // 最多50列 A-AX
    var totalRows = range.e.r + 1;
    var totalCols = range.e.c + 1;
    var colTruncated = range.e.c > maxCol;

    // 冻结窗格
    var freeze = ws['!freeze'] || null; // { x: colNum, y: rowNum }

    var html = '<div class="pv-excel-table-wrap"><table class="pv-excel-table">';

    // 列宽
    html += '<colgroup><col class="pv-excel-rowhead-col">';
    for (var c = 0; c <= maxCol; c++) {
        var w = cols[c] ? (cols[c].wpx || Math.round((cols[c].wch || 10) * 8)) : 80;
        if (w < 30) w = 30;
        html += '<col style="width:' + w + 'px">';
    }
    html += '</colgroup>';

    // 表头行（列标号）
    html += '<thead><tr><th class="pv-excel-corner"></th>';
    for (var j = 0; j <= maxCol; j++) html += '<th>' + _colLabel(j) + '</th>';
    if (colTruncated) html += '<th class="pv-excel-more">…</th>';
    html += '</tr></thead>';

    // 表体
    html += '<tbody>';
    for (var ri = range.s.r; ri <= maxRow; ri++) {
        var rowH = rows[ri] ? (rows[ri].hpx || (rows[ri].hpt ? Math.round(rows[ri].hpt * 1.33) : 0)) : 0;
        var rowStyle = rowH > 0 ? ' style="height:' + rowH + 'px"' : '';
        var rowHidden = rows[ri] && rows[ri].hidden;
        if (rowHidden) rowStyle = ' style="display:none"';

        html += '<tr' + rowStyle + '><td class="pv-excel-rowhead">' + (ri + 1) + '</td>';

        for (var ci = range.s.c; ci <= maxCol; ci++) {
            var skipKey = ri + '_' + ci;
            if (skipMap[skipKey]) continue;

            var addr = XLSX.utils.encode_cell({ r: ri, c: ci });
            var cell = ws[addr];
            var attrs = '';
            var style = '';

            // 合并单元格属性
            var mergeKey = ri + '_' + ci;
            if (mergeMap[mergeKey]) {
                attrs += ' rowspan="' + mergeMap[mergeKey].rowspan + '"';
                attrs += ' colspan="' + mergeMap[mergeKey].colspan + '"';
                // 确保合并区域不超出显示范围
                if (mergeMap[mergeKey].rowspan + ri > maxRow + 1) {
                    attrs = ' rowspan="' + (maxRow - ri + 1) + '"';
                }
                if (mergeMap[mergeKey].colspan + ci > maxCol + 1) {
                    attrs += ' colspan="' + (maxCol - ci + 1) + '"';
                }
            }

            // 单元格样式
            if (cell && cell.s) {
                style = _cellStyleToCSS(cell.s);
            }

            // 单元格值：优先用格式化文本 w
            var display = '';
            if (cell) {
                if (cell.w) display = cell.w;
                else if (cell.t === 'd' && cell.v instanceof Date) {
                    var d = cell.v;
                    display = d.getFullYear() + '/' + (d.getMonth() + 1) + '/' + d.getDate();
                }
                else if (cell.v !== undefined && cell.v !== null) display = String(cell.v);
            }

            var styleAttr = style ? ' style="' + style + '"' : '';
            html += '<td' + attrs + styleAttr + '>' + escapeHtml(display) + '</td>';
        }

        if (colTruncated) html += '<td class="pv-excel-more">…</td>';
        html += '</tr>';
    }
    html += '</tbody></table></div>';

    // 截断提示
    var notes = [];
    if (totalRows > 1000) notes.push('共 ' + totalRows + ' 行，仅显示前 1000 行');
    if (totalCols > 50) notes.push('共 ' + totalCols + ' 列，仅显示前 50 列');
    if (notes.length) html += '<p class="pv-note">' + notes.join('；') + '</p>';

    return html;
}

// ===== Word 预览 =====

function renderWordPreviewInShell(downloadUrl, filename) {
    PreviewShell.setType('word');
    fetch(downloadUrl).then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.arrayBuffer();
    }).then(function (buf) {
        PreviewShell.setContent(
            '<div class="pv-doc-frame word-frame">' +
                '<div class="pv-doc-body word-body" id="wordDocBody">' +
                    '<div class="pv-word-loading" id="wordLoading">' +
                        '<div class="preview-loading-ring"></div>' +
                        '<p>正在渲染文档...</p>' +
                    '</div>' +
                '</div>' +
            '</div>',
            'word'
        );
        var container = document.getElementById('wordDocBody');
        var loading = document.getElementById('wordLoading');
        if (!container) return;
        var docx = window.docx;
        if (!docx || typeof docx.renderAsync !== 'function') {
            throw new Error('Word 预览组件不可用');
        }
        return docx.renderAsync(buf, container, null, {
            className: 'docx-preview',
            inWrapper: true,
            breakPages: true,
            renderHeaders: true,
            renderFooters: true,
            ignoreWidth: false,
            ignoreHeight: false
        }).then(function () {
            if (loading) loading.remove();
        });
    }).catch(function (err) {
        PreviewShell.showError('Word 加载失败：' + (err.message || ''));
    });
}

// ===== PDF 查看器 =====

function renderPdfViewer(file, previewUrl, fileId) {
    PreviewShell.setType('pdf');
    PreviewShell.setContent(
        '<div class="pv-pdf-wrap" id="pdfWrap">' +
            '<iframe src="' + escapeAttr(previewUrl) + '" id="pdfIframe" allowfullscreen></iframe>' +
        '</div>',
        'pdf'
    );
    // 不设置自定义 footer，完全依赖浏览器/PDF.js viewer 自带控件
    PreviewShell.setFooter('', 'pdf');

    // iframe 插入后立即显示 loading，等 PDF viewer 加载完成后再隐藏，避免干等
    var iframe = document.getElementById('pdfIframe');
    if (!iframe) return;

    PreviewShell.showLoading();

    var loadFired = false;
    var minDone = false;
    var timer = null;

    function tryHideLoading() {
        if (loadFired && minDone) {
            if (timer) { clearTimeout(timer); timer = null; }
            PreviewShell._pdfLoadTimer = null;
            PreviewShell.hideLoading();
        }
    }

    iframe.addEventListener('load', function onPdfLoad() {
        iframe.removeEventListener('load', onPdfLoad);
        loadFired = true;
        tryHideLoading();
    });

    timer = setTimeout(function () {
        minDone = true;
        tryHideLoading();
    }, 500);

    PreviewShell._pdfLoadTimer = timer;
}

// ===== 音频播放器（现代玻璃拟态） =====

var _audioPlaylist = [];
var _audioPlaylistIndex = 0;
var _audioLyrics = [];
var _audioMeta = null;

function resetAudioState() {
    _audioPlaylist = [];
    _audioPlaylistIndex = 0;
    _audioLyrics = [];
    _audioMeta = null;
    if (previewState.audio) {
        previewState.audio.pause();
        previewState.audio.src = '';
        previewState.audio = null;
    }
}

function parseLrc(content) {
    var lines = content.split(/\r?\n/);
    var result = [];
    var timeRegex = /\[(\d{2}):(\d{2})(?:\.(\d{1,3}))?\]/g;
    for (var i = 0; i < lines.length; i++) {
        var line = lines[i].trim();
        if (!line) continue;
        var matches = [];
        var m;
        while ((m = timeRegex.exec(line)) !== null) {
            matches.push(m);
        }
        if (matches.length === 0) continue;
        var text = line.replace(timeRegex, '').trim();
        for (var j = 0; j < matches.length; j++) {
            var min = parseInt(matches[j][1], 10);
            var sec = parseInt(matches[j][2], 10);
            var msStr = matches[j][3] || '0';
            var ms = parseInt(msStr.padEnd(3, '0').substring(0, 3), 10);
            result.push({ time: min * 60 + sec + ms / 1000, text: text });
        }
    }
    return result.sort(function (a, b) { return a.time - b.time; });
}

function findBestLyricFile(filename, lyricFiles) {
    if (!lyricFiles || lyricFiles.length === 0) return null;
    var base = filename.replace(/\.[^/.]+$/, '').toLowerCase();
    var best = null;
    for (var i = 0; i < lyricFiles.length; i++) {
        var lname = lyricFiles[i].filename.replace(/\.[^/.]+$/, '').toLowerCase();
        if (lname === base) return lyricFiles[i];
        if (!best && lname.indexOf(base) === 0) best = lyricFiles[i];
    }
    return best || lyricFiles[0];
}

function extractEmbeddedLyrics(tags) {
    if (!tags) return '';

    function extractText(value) {
        if (typeof value === 'string') return value;
        if (value && typeof value.lyrics === 'string') return value.lyrics;
        if (value && typeof value.text === 'string') return value.text;
        if (value && typeof value.data === 'string') return value.data;
        // jsmediatags 对 USLT 的常见结构：{ id, size, description, data: { language, descriptor, lyrics } }
        if (value && value.data && typeof value.data.lyrics === 'string') return value.data.lyrics;
        if (value && value.data && typeof value.data.text === 'string') return value.data.text;
        if (Array.isArray(value)) {
            for (var i = 0; i < value.length; i++) {
                var t = extractText(value[i]);
                if (t) return t;
            }
        }
        return '';
    }

    // MP3 ID3v2 USLT 帧（jsmediatags 通常同时提供 tags.lyrics 快捷字段）
    var uslt = extractText(tags.USLT);
    if (uslt) return uslt;

    // 常见字符串/对象标签名
    var directKeys = ['lyrics', 'LYRICS', 'unsynchronisedLyrics', 'UNSYNCEDLYRICS', 'SyncLyrics'];
    for (var k = 0; k < directKeys.length; k++) {
        var key = directKeys[k];
        if (typeof tags[key] === 'string' && tags[key].trim()) return tags[key];
        if (tags[key]) {
            var txt = extractText(tags[key]);
            if (txt) return txt;
        }
    }

    // M4A ©lyr 原子
    for (var m4aKey in tags) {
        if (tags.hasOwnProperty(m4aKey) && /\u00a9lyr/i.test(m4aKey)) {
            var m4aTxt = extractText(tags[m4aKey]);
            if (m4aTxt) return m4aTxt;
        }
    }

    // SYLT 同步歌词转 LRC
    var sylt = tags.SYLT;
    if (!sylt && tags.sylt) sylt = tags.sylt;
    if (sylt) {
        var syltArr = Array.isArray(sylt) ? sylt : [sylt];
        return syltArr.map(function(line) {
            if (!line || typeof line !== 'object') return '';
            var t = line.time || 0;
            var min = Math.floor(t / 60000);
            var sec = Math.floor((t % 60000) / 1000);
            var ms = Math.floor((t % 1000) / 10);
            return '[' + (min < 10 ? '0' : '') + min + ':' + (sec < 10 ? '0' : '') + sec + '.' + (ms < 10 ? '0' : '') + ms + ']' + (line.text || '');
        }).filter(Boolean).join('\n');
    }

    return '';
}

function readAudioMetadata(audioUrl, callback) {
    if (typeof jsmediatags === 'undefined') {
        callback(null);
        return;
    }
    try {
        // jsmediatags 直接读取 URL 时，其内部 XHR 在部分浏览器/环境下会被取消（net::ERR_ABORTED）。
        // 先通过 fetch 获取 Blob 再交给 jsmediatags 解析，可稳定读取 ID3 标签。
        if (typeof fetch !== 'undefined') {
            fetch(audioUrl, { method: 'GET', credentials: 'same-origin' })
                .then(function(response) {
                    if (!response.ok) throw new Error('fetch failed');
                    return response.blob();
                })
                .then(function(blob) {
                    jsmediatags.read(blob, {
                        onSuccess: function(tag) { callback(tag.tags); },
                        onError: function() { callback(null); }
                    });
                })
                .catch(function() {
                    // fallback：尝试直接 URL 读取
                    jsmediatags.read(audioUrl, {
                        onSuccess: function(tag) { callback(tag.tags); },
                        onError: function() { callback(null); }
                    });
                });
        } else {
            jsmediatags.read(audioUrl, {
                onSuccess: function(tag) { callback(tag.tags); },
                onError: function() { callback(null); }
            });
        }
    } catch (e) {
        callback(null);
    }
}

function updateAudioMetaUI(tags, file) {
    var titleEl = document.getElementById('audioTitle');
    var artistEl = document.getElementById('audioArtist');
    var albumEl = document.getElementById('audioAlbum');
    if (!titleEl) return;

    var title = (tags && tags.title) || (file ? file.filename.replace(/\.[^/.]+$/, '') : '');
    var artist = (tags && tags.artist) || '未知艺术家';
    var album = (tags && tags.album) || '';

    titleEl.textContent = title;
    titleEl.title = title;
    artistEl.textContent = artist;
    albumEl.textContent = album;
}

function updateAudioCoverFromTags(tags) {
    if (!tags || !tags.picture) return;
    var pic = tags.picture;
    var format = pic.format || 'image/jpeg';
    var bytes = new Uint8Array(pic.data);
    var blob = new Blob([bytes], { type: format });
    var url = URL.createObjectURL(blob);
    var img = document.getElementById('audioCoverImg');
    var bg = document.getElementById('audioBgBlur');
    if (img) {
        img.src = url;
        img.onload = function() { if (bg) bg.style.backgroundImage = 'url(' + url + ')'; };
    } else if (bg) {
        bg.style.backgroundImage = 'url(' + url + ')';
    }
}

function renderLyrics(lyrics) {
    var container = document.getElementById('audioLyrics');
    if (!container) return;
    if (!lyrics || lyrics.length === 0) {
        container.innerHTML = '<div class="pv-audio-lyrics-empty">暂无歌词</div>';
        container.dataset.hasLyrics = 'false';
        return;
    }
    container.dataset.hasLyrics = 'true';
    var html = '';
    for (var i = 0; i < lyrics.length; i++) {
        html += '<div class="pv-audio-lyric-line" data-time="' + lyrics[i].time + '">' + escapeHtml(lyrics[i].text || '') + '</div>';
    }
    container.innerHTML = html;
}

function updateActiveLyric(currentTime) {
    if (!_audioLyrics || _audioLyrics.length === 0) return;
    var container = document.getElementById('audioLyrics');
    if (!container || container.dataset.hasLyrics !== 'true') return;
    var lines = container.querySelectorAll('.pv-audio-lyric-line');
    var activeIndex = 0;
    for (var i = 0; i < _audioLyrics.length; i++) {
        if (_audioLyrics[i].time <= currentTime + 0.2) activeIndex = i;
        else break;
    }
    for (var j = 0; j < lines.length; j++) {
        lines[j].classList.toggle('active', j === activeIndex);
    }
    var activeLine = lines[activeIndex];
    if (activeLine) {
        activeLine.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

function renderAudioPlaylist() {
    var body = document.getElementById('audioPlaylistBody');
    if (!body) return;
    if (_audioPlaylist.length === 0) {
        body.innerHTML = '<div class="pv-audio-playlist-empty">暂无其他音频文件</div>';
        return;
    }
    var html = '';
    for (var i = 0; i < _audioPlaylist.length; i++) {
        var item = _audioPlaylist[i];
        var active = i === _audioPlaylistIndex ? ' active' : '';
        html += '<div class="pv-audio-playlist-item' + active + '" data-index="' + i + '">' +
            '<div class="pv-audio-playlist-icon"><i class="fas fa-music"></i></div>' +
            '<div class="pv-audio-playlist-info">' +
                '<div class="pv-audio-playlist-name">' + escapeHtml(item.filename) + '</div>' +
                '<div class="pv-audio-playlist-sub">' + (item.filesize_formatted || '') + '</div>' +
            '</div>' +
            (active ? '<div class="pv-audio-playlist-playing"><i class="fas fa-volume-up"></i></div>' : '') +
        '</div>';
    }
    body.innerHTML = html;
}

function loadAudioMeta(fileId, previewUrl) {
    api('audio_meta', { file_id: fileId }, 'GET').then(function(data) {
        if (data && data.success && data.playlist) {
            _audioMeta = data;
            _audioPlaylist = data.playlist || [];
            _audioPlaylistIndex = data.current_index || 0;
            renderAudioPlaylist();

            // 优先从后端提取内置歌词（支持 FLAC 等 jsmediatags 读不到的格式）
            loadEmbeddedLyrics(fileId).then(function(lyrics) {
                if (lyrics) {
                    _audioLyrics = parseLrc(lyrics);
                    renderLyrics(_audioLyrics);
                }
            });
        }
    }).catch(function() {});
}

function loadEmbeddedLyrics(fileId) {
    return api('audio_embedded_lyric', { file_id: fileId }, 'GET').then(function(data) {
        if (data && data.success && data.embedded && data.content) {
            return data.content;
        }
        return '';
    }).catch(function() { return ''; });
}

function loadAudioLyrics(lyricFiles) {
    var current = _audioMeta ? _audioMeta.current : null;
    if (!current) return;
    var best = findBestLyricFile(current.filename, lyricFiles || []);
    if (!best) {
        renderLyrics([]);
        return;
    }
    api('audio_lyric', { file_id: best.id }, 'GET').then(function(data) {
        if (data && data.success && data.content) {
            _audioLyrics = parseLrc(data.content);
            renderLyrics(_audioLyrics);
        } else {
            renderLyrics([]);
        }
    }).catch(function() { renderLyrics([]); });
}

function switchAudioTrack(index) {
    if (!_audioPlaylist || _audioPlaylist.length === 0) return;
    if (index < 0 || index >= _audioPlaylist.length) return;
    _audioPlaylistIndex = index;
    var item = _audioPlaylist[index];
    previewFile(item.id);
}

function playNextAudio() {
    if (_audioPlaylist.length === 0) return;
    var nextIndex = _audioPlaylistIndex + 1;
    if (nextIndex >= _audioPlaylist.length) nextIndex = 0;
    switchAudioTrack(nextIndex);
}

function playPrevAudio() {
    if (_audioPlaylist.length === 0) return;
    var prevIndex = _audioPlaylistIndex - 1;
    if (prevIndex < 0) prevIndex = _audioPlaylist.length - 1;
    switchAudioTrack(prevIndex);
}

function renderAudioPlayer(file, previewUrl, fileId) {
    PreviewShell.setType('audio');
    resetAudioState();

    var coverUrl = file.thumbnail_url ? (file.thumbnail_url + '&size=large') : '';

    PreviewShell.setContent(
        '<div class="pv-audio-modern" id="audioModernPlayer">' +
            '<div class="pv-audio-bg-blur" id="audioBgBlur" style="background-image:url(' + (coverUrl ? escapeAttr(coverUrl) : '') + ')"></div>' +
            '<div class="pv-audio-main">' +
                '<div class="pv-audio-left">' +
                    '<div class="pv-audio-disc-wrap">' +
                        '<div class="pv-audio-disc" id="audioDisc">' +
                            (coverUrl ? '<img src="' + escapeAttr(coverUrl) + '" alt="" id="audioCoverImg">' : '<div class="pv-audio-disc-placeholder"><i class="fas fa-music"></i></div>') +
                        '</div>' +
                        '<div class="pv-audio-disc-glow"></div>' +
                    '</div>' +
                    '<div class="pv-audio-meta">' +
                        '<div class="pv-audio-title" id="audioTitle" title="' + escapeAttr(file.filename) + '">' + escapeHtml(file.filename) + '</div>' +
                        '<div class="pv-audio-artist" id="audioArtist">未知艺术家</div>' +
                        '<div class="pv-audio-album" id="audioAlbum"></div>' +
                    '</div>' +
                '</div>' +
                '<div class="pv-audio-right">' +
                    '<div class="pv-audio-lyrics" id="audioLyrics">' +
                        '<div class="pv-audio-lyrics-empty">暂无歌词</div>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div class="pv-audio-controls-modern">' +
                '<div class="pv-audio-progress-modern">' +
                    '<span class="pv-audio-time-current" id="audioCurrentTime">0:00</span>' +
                    '<div class="pv-progress-bar" id="audioProgressBar">' +
                        '<div class="pv-progress-fill" id="audioProgressFill" style="width:0%"></div>' +
                        '<div class="pv-progress-handle" id="audioProgressHandle" style="left:0%"></div>' +
                    '</div>' +
                    '<span class="pv-audio-time-duration" id="audioDuration">0:00</span>' +
                '</div>' +
                '<div class="pv-audio-buttons">' +
                    '<button class="pv-audio-prev-btn" id="audioPrevBtn" title="上一首"><i class="fas fa-step-backward"></i></button>' +
                    '<button class="pv-audio-play-btn" id="audioPlayBtn" title="播放/暂停 (空格)"><i class="fas fa-play"></i></button>' +
                    '<button class="pv-audio-next-btn" id="audioNextBtn" title="下一首"><i class="fas fa-step-forward"></i></button>' +
                    '<button class="pv-audio-list-btn" id="audioListBtn" title="播放列表"><i class="fas fa-list"></i></button>' +
                '</div>' +
            '</div>' +
            '<div class="pv-audio-playlist" id="audioPlaylist" style="display:none">' +
                '<div class="pv-audio-playlist-header">播放列表</div>' +
                '<div class="pv-audio-playlist-body" id="audioPlaylistBody"></div>' +
            '</div>' +
        '</div>' +
        '<audio id="previewAudio" preload="metadata" style="display:none"><source src="' + previewUrl + '" type="' + (file.mime_type || 'audio/mpeg') + '"></audio>',
        'audio'
    );

    PreviewShell.setFooter(
        '<div class="pv-image-toolbar">' +
            buildIconButton('audioMuteBtn', 'fa-volume-up', '静音 (M)') +
            '<div class="pv-volume-slider" id="audioVolumeSlider"><div class="pv-volume-fill" id="audioVolumeFill" style="width:100%"></div></div>' +
            '<span class="pv-toolbar-divider"></span>' +
            '<button class="pv-tool-btn" id="audioLyricSizeBtn" title="歌词字号"><i class="fas fa-font"></i></button>' +
            '<span class="pv-toolbar-divider"></span>' +
            '<button class="pv-tool-btn" id="audioSpeedBtn" title="播放速度">1x</button>' +
        '</div>'
    );

    loadAudioMeta(fileId, previewUrl);

    setTimeout(function () {
        var audio = document.getElementById('previewAudio');
        if (!audio) return;
        previewState.audio = audio;

        readAudioMetadata(previewUrl, function(tags) {
            updateAudioMetaUI(tags, file);
            updateAudioCoverFromTags(tags);
            // 后端已返回内置歌词时，不再用 jsmediatags 覆盖
            if (_audioLyrics && _audioLyrics.length > 0) return;
            var embeddedLyrics = extractEmbeddedLyrics(tags);
            if (embeddedLyrics) {
                _audioLyrics = parseLrc(embeddedLyrics);
                renderLyrics(_audioLyrics);
            } else if (_audioMeta && _audioMeta.lyric_files && _audioMeta.lyric_files.length > 0) {
                loadAudioLyrics(_audioMeta.lyric_files);
            } else if (!_audioLyrics || _audioLyrics.length === 0) {
                renderLyrics([]);
            }
        });

        var playBtn = document.getElementById('audioPlayBtn');
        var disc = document.getElementById('audioDisc');
        var fill = document.getElementById('audioProgressFill');
        var handle = document.getElementById('audioProgressHandle');
        var bar = document.getElementById('audioProgressBar');
        var currentEl = document.getElementById('audioCurrentTime');
        var durationEl = document.getElementById('audioDuration');
        var muteBtn = document.getElementById('audioMuteBtn');
        var volumeSlider = document.getElementById('audioVolumeSlider');
        var volumeFill = document.getElementById('audioVolumeFill');
        var speedBtn = document.getElementById('audioSpeedBtn');
        var prevBtn = document.getElementById('audioPrevBtn');
        var nextBtn = document.getElementById('audioNextBtn');
        var listBtn = document.getElementById('audioListBtn');
        var playlist = document.getElementById('audioPlaylist');
        var lyricSizeBtn = document.getElementById('audioLyricSizeBtn');

        var speeds = [0.5, 0.75, 1, 1.25, 1.5, 2];
        var speedIndex = 2;
        var isDraggingBar = false;

        function updatePlayBtn() {
            playBtn.innerHTML = '<i class="fas ' + (audio.paused ? 'fa-play' : 'fa-pause') + '"></i>';
            if (disc) disc.classList.toggle('playing', !audio.paused);
        }
        function updateProgress() {
            if (!audio.duration || isDraggingBar) return;
            var pct = audio.currentTime / audio.duration * 100;
            fill.style.width = pct + '%';
            handle.style.left = pct + '%';
            currentEl.textContent = formatTime(audio.currentTime);
            updateActiveLyric(audio.currentTime);
        }
        function seekToRatio(ratio) {
            if (audio.duration) audio.currentTime = Math.max(0, Math.min(1, ratio)) * audio.duration;
        }
        function updateVolumeUI() {
            var pct = audio.muted ? 0 : audio.volume * 100;
            volumeFill.style.width = pct + '%';
            var icon = audio.muted || audio.volume === 0 ? 'fa-volume-mute' : (audio.volume < 0.5 ? 'fa-volume-down' : 'fa-volume-up');
            muteBtn.innerHTML = '<i class="fas ' + icon + '"></i>';
        }

        audio.addEventListener('loadedmetadata', function () { durationEl.textContent = formatTime(audio.duration); });
        audio.addEventListener('timeupdate', updateProgress);
        audio.addEventListener('play', updatePlayBtn);
        audio.addEventListener('pause', updatePlayBtn);
        audio.addEventListener('ended', function () {
            updatePlayBtn();
            audio.currentTime = 0;
            updateProgress();
        });

        playBtn.onclick = function () {
            if (audio.paused) audio.play().catch(function(){});
            else audio.pause();
        };
        prevBtn.onclick = playPrevAudio;
        nextBtn.onclick = playNextAudio;

        listBtn.onclick = function () {
            playlist.style.display = playlist.style.display === 'none' ? 'block' : 'none';
        };
        playlist.onclick = function (e) {
            var item = e.target.closest('.pv-audio-playlist-item');
            if (item) switchAudioTrack(parseInt(item.dataset.index, 10));
        };

        bar.addEventListener('click', function (e) {
            var rect = bar.getBoundingClientRect();
            seekToRatio((e.clientX - rect.left) / rect.width);
        });
        bar.addEventListener('mousedown', function (e) {
            if (e.button !== 0) return;
            isDraggingBar = true;
            var rect = bar.getBoundingClientRect();
            var ratio = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
            fill.style.width = (ratio * 100) + '%';
            handle.style.left = (ratio * 100) + '%';
            currentEl.textContent = formatTime(ratio * (audio.duration || 0));
            e.preventDefault();
        });
        function onMove(e) {
            if (!isDraggingBar) return;
            var rect = bar.getBoundingClientRect();
            var ratio = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
            fill.style.width = (ratio * 100) + '%';
            handle.style.left = (ratio * 100) + '%';
            currentEl.textContent = formatTime(ratio * (audio.duration || 0));
        }
        function onUp(e) {
            if (!isDraggingBar) return;
            isDraggingBar = false;
            var rect = bar.getBoundingClientRect();
            seekToRatio((e.clientX - rect.left) / rect.width);
        }
        PreviewShell._addDocListener('mousemove', onMove);
        PreviewShell._addDocListener('mouseup', onUp);

        muteBtn.onclick = function () { audio.muted = !audio.muted; updateVolumeUI(); };
        var isDraggingVol = false;
        function setVolFromX(clientX) {
            var rect = volumeSlider.getBoundingClientRect();
            audio.volume = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
            audio.muted = false;
            updateVolumeUI();
        }
        volumeSlider.addEventListener('click', function (e) { setVolFromX(e.clientX); });
        volumeSlider.addEventListener('mousedown', function (e) { isDraggingVol = true; setVolFromX(e.clientX); e.preventDefault(); });
        function onVolMove(e) { if (isDraggingVol) setVolFromX(e.clientX); }
        function onVolUp() { isDraggingVol = false; }
        PreviewShell._addDocListener('mousemove', onVolMove);
        PreviewShell._addDocListener('mouseup', onVolUp);

        speedBtn.onclick = function () {
            speedIndex = (speedIndex + 1) % speeds.length;
            audio.playbackRate = speeds[speedIndex];
            speedBtn.textContent = speeds[speedIndex] + 'x';
        };

        lyricSizeBtn.onclick = function () {
            var container = document.getElementById('audioLyrics');
            if (!container) return;
            var sizes = [14, 16, 18, 20, 22];
            var current = parseInt(container.style.fontSize, 10) || 16;
            var idx = sizes.indexOf(current);
            var next = sizes[(idx + 1) % sizes.length];
            container.style.fontSize = next + 'px';
        };

        audio.load();
        updateVolumeUI();

        // 防止点击播放器内部元素时冒泡到预览遮罩导致关闭
        var playerEl = document.getElementById('audioModernPlayer');
        var footerEl = document.querySelector('.preview-footer .pv-image-toolbar');
        function stopProp(e) { e.stopPropagation(); }
        if (playerEl) playerEl.addEventListener('click', stopProp);
        if (footerEl) footerEl.addEventListener('click', stopProp);
    }, 50);
}

function onAudioKeyDown(e) {
    var audio = previewState.audio;
    if (!audio) return;
    switch (e.key) {
        case ' ':
            e.preventDefault();
            if (audio.paused) audio.play().catch(function(){});
            else audio.pause();
            break;
        case 'ArrowLeft': audio.currentTime = Math.max(0, audio.currentTime - 5); break;
        case 'ArrowRight': audio.currentTime = Math.min(audio.duration || 0, audio.currentTime + 5); break;
        case 'ArrowUp':
            e.preventDefault();
            audio.volume = Math.min(1, audio.volume + 0.1);
            updateAudioVolumeUI(audio);
            break;
        case 'ArrowDown':
            e.preventDefault();
            audio.volume = Math.max(0, audio.volume - 0.1);
            updateAudioVolumeUI(audio);
            break;
        case 'm': case 'M':
            var btn = document.getElementById('audioMuteBtn');
            if (btn) btn.click();
            break;
    }
}

// ===== 视频播放器 =====

function renderVideoPlayer(file, previewUrl, fileId) {
    PreviewShell.setType('video');
    PreviewShell.setContent(
        '<div class="pv-video-wrap">' +
            '<video id="previewVideo" preload="metadata" controlsList="nodownload">' +
                '<source src="' + previewUrl + '" type="' + (file.mime_type || 'video/mp4') + '">' +
            '</video>' +
            '<div class="pv-video-overlay" id="videoOverlay">' +
                '<button class="pv-big-play" id="videoBigPlayBtn"><i class="fas fa-play"></i></button>' +
            '</div>' +
            '<div class="pv-video-controls" id="videoControls">' +
                '<div class="pv-video-progress-area">' +
                    '<div class="pv-progress-bar" id="videoProgressBar">' +
                        '<div class="pv-progress-fill" id="videoProgressFill" style="width:0%"></div>' +
                        '<div class="pv-progress-handle" id="videoProgressHandle" style="left:0%"></div>' +
                    '</div>' +
                    '<div class="pv-time-tooltip" id="videoTimeTooltip">0:00</div>' +
                '</div>' +
                '<div class="pv-video-controls-row">' +
                    '<div class="pv-video-controls-left">' +
                        '<button class="pv-tool-btn" id="videoPlayBtn" title="播放/暂停 (空格)"><i class="fas fa-play"></i></button>' +
                        '<button class="pv-tool-btn" id="videoRewindBtn" title="后退 5s (←)"><i class="fas fa-backward"></i></button>' +
                        '<button class="pv-tool-btn" id="videoForwardBtn" title="前进 5s (→)"><i class="fas fa-forward"></i></button>' +
                        '<div class="pv-volume-wrap">' +
                            '<button class="pv-tool-btn" id="videoMuteBtn" title="静音 (M)"><i class="fas fa-volume-up"></i></button>' +
                            '<div class="pv-volume-slider" id="videoVolumeSlider"><div class="pv-volume-fill" id="videoVolumeFill" style="width:100%"></div></div>' +
                        '</div>' +
                        '<span class="pv-time-display" id="videoTime">0:00 / 0:00</span>' +
                    '</div>' +
                    '<div class="pv-video-controls-right">' +
                        '<button class="pv-tool-btn" id="videoSpeedBtn" title="播放速度">1x</button>' +
                        '<button class="pv-tool-btn" id="videoFullscreenBtn" title="全屏 (F)"><i class="fas fa-expand"></i></button>' +
                    '</div>' +
                '</div>' +
            '</div>' +
        '</div>',
        'video'
    );

    PreviewShell.setFooter('');

    setTimeout(function () {
        var video = document.getElementById('previewVideo');
        if (!video) return;
        previewState.video = video;

        var playBtn = document.getElementById('videoPlayBtn');
        var bigPlayBtn = document.getElementById('videoBigPlayBtn');
        var overlay = document.getElementById('videoOverlay');
        var progressBar = document.getElementById('videoProgressBar');
        var progressFill = document.getElementById('videoProgressFill');
        var progressHandle = document.getElementById('videoProgressHandle');
        var timeTooltip = document.getElementById('videoTimeTooltip');
        var timeEl = document.getElementById('videoTime');
        var muteBtn = document.getElementById('videoMuteBtn');
        var volumeSlider = document.getElementById('videoVolumeSlider');
        var volumeFill = document.getElementById('videoVolumeFill');
        var speedBtn = document.getElementById('videoSpeedBtn');
        var fullscreenBtn = document.getElementById('videoFullscreenBtn');
        var controls = document.getElementById('videoControls');
        var wrap = video.closest('.pv-video-wrap');

        var speeds = [0.5, 0.75, 1, 1.25, 1.5, 2];
        var speedIndex = 2;
        var isDragging = false;
        var hideTimer = null;

        function togglePlay() {
            if (video.paused) video.play().catch(function(){});
            else video.pause();
        }
        function updatePlayState() {
            var icon = video.paused ? 'fa-play' : 'fa-pause';
            playBtn.innerHTML = '<i class="fas ' + icon + '"></i>';
            bigPlayBtn.innerHTML = '<i class="fas ' + icon + '"></i>';
            overlay.style.opacity = video.paused ? '1' : '0';
            overlay.style.pointerEvents = video.paused ? 'auto' : 'none';
        }
        function updateProgress() {
            if (!video.duration) return;
            var pct = video.currentTime / video.duration * 100;
            progressFill.style.width = pct + '%';
            progressHandle.style.left = pct + '%';
            timeEl.textContent = formatTime(video.currentTime) + ' / ' + formatTime(video.duration);
        }
        function seekToRatio(ratio) {
            if (video.duration) video.currentTime = Math.max(0, Math.min(1, ratio)) * video.duration;
        }
        function updateVolumeUI() {
            var pct = video.muted ? 0 : video.volume * 100;
            volumeFill.style.width = pct + '%';
            var icon = video.muted || video.volume === 0 ? 'fa-volume-mute' : (video.volume < 0.5 ? 'fa-volume-down' : 'fa-volume-up');
            muteBtn.innerHTML = '<i class="fas ' + icon + '"></i>';
        }
        function showControls() {
            controls.classList.add('active');
            clearTimeout(hideTimer);
            if (!video.paused) {
                hideTimer = setTimeout(function () { controls.classList.remove('active'); }, 3000);
            }
        }

        playBtn.onclick = togglePlay;
        bigPlayBtn.onclick = togglePlay;
        video.onclick = togglePlay;

        video.addEventListener('play', updatePlayState);
        video.addEventListener('pause', updatePlayState);
        video.addEventListener('timeupdate', updateProgress);
        video.addEventListener('loadedmetadata', function () {
            timeEl.textContent = '0:00 / ' + formatTime(video.duration);
        });
        video.addEventListener('ended', function () { updatePlayState(); });

        document.getElementById('videoRewindBtn').onclick = function () {
            video.currentTime = Math.max(0, video.currentTime - 5);
        };
        document.getElementById('videoForwardBtn').onclick = function () {
            video.currentTime = Math.min(video.duration || 0, video.currentTime + 5);
        };

        progressBar.addEventListener('click', function (e) {
            var rect = progressBar.getBoundingClientRect();
            seekToRatio((e.clientX - rect.left) / rect.width);
        });
        progressBar.addEventListener('mousemove', function (e) {
            if (isDragging) return; // 拖拽中由 onMove 处理
            var rect = progressBar.getBoundingClientRect();
            var ratio = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
            // 限制 tooltip 在 5%~95% 之间，避免边缘溢出
            var tooltipPos = Math.max(5, Math.min(95, ratio * 100));
            timeTooltip.style.left = tooltipPos + '%';
            timeTooltip.textContent = formatTime(ratio * (video.duration || 0));
            timeTooltip.style.opacity = '1';
        });
        progressBar.addEventListener('mouseleave', function () { if (!isDragging) timeTooltip.style.opacity = '0'; });

        // handle 设置了 pointer-events:none，mousedown 必须绑定在 progressBar 上
        progressBar.addEventListener('mousedown', function (e) {
            if (e.button !== 0) return;
            isDragging = true;
            var rect = progressBar.getBoundingClientRect();
            var ratio = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
            progressFill.style.width = (ratio * 100) + '%';
            progressHandle.style.left = (ratio * 100) + '%';
            var tooltipPos = Math.max(5, Math.min(95, ratio * 100));
            timeTooltip.style.left = tooltipPos + '%';
            timeTooltip.textContent = formatTime(ratio * (video.duration || 0));
            timeTooltip.style.opacity = '1';
            e.preventDefault();
        });
        function onMove(e) {
            if (!isDragging) return;
            var rect = progressBar.getBoundingClientRect();
            var ratio = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
            progressFill.style.width = (ratio * 100) + '%';
            progressHandle.style.left = (ratio * 100) + '%';
            var tooltipPos = Math.max(5, Math.min(95, ratio * 100));
            timeTooltip.style.left = tooltipPos + '%';
            timeTooltip.textContent = formatTime(ratio * (video.duration || 0));
            timeTooltip.style.opacity = '1';
        }
        function onUp(e) {
            if (!isDragging) return;
            isDragging = false;
            var rect = progressBar.getBoundingClientRect();
            seekToRatio((e.clientX - rect.left) / rect.width);
            timeTooltip.style.opacity = '0';
        }
        PreviewShell._addDocListener('mousemove', onMove);
        PreviewShell._addDocListener('mouseup', onUp);

        muteBtn.onclick = function () { video.muted = !video.muted; updateVolumeUI(); };
        var isDraggingVol = false;
        function setVolFromX(clientX) {
            var rect = volumeSlider.getBoundingClientRect();
            video.volume = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
            video.muted = false;
            updateVolumeUI();
        }
        volumeSlider.addEventListener('click', function (e) { setVolFromX(e.clientX); });
        volumeSlider.addEventListener('mousedown', function (e) { isDraggingVol = true; setVolFromX(e.clientX); e.preventDefault(); });
        function onVolMove(e) { if (isDraggingVol) setVolFromX(e.clientX); }
        function onVolUp() { isDraggingVol = false; }
        PreviewShell._addDocListener('mousemove', onVolMove);
        PreviewShell._addDocListener('mouseup', onVolUp);

        speedBtn.onclick = function () {
            speedIndex = (speedIndex + 1) % speeds.length;
            video.playbackRate = speeds[speedIndex];
            speedBtn.textContent = speeds[speedIndex] + 'x';
        };
        fullscreenBtn.onclick = function () {
            if (!document.fullscreenElement) {
                PreviewShell.overlay.requestFullscreen().catch(function(){});
            } else document.exitFullscreen();
        };

        wrap.addEventListener('mousemove', showControls);
        wrap.addEventListener('mouseleave', function () {
            if (!video.paused) controls.classList.remove('active');
        });
        // 控制栏交互时重置隐藏计时器
        controls.addEventListener('mouseenter', showControls);
        controls.addEventListener('mousemove', showControls);

        video.load();
        updateVolumeUI();
        showControls(); // 初始显示控制栏
    }, 50);
}

function onVideoKeyDown(e) {
    var video = previewState.video;
    if (!video) return;
    switch (e.key) {
        case ' ':
            e.preventDefault();
            if (video.paused) video.play().catch(function(){});
            else video.pause();
            break;
        case 'ArrowLeft': video.currentTime = Math.max(0, video.currentTime - 5); break;
        case 'ArrowRight': video.currentTime = Math.min(video.duration || 0, video.currentTime + 5); break;
        case 'ArrowUp':
            e.preventDefault();
            video.volume = Math.min(1, video.volume + 0.1);
            updateVideoVolumeUI(video);
            break;
        case 'ArrowDown':
            e.preventDefault();
            video.volume = Math.max(0, video.volume - 0.1);
            updateVideoVolumeUI(video);
            break;
        case 'f': case 'F':
            var btn = document.getElementById('videoFullscreenBtn');
            if (btn) btn.click();
            break;
        case 'm': case 'M':
            var mb = document.getElementById('videoMuteBtn');
            if (mb) mb.click();
            break;
    }
}

// ===== 图片查看器 =====

function renderImageViewer(file, previewUrl, fileId) {
    PreviewShell.setType('image');
    // 直接使用后端返回的缩略图 URL（含版本 hash），避免前端自己构造导致缓存失控
    var thumbUrl = file.thumbnail_url || '';
    var hasThumb = !!thumbUrl;

    PreviewShell.setContent(
        '<div class="pv-image-wrap" id="imageWrap">' +
            '<div class="pv-image-container" id="imageContainer">' +
                '<img src="" alt="" id="previewImage" draggable="false">' +
            '</div>' +
            '<div class="pv-image-loading" id="imageLoading" style="display:none"><div class="preview-loading-ring"></div></div>' +
        '</div>',
        'image'
    );

    PreviewShell.setFooter(
        '<div class="pv-image-toolbar">' +
            buildToolbarButton('imgZoomOut', 'fa-minus', '缩小 (-)') +
            '<span class="pv-image-zoom-text" id="imgZoomText">100%</span>' +
            buildToolbarButton('imgZoomIn', 'fa-plus', '放大 (+)') +
            '<span class="pv-toolbar-divider"></span>' +
            buildToolbarButton('imgReset', 'fa-compress-arrows-alt', '适应屏幕 (0)') +
            buildToolbarButton('imgActualSize', 'fa-expand-arrows-alt', '实际大小 (1)') +
            '<span class="pv-toolbar-divider"></span>' +
            buildToolbarButton('imgRotateLeft', 'fa-undo', '向左旋转 (L)') +
            buildToolbarButton('imgRotateRight', 'fa-redo', '向右旋转 (R)') +
            '<span class="pv-toolbar-divider"></span>' +
            buildToolbarButton('imgFullscreen', 'fa-expand', '全屏 (F)') +
        '</div>'
    );

    setTimeout(function () {
        var wrap = document.getElementById('imageWrap');
        var container = document.getElementById('imageContainer');
        var img = document.getElementById('previewImage');
        var loading = document.getElementById('imageLoading');
        if (!img) return;

        var state = {
            scale: 1, rotate: 0, x: 0, y: 0,
            isDragging: false, lastX: 0, lastY: 0,
            naturalW: 0, naturalH: 0, loaded: false
        };

        function applyTransform() {
            img.style.transform = 'translate(' + state.x + 'px, ' + state.y + 'px) scale(' + state.scale + ') rotate(' + state.rotate + 'deg)';
            img.style.opacity = '1'; // 显示图片（初始 opacity:0 防止未缩放时闪烁）
            var zoomText = document.getElementById('imgZoomText');
            if (zoomText) zoomText.textContent = Math.round(state.scale * 100) + '%';
        }
        function fitToScreen() {
            if (!state.loaded || !state.naturalW || !state.naturalH) return;
            var wrapW = wrap.clientWidth || 0;
            var wrapH = wrap.clientHeight || 0;
            if (!wrapW || !wrapH) { state.scale = 1; }
            else {
                // 旋转 90/270 度时宽高互换
                var isRotated = state.rotate === 90 || state.rotate === 270;
                var imgW = isRotated ? state.naturalH : state.naturalW;
                var imgH = isRotated ? state.naturalW : state.naturalH;
                // 适应屏幕：图片缩放到容器内，不超过原始尺寸（ratio <= 1）
                var ratio = Math.min(wrapW / imgW, wrapH / imgH, 1);
                state.scale = isFinite(ratio) && !isNaN(ratio) && ratio > 0 ? ratio : 1;
            }
            state.x = 0;
            state.y = 0;
            applyTransform();
        }
        function actualSize() {
            // 实际大小：1:1 像素显示
            state.scale = 1;
            state.x = 0;
            state.y = 0;
            applyTransform();
        }
        function zoom(delta) {
            state.scale = Math.max(0.1, Math.min(10, state.scale + delta));
            clampPosition();
            applyTransform();
        }
        function rotate(deg) {
            state.rotate = (state.rotate + deg) % 360;
            // 旋转后重新适应屏幕
            fitToScreen();
        }

        // 加载上下文：用于在切换/关闭预览时取消进行中的图片加载，避免旧回调污染新预览
        var loadCtx = { cancelled: false, fullImg: null };
        function cleanupImageCtx() {
            loadCtx.cancelled = true;
            if (loadCtx.fullImg) {
                loadCtx.fullImg.onload = null;
                loadCtx.fullImg.onerror = null;
                loadCtx.fullImg.src = '';
                loadCtx.fullImg = null;
            }
            if (img) {
                img.onload = null;
                img.onerror = null;
            }
        }
        previewState.imageLoads.push({ ctx: loadCtx, cleanup: cleanupImageCtx });

        // 图片加载完成后的统一入口（防止重复触发导致闪烁）
        var readyCalled = false;
        function onImageReady(naturalW, naturalH) {
            if (readyCalled || loadCtx.cancelled) return;
            readyCalled = true;
            state.naturalW = naturalW;
            state.naturalH = naturalH;
            state.loaded = true;
            if (loading) loading.style.display = 'none';
            fitToScreen();
        }

        // 统一图片加载策略：
        // 1. 有缩略图时先展示缩略图，给用户即时反馈；
        // 2. 同时后台始终预加载原图（只要后端允许预览的大小限制内）；
        // 3. 原图就绪后切换到原图并按真实尺寸自适应；
        // 4. 原图加载失败则回退到缩略图（并把 src 切回缩略图，避免显示裂图）。
        var fullLoaded = false, fullFailed = false;
        var fullW = 0, fullH = 0;
        var thumbLoaded = false;
        var thumbW = 0, thumbH = 0;

        function showError(msg) {
            if (loadCtx.cancelled) return;
            if (loading) loading.style.display = 'none';
            PreviewShell.showError(msg || '图片加载失败');
        }

        function onThumbVisible() {
            if (loadCtx.cancelled || state.loaded) return;
            if (!state.loaded) img.style.opacity = '1';
            thumbW = img.naturalWidth || thumbW;
            thumbH = img.naturalHeight || thumbH;
            thumbLoaded = true;
        }

        function fallbackToThumb() {
            if (loadCtx.cancelled) return;
            if (hasThumb && thumbLoaded) {
                // 确保当前显示的是缩略图，而不是原图加载失败后的裂图/空图
                if (img) img.src = thumbUrl;
                onImageReady(thumbW, thumbH);
            } else {
                showError('图片加载失败');
            }
        }

        function switchToFull(naturalW, naturalH) {
            if (loadCtx.cancelled || state.loaded) return;
            if (loading) loading.style.display = 'flex';
            img.onload = function () {
                if (loadCtx.cancelled) return;
                onImageReady(naturalW, naturalH);
            };
            img.onerror = function () {
                if (loadCtx.cancelled) return;
                // 原图显示失败时回退到缩略图
                fallbackToThumb();
            };
            img.src = previewUrl;
            // 缓存命中时 onload 可能同步触发，同步兜底一次
            if (!readyCalled && img.complete && img.naturalWidth) {
                onImageReady(naturalW, naturalH);
            }
        }

        // 有缩略图时：先展示缩略图，同时用独立 Image 对象预加载原图
        if (hasThumb) {
            if (loading) loading.style.display = 'flex';
            img.src = thumbUrl;
            img.onload = function () {
                if (loadCtx.cancelled) return;
                onThumbVisible();
            };
            img.onerror = function () {
                if (loadCtx.cancelled) return;
                if (fullLoaded) switchToFull(fullW, fullH);
                else if (fullFailed) showError('图片加载失败');
                // 否则等待 fullImg 结果
            };
            // 缓存命中时可能已加载完成，同步兜底
            if (img.complete && img.naturalWidth && !state.loaded) {
                onThumbVisible();
            }

            // 预加载原图
            loadCtx.fullImg = new Image();
            loadCtx.fullImg.onload = function () {
                if (loadCtx.cancelled) return;
                fullLoaded = true;
                fullW = loadCtx.fullImg.naturalWidth;
                fullH = loadCtx.fullImg.naturalHeight;
                switchToFull(fullW, fullH);
            };
            loadCtx.fullImg.onerror = function () {
                if (loadCtx.cancelled) return;
                fullFailed = true;
                if (state.loaded) return;
                fallbackToThumb();
            };
            loadCtx.fullImg.src = previewUrl;
        } else {
            // 没有缩略图：直接在主 img 中加载原图
            if (loading) loading.style.display = 'flex';
            img.src = previewUrl;
            img.onload = function () {
                if (loadCtx.cancelled) return;
                onImageReady(img.naturalWidth, img.naturalHeight);
            };
            img.onerror = function () {
                if (loadCtx.cancelled) return;
                showError('图片加载失败');
            };
            if (img.complete && img.naturalWidth && !state.loaded) {
                onImageReady(img.naturalWidth, img.naturalHeight);
            }
        }

        document.getElementById('imgZoomOut').onclick = function () { zoom(-0.25); };
        document.getElementById('imgZoomIn').onclick = function () { zoom(0.25); };
        document.getElementById('imgReset').onclick = fitToScreen;
        document.getElementById('imgActualSize').onclick = actualSize;
        document.getElementById('imgRotateLeft').onclick = function () { rotate(-90); };
        document.getElementById('imgRotateRight').onclick = function () { rotate(90); };
        document.getElementById('imgFullscreen').onclick = function () {
            if (!document.fullscreenElement) PreviewShell.overlay.requestFullscreen().catch(function(){});
            else document.exitFullscreen();
        };

        container.addEventListener('wheel', function (e) {
            e.preventDefault();
            zoom(e.deltaY > 0 ? -0.15 : 0.15);
        }, { passive: false });

        container.addEventListener('mousedown', function (e) {
            if (e.button !== 0) return;
            state.isDragging = true;
            state.lastX = e.clientX;
            state.lastY = e.clientY;
            container.style.cursor = 'grabbing';
            e.preventDefault();
        });
        function clampPosition() {
            if (!state.loaded || !state.naturalW || !state.naturalH) return;
            var isRotated = state.rotate === 90 || state.rotate === 270;
            var dispW = (isRotated ? state.naturalH : state.naturalW) * state.scale;
            var dispH = (isRotated ? state.naturalW : state.naturalH) * state.scale;
            var wrapW = wrap.clientWidth || 0;
            var wrapH = wrap.clientHeight || 0;
            if (dispW <= wrapW) state.x = 0;
            else state.x = Math.max(-(dispW - wrapW) / 2, Math.min((dispW - wrapW) / 2, state.x));
            if (dispH <= wrapH) state.y = 0;
            else state.y = Math.max(-(dispH - wrapH) / 2, Math.min((dispH - wrapH) / 2, state.y));
        }
        function onMove(e) {
            if (!state.isDragging) return;
            state.x += e.clientX - state.lastX;
            state.y += e.clientY - state.lastY;
            state.lastX = e.clientX;
            state.lastY = e.clientY;
            clampPosition();
            applyTransform();
        }
        function onUp() {
            if (state.isDragging) {
                state.isDragging = false;
                container.style.cursor = 'grab';
            }
        }
        PreviewShell._addDocListener('mousemove', onMove);
        PreviewShell._addDocListener('mouseup', onUp);
        container.style.cursor = 'grab';

        container.addEventListener('dblclick', function () {
            // 当前缩小状态（适应屏幕）→ 实际大小；否则 → 适应屏幕
            if (state.scale < 0.99) actualSize();
            else fitToScreen();
        });

        // 窗口尺寸变化时重新适应
        function onResize() { fitToScreen(); }
        PreviewShell._addDocListener('fullscreenchange', onResize);
    }, 50);
}

function onImageKeyDown(e) {
    var btn;
    switch (e.key) {
        case '+': case '=': btn = document.getElementById('imgZoomIn'); break;
        case '-': case '_': btn = document.getElementById('imgZoomOut'); break;
        case '0': btn = document.getElementById('imgReset'); break;
        case '1': btn = document.getElementById('imgActualSize'); break;
        case 'r': case 'R': btn = document.getElementById('imgRotateRight'); break;
        case 'l': case 'L': btn = document.getElementById('imgRotateLeft'); break;
        case 'f': case 'F': btn = document.getElementById('imgFullscreen'); break;
        case 'ArrowLeft': PreviewShell.prev(); return;
        case 'ArrowRight': PreviewShell.next(); return;
    }
    if (btn) btn.click();
}

// ===== 全局辅助 =====

function updateAudioVolumeUI(audio) {
    var fill = document.getElementById('audioVolumeFill');
    var muteBtn = document.getElementById('audioMuteBtn');
    if (fill) fill.style.width = (audio.muted ? 0 : audio.volume * 100) + '%';
    if (muteBtn) {
        var icon = audio.muted || audio.volume === 0 ? 'fa-volume-mute' : (audio.volume < 0.5 ? 'fa-volume-down' : 'fa-volume-up');
        muteBtn.innerHTML = '<i class="fas ' + icon + '"></i>';
    }
}

function updateVideoVolumeUI(video) {
    var fill = document.getElementById('videoVolumeFill');
    var muteBtn = document.getElementById('videoMuteBtn');
    if (fill) fill.style.width = (video.muted ? 0 : video.volume * 100) + '%';
    if (muteBtn) {
        var icon = video.muted || video.volume === 0 ? 'fa-volume-mute' : (video.volume < 0.5 ? 'fa-volume-down' : 'fa-volume-up');
        muteBtn.innerHTML = '<i class="fas ' + icon + '"></i>';
    }
}

function formatTime(seconds) {
    if (!seconds || isNaN(seconds)) return '0:00';
    var m = Math.floor(seconds / 60);
    var s = Math.floor(seconds % 60);
    if (m >= 60) {
        var h = Math.floor(m / 60);
        m = m % 60;
        return h + ':' + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }
    return m + ':' + (s < 10 ? '0' : '') + s;
}
