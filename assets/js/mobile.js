/**
 * 移动端交互适配
 * 为底部导航、搜索覆盖层、浮动按钮、批量操作栏、滑动与长按等提供触摸友好的行为。
 */

(function () {
    'use strict';

    // 仅在触摸设备或窄屏启用部分行为，避免干扰桌面鼠标操作
    var isMobile = function () {
        return isTouchDevice || window.innerWidth <= 768;
    };

    var longPressTimer = null;
    var longPressFired = false;
    var longPressIgnoreClickUntil = 0;
    var touchStartX = 0;
    var touchStartY = 0;
    var touchStartTime = 0;
    var swipeThreshold = 60;
    var swipeAngle = 30;

    function setupMobileSearch() {
        var toggle = document.getElementById('mobileSearchToggle');
        var overlay = document.getElementById('mobileSearchOverlay');
        var back = document.getElementById('mobileSearchBack');
        var input = document.getElementById('mobileSearchInput');
        var clear = document.getElementById('mobileSearchClear');
        var submit = document.getElementById('mobileSearchSubmit');
        var results = document.getElementById('mobileSearchResults');

        if (!toggle || !overlay) return;

        function openSearch() {
            overlay.classList.add('active');
            if (input) {
                input.value = document.getElementById('searchInput') ? document.getElementById('searchInput').value : '';
                setTimeout(function () { input.focus(); }, 50);
            }
        }

        function closeSearch() {
            overlay.classList.remove('active');
        }

        function doSearch() {
            var keyword = input ? input.value.trim() : '';
            var desktopInput = document.getElementById('searchInput');
            if (desktopInput) desktopInput.value = keyword;
            if (keyword) {
                if (typeof performSearch === 'function') {
                    performSearch();
                }
                if (results) {
                    results.innerHTML = '<div class="loading" style="padding:48px 16px"><div class="spinner"></div><span>搜索中...</span></div>';
                }
                setTimeout(closeSearch, 150);
            } else {
                if (typeof loadFiles === 'function') {
                    loadFiles(currentParentId || 0, true);
                }
                closeSearch();
            }
        }

        toggle.addEventListener('click', openSearch);
        if (back) back.addEventListener('click', closeSearch);
        if (clear) clear.addEventListener('click', function () { if (input) { input.value = ''; input.focus(); } });
        if (submit) submit.addEventListener('click', doSearch);
        if (input) {
            input.addEventListener('keyup', function (e) {
                if (e.key === 'Enter') doSearch();
            });
        }
    }

    function setupMobileFab() {
        var fab = document.getElementById('mobileFab');
        if (!fab) return;
        fab.addEventListener('click', function () {
            if (typeof showUploadDialog === 'function') {
                showUploadDialog();
            }
        });
    }

    function setupMobileBatchBar() {
        var bar = document.getElementById('mobileBatchBar');
        if (!bar) return;
        bar.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) return;
            var action = btn.dataset.action;
            if (action === 'batch-delete' && typeof batchDelete === 'function') batchDelete();
            else if (action === 'batch-rename' && typeof showBatchRenameDialog === 'function') showBatchRenameDialog();
            else if (action === 'batch-move' && typeof showMoveDialog === 'function') showMoveDialog();
            else if (action === 'batch-copy' && typeof showCopyDialog === 'function') showCopyDialog();
        });
    }

    function setupTouchGestures() {
        var container = document.getElementById('fileList');
        if (!container) return;

        // 长按后忽略随后触发的 click，避免菜单刚显示就被关闭
        document.addEventListener('click', function (e) {
            if (Date.now() < longPressIgnoreClickUntil) {
                e.stopPropagation();
                longPressIgnoreClickUntil = 0;
            }
        }, true);

        container.addEventListener('touchstart', function (e) {
            var row = e.target.closest('.file-row, .grid-item');
            if (!row) return;
            var touch = e.touches[0];
            touchStartX = touch.clientX;
            touchStartY = touch.clientY;
            touchStartTime = Date.now();
            longPressFired = false;

            if (longPressTimer) clearTimeout(longPressTimer);
            longPressTimer = setTimeout(function () {
                longPressFired = true;
                longPressIgnoreClickUntil = Date.now() + 400;
                var fileId = parseInt(row.dataset.id);
                var file = typeof findFileById === 'function' ? findFileById(fileId) : null;
                if (file && typeof showContextMenu === 'function') {
                    // 构造一个类似鼠标事件的坐标对象
                    var rect = row.getBoundingClientRect();
                    showContextMenu({
                        clientX: rect.left + rect.width / 2,
                        clientY: rect.top + rect.height / 2,
                        preventDefault: function () {},
                        stopPropagation: function () {}
                    }, fileId, file);
                }
                if (navigator.vibrate) navigator.vibrate(40);
            }, 600);
        }, { passive: true });

        container.addEventListener('touchmove', function (e) {
            if (!longPressTimer) return;
            var touch = e.touches[0];
            var dx = Math.abs(touch.clientX - touchStartX);
            var dy = Math.abs(touch.clientY - touchStartY);
            // 移动超过阈值则取消长按
            if (dx > 10 || dy > 10) {
                clearTimeout(longPressTimer);
                longPressTimer = null;
            }
        }, { passive: true });

        container.addEventListener('touchend', function (e) {
            if (longPressTimer) {
                clearTimeout(longPressTimer);
                longPressTimer = null;
            }
            if (longPressFired) {
                e.preventDefault();
                e.stopPropagation();
                longPressFired = false;
                return;
            }

            var touch = e.changedTouches[0];
            var dx = touch.clientX - touchStartX;
            var dy = touch.clientY - touchStartY;
            var dt = Date.now() - touchStartTime;

            var row = e.target.closest('.file-row');
            if (row && Math.abs(dx) > swipeThreshold && Math.abs(dy) < Math.abs(dx) * Math.tan(swipeAngle * Math.PI / 180) && dt < 500) {
                // 水平滑动触发批量选择/取消
                e.preventDefault();
                e.stopPropagation();
                var checkbox = row.querySelector('.col-check input[type="checkbox"]');
                if (checkbox) {
                    checkbox.checked = !checkbox.checked;
                    if (typeof toggleSelect === 'function') {
                        toggleSelect(parseInt(row.dataset.id), checkbox);
                    }
                }
                return;
            }
        }, { passive: false });

        container.addEventListener('touchcancel', function () {
            if (longPressTimer) {
                clearTimeout(longPressTimer);
                longPressTimer = null;
            }
        });
    }

    function setupMobileSidebar() {
        var menuToggle = document.getElementById('menuToggle');
        if (!menuToggle || typeof toggleSidebar !== 'function') return;
        // 移动端汉堡菜单已由 files.js 注册，这里不做重复处理
    }

    function setupMobileUserMenu() {
        var avatar = document.querySelector('.user-avatar');
        if (!avatar) return;

        var menu = null;

        function closeMenu() {
            if (!menu) return;
            menu.classList.remove('show');
            var m = menu;
            menu = null;
            setTimeout(function () { if (m && m.parentNode) m.remove(); }, 200);
            document.removeEventListener('click', onOutsideClick, true);
        }

        function onOutsideClick(e) {
            if (menu && !menu.contains(e.target) && !avatar.contains(e.target)) {
                closeMenu();
            }
        }

        function openMenu() {
            if (menu) { closeMenu(); return; }

            menu = document.createElement('div');
            menu.className = 'mobile-user-menu';
            menu.innerHTML =
                '<button class="mobile-user-menu-item" data-action="change-password">' +
                '<i class="fas fa-key"></i><span>修改密码</span></button>' +
                '<button class="mobile-user-menu-item" data-action="logout">' +
                '<i class="fas fa-sign-out-alt"></i><span>退出登录</span></button>';

            document.body.appendChild(menu);

            // 定位到 avatar 下方右侧
            var rect = avatar.getBoundingClientRect();
            menu.style.top = (rect.bottom + 8) + 'px';
            menu.style.right = (window.innerWidth - rect.right) + 'px';

            requestAnimationFrame(function () { if (menu) menu.classList.add('show'); });

            menu.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-action]');
                if (!btn) return;
                var action = btn.dataset.action;
                closeMenu();

                if (action === 'change-password') {
                    var navItem = document.querySelector('[data-page="settings"]');
                    if (typeof switchPage === 'function') switchPage('settings', navItem);
                    var accountTab = document.querySelector('.settings-tab-btn[data-tab="account"]');
                    if (accountTab && typeof switchSettingsTab === 'function') {
                        setTimeout(function () { switchSettingsTab('account', accountTab); }, 120);
                    }
                } else if (action === 'logout') {
                    if (typeof handleLogout === 'function') handleLogout();
                }
            });

            // 延迟绑定外部点击，避免当前事件立即关闭
            setTimeout(function () {
                document.addEventListener('click', onOutsideClick, true);
            }, 0);
        }

        avatar.addEventListener('click', function (e) {
            e.stopPropagation();
            e.preventDefault();
            openMenu();
        });
    }

    function setupMobilePullToRefreshHint() {
        var contentArea = document.querySelector('.content-area');
        var fileList = document.getElementById('fileList');
        if (!contentArea || !fileList) return;

        var startY = 0;
        var refreshHint = null;

        contentArea.addEventListener('touchstart', function (e) {
            if (contentArea.scrollTop > 0) return;
            startY = e.touches[0].clientY;
        }, { passive: true });

        contentArea.addEventListener('touchmove', function (e) {
            if (contentArea.scrollTop > 0) return;
            var dy = e.touches[0].clientY - startY;
            if (dy > 80 && !refreshHint) {
                refreshHint = document.createElement('div');
                refreshHint.className = 'mobile-refresh-hint';
                refreshHint.innerHTML = '<i class="fas fa-arrow-down"></i> 释放刷新';
                refreshHint.style.cssText = 'text-align:center;padding:12px;color:var(--text-muted);font-size:13px;opacity:0;transition:opacity 0.2s;';
                contentArea.insertBefore(refreshHint, contentArea.firstChild);
                requestAnimationFrame(function () { if (refreshHint) refreshHint.style.opacity = '1'; });
            }
        }, { passive: true });

        contentArea.addEventListener('touchend', function (e) {
            if (refreshHint) {
                var dy = e.changedTouches[0].clientY - startY;
                refreshHint.remove();
                refreshHint = null;
                if (dy > 120 && contentArea.scrollTop <= 0) {
                    var page = document.querySelector('.page.active');
                    if (page && page.id === 'pageFiles' && typeof loadFiles === 'function') {
                        loadFiles(currentParentId || 0, true);
                    } else if (page && page.id === 'pageRecent' && typeof loadRecent === 'function') {
                        loadRecent();
                    }
                }
            }
        }, { passive: true });
    }

    function setupViewportSafeArea() {
        // 动态修正全屏搜索覆盖层高度，避免地址栏显隐导致布局问题
        function setMobileVh() {
            var vh = window.innerHeight * 0.01;
            document.documentElement.style.setProperty('--mobile-vh', vh + 'px');
        }
        setMobileVh();
        window.addEventListener('resize', debounce(setMobileVh, 200));
    }

    function debounce(fn, wait) {
        var t = null;
        return function () {
            var args = arguments;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(null, args); }, wait);
        };
    }

    function init() {
        if (!isMobile()) return;
        setupViewportSafeArea();
        setupMobileSearch();
        setupMobileFab();
        setupMobileBatchBar();
        setupTouchGestures();
        setupMobilePullToRefreshHint();
        setupMobileUserMenu();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
