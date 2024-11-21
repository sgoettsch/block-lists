<?php

class build
{
    private static array $cache = [];

    /** @var array|string[] $lists */
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
        self::validateOwnLists();
        self::downloadLists();
        self::combineLists();
        self::writeNewDeadHosts();
    }

    /**
     * @throws JsonException
     * @throws Exception
     */
    public static function downloadLists(): void
    {
        foreach (self::$lists as $list) {
            $source = __DIR__ . '/' . $list . '/sources.json';
            if (file_exists($source)) {
                $sourceContent = file_get_contents($source);
                if ($sourceContent) {
                    $sources = json_decode($sourceContent, true, 512, JSON_THROW_ON_ERROR);
                    foreach ($sources as $source) {
                        self::downloadList($source['url']);

                        echo '✅ List downloaded' . PHP_EOL;

                        if (!empty($source['description'])) {
                            echo 'ℹ️ List details: ' . $source['description'] . PHP_EOL;
                        }

                        if (!empty($source['urlDetails'])) {
                            echo 'ℹ️ More details for this list can be found at: ' . $source['urlDetails'] . PHP_EOL;
                        }
                    }
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

        echo '⬇️ Downloading ' . $l . PHP_EOL;

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

            if (curl_errno($curl) === 0) {
                file_put_contents($location . $filename, $content);
                return;
            }
        }

        throw new RuntimeException('⚠️ Could not download ' . $l);
    }

    private static function readAndParseList(string $l): array
    {
        $filename = md5($l) . '.txt';
        $location = __DIR__ . '/tmp/';

        echo 'ℹ️ Parsing list: ' . $location . $filename . PHP_EOL;

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
            $source = __DIR__ . '/' . $list . '/sources.json';
            if (file_exists($source)) {
                $sourceContent = file_get_contents($source);
                if ($sourceContent) {
                    $sources = json_decode($sourceContent, true, 512, JSON_THROW_ON_ERROR);
                    foreach ($sources as $source) {
                        $res = self::readAndParseList($source['url']);
                        foreach ($res as $r) {
                            if (!empty($r)) {
                                $allRecords[$r] = $r;
                            }
                        }


                        if (!empty($allRecords)) {
                            file_put_contents($hostsFile, implode("\n", $allRecords));
                        }
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

        $dir = __DIR__ . '/tmp/';
        if (!is_dir($dir) && !mkdir($dir) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('⚠️ Directory "%s" was not created', $dir));
        }

        $result = gethostbyname('google.com');
        if ($result == 'google.com' || $result == '0.0.0.0') {
            throw new RuntimeException('⚠️ Can not resolve DNS');
        }
    }

    private static function cleanRow(string $value): string
    {
        $originalValue = $value;
        $value = trim($value);

        if (empty($value)) {
            return '';
        }

        /** @noinspection HttpUrlsUsage */
        $value = trim(str_replace(['http://', 'https://', 'www.', '0.0.0.0 ', '127.0.0.1 ', "\r", "\n"], '', $value));

        foreach (['#', '[', ':', '(', '&', '"', '@', '<', '/'] as $needle) {
            if (str_starts_with($value, $needle)) {
                return '';
            }
        }

        // remove comments
        foreach (['#', '[', ':', '(', '&', '"', '@', '<', '/', ' '] as $remove) {
            $pos = strpos($value, $remove);
            if ($pos !== false) {
                $value = trim(substr($value, 0, $pos));
            }
        }

        if (in_array($value, ['localhost', '404', '127.0.0.1', 'wpad'], true)) {
            return '';
        }

        if (!empty($value)) {
            $parsed = parse_url('https://' . $value);
            if (!$parsed) {
                echo '💡 Skipping invalid domain: ' . print_r($parsed, true) . $originalValue . PHP_EOL;
                return '';
            }

            if (empty($parsed['host'])) {
                echo '💡 Skipping invalid domain: ' . $originalValue . PHP_EOL;
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

    private static function validateOwnLists(): void
    {
        foreach (self::$lists as $list) {
            $cleanList = [];

            echo 'ℹ️ Validating domains from list: ' . $list . PHP_EOL;

            $listFile = __DIR__ . '/' . $list . '/list';
            $fileContent = file_get_contents($listFile);
            if ($fileContent !== false) {
                $sourceContent = explode("\n", $fileContent);
                foreach ($sourceContent as $value) {
                    $value = trim($value);
                    if (!str_starts_with($value, '#')) {
                        $dnsValue = gethostbyname($value);

                        if ($value != $dnsValue) {
                            $cleanList[$value] = $value;
                        } else {
                            self::$cache['deadHosts'][] = $value;
                        }
                    }
                }

                $newContent = '## Generated at: ' . date('c') . PHP_EOL;
                $newContent .= implode("\n", $cleanList);
                if ($newContent != $sourceContent) {
                    file_put_contents($listFile, $newContent);
                }
            }
        }
    }

    private static function writeNewDeadHosts(): void
    {
        $deadHostsFile = __DIR__ . '/dead/hosts';

        if (file_exists($deadHostsFile)) {
            $cleanList = file($deadHostsFile);
            if (is_array($cleanList)) {
                unset($cleanList[0]);

                foreach (self::$cache['deadHosts'] as $value) {
                    $value = trim($value);
                    if (!empty($value)) {
                        $cleanList[] = $value;
                    }
                }

                $newContent = '## Generated at: ' . date('c') . PHP_EOL;
                $newContent .= implode("\n", array_unique(array_filter($cleanList)));
                file_put_contents($deadHostsFile, $newContent);
            }
        }
    }
}

try {
    build::run();
} catch (JsonException $e) {
    die(1);
}
