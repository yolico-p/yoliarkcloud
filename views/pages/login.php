<?php
use App\Core\Security;
?>
<div class="login-scene">
    <div class="login-card glass-strong">
        <div class="login-left-panel">
            <div class="login-brand">
                <div class="login-brand-icon"><i class="fas fa-cloud"></i></div>
                <h1><?php echo Security::escape($config->get('app_name')); ?></h1>
            </div>
            <p>刚好够用</p>
            <div class="login-features">
                <div class="login-feature-item">
                    <i class="fas fa-check"></i>
                    <span>存文件，管文件，找文件</span>
                </div>
                <div class="login-feature-item">
                    <i class="fas fa-check"></i>
                    <span>一条链接完成分享</span>
                </div>
                <div class="login-feature-item">
                    <i class="fas fa-check"></i>
                    <span>浏览器即客户端</span>
                </div>
            </div>
        </div>

        <div class="login-right-panel">
            <h2 class="login-form-title">欢迎回来</h2>
            <p class="login-form-subtitle">登录您的账户继续</p>
            
            <form id="loginForm" class="login-form">
                <div class="form-group">
                    <label for="username">用户名</label>
                    <input type="text" id="username" name="username" required autocomplete="username" placeholder="请输入用户名">
                </div>
                <div class="form-group">
                    <label for="password">密码</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="请输入密码">
                </div>
                <div id="loginError" class="error-message" style="display:none"></div>
                <button type="submit" class="btn btn-primary btn-block" id="loginBtn">登 录</button>
            </form>

            <div class="forgot-link-wrap">
                <a href="#" id="forgotPwdLink" class="forgot-link">忘记密码？</a>
            </div>
        </div>
    </div>
</div>

<!-- ── 忘记密码 - 输入邮箱弹窗 ── -->
<div class="modal-overlay" id="forgotEmailModal">
    <div class="modal-box login-modal-box">
        <div class="modal-header">
            <h3 class="modal-title">找回密码</h3>
            <button class="modal-close" data-action="close-forgot-email"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="forgotEmail">注册邮箱</label>
                <input type="email" id="forgotEmail" required placeholder="请输入您的注册邮箱">
            </div>
            <div id="forgotEmailError" class="error-message" style="display:none"></div>
            <button class="btn btn-primary btn-block" id="forgotEmailBtn" data-action="send-forgot-code">发送验证码</button>
            <p class="forgot-tip">验证码将发送到您的邮箱，5分钟内有效</p>
        </div>
    </div>
</div>

<!-- ── 忘记密码 - 输入验证码+新密码弹窗 ── -->
<div class="modal-overlay" id="forgotResetModal">
    <div class="modal-box login-modal-box">
        <div class="modal-header">
            <h3 class="modal-title">重置密码</h3>
            <button class="modal-close" data-action="close-forgot-reset"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div id="forgotEmailDisplay" style="font-size:0.85rem;color:var(--text-muted);margin-bottom:1rem"></div>
            <div class="form-group">
                <label for="forgotCode">验证码</label>
                <input type="text" id="forgotCode" maxlength="8" required placeholder="请输入8位数字验证码" style="letter-spacing:4px;font-size:1.2rem;font-weight:700;text-align:center">
            </div>
            <div class="form-group">
                <label for="forgotNewPwd">新密码</label>
                <input type="password" id="forgotNewPwd" required minlength="8" placeholder="至少8位">
            </div>
            <div class="form-group">
                <label for="forgotConfirmPwd">确认密码</label>
                <input type="password" id="forgotConfirmPwd" required minlength="8" placeholder="再次输入新密码">
            </div>
            <div id="forgotResetError" class="error-message" style="display:none"></div>
            <button class="btn btn-primary btn-block" id="forgotResetBtn" data-action="submit-forgot-reset">重置密码</button>
            <p class="forgot-tip">验证通过后密码将立即更新</p>
        </div>
    </div>
</div>
