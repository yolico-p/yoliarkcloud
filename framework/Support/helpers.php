<?php

if (!function_exists('app')) {
    /**
     * 容器解析助手。委托给 App\Core\Container。
     *
     * 用法：
     *   $db = app('db');            // 解析 'db' 服务
     *   $container = app();          // 不传参返回 Container 实例
     *
     * @param string|null $abstract 服务名；null 返回 Container 自身
     * @return App\Core\Container|mixed
     */
    function app($abstract = null, array $parameters = [])
    {
        $container = \App\Core\Container::getInstance();
        if ($abstract === null) {
            return $container;
        }
        return $container->get($abstract, $parameters);
    }
}

if (!function_exists('config')) {
    function config($key = null, $default = null)
    {
        $config = App\Core\Config::getInstance();
        if ($key === null) {
            return $config->getAll();
        }
        return $config->get($key, $default);
    }
}

if (!function_exists('db')) {
    function db($table = null)
    {
        $db = App\Core\Database::getInstance();
        if ($table === null) {
            return $db;
        }
        return $db;
    }
}

if (!function_exists('request')) {
    function request()
    {
        static $instance = null;
        if ($instance === null && class_exists('Framework\Http\Request')) {
            $instance = Framework\Http\Request::capture();
        }
        return $instance;
    }
}

if (!function_exists('response')) {
    function response($content = '', $status = 200, array $headers = [])
    {
        if (class_exists('Framework\Http\Response')) {
            return Framework\Http\Response::make($content, $status, $headers);
        }
        return null;
    }
}

if (!function_exists('json')) {
    function json($data, $status = 200, $options = 0)
    {
        if (class_exists('Framework\Http\Response')) {
            return Framework\Http\Response::json($data, $status, $options);
        }
        App\Core\Security::jsonOutput($data, $status);
    }
}

if (!function_exists('success')) {
    function success($message = '操作成功', $data = [])
    {
        if (class_exists('Framework\Http\Response')) {
            return Framework\Http\Response::success($message, $data);
        }
        App\Core\Security::jsonOutput(array_merge(['success' => true, 'message' => $message], $data));
    }
}

if (!function_exists('error')) {
    function error($message = '操作失败', $status = 400)
    {
        if (class_exists('Framework\Http\Response')) {
            return Framework\Http\Response::error($message, $status);
        }
        App\Core\Security::jsonOutput(['success' => false, 'message' => $message], $status);
    }
}

if (!function_exists('router')) {
    function router()
    {
        return app('router');
    }
}

if (!function_exists('session')) {
    function session($key = null, $default = null)
    {
        if ($key === null) {
            return $_SESSION;
        }
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $_SESSION[$k] = $v;
            }
            return null;
        }
        return $_SESSION[$key] ?? $default;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token()
    {
        return App\Core\Security::generateCSRFToken();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field()
    {
        return '<input type="hidden" name="_csrf_token" value="' . csrf_token() . '">';
    }
}

if (!function_exists('asset')) {
    function asset($path)
    {
        $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        return $basePath . '/' . ltrim($path, '/');
    }
}

if (!function_exists('url')) {
    function url($path = '')
    {
        $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        return $basePath . '/' . ltrim($path, '/');
    }
}

if (!function_exists('storage_path')) {
    function storage_path($path = '')
    {
        return STORAGE_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('config_path')) {
    function config_path($path = '')
    {
        return CONFIG_PATH . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('cache')) {
    function cache($key, $value = null, $ttl = 3600)
    {
        $cacheFile = DATA_PATH . DIRECTORY_SEPARATOR . 'cache_' . md5($key) . '.json';

        if ($value === null) {
            if (file_exists($cacheFile)) {
                $data = json_decode(file_get_contents($cacheFile), true);
                if (is_array($data) && time() - $data['time'] < $data['ttl']) {
                    return $data['value'];
                }
            }
            return null;
        }

        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        file_put_contents($cacheFile, json_encode([
            'value' => $value,
            'time' => time(),
            'ttl' => $ttl,
        ]), LOCK_EX);
        return $value;
    }
}

if (!function_exists('forget_cache')) {
    function forget_cache($key)
    {
        $cacheFile = DATA_PATH . DIRECTORY_SEPARATOR . 'cache_' . md5($key) . '.json';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }
    }
}

if (!function_exists('format_size')) {
    function format_size($bytes)
    {
        return App\Core\Security::formatSize($bytes);
    }
}

if (!function_exists('format_time')) {
    function format_time($timestamp)
    {
        return App\Core\Security::formatTime($timestamp);
    }
}

if (!function_exists('sanitize')) {
    function sanitize($input)
    {
        return App\Core\Security::sanitizeFilename($input);
    }
}

if (!function_exists('env')) {
    function env($key, $default = null)
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        return $value;
    }
}
