<script>
// ── 登录表单 ─────────────────────────────────
document.getElementById('loginForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var btn = document.getElementById('loginBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> 登录中...';
    document.getElementById('loginError').style.display = 'none';

    api('login', {
        username: document.getElementById('username').value,
        password: document.getElementById('password').value,
    }).then(function(data) {
        if (data.success) {
            window.location.href = 'index.php';
        } else {
            document.getElementById('loginError').textContent = data.message;
            document.getElementById('loginError').style.display = 'block';
            btn.disabled = false;
            btn.textContent = '登 录';
        }
    }).catch(function(err) {
        document.getElementById('loginError').textContent = '网络错误，请稍后重试';
        document.getElementById('loginError').style.display = 'block';
        btn.disabled = false;
        btn.textContent = '登 录';
    });
});

// ── 忘记密码 - 邮箱弹窗 ─────────────────────
var forgotEmail = '';

document.getElementById('forgotPwdLink').addEventListener('click', function(e) {
    e.preventDefault();
    document.getElementById('forgotEmail').value = '';
    document.getElementById('forgotEmailError').style.display = 'none';
    document.getElementById('forgotEmailBtn').disabled = false;
    document.getElementById('forgotEmailBtn').textContent = '发送验证码';
    document.getElementById('forgotEmailModal').classList.add('active');
});

function closeForgotEmail() {
    document.getElementById('forgotEmailModal').classList.remove('active');
}

function sendForgotCode() {
    var email = document.getElementById('forgotEmail').value.trim();
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        showForgotEmailError('请输入有效的邮箱地址');
        return;
    }

    var btn = document.getElementById('forgotEmailBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> 发送中...';
    document.getElementById('forgotEmailError').style.display = 'none';

    api('forgot_password', { email: email }).then(function(data) {
        if (data.success) {
            forgotEmail = email;
            closeForgotEmail();
            openForgotReset(email);
        } else {
            btn.disabled = false;
            btn.textContent = '发送验证码';
            showForgotEmailError(data.message || '发送失败，请稍后重试');
        }
    }).catch(function(err) {
        btn.disabled = false;
        btn.textContent = '发送验证码';
        showForgotEmailError('网络错误，请稍后重试');
    });
}

function showForgotEmailError(msg) {
    var el = document.getElementById('forgotEmailError');
    el.textContent = msg;
    el.style.display = 'block';
}

// ── 忘记密码 - 重置弹窗 ─────────────────────
function openForgotReset(email) {
    document.getElementById('forgotEmailDisplay').textContent = '验证码已发送至 ' + email;
    document.getElementById('forgotCode').value = '';
    document.getElementById('forgotNewPwd').value = '';
    document.getElementById('forgotConfirmPwd').value = '';
    document.getElementById('forgotResetError').style.display = 'none';
    document.getElementById('forgotResetBtn').disabled = false;
    document.getElementById('forgotResetBtn').textContent = '重置密码';
    document.getElementById('forgotResetModal').classList.add('active');
}

function closeForgotReset() {
    document.getElementById('forgotResetModal').classList.remove('active');
}

function submitForgotReset() {
    var code = document.getElementById('forgotCode').value.trim();
    var newPwd = document.getElementById('forgotNewPwd').value;
    var confirmPwd = document.getElementById('forgotConfirmPwd').value;

    if (!code || !/^\d{8}$/.test(code)) {
        showForgotResetError('请输入8位数字验证码');
        return;
    }
    if (!newPwd || newPwd.length < 8) {
        showForgotResetError('新密码长度不能少于8位');
        return;
    }
    if (newPwd !== confirmPwd) {
        showForgotResetError('两次输入的密码不一致');
        return;
    }

    var btn = document.getElementById('forgotResetBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> 重置中...';
    document.getElementById('forgotResetError').style.display = 'none';

    api('verify_reset', {
        email: forgotEmail,
        code: code,
        password: newPwd,
    }).then(function(data) {
        if (data.success) {
            closeForgotReset();
            showLoginSuccess('密码修改成功，请使用新密码登录');
        } else {
            btn.disabled = false;
            btn.textContent = '重置密码';
            showForgotResetError(data.message || '验证失败，请重试');
        }
    }).catch(function(err) {
        btn.disabled = false;
        btn.textContent = '重置密码';
        showForgotResetError('网络错误，请稍后重试');
    });
}

function showForgotResetError(msg) {
    var el = document.getElementById('forgotResetError');
    el.textContent = msg;
    el.style.display = 'block';
}

function showLoginSuccess(msg) {
    var el = document.getElementById('loginError');
    el.style.color = 'var(--accent-success)';
    el.style.background = '#ecfdf5';
    el.style.borderColor = '#a7f3d0';
    el.textContent = msg;
    el.style.display = 'block';
}

// ── 模态框事件委托 ─────────────────────────
(function() {
    var emailModal = document.getElementById('forgotEmailModal');
    if (emailModal) {
        emailModal.addEventListener('click', function(e) {
            if (e.target === emailModal) { closeForgotEmail(); return; }
            var btn = e.target.closest('[data-action]');
            if (!btn) return;
            var action = btn.dataset.action;
            if (action === 'close-forgot-email') closeForgotEmail();
            else if (action === 'send-forgot-code') sendForgotCode();
        });
    }

    var resetModal = document.getElementById('forgotResetModal');
    if (resetModal) {
        resetModal.addEventListener('click', function(e) {
            if (e.target === resetModal) { closeForgotReset(); return; }
            var btn = e.target.closest('[data-action]');
            if (!btn) return;
            var action = btn.dataset.action;
            if (action === 'close-forgot-reset') closeForgotReset();
            else if (action === 'submit-forgot-reset') submitForgotReset();
        });
    }
})();
</script>
