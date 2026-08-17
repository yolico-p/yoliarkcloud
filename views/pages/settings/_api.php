<?php
use App\Core\Security;
?>
                    <div id="settingsTabApi" class="settings-tab-content">
                        <div class="settings-card glass">
                            <h3><i class="fas fa-plug" style="margin-right:8px"></i>开放 API</h3>
                            <div class="settings-row">
                                <label>启用开放 API</label>
                                <div class="toggle-switch">
                                    <input type="checkbox" id="cfg_api_enabled" <?php echo $config->get('api_enabled') ? 'checked' : ''; ?>>
                                    <label for="cfg_api_enabled"><span></span></label>
                                </div>
                            </div>
                            <small>开启后，外部程序可通过 API Token 访问网盘功能</small>
                        </div>

                        <div class="settings-card glass" id="apiTokenSection" style="<?php echo $config->get('api_enabled') ? '' : 'display:none'; ?>">
                            <h3><i class="fas fa-key" style="margin-right:8px"></i>API Token</h3>
                            <div id="apiTokenStatus">
                                <?php if ($config->get('api_token')): ?>
                                <div class="settings-row">
                                    <label>当前状态</label>
                                    <span style="color:var(--success-color)"><i class="fas fa-check-circle"></i> 已激活</span>
                                </div>
                                <div class="settings-row">
                                    <label>创建时间</label>
                                    <span><?php echo date('Y-m-d H:i:s', $config->get('api_token_created_at', 0)); ?></span>
                                </div>
                                <?php else: ?>
                                <div class="settings-row">
                                    <label>当前状态</label>
                                    <span style="color:var(--warning-color)"><i class="fas fa-exclamation-circle"></i> 未生成 Token</span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="settings-row" style="gap:8px">
                                <button class="btn btn-primary" data-action="generate-api-token" style="flex:1"><i class="fas fa-key" style="margin-right:4px"></i>生成新 Token</button>
                                <button class="btn btn-glass btn-sm" data-action="revoke-api-token" id="revokeTokenBtn" style="<?php echo $config->get('api_token') ? '' : 'display:none'; ?>"><i class="fas fa-ban"></i> 撤销</button>
                            </div>
                            <small>Token 仅在生成时显示一次，请妥善保管</small>

                            <!-- Token 显示区域（生成后临时显示） -->
                            <div id="apiTokenDisplay" style="display:none;margin-top:16px">
                                <div style="background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:8px;padding:16px;position:relative">
                                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                                        <strong style="color:var(--warning-color)"><i class="fas fa-exclamation-triangle"></i> 请立即复制，此 Token 仅显示一次</strong>
                                        <button class="btn btn-glass btn-sm" data-action="copy-api-token"><i class="fas fa-copy"></i> 复制</button>
                                    </div>
                                    <code id="apiTokenValue" style="word-break:break-all;font-size:13px;display:block;padding:8px;background:var(--bg-primary);border-radius:4px"></code>
                                </div>
                            </div>
                        </div>

                        <div class="settings-card glass" id="apiRateSection" style="<?php echo $config->get('api_enabled') ? '' : 'display:none'; ?>">
                            <h3><i class="fas fa-tachometer-alt" style="margin-right:8px"></i>速率限制</h3>
                            <div class="settings-row">
                                <label>请求限制（次/窗口）</label>
                                <input type="number" id="cfg_api_rate_limit" value="<?php echo $config->get('api_rate_limit', 60); ?>" min="1" max="1000" placeholder="1-1000">
                            </div>
                            <div class="settings-row">
                                <label>时间窗口（秒）</label>
                                <input type="number" id="cfg_api_rate_window" value="<?php echo $config->get('api_rate_window', 60); ?>" min="10" max="3600" placeholder="10-3600">
                            </div>
                            <button class="btn btn-primary" data-action="save-api-config"><i class="fas fa-save" style="margin-right:4px"></i>保存设置</button>
                        </div>

                        <div class="settings-card glass" id="apiDocSection" style="<?php echo $config->get('api_enabled') ? '' : 'display:none'; ?>">
                            <h3><i class="fas fa-book" style="margin-right:8px"></i>API 调用文档</h3>
                            <div class="api-doc">
                                <div class="api-doc-section">
                                    <h4>认证方式</h4>
                                    <p>所有 API 请求需在 Header 中携带 Bearer Token：</p>
                                    <pre><code>Authorization: Bearer &lt;your_token&gt;</code></pre>
                                    <p>或通过 URL 参数传递：</p>
                                    <pre><code>openapi.php?action=list_files&amp;token=&lt;your_token&gt;</code></pre>
                                </div>

                                <div class="api-doc-section">
                                    <h4>基础 URL</h4>
                                    <pre><code id="apiBaseUrl"><?php echo Security::escape((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . ':' . ($_SERVER['SERVER_PORT'] ?? 80)); ?>/openapi.php</code></pre>
                                </div>

                                <div class="api-doc-section">
                                    <h4>文件操作</h4>
                                    <table class="api-doc-table">
                                        <thead><tr><th>操作</th><th>action</th><th>方法</th><th>参数</th></tr></thead>
                                        <tbody>
                                            <tr><td>文件列表</td><td>list_files</td><td>GET</td><td>parent_id, sort_by, sort_order, page, page_size</td></tr>
                                            <tr><td>文件详情</td><td>file_info</td><td>GET</td><td>file_id</td></tr>
                                            <tr><td>搜索文件</td><td>search</td><td>GET</td><td>keyword, type, page</td></tr>
                                            <tr><td>创建文件夹</td><td>create_folder</td><td>POST</td><td>parent_id, folder_name</td></tr>
                                            <tr><td>重命名</td><td>rename</td><td>POST</td><td>file_id, new_name</td></tr>
                                            <tr><td>移动文件</td><td>move</td><td>POST</td><td>file_id, target_parent_id</td></tr>
                                            <tr><td>复制文件</td><td>copy</td><td>POST</td><td>file_id, target_parent_id</td></tr>
                                            <tr><td>删除文件</td><td>delete</td><td>POST</td><td>file_id</td></tr>
                                            <tr><td>批量删除</td><td>batch_delete</td><td>POST</td><td>ids (JSON数组)</td></tr>
                                            <tr><td>下载文件</td><td>download</td><td>GET</td><td>file_id</td></tr>
                                            <tr><td>缩略图</td><td>thumbnail</td><td>GET</td><td>file_id</td></tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="api-doc-section">
                                    <h4>上传文件</h4>
                                    <table class="api-doc-table">
                                        <thead><tr><th>操作</th><th>action</th><th>方法</th><th>参数</th></tr></thead>
                                        <tbody>
                                            <tr><td>上传文件</td><td>upload</td><td>POST</td><td>parent_id, file (multipart)</td></tr>
                                            <tr><td>分片上传</td><td>upload_chunk</td><td>POST</td><td>task_id, chunk_index, chunk (multipart)</td></tr>
                                            <tr><td>获取已上传分片</td><td>get_uploaded_chunks</td><td>GET</td><td>task_id</td></tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="api-doc-section">
                                    <h4>分享管理</h4>
                                    <table class="api-doc-table">
                                        <thead><tr><th>操作</th><th>action</th><th>方法</th><th>参数</th></tr></thead>
                                        <tbody>
                                            <tr><td>创建分享</td><td>create_share</td><td>POST</td><td>file_id, expire_days, password(可选), max_downloads(可选)</td></tr>
                                            <tr><td>分享列表</td><td>list_shares</td><td>GET</td><td>page</td></tr>
                                            <tr><td>删除分享</td><td>delete_share</td><td>POST</td><td>share_id</td></tr>
                                            <tr><td>切换分享状态</td><td>toggle_share</td><td>POST</td><td>share_id</td></tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="api-doc-section">
                                    <h4>回收站</h4>
                                    <table class="api-doc-table">
                                        <thead><tr><th>操作</th><th>action</th><th>方法</th><th>参数</th></tr></thead>
                                        <tbody>
                                            <tr><td>回收站列表</td><td>list_trash</td><td>GET</td><td>page</td></tr>
                                            <tr><td>恢复文件</td><td>restore</td><td>POST</td><td>trash_id</td></tr>
                                            <tr><td>永久删除</td><td>permanent_delete</td><td>POST</td><td>trash_id</td></tr>
                                            <tr><td>清空回收站</td><td>empty_trash</td><td>POST</td><td>-</td></tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="api-doc-section">
                                    <h4>调用示例</h4>
                                    <p><strong>curl 获取文件列表：</strong></p>
                                    <pre><code>curl -H "Authorization: Bearer &lt;token&gt;" \
  "<?php echo Security::escape((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . ':' . ($_SERVER['SERVER_PORT'] ?? 80)); ?>/openapi.php?action=list_files&parent_id=0"</code></pre>

                                    <p><strong>curl 创建文件夹：</strong></p>
                                    <pre><code>curl -X POST -H "Authorization: Bearer &lt;token&gt;" \
  -H "Content-Type: application/json" \
  -d '{"parent_id":0,"folder_name":"新文件夹"}' \
  "<?php echo Security::escape((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . ':' . ($_SERVER['SERVER_PORT'] ?? 80)); ?>/openapi.php?action=create_folder"</code></pre>

                                    <p><strong>curl 上传文件：</strong></p>
                                    <pre><code>curl -X POST -H "Authorization: Bearer &lt;token&gt;" \
  -F "file=@/path/to/file.txt" \
  -F "parent_id=0" \
  "<?php echo Security::escape((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . ':' . ($_SERVER['SERVER_PORT'] ?? 80)); ?>/openapi.php?action=upload"</code></pre>

                                    <p><strong>Python 示例：</strong></p>
                                    <pre><code>import requests

BASE_URL = "http://your-server/openapi.php"
TOKEN = "your_token_here"
HEADERS = {"Authorization": f"Bearer {TOKEN}"}

# 获取文件列表
resp = requests.get(f"{BASE_URL}?action=list_files&parent_id=0", headers=HEADERS)
print(resp.json())

# 创建文件夹
resp = requests.post(f"{BASE_URL}?action=create_folder", headers=HEADERS, json={"parent_id": 0, "folder_name": "新文件夹"})
print(resp.json())</code></pre>
                                </div>
                            </div>
                        </div>
                    </div>
