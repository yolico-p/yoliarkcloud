<?php
use App\Core\Security;
?>
                    <div id="settingsTabAccount" class="settings-tab-content">
                        <div class="settings-card glass">
                            <h3>个人资料</h3>
                            <form id="profileForm">
                                <div class="settings-row">
                                    <label>用户名</label>
                                    <input type="text" value="<?php echo Security::escape($user['username'] ?? ''); ?>" disabled>
                                </div>
                                <div class="settings-row">
                                    <label>邮箱</label>
                                    <input type="email" id="settingEmail" value="<?php echo Security::escape($user['email'] ?? ''); ?>" placeholder="输入邮箱地址">
                                </div>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> 保存修改</button>
                            </form>
                        </div>
                        <div class="settings-card glass">
                            <h3>修改密码</h3>
                            <form id="passwordForm">
                                <div class="settings-row">
                                    <label>当前密码</label>
                                    <input type="password" id="oldPassword" required placeholder="输入当前密码">
                                </div>
                                <div class="settings-row">
                                    <label>新密码</label>
                                    <input type="password" id="newPassword" required placeholder="输入新密码（至少8位）">
                                </div>
                                <div class="settings-row">
                                    <label>确认密码</label>
                                    <input type="password" id="confirmPassword" required placeholder="再次输入新密码">
                                </div>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-key"></i> 修改密码</button>
                            </form>
                        </div>
                    </div>
