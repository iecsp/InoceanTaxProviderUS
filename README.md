# InoceanTaxProviderUS 2.0.0

Shopware 6.7 的美国普通实物商品销售税插件。当前经过验证的官方数据源为 **California (CA)** 和 **Washington (WA)**。不能把本插件宣传成覆盖全美所有商品税类的合规引擎。

本商店当前适用范围：加州 nexus，普通实物商品。食品尚未实现自动税类判断；销售食品前需要另行配置和验证税务规则。后台默认 nexus 为 `CA`，实际计税及地址外发核验默认关闭。

## 计算与数据架构

```text
后台同步按钮 / CLI
  → Shopware async 消息队列
  → 固定政府 HTTPS 数据源（限制重定向、下载大小和超时）
  → 完整格式、税率、日期、重复／区间重叠检查
  → 数据库暂存 → 原子切换 active_dataset_id

结算
  → 按销售渠道读取配置及 nexus
  → CA：当前数据版本 + 本地已核验地址缓存
       缓存未命中：只入队后台地址核验，阻止下单并提示稍后刷新
  → WA：按州、ZIP+4、有效期查索引
  → 计算商品、折扣、所有配送及支付总额
  → 随订单保存税额和数据版本快照
```

订单计算不下载、不解析完整税表、不调用远程 HTTP。每次计税最多解析一个目的地税率，商品数量不会增加地理税率查询次数。DBAL 直接访问专用表，不向 Shopware 原生 `tax` 表灌入数十万地区记录。无需增加 Redis 层；版本索引查询避免缓存失效与长驻 worker 状态残留问题。

五张新表全部使用 `ino_` 前缀：

| 表 | 用途 |
| --- | --- |
| `ino_usa_tax_source` | 各州当前版本、同步任务及状态 |
| `ino_usa_tax_dataset` | 数据版本、来源、有效期、校验和、记录数 |
| `ino_usa_tax_rate` | WA ZIP+4 高低区间及州／地方／合计税率 |
| `ino_usa_tax_jurisdiction` | CA 官方城市／县合计税率 |
| `ino_usa_tax_address` | CA 地址指纹、已核验 TAC 与税率、状态和核验时间 |

`ino_usa_tax_rate` 主键为 `(dataset_id, zip, plus4_low, plus4_high)`。税率按百分数存储为 `DECIMAL(9,6)`，避免把 `0.0875` 和 `8.75` 混用。订单使用标准 `CalculatedTaxCollection`，每个合计税率只有一项，保存／重新读取不会因相同分项税率覆盖而丢税。

## 官方来源与覆盖

- [California CDTFA 城市／县税率](https://cdtfa.ca.gov/taxes-and-fees/rates.aspx)：同步当前季度全部有效数值记录；带 `*` 的县级汇总行没有可直接使用的税率，明确跳过。页面结构、有效期或记录数异常时拒绝发布。
- [CDTFA 地址核验 API](https://services.maps.cdtfa.ca.gov/)：核验真实税区，不能通过邮寄城市名称或 ZIP 猜测。只接受唯一结果、High confidence、Good match、Rooftop/Parcel 精度；同时检查该县税率与同步版本一致。边界多结果一律拒绝。
- [Washington DOR 可下载数据库](https://dor.wa.gov/taxes-rates/sales-use-tax-rates/downloadable-database)：自动发现当前季度的 ZIP+4 Short 文件，校验全部区间。要求完整 ZIP+4，保留前导零。

2026-11-01 实测版本：2026-10-01 至 2026-12-31；CA 541 条数值税率地区、WA 167,832 条 ZIP+4 区间。各州核验日期按 America/Los_Angeles 日历计算，持久化时间为 UTC。未来季度需再次同步。

校验和：CA 为标准化有效期和税率记录的 SHA-256；WA 为官方 ZIP 压缩文件的 SHA-256。校验和用于识别版本与审计，不能替代政府数字签名。相同版本重复同步不会切换数据库版本或使地址缓存失效。

## 隐私与首次地址核验

默认关闭 `OfficialAddressVerification`。启用表示允许后台任务将加州收货地址的**街道、城市和 ZIP**发送到 CDTFA；不发送姓名、邮箱、购物内容、订单金额。请先阅读 [CDTFA 使用条款](https://www.cdtfa.ca.gov/dataportal/policy.htm) 和 [隐私说明](https://www.cdtfa.ca.gov/privacy.htm)。

地址缓存只保存 SHA-256 地址指纹、税区、税率及时间，队列只保存本地地址 ID、指纹、版本和销售渠道 ID。后台 worker 执行前重新检查核验开关与地址指纹，地址已修改／删除或授权已关闭时不再发送。HTTP 客户端不接入共享 profiler，网络异常重新包装后不携带含地址的 URL。

缓存仅在**同一个太平洋日历日及同一税率版本**有效。未命中时后台异步核验；此时结算提示稍后刷新，并阻止最终下单，不回退到虚构的 10% 税率。当天后续同地址使用本地结果。需要持续运行 `async` worker；API 或队列不可用时未核验地址不能下单。模糊地址不会自动选择较低或较高税率。失败记录有五分钟冷却，避免每次刷新重复请求。

## 安装与配置

依赖 PHP >=8.4、ext-zip、DOM、mbstring 和项目自带 Symfony HTTP Client。运行验证基线为 Shopware **6.7.15.1 / PHP 8.4.26 / MySQL 8.0**。Composer 已为 6.8 草案保留依赖范围；**没有完成正式 6.8 运行验证**。

在本项目中部署时执行：

```sh
docker exec maginut-app php bin/console plugin:refresh
docker exec maginut-app php bin/console plugin:install --activate InoceanTaxProviderUS
docker exec maginut-app php bin/console cache:clear
docker exec maginut-app php bin/console assets:install
```

发布包包含已编译的后台资源。

### 已安装旧名称的站点

部署前应保留现有插件注册记录的 ID 和启用状态，并将类名、包名、目录和自动加载配置迁移到新名称。同步迁移 `InoceanSalesTaxesUsa.config.*` 的全局及销售渠道设置、已登记的迁移类和计税服务标识。切换前先处理仍引用旧 PHP 类名的队列消息，切换后清理缓存并重新安装静态资源。Shopware 按入口类匹配插件记录，因此应先迁移注册记录，再执行 `plugin:refresh`。

后台进入 **Extensions → My extensions → Inocean Tax Provider (US) → Configure**：

1. nexus 填 `CA`。这个配置必须反映商家实际代收义务；不在 nexus 内的托管商品按零税率处理。
2. 选择 California，点击“同步官方税率”。可查看任务状态、有效期、版本、来源及记录数；页面会轮询任务状态。
3. 阅读地址核验外发说明后启用该开关。确认 `async` worker 正常，再启用计税。
4. 普通应税商品使用 `(US) COMBINED TAX`；确认免税的商品使用 `(US) TAX-FREE`。原生其他税类保持其原始计算结果。
5. 需要本插件计算运费时，将运输方式设置为固定税率并选择该托管税类；核对“Tax managed shipping charges”是否符合实际运费处理方式。运费应税性不会自动推断。
6. 使用实际经营所需的地址、折扣和运费场景完成商家验收，再对外使用。

新建的托管税类名义税率为 0%，真实税率由 provider 决定；计税未启用或配置不完整时，含托管税类的美国订单会被阻止。升级不强制改写旧税类或商品配置。

后台状态接口需要 `system_config:read`，POST 同步接口需要 `system_config:update`，两者都需要后台身份认证；GET 不能触发同步。来源由 CA/WA 固定白名单决定，不能提交任意 URL。已排队任务去重，超过十五分钟的中断任务可再次点击同步恢复；正在运行时数据库锁防止重复发布。

## 运维

```sh
# 同步 CA；也可作为计划任务运行，每天执行会检查版本是否变化
docker exec maginut-app php bin/console ino:usa-tax:sync --state=CA
# 可选 WA
docker exec maginut-app php bin/console ino:usa-tax:sync --state=WA
# 持续消费（交由现有进程管理器管理）
docker exec maginut-app php bin/console messenger:consume async --time-limit=3600
# 保留每州最近三个版本及当前活跃版本，清理七天前的地址核验缓存
docker exec maginut-app php bin/console ino:usa-tax:prune --keep=3
```

同步失败保持旧版本；旧版本过期后阻止依赖该地区税率的新订单。排查服务器同步日志、worker 和后台有效期。地址核验 `rejected` 表示官方定位不唯一、精度不足或与当前版本不一致，不能当作免税。

卸载并保留数据时停用 provider；完全卸载删除五张插件表。两种卸载方式均保留可能被商品、运输方式或历史数据引用的原生税类。卸载前停止／处理本插件的队列任务，商品和运输税务配置改回可工作的替代方案。

## 明确边界

- 其他 nexus 州尚未接入官方适配器，会阻止依赖其税率的结算；不沿用来源不明的旧全国 JSON。
- 不自动处理食品、餐饮、运费法定例外、税收假期、商品特定税种、远程卖家门槛、申报分配和代报税。食品上架前需补充产品税类与规则测试。
- 支持含税与未税价格、混合免税商品折扣、多笔同地址配送；运费独立计税。无明确 composition 的促销、仅运费促销、嵌套商品以及跨税区分单目前会明确阻止，避免猜测。
- 历史订单读取和文档使用已保存金额。**后台历史订单税额重算明确拒绝**：CDTFA 当前地址接口没有历史日期参数，不能拿今天的税率覆盖原订单；需要经过审核的历史税额调整流程。不会自动修正或重算旧插件生成的历史订单。
- 后台采用原生订单、店面和文档的标准税额展示；已移除旧版大量整块模板覆盖。税额／版本快照保留在行项目 payload 中；没有编造不存在的税务申报报表功能。

## 测试

离线测试不访问政府接口、不创建订单、不发送短信。数据库测试必须显式开启，并仅创建／删除 `ino_usa_test_<随机值>` 数据库。

```sh
# 在本项目已安装 PHPUnit 或指定 PHAR 路径后执行：
docker exec maginut-app php /path/to/phpunit.phar -c custom/plugins/InoceanTaxProviderUS/phpunit.xml.dist
docker exec -e INO_USA_DB_TESTS=1 maginut-app php /path/to/phpunit.phar -c custom/plugins/InoceanTaxProviderUS/phpunit.xml.dist
docker exec maginut-app node --test custom/plugins/InoceanTaxProviderUS/tests/administration.test.mjs
# PHPStan 2，level 5，无基线抑制
docker exec maginut-app php /path/to/phpstan.phar analyse -c custom/plugins/InoceanTaxProviderUS/phpstan.dist.neon
# Shopware 容器、路由认证、配置 XSD、async 路由（不安装插件）
docker exec maginut-app php custom/plugins/InoceanTaxProviderUS/tests/kernel-smoke.php
# 以下为显式联网检查，仅使用公开资料及 CDTFA 自己的公开示例地址：
docker exec maginut-app php custom/plugins/InoceanTaxProviderUS/tests/live-smoke.php
docker exec maginut-app php custom/plugins/InoceanTaxProviderUS/tests/live-database-smoke.php
```

后台 Node 测试使用项目 Shopware Administration 自带 Vue 编译器；需其 npm dependencies。独立插件 checkout 可 `composer install` 后运行 `composer test`，数据库和 Shopware kernel 检查则需要完整项目环境。

许可证：[MIT](LICENSE.md)。
