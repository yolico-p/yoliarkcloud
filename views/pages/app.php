<?php
use App\Core\Security;
?>
<div class="app-layout">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-inner glass">
            <div class="sidebar-brand">
                <div class="sidebar-brand-icon"><i class="fas fa-cloud"></i></div>
                <span class="sidebar-brand-text"><?php echo Security::escape($config->get('app_name')); ?></span>
            </div>
            <nav class="sidebar-nav">
                <a href="javascript:;" class="nav-item active" data-page="files">
                    <span class="nav-icon"><i class="fas fa-folder"></i></span>
                    <span>全部文件</span>
                </a>
                <a href="javascript:;" class="nav-item" data-page="recent">
                    <span class="nav-icon"><i class="fas fa-clock"></i></span>
                    <span>最近访问</span>
                </a>
                <a href="javascript:;" class="nav-item" data-page="favorites">
                    <span class="nav-icon"><i class="fas fa-star"></i></span>
                    <span>我的收藏</span>
                </a>
                <a href="javascript:;" class="nav-item" data-page="shares">
                    <span class="nav-icon"><i class="fas fa-link"></i></span>
                    <span>我的分享</span>
                </a>
                <a href="javascript:;" class="nav-item" data-page="inbox" id="navInbox" style="display:none">
                    <span class="nav-icon"><i class="fas fa-inbox"></i></span>
                    <span>文件信箱</span>
                </a>
                <a href="javascript:;" class="nav-item" data-page="trash">
                    <span class="nav-icon"><i class="fas fa-trash-alt"></i></span>
                    <span>回收站</span>
                </a>
                <a href="javascript:;" class="nav-item" data-page="logs">
                    <span class="nav-icon"><i class="fas fa-history"></i></span>
                    <span>操作日志</span>
                </a>
                <a href="javascript:;" class="nav-item" data-page="ai" style="position:relative">
                    <span class="nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.4 7.2L21 12l-6.6 2.8L12 22l-2.4-7.2L3 12l6.6-2.8z"/></svg></span>
                    <span>AI 云助手</span>
                    <span id="aiNotifBadge" class="ai-notif-badge" style="display:none;position:absolute;top:2px;right:8px;min-width:16px;height:16px;padding:0 4px;background:var(--accent-danger);color:#fff;font-size:10px;font-weight:700;line-height:16px;text-align:center;border-radius:8px">0</span>
                </a>
                <div class="nav-divider"></div>
                <a href="javascript:;" class="nav-item" data-page="settings">
                    <span class="nav-icon"><i class="fas fa-cog"></i></span>
                    <span>系统设置</span>
                </a>
            </nav>
            <div class="sidebar-storage" id="sidebarStorage">
                <div class="storage-label">
                    <span>存储空间</span>
                    <span id="storageText">-- / --</span>
                </div>
                <div class="storage-bar">
                    <div class="storage-fill" id="storageFill" style="width:0%"></div>
                </div>
            </div>
        </div>
    </aside>

    <main class="main-content">
        <header class="top-bar glass">
            <button class="menu-toggle" id="menuToggle" aria-label="菜单"><i class="fas fa-bars"></i></button>
            <div class="breadcrumb" id="breadcrumb">
                <span class="breadcrumb-item" data-parent-id="0">全部文件</span>
            </div>
            <div style="position:absolute;left:-9999px;top:-9999px;opacity:0;pointer-events:none;">
                <input type="text" id="fakeInput" autocomplete="off" tabindex="-1" readonly>
                <input type="password" id="fakePassword" autocomplete="off" tabindex="-1" readonly>
            </div>
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="搜索文件..." autocomplete="off" spellcheck="false" data-1p-ignore="true" data-lpignore="true" data-form-type="other">
                <button data-action="perform-search" type="button" aria-label="搜索"><i class="fas fa-search"></i></button>
            </div>
            <button class="mobile-search-toggle" id="mobileSearchToggle" aria-label="搜索"><i class="fas fa-search"></i></button>
            <div class="user-menu">
                <button class="theme-toggle-btn" data-action="toggle-theme" title="切换主题">
                    <i class="fas fa-moon" id="themeIcon"></i>
                </button>
                <div class="user-avatar"><?php echo Security::escape(mb_substr($user['username'] ?? '?', 0, 1)); ?></div>
                <span class="user-name"><?php echo Security::escape($user['username'] ?? ''); ?></span>
                <button class="btn-icon" data-action="handle-logout" title="退出"><i class="fas fa-sign-out-alt"></i></button>
            </div>
        </header>

        <div class="content-area" id="contentArea">
            <div id="pageFiles" class="page active">
                <div class="toolbar">
                    <div class="toolbar-left">
                        <button class="btn btn-primary" data-action="show-upload-dialog"><i class="fas fa-cloud-upload-alt"></i> 上传</button>
                        <button class="btn btn-glass" data-action="show-new-folder-dialog"><i class="fas fa-folder-plus"></i> 新建</button>
                        <span class="toolbar-sep"></span>
                        <label class="select-all-check" id="selectAllCheck" style="display:none">
                            <input type="checkbox">
                            <span>全选</span>
                        </label>
                        <button class="btn btn-glass" id="batchDeleteBtn" style="display:none" data-action="batch-delete"><i class="fas fa-trash-alt"></i> 删除</button>
                        <button class="btn btn-glass" id="batchRenameBtn" style="display:none" data-action="batch-rename"><i class="fas fa-font"></i> 重命名</button>
                        <button class="btn btn-glass" id="batchMoveBtn" style="display:none" data-action="batch-move"><i class="fas fa-arrows-alt"></i> 移动</button>
                        <button class="btn btn-glass" id="batchCopyBtn" style="display:none" data-action="batch-copy"><i class="fas fa-copy"></i> 复制</button>
                    </div>
                    <div class="toolbar-right">
                        <select id="sortSelect" class="sort-select">
                            <option value="name">按名称</option>
                            <option value="size">按大小</option>
                            <option value="time">按时间</option>
                            <option value="type">按类型</option>
                            <option value="custom">自定义排序</option>
                        </select>
                        <div class="view-toggle" id="viewToggle">
                            <button class="view-toggle-btn" data-view="list" title="列表视图"><i class="fas fa-list"></i></button>
                            <button class="view-toggle-btn" data-view="grid" title="网格视图"><i class="fas fa-th-large"></i></button>
                        </div>
                    </div>
                </div>
                <div class="file-table" id="fileList">
                    <div class="loading"><div class="spinner"></div><span>加载中</span></div>
                </div>
            </div>

            <div id="pageRecent" class="page">
                <div class="page-header">
                    <h2 class="page-title"><i class="fas fa-clock" style="margin-right:10px;color:var(--accent-cyan)"></i>最近访问</h2>
                    <button class="btn btn-glass btn-sm" data-action="refresh-recent"><i class="fas fa-sync-alt"></i> 刷新</button>
                </div>
                <div class="file-table" id="recentList">
                    <div class="loading"><div class="spinner"></div><span>加载中</span></div>
                </div>
            </div>

            <div id="pageFavorites" class="page">
                <div class="page-header">
                    <h2 class="page-title"><i class="fas fa-star" style="margin-right:10px;color:var(--accent-warning)"></i>我的收藏</h2>
                </div>
                <div class="file-table" id="favoriteList">
                    <div class="loading"><div class="spinner"></div><span>加载中</span></div>
                </div>
            </div>

            <div id="pageShares" class="page">
                <div class="fluent-share-list-header">
                    <h2 class="fluent-share-list-title"><i class="fas fa-link"></i>我的分享</h2>
                </div>
                <div class="fluent-share-list" id="shareList">
                    <div class="loading"><div class="spinner"></div><span>加载中</span></div>
                </div>
            </div>

            <div id="pageInbox" class="page">
                <div class="page-header">
                    <h2 class="page-title"><i class="fas fa-inbox" style="margin-right:10px;color:var(--accent-primary)"></i>文件信箱</h2>
                    <div class="inbox-header-actions">
                        <button class="btn btn-glass btn-sm" data-action="refresh-inbox"><i class="fas fa-sync-alt"></i> 刷新</button>
                    </div>
                </div>

                <div class="inbox-status-card glass" id="inboxStatusCard">
                    <div class="inbox-status-title"><i class="fas fa-envelope-open-text"></i>收件链接</div>
                    <div class="inbox-status-desc">将链接发送给朋友，他们无需登录即可向你投递文件</div>
                    <div class="inbox-url-wrap">
                        <input type="text" id="inboxUrlInput" readonly value="" placeholder="信箱未启用或链接加载失败">
                        <button class="btn btn-primary btn-sm" data-action="copy-inbox-url"><i class="fas fa-copy"></i> 复制</button>
                    </div>
                    <div class="inbox-url-actions">
                        <button class="btn btn-ghost btn-sm" data-action="regenerate-inbox-url"><i class="fas fa-sync-alt"></i> 重新生成链接</button>
                    </div>
                </div>

                <div class="inbox-stats" id="inboxStats">
                    <div class="inbox-stat-box">
                        <div class="inbox-stat-value" id="inboxCount">--</div>
                        <div class="inbox-stat-label">待收取文件</div>
                    </div>
                    <div class="inbox-stat-box">
                        <div class="inbox-stat-value" id="inboxSize">--</div>
                        <div class="inbox-stat-label">总大小</div>
                    </div>
                </div>

                <div class="inbox-list" id="inboxList">
                    <div class="loading"><div class="spinner"></div><span>加载中</span></div>
                </div>
            </div>

            <div id="pageTrash" class="page">
                <div class="page-header">
                    <h2 class="page-title"><i class="fas fa-trash-alt" style="margin-right:10px;color:var(--accent-danger)"></i>回收站</h2>
                    <button class="btn btn-danger btn-sm" data-action="empty-trash"><i class="fas fa-broom"></i> 清空</button>
                </div>
                <div class="trash-list" id="trashList">
                    <div class="loading"><div class="spinner"></div><span>加载中</span></div>
                </div>
            </div>

            <div id="pageLogs" class="page">
                <div class="page-header">
                    <h2 class="page-title"><i class="fas fa-history" style="margin-right:10px;color:var(--accent-cyan)"></i>操作日志</h2>
                    <div class="logs-header-actions">
                        <button class="btn btn-glass btn-sm" data-action="toggle-log-stats"><i class="fas fa-chart-bar"></i> 统计</button>
                        <button class="btn btn-glass btn-sm" data-action="export-logs"><i class="fas fa-file-export"></i> 导出</button>
                        <button class="btn btn-glass btn-sm" data-action="clear-logs"><i class="fas fa-broom"></i> 清理</button>
                        <button class="btn btn-glass btn-sm" data-action="refresh-logs"><i class="fas fa-sync-alt"></i> 刷新</button>
                    </div>
                </div>
                
                <div id="logStatisticsPanel" class="log-statistics-panel" style="display:none;">
                    <div class="stats-grid" id="statsGrid"></div>
                </div>

                <div class="log-filters">
                    <div class="filter-bar">
                        <div class="filter-group">
                            <select id="logFilterCategory" class="filter-select">
                                <option value="all">所有类别</option>
                                <option value="file">文件操作</option>
                                <option value="auth">认证操作</option>
                                <option value="share">分享操作</option>
                                <option value="account">账户操作</option>
                                <option value="system">系统操作</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <select id="logFilterSeverity" class="filter-select">
                                <option value="all">所有级别</option>
                                <option value="info">普通</option>
                                <option value="warning">警告</option>
                                <option value="critical">严重</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <input type="text" id="logSearchInput" class="filter-input" placeholder="搜索操作目标、详情或IP...">
                        </div>
                        <div class="filter-group">
                            <input type="date" id="logDateFrom" class="filter-input">
                        </div>
                        <div class="filter-group">
                            <input type="date" id="logDateTo" class="filter-input">
                        </div>
                        <button class="btn btn-sm btn-ghost" data-action="reset-log-filters"><i class="fas fa-times"></i> 重置</button>
                    </div>
                </div>

                <div class="logs-container" id="logsContainer">
                    <div class="loading"><div class="spinner"></div><span>加载中</span></div>
                </div>
            </div>

            <div id="pageAi" class="page">
                <div class="ai-page-layout">
                    <div class="ai-page-sidebar-overlay" id="aiPageSidebarOverlay"></div>
                    <div class="ai-page-sidebar" id="aiPageSidebar">
                        <div class="ai-sidebar-mobile-header">
                            <span>历史会话</span>
                            <button class="ai-sidebar-close-btn" data-action="close-ai-history" aria-label="关闭"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="ai-sidebar-header">
                            <button class="btn btn-primary btn-sm" data-action="ai-new-session" style="width:100%"><i class="fas fa-plus"></i> 新对话</button>
                        </div>
                        <div id="aiSessionList" class="ai-session-list">
                            <div class="chat-history-empty" style="text-align:center;padding:20px;color:var(--text-muted);font-size:13px">暂无历史对话</div>
                        </div>
                        <div class="ai-quick-actions">
                            <h4>智能操作</h4>
                            <button class="ai-quick-btn" data-action="ai-quick" data-prompt="整理当前目录的文件，按类型分类到对应文件夹"><i class="fas fa-magic"></i> 智能整理</button>
                            <button class="ai-quick-btn" data-action="ai-quick" data-prompt="查找所有重复文件"><i class="fas fa-clone"></i> 查重</button>
                            <button class="ai-quick-btn" data-action="ai-quick" data-prompt="分析哪些文件占空间最大，给我清理建议"><i class="fas fa-chart-pie"></i> 空间分析</button>
                            <button class="ai-quick-btn" data-action="ai-quick" data-prompt="列出所有大文件，按大小降序排列"><i class="fas fa-weight-hanging"></i> 大文件</button>
                        </div>
                    </div>
                    <div class="ai-page-chat">
                        <div class="ai-mobile-quickbar">
                            <button class="ai-quick-btn" data-action="ai-quick" data-prompt="整理当前目录的文件，按类型分类到对应文件夹"><i class="fas fa-magic"></i> 智能整理</button>
                            <button class="ai-quick-btn" data-action="ai-quick" data-prompt="查找所有重复文件"><i class="fas fa-clone"></i> 查重</button>
                            <button class="ai-quick-btn" data-action="ai-quick" data-prompt="分析哪些文件占空间最大，给我清理建议"><i class="fas fa-chart-pie"></i> 空间分析</button>
                            <button class="ai-quick-btn" data-action="ai-quick" data-prompt="列出所有大文件，按大小降序排列"><i class="fas fa-weight-hanging"></i> 大文件</button>
                        </div>
                        <div class="ai-page-header">
                            <div style="display:flex;align-items:center;gap:10px">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--accent-primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.4 7.2L21 12l-6.6 2.8L12 22l-2.4-7.2L3 12l6.6-2.8z"/></svg>
                                <h2 class="page-title" style="margin:0">AI 云助手</h2>
                            </div>
                            <div class="ai-mobile-actions">
                                <button class="ai-mobile-history-btn" data-action="toggle-ai-history" aria-label="历史会话"><i class="fas fa-history"></i></button>
                                <button class="ai-mobile-new-btn" data-action="ai-new-session" aria-label="新对话"><i class="fas fa-plus"></i></button>
                            </div>
                            <div id="aiContextBar" class="ai-context-bar" style="display:none"></div>
                        </div>
                        <div id="aiChatMessages" class="ai-page-messages">
                            <div class="ai-msg ai-msg-assistant">
                                <div class="ai-msg-avatar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent-primary)" stroke-width="2"><path d="M12 2l2.4 7.2L21 12l-6.6 2.8L12 22l-2.4-7.2L3 12l6.6-2.8z"/></svg></div>
                                <div class="ai-msg-content">你好！我是云助手，可以帮你管理文件、创建分享、查看存储信息等。有什么可以帮你的吗？</div>
                            </div>
                        </div>
                        <div class="ai-page-input-area">
                            <div class="ai-input-wrap">
                                <textarea id="aiChatInput" placeholder="输入消息，Enter 发送，Shift+Enter 换行" rows="1"></textarea>
                                <button data-action="stop-ai-message" class="ai-stop-btn" id="aiStopBtn" style="display:none"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="6" width="12" height="12" rx="2"/></svg></button>
                                <button data-action="send-ai-message" class="ai-send-btn" id="aiSendBtn"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="pageSettings" class="page">
                <div class="page-header">
                    <h2 class="page-title"><i class="fas fa-cog" style="margin-right:10px;color:var(--text-muted)"></i>系统设置</h2>
                    <div class="settings-header-actions">
                        <button class="btn btn-glass btn-sm" data-action="refresh-config"><i class="fas fa-sync-alt"></i> 刷新</button>
                        <button class="btn btn-glass btn-sm" data-action="import-settings"><i class="fas fa-upload"></i> 导入设置</button>
                        <button class="btn btn-glass btn-sm" data-action="export-settings"><i class="fas fa-download"></i> 导出设置</button>
                    </div>
                </div>
                <div class="settings-tabs">
                    <button class="settings-tab-btn active" data-tab="general"><span>基础设置</span></button>
                    <button class="settings-tab-btn" data-tab="upload"><span>上传设置</span></button>
                    <button class="settings-tab-btn" data-tab="security"><span>安全设置</span></button>
                    <button class="settings-tab-btn" data-tab="api"><span>开放 API</span></button>
                    <button class="settings-tab-btn" data-tab="share"><span>分享设置</span></button>
                    <button class="settings-tab-btn" data-tab="cache"><span>缓存管理</span></button>
                    <button class="settings-tab-btn" data-tab="storage"><span>存储管理</span></button>
                    <button class="settings-tab-btn" data-tab="account"><span>账户设置</span></button>
                    <button class="settings-tab-btn" data-tab="update"><span>系统更新</span></button>
                    <button class="settings-tab-btn" data-tab="about"><span>关于</span></button>
                </div>
                <div class="settings-grid">
                    <?php require __DIR__ . '/settings/_general.php'; ?>
                    <?php require __DIR__ . '/settings/_upload.php'; ?>
                    <?php require __DIR__ . '/settings/_security.php'; ?>
                    <?php require __DIR__ . '/settings/_api.php'; ?>
                    <?php require __DIR__ . '/settings/_share.php'; ?>
                    <?php require __DIR__ . '/settings/_cache.php'; ?>
                    <?php require __DIR__ . '/settings/_storage.php'; ?>
                    <?php require __DIR__ . '/settings/_account.php'; ?>
                    <?php require __DIR__ . '/settings/_update.php'; ?>
                    <?php require __DIR__ . '/settings/_about.php'; ?>
                    </div>
                </div>
            </div>
<!-- Modals and overlays -->

<!-- 移动端：全屏搜索覆盖层 -->
<div class="mobile-search-overlay" id="mobileSearchOverlay">
    <div class="mobile-search-header">
        <button class="mobile-search-back" id="mobileSearchBack" aria-label="返回"><i class="fas fa-arrow-left"></i></button>
        <div class="mobile-search-input-wrap">
            <input type="text" id="mobileSearchInput" placeholder="搜索文件..." autocomplete="off" spellcheck="false">
            <button id="mobileSearchClear" type="button" aria-label="清空"><i class="fas fa-times"></i></button>
            <button id="mobileSearchSubmit" type="button" aria-label="搜索"><i class="fas fa-search"></i></button>
        </div>
    </div>
    <div class="content-area" id="mobileSearchResults" style="flex:1;overflow-y:auto;padding-top:12px">
        <div class="empty-state" style="padding:48px 16px">
            <div class="empty-icon"><i class="fas fa-search"></i></div>
            <h3>输入关键词搜索文件</h3>
            <p style="color:var(--text-muted);font-size:13px">支持按文件名、标签搜索</p>
        </div>
    </div>
</div>

<!-- 移动端：浮动上传按钮 -->
<button class="mobile-fab" id="mobileFab" aria-label="上传"><i class="fas fa-plus"></i></button>

<!-- 移动端：底部批量操作栏 -->
<div class="mobile-batch-bar" id="mobileBatchBar">
    <span class="mobile-batch-info" id="mobileBatchInfo">已选择 0 项</span>
    <div class="mobile-batch-actions" id="mobileBatchActions">
        <button class="btn btn-glass" data-action="batch-move"><i class="fas fa-arrows-alt"></i> 移动</button>
        <button class="btn btn-glass" data-action="batch-copy"><i class="fas fa-copy"></i> 复制</button>
        <button class="btn btn-glass" data-action="batch-rename"><i class="fas fa-font"></i> 重命名</button>
        <button class="btn btn-danger" data-action="batch-delete"><i class="fas fa-trash-alt"></i> 删除</button>
    </div>
</div>

<div class="modal-overlay" id="modalOverlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="modalTitle"></h3>
            <button class="modal-close" aria-label="关闭"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="modalBody"></div>
    </div>
</div>
</div>

<div class="upload-overlay" id="uploadOverlay" style="display:none">
    <div class="upload-box glass-strong">
        <div class="upload-box-header">
            <h3>上传文件</h3>
            <button data-action="close-upload-dialog" aria-label="关闭"><i class="fas fa-times"></i></button>
        </div>
        <div class="upload-box-body">
            <div class="dropzone" id="uploadDropzone">
                <div class="dropzone-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                <p>拖拽文件/文件夹到此处，或点击选择</p>
                <input type="file" id="fileInput" multiple style="display:none">
                <input type="file" id="folderInput" webkitdirectory directory multiple style="display:none">
                <div class="dropzone-buttons">
                    <button class="btn btn-glass" data-action="select-files"><i class="fas fa-folder-open"></i> 选择文件</button>
                    <button class="btn btn-glass" data-action="select-folder"><i class="fas fa-folder"></i> 选择文件夹</button>
                </div>
            </div>
            <div class="upload-queue" id="uploadQueue"></div>
        </div>
    </div>
</div>

<div class="upload-float-widget" id="uploadFloatWidget">
    <div class="upload-float-mini idle" id="uploadFloatMini">
        <i class="fas fa-cloud-upload-alt upload-float-icon"></i>
        <span class="upload-float-text" id="uploadFloatText">上传中 0%</span>
    </div>
    <div class="upload-float-panel" id="uploadFloatPanel" style="display:none">
        <div class="upload-float-panel-header">
            <h4>传输任务</h4>
            <button data-action="toggle-float-widget" title="收起"><i class="fas fa-chevron-down"></i></button>
        </div>
        <div class="upload-float-list" id="uploadFloatList"></div>
    </div>
</div>

<div class="upload-interrupt-overlay" id="uploadInterruptOverlay" style="display:none">
    <div class="upload-interrupt-box glass-strong">
        <div class="upload-interrupt-header">
            <h3><i class="fas fa-exclamation-triangle" style="color:var(--accent-warning);margin-right:8px"></i>上传中断</h3>
            <button data-action="close-interrupt-dialog" aria-label="关闭"><i class="fas fa-times"></i></button>
        </div>
        <div class="upload-interrupt-body">
            <p class="upload-interrupt-hint" id="interruptHint"></p>
            <div class="upload-interrupt-file-list" id="interruptFileList"></div>
        </div>
        <div class="upload-interrupt-footer">
            <button class="btn btn-primary" style="width:100%" data-action="close-interrupt-dialog">知道了</button>
        </div>
    </div>
</div>

<div class="context-menu glass-strong" id="contextMenu" style="display:none">
    <a href="javascript:;" data-action="ctx-download"><i class="fas fa-download"></i> 下载</a>
    <a href="javascript:;" data-action="ctx-preview"><i class="fas fa-eye"></i> 预览</a>
    <a href="javascript:;" data-action="ctx-share"><i class="fas fa-link"></i> 分享</a>
    <a href="javascript:;" data-action="ctx-favorite"><i class="fas fa-star"></i> 收藏</a>
    <a href="javascript:;" data-action="ctx-lock"><i class="fas fa-lock"></i> 锁定</a>
    <a href="javascript:;" data-action="ctx-encrypt"><i class="fas fa-shield-alt"></i> 加密</a>
    <a href="javascript:;" data-action="ctx-tags"><i class="fas fa-tags"></i> 标签</a>
    <a href="javascript:;" data-action="ctx-rename"><i class="fas fa-edit"></i> 重命名</a>
    <a href="javascript:;" data-action="ctx-move"><i class="fas fa-arrows-alt"></i> 移动</a>
    <a href="javascript:;" data-action="ctx-copy"><i class="fas fa-copy"></i> 复制</a>
    <a href="javascript:;" data-action="ctx-info"><i class="fas fa-info-circle"></i> 详情</a>
    <div class="context-divider"></div>
    <a href="javascript:;" data-action="ctx-delete" class="danger"><i class="fas fa-trash-alt"></i> 删除</a>
</div>

<!-- ===== UNIFIED PREVIEW OVERLAY ===== -->
<div class="preview-overlay" id="previewOverlay">
    <!-- 顶部玻璃态工具栏 -->
    <header class="preview-header" id="previewHeader">
        <div class="preview-header-left">
            <span class="preview-file-icon" id="previewFileIcon"></span>
            <div class="preview-file-meta">
                <span class="preview-file-name" id="previewFileName"></span>
                <span class="preview-file-size" id="previewFileSize"></span>
            </div>
        </div>
        <div class="preview-header-right">
            <button class="preview-action-btn" id="previewDownloadBtn" title="下载">
                <i class="fas fa-download"></i>
            </button>
            <button class="preview-action-btn preview-close-btn" id="previewCloseBtn" title="关闭 (Esc)">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </header>

    <!-- 左右悬浮导航 -->
    <button class="preview-nav-arrow preview-nav-prev" id="previewPrevBtn" title="上一个 (←)">
        <i class="fas fa-chevron-left"></i>
    </button>
    <button class="preview-nav-arrow preview-nav-next" id="previewNextBtn" title="下一个 (→)">
        <i class="fas fa-chevron-right"></i>
    </button>

    <!-- 主体内容区 -->
    <div class="preview-body" id="previewBody">
        <div class="preview-loading" id="previewLoading">
            <div class="preview-loading-ring"></div>
            <p>正在加载预览...</p>
        </div>
        <div class="preview-content" id="previewContent" style="display:none"></div>
        <div class="preview-error" id="previewError" style="display:none">
            <div class="preview-error-icon"><i class="fas fa-exclamation-circle"></i></div>
            <p class="preview-error-title">无法加载文件</p>
            <p class="preview-error-message" id="previewErrorMessage"></p>
            <button class="preview-error-btn" id="previewErrorClose">关闭预览</button>
        </div>
    </div>

    <!-- 底部信息/控制栏（按类型动态填充） -->
    <footer class="preview-footer" id="previewFooter" style="display:none"></footer>
</div>

<div class="toast-container" id="toastContainer"></div>
