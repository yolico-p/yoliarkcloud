/**
 * 移动端预览 UI 独立适配层
 *
 * 设计原则：
 *  - 不修改原 preview.js / preview.css，仅在外部 Hook 增强移动端体验
 *  - 仅在触摸设备或窄屏 (<=768px) 启用
 *  - 注入独立的底部操作栏（大触摸区按钮），替代原鼠标悬停的灵动岛箭头
 *  - 支持左右滑动手势切换文件、双击图片放大、双指缩放
 *  - Hook PreviewShell.open/close/setContent/updateNavButtons，同步状态
 */
(function () {
    'use strict';

    function isMobileEnv() {
        var touch = (typeof isTouchDevice !== 'undefined' && isTouchDevice) ||
                    ('ontouchstart' in window) ||
                    (navigator.maxTouchPoints > 0);
        var narrow = window.innerWidth <= 768;
        var coarse = window.matchMedia && window.matchMedia('(hover: none) and (pointer: coarse)').matches;
        return touch || narrow || coarse;
    }

    if (!isMobileEnv()) return;

    // 标记移动端模式，触发 preview-mobile.css 中的样式覆盖
    document.body.classList.add('pv-mobile-mode');

    var MOBILE_BAR_HTML =
        '<button class="pv-mobile-bar-btn pv-mobile-prev" title="上一个"><i class="fas fa-chevron-left"></i></button>' +
        '<button class="pv-mobile-bar-btn pv-mobile-download" title="下载"><i class="fas fa-download"></i></button>' +
        '<button class="pv-mobile-bar-btn pv-mobile-next" title="下一个"><i class="fas fa-chevron-right"></i></button>' +
        '<button class="pv-mobile-bar-btn pv-mobile-close" title="关闭"><i class="fas fa-times"></i></button>';

    var overlay = null;
    var mobileBar = null;
    var hintEl = null;

    // 手势状态
    var swipeState = null;   // 单指滑动起点
    var pinchState = null;   // 双指缩放状态
    var lastTapTime = 0;     // 上次轻触时间（双击检测）

    // ===== 工具函数 =====

    function getImgScale(img) {
        if (!img) return 1;
        var match = img.style.transform.match(/scale\(([^)]+)\)/);
        return match ? parseFloat(match[1]) : 1;
    }

    function setImgScale(img, newScale) {
        var transform = img.style.transform || '';
        if (/scale\([^)]+\)/.test(transform)) {
            img.style.transform = transform.replace(/scale\([^)]+\)/, 'scale(' + newScale + ')');
        } else {
            img.style.transform = transform + ' scale(' + newScale + ')';
        }
        img.style.opacity = '1';
    }

    function vibrate(ms) {
        if (navigator.vibrate) {
            try { navigator.vibrate(ms); } catch (e) {}
        }
    }

    // ===== 移动端底部操作栏 =====

    function ensureMobileBar() {
        if (!overlay) overlay = document.getElementById('previewOverlay');
        if (!overlay) return;

        if (!mobileBar) {
            mobileBar = document.createElement('div');
            mobileBar.className = 'pv-mobile-bar';
            mobileBar.innerHTML = MOBILE_BAR_HTML;
            overlay.appendChild(mobileBar);

            var prevBtn = mobileBar.querySelector('.pv-mobile-prev');
            var nextBtn = mobileBar.querySelector('.pv-mobile-next');
            var dlBtn = mobileBar.querySelector('.pv-mobile-download');
            var closeBtn = mobileBar.querySelector('.pv-mobile-close');

            prevBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (typeof PreviewShell !== 'undefined') PreviewShell.prev();
            });
            nextBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (typeof PreviewShell !== 'undefined') PreviewShell.next();
            });
            dlBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (typeof PreviewShell !== 'undefined') PreviewShell.download();
            });
            closeBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (typeof PreviewShell !== 'undefined') PreviewShell.close();
            });
        }

        if (!hintEl) {
            hintEl = document.createElement('div');
            hintEl.className = 'pv-mobile-hint';
            overlay.appendChild(hintEl);
        }

        updateMobileBarState();
    }

    function updateMobileBarState() {
        if (!mobileBar) return;
        var prevBtn = mobileBar.querySelector('.pv-mobile-prev');
        var nextBtn = mobileBar.querySelector('.pv-mobile-next');
        if (!prevBtn || !nextBtn) return;

        var hasPrev = false, hasNext = false;
        if (typeof previewState !== 'undefined' && previewState.fileList) {
            var idx = previewState.fileIndex;
            for (var i = idx - 1; i >= 0; i--) {
                if (!previewState.fileList[i].is_dir) { hasPrev = true; break; }
            }
            for (var j = idx + 1; j < previewState.fileList.length; j++) {
                if (!previewState.fileList[j].is_dir) { hasNext = true; break; }
            }
        }
        prevBtn.disabled = !hasPrev;
        nextBtn.disabled = !hasNext;
    }

    function showSwipeHint() {
        if (!hintEl) return;
        try {
            if (sessionStorage.getItem('pv-mobile-hint-shown')) return;
            sessionStorage.setItem('pv-mobile-hint-shown', '1');
        } catch (e) {}
        hintEl.textContent = '左右滑动切换 · 双击图片放大';
        hintEl.classList.add('show');
        setTimeout(function () {
            if (hintEl) hintEl.classList.remove('show');
        }, 2200);
    }

    // ===== 手势：左右滑动 / 双击 / 双指缩放 =====

    function bindGestures() {
        if (!overlay) overlay = document.getElementById('previewOverlay');
        if (!overlay) return;

        // touchstart：记录滑动起点 或 开始双指缩放
        overlay.addEventListener('touchstart', function (e) {
            if (e.touches.length === 2) {
                // 双指：图片预览时开始缩放
                if (typeof PreviewShell !== 'undefined' && PreviewShell._currentType === 'image') {
                    var img = document.getElementById('previewImage');
                    if (img) {
                        var dx = e.touches[0].clientX - e.touches[1].clientX;
                        var dy = e.touches[0].clientY - e.touches[1].clientY;
                        pinchState = {
                            startDist: Math.hypot(dx, dy),
                            startScale: getImgScale(img)
                        };
                    }
                }
                swipeState = null; // 多指时取消单指滑动
                return;
            }

            if (e.touches.length !== 1) {
                swipeState = null;
                return;
            }

            var target = e.target;

            // 在以下交互元素上不触发滑动切换（由元素自身处理触摸）
            if (target.closest('.pv-video-wrap, .pv-doc-frame, .pv-audio-modern, ' +
                '.pv-tool-btn, .pv-icon-btn, .preview-action-btn, .pv-mobile-bar, ' +
                '.preview-footer, .preview-header, .pv-audio-playlist, .pv-tabs-bar, ' +
                '.pv-progress-bar, .pv-volume-slider')) {
                swipeState = null;
                return;
            }

            // 图片容器：仅当适应屏幕（scale <= 1）时允许滑动切换
            var inImage = target.closest('.pv-image-container');
            if (inImage) {
                var img = document.getElementById('previewImage');
                if (img && getImgScale(img) > 1.01) {
                    swipeState = null; // 放大状态，让图片拖动处理
                    return;
                }
            }

            var t = e.touches[0];
            swipeState = {
                startX: t.clientX,
                startY: t.clientY,
                startTime: Date.now()
            };
        }, { passive: true });

        // touchmove：双指缩放
        overlay.addEventListener('touchmove', function (e) {
            if (e.touches.length === 2 && pinchState) {
                var img = document.getElementById('previewImage');
                if (img) {
                    var dx = e.touches[0].clientX - e.touches[1].clientX;
                    var dy = e.touches[0].clientY - e.touches[1].clientY;
                    var dist = Math.hypot(dx, dy);
                    var ratio = pinchState.startDist > 0 ? dist / pinchState.startDist : 1;
                    var newScale = Math.max(0.1, Math.min(10, pinchState.startScale * ratio));
                    setImgScale(img, newScale);
                    var zoomText = document.getElementById('imgZoomText');
                    if (zoomText) zoomText.textContent = Math.round(newScale * 100) + '%';
                    e.preventDefault();
                }
            }
        }, { passive: false });

        // touchend：处理滑动切换 / 双击 / 缩放结束
        overlay.addEventListener('touchend', function (e) {
            // 双指缩放结束
            if (pinchState && e.touches.length < 2) {
                pinchState = null;
                swipeState = null;
                return;
            }

            if (!swipeState) return;

            var t = e.changedTouches[0];
            var dx = t.clientX - swipeState.startX;
            var dy = t.clientY - swipeState.startY;
            var dt = Date.now() - swipeState.startTime;
            var moved = Math.hypot(dx, dy);
            swipeState = null;

            if (typeof PreviewShell === 'undefined') return;

            // 双击检测：位移小 + 时间短 + 图片预览
            if (PreviewShell._currentType === 'image' && moved < 12 && dt < 300) {
                var now = Date.now();
                if (now - lastTapTime < 300) {
                    // 触发双击：切换实际大小 / 适应屏幕
                    var img = document.getElementById('previewImage');
                    if (img) {
                        var scale = getImgScale(img);
                        var btn = scale < 0.99
                            ? document.getElementById('imgReset')
                            : document.getElementById('imgActualSize');
                        if (btn) btn.click();
                    }
                    lastTapTime = 0;
                    return;
                }
                lastTapTime = now;
                return;
            }

            // 左右滑动切换文件
            if (Math.abs(dx) > 60 && Math.abs(dy) < Math.abs(dx) * 0.5 && dt < 500) {
                if (dx < 0) PreviewShell.next();
                else PreviewShell.prev();
                vibrate(20);
            }
        }, { passive: true });

        overlay.addEventListener('touchcancel', function () {
            swipeState = null;
            pinchState = null;
        }, { passive: true });
    }

    // ===== Hook PreviewShell =====

    function hookPreviewShell() {
        if (typeof PreviewShell === 'undefined') return;

        var origOpen = PreviewShell.open;
        var origClose = PreviewShell.close;
        var origUpdateNav = PreviewShell.updateNavButtons;
        var origSetContent = PreviewShell.setContent;

        PreviewShell.open = function (fileId, fileList, index) {
            var result = origOpen.apply(this, arguments);
            ensureMobileBar();
            // 延迟显示提示，等预览内容开始加载
            setTimeout(showSwipeHint, 300);
            return result;
        };

        PreviewShell.close = function () {
            swipeState = null;
            pinchState = null;
            lastTapTime = 0;
            return origClose.apply(this, arguments);
        };

        PreviewShell.updateNavButtons = function () {
            var result = origUpdateNav.apply(this, arguments);
            updateMobileBarState();
            return result;
        };

        // 内容变化后，重置手势状态，避免跨预览残留
        PreviewShell.setContent = function (html, cssClass) {
            swipeState = null;
            pinchState = null;
            lastTapTime = 0;
            return origSetContent.apply(this, arguments);
        };
    }

    // ===== 初始化 =====

    function init() {
        overlay = document.getElementById('previewOverlay');
        if (!overlay) {
            // DOM 尚未就绪，等待
            document.addEventListener('DOMContentLoaded', init);
            return;
        }
        ensureMobileBar();
        bindGestures();
        hookPreviewShell();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
