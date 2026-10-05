# 黑洞查询（Blackhole）

魔方财务（ZJMF / idcsmart）插件：在**客户前台「实例详情」页**展示该实例 IP 的黑洞（DDoS 封禁）状态（仅辰迅所属IP可用），
点击可查看封堵时间、解封时间、封堵次数、攻击流量与攻击包数。

## 特性

- **不拖慢详情页**：模板钩子里只输出一个占位块 + JS/CSS，不做任何外部请求
- **异步加载**：状态由客户区接口异步获取，接口挂了前台静默隐藏，不影响详情页渲染
- **防伪造请求**：接口参数带 HMAC-SHA256 签名（绑定 `hostid` + `ip`），客户端无法篡改要查询的 IP
- **结果缓存**：按 `IP + 线路` 缓存（默认 90 秒），可配置，显著降低对上游的压力
- **超时降级**：上游超时/异常时接口返回错误码，前台不显示任何内容
- **后台可配**：启用开关、接口地址、缓存时长
- **可卸载干净**：卸载时自动删除插件自建的两张表，不触碰任何魔方核心表

## 环境要求

- 魔方财务系统（ZJMF）
- PHP >= 7.1（已在 PHP 7.3 上验证）
- 支持 `curl` 扩展（缺失时自动回退到 `file_get_contents`）

## 安装

1. 在您的魔方财务站点public/plugins/addons目录**新建文件夹名为 `blackhole`**
2. 将此仓库下载为压缩包放到 `public/plugins/addons/blackhole` 下并解压，最终路径为：

   ```
   public/plugins/addons/blackhole/
   ```

3. 确保目录属主与站点一致（如 `chown -R www:www`）
4. 后台 →「插件管理」→ 找到「黑洞查询」→ **安装**并**启用**

> 目录名必须是 `blackhole`，插件静态资源通过 `/plugins/addons/blackhole/static/` 访问。

安装会创建两张插件自有表：

- `shd_plugin_blackhole_config` —— 配置（含自动生成的签名密钥）
- `shd_plugin_blackhole_cache` —— 上游结果缓存

表名前缀自动读取站点 `database.prefix` 配置，非 `shd_` 前缀的站点无需改动。

## 后台配置

后台 →「黑洞查询」：

| 配置项 | 说明 |
| --- | --- |
| 启用 | 关闭后前台实例详情页不再显示黑洞状态 |
| 接口地址 | 上游查询接口，留空使用内置默认值 |
| 缓存秒数 | 同一 IP + 线路在该时长内不重复请求上游；`0` 表示不缓存 |

## 工作原理

```
实例详情页模板
   └─ hook: template_after_servicedetail_suspended
        └─ 输出占位块 <div class="bh-root" data-ip data-sig data-url>
             └─ 前端 JS 异步 GET 客户区接口 Blackhole://Index/status
                  └─ 校验 HMAC 签名
                       └─ 查缓存 → 未命中则请求上游（hk / usa 依次尝试，命中即止）
                            └─ 返回 JSON → 渲染状态徽标 + 详情弹窗
```

设计取舍：

- **钩子里绝不请求外部接口**，否则会同步阻塞详情页渲染
- **只有页面被打开时才查询**，没有定时任务，不会遍历全站 IP
- 上游正常 IP 会请求 2 次（hk、usa 各一次），被封 IP 命中即止；结果均写入缓存

### 上游返回字段

被封禁时上游 `data` 为结构化对象，插件直接使用以下字段（不解析其返回的 HTML 表格）：

| 字段 | 含义 |
| --- | --- |
| `bw` | 攻击流量，如 `2446.32 Mbps` |
| `pps` | 攻击包数，如 `1714924 pps` |
| `num` | 封堵次数 |
| `start_time` | 封堵时间 |
| `end_time` | 解封时间 |
| `time` | 封堵时长 |

## 目录结构

```
blackhole/
├─ BlackholePlugin.php            主文件：插件信息 / 安装 / 卸载 / 前台钩子
├─ menu.php                       后台菜单
├─ controller/
│  ├─ AdminIndexController.php    后台设置
│  └─ clientarea/
│     └─ IndexController.php      客户区 JSON 接口
├─ lib/
│  └─ Api.php                     公共逻辑：配置、签名、缓存、请求上游
├─ template/
│  └─ admin/index.tpl             后台设置模板
└─ static/
   ├─ blackhole.css
   └─ blackhole.js                前台脚本（无第三方依赖）
```

## 二次开发

- **新增线路**：编辑 `lib/Api.php` 中的 `Api::regions()` 数组
- **更换上游**：后台「接口地址」即可，或在 `Api::DEFAULT_API` 修改默认值
- **修改样式**：`static/blackhole.css`；前台脚本不依赖任何框架或 CDN

## 常见问题

**Q：前台没显示状态块？**

依次检查：插件是否已启用；该实例 `shd_host.dedicatedip` 是否有公网 IPv4（无 IP 的实例不显示）；
该产品类型的详情页模板是否包含 `template_after_servicedetail_suspended` 钩子。

**Q：显示「黑洞中」但数据不对？**

数据直接来自上游第三方接口，插件不做缓存以外的任何加工。可在后台临时把缓存秒数设为 `0` 验证。

**Q：会影响详情页速度吗？**

不会。钩子内只有一次本地数据库查询（按主键取 IP），外部请求全部在异步接口中完成。

## 数据源说明与免责声明

本插件的数据来自**第三方接口**，作者不生产也不保证该数据的准确性与服务可用性。
上游接口地址可在后台配置，若上游变更、限流或停止服务，插件将降级为不显示（不影响站点其他功能）。

本项目基于 [MIT](LICENSE) 协议开源，**按「现状」提供，不附带任何明示或暗示的担保**。
使用者应自行评估合规性与适用性，因使用本插件产生的任何后果由使用者自行承担。

## 开源协议

[MIT](LICENSE)
