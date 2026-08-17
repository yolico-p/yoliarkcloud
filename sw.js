// ===== SERVICE WORKER FOR 柚舟Cloud =====
// CACHE_VERSION 自动化：从注册 URL 的 ?v= 查询参数读取（由 PHP 注入 sw.js 的 mtime），
// 这样每次部署后 sw.js 一旦变更，浏览器立即拉取新 SW 并刷新 CACHE_NAME，
// 触发 activate 阶段清理所有旧缓存——无需手动 bump。
// 缺失 ?v= 时回退到 'fallback'，保证旧式注册仍可工作。
var CACHE_VERSION = (function () {
    var m = self.location.search.match(/[?&]v=([^&]+)/);
    return m ? decodeURIComponent(m[1]) : 'fallback';
})();
var CACHE_NAME = 'pancloud-' + CACHE_VERSION;
// 缩略图独立持久缓存：跨部署版本保留，content_hash 寻址，文件移动/重命名后仍命中
var THUMB_CACHE = 'pancloud-thumbnails';
var STATIC_ASSETS = [
  '/',
  '/index.php',
  '/manifest.json',
  '/assets/css/base.css',
  '/assets/css/layout.css',
  '/assets/css/components.css',
  '/assets/css/style.css',
  '/assets/css/fluent-share.css',
  '/assets/css/mobile.css',
  '/assets/css/fontawesome.min.css',
  '/assets/js/store.js',
  '/assets/js/utils.js',
  '/assets/js/core.js',
  '/assets/js/theme.js',
  '/assets/js/upload.js',
  '/assets/js/preview.js',
  '/assets/js/files.js',
  '/assets/js/pages.js',
  '/assets/js/ai.js',
  '/assets/js/share.js',
  '/assets/js/batch_rename.js',
  '/assets/js/mobile.js',
  '/assets/vendor/purify.min.js',
  '/assets/vendor/qrcode.min.js',
  '/assets/vendor/crypto-js.min.js',
  '/assets/vendor/highlight.min.js',
  '/assets/vendor/atom-one-dark.min.css',
  '/assets/vendor/xlsx.full.min.js',
  '/assets/vendor/jszip.min.js',
  '/assets/vendor/docx-preview.min.js',
  '/assets/vendor/marked.min.js',
  '/assets/vendor/marked-footnote.min.js',
  '/assets/vendor/marked-emoji.min.js',
  '/assets/vendor/marked-alert.min.js',
  '/assets/vendor/katex.min.js',
  '/assets/vendor/katex.min.css',
  '/assets/vendor/mermaid.min.js',
  'https://cdn.bootcdn.net/ajax/libs/font-awesome/6.5.1/css/all.min.css',
  'https://cdn.staticfile.org/dompurify/3.0.6/purify.min.js'
];

// Install event - 预缓存静态资源
// 用 Promise.allSettled 逐个缓存而非 cache.addAll（事务性 addAll 任一失败会 reject 全部），
// 避免单个 CDN 资源跨源失败或 404 导致整个预缓存被丢弃
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return Promise.allSettled(
        STATIC_ASSETS.map((url) =>
          cache.match(url).then((existing) => {
            if (existing) return;
            return fetch(url, { mode: 'cors', credentials: 'omit' })
              .then((resp) => {
                // 仅缓存 200 OK 响应；3xx/4xx/5xx 跳过
                if (resp && resp.status === 200) {
                  return cache.put(url, resp);
                }
              })
              .catch((err) => {
                console.warn('[SW] precache miss:', url, err && err.message);
              });
          })
        )
      );
    })
  );
});

// Activate event - clean up old caches，首次注册时 claim 客户端
// 注意：THUMB_CACHE 跨版本保留，不清理
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheName !== CACHE_NAME && cacheName !== THUMB_CACHE) {
            console.log('[SW] Deleting old cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    }).then(function () {
      return self.clients.claim();
    })
  );
});

// 监听前端消息，由前端控制何时激活新版本（替代 skipWaiting）
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

// 判断是否为缩略图请求
function isThumbnailRequest(url) {
  return url.indexOf('action=thumbnail') !== -1;
}

// 判断是否为 API 请求（不缓存）
function isApiRequest(url) {
  return (url.indexOf('action=') !== -1 && !isThumbnailRequest(url)) || url.indexOf('/api.php') !== -1 || url.indexOf('/openapi.php') !== -1;
}

// 判断是否为静态资源
function isStaticAsset(request) {
  var dest = request.destination;
  return dest === 'style' || dest === 'script' || dest === 'font' || dest === 'image';
}

// 判断 URL 是否在 STATIC_ASSETS 预缓存清单内（含跨源 CDN 资源）
function isPrecachedAsset(url) {
  return STATIC_ASSETS.indexOf(url) !== -1;
}

// Fetch event - 静态资源 cache-first，API network-only，页面 network-first
self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') {
    return;
  }

  var url = event.request.url;

  // 缩略图请求：cache-first，持久缓存（content_hash 寻址，跨目录/跨部署复用）
  if (isThumbnailRequest(url)) {
    event.respondWith(
      caches.open(THUMB_CACHE).then(function (cache) {
        return cache.match(event.request).then(function (cached) {
          if (cached) return cached;
          return fetch(event.request).then(function (response) {
            if (response.status === 200) {
              cache.put(event.request, response.clone());
            }
            return response;
          }).catch(function () {
            return new Response('', { status: 503 });
          });
        });
      })
    );
    return;
  }

  // API 请求：network-only，不缓存
  if (isApiRequest(url)) {
    event.respondWith(fetch(event.request));
    return;
  }

  // 跨源请求：仅处理 STATIC_ASSETS 清单内已预缓存的资源，
  // 其他跨源请求直接放行（不拦截、不缓存）
  if (!url.startsWith(self.location.origin)) {
    if (isPrecachedAsset(url)) {
      event.respondWith(
        caches.match(url).then((cached) => cached || fetch(event.request).catch(() => new Response('', { status: 503 })))
      );
    }
    return;
  }

  // 静态资源：cache-first
  if (isStaticAsset(event.request)) {
    event.respondWith(
      caches.match(event.request).then((cachedResponse) => {
        if (cachedResponse) {
          return cachedResponse;
        }
        return fetch(event.request).then((response) => {
          if (response.status === 200) {
            var responseToCache = response.clone();
            caches.open(CACHE_NAME).then((cache) => {
              cache.put(event.request, responseToCache);
            });
          }
          return response;
        }).catch(() => {
          return new Response('Offline', { status: 503 });
        });
      })
    );
    return;
  }

  // 页面/导航请求：network-first，回退到缓存
  event.respondWith(
    fetch(event.request)
      .then((response) => {
        // 不缓存 API JSON 响应；仅缓存成功的 HTML 页面
        var contentType = response.headers.get('content-type') || '';
        if (response.status === 200 && contentType.indexOf('text/html') !== -1) {
          var responseToCache = response.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(event.request, responseToCache);
          });
        }
        return response;
      })
      .catch(() => {
        return caches.match(event.request).then((cachedResponse) => {
          if (cachedResponse) return cachedResponse;
          if (event.request.mode === 'navigate') {
            return caches.match('/index.php');
          }
          return new Response('Offline', { status: 503 });
        });
      })
  );
});

// Push notifications (optional)
self.addEventListener('push', (event) => {
  if (!event.data) return;

  const data = event.data.json();
  const options = {
    body: data.body || '您有一条新消息',
    icon: 'assets/img/icon-192.svg',
    badge: 'assets/img/icon-192.svg',
    vibrate: [100, 50, 100],
    data: {
      dateOfArrival: Date.now(),
      primaryKey: 1
    },
    actions: [
      {
        action: 'view',
        title: '查看'
      }
    ]
  };

  event.waitUntil(
    self.registration.showNotification(data.title || '柚舟Cloud', options)
  );
});

// Notification click handler
self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  if (event.action === 'view') {
    event.waitUntil(
      clients.openWindow('/index.php')
    );
  }
});

// Background sync (optional, for offline uploads)
self.addEventListener('sync', (event) => {
  if (event.tag === 'sync-uploads') {
    event.waitUntil(
      Promise.resolve()
    );
  }
});
