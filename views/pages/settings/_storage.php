                    <div id="settingsTabStorage" class="settings-tab-content">
                        <div class="settings-card glass">
                            <h3>服务器磁盘状态</h3>
                            <div class="storage-status-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px">
                                <div class="storage-stat-item" style="text-align:center;padding:16px;background:var(--bg-elevated);border-radius:var(--radius-md);border:1px solid var(--border-color)">
                                    <div style="font-size:12px;color:var(--text-secondary);margin-bottom:6px">总空间</div>
                                    <div style="font-size:18px;font-weight:600;color:var(--text-primary)" id="storageTotalSpace">-</div>
                                </div>
                                <div class="storage-stat-item" style="text-align:center;padding:16px;background:var(--bg-elevated);border-radius:var(--radius-md);border:1px solid var(--border-color)">
                                    <div style="font-size:12px;color:var(--text-secondary);margin-bottom:6px">剩余空间</div>
                                    <div style="font-size:18px;font-weight:600;color:var(--success-color)" id="storageFreeSpace">-</div>
                                </div>
                                <div class="storage-stat-item" style="text-align:center;padding:16px;background:var(--bg-elevated);border-radius:var(--radius-md);border:1px solid var(--border-color)">
                                    <div style="font-size:12px;color:var(--text-secondary);margin-bottom:6px">使用率</div>
                                    <div style="font-size:18px;font-weight:600;color:var(--warning-color)" id="storageUsagePercent">-</div>
                                </div>
                            </div>
                            <button class="btn btn-glass" data-action="load-disk-info"><i class="fas fa-sync-alt"></i> 刷新磁盘信息</button>
                        </div>
                        <div class="settings-card glass">
                            <h3>当前网盘限额</h3>
                            <div class="settings-row">
                                <label>当前存储限额</label>
                                <div id="currentStorageLimit" style="font-size:16px;font-weight:600;color:var(--primary-color)">-</div>
                            </div>
                            <div class="settings-row">
                                <label>已使用</label>
                                <div id="currentStorageUsed" style="font-size:14px;color:var(--text-secondary)">-</div>
                            </div>
                            <div class="settings-row">
                                <label>可用剩余</label>
                                <div id="currentStorageRemaining" style="font-size:14px;color:var(--success-color)">-</div>
                            </div>
                            <div class="settings-row">
                                <label>最后更新时间</label>
                                <div id="lastUpdateTime" style="font-size:13px;color:var(--text-muted)">-</div>
                            </div>
                        </div>
                        <div class="settings-card glass">
                            <h3>自动调整设置</h3>
                            <div class="settings-row">
                                <label>服务器预留空间（MB）</label>
                                <input type="number" id="cfg_storage_reserve_mb" value="500" min="100" max="10240" placeholder="100-10240">
                                <small>为服务器其他程序预留的磁盘空间（默认 500MB）</small>
                            </div>
                            <div class="settings-row">
                                <label>更新变化阈值（%）</label>
                                <input type="number" id="cfg_storage_update_threshold" value="1" min="0.1" max="50" step="0.1" placeholder="0.1-50">
                                <small>只有当计算出的限额与当前限额差异超过此百分比时才会更新</small>
                            </div>
                            <button class="btn btn-primary" data-action="save-storage-settings"><i class="fas fa-save"></i> 保存设置</button>
                        </div>
                        <div class="settings-card glass">
                            <h3>手动调整</h3>
                            <p class="settings-hint" style="margin-bottom:12px">点击以下按钮将根据当前磁盘状态和设置的参数，立即重新计算并更新存储限额</p>
                            <button class="btn btn-glass" data-action="manual-update-storage"><i class="fas fa-calculator"></i> 立即重新计算限额</button>
                        </div>
                    </div>
