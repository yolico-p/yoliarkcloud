/**
 * 工具函数集合
 * 纯 JavaScript 工具函数，不依赖业务逻辑
 */

/**
 * 格式化时间（秒转为分:秒格式）
 * @param {number} seconds - 秒数
 * @returns {string} 格式化后的时间字符串
 */
function formatTime(seconds) {
    if (!seconds || isNaN(seconds)) return '0:00';
    var mins = Math.floor(seconds / 60);
    var secs = Math.floor(seconds % 60);
    return mins + ':' + (secs < 10 ? '0' : '') + secs;
}

/**
 * HTML 转义
 * @param {string} str - 需要转义的字符串
 * @returns {string} 转义后的字符串
 */
function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function sanitizeHtml(html) {
    if (typeof window !== 'undefined' && window.DOMPurify) {
        return window.DOMPurify.sanitize(html, {
            ALLOWED_TAGS: ['p','br','strong','em','ul','ol','li','h1','h2','h3','a','img','table','tr','td','th','span','div'],
            ALLOWED_ATTR: ['href','src','alt','class']
        });
    }
    return html
        .replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '')
        .replace(/<iframe\b[^<]*(?:(?!<\/iframe>)<[^<]*)*<\/iframe>/gi, '')
        .replace(/<object\b[^<]*(?:(?!<\/object>)<[^<]*)*<\/object>/gi, '')
        .replace(/<embed\b[^>]*>/gi, '')
        .replace(/\son\w+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '');
}

/**
 * JSON 字符串转义（用于嵌入 HTML）
 * @param {string} jsonStr - JSON 字符串
 * @returns {string} 转义后的字符串
 */
function escapeJsonForHtml(jsonStr) {
    return jsonStr.replace(/'/g, '&#39;').replace(/"/g, '&quot;');
}

/**
 * 代码高亮处理
 * @param {string} content - 代码内容
 * @param {string} filename - 文件名
 * @returns {string} 高亮后的 HTML
 */
function highlightTextContent(content, filename) {
    if (typeof hljs === 'undefined') return content;

    var codeExtensions = ['py', 'rb', 'java', 'c', 'cpp', 'h', 'go', 'rs', 'sql', 'ts', 'jsx', 'tsx', 'vue', 'js', 'php', 'sh', 'bash', 'css', 'html', 'xml', 'json', 'yml', 'yaml', 'ini', 'cfg', 'md', 'r', 'm', 'swift', 'kt', 'scala', 'log'];
    var ext = filename.split('.').pop().toLowerCase();

    if (codeExtensions.includes(ext)) {
        return '<code class="language-' + (ext === 'js' ? 'javascript' : ext === 'py' ? 'python' : ext === 'rb' ? 'ruby' : ext === 'rs' ? 'rust' : ext === 'ts' ? 'typescript' : ext) + '">' + content + '</code>';
    }
    return content;
}


