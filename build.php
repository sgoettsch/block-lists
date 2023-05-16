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

        echo 'Downloading ' . $l . PHP_EOL;

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_AUTOREFERER => true,
            CURLOPT_CONNECTTIMEOUT => 120,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_MAXREDIRS => 10,
        ];
        $content = '';

        $curl = curl_init($l);
        if ($curl) {
            curl_setopt_array($curl, $options);
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

        $content = file($location . $filename);
        if (is_array($content)) {
            foreach ($content as $key => $value) {
                $value = str_replace("\t", ' ', $value);
                $value = preg_replace('!\s+!', ' ', $value);
                /** @noinspection SpellCheckingInspection */
                $remove = [
                    '127.0.0.1 localhost',
                    '127.0.0.1 localhost.localdomain',
                    '127.0.0.1 local',
                    '255.255.255.255 broadcasthost',
                    '::1 localhost',
                    '::1 ip6-localhost',
                    '::1 ip6-loopback',
                    'fe80::1%lo0 localhost',
                    'ff00::0 ip6-localnet',
                    'ff00::0 ip6-mcastprefix',
                    'ff02::1 ip6-allnodes',
                    'ff02::2 ip6-allrouters',
                    'ff02::3 ip6-allhosts',
                    '0.0.0.0 0.0.0.0',
                    '::1 ip6-localhost ip6-loopback',
                    'fe00::0 ip6-localnet',
                    '#fe80::1%lo0 localhost',
                    '0.0.0.0',
                    '127.0.0.1',
                ];
                foreach ($remove as $r) {
                    if ($r === trim($value)) {
                        unset($content[$key]);
                        continue 2;
                    }
                }

                $remove = [
                    '0.0.0.0 ',
                    '127.0.0.1 ',
                ];
                foreach ($remove as $r) {
                    $pos = strpos($value, $r);
                    if ($pos === 0) {
                        $value = trim(substr($value, strlen($r)));
                    }
                }
                $content[$key] = $value;
            }
        }

        return $content;
    }

    /**
     * @throws JsonException
     */
    public static function combineLists(): void
    {
        foreach (self::$lists as $list) {
            $allRecords = file(__DIR__ . '/' . $list . '/list');
            if (!$allRecords) {
                $allRecords = [];
            }
            $hostsFile = __DIR__ . '/' . $list . '/hosts';
            $sourceContent = file_get_contents(__DIR__ . '/' . $list . '/sources.json');
            if ($sourceContent) {
                $sources = json_decode($sourceContent, true, 512, JSON_THROW_ON_ERROR);
                foreach ($sources as $source) {
                    $res = self::readAndParseList($source['url']);
                    if (is_array($res)) {
                        foreach ($res as $r) {
                            $allRecords[] = $r;
                        }
                    }
                    $records = self::cleanupList($allRecords);
                    if (!empty($records)) {
                        file_put_contents($hostsFile, implode("\n", $records));
                    }
                }
            }
        }
    }

    private static function cleanupList(array $rows): array
    {
        $whitelist = self::getWhiteList();
        $newContent = [];
        foreach ($rows as $row) {
            $tmp = $row;

            $row = self::cleanString($row);
            /** @noinspection HttpUrlsUsage */
            $row = trim(str_replace(['http://', 'https://', 'www.'], '', $row));

            if (!empty($row)) {
                $parsed = parse_url('https://' . $row);
                if (!$parsed) {
                    echo 'Skipping invalid domain: ' . print_r($parsed, true) . PHP_EOL;
                    continue;
                }
                $domain = trim(str_replace('www.', '', $parsed['host']));
                if ($domain === 'www.' || str_starts_with($domain, '-') || str_contains($domain, ' ') || !preg_match(
                    "/[a-z]/i",
                    $domain
                )) {
                    echo 'Skipping invalid domain: ' . $tmp . PHP_EOL;
                    continue;
                }

                if (!empty($domain) && !in_array($domain, $whitelist, true)) {
                    $newContent['www.' . $domain] = 'www.' . $domain;
                    $newContent[$domain] = $domain;
                }
            }
        }

        asort($newContent);

        return $newContent;
    }

    private static function cleanString(string $row): string
    {
        foreach (['#', '[', ':', '(', '&', '"', '@', '<', '/', ' '] as $remove) {
            $pos = strpos($row, $remove);
            if ($pos !== false) {
                $row = substr($row, 0, $pos);
            }
        }

        $row = preg_replace("/(\p{Han}|\p{Katakana}|\p{Hiragana})+/u", '', $row);

        return strtolower(rtrim(str_replace([',', '@', '%', '*'], '', $row), '.'));
    }

    private static function getWhiteList(): array
    {
        if (isset(self::$cache['whiteList'])) {
            return self::$cache['whiteList'];
        }

        $content = file(__DIR__ . '/whiteList/general.source');
        if (is_array($content)) {
            foreach ($content as $c) {
                self::$cache['whiteList'][] = trim($c);
            }
        }

        return self::$cache['whiteList'];
    }

    /**
     * @throws JsonException
     */
    public static function run(): void
    {
        self::prepare();
        self::downloadLists();
        self::combineLists();
    }

    private static function prepare(): void
    {
        ini_set('memory_limit', '1G');

        if (!mkdir($concurrentDirectory = __DIR__ . '/tmp/') && !is_dir($concurrentDirectory)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }
    }
}

try {
    build::run();
} catch (JsonException $e) {
    die(1);
}
