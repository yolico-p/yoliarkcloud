                    <div id="settingsTabSecurity" class="settings-tab-content">
                        <div class="settings-card glass">
                            <h3>登录安全</h3>
                            <div class="settings-row">
                                <label>最大登录尝试次数</label>
                                <input type="number" id="cfg_login_max_attempts" value="<?php echo $config->get('login_max_attempts'); ?>" min="1" max="20" placeholder="1-20">
                            </div>
                            <div class="settings-row">
                                <label>锁定时间（秒）</label>
                                <input type="number" id="cfg_login_lockout_time" value="<?php echo $config->get('login_lockout_time'); ?>" min="60" max="3600" step="60" placeholder="60-3600">
                            </div>
                            <div class="settings-row">
                                <label>密码最小长度</label>
                                <input type="number" id="cfg_password_min_length" value="<?php echo $config->get('password_min_length'); ?>" min="4" max="32" placeholder="4-32">
                            </div>
                            <button class="btn btn-primary" data-action="save-config"><i class="fas fa-save"></i> 保存设置</button>
                        </div>
                        <div class="settings-card glass">
                            <h3>速率限制</h3>
                            <div class="settings-row">
                                <label>下载速率限制（次/分钟）</label>
                                <input type="number" id="cfg_download_rate_limit" value="<?php echo $config->get('download_rate_limit'); ?>" min="1" max="100" placeholder="1-100">
                            </div>
                            <div class="settings-row">
                                <label>下载时间窗口（秒）</label>
                                <input type="number" id="cfg_download_rate_window" value="<?php echo $config->get('download_rate_window'); ?>" min="10" max="600" placeholder="10-600">
                            </div>
                            <div class="settings-row">
                                <label>删除速率限制（次/分钟）</label>
                                <input type="number" id="cfg_delete_rate_limit" value="<?php echo $config->get('delete_rate_limit'); ?>" min="1" max="100" placeholder="1-100">
                            </div>
                            <div class="settings-row">
                                <label>删除时间窗口（秒）</label>
                                <input type="number" id="cfg_delete_rate_window" value="<?php echo $config->get('delete_rate_window'); ?>" min="10" max="600" placeholder="10-600">
                            </div>
                            <button class="btn btn-primary" data-action="save-config"><i class="fas fa-save"></i> 保存设置</button>
                        </div>
                    </div>
