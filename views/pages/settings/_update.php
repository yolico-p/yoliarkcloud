<?php use App\Core\Security; ?>
                    <div id="settingsTabUpdate" class="settings-tab-content">
                        <!-- 版本信息网格 -->
                        <div class="update-version-grid">
                            <div class="update-version-box">
                                <div class="update-version-label"><i class="fas fa-tag"></i> 当前版本</div>
                                <div class="update-version-value" id="updateCurrentVersion">-</div>
                            </div>
                            <div class="update-version-box">
                                <div class="update-version-label"><i class="fas fa-rocket"></i> 最新版本</div>
                                <div class="update-version-value" id="updateLatestVersion">-</div>
                            </div>
                            <div class="update-version-box">
                                <div class="update-version-label"><i class="fas fa-info-circle"></i> 更新状态</div>
                                <div class="update-version-value update-status-active" id="updateStatusBadge">空闲</div>
                            </div>
                            <div class="update-version-box">
                                <div class="update-version-label"><i class="fas fa-clock"></i> 最后检查</div>
                                <div class="update-version-value" id="updateLastCheckTime">从未检查</div>
                            </div>
                        </div>

                        <!-- 更新配置卡片 -->
                        <div class="settings-card glass">
                            <h3><i class="fas fa-cog"></i> 更新配置</h3>
                            <div class="settings-row">
                                <label>启用自动更新</label>
                                <div class="toggle-switch">
                                    <input type="checkbox" id="cfg_update_enabled">
                                    <label for="cfg_update_enabled"><span></span></label>
                                </div>
                                <small>开启后系统将按设定间隔自动检查并应用更新</small>
                            </div>
                            <div class="settings-row">
                                <label>更新渠道</label>
                                <select id="cfg_update_channel">
                                    <option value="stable">稳定版（推荐）</option>
                                    <option value="beta">测试版</option>
                                    <option value="lts">长期支持版（LTS）</option>
                                </select>
                                <small>稳定版经过完整测试；测试版包含最新特性但可能不稳定</small>
                            </div>
                            <div class="settings-row">
                                <label>检查间隔（秒）</label>
                                <input type="number" id="cfg_update_check_interval" min="3600" step="60" value="3600" placeholder="最小 3600">
                                <small>自动检查更新的时间间隔，最小 3600 秒（1 小时）</small>
                            </div>
                            <div class="settings-row">
                                <label>更新策略</label>
                                <select id="cfg_update_strategy">
                                    <option value="notify_only">仅通知</option>
                                    <option value="auto_apply">自动应用</option>
                                </select>
                                <small>「仅通知」发现新版本后提示用户手动更新；「自动应用」将自动下载并安装</small>
                            </div>
                            <div class="settings-row">
                                <label>更新源地址</label>
                                <input type="text" id="updateSourceUrl" value="https://yoliarkupdate.yoliark.com/" readonly>
                                <small>更新源由系统统一管理，不可修改</small>
                            </div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <button class="btn btn-primary" data-action="save-update-config"><i class="fas fa-save"></i> 保存配置</button>
                                <button class="btn btn-glass" data-action="check-update-now"><i class="fas fa-search"></i> 立即检查更新</button>
                                <button class="btn btn-glass" data-action="refresh-update-status"><i class="fas fa-sync-alt"></i> 刷新状态</button>
                            </div>
                        </div>

                        <!-- 可用更新卡片 -->
                        <div class="settings-card glass" id="updateAvailableCard" style="display:none">
                            <div class="update-available-header">
                                <div class="update-available-version">
                                    <div class="update-available-label">发现新版本</div>
                                    <div class="update-available-num" id="updateAvailableVersion">-</div>
                                </div>
                                <div class="update-available-badges" id="updateAvailableBadges"></div>
                            </div>
                            <div class="update-available-meta">
                                <span><i class="fas fa-weight-hanging"></i> <span id="updateAvailableSize">-</span></span>
                                <span><i class="fas fa-calendar-alt"></i> <span id="updateAvailableTime">-</span></span>
                                <span><i class="fas fa-server"></i> PHP <span id="updateAvailablePhp">-</span></span>
                            </div>
                            <div class="update-features-title"><i class="fas fa-star"></i> 新特性</div>
                            <ul class="update-features-list" id="updateFeaturesList"></ul>
                            <details class="update-release-notes">
                                <summary><i class="fas fa-file-alt"></i> 查看发布说明</summary>
                                <div class="update-release-notes-body" id="updateReleaseNotesBody"></div>
                            </details>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px">
                                <button class="btn btn-primary" data-action="apply-update"><i class="fas fa-cloud-download-alt"></i> 立即更新</button>
                                <button class="btn btn-glass" data-action="dismiss-update"><i class="fas fa-clock"></i> 稍后</button>
                            </div>
                        </div>

                        <!-- 更新进度卡片 -->
                        <div class="settings-card glass" id="updateProgressCard" style="display:none">
                            <h3><i class="fas fa-spinner fa-pulse"></i> 更新进度</h3>
                            <div class="update-progress-stage" id="updateProgressStage">正在启动更新...</div>
                            <div class="update-progress-bar-wrap">
                                <div class="update-progress-bar" id="updateProgressBar" style="width:0%"></div>
                            </div>
                            <div class="update-progress-detail" id="updateProgressDetail"></div>
                        </div>

                        <!-- 失败卡片 -->
                        <div class="settings-card glass update-failed-card" id="updateFailedCard" style="display:none">
                            <h3><i class="fas fa-exclamation-triangle" style="color:var(--accent-danger)"></i> 更新失败</h3>
                            <div class="update-failed-message" id="updateFailedMessage">更新失败，请稍后重试</div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px">
                                <button class="btn btn-primary" data-action="apply-update"><i class="fas fa-redo"></i> 重试更新</button>
                                <button class="btn btn-glass" data-action="rollback-update"><i class="fas fa-undo"></i> 回滚到备份</button>
                                <button class="btn btn-glass" data-action="force-rollback-update"><i class="fas fa-exclamation-circle"></i> 强制回滚</button>
                                <button class="btn btn-glass" data-action="clear-update-failed"><i class="fas fa-eraser"></i> 清除失败状态</button>
                            </div>
                        </div>

                        <!-- 备份列表卡片 -->
                        <div class="settings-card glass">
                            <h3><i class="fas fa-archive"></i> 备份列表</h3>
                            <div class="update-backups-list" id="updateBackupsList">
                                <div class="update-empty"><i class="fas fa-archive"></i><span>加载中...</span></div>
                            </div>
                        </div>

                        <!-- 更新历史卡片 -->
                        <div class="settings-card glass">
                            <h3 style="display:flex;align-items:center;justify-content:space-between">
                                <span><i class="fas fa-history"></i> 更新历史</span>
                                <button class="btn btn-glass btn-sm" data-action="show-update-history"><i class="fas fa-sync-alt"></i> 刷新</button>
                            </h3>
                            <div class="update-history-list" id="updateHistoryList">
                                <div class="update-empty"><i class="fas fa-history"></i><span>加载中...</span></div>
                            </div>
                        </div>

                        <!-- 危险操作 -->
                        <div class="settings-card glass">
                            <h3><i class="fas fa-tools"></i> 高级操作</h3>
                            <small>如果更新子系统异常，可尝试重置以恢复初始状态</small>
                            <div style="margin-top:12px">
                                <button class="btn btn-danger" data-action="reset-update-subsystem"><i class="fas fa-redo-alt"></i> 重置更新子系统</button>
                            </div>
                        </div>
                    </div>
