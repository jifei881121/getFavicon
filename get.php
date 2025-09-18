<?php
/**
 * getFavicon - 网站图标获取工具
 * @author    一为
 * @date      2024-12-18
 * @link      https://www.iowen.cn
 * @version   2.0.0
 * @changelog 1. 增强错误处理机制
 *            2. 提升输入验证与安全性
 *            3. 优化缓存目录结构与性能
 *            4. 规范代码风格与类型提示
 *            5. 增加日志记录功能
 *            6. 完善HTTP响应处理
 */

// 确保脚本只在CLI或Web服务器环境下运行
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server' && !(isset($_SERVER['REQUEST_METHOD']))) {
    exit('Invalid execution environment');
}

// 检查必要参数
if (!isset($_GET['url']) || trim($_GET['url']) === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'URL参数不能为空']);
    exit;
}

// 加载依赖
try {
    if (!file_exists('./config.php')) {
        throw new RuntimeException('配置文件不存在: config.php');
    }
    require "./config.php";
    
    if (!file_exists('./Favicon.php')) {
        throw new RuntimeException('Favicon类文件不存在: Favicon.php');
    }
    require "./Favicon.php";
    
    // 检查必要配置项
    $requiredConfigs = ['CACHE_DIR', 'HASH_KEY', 'DEFAULT_ICO', 'EXPIRE'];
    foreach ($requiredConfigs as $config) {
        if (!defined($config)) {
            throw new RuntimeException("缺少必要配置项: {$config}");
        }
    }
} catch (RuntimeException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// 初始化配置
$cacheDir    = CACHE_DIR;
$hashKey     = HASH_KEY;
$defaultIco  = DEFAULT_ICO;
$expire      = (int)EXPIRE;

// 生成随机哈希密钥（首次运行）
if ($hashKey === 'iowen') {
    try {
        $newHashKey = substr(hash('sha256', uniqid(random_bytes(16), true)), 0, 16);
        $configContent = file_get_contents('./config.php');
        
        if ($configContent === false) {
            throw new RuntimeException('无法读取配置文件');
        }
        
        $updatedContent = str_replace('iowen', $newHashKey, $configContent);
        if (file_put_contents('./config.php', $updatedContent) === false) {
            throw new RuntimeException('无法更新配置文件');
        }
        
        $hashKey = $newHashKey;
    } catch (Exception $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => '哈希密钥生成失败: ' . $e->getMessage()]);
        exit;
    }
}

// 验证默认图标文件
if (!file_exists($defaultIco)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => "默认图标文件不存在: {$defaultIco}"]);
    exit;
}

// 初始化Favicon实例
try {
    $favicon = new \Jerrybendy\Favicon\Favicon();
    $favicon->setDefaultIcon($defaultIco);
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Favicon类初始化失败: ' . $e->getMessage()]);
    exit;
}

// 处理URL参数
$url = trim($_GET['url']);

// 验证URL格式
if (!filter_var($url, FILTER_VALIDATE_URL) && !preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $url)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => '无效的URL格式']);
    exit;
}

// 格式化URL并处理
$formatUrl = $favicon->formatUrl($url);
if (!$formatUrl) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => '无法格式化URL']);
    exit;
}

// 处理缓存逻辑
try {
    $cache = new Cache($hashKey, $cacheDir);
    
    // 检查是否需要刷新缓存
    $refreshCache = isset($_GET['refresh']) && strtolower($_GET['refresh']) === 'true';
    
    // 不需要刷新且缓存有效时直接返回缓存
    if (!$refreshCache && $expire > 0) {
        $defaultMd5 = md5_file($defaultIco);
        $cachedData = $cache->get($formatUrl, $defaultMd5, $expire);
        
        if ($cachedData !== null) {
            foreach ($favicon->getHeader() as $header) {
                header($header, true);
            }
            header('X-Cache: HIT');
            header('X-Cache-Expire: ' . ($cache->getCacheExpiry($formatUrl, $defaultMd5, $expire) - time()));
            echo $cachedData;
            exit;
        }
    }
    
    // 缓存未命中或需要刷新时，重新获取并缓存
    $content = $favicon->getFavicon($formatUrl, true);
    
    if ($expire > 0) {
        $cache->set($formatUrl, $content);
    }
    
    foreach ($favicon->getHeader() as $header) {
        header($header, true);
    }
    
    header('X-Cache: MISS');
    echo $content;
    exit;
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => '处理请求失败: ' . $e->getMessage()]);
    exit;
}

/**
 * 缓存处理类
 * 负责图标数据的缓存管理，包括获取、存储和过期检查
 */
class Cache
{
    private string $dir;        // 图标缓存目录
    private string $hashKey;    // 哈希密钥

    /**
     * 构造函数
     * @param string $hashKey 哈希密钥
     * @param string $dir 缓存目录
     * @throws RuntimeException 当目录无法创建时抛出异常
     */
    public function __construct(string $hashKey, string $dir = 'cache')
    {
        $this->hashKey = $hashKey;
        $this->dir = rtrim($dir, '/') . '/';
        
        // 确保缓存目录存在
        $this->ensureDirectoryExists($this->dir);
    }

    /**
     * 获取缓存数据
     * @param string $key 缓存键(URL)
     * @param string $defaultMd5 默认图片的MD5
     * @param int $expire 过期时间(秒)
     * @return string|null 缓存数据或null(未命中或过期)
     * @throws RuntimeException 当URL解析失败时抛出异常
     */
    public function get(string $key, string $defaultMd5, int $expire): ?string
    {
        $filePath = $this->getCacheFilePath($key);
        
        if (!is_file($filePath)) {
            return null;
        }
        
        // 检查文件是否过期
        $fileMtime = filemtime($filePath);
        if ($fileMtime === false) {
            return null;
        }
        
        // 读取文件内容
        $data = file_get_contents($filePath);
        if ($data === false) {
            return null;
        }
        
        // 对默认图标使用不同的过期时间
        $actualExpire = (md5($data) === $defaultMd5) ? 43200 : $expire;
        
        if ((time() - $fileMtime) > $actualExpire) {
            // 缓存过期，删除旧文件
            @unlink($filePath);
            return null;
        }
        
        return $data;
    }

    /**
     * 获取缓存过期时间
     * @param string $key 缓存键(URL)
     * @param string $defaultMd5 默认图片的MD5
     * @param int $expire 过期时间(秒)
     * @return int 过期时间戳
     */
    public function getCacheExpiry(string $key, string $defaultMd5, int $expire): int
    {
        $filePath = $this->getCacheFilePath($key);
        
        if (!is_file($filePath)) {
            return time();
        }
        
        $fileMtime = filemtime($filePath) ?: time();
        $data = @file_get_contents($filePath);
        
        $actualExpire = (md5($data ?? '') === $defaultMd5) ? 43200 : $expire;
        return $fileMtime + $actualExpire;
    }

    /**
     * 存储缓存数据
     * @param string $key 缓存键(URL)
     * @param string $value 缓存值(图标数据)
     * @throws RuntimeException 当存储失败时抛出异常
     */
    public function set(string $key, string $value): void
    {
        $filePath = $this->getCacheFilePath($key);
        
        // 确保父目录存在
        $this->ensureDirectoryExists(dirname($filePath));
        
        // 写入文件并加锁防止并发问题
        $fileHandle = fopen($filePath, 'w');
        if (!$fileHandle) {
            throw new RuntimeException("无法打开缓存文件: {$filePath}");
        }
        
        try {
            // 获取排他锁
            if (!flock($fileHandle, LOCK_EX)) {
                throw new RuntimeException("无法锁定缓存文件: {$filePath}");
            }
            
            // 写入数据
            if (fwrite($fileHandle, $value) === false) {
                throw new RuntimeException("无法写入缓存文件: {$filePath}");
            }
            
            // 释放锁
            flock($fileHandle, LOCK_UN);
        } finally {
            fclose($fileHandle);
        }
        
        // 设置文件权限
        chmod($filePath, 0644);
    }

    /**
     * 获取缓存文件路径
     * @param string $key 缓存键(URL)
     * @return string 缓存文件路径
     * @throws RuntimeException 当URL解析失败时抛出异常
     */
    private function getCacheFilePath(string $key): string
    {
        $urlParts = parse_url($key);
        if (!$urlParts || !isset($urlParts['host'])) {
            throw new RuntimeException("无法解析URL: {$key}");
        }
        
        $host = strtolower($urlParts['host']);
        $hash = substr(hash_hmac('sha256', $host, $this->hashKey), 8, 16);
        
        // 分目录存储，避免单目录文件过多
        $subDir = substr($hash, 0, 2);
        $fileName = "{$host}_{$hash}.dat";
        
        return "{$this->dir}{$subDir}/{$fileName}";
    }

    /**
     * 确保目录存在，不存在则创建
     * @param string $directory 目录路径
     * @throws RuntimeException 当目录无法创建时抛出异常
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            $oldUmask = umask(0);
            $created = mkdir($directory, 0755, true);
            umask($oldUmask);
            
            if (!$created && !is_dir($directory)) {
                throw new RuntimeException("无法创建目录: {$directory}");
            }
        }
    }
}
