<?php
namespace addons\blackhole\lib;

use think\Db;

/**
 * 黑洞查询 - 公共逻辑
 *
 * 集中处理配置读写、请求签名、结果缓存与上游请求。
 * 被封禁时上游返回的 data 为结构化对象，字段含义见 README「上游返回字段」。
 *
 * @package addons\blackhole\lib
 * @license MIT
 */
class Api
{
    const DEFAULT_API = 'https://mianban.288cloud.com/ddos/api/';
    const VERSION     = '1.0.0';

    public static function prefix()
    {
        $p = (string)config('database.prefix');
        return $p !== '' ? $p : 'shd_';
    }

    public static function configTable()
    {
        return self::prefix() . 'plugin_blackhole_config';
    }

    public static function cacheTable()
    {
        return self::prefix() . 'plugin_blackhole_cache';
    }

    /* ---------------- 建表 / 删表 ---------------- */

    public static function createTables()
    {
        Db::execute(
            'CREATE TABLE IF NOT EXISTS `' . self::configTable() . '` ('
            . '`ck` varchar(64) NOT NULL,'
            . '`cv` text,'
            . 'PRIMARY KEY (`ck`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        Db::execute(
            'CREATE TABLE IF NOT EXISTS `' . self::cacheTable() . '` ('
            . '`id` int(11) unsigned NOT NULL AUTO_INCREMENT,'
            . '`ip` varchar(45) NOT NULL,'
            . '`region` varchar(10) NOT NULL,'
            . '`data` mediumtext,'
            . '`expire_time` int(11) unsigned NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (`id`),'
            . 'UNIQUE KEY `uk_ip_region` (`ip`,`region`),'
            . 'KEY `idx_expire` (`expire_time`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public static function dropTables()
    {
        Db::execute('DROP TABLE IF EXISTS `' . self::cacheTable() . '`');
        Db::execute('DROP TABLE IF EXISTS `' . self::configTable() . '`');
    }

    /* ---------------- 配置 ---------------- */

    public static function cfg($key, $default = null)
    {
        $row = Db::name('plugin_blackhole_config')->where('ck', $key)->find();
        return ($row && $row['cv'] !== null) ? $row['cv'] : $default;
    }

    public static function setCfg($key, $value)
    {
        Db::execute(
            'REPLACE INTO `' . self::configTable() . '` (`ck`,`cv`) VALUES (?,?)',
            [$key, (string)$value]
        );
    }

    // 签名密钥（首次使用时自动生成）
    public static function secret()
    {
        $s = (string)self::cfg('secret', '');
        if ($s === '') {
            $s = bin2hex(random_bytes(16));
            self::setCfg('secret', $s);
        }
        return $s;
    }

    public static function isEnabled()
    {
        return (string)self::cfg('enabled', '1') === '1';
    }

    public static function apiBase()
    {
        $u = trim((string)self::cfg('api_base', ''));
        return $u !== '' ? $u : self::DEFAULT_API;
    }

    public static function cacheTtl()
    {
        $t = (int)self::cfg('cache_ttl', 90);
        return $t < 0 ? 0 : ($t > 3600 ? 3600 : $t);
    }

    public static function regions()
    {
        return ['hk', 'usa'];
    }

    /* ---------------- 签名 ---------------- */

    public static function sign($hostid, $ip)
    {
        return hash_hmac('sha256', (int)$hostid . '|' . $ip, self::secret());
    }

    public static function verify($hostid, $ip, $sig)
    {
        if (!is_string($sig) || $sig === '') {
            return false;
        }
        return hash_equals(self::sign($hostid, $ip), $sig);
    }

    /* ---------------- 实例 IP ---------------- */

    public static function hostIp($hostid)
    {
        $row = Db::name('host')->where('id', (int)$hostid)->field('dedicatedip,assignedips')->find();
        if (!$row) {
            return '';
        }
        foreach (['dedicatedip', 'assignedips'] as $f) {
            $raw = (string)(isset($row[$f]) ? $row[$f] : '');
            if ($raw === '') {
                continue;
            }
            if (preg_match_all('/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/', $raw, $m)) {
                foreach ($m[1] as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !self::isPrivateIp($ip)) {
                        return $ip;
                    }
                }
            }
        }
        return '';
    }

    protected static function isPrivateIp($ip)
    {
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }
        $ranges = [
            ['10.0.0.0', '10.255.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
            ['127.0.0.0', '127.255.255.255'],
            ['169.254.0.0', '169.254.255.255'],
            ['100.64.0.0', '100.127.255.255'],
            ['0.0.0.0', '0.255.255.255'],
        ];
        foreach ($ranges as $r) {
            if ($long >= ip2long($r[0]) && $long <= ip2long($r[1])) {
                return true;
            }
        }
        return false;
    }

    /* ---------------- 查询（含缓存） ---------------- */

    public static function query($ip, $useCache = true)
    {
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['ok' => false, 'blackhole' => false, 'ip' => $ip, 'msg' => 'IP 无效'];
        }

        $ttl    = self::cacheTtl();
        $errors = 0;

        foreach (self::regions() as $region) {
            $json = null;
            if ($useCache && $ttl > 0) {
                $json = self::cacheGet($ip, $region);
            }
            if ($json === null) {
                $raw = self::httpGet(self::apiBase() . '?ip=' . urlencode($ip) . '&region=' . urlencode($region));
                if ($raw === null) {
                    $errors++;
                    continue;
                }
                $json = self::parseJson($raw);
                if ($json === null) {
                    $errors++;
                    continue;
                }
                if ($ttl > 0) {
                    self::cacheSet($ip, $region, $json, $ttl);
                }
            }

            if (!empty($json['data']) && is_array($json['data'])) {
                return self::format(true, $ip, $region, $json['data']);
            }
        }

        if ($errors >= count(self::regions())) {
            return ['ok' => false, 'blackhole' => false, 'ip' => $ip, 'msg' => '接口不可用'];
        }
        return ['ok' => true, 'blackhole' => false, 'ip' => $ip];
    }

    protected static function format($blackhole, $ip, $region, array $data)
    {
        return [
            'ok'         => true,
            'blackhole'  => $blackhole,
            'ip'         => $ip,
            'region'     => $region,
            'region_name' => $region === 'hk' ? '香港' : ($region === 'usa' ? '美国' : $region),
            'bw'         => isset($data['bw']) ? (string)$data['bw'] : '',
            'pps'        => isset($data['pps']) ? (string)$data['pps'] : '',
            'num'        => isset($data['num']) ? (string)$data['num'] : '',
            'start_time' => isset($data['start_time']) ? (string)$data['start_time'] : '',
            'end_time'   => isset($data['end_time']) ? (string)$data['end_time'] : '',
            'duration'   => isset($data['time']) ? (string)$data['time'] : '',
        ];
    }

    protected static function parseJson($raw)
    {
        $pos = strpos($raw, '{');
        if ($pos === false) {
            return null;
        }
        $json = json_decode(substr($raw, $pos), true);
        return is_array($json) ? $json : null;
    }

    public static function httpGet($url, $timeout = 5)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => 'zjmf-blackhole/' . self::VERSION,
            ]);
            $r = curl_exec($ch);
            curl_close($ch);
            return $r === false ? null : $r;
        }
        $ctx = stream_context_create([
            'http' => ['timeout' => $timeout],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $r = @file_get_contents($url, false, $ctx);
        return $r === false ? null : $r;
    }

    /* ---------------- 缓存 ---------------- */

    protected static function cacheGet($ip, $region)
    {
        $row = Db::name('plugin_blackhole_cache')->where('ip', $ip)->where('region', $region)->find();
        if (!$row || (int)$row['expire_time'] < time()) {
            return null;
        }
        $json = json_decode((string)$row['data'], true);
        return is_array($json) ? $json : null;
    }

    protected static function cacheSet($ip, $region, array $json, $ttl)
    {
        Db::execute(
            'REPLACE INTO `' . self::cacheTable() . '` (`ip`,`region`,`data`,`expire_time`) VALUES (?,?,?,?)',
            [$ip, $region, json_encode($json, JSON_UNESCAPED_UNICODE), time() + $ttl]
        );
        if (mt_rand(1, 100) === 1) {
            Db::execute('DELETE FROM `' . self::cacheTable() . '` WHERE `expire_time` < ?', [time()]);
        }
    }
}
