<?php
namespace addons\blackhole\controller;

use app\admin\controller\PluginAdminBaseController;
use addons\blackhole\lib\Api;

/**
 * 黑洞查询 - 后台设置
 *
 * @package addons\blackhole\controller
 * @license MIT
 */
class AdminIndexController extends PluginAdminBaseController
{
    public function initialize()
    {
        parent::initialize();
        Api::createTables();
    }

    public function index()
    {
        if (request()->isPost()) {
            $this->save();
            return $this->redirect(shd_addon_url('Blackhole://AdminIndex/index'));
        }

        $this->assign('Title', '黑洞查询');
        $this->assign('enabled', Api::isEnabled() ? '1' : '0');
        $this->assign('apiBase', Api::apiBase());
        $this->assign('defaultApi', Api::DEFAULT_API);
        $this->assign('cacheTtl', Api::cacheTtl());

        return $this->fetch('/index');
    }

    protected function save()
    {
        Api::setCfg('enabled', request()->post('enabled', '0') === '1' ? '1' : '0');

        $api = trim((string)request()->post('api_base', ''));
        if ($api === '' || filter_var($api, FILTER_VALIDATE_URL)) {
            Api::setCfg('api_base', $api);
        }

        $ttl = (int)request()->post('cache_ttl', 90);
        if ($ttl < 0) {
            $ttl = 0;
        }
        if ($ttl > 3600) {
            $ttl = 3600;
        }
        Api::setCfg('cache_ttl', (string)$ttl);
    }
}
