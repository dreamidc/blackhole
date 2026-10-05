<?php
namespace addons\blackhole\controller\clientarea;

use app\home\controller\PluginHomeBaseController;
use addons\blackhole\lib\Api;

/**
 * 黑洞查询 - 客户区接口
 *
 * 前台状态块异步调用此接口，返回 JSON。
 * 请求需携带 hostid / ip / sig，其中 sig 为绑定 hostid 与 ip 的 HMAC-SHA256 签名。
 *
 * @package addons\blackhole\controller\clientarea
 * @license MIT
 */
class IndexController extends PluginHomeBaseController
{
    public function status()
    {
        $hostid = (int)request()->param('hostid');
        $ip     = trim((string)request()->param('ip'));
        $sig    = (string)request()->param('sig');

        if ($hostid <= 0 || $ip === '' || !Api::verify($hostid, $ip, $sig)) {
            return json(['code' => 1, 'msg' => 'invalid']);
        }
        if (!Api::isEnabled()) {
            return json(['code' => 1, 'msg' => 'disabled']);
        }

        $result = Api::query($ip);
        if (empty($result['ok'])) {
            return json(['code' => 1, 'msg' => isset($result['msg']) ? $result['msg'] : 'error']);
        }

        return json(['code' => 0, 'data' => $result]);
    }
}
