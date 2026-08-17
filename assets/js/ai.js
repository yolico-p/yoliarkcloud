/**
 * AI 聊天助手 - 对话管理、流式响应、工具调用
 *
 * 架构：
 *  - AIChat：核心状态机，管理会话、消息、流式请求、确认机制（会话持久化由后端负责）
 *  - StreamRenderer：流式响应渲染器，处理文本 / 工具调用 / 确认卡片
 *  - 设置页相关函数（AI_PROVIDER_DEFAULTS / loadAIConfig 等）保持原样
 *
 * 依赖的全局：escapeHtml, renderMarkdown, _markedLoaded, api, showToast,
 *             switchPage, currentParentId, selectedFiles, APP_CONFIG.csrfToken,
 *             navigateTo, previewFile
 */

// ── Markdown 渲染辅助 ──
// 全局 renderMarkdown：marked 加载完成后用于渲染助手消息；未加载时退化为转义文本。
// 渲染结果一律走 DOMPurify 清洗，避免 AI 输出含 <script>/onerror 等 XSS 载荷。
function renderMarkdown(content) {
    if (_markedLoaded && typeof marked !== 'undefined') {
        try {
            var html = (typeof marked.parse === 'function') ? marked.parse(content) : marked(content);
            if (typeof window !== 'undefined' && window.DOMPurify) {
                html = window.DOMPurify.sanitize(html);
            }
            return html;
        } catch (e) {
            return escapeHtml(content).replace(/\n/g, '<br>');
        }
    }
    return escapeHtml(content).replace(/\n/g, '<br>');
}

function initMarkdown() {
    if (typeof marked !== 'undefined') {
        _markedLoaded = true;
        var renderer = new marked.Renderer();
        renderer.html = function (html) { return escapeHtml(html); };
        marked.setOptions({ renderer: renderer });
        return;
    }
    loadScript('marked').then(function () {
        _markedLoaded = true;
        var renderer = new marked.Renderer();
        renderer.html = function (html) { return escapeHtml(html); };
        marked.setOptions({ renderer: renderer });
    }).catch(function () {
        console.warn('[AI] marked 加载失败，Markdown 将以纯文本显示');
    });
}

// 注入 AI 智能升级相关样式（计划卡片 / 思考过程 / 复杂度提示 / TODO 卡片 / 子任务卡片）
(function () {
    var css = '' +
        '/* Plan card */' +
        '.ai-plan-card { background: #f0f7ff; border: 1px solid #d0e3ff; border-radius: 8px; padding: 12px; margin: 8px 0; }' +
        '.ai-plan-header { font-weight: 600; margin-bottom: 8px; }' +
        '.ai-plan-step { display: flex; align-items: center; gap: 8px; padding: 4px 0; }' +
        '.ai-plan-step-num { background: #4a90d9; color: white; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 12px; }' +
        '.ai-plan-step-tool { color: #333; }' +
        '.ai-plan-risk { color: #d97706; font-size: 13px; margin-top: 8px; }' +
        '.ai-plan-est { color: #666; font-size: 13px; }' +
        '/* Thought block */' +
        '.ai-thought-block { background: #f8f8f8; border-left: 3px solid #ccc; border-radius: 4px; padding: 8px 12px; margin: 8px 0; }' +
        '.ai-thought-block summary { cursor: pointer; color: #888; font-size: 13px; }' +
        '.ai-thought-content { margin-top: 8px; color: #555; font-size: 14px; white-space: pre-wrap; }' +
        '/* Complexity hint */' +
        '.ai-complexity-hint { background: #fff7ed; border: 1px solid #fed7aa; border-radius: 6px; padding: 8px 12px; margin: 8px 0; color: #92400e; font-size: 13px; }' +
        '/* TODO card */' +
        '.ai-todo-card { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px; margin: 8px 0; }' +
        '.ai-todo-header { font-weight: 600; margin-bottom: 8px; }' +
        '.ai-todo-progress { background: #ddd; border-radius: 4px; height: 6px; overflow: hidden; margin-bottom: 8px; }' +
        '.ai-todo-progress-bar { background: #22c55e; height: 100%; transition: width 0.3s; }' +
        '.ai-todo-item { padding: 4px 0; font-size: 14px; }' +
        '.ai-todo-done { text-decoration: line-through; color: #888; }' +
        '/* Subagent card */' +
        '.ai-subagent-card { background: #faf5ff; border: 1px solid #e9d5ff; border-radius: 8px; padding: 12px; margin: 8px 0; }' +
        '.ai-subagent-header { font-weight: 600; margin-bottom: 4px; }' +
        '.ai-subagent-status { color: #7c3aed; font-size: 13px; }' +
        '.ai-subagent-done { color: #22c55e; }';
    var style = document.createElement('style');
    style.setAttribute('data-ai-intelligence', '');
    style.textContent = css;
    (document.head || document.documentElement).appendChild(style);
})();

var AI_PROVIDER_DEFAULTS = {
    zhipu: { url: 'https://open.bigmodel.cn/api/paas/v4', desc: 'GLM 系列模型，GLM-4-Flash 免费使用' },
    deepseek: { url: 'https://api.deepseek.com/v1', desc: 'DeepSeek-V3/R1 系列模型' },
    siliconflow: { url: 'https://api.siliconflow.cn/v1', desc: '硅基流动，聚合多款开源模型' },
    moonshot: { url: 'https://api.moonshot.cn/v1', desc: 'Moonshot (Kimi) 长上下文模型' },
    qwen: { url: 'https://dashscope.aliyuncs.com/compatible-mode/v1', desc: '通义千问系列模型' },
    aiping: { url: 'https://api.aiping.io/v1', desc: 'AI Ping 聚合服务' },
    yi: { url: 'https://api.lingyiwanwu.com/v1', desc: '零一万物 Yi 系列模型' },
    ollama: { url: 'http://localhost:11434/v1', desc: 'Ollama 本地模型服务，无需 API Key' },
    custom: { url: '', desc: '自定义 OpenAI 兼容 API 地址' }
};

function loadAIConfig() {
    api('ai_agent_config', {}, 'GET').then(function(data) {
        if (data.success && data.config) {
            var c = data.config;
            var provider = c.provider || 'zhipu';
            var sel = document.getElementById('aiProvider');
            if (sel) sel.value = provider;
            var keyInput = document.getElementById('aiApiKey');
            if (keyInput && c.api_key) keyInput.value = c.api_key;
            var modelSel = document.getElementById('aiModel');
            if (modelSel && c.model) {
                var opt = document.createElement('option');
                opt.value = c.model;
                opt.textContent = c.model;
                modelSel.innerHTML = '';
                modelSel.appendChild(opt);
                modelSel.value = c.model;
            }
            var urlInput = document.getElementById('aiCustomBaseUrl');
            if (urlInput) urlInput.value = c.base_url || '';
            onAIProviderChange();
        }
    });
}

function onAIProviderChange() {
    var sel = document.getElementById('aiProvider');
    var provider = sel ? sel.value : 'zhipu';
    var defaults = AI_PROVIDER_DEFAULTS[provider] || AI_PROVIDER_DEFAULTS.custom;
    var descEl = document.getElementById('aiProviderDesc');
    if (descEl) descEl.textContent = defaults.desc;
    var customUrlRow = document.getElementById('aiCustomUrl');
    if (customUrlRow) customUrlRow.style.display = provider === 'custom' ? '' : 'none';
    var urlInput = document.getElementById('aiCustomBaseUrl');
    if (urlInput && provider !== 'custom' && defaults.url) {
        urlInput.value = defaults.url;
    }
}

function fetchAIModels() {
    var provider = document.getElementById('aiProvider').value;
    var apiKey = document.getElementById('aiApiKey').value;
    var baseUrl = document.getElementById('aiCustomBaseUrl').value;
    var defaults = AI_PROVIDER_DEFAULTS[provider] || AI_PROVIDER_DEFAULTS.custom;
    var url = provider === 'custom' ? baseUrl : defaults.url;
    if (!url) { showToast('请先填写 API 地址', 'warning'); return; }
    var btn = document.getElementById('fetchModelsBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
    api('ai_agent_fetch_models', { api_key: apiKey, base_url: url }).then(function(data) {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-sync-alt"></i> 获取模型'; }
        if (data.success && data.models) {
            var modelSel = document.getElementById('aiModel');
            modelSel.innerHTML = '';
            data.models.forEach(function(m) {
                var opt = document.createElement('option');
                opt.value = m.id || m;
                opt.textContent = m.id || m;
                modelSel.appendChild(opt);
            });
            showToast('已获取 ' + data.models.length + ' 个模型');
        } else {
            showToast(data.message || '获取模型失败', 'error');
        }
    });
}

function testAIConnection() {
    var provider = document.getElementById('aiProvider').value;
    var apiKey = document.getElementById('aiApiKey').value;
    var baseUrl = document.getElementById('aiCustomBaseUrl').value;
    var defaults = AI_PROVIDER_DEFAULTS[provider] || AI_PROVIDER_DEFAULTS.custom;
    var url = provider === 'custom' ? baseUrl : defaults.url;
    if (!url) { showToast('请先填写 API 地址', 'warning'); return; }
    var btn = document.getElementById('testConnBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 测试中'; }
    api('ai_agent_test_connection', { api_key: apiKey, base_url: url }).then(function(data) {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-plug"></i> 测试连接'; }
        if (data.success) {
            showToast(data.message || '连接成功', 'success');
        } else {
            showToast(data.message || '连接失败', 'error');
        }
    });
}

function saveAIConfig() {
    var provider = document.getElementById('aiProvider').value;
    var apiKey = document.getElementById('aiApiKey').value;
    var model = document.getElementById('aiModel').value;
    var baseUrl = document.getElementById('aiCustomBaseUrl').value;
    var defaults = AI_PROVIDER_DEFAULTS[provider] || AI_PROVIDER_DEFAULTS.custom;
    var url = provider === 'custom' ? baseUrl : defaults.url;
    api('ai_agent_save', { provider: provider, api_key: apiKey, model: model, base_url: url }).then(function(data) {
        if (data.success) {
            showToast('AI 配置已保存');
        } else {
            showToast(data.message || '保存失败', 'error');
        }
    });
}

// ── 流式进度订阅器 ──
// 通过 EventSource 订阅 Worker 写入的进度事件，支持断线重连。
// 后端 SSE 转发时未把 seq 放入 payload，因此 last_seq 始终传 0，
// 重连时可能重放少量事件——对 AI 对话场景影响可接受（token 累积渲染）。
function StreamProgressSubscriber(taskId, onEvent, onDone, onError) {
    this.taskId = taskId;
    this.lastSeq = 0;
    this.onEvent = onEvent;   // 回调(type, payload)
    this.onDone = onDone;     // 回调()
    this.onError = onError;   // 回调(message)
    this.eventSource = null;
    this.closed = false;
    this.reconnectTimer = null;
    this.reconnectCount = 0;  // 重连计数
    this.maxReconnects = 5;   // 最大重连次数
}

StreamProgressSubscriber.prototype = {
    start: function() {
        this._connect();
    },

    _connect: function() {
        if (this.closed) return;
        var self = this;
        var url = 'index.php?action=ai_agent_chat_stream_progress&task_id='
            + encodeURIComponent(this.taskId)
            + '&last_seq=' + this.lastSeq;
        this.eventSource = new EventSource(url);

        this.eventSource.onmessage = function(e) {
            try {
                var payload = JSON.parse(e.data);
                if (!payload || !payload.type) return;
                // 从 payload 中提取 seq 更新 lastSeq（支持断线续传）
                if (payload.seq && payload.seq > self.lastSeq) {
                    self.lastSeq = parseInt(payload.seq);
                }
                if (payload.type === 'done') {
                    self.onEvent && self.onEvent(payload.type, payload);
                    self._close();
                    self.onDone && self.onDone();
                } else if (payload.type === 'error') {
                    self.onEvent && self.onEvent(payload.type, payload);
                    self._close();
                    self.onError && self.onError(payload.message || '任务执行失败');
                } else {
                    self.onEvent && self.onEvent(payload.type, payload);
                }
            } catch (err) {
                // 忽略单条事件解析错误
            }
        };

        this.eventSource.onerror = function() {
            if (self.closed) return;
            self.eventSource.close();
            self.eventSource = null;
            // 重连次数超限：停止重连，通知错误
            self.reconnectCount++;
            if (self.reconnectCount > self.maxReconnects) {
                self.onError && self.onError('连接多次失败，请检查网络后重试');
                return;
            }
            // 网络抖动时 3 秒后重连，携带真实 last_seq 避免重放
            self.reconnectTimer = setTimeout(function() {
                self._connect();
            }, 3000);
        };
    },

    _close: function() {
        this.closed = true;
        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }
    },

    close: function() {
        this._close();
    }
};

// ── AIChat：核心状态机 ──
const AIChat = {
    state: {
        sessionId: null,
        messages: [],      // 本地副本，仅用于展示与构造请求
        streaming: false,
        abortController: null,
        pendingConfirm: null,  // {tool, args} 等待用户确认
        toolLoopCount: 0,      // 工具循环计数，超过阈值显示"后台执行"
        pollingTaskId: null,   // 后台任务轮询ID
        pollingInterval: null, // 轮询定时器
        _notifInterval: null,  // 通知轮询定时器
        _bgBtnShown: false,    // 后台执行按钮是否已显示
    },

    // AI 页面展示时初始化
    init() {
        initMarkdown();
        this.renderSessionList();
        this.startNotificationPolling();
        // 检查 AI 是否已配置
        fetch('index.php?action=ai_agent_config')
            .then(r => r.json())
            .then(data => {
                var configured = data.success && data.config && data.config.api_key && data.config.api_key !== '';
                if (!configured) {
                    var msgs = document.getElementById('aiChatMessages');
                    if (msgs) {
                        msgs.innerHTML = '<div class="ai-msg ai-msg-assistant"><div class="ai-msg-avatar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent-primary)" stroke-width="2"><path d="M12 2l2.4 7.2L21 12l-6.6 2.8L12 22l-2.4-7.2L3 12l6.6-2.8z"/></svg></div><div class="ai-msg-content" style="color:var(--text-muted)">AI 云助手尚未配置，请先在 <a href="javascript:void(0)" data-action="go-settings" style="color:var(--accent-primary);text-decoration:underline">系统设置 → AI 配置</a> 中填写 API Key 后使用。</div></div>';
                    }
                }
            })
            .catch(() => {});
    },

    // 从文件管理器状态获取当前上下文
    getContext() {
        // 状态来源：store.js 通过 defineAlias 暴露的全局变量
        //   - currentParentId：当前目录 ID（files.parentId 别名）
        //   - selectedFiles：选中的文件 ID 集合（files.selectedIds 别名，Set<number>）
        // 当前活跃页从 DOM 的 .nav-item.active[data-page] 推断，
        // 面包屑目录名从 #breadcrumb 容器内的 .breadcrumb-item 读取
        var activeNav = document.querySelector('.nav-item.active[data-page]');
        var page = (activeNav && activeNav.dataset.page) || 'files';
        var context = {
            page: page,
            current_dir_id: (typeof currentParentId !== 'undefined') ? currentParentId : 0,
        };

        // 从 DOM 面包屑获取当前目录名
        // files.js switchPage() 在非 files 页把 #breadcrumb 替换为 <span class="breadcrumb-page-title">
        // 仅 files 页保留 .breadcrumb-item 序列
        var bcItems = document.querySelectorAll('#breadcrumb .breadcrumb-item');
        if (bcItems.length > 0) {
            var last = bcItems[bcItems.length - 1];
            context.current_dir_name = (last.textContent || '').trim() || '根目录';
        } else {
            var titleEl = document.querySelector('#breadcrumb .breadcrumb-page-title');
            context.current_dir_name = titleEl ? (titleEl.textContent || '').trim() : '根目录';
        }

        // 获取已选文件名 - selectedFiles 中只有 ID
        // 前端尽量从 DOM 抓取名称；后端 buildSystemPrompt 还会按 ID 从 DB 兜底补全
        if (typeof selectedFiles !== 'undefined' && selectedFiles && selectedFiles.size > 0) {
            context.selected_files = Array.from(selectedFiles).map(id => ({ id: id }));
            var names = [];
            selectedFiles.forEach(function(id) {
                // 列表视图 .file-row[data-id]，网格视图 .grid-item[data-id]
                var card = document.querySelector('.file-row[data-id="' + id + '"], .grid-item[data-id="' + id + '"]');
                if (card) {
                    var nameEl = card.querySelector('.file-name-text, .grid-name');
                    if (nameEl) names.push({ id: id, name: nameEl.textContent.trim() });
                }
            });
            if (names.length > 0) context.selected_files = names;
        }

        return context;
    },

    // 更新上下文栏显示
    updateContextBar() {
        var bar = document.getElementById('aiContextBar');
        if (!bar) return;
        var ctx = this.getContext();
        var html = '';
        if (ctx.current_dir_name && ctx.current_dir_name !== '根目录') {
            html += '<span class="ai-ctx-tag"><i class="fas fa-folder"></i> ' + escapeHtml(ctx.current_dir_name) + '</span>';
        }
        if (ctx.selected_files && ctx.selected_files.length > 0) {
            html += '<span class="ai-ctx-tag"><i class="fas fa-file"></i> ' + ctx.selected_files.length + '个文件已选</span>';
        }
        if (html) {
            bar.innerHTML = html;
            bar.style.display = 'flex';
        } else {
            bar.style.display = 'none';
        }
    },

    // 创建新会话
    async newSession() {
        var context = this.getContext();
        try {
            var response = await fetch('index.php?action=ai_session_create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': APP_CONFIG.csrfToken },
                body: JSON.stringify({ context: context, _csrf_token: APP_CONFIG.csrfToken })
            });
            var data = await response.json();
            if (data.success) {
                this.state.sessionId = data.session_id;
                this.state.messages = [];
                this.state.pendingConfirm = null;
                var msgs = document.getElementById('aiChatMessages');
                msgs.innerHTML = '<div class="ai-msg ai-msg-assistant"><div class="ai-msg-avatar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent-primary)" stroke-width="2"><path d="M12 2l2.4 7.2L21 12l-6.6 2.8L12 22l-2.4-7.2L3 12l6.6-2.8z"/></svg></div><div class="ai-msg-content">你好！我是云助手，可以帮你管理文件、创建分享、查看存储信息等。有什么可以帮你的吗？</div></div>';
                this.renderSessionList();
            }
        } catch (e) {
            console.error('[AI] Failed to create session:', e);
        }
    },

    // 从服务器渲染会话列表
    async renderSessionList() {
        try {
            var response = await fetch('index.php?action=ai_session_list');
            var data = await response.json();
            var container = document.getElementById('aiSessionList');
            if (!container) return;

            if (!data.success || !data.sessions || data.sessions.length === 0) {
                container.innerHTML = '<div class="chat-history-empty" style="text-align:center;padding:20px;color:var(--text-muted);font-size:13px">暂无历史对话</div>';
                return;
            }

            var html = '';
            data.sessions.forEach(function(s) {
                var isActive = s.id === AIChat.state.sessionId ? 'active' : '';
                var date = new Date(s.updated_at * 1000);
                var now = new Date();
                var timeStr;
                if (date.toDateString() === now.toDateString()) {
                    timeStr = date.toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' });
                } else {
                    timeStr = date.toLocaleDateString('zh-CN', { month: 'numeric', day: '2-digit' });
                }
                html += '<div class="chat-history-item ' + isActive + '" data-session-id="' + s.id + '">' +
                    '<i class="fas fa-comment-alt" style="font-size:14px;color:var(--text-secondary)"></i>' +
                    '<div class="chat-history-item-title">' + escapeHtml(s.title) + '</div>' +
                    '<div class="chat-history-item-time">' + timeStr + '</div>' +
                    '<div class="chat-history-item-delete" data-action="delete-ai-session" data-session-id="' + s.id + '">' +
                        '<i class="fas fa-times" style="font-size:12px"></i>' +
                    '</div>' +
                '</div>';
            });
            container.innerHTML = html;
        } catch (e) {
            console.error('[AI] Failed to load sessions:', e);
        }
    },

    // 加载某个会话的消息
    async loadSession(sessionId) {
        try {
            var response = await fetch('index.php?action=ai_session_messages&session_id=' + encodeURIComponent(sessionId));
            var data = await response.json();
            if (!data.success) return;

            this.state.sessionId = sessionId;
            this.state.messages = [];
            this.state.pendingConfirm = null;

            var msgs = document.getElementById('aiChatMessages');
            msgs.innerHTML = '';

            if (!data.messages || data.messages.length === 0) {
                msgs.innerHTML = '<div class="ai-msg ai-msg-assistant"><div class="ai-msg-avatar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent-primary)" stroke-width="2"><path d="M12 2l2.4 7.2L21 12l-6.6 2.8L12 22l-2.4-7.2L3 12l6.6-2.8z"/></svg></div><div class="ai-msg-content">你好！有什么可以帮你的吗？</div></div>';
            } else {
                data.messages.forEach(function(msg) {
                    if (msg.role === 'user' || msg.role === 'assistant') {
                        if (msg.content && msg.content.trim()) {
                            AIChat.renderMessage(msg.role, msg.content, false);
                        }
                    }
                });
            }

            this.renderSessionList();
        } catch (e) {
            console.error('[AI] Failed to load session:', e);
        }
    },

    // 删除会话
    async deleteSession(sessionId) {
        try {
            await fetch('index.php?action=ai_session_delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': APP_CONFIG.csrfToken },
                body: JSON.stringify({ session_id: sessionId, _csrf_token: APP_CONFIG.csrfToken })
            });
            if (this.state.sessionId === sessionId) {
                this.state.sessionId = null;
                this.state.messages = [];
                var msgs = document.getElementById('aiChatMessages');
                msgs.innerHTML = '<div class="ai-msg ai-msg-assistant"><div class="ai-msg-avatar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent-primary)" stroke-width="2"><path d="M12 2l2.4 7.2L21 12l-6.6 2.8L12 22l-2.4-7.2L3 12l6.6-2.8z"/></svg></div><div class="ai-msg-content">你好！有什么可以帮你的吗？</div></div>';
            }
            this.renderSessionList();
        } catch (e) {
            console.error('[AI] Failed to delete session:', e);
        }
    },

    // 发送消息
    async send(text) {
        if (!text || !text.trim()) return;
        if (this.state.streaming) return;
        // 立即设置 streaming=true 防止并发 send 竞态
        // （在首个 await 之前设置，避免两次 send 都通过检查）
        this.state.streaming = true;
        this.updateUI(true);

        // 若无会话则先创建
        if (!this.state.sessionId) {
            var context = this.getContext();
            try {
                var resp = await fetch('index.php?action=ai_session_create', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': APP_CONFIG.csrfToken },
                    body: JSON.stringify({ context: context, _csrf_token: APP_CONFIG.csrfToken })
                });
                var data = await resp.json();
                if (data.success) {
                    this.state.sessionId = data.session_id;
                } else {
                    showToast('创建会话失败', 'error');
                    this.state.streaming = false;
                    this.updateUI(false);
                    return;
                }
            } catch (e) {
                showToast('网络错误', 'error');
                this.state.streaming = false;
                this.updateUI(false);
                return;
            }
        }

        // 若存在待确认操作，则将本次输入视为确认
        if (this.state.pendingConfirm) {
            this.state.pendingConfirm = null;
            this.renderMessage('user', text, true);
            this.state.messages.push({ role: 'user', content: text });
            // streaming 已在 send 开头设置，直接执行
            await this._streamWithConfirmationInternal();
            return;
        }

        // 普通消息
        this.renderMessage('user', text, true);
        this.state.messages.push({ role: 'user', content: text });

        var apiMessages = this.state.messages.map(function(m) {
            return { role: m.role, content: m.content };
        });

        // streaming 已在 send 开头设置，直接执行
        await this._streamInternal(apiMessages);
    },

    // 内部流式发送（streaming 已由调用方设置）
    async _streamInternal(messages) {
        this.state.toolLoopCount = 0;
        this.state._bgBtnShown = false;

        var bubble = this.createAssistantBubble();
        var renderer = new StreamRenderer(bubble);

        try {
            await this._streamViaWorker(messages, false, renderer);
        } catch (e) {
            renderer.error(e.message || '网络错误');
        } finally {
            this.state.streaming = false;
            this.state.abortController = null;
            this.state.pollingTaskId = null;
            this.updateUI(false);
        }
    },

    // 内部带确认流式发送（streaming 已由调用方设置）
    async _streamWithConfirmationInternal() {
        this.state.toolLoopCount = 0;
        this.state._bgBtnShown = false;

        var bubble = this.createAssistantBubble();
        var renderer = new StreamRenderer(bubble);

        try {
            await this._streamViaWorker([], true, renderer);
        } catch (e) {
            renderer.error(e.message || '网络错误');
        } finally {
            this.state.streaming = false;
            this.state.abortController = null;
            this.state.pollingTaskId = null;
            this.updateUI(false);
        }
    },

    // 两步走 CLI Worker：submit 拿 task_id → EventSource 订阅进度
    async _streamViaWorker(messages, confirmResume, renderer) {
        // 步骤 1：提交对话任务到 Worker
        var bodyObj = {
            context: this.getContext(),
            session_id: this.state.sessionId,
            _csrf_token: APP_CONFIG.csrfToken
        };
        if (confirmResume) {
            bodyObj.messages = [];
            bodyObj.confirm_resume = true;
        } else {
            bodyObj.messages = messages;
        }

        var submitResp = await fetch('index.php?action=ai_agent_chat_stream_submit', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': APP_CONFIG.csrfToken },
            body: JSON.stringify(bodyObj)
        });
        var data = await submitResp.json();
        if (!data.success) {
            throw new Error(data.message || '提交任务失败');
        }

        var taskId = data.task_id;
        if (data.session_id) {
            this.state.sessionId = data.session_id;
        }
        this.state.pollingTaskId = taskId;

        // 步骤 2：订阅进度流
        var self = this;
        var ctx = { fullContent: '' };

        await new Promise(function(resolve) {
            var settled = false;
            function settle() {
                if (!settled) { settled = true; resolve(); }
            }
            var subscriber = new StreamProgressSubscriber(
                taskId,
                function(type, payload) {
                    // 复用 handleStream 的事件渲染逻辑
                    self._handleProgressEvent(type, payload, renderer, ctx);
                },
                settle,  // onDone：done 事件已由 onEvent 处理，此处兜底 resolve
                settle   // onError：error 事件已由 onEvent 渲染，不 reject 避免重复报错
            );
            self.state.abortController = { subscriber: subscriber };
            subscriber.start();
        });
    },

    // 处理单个进度事件（从原 handleStream 提取，保持渲染逻辑不变）
    // ctx = { fullContent: '' } 跨事件共享的可变状态
    _handleProgressEvent(type, event, renderer, ctx) {
        switch (type) {
            case 'text':
                ctx.fullContent += event.content || '';
                renderer.text(ctx.fullContent);
                break;
            case 'tool_start':
                this.state.toolLoopCount++;
                renderer.toolStart(event.name, event.args);
                // 工具循环超过 3 轮，显示"后台执行"按钮
                if (this.state.toolLoopCount > 3 && !this.state._bgBtnShown) {
                    this.state._bgBtnShown = true;
                    renderer.showBackgroundButton();
                }
                break;
            case 'tool_progress':
                renderer.toolProgress(event.name, event.progress, event.message);
                break;
            case 'tool_result':
                renderer.toolResult(event.name, event.result);
                // Render TODO card for manage_todo results
                if (event.name === 'manage_todo' && event.result && event.result.todos) {
                    renderer.todoCard(event.result);
                }
                // Handle need_confirm
                if (event.result && event.result.need_confirm) {
                    this.state.pendingConfirm = {
                        tool: event.result.tool || event.name,
                        args: event.result.args || event.args,
                    };
                    renderer.confirmCard(event.result);
                }
                break;
            case 'need_confirm':
                // 已在 tool_result 中处理
                break;
            case 'done':
                if (ctx.fullContent) {
                    this.state.messages.push({ role: 'assistant', content: ctx.fullContent });
                }
                renderer.done();
                // 首轮交互后异步生成标题
                if (this.state.messages.length === 2) {
                    this.generateTitleInBackground();
                }
                this.renderSessionList();
                break;
            case 'error':
                renderer.error(event.message || '请求失败');
                break;
            case 'plan':
                renderer.planCard(event);
                break;
            case 'thought':
                renderer.thoughtBlock(event.text || '');
                break;
            case 'complexity_hint':
                renderer.complexityHint(event.message || '检测到复杂任务');
                break;
            case 'subagent_start':
                renderer.subagentStart(event);
                break;
            case 'subagent_progress':
                renderer.subagentProgress(event);
                break;
            case 'subagent_done':
                renderer.subagentDone(event);
                break;
        }
    },

    // 停止生成
    stop() {
        var taskId = this.state.pollingTaskId;
        if (this.state.abortController && this.state.abortController.subscriber) {
            this.state.abortController.subscriber.close();
        }
        // fire-and-forget 取消后端任务
        if (taskId) {
            fetch('index.php?action=ai_agent_cancel_task', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': APP_CONFIG.csrfToken },
                body: JSON.stringify({ task_id: taskId, _csrf_token: APP_CONFIG.csrfToken })
            }).catch(function() {});
        }
        this.state.streaming = false;
        this.state.abortController = null;
        this.state.pollingTaskId = null;
        this.updateUI(false);
    },

    // ── 后台执行 ──

    // 切换到后台执行：停止当前 SSE 流，入队后台任务
    async sendToBackground() {
        var taskId = this.state.pollingTaskId;
        // 停止当前进度订阅
        if (this.state.abortController && this.state.abortController.subscriber) {
            this.state.abortController.subscriber.close();
        }
        this.state.streaming = false;
        this.state.abortController = null;
        this.updateUI(false);

        if (!taskId) {
            showToast('无法获取任务ID', 'error');
            return;
        }

        try {
            var response = await fetch('index.php?action=ai_agent_convert_to_background', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': APP_CONFIG.csrfToken },
                body: JSON.stringify({ task_id: taskId, _csrf_token: APP_CONFIG.csrfToken })
            });
            var data = await response.json();
            if (data.success) {
                // 复用原 task_id 切换到粗粒度轮询
                this.state.pollingTaskId = taskId;
                this.startProgressPolling(taskId);
                this.renderMessage('assistant', '⏳ 任务已转至后台执行，您可以继续其他操作。任务完成后会通知您。', true);
                showToast('任务已转至后台执行', 'info');
            } else {
                showToast(data.message || '转换失败', 'error');
            }
        } catch (e) {
            showToast('网络错误', 'error');
        }
    },

    // 开始进度轮询
    startProgressPolling(taskId) {
        this.stopProgressPolling();
        var self = this;
        this.state.pollingInterval = setInterval(function() {
            api('ai_agent_task_status', { task_id: taskId }).then(function(data) {
                if (!data.success || !data.progress) return;
                var p = data.progress;
                if (p.status === 'completed') {
                    self.stopProgressPolling();
                    self.renderMessage('assistant', p.result_summary || '后台任务已完成', true);
                    self.state.messages.push({ role: 'assistant', content: p.result_summary || '后台任务已完成' });
                    self.renderSessionList();
                } else if (p.status === 'failed') {
                    self.stopProgressPolling();
                    self.renderMessage('assistant', '后台任务失败: ' + (p.error_message || '未知错误'), true);
                }
                // running / queued 状态继续轮询
            });
        }, 3000);
    },

    // 停止进度轮询
    stopProgressPolling() {
        if (this.state.pollingInterval) {
            clearInterval(this.state.pollingInterval);
            this.state.pollingInterval = null;
        }
        this.state.pollingTaskId = null;
    },

    // 销毁实例，清理所有资源（页面切换时调用）
    destroy() {
        // 关闭 EventSource
        if (this.state.abortController && this.state.abortController.subscriber) {
            this.state.abortController.subscriber.close();
        }
        this.state.abortController = null;
        // 清理轮询定时器
        this.stopProgressPolling();
        // 清理通知轮询
        if (this.state._notifInterval) {
            clearInterval(this.state._notifInterval);
            this.state._notifInterval = null;
        }
        // 重置流式状态
        this.state.streaming = false;
        // 保留 sessionId 和 messages（用户可能切回来继续对话）
    },

    // ── 通知轮询 ──

    startNotificationPolling() {
        if (this._notifInterval) return;
        var self = this;
        // 首次立即查询
        this._updateNotifBadge();
        this._notifInterval = setInterval(function() {
            self._updateNotifBadge();
        }, 15000);
    },

    _updateNotifBadge() {
        api('ai_unread_count').then(function(data) {
            if (!data.success) return;
            var badge = document.getElementById('aiNotifBadge');
            if (badge) {
                if (data.count > 0) {
                    badge.textContent = data.count > 99 ? '99+' : data.count;
                    badge.style.display = '';
                } else {
                    badge.style.display = 'none';
                }
            }
        });
    },

    // 更新 UI 状态（按钮）
    updateUI(streaming) {
        var sendBtn = document.getElementById('aiSendBtn');
        var stopBtn = document.getElementById('aiStopBtn');
        var input = document.getElementById('aiChatInput');
        if (sendBtn) sendBtn.style.display = streaming ? 'none' : '';
        if (stopBtn) stopBtn.style.display = streaming ? '' : 'none';
        if (input) input.disabled = streaming;
    },

    // 创建助手消息气泡
    createAssistantBubble() {
        var msgs = document.getElementById('aiChatMessages');
        var div = document.createElement('div');
        div.className = 'ai-msg ai-msg-assistant';
        var svgIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent-primary)" stroke-width="2"><path d="M12 2l2.4 7.2L21 12l-6.6 2.8L12 22l-2.4-7.2L3 12l6.6-2.8z"/></svg>';
        div.innerHTML = '<div class="ai-msg-avatar">' + svgIcon + '</div><div class="ai-msg-content"><span class="ai-typing-indicator"><span>正在思考</span><span class="ai-typing-dots"><span></span><span></span><span></span></span></span></div>';
        msgs.appendChild(div);
        msgs.scrollTop = msgs.scrollHeight;
        return div.querySelector('.ai-msg-content');
    },

    // 渲染一条消息（用于加载历史）
    renderMessage(role, content, animate) {
        var msgs = document.getElementById('aiChatMessages');
        var div = document.createElement('div');
        div.className = 'ai-msg ai-msg-' + role + (animate === false ? '' : ' ai-msg-new');
        var avatarIcon = role === 'user'
            ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>'
            : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent-primary)" stroke-width="2"><path d="M12 2l2.4 7.2L21 12l-6.6 2.8L12 22l-2.4-7.2L3 12l6.6-2.8z"/></svg>';
        var displayContent = _markedLoaded && role === 'assistant'
            ? renderMarkdown(content)
            : escapeHtml(content).replace(/\n/g, '<br>');
        div.innerHTML = '<div class="ai-msg-avatar">' + avatarIcon + '</div><div class="ai-msg-content">' + displayContent + '</div>';
        msgs.appendChild(div);
        msgs.scrollTop = msgs.scrollHeight;
    },

    // 后台生成标题
    async generateTitleInBackground() {
        if (!this.state.sessionId) return;
        var firstUserMsg = (this.state.messages.find(function(m) { return m.role === 'user'; }) || {}).content;
        var firstAiMsg = (this.state.messages.find(function(m) { return m.role === 'assistant'; }) || {}).content;
        if (!firstUserMsg || !firstAiMsg) return;

        try {
            var response = await api('ai_generate_title', {
                firstUserMsg: firstUserMsg.substring(0, 200),
                firstAiMsg: firstAiMsg.substring(0, 200)
            });
            if (response.success && response.title) {
                await fetch('index.php?action=ai_session_update_title', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': APP_CONFIG.csrfToken },
                    body: JSON.stringify({
                        session_id: this.state.sessionId,
                        title: response.title,
                        _csrf_token: APP_CONFIG.csrfToken
                    })
                });
                this.renderSessionList();
            }
        } catch (e) {}
    },

    // 快捷操作
    quick(prompt) {
        var input = document.getElementById('aiChatInput');
        if (input) input.value = prompt;
        this.sendFromInput();
    },

    // 从输入框发送
    sendFromInput() {
        var input = document.getElementById('aiChatInput');
        if (!input) return;
        var msg = input.value.trim();
        if (!msg) return;
        input.value = '';
        this.send(msg);
    },
};

// StreamRenderer：处理流式 AI 响应的渲染
class StreamRenderer {
    constructor(container) {
        this.container = container;
        this.textArea = null;
        this.toolHistory = null;
        this.typingIndicator = container.querySelector('.ai-typing-indicator');
        this.toolNameMap = {
            'list_files': '列出文件',
            'search_files': '搜索文件',
            'search_content': '搜索内容',
            'read_file': '读取文件',
            'get_file_info': '文件详情',
            'list_recent_files': '最近文件',
            'create_folder': '创建文件夹',
            'move_files': '移动文件',
            'copy_files': '复制文件',
            'delete_files': '删除文件',
            'rename_file': '重命名',
            'batch_rename': '批量重命名',
            'toggle_favorite': '收藏',
            'navigate_to': '跳转',
            'create_share': '创建分享',
            'list_shares': '查看分享',
            'delete_share': '删除分享',
            'get_storage_info': '存储信息',
            'find_duplicates': '查找重复',
            'find_large_files': '查找大文件',
            'list_trash': '回收站',
            'restore_files': '恢复文件',
            'empty_trash': '清空回收站',
            'organize_files_by_type': '按类型整理',
            'search_and_delete': '搜索并删除',
            'search_and_move': '搜索并移动',
            'cleanup_empty_folders': '清理空文件夹',
            'get_file_tree': '文件树',
            'scan_files': '扫描文件',
            'get_file_stats_by_type': '类型统计',
            'get_folder_size': '文件夹大小',
            'find_and_share_largest_image': '查找并分享最大图片',
            'search_and_share': '搜索并分享',
            'manage_todo': '任务清单',
            'spawn_subagent': '派发子任务',
        };
    }

    text(content) {
        if (!this.textArea) {
            if (this.typingIndicator) this.typingIndicator.style.display = 'none';
            this.textArea = document.createElement('div');
            this.textArea.className = 'ai-text-area';
            this.container.appendChild(this.textArea);
        }
        // 使用 requestAnimationFrame 节流渲染，避免每个 token 触发一次 innerHTML 重解析
        this._pendingContent = content.trimStart();
        if (!this._rafScheduled) {
            this._rafScheduled = true;
            var self = this;
            requestAnimationFrame(function() {
                self._rafScheduled = false;
                if (self.textArea) {
                    self.textArea.innerHTML = renderMarkdown(self._pendingContent);
                    var msgs = document.getElementById('aiChatMessages');
                    if (msgs) msgs.scrollTop = msgs.scrollHeight;
                }
            });
        }
    }

    toolStart(name, args) {
        if (this.typingIndicator) this.typingIndicator.style.display = 'none';
        if (!this.toolHistory) {
            this.toolHistory = document.createElement('div');
            this.toolHistory.className = 'ai-tool-history';
            this.container.insertBefore(this.toolHistory, this.textArea || null);
        }

        var displayName = this.toolNameMap[name] || escapeHtml(name);
        var indicator = document.createElement('div');
        indicator.className = 'ai-tool-indicator';
        indicator.dataset.name = name;

        var argsHtml = '';
        if (args && Object.keys(args).length > 0) {
            var argTexts = [];
            var entries = Object.entries(args);
            for (var i = 0; i < entries.length; i++) {
                var k = entries[i][0], v = entries[i][1];
                var val = typeof v === 'object' ? JSON.stringify(v) : String(v);
                if (val.length > 30) val = val.substring(0, 30) + '...';
                argTexts.push(escapeHtml(k) + ': ' + escapeHtml(val));
            }
            argsHtml = '<div class="ai-tool-args">' + argTexts.join(' | ') + '</div>';
        }

        indicator.innerHTML = '<div class="ai-tool-row"><span class="ai-tool-spinner"></span><span class="ai-tool-name">' + displayName + '</span></div>' + argsHtml;
        this.toolHistory.appendChild(indicator);

        var msgs = document.getElementById('aiChatMessages');
        msgs.scrollTop = msgs.scrollHeight;
    }

    toolProgress(name, progress, message) {
        // 此处可更新进度条，暂仅滚动
        var msgs = document.getElementById('aiChatMessages');
        msgs.scrollTop = msgs.scrollHeight;
    }

    toolResult(name, result) {
        if (!this.toolHistory) return;
        var indicators = this.toolHistory.querySelectorAll('.ai-tool-indicator[data-name="' + name + '"]');
        var target = indicators[indicators.length - 1];
        if (!target) return;

        var displayName = this.toolNameMap[name] || escapeHtml(name);
        var hasError = result && result.error;
        var success = !hasError;
        var statusIcon = success
            ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>'
            : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        var statusClass = success ? 'ai-tool-success' : 'ai-tool-error';
        var resultSummary = '执行成功';
        if (hasError) {
            resultSummary = escapeHtml(String(result.error));
        } else if (result && result.message) {
            resultSummary = escapeHtml(result.message);
        } else if (result && Array.isArray(result)) {
            resultSummary = '返回 ' + result.length + ' 条结果';
        }

        target.innerHTML = '<div class="ai-tool-row ' + statusClass + '">' + statusIcon + '<span class="ai-tool-name">' + displayName + '</span><span class="ai-tool-summary">' + resultSummary + '</span></div>';

        // 若结果包含文件列表，渲染可交互卡片
        if (success && result && result.files && Array.isArray(result.files) && result.files.length > 0) {
            this.renderFileCards(target, result.files);
        }

        // 若结果包含分享链接，渲染分享卡片
        if (success && result && (result.share_url || result.qrcode_svg)) {
            this.renderShareCard(target, result);
        }

        // navigate_to 工具：触发前端跳转
        if (success && result && result.navigate_to) {
            var navFileId = result.navigate_to;
            var isDir = result.is_dir;
            setTimeout(function () {
                if (isDir && typeof navigateTo === 'function') {
                    navigateTo(navFileId);
                    if (typeof switchPage === 'function') {
                        switchPage('files', document.querySelector('[data-page=files]'));
                    }
                } else if (typeof previewFile === 'function') {
                    previewFile(navFileId);
                }
            }, 500);
        }

        var msgs = document.getElementById('aiChatMessages');
        msgs.scrollTop = msgs.scrollHeight;
    }

    renderFileCards(container, files) {
        var cardDiv = document.createElement('div');
        cardDiv.className = 'ai-file-cards';
        cardDiv.style.cssText = 'margin-top:8px;display:flex;flex-direction:column;gap:4px';

        var html = '';
        files.slice(0, 20).forEach(function(f) {
            var icon = f.is_dir || f.type === 'folder' ? 'fa-folder' : getFileIconClass(f.type);
            html += '<div class="ai-file-card" data-file-id="' + (f.id || '') + '" data-is-dir="' + (f.is_dir || f.type === 'folder' ? 'true' : 'false') + '" style="display:flex;align-items:center;gap:8px;padding:6px 10px;background:var(--bg-surface);border-radius:6px;cursor:pointer;transition:background 0.15s" onmouseover="this.style.background=\'var(--bg-glass-hover)\'" onmouseout="this.style.background=\'var(--bg-surface)\'">' +
                '<i class="fas ' + icon + '" style="font-size:14px;color:var(--accent-primary);width:16px"></i>' +
                '<span style="flex:1;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + escapeHtml(f.name || f.filename || '') + '</span>' +
                (f.size ? '<span style="font-size:11px;color:var(--text-muted)">' + escapeHtml(f.size) + '</span>' : '') +
            '</div>';
        });
        if (files.length > 20) {
            html += '<div style="text-align:center;font-size:12px;color:var(--text-muted);padding:4px">还有 ' + (files.length - 20) + ' 个文件未显示</div>';
        }
        cardDiv.innerHTML = html;
        container.appendChild(cardDiv);

        // 为文件卡片绑定点击跳转
        cardDiv.querySelectorAll('.ai-file-card').forEach(function(card) {
            card.addEventListener('click', function() {
                var fileId = parseInt(this.dataset.fileId);
                var isDir = this.dataset.isDir === 'true';
                if (isDir) {
                    if (typeof navigateTo === 'function') navigateTo(fileId);
                    if (typeof switchPage === 'function') switchPage('files', document.querySelector('[data-page=files]'));
                } else {
                    if (typeof previewFile === 'function') previewFile(fileId);
                }
            });
        });
    }

    renderShareCard(container, result) {
        var shareCard = document.createElement('div');
        shareCard.className = 'ai-share-card';
        shareCard.style.cssText = 'margin-top:12px;padding:16px;background:var(--bg-surface);border:1px solid var(--bg-glass-border);border-radius:12px;display:flex;align-items:center;gap:16px;flex-wrap:wrap';
        var html = '<div style="flex:1;min-width:200px">';
        html += '<div style="font-size:13px;color:var(--text-muted);margin-bottom:4px">分享链接</div>';
        html += '<a href="' + escapeHtml(result.share_url || '') + '" target="_blank" style="font-size:14px;color:var(--accent-primary);word-break:break-all">' + escapeHtml(result.share_url || '') + '</a>';
        if (result.has_password) {
            html += '<div style="font-size:12px;color:var(--accent-warning);margin-top:4px"><i class="fas fa-lock"></i> 需要密码</div>';
        }
        if (result.expire_at) {
            var expireDate = new Date(result.expire_at * 1000);
            html += '<div style="font-size:12px;color:var(--text-muted);margin-top:4px"><i class="fas fa-clock"></i> 有效期至: ' + expireDate.toLocaleString() + '</div>';
        }
        html += '</div>';
        if (result.qrcode_svg) {
            html += '<div style="flex-shrink:0"><img src="data:image/svg+xml;base64,' + result.qrcode_svg + '" style="width:140px;height:140px;border-radius:8px;display:block" alt="二维码"></div>';
        }
        shareCard.innerHTML = html;
        container.appendChild(shareCard);
    }

    confirmCard(result) {
        if (!result || !result.preview) return;
        var preview = result.preview;
        var card = document.createElement('div');
        card.className = 'ai-confirm-card';
        card.style.cssText = 'margin-top:12px;padding:16px;background:rgba(239,68,68,0.05);border:1px solid rgba(239,68,68,0.2);border-radius:12px';

        var html = '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">';
        html += '<i class="fas fa-exclamation-triangle" style="color:var(--accent-danger)"></i>';
        html += '<span style="font-weight:600;color:var(--accent-danger)">需要确认：' + escapeHtml(preview.action) + ' ' + preview.file_count + ' 个文件</span>';
        html += '</div>';

        if (preview.target) {
            html += '<div style="font-size:13px;color:var(--text-secondary);margin-bottom:4px">目标: ' + escapeHtml(preview.target) + '</div>';
        }
        html += '<div style="font-size:13px;color:var(--text-secondary);margin-bottom:8px">总大小: ' + escapeHtml(preview.total_size) + '</div>';

        if (preview.file_samples && preview.file_samples.length > 0) {
            html += '<div style="font-size:12px;color:var(--text-muted);margin-bottom:8px">受影响文件:</div>';
            html += '<div style="display:flex;flex-direction:column;gap:2px;margin-bottom:12px">';
            preview.file_samples.forEach(function(f) {
                html += '<div style="font-size:12px;padding:2px 8px;background:var(--bg-surface);border-radius:4px">' + escapeHtml(f.name) + ' (' + escapeHtml(f.size) + ')</div>';
            });
            if (preview.file_count > preview.file_samples.length) {
                html += '<div style="font-size:11px;color:var(--text-muted);padding:2px 8px">...还有 ' + (preview.file_count - preview.file_samples.length) + ' 个</div>';
            }
            html += '</div>';
        }

        html += '<div style="display:flex;gap:8px">';
        html += '<button class="btn btn-danger btn-sm" data-action="ai-confirm-yes" style="flex:1"><i class="fas fa-check"></i> 确认' + escapeHtml(preview.action) + '</button>';
        html += '<button class="btn btn-glass btn-sm" data-action="ai-confirm-no" style="flex:1">取消</button>';
        html += '</div>';

        card.innerHTML = html;
        this.container.appendChild(card);

        var msgs = document.getElementById('aiChatMessages');
        msgs.scrollTop = msgs.scrollHeight;
    }

    error(msg) {
        if (this.typingIndicator) this.typingIndicator.style.display = 'none';
        var errDiv = document.createElement('div');
        errDiv.style.cssText = 'color:var(--accent-danger);font-size:14px';
        errDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + escapeHtml(msg);
        this.container.appendChild(errDiv);
    }

    done() {
        if (this.typingIndicator) this.typingIndicator.style.display = 'none';
    }

    /**
     * 显示"后台执行"按钮（工具循环超过 3 轮时触发）。
     */
    showBackgroundButton() {
        // 避免重复添加
        if (this.container.querySelector('.ai-bg-btn')) return;

        var btn = document.createElement('div');
        btn.className = 'ai-bg-btn';
        btn.style.cssText = 'margin-top:12px;padding:12px 16px;background:rgba(59,130,246,0.08);border:1px solid rgba(59,130,246,0.2);border-radius:10px;display:flex;align-items:center;gap:10px;cursor:pointer;transition:background 0.15s';
        btn.innerHTML = '<i class="fas fa-cloud-arrow-up" style="color:var(--accent-primary);font-size:16px"></i>' +
            '<div style="flex:1">' +
            '<div style="font-size:13px;font-weight:600;color:var(--accent-primary)">转至后台执行</div>' +
            '<div style="font-size:12px;color:var(--text-muted);margin-top:2px">任务将在后台继续，您可以继续其他操作</div>' +
            '</div>' +
            '<i class="fas fa-chevron-right" style="color:var(--text-muted)"></i>';

        btn.addEventListener('click', function() {
            AIChat.sendToBackground();
        });
        btn.addEventListener('mouseover', function() { this.style.background = 'rgba(59,130,246,0.14)'; });
        btn.addEventListener('mouseout', function() { this.style.background = 'rgba(59,130,246,0.08)'; });

        this.container.appendChild(btn);

        var msgs = document.getElementById('aiChatMessages');
        msgs.scrollTop = msgs.scrollHeight;
    }

    // 计划卡片
    planCard(plan) {
        if (this.typingIndicator) this.typingIndicator.style.display = 'none';
        if (!this.toolHistory) {
            this.toolHistory = document.createElement('div');
            this.toolHistory.className = 'ai-tool-history';
            this.container.insertBefore(this.toolHistory, this.textArea || null);
        }
        var card = document.createElement('div');
        card.className = 'ai-plan-card';
        var stepsHtml = '';
        if (plan.steps && plan.steps.length > 0) {
            for (var i = 0; i < plan.steps.length; i++) {
                var step = plan.steps[i];
                var tool = step.tool || '';
                var toolName = this.toolNameMap[tool] || escapeHtml(tool);
                stepsHtml += '<div class="ai-plan-step"><span class="ai-plan-step-num">' + (i + 1) + '</span><span class="ai-plan-step-tool">' + toolName + '</span></div>';
            }
        }
        var riskHtml = plan.risk ? '<div class="ai-plan-risk">风险：' + escapeHtml(String(plan.risk)) + '</div>' : '';
        var estHtml = plan.estimated_steps ? '<div class="ai-plan-est">预计步骤：' + plan.estimated_steps + '</div>' : '';
        card.innerHTML = '<div class="ai-plan-header">📋 执行计划</div><div class="ai-plan-steps">' + stepsHtml + '</div>' + riskHtml + estHtml;
        this.toolHistory.appendChild(card);
        var msgs = document.getElementById('aiChatMessages');
        msgs.scrollTop = msgs.scrollHeight;
    }

    // 思考过程（折叠）
    thoughtBlock(text) {
        if (!this.textArea) {
            if (this.typingIndicator) this.typingIndicator.style.display = 'none';
            this.textArea = document.createElement('div');
            this.textArea.className = 'ai-text-area';
            this.container.appendChild(this.textArea);
        }
        var thoughtEl = document.createElement('details');
        thoughtEl.className = 'ai-thought-block';
        thoughtEl.innerHTML = '<summary>💭 思考过程</summary><div class="ai-thought-content">' + escapeHtml(text) + '</div>';
        this.container.insertBefore(thoughtEl, this.textArea);
        var msgs = document.getElementById('aiChatMessages');
        msgs.scrollTop = msgs.scrollHeight;
    }

    // 复杂度提示
    complexityHint(message) {
        if (!this.toolHistory) {
            this.toolHistory = document.createElement('div');
            this.toolHistory.className = 'ai-tool-history';
            this.container.insertBefore(this.toolHistory, this.textArea || null);
        }
        var hint = document.createElement('div');
        hint.className = 'ai-complexity-hint';
        hint.innerHTML = '⚠️ ' + escapeHtml(message);
        this.toolHistory.appendChild(hint);
        var msgs = document.getElementById('aiChatMessages');
        msgs.scrollTop = msgs.scrollHeight;
    }

    // TODO 任务卡片
    todoCard(result) {
        if (this.typingIndicator) this.typingIndicator.style.display = 'none';
        if (!this.toolHistory) {
            this.toolHistory = document.createElement('div');
            this.toolHistory.className = 'ai-tool-history';
            this.container.insertBefore(this.toolHistory, this.textArea || null);
        }
        // Remove existing todo card if present
        var existing = this.toolHistory.querySelector('.ai-todo-card');
        if (existing) existing.remove();

        var card = document.createElement('div');
        card.className = 'ai-todo-card';
        var todos = result.todos || [];
        var total = result.total || todos.length;
        var completed = result.completed || 0;
        var progress = total > 0 ? Math.round(completed / total * 100) : 0;

        var itemsHtml = '';
        for (var i = 0; i < todos.length; i++) {
            var todo = todos[i];
            var status = todo.status || 'pending';
            var checked = status === 'completed' ? '✅' : '⬜';
            var content = escapeHtml(todo.content || '');
            var cls = status === 'completed' ? ' ai-todo-done' : '';
            itemsHtml += '<div class="ai-todo-item' + cls + '">' + checked + ' ' + content + '</div>';
        }

        card.innerHTML = '<div class="ai-todo-header">📝 任务清单 (' + completed + '/' + total + ')</div>' +
            '<div class="ai-todo-progress"><div class="ai-todo-progress-bar" style="width:' + progress + '%"></div></div>' +
            '<div class="ai-todo-items">' + itemsHtml + '</div>';
        this.toolHistory.appendChild(card);
        var msgs = document.getElementById('aiChatMessages');
        msgs.scrollTop = msgs.scrollHeight;
    }

    // 子任务进度
    subagentStart(event) {
        if (this.typingIndicator) this.typingIndicator.style.display = 'none';
        if (!this.toolHistory) {
            this.toolHistory = document.createElement('div');
            this.toolHistory.className = 'ai-tool-history';
            this.container.insertBefore(this.toolHistory, this.textArea || null);
        }
        var card = document.createElement('div');
        card.className = 'ai-subagent-card';
        card.dataset.taskId = event.task_id || '';
        card.innerHTML = '<div class="ai-subagent-header">🤖 子任务: ' + escapeHtml(event.task || '') + '</div>' +
            '<div class="ai-subagent-status">执行中...</div>';
        this.toolHistory.appendChild(card);
        var msgs = document.getElementById('aiChatMessages');
        msgs.scrollTop = msgs.scrollHeight;
    }

    subagentProgress(event) {
        var card = this.toolHistory ? this.toolHistory.querySelector('.ai-subagent-card:last-child') : null;
        if (card) {
            var status = card.querySelector('.ai-subagent-status');
            if (status) {
                status.textContent = event.message || event.progress || '执行中...';
            }
        }
        var msgs = document.getElementById('aiChatMessages');
        msgs.scrollTop = msgs.scrollHeight;
    }

    subagentDone(event) {
        var card = this.toolHistory ? this.toolHistory.querySelector('.ai-subagent-card:last-child') : null;
        if (card) {
            var status = card.querySelector('.ai-subagent-status');
            if (status) {
                status.textContent = '✅ 完成';
                status.classList.add('ai-subagent-done');
            }
        }
        var msgs = document.getElementById('aiChatMessages');
        msgs.scrollTop = msgs.scrollHeight;
    }
}

// 辅助：根据类型获取文件图标 class
function getFileIconClass(type) {
    var iconMap = {
        'image': 'fa-image',
        'video': 'fa-video',
        'audio': 'fa-music',
        'document': 'fa-file-alt',
        'archive': 'fa-file-archive',
        'pdf': 'fa-file-pdf',
    };
    return iconMap[type] || 'fa-file';
}

// ── 供 HTML 调用的全局函数 ──

function sendAIMessage() {
    AIChat.sendFromInput();
}

function sendAIQuick(text) {
    AIChat.quick(text);
}

function clearAIChat() {
    AIChat.newSession();
}

function setupAIDelegation() {
    var sessionList = document.getElementById('aiSessionList');
    if (sessionList) {
        sessionList.addEventListener('click', function(e) {
            var delBtn = e.target.closest('[data-action="delete-ai-session"]');
            if (delBtn) {
                e.stopPropagation();
                AIChat.deleteSession(delBtn.dataset.sessionId);
                return;
            }
            var item = e.target.closest('.chat-history-item');
            if (item) AIChat.loadSession(item.dataset.sessionId);
        });
    }

    var messages = document.getElementById('aiChatMessages');
    if (messages) {
        messages.addEventListener('click', function(e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) return;
            if (btn.dataset.action === 'ai-confirm-yes') {
                // 发送“确认”以触发待确认操作
                AIChat.send('确认执行');
            } else if (btn.dataset.action === 'ai-confirm-no') {
                AIChat.state.pendingConfirm = null;
                var card = btn.closest('.ai-confirm-card');
                if (card) card.remove();
                AIChat.renderMessage('assistant', '已取消操作。', false);
                AIChat.state.messages.push({ role: 'assistant', content: '已取消操作。' });
            } else if (btn.dataset.action === 'go-settings') {
                switchPage('settings', document.querySelector('[data-page=settings]'));
            }
        });
    }

    // AI 页面级按钮：新对话 / 停止生成
    // （发送 send-ai-message 与快捷 ai-quick 由 contentArea 统一委托处理，此处不重复）
    var aiPage = document.getElementById('pageAi');
    var aiSidebar = document.getElementById('aiPageSidebar');
    var aiSidebarOverlay = document.getElementById('aiPageSidebarOverlay');

    function openAiSidebar() {
        if (aiSidebar) aiSidebar.classList.add('open');
        if (aiSidebarOverlay) aiSidebarOverlay.classList.add('active');
    }

    function closeAiSidebar() {
        if (aiSidebar) aiSidebar.classList.remove('open');
        if (aiSidebarOverlay) aiSidebarOverlay.classList.remove('active');
    }

    if (aiPage) {
        aiPage.addEventListener('click', function(e) {
            var btn = e.target.closest('[data-action]');
            if (!btn) return;
            var action = btn.dataset.action;
            if (action === 'ai-new-session') {
                AIChat.newSession();
                closeAiSidebar();
            } else if (action === 'stop-ai-message') {
                AIChat.stop();
            } else if (action === 'toggle-ai-history') {
                if (aiSidebar && aiSidebar.classList.contains('open')) {
                    closeAiSidebar();
                } else {
                    openAiSidebar();
                }
            } else if (action === 'close-ai-history') {
                closeAiSidebar();
            }
        });

        // 点击遮罩关闭历史会话抽屉
        if (aiSidebarOverlay) {
            aiSidebarOverlay.addEventListener('click', closeAiSidebar);
        }
    }

    // 加载时初始化
    AIChat.init();

    // 注册页面切换清理：监听 AI 页面的 visibility 变化
    // 通过 MutationObserver 监听 nav-item.active 的变化
    var _prevAiActive = document.querySelector('.nav-item.active[data-page="ai"]');
    if (_prevAiActive) {
        // AI 页面当前激活，无需处理
    }
    // 使用全局 switchPage 钩子（如果存在）
    if (typeof window._aiPageCleanupRegistered === 'undefined') {
        window._aiPageCleanupRegistered = true;
        // 监听 nav-item 点击，切换离开 AI 页时调用 destroy
        document.addEventListener('click', function(e) {
            var navItem = e.target.closest('.nav-item[data-page]');
            if (navItem && navItem.dataset.page !== 'ai') {
                // 切换到非 AI 页，清理 AI 资源
                if (AIChat.state.streaming || AIChat.state.abortController || AIChat.state.pollingInterval || AIChat.state._notifInterval) {
                    AIChat.destroy();
                }
            }
        }, true); // capture 模式，在路由切换前执行
    }
}
