<?php
namespace addons\blackhole;

use app\admin\lib\Plugin;
use addons\blackhole\lib\Api;

/**
 * 黑洞查询插件
 *
 * 客户前台「实例详情」页展示该实例 IP 的黑洞（DDoS 封禁）状态，点击可查看详情。
 * 钩子内只输出占位块 + 静态资源，真正的查询由客户区接口异步完成，避免阻塞详情页渲染。
 *
 * @package addons\blackhole
 * @author  梦云互联
 * @license MIT
 */
class BlackholePlugin extends Plugin
{
    # 插件基本信息
    public $info = array(
        'name'        => 'Blackhole',
        'title'       => '黑洞查询',
        'description' => '客户前台实例详情页显示 IP 黑洞（DDoS 封禁）状态，点击查看解封时间、攻击包数等详情',
        'status'      => 1,
        'author'      => '梦云互联',
        'version'     => '1.0.0',
        'module'      => 'addons',
        'lang'        => array(
            'chinese'    => '黑洞查询',
            'chinese_tw' => '黑洞查詢',
            'english'    => 'Blackhole Status',
        ),
    );

    # 插件安装
    public function install()
    {
        Api::createTables();
        Api::secret();
        if (Api::cfg('enabled', null) === null) {
            Api::setCfg('enabled', '1');
        }
        if (Api::cfg('cache_ttl', null) === null) {
            Api::setCfg('cache_ttl', '90');
        }
        return true;
    }

    # 插件卸载
    public function uninstall()
    {
        Api::dropTables();
        return true;
    }

    /*
     * 模板钩子：实例详情页「产品转移」按钮区
     * 只在有公网 IP 的实例上输出状态块
     */
    public function templateAfterServicedetailSuspended($param)
    {
        if (!Api::isEnabled()) {
            return '';
        }
        $hostid = isset($param['hostid']) ? (int)$param['hostid'] : 0;
        if ($hostid <= 0) {
            return '';
        }
        $ip = Api::hostIp($hostid);
        if ($ip === '') {
            return '';
        }

        $e = function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        };
        $sig      = Api::sign($hostid, $ip);
        $endpoint = shd_addon_url('Blackhole://Index/status', ['v' => Api::VERSION], true);

        return '<div class="bh-root" data-hostid="' . $hostid . '"'
            . ' data-ip="' . $e($ip) . '"'
            . ' data-sig="' . $e($sig) . '"'
            . ' data-url="' . $e($endpoint) . '">'
            . '<span class="bh-badge bh-loading">黑洞状态检测中…</span>'
            . '</div>'
            . '<link rel="stylesheet" href="/plugins/addons/blackhole/static/blackhole.css?v=' . Api::VERSION . '">'
            . '<script src="/plugins/addons/blackhole/static/blackhole.js?v=' . Api::VERSION . '"></script>';
    }
}
