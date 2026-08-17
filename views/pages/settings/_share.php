<?php use App\Core\Security; ?>
                    <div id="settingsTabShare" class="settings-tab-content">
                        <div class="settings-card glass">
                            <h3><i class="fas fa-inbox" style="margin-right:8px"></i>文件信箱</h3>
                            <div class="settings-row">
                                <label>启用文件信箱</label>
                                <div class="toggle-switch">
                                    <input type="checkbox" id="cfg_inbox_enabled" <?php echo $config->get('inbox_enabled') ? 'checked' : ''; ?>>
                                    <label for="cfg_inbox_enabled"><span></span></label>
                                </div>
                            </div>
                            <small>开启后，左侧边栏会出现「文件信箱」入口，朋友可通过专属链接向你投递文件，无需登录</small>
                            <div class="settings-row">
                                <label>信箱链接</label>
                                <div style="display:flex;gap:8px;flex:1">
                                    <input type="text" id="cfg_inbox_url" value="<?php echo Security::escape($config->get('inbox_url') ?? ''); ?>" readonly placeholder="开启并保存后生成链接">
                                    <button class="btn btn-glass btn-sm" data-action="copy-inbox-url" id="cfgInboxCopyBtn" style="display:none"><i class="fas fa-copy"></i> 复制</button>
                                    <button class="btn btn-primary" data-action="save-inbox-settings"><i class="fas fa-save" style="margin-right:4px"></i>保存设置</button>
                                </div>
                            </div>
                        </div>

                        <div class="settings-card glass">
                            <h3>分享设置</h3>
                            <div class="settings-row">
                                <label>默认过期时间（天）</label>
                                <input type="number" id="cfg_share_default_expire" value="<?php echo $config->get('share_default_expire') / 86400; ?>" min="0" max="365" placeholder="0-365，0为永久">
                                <small>0 表示永不过期</small>
                            </div>
                            <div class="settings-row">
                                <label>分享链接长度</label>
                                <input type="number" id="cfg_share_link_length" value="<?php echo $config->get('share_link_length'); ?>" min="6" max="20" placeholder="6-20">
                            </div>
                            <button class="btn btn-primary" data-action="save-config"><i class="fas fa-save"></i> 保存设置</button>
                        </div>
                    </div>
