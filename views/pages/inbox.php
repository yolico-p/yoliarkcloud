<?php
use App\Core\Security;
use App\Core\Config;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title><?php echo Security::escape($inboxData['app_name'] ?? '柚舟Cloud'); ?> - 文件信箱</title>
    <link rel="stylesheet" href="assets/css/base.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/components.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/fluent-share.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/dark-theme.css?v=<?php echo $pageBuildHash; ?>">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css?v=<?php echo $pageBuildHash; ?>">
    <style>
    .inbox-upload-page { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
    .inbox-upload-card { width:100%; max-width:520px; }
    .inbox-upload-card .fluent-share-card { padding:40px 36px; }
    .inbox-dropzone { border:2px dashed var(--border-color); border-radius:var(--radius-lg); padding:48px 24px; text-align:center; cursor:pointer; transition:var(--transition-smooth); background:var(--bg-secondary); margin:20px 0; }
    .inbox-dropzone:hover, .inbox-dropzone.dragover { border-color:var(--accent-primary); background:rgba(79,70,229,0.04); }
    .inbox-dropzone .dz-icon { font-size:42px; color:var(--text-muted); margin-bottom:16px; }
    .inbox-dropzone .dz-text { font-size:14px; color:var(--text-secondary); }
    .inbox-dropzone .dz-text strong { color:var(--accent-primary); }
    .inbox-sender-fields { display:flex; flex-direction:column; gap:12px; margin-top:16px; }
    .inbox-sender-fields input, .inbox-sender-fields textarea { width:100%; padding:10px 14px; border:1px solid var(--border-color); border-radius:var(--radius-sm); background:var(--bg-surface); color:var(--text-primary); font-size:14px; outline:none; transition:border-color 0.2s; }
    .inbox-sender-fields input:focus, .inbox-sender-fields textarea:focus { border-color:var(--accent-primary); }
    .inbox-sender-fields textarea { resize:vertical; min-height:60px; }
    .inbox-submit-btn { width:100%; margin-top:20px; padding:12px; font-size:15px; }
    .inbox-file-info { display:flex; align-items:center; gap:12px; padding:14px 16px; background:var(--bg-secondary); border-radius:var(--radius); margin:12px 0; }
    .inbox-file-info .fi-icon { font-size:24px; color:var(--accent-primary); }
    .inbox-file-info .fi-detail { flex:1; min-width:0; }
    .inbox-file-info .fi-name { font-size:14px; font-weight:600; color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .inbox-file-info .fi-size { font-size:12px; color:var(--text-muted); }
    .inbox-file-info .fi-remove { cursor:pointer; color:var(--text-muted); font-size:16px; transition:color 0.2s; }
    .inbox-file-info .fi-remove:hover { color:var(--accent-danger); }
    .inbox-progress { width:100%; height:4px; background:var(--bg-secondary); border-radius:2px; margin-top:12px; overflow:hidden; display:none; }
    .inbox-progress-bar { height:100%; background:linear-gradient(90deg,var(--accent-primary),var(--accent-secondary)); border-radius:2px; transition:width 0.3s; width:0%; }
    .inbox-result { text-align:center; padding:24px; display:none; }
    .inbox-result .ir-icon { font-size:48px; margin-bottom:16px; }
    .inbox-result.success .ir-icon { color:#22c55e; }
    .inbox-result.error .ir-icon { color:var(--accent-danger); }
    </style>
</head>
<body>
<?php if ($inboxData): ?>
<div class="inbox-upload-page">
    <div class="inbox-upload-card">
        <div class="fluent-share-card glass">
            <div class="fluent-share-header">
                <div class="fluent-share-icon" style="background:linear-gradient(135deg,var(--accent-primary),var(--accent-secondary))">
                    <i class="fas fa-inbox"></i>
                </div>
                <h1 class="fluent-share-title">文件信箱</h1>
                <p class="fluent-share-subtitle">向 <?php echo Security::escape($inboxData['app_name']); ?> 投递文件</p>
            </div>

            <div id="uploadSection">
                <div class="inbox-dropzone" id="dropzone" onclick="document.getElementById('fileInput').click()">
                    <div class="dz-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                    <div class="dz-text">拖拽文件到此处，或 <strong>点击选择</strong></div>
                    <input type="file" id="fileInput" style="display:none" onchange="onFileSelected(this)">
                </div>
                <div id="fileInfoArea"></div>
                <div class="inbox-sender-fields">
                    <input type="text" id="senderName" placeholder="你的名字（选填）" maxlength="50" autocomplete="off">
                    <textarea id="senderMessage" placeholder="留言（选填）" maxlength="500" rows="2"></textarea>
                </div>
                <button class="btn btn-primary inbox-submit-btn" id="submitBtn" onclick="submitFile()" disabled>
                    <i class="fas fa-paper-plane" style="margin-right:6px"></i>投递文件
                </button>
                <div class="inbox-progress" id="progressBar">
                    <div class="inbox-progress-bar" id="progressFill"></div>
                </div>
            </div>

            <div class="inbox-result" id="resultSuccess">
                <div class="ir-icon"><i class="fas fa-check-circle"></i></div>
                <h3 style="font-size:18px;font-weight:700;color:var(--text-primary);margin-bottom:8px">投递成功</h3>
                <p style="font-size:14px;color:var(--text-secondary)">文件已成功送达，对方会很快收到</p>
                <button class="btn btn-glass" style="margin-top:20px" onclick="resetForm()"><i class="fas fa-redo"></i> 继续投递</button>
            </div>

            <div class="inbox-result" id="resultError">
                <div class="ir-icon"><i class="fas fa-exclamation-circle"></i></div>
                <h3 style="font-size:18px;font-weight:700;color:var(--text-primary);margin-bottom:8px" id="errorTitle">投递失败</h3>
                <p style="font-size:14px;color:var(--text-secondary)" id="errorMessage"></p>
                <button class="btn btn-glass" style="margin-top:20px" onclick="resetForm()"><i class="fas fa-redo"></i> 重试</button>
            </div>
        </div>
    </div>
</div>
<script>
var selectedFile = null;
var inboxToken = <?php echo json_encode($inboxData['token']); ?>;

function onFileSelected(input) {
    if (!input.files || !input.files[0]) return;
    selectedFile = input.files[0];
    var area = document.getElementById('fileInfoArea');
    var size = selectedFile.size;
    var sizeStr = size < 1024 ? size + ' B' : size < 1048576 ? (size/1024).toFixed(1)+' KB' : (size/1048576).toFixed(1)+' MB';
    area.innerHTML = '<div class="inbox-file-info"><div class="fi-icon"><i class="fas fa-file"></i></div><div class="fi-detail"><div class="fi-name">'+selectedFile.name+'</div><div class="fi-size">'+sizeStr+'</div></div><div class="fi-remove" onclick="removeFile()"><i class="fas fa-times"></i></div></div>';
    document.getElementById('submitBtn').disabled = false;
}

function removeFile() {
    selectedFile = null;
    document.getElementById('fileInput').value = '';
    document.getElementById('fileInfoArea').innerHTML = '';
    document.getElementById('submitBtn').disabled = true;
}

function submitFile() {
    if (!selectedFile) return;
    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:6px"></i>投递中...';

    var formData = new FormData();
    formData.append('file', selectedFile);
    formData.append('inbox_token', inboxToken);
    formData.append('sender_name', document.getElementById('senderName').value);
    formData.append('sender_message', document.getElementById('senderMessage').value);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'index.php?action=inbox_upload');

    xhr.upload.onprogress = function(e) {
        if (e.lengthComputable) {
            var pct = Math.round(e.loaded / e.total * 100);
            document.getElementById('progressBar').style.display = 'block';
            document.getElementById('progressFill').style.width = pct + '%';
        }
    };

    xhr.onload = function() {
        var data;
        try { data = JSON.parse(xhr.responseText); } catch(e) { data = {success:false,message:'服务器错误'}; }
        if (data.success) {
            document.getElementById('uploadSection').style.display = 'none';
            document.getElementById('resultSuccess').style.display = 'block';
        } else {
            document.getElementById('uploadSection').style.display = 'none';
            document.getElementById('resultError').style.display = 'block';
            document.getElementById('errorMessage').textContent = data.message || '投递失败，请稍后重试';
        }
    };

    xhr.onerror = function() {
        document.getElementById('uploadSection').style.display = 'none';
        document.getElementById('resultError').style.display = 'block';
        document.getElementById('errorMessage').textContent = '网络错误，请检查连接后重试';
    };

    xhr.send(formData);
}

function resetForm() {
    selectedFile = null;
    document.getElementById('fileInput').value = '';
    document.getElementById('fileInfoArea').innerHTML = '';
    document.getElementById('senderName').value = '';
    document.getElementById('senderMessage').value = '';
    document.getElementById('submitBtn').disabled = true;
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-paper-plane" style="margin-right:6px"></i>投递文件';
    document.getElementById('progressBar').style.display = 'none';
    document.getElementById('progressFill').style.width = '0%';
    document.getElementById('uploadSection').style.display = '';
    document.getElementById('resultSuccess').style.display = 'none';
    document.getElementById('resultError').style.display = 'none';
}

// Drag & drop
var dz = document.getElementById('dropzone');
dz.addEventListener('dragover', function(e) { e.preventDefault(); dz.classList.add('dragover'); });
dz.addEventListener('dragleave', function() { dz.classList.remove('dragover'); });
dz.addEventListener('drop', function(e) {
    e.preventDefault(); dz.classList.remove('dragover');
    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
        document.getElementById('fileInput').files = e.dataTransfer.files;
        onFileSelected(document.getElementById('fileInput'));
    }
});
</script>
<?php else: ?>
<div class="inbox-upload-page">
    <div class="inbox-upload-card">
        <div class="fluent-share-card glass">
            <div class="fluent-share-header">
                <div class="fluent-share-icon"><i class="fas fa-inbox"></i></div>
                <h1 class="fluent-share-title">文件信箱</h1>
                <p class="fluent-share-subtitle">链接无效或已过期</p>
            </div>
            <div class="fluent-empty-state">
                <div class="fluent-empty-icon"><i class="fas fa-circle-exclamation"></i></div>
                <h3 class="fluent-empty-title">链接无效</h3>
                <p class="fluent-empty-desc">此收件链接已失效，请联系对方获取新链接</p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
</body>
</html>
