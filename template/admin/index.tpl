<section class="admin-main">
  <div class="container-fluid">
    <div class="page-container">
      <div class="card">
        <div class="card-body">
          <div class="card-title row">
            <div style="padding:0 15px;">{$Title}</div>
            <div class="col-lg-8 col-md-12 col-sm-12">
              {foreach $PluginsAdminMenu as $v}
              <span class="ml-2"><a class="h5" href="{$v.url}">{$v.name}</a></span>
              {/foreach}
            </div>
          </div>

          <div class="bh-admin">
            <form method="post" action="{:shd_addon_url('Blackhole://AdminIndex/index')}">
              <h4>设置</h4>
              <div class="bh-field">
                <label>启用</label>
                <div>
                  <input type="checkbox" name="enabled" value="1" {if $enabled == '1'}checked{/if}>
                  <span class="tip">关闭后前台实例详情页不再显示黑洞状态</span>
                </div>
              </div>
              <div class="bh-field">
                <label>接口地址</label>
                <div>
                  <input type="text" name="api_base" value="{$apiBase}" style="width:520px" placeholder="{$defaultApi}">
                  <div class="tip">留空则用默认：{$defaultApi}</div>
                </div>
              </div>
              <div class="bh-field">
                <label>缓存秒数</label>
                <div>
                  <input type="number" name="cache_ttl" value="{$cacheTtl}" min="0" max="3600" style="width:120px">
                  <span class="tip">同一 IP 在此时长内不重复请求上游（0 = 不缓存）</span>
                </div>
              </div>
              <div class="bh-field">
                <label></label>
                <div><button type="submit" class="btn btn-primary">保存</button></div>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
