<?php
declare(strict_types=1);

class WebsiteScraper {
    private string $baseUrl;
    private string $savePath;
    private array $processedUrls = [];
    private array $processedFiles = [];
    private array $errors = [];
    private array $failedResources = [];
    private int $maxFileSize;
    private array $allowedExtensions;
    private $logger;
    private int $maxRetries = 3;
    private array $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:89.0) Gecko/20100101 Firefox/89.0'
    ];

    public function __construct(string $baseUrl, string $savePath = 'downloaded_site', int $maxFileSize = 10485760) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->savePath = rtrim($savePath, '/');
        $this->maxFileSize = $maxFileSize;
        $this->allowedExtensions = ['html', 'css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'woff', 'woff2'];
        $this->initLogger();
    }

    private function initLogger(): void {
        if (!is_dir($this->savePath)) {
            mkdir($this->savePath, 0777, true);
        }
        $logFile = $this->savePath . '/scraper.log';
        $this->logger = @fopen($logFile, 'a');
    }

    private function log(string $message): void {
        if ($this->logger) {
            $timestamp = date('Y-m-d H:i:s');
            fwrite($this->logger, "[$timestamp] $message\n");
        }
    }

    private function getRandomUserAgent(): string {
        return $this->userAgents[array_rand($this->userAgents)];
    }

    private function createContext(): array {
        return [
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: ' . $this->getRandomUserAgent(),
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.5',
                    'Connection: keep-alive',
                    'Upgrade-Insecure-Requests: 1'
                ],
                'timeout' => 30,
                'follow_location' => true,
                'max_redirects' => 3,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];
    }

    private function fetchUrl(string $url, int $retryCount = 0): ?string {
        if ($retryCount >= $this->maxRetries) {
            $this->failedResources[] = $url;
            return null;
        }

        $context = stream_context_create($this->createContext());

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            $error = error_get_last();
            $this->log("Error fetching $url: " . ($error['message'] ?? 'Unknown error'));

            // Check if we got a 403/404 error
            if (isset($http_response_header)) {
                $headers = implode("\n", $http_response_header);
                if (strpos($headers, '403') !== false || strpos($headers, '404') !== false) {
                    $this->failedResources[] = $url;
                    return null;
                }
            }

            // Wait before retry
            sleep(1);
            return $this->fetchUrl($url, $retryCount + 1);
        }

        return $content;
    }

    private function saveFile(string $url): void {
        try {
            $normalizedUrl = $this->normalizeUrl($url);

            if (in_array($normalizedUrl, $this->processedFiles) || in_array($normalizedUrl, $this->failedResources)) {
                return;
            }

            $content = $this->fetchUrl($normalizedUrl);
            if ($content === null) {
                return;
            }

            $parsedUrl = parse_url($normalizedUrl);
            $localPath = $this->savePath . ($parsedUrl['path'] ?? '/index.html');
            $dir = dirname($localPath);

            if (!is_dir($dir)) {
                if (!mkdir($dir, 0777, true)) {
                    throw new Exception("Failed to create directory: $dir");
                }
            }

            file_put_contents($localPath, $content);
            $this->processedFiles[] = $normalizedUrl;
            $this->log("Saved: $localPath");
            echo json_encode(['type' => 'progress', 'data' => $this->getProgress()]);
            flush();

        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            $this->log("Error: " . $e->getMessage());
        }
    }

    private function normalizeUrl(string $url): string {
        if (!preg_match('/^https?:\/\//', $url)) {
            return $this->baseUrl . '/' . ltrim($url, '/');
        }
        return $url;
    }

    public function processPage(string $url): void {
        try {
            $normalizedUrl = $this->normalizeUrl($url);

            if (in_array($normalizedUrl, $this->processedUrls)) {
                return;
            }

            $this->processedUrls[] = $normalizedUrl;
            $this->log("Processing page: $normalizedUrl");

            $html = $this->fetchUrl($normalizedUrl);
            if ($html === null) {
                return;
            }

            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();

            $this->processResources($dom);
            $this->processLinks($dom);

        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            $this->log("Error: " . $e->getMessage());
        }
    }

    private function processResources(DOMDocument $dom): void {
        $resources = [
            'link' => 'href',
            'script' => 'src',
            'img' => 'src',
            'source' => 'srcset'
        ];

        foreach ($resources as $tag => $attr) {
            $elements = $dom->getElementsByTagName($tag);
            foreach ($elements as $element) {
                if ($element->hasAttribute($attr)) {
                    $resourceUrl = $element->getAttribute($attr);
                    if ($this->isAllowedFile($resourceUrl)) {
                        $this->saveFile($resourceUrl);
                    }
                }
            }
        }
    }

    private function isAllowedFile(string $url): bool {
        $extension = strtolower(pathinfo($url, PATHINFO_EXTENSION));
        return in_array($extension, $this->allowedExtensions);
    }

    private function processLinks(DOMDocument $dom): void {
        $links = $dom->getElementsByTagName('a');
        foreach ($links as $link) {
            if ($link->hasAttribute('href')) {
                $href = $link->getAttribute('href');
                $normalizedHref = $this->normalizeUrl($href);

                $parsedHref = parse_url($normalizedHref);
                $parsedBase = parse_url($this->baseUrl);

                if (isset($parsedHref['host']) && $parsedHref['host'] === $parsedBase['host']) {
                    $this->processPage($normalizedHref);
                }
            }
        }
    }

    public function start(): array {
        $this->log("Starting website scraping: " . $this->baseUrl);
        echo json_encode(['type' => 'start', 'data' => ['url' => $this->baseUrl]]);
        flush();
        $this->processPage($this->baseUrl);

        $summary = $this->getProgress();
        $this->log("Scraping completed. " . json_encode($summary));
        echo json_encode(['type' => 'complete', 'data' => $summary]);
        flush();
        return $summary;
    }

    public function getProgress(): array {
        return [
            'processed_urls' => count($this->processedUrls),
            'processed_files' => count($this->processedFiles),
            'failed_resources' => count($this->failedResources),
            'errors' => $this->errors
        ];
    }

    public function __destruct() {
        if ($this->logger) {
            fclose($this->logger);
        }
    }
}

// HTML form ve PHP işleme kodu
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $url = filter_input(INPUT_POST, 'url', FILTER_SANITIZE_URL);
    if (filter_var($url, FILTER_VALIDATE_URL)) {
        try {
            header('Content-Type: application/json');
            $scraper = new WebsiteScraper($url, "downloaded_site");
            $scraper->start();
        } catch (Exception $e) {
            echo json_encode(['type' => 'error', 'data' => $e->getMessage()]);
        }
        exit;
    } else {
        echo json_encode(['type' => 'error', 'data' => 'Invalid URL. Please enter a valid URL.']);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Website Scraper</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            max-width: 600px;
            margin: auto;
            background: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1 {
            text-align: center;
            color: #333;
        }
        form {
            display: flex;
            flex-direction: column;
        }
        input[type="url"] {
            padding: 10px;
            margin-bottom: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        input[type="submit"] {
            padding: 10px;
            background: #333;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        input[type="submit"]:hover {
            background: #555;
        }
        .message {
            margin-top: 20px;
            padding: 10px;
            background: #e8e8e8;
            border-radius: 4px;
            white-space: pre-line;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Website Scraper</h1>
        <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
            <input type="url" name="url" placeholder="Site adresini girin" required>
            <input type="submit" value="Kaydet">
        </form>
        <?php if (isset($message)): ?>
            <div class="message">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

