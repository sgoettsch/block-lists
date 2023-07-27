<?php

class build
{
    private static array $cache = [];
    private static array $lists = [
        'ads',
        'fakeNews',
        'gambling',
        'mailSpam',
        'malware',
        'scam',
        'spam',
        'telemetry',
    ];

    /**
     * @throws JsonException
     */
    public static function run(): void
    {
        self::prepare();
        self::downloadLists();
        self::combineLists();
    }

    /**
     * @throws JsonException
     * @throws Exception
     */
    public static function downloadLists(): void
    {
        foreach (self::$lists as $list) {
            $sourceContent = file_get_contents(__DIR__ . '/' . $list . '/sources.json');
            if ($sourceContent) {
                $sources = json_decode($sourceContent, true, 512, JSON_THROW_ON_ERROR);
                foreach ($sources as $source) {
                    self::downloadList($source['url']);
                }
            }
        }
    }

    /**
     * @throws Exception
     */
    private static function downloadList(string $l): void
    {
        $filename = md5($l) . '.txt';
        $location = __DIR__ . '/tmp/';

        if (file_exists($location . $filename)) {
            return;
        }

        echo 'Downloading ' . $l . PHP_EOL;

        $content = '';

        $curl = curl_init($l);
        if ($curl) {
            /** @noinspection CurlSslServerSpoofingInspection */
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_AUTOREFERER => true,
                CURLOPT_CONNECTTIMEOUT => 120,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_SSL_VERIFYHOST => false, // debug
                CURLOPT_SSL_VERIFYPEER => false, // debug
            ]);
            $content = curl_exec($curl);
            curl_close($curl);
        }

        if ($content) {
            file_put_contents($location . $filename, $content);
            return;
        }

        throw new RuntimeException('Could not download ' . $l);
    }

    private static function readAndParseList(string $l): bool|array
    {
        $filename = md5($l) . '.txt';
        $location = __DIR__ . '/tmp/';

        echo 'Parsing list: ' . $location . $filename . PHP_EOL;

        $list = [];
        $content = file($location . $filename);
        if (is_array($content)) {
            foreach ($content as $value) {
                $cleanValue = self::cleanRow($value);
                if (empty($cleanValue)) {
                    continue;
                }

                $list[] = $cleanValue;
                $list[] = 'www.' . $cleanValue;
            }
        }

        return $list;
    }

    /**
     * @throws JsonException
     */
    public static function combineLists(): void
    {
        foreach (self::$lists as $list) {
            $allRecords = [];

            $hostsFile = __DIR__ . '/' . $list . '/hosts';
            $sourceContent = file_get_contents(__DIR__ . '/' . $list . '/sources.json');
            if ($sourceContent) {
                $sources = json_decode($sourceContent, true, 512, JSON_THROW_ON_ERROR);
                foreach ($sources as $source) {
                    $res = self::readAndParseList($source['url']);
                    if (is_array($res)) {
                        foreach ($res as $r) {
                            if (!empty($r)) {
                                $allRecords[$r] = $r;
                            }
                        }
                    }

                    if (!empty($allRecords)) {
                        file_put_contents($hostsFile, implode("\n", $allRecords));
                    }
                }
            }
        }
    }

    private static function getWhiteList(): void
    {
        $content = file(__DIR__ . '/whiteList/general.source');
        if (is_array($content)) {
            foreach ($content as $c) {
                self::$cache['whiteList'][] = trim($c);
            }
        }
    }

    private static function prepare(): void
    {
        ini_set('memory_limit', '1G');

        self::getWhiteList();

        if (!mkdir($concurrentDirectory = __DIR__ . '/tmp/') && !is_dir($concurrentDirectory)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }
    }

    private static function cleanRow(string $value): string
    {
        $originalValue = $value;
        $value = trim($value);

        if (empty($value)) {
            return '';
        }

        if (in_array($value[0], ['#', '[', ':', '(', '&', '"', '@', '<', '/'], true)) {
            return '';
        }

        /** @noinspection HttpUrlsUsage */
        $value = trim(str_replace(['http://', 'https://', 'www.', '0.0.0.0 ', '127.0.0.1 ', "\r", "\n"], '', $value));

        // remove comments
        foreach (['#', '[', ':', '(', '&', '"', '@', '<', '/', ' '] as $remove) {
            $pos = strpos($value, $remove);
            if ($pos !== false) {
                $value = trim(substr($value, 0, $pos));
            }
        }

        if (in_array($value, ['localhost', '404'], true)) {
            return '';
        }

        if (!empty($value)) {
            $parsed = parse_url('https://' . $value);
            if (!$parsed) {
                echo 'Skipping invalid domain: ' . print_r($parsed, true) . $originalValue . PHP_EOL;
                return '';
            }

            if (empty($parsed['host'])) {
                echo 'Skipping invalid domain: ' . $originalValue . PHP_EOL;
                return '';
            }

            $value = trim(str_replace('www.', '', $parsed['host']));
            if (in_array($value, self::$cache['whiteList'], true)) {
                return '';
            }

            return $value;
        }

        return '';
    }
}

try {
    build::run();
} catch (JsonException $e) {
    die(1);
}
