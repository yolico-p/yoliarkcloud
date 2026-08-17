                    <div id="settingsTabUpload" class="settings-tab-content">
                        <div class="settings-card glass">
                            <h3>上传配置</h3>
                            <div class="settings-row">
                                <label>最大上传文件大小</label>
                                <select id="cfg_max_upload_size">
                                    <option value="104857600" <?php echo $config->get('max_upload_size') === 104857600 ? 'selected' : ''; ?>>100 MB</option>
                                    <option value="209715200" <?php echo $config->get('max_upload_size') === 209715200 ? 'selected' : ''; ?>>200 MB</option>
                                    <option value="524288000" <?php echo $config->get('max_upload_size') === 524288000 ? 'selected' : ''; ?>>500 MB</option>
                                    <option value="1073741824" <?php echo $config->get('max_upload_size') === 1073741824 ? 'selected' : ''; ?>>1 GB</option>
                                    <option value="2147483648" <?php echo $config->get('max_upload_size') === 2147483648 ? 'selected' : ''; ?>>2 GB</option>
                                </select>
                            </div>
                            <div class="settings-row">
                                <label>分片上传大小</label>
                                <select id="cfg_chunk_size">
                                    <option value="1048576" <?php echo $config->get('chunk_size') === 1048576 ? 'selected' : ''; ?>>1 MB</option>
                                    <option value="5242880" <?php echo $config->get('chunk_size') === 5242880 ? 'selected' : ''; ?>>5 MB</option>
                                    <option value="10485760" <?php echo $config->get('chunk_size') === 10485760 ? 'selected' : ''; ?>>10 MB</option>
                                    <option value="20971520" <?php echo $config->get('chunk_size') === 20971520 ? 'selected' : ''; ?>>20 MB</option>
                                </select>
                            </div>
                            <button class="btn btn-primary" data-action="save-config"><i class="fas fa-save"></i> 保存设置</button>
                        </div>
                        <div class="settings-card glass">
                            <h3>上传文件类型限制</h3>
                            <div class="settings-notice" style="padding:10px 14px;background:var(--accent-warning-bg, #fff4ce);border:1px solid var(--accent-warning, #986f0b);border-radius:8px;font-size:13px;color:var(--accent-warning, #986f0b);margin-bottom:16px;display:flex;align-items:center;gap:8px">
                                <i class="fas fa-shield-halved"></i>
                                <span>仅黑名单模式，不在列表中的扩展名均可上传。修改需输入当前登录密码确认。</span>
                            </div>
                            <div class="settings-row">
                                <label>禁止上传的扩展名</label>
                                <textarea id="cfg_blocked_extensions" rows="3" style="width:100%;padding:8px 12px;border:1px solid var(--border-color,#e5e7eb);border-radius:8px;font-size:13px;font-family:monospace;background:var(--bg-surface);color:var(--text-primary);resize:vertical"><?php echo implode(' ', $config->get('blocked_extensions')); ?></textarea>
                                <small>空格分隔，在此列表中的扩展名将被拒绝上传</small>
                            </div>
                            <div class="settings-row">
                                <label>当前密码（确认身份）</label>
                                <input type="password" id="cfg_blocked_password" style="width:100%;padding:10px 12px;border:1px solid var(--border-color,#e5e7eb);border-radius:8px;font-size:14px;background:var(--bg-surface);color:var(--text-primary)" placeholder="输入当前登录密码以确认修改">
                            </div>
                            <button class="btn btn-primary" data-action="save-blocked-extensions"><i class="fas fa-save"></i> 保存黑名单</button>
                        </div>
                        <div class="settings-card glass">
                            <h3>回收站配置</h3>
                            <div class="settings-row">
                                <label>回收站保留天数</label>
                                <input type="number" id="cfg_trash_retention_days" value="<?php echo $config->get('trash_retention_days'); ?>" min="1" max="90" placeholder="1-90">
                                <small>文件删除后在回收站中保留的天数，到期自动清理</small>
                            </div>
                            <button class="btn btn-primary" data-action="save-config"><i class="fas fa-save"></i> 保存设置</button>
                        </div>
                    </div>
