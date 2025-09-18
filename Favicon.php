<?php
/**
 * 获取网站Favicon服务接口
 *
 * @author    Jerry Bendy / 一为
 * @date      2014-09-10
 * @link      https://www.iowen.cn
 * @version   3.0.0
 * @changelog 1. 全面支持PHP 7.4+类型声明（严格模式）
 *            2. 修复safe_mode判断语法bug，优化CURL重定向逻辑
 *            3. 替换getimagesize(URL)为getimagesizefromstring，减少HTTP请求
 *            4. 日志路径可配置，增加目录可写性检查
 *            5. 移除@错误抑制，改用显式错误处理
 *            6. 提取CURL初始化逻辑为独立方法，减少代码重复
 *            7. 动态设置Content-Type（支持ico/png/jpg等格式）
 *            8. 增加超时时间、请求头可配置项
 *            9. 优化相对URL解析逻辑，增强边缘场景处理
 *            10. 增加getter方法，支持外部获取请求耗时、内存占用等信息
 */

declare(strict_types=1);

namespace Jerrybendy\Favicon;

use InvalidArgumentException;

class Favicon
{
    /**
     * 调试模式开关
     * @var bool
     */
    public bool $debug_mode = false;

    /**
     * 存储请求参数（如原始URL、重定向URL等）
     * @var array<string, mixed>
     */
    private array $params = [];

    /**
     * 完整主机地址（如 https://www.iowen.cn:8080）
     * @var string
     */
    private string $full_host = '';

    /**
     * 最终获取的图标二进制数据
     * @var string|null
     */
    private ?string $data = null;

    /**
     * 最后一次请求耗时（秒）
     * @var float
     */
    private float $_last_time_spend = 0.0;

    /**
     * 最后一次请求内存占用
     * @var string
     */
    private string $_last_memory_usage = '0MB';

    /**
     * 正则规则到图标文件的映射（本地/网络文件）
     * @var array<string, string>
     */
    private array $_file_map = [];

    /**
     * 默认图标路径（本地文件）
     * @var string
     */
    private string $_default_icon = '';

    /**
     * 日志文件路径
     * @var string
     */
    private string $_log_path = './logs/favicon-errors.log';

    /**
     * CURL配置项
     * @var array<string, int>
     */
    private array $_curl_config = [
        'total_timeout' => 5,    // 总超时（秒）
        'connect_timeout' => 2,  // 连接超时（秒）
        'max_redirects' => 5,    // 最大重定向次数
        'max_download_size' => 512000, // 最大下载大小（500KB）
    ];

    /**
     * 设置CURL配置
     * @param array<string, int> $config 配置数组（支持total_timeout/connect_timeout/max_redirects/max_download_size）
     * @return self
     */
    public function setCurlConfig(array $config): self
    {
        $validKeys = ['total_timeout', 'connect_timeout', 'max_redirects', 'max_download_size'];
        foreach ($config as $key => $value) {
            if (in_array($key, $validKeys) && is_int($value) && $value > 0) {
                $this->_curl_config[$key] = $value;
            }
        }
        return $this;
    }

    /**
     * 设置日志路径
     * @param string $path 日志文件路径
     * @return self
     * @throws InvalidArgumentException
     */
    public function setLogPath(string $path): self
    {
        $logDir = dirname($path);
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0755, true)) {
                throw new InvalidArgumentException("日志目录创建失败：{$logDir}");
            }
        }
        if (!is_writable($logDir)) {
            throw new InvalidArgumentException("日志目录不可写：{$logDir}");
        }
        $this->_log_path = $path;
        return $this;
    }

    /**
     * 设置默认图标
     * @param string $filePath 本地图标文件路径
     * @return self
     * @throws InvalidArgumentException
     */
    public function setDefaultIcon(string $filePath): self
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("默认图标文件不存在：{$filePath}");
        }
        if (!is_readable($filePath)) {
            throw new InvalidArgumentException("默认图标文件不可读：{$filePath}");
        }
        $this->_default_icon = $filePath;
        return $this;
    }

    /**
     * 设置文件映射规则
     * @param array<string, string> $map 正则规则 => 图标文件路径（本地/网络）
     * @return self
     */
    public function setFileMap(array $map): self
    {
        $this->_file_map = $map;
        return $this;
    }

    /**
     * 获取最后一次请求耗时
     * @return float
     */
    public function getLastTimeSpend(): float
    {
        return $this->_last_time_spend;
    }

    /**
     * 获取最后一次请求内存占用
     * @return string
     */
    public function getLastMemoryUsage(): string
    {
        return $this->_last_memory_usage;
    }

    /**
     * 获取Favicon并输出/返回
     * @param string $url 目标网址
     * @param bool $return 是否返回二进制数据（true=返回，false=直接输出）
     * @return string|null 二进制图标数据（$return=true时）或null（$return=false时）
     * @throws InvalidArgumentException
     */
    public function getFavicon(string $url, bool $return = false): ?string
    {
        // 验证URL参数
        $url = trim($url);
        if (empty($url)) {
            throw new InvalidArgumentException('URL不能为空', 1001);
        }
        $this->params['origin_url'] = $url;

        // 格式化URL
        $formattedUrl = $this->formatUrl($url);
        if (empty($formattedUrl)) {
            throw new InvalidArgumentException("无效的URL：{$url}", 1002);
        }

        // 记录请求开始时间
        $timeStart = microtime(true);
        $this->_log_message("开始获取图标：{$url}");

        // 核心逻辑：获取图标数据
        $iconData = $this->getData();

        // 计算请求耗时和内存占用
        $this->_last_time_spend = microtime(true) - $timeStart;
        $memoryUsage = function_exists('memory_get_usage') 
            ? round(memory_get_usage() / 1024 / 1024, 2) 
            : 0;
        $this->_last_memory_usage = "{$memoryUsage}MB";

        $this->_log_message(
            "获取完成：耗时{$this->_last_time_spend}秒，内存占用{$this->_last_memory_usage}"
        );

        // 处理默认图标（当获取失败时）
        if ($iconData === false && !empty($this->_default_icon)) {
            $iconData = file_get_contents($this->_default_icon);
            $this->_log_message("使用默认图标：{$this->_default_icon}");
        }

        // 返回或输出数据
        if ($return) {
            return $iconData !== false ? $iconData : null;
        }

        if ($iconData !== false) {
            // 动态设置Content-Type（基于图标实际类型）
            $headers = $this->getHeader($iconData);
            foreach ($headers as $header) {
                header($header, true);
            }
            echo $iconData;
        } else {
            header('Content-Type: application/json', true);
            http_response_code(404);
            echo json_encode([
                'status' => -1,
                'msg' => '无法获取Favicon',
                'url' => $url
            ], JSON_UNESCAPED_UNICODE);
        }

        return null;
    }

    /**
     * 动态生成响应头（根据图标类型）
     * @param string $iconData 图标二进制数据
     * @return array<string>
     */
    public function getHeader(string $iconData): array
    {
        // 识别图片类型
        $imageInfo = getimagesizefromstring($iconData);
        $mimeType = $imageInfo ? $imageInfo['mime'] : 'image/x-icon';

        return [
            'X-Robots-Tag: noindex, nofollow',
            "Content-Type: {$mimeType}",
            'Cache-Control: public, max-age=86400',
            'Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT',
            "Content-Length: " . strlen($iconData)
        ];
    }

    /**
     * 核心逻辑：获取图标数据
     * @return string|false 图标二进制数据（成功）或false（失败）
     */
    protected function getData(): string|false
    {
        // 1. 优先匹配本地文件映射
        $mappedData = $this->_matchFileMap();
        if ($mappedData !== null) {
            $this->data = $mappedData;
            return $this->data;
        }

        // 2. 从目标网站HTML中解析link标签
        $htmlResponse = $this->fetchUrl($this->params['origin_url']);
        if ($htmlResponse['status'] === 'OK') {
            $iconUrl = $this->_parseFaviconFromHtml($htmlResponse['data']);
            if (!empty($iconUrl)) {
                $iconResponse = $this->fetchUrl($iconUrl, true);
                if ($iconResponse['status'] === 'OK') {
                    $this->data = $iconResponse['data'];
                    return $this->data;
                }
            }
        }

        // 3. 尝试从网站根目录获取favicon.ico
        $rootIconUrl = "{$this->full_host}/favicon.ico";
        $rootIconResponse = $this->fetchUrl($rootIconUrl, true);
        if ($rootIconResponse['status'] === 'OK') {
            $this->data = $rootIconResponse['data'];
            return $this->data;
        }

        // 4. 处理重定向后的根目录图标
        $redirectedUrl = $htmlResponse['real_url'] ?? '';
        if (!empty($redirectedUrl)) {
            $this->formatUrl($redirectedUrl); // 更新full_host
            $redirectIconUrl = "{$this->full_host}/favicon.ico";
            $redirectIconResponse = $this->fetchUrl($redirectIconUrl, true);
            if ($redirectIconResponse['status'] === 'OK') {
                $this->data = $redirectIconResponse['data'];
                return $this->data;
            }
        }

        // 5. 最后尝试Google Favicon API（备用）
        $googleApiUrl = sprintf(
            'https://t3.gstatic.cn/faviconV2?client=SOCIAL&type=FAVICON&fallback_opts=TYPE,SIZE,URL&size=128&url=%s',
            urlencode($this->full_host)
        );
        $googleResponse = $this->fetchUrl($googleApiUrl, true);
        if ($googleResponse['status'] === 'OK') {
            $this->data = $googleResponse['data'];
            return $this->data;
        }

        // 所有方法均失败
        $this->_log_message("获取失败：{$this->params['origin_url']}");
        return false;
    }

    /**
     * 从HTML中解析Favicon URL
     * @param string $html HTML内容
     * @return string|null Favicon URL（成功）或null（失败）
     */
    private function _parseFaviconFromHtml(string $html): ?string
    {
        // 移除换行符，避免标签折行导致匹配失败
        $html = str_replace(["\n", "\r", "\t"], '', $html);

        // 匹配link标签（支持多种rel属性：icon/shortcut icon/apple-touch-icon等）
        $relPattern = '(icon|shortcut icon|alternate icon|apple-touch-icon|apple-touch-icon-precomposed)';
        $linkPattern = "/<link[^>]+rel\s*=\s*('|\"){$relPattern}\1[^>]+>/i";
        
        if (!preg_match($linkPattern, $html, $linkMatches)) {
            return null;
        }

        // 从link标签中提取href属性
        $hrefPattern = '/href\s*=\s*(\'|\")(.*?)\1/i';
        if (!preg_match($hrefPattern, $linkMatches[0], $hrefMatches)) {
            return null;
        }

        // 解析相对URL为绝对URL
        $relativeUrl = trim($hrefMatches[2]);
        return $this->filterRelativeUrl($relativeUrl, $this->params['origin_url']);
    }

    /**
     * 格式化URL，提取完整主机地址（协议+域名+端口）
     * @param string $url 原始URL
     * @return string|null 完整主机地址（成功）或null（失败）
     */
    public function formatUrl(string $url): ?string
    {
        $parsedUrl = parse_url($url);
        if ($parsedUrl === false || !isset($parsedUrl['host'])) {
            // 尝试添加HTTP前缀（仅当无协议时）
            if (!preg_match('/^https?:\/\//i', $url)) {
                $url = "http://{$url}";
                $parsedUrl = parse_url($url);
            }
            // 仍解析失败则返回null
            if ($parsedUrl === false || !isset($parsedUrl['host'])) {
                $this->_log_message("URL解析失败：{$url}");
                return null;
            }
            // 更新原始URL为带HTTP前缀的版本
            $this->params['origin_url'] = $url;
        }

        // 提取协议（默认http）、主机、端口
        $scheme = $parsedUrl['scheme'] ?? 'http';
        $host = strtolower($parsedUrl['host']);
        $port = isset($parsedUrl['port']) ? ":{$parsedUrl['port']}" : '';

        // 仅支持HTTP/HTTPS协议
        if (!in_array($scheme, ['http', 'https'])) {
            $this->_log_message("不支持的协议：{$scheme}（URL：{$url}）");
            return null;
        }

        $this->full_host = "{$scheme}://{$host}{$port}";
        return $this->full_host;
    }

    /**
     * 相对URL转换为绝对URL
     * @param string $relativeUrl 相对URL
     * @param string $baseUrl 基础URL
     * @return string|null 绝对URL（成功）或null（失败）
     */
    private function filterRelativeUrl(string $relativeUrl, string $baseUrl): ?string
    {
        // 已为绝对URL（含协议）
        if (strpos($relativeUrl, '://') !== false) {
            return $relativeUrl;
        }

        // 解析基础URL
        $baseParsed = parse_url($baseUrl);
        if ($baseParsed === false || !isset($baseParsed['host'], $baseParsed['scheme'])) {
            $this->_log_message("基础URL解析失败：{$baseUrl}（相对URL：{$relativeUrl}）");
            return null;
        }

        // 基础URL根路径（协议+主机+端口）
        $baseRoot = "{$baseParsed['scheme']}://{$baseParsed['host']}";
        if (isset($baseParsed['port'])) {
            $baseRoot .= ":{$baseParsed['port']}";
        }

        // 处理//开头的URL（省略协议）
        if (str_starts_with($relativeUrl, '//')) {
            return "{$baseParsed['scheme']}:{$relativeUrl}";
        }

        // 处理/开头的URL（根路径）
        if (str_starts_with($relativeUrl, '/')) {
            return "{$baseRoot}{$relativeUrl}";
        }

        // 处理相对路径（不含./或../）
        if (!str_contains($relativeUrl, './')) {
            $baseDir = $baseParsed['path'] ?? '';
            $baseDir = rtrim(dirname($baseDir), '/') ?: '';
            return "{$baseRoot}{$baseDir}/{$relativeUrl}";
        }

        // 处理含./或../的相对路径
        $basePath = $baseParsed['path'] ?? '/';
        $baseDir = dirname($basePath);
        $combinedPath = rtrim("{$baseDir}/{$relativeUrl}", '/');

        // 解析路径片段，处理../
        $pathSegments = explode('/', $combinedPath);
        $resolvedSegments = [];
        foreach ($pathSegments as $segment) {
            if ($segment === '..') {
                array_pop($resolvedSegments);
            } elseif ($segment !== '.' && $segment !== '') {
                $resolvedSegments[] = $segment;
            }
        }

        $resolvedPath = '/' . implode('/', $resolvedSegments);
        return "{$baseRoot}{$resolvedPath}";
    }

    /**
     * 发送HTTP请求获取内容（支持图片验证）
     * @param string $url 请求URL
     * @param bool $isImage 是否验证为图片
     * @return array<string, mixed> 响应数组（status/data/real_url/code）
     */
    private function fetchUrl(string $url, bool $isImage = false): array
    {
        $response = [
            'status' => 'FAIL',
            'data' => '',
            'real_url' => $url,
            'code' => 0
        ];

        // 初始化CURL
        $ch = $this->_initCurl($url);
        if (!$ch) {
            return $response;
        }

        // 执行请求并处理响应
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;

        // 检查CURL错误
        if (curl_errno($ch)) {
            $errorMsg = curl_error($ch);
            $this->_log_message("CURL请求失败：{$url}（错误：{$errorMsg}，状态码：{$httpCode}）");
            curl_close($ch);
            return $response;
        }

        curl_close($ch);

        // 检查HTTP状态码（2xx/3xx为成功）
        if ($httpCode < 200 || $httpCode >= 400) {
            $this->_log_message("HTTP请求失败：{$url}（状态码：{$httpCode}）");
            return $response;
        }

        // 验证图片类型（当$isImage=true时）
        if ($isImage) {
            $imageInfo = getimagesizefromstring($content);
            if (!$imageInfo) {
                $this->_log_message("非图片类型：{$url}（内容长度：" . strlen($content) . "字节）");
                return $response;
            }
        }

        // 成功响应
        $response = [
            'status' => 'OK',
            'data' => $content,
            'real_url' => $effectiveUrl,
            'code' => $httpCode
        ];

        $this->_log_message("请求成功：{$url}（状态码：{$httpCode}，内容长度：" . strlen($content) . "字节）");
        return $response;
    }

    /**
     * 初始化CURL句柄
     * @param string $url 请求URL
     * @return resource|null CURL句柄（成功）或null（失败）
     */
    private function _initCurl(string $url): ?\CurlHandle
    {
        $ch = curl_init($url);
        if (!$ch) {
            $this->_log_message("CURL初始化失败：{$url}");
            return null;
        }

        // 设置CURL选项
        $range = "bytes=0-{$this->_curl_config['max_download_size']}";
        $headers = [
            "Range: {$range}",
            'Connection: close',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.0.0 Safari/537.36'
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->_curl_config['total_timeout'],
            CURLOPT_CONNECTTIMEOUT => $this->_curl_config['connect_timeout'],
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TCP_NODELAY => true,
            CURLOPT_SSL_VERIFYPEER => false, // 忽略SSL证书验证（适合通用场景）
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => $this->_curl_config['max_redirects'],
            CURLOPT_FAILONERROR => true,
        ]);

        return $ch;
    }

    /**
     * 匹配文件映射规则
     * @return string|null 匹配到的图标数据（成功）或null（失败）
     */
    private function _matchFileMap(): ?string
    {
        foreach ($this->_file_map as $pattern => $filePath) {
            if (preg_match($pattern, $this->full_host)) {
                // 本地文件：检查存在性和可读性
                if (file_exists($filePath)) {
                    if (!is_readable($filePath)) {
                        $this->_log_message("映射文件不可读：{$filePath}（规则：{$pattern}）");
                        continue;
                    }
                    $data = file_get_contents($filePath);
                    if ($data !== false) {
                        $this->_log_message("从映射文件获取：{$filePath}（规则：{$pattern}）");
                        return $data;
                    }
                } 
                // 网络文件：通过CURL获取
                else {
                    $response = $this->fetchUrl($filePath, true);
                    if ($response['status'] === 'OK') {
                        $this->_log_message("从映射URL获取：{$filePath}（规则：{$pattern}）");
                        return $response['data'];
                    }
                }
                $this->_log_message("映射匹配失败：{$filePath}（规则：{$pattern}）");
            }
        }
        return null;
    }

    /**
     * 写入日志
     * @param string $message 日志内容
     */
    private function _log_message(string $message): void
    {
        if (!$this->debug_mode) {
            return;
        }

        $logLine = sprintf(
            "[%s] [Favicon] %s" . PHP_EOL,
            date('Y-m-d H:i:s'),
            $message
        );

        // 写入日志（追加模式）
        $result = file_put_contents($this->_log_path, $logLine, FILE_APPEND);
        if ($result === false) {
            error_log("日志写入失败：{$this->_log_path}（内容：{$message}）");
        }
    }
}
