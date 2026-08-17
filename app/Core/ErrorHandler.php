<?php

namespace App\Core;

/**
 * 统一错误处理器。
 *
 * 所有错误、异常、致命错误统一通过 AsyncLogger 写入 storage/logs/app.log，
 * 与业务日志共用同一文件，便于运维查询。
 *
 * 历史问题：
 * - 原实现写 DATA_PATH/error.log，与 AsyncLogger 的 app.log 分离
 * - file_put_contents 无 LOCK_EX，多进程并发可能交错
 * - getLogStats 全量读文件统计级别，大日志 OOM
 */
class ErrorHandler
{
    private static $instance = null;
    private $isProduction = true;

    private function __construct()
    {
        $this->isProduction = !(defined('DEBUG') && DEBUG);
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 注册错误处理器。
     */
    public function register()
    {
        if ($this->isProduction) {
            ini_set('display_errors', 0);
        } else {
            ini_set('display_errors', 1);
        }
        error_reporting(E_ALL);

        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * 处理 PHP 错误。
     */
    public function handleError($errno, $errstr, $errfile, $errline)
    {
        // PHP 8.0+ 改变了 @ 行为：@ 不再将 error_reporting() 置为 0，
        // 而是移除被抑制的错误位。必须用位运算判断当前错误是否被 @ 抑制。
        if (!(error_reporting() & $errno)) {
            return false;
        }

        $level = match ($errno) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR => 'error',
            E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'warning',
            E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED => 'info',
            default => 'info',
        };

        AsyncLogger::getInstance()->log(
            sprintf('PHP %s: %s in %s:%d', $this->getErrorType($errno), $errstr, $errfile, $errline),
            $level,
            ['kind' => 'php_error', 'errno' => $errno, 'file' => $errfile, 'line' => $errline]
        );

        return $this->isProduction;
    }

    /**
     * 处理未捕获的异常。
     */
    public function handleException($exception)
    {
        AsyncLogger::getInstance()->log(
            'Uncaught ' . get_class($exception) . ': ' . $exception->getMessage(),
            'error',
            [
                'kind' => 'exception',
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]
        );

        $this->showErrorPage($this->isProduction, $exception);
    }

    /**
     * 处理致命错误。
     */
    public function handleShutdown()
    {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            AsyncLogger::getInstance()->log(
                sprintf('Fatal: %s in %s:%d', $error['message'], $error['file'], $error['line']),
                'error',
                ['kind' => 'fatal', 'errno' => $error['type'], 'file' => $error['file'], 'line' => $error['line']]
            );
            // 立即刷新确保日志落盘
            AsyncLogger::getInstance()->flush();
            $this->showErrorPage($this->isProduction);
            exit;
        }
    }

    /**
     * 显示错误页面（统一不再读 global $error，从异常对象取信息）。
     */
    private function showErrorPage($isProduction = true, ?\Throwable $exception = null)
    {
        if (ob_get_level()) {
            ob_end_clean();
        }

        http_response_code(500);

        if ($isProduction) {
            echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>出错了</title><style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f5f5f5;color:#333}.container{text-align:center;padding:40px;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);max-width:400px;width:90%}h2{font-size:18px;margin-bottom:12px;font-weight:600}p{font-size:14px;color:#666;margin-bottom:20px}.btn{display:inline-block;padding:10px 20px;background:#007DFF;color:#fff;border-radius:6px;text-decoration:none;font-size:14px}.btn:hover{background:#0066d9}</style></head><body><div class="container"><h2>出错了</h2><p>服务器遇到了一些问题，请稍后再试。</p><a href="index.php" class="btn">返回首页</a></div></body></html>';
        } else {
            $msg = $exception ? htmlspecialchars($exception->getMessage()) : 'Unknown error';
            $file = $exception ? htmlspecialchars($exception->getFile() . ':' . $exception->getLine()) : '';
            $trace = $exception ? htmlspecialchars($exception->getTraceAsString()) : '';
            echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><style>body{font-family:monospace;padding:20px;background:#f5f5f5}.error{background:#ffebee;padding:20px;border-radius:4px;margin-bottom:20px}h2{color:#c62828}.info{background:#e3f2fd;padding:15px;border-radius:4px;margin:10px 0}pre{background:#f5f5f5;padding:10px;overflow-x:auto}</style></head><body>';
            echo '<div class="error"><h2>' . $msg . '</h2>';
            echo '<p><strong>File:</strong> ' . $file . '</p></div>';
            if ($trace) {
                echo '<div class="info"><h3>Stack Trace:</h3><pre>' . $trace . '</pre></div>';
            }
            echo '</body></html>';
        }

        exit;
    }

    /**
     * 获取错误类型名称。
     */
    private function getErrorType($errno)
    {
        $types = [
            E_ERROR => 'Fatal Error',
            E_WARNING => 'Warning',
            E_PARSE => 'Parse Error',
            E_NOTICE => 'Notice',
            E_CORE_ERROR => 'Core Error',
            E_COMPILE_ERROR => 'Compile Error',
            E_USER_ERROR => 'User Error',
            E_USER_WARNING => 'User Warning',
            E_USER_NOTICE => 'User Notice',
            E_DEPRECATED => 'Deprecated',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
        ];

        return $types[$errno] ?? 'Unknown Error (' . $errno . ')';
    }
}

