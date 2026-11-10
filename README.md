# watch-laravel

Watch 嘅來源端套件。裝入 Laravel app 之後，每個 HTTP request 同佢期間寫嘅應用日誌會用同一個 `request_id` 關聯起嚟，經本機 agent 送去你自架嘅 Watch central。

- 業務路徑 fail-open：capture、遮罩或者傳送出錯，都唔會改個 HTTP response。
- 敏感欄位喺離開來源 app 之前已經遮罩。
- 來源 app 唔需要 queue worker。

## 安裝

```bash
composer require skyhilam/watch-laravel
```

`.env` 加一行。`WATCH_TOKEN` 喺 Watch central 嘅項目頁複製：

```dotenv
WATCH_TOKEN=wt_xxxxxxxxxxxxxxxx
```

Service provider 靠 package discovery 自動載入，唔使改 `config/app.php`。

最後起 agent（下面「Agent」一節）。冇 agent 嘅時候事件會留喺本機 spool，app 照常運作。

### 可選：發佈設定檔

```bash
php artisan watch:install
```

會發佈 `config/watch.php` 並列印 agent 設定指引。冇 `--force` 就唔會覆寫已有嘅檔。呢個指令唔會啟動 agent。

## 支援版本

下表係 CI 每次都實際跑 PHPUnit 嘅組合。冇列出嘅組合未驗證，唔算支援。

| Laravel | PHP |
| --- | --- |
| 8（`^8.83`） | 8.1 |
| 9 | 8.1、8.2 |
| 10 | 8.1、8.2、8.3 |
| 11 | 8.2、8.3、8.4 |
| 12 | 8.2、8.3、8.4 |
| 13 | 8.3、8.4 |

- PHP 7、PHP 8.0 唔支援。PHP 8.5 同 Laravel 14 未驗證。
- Monolog 2（Laravel 8、9）同 Monolog 3（Laravel 10 或以上）都得。
- Laravel 8 至 11 已經冇官方安全維護。套件裝得落去，唔代表呢啲框架版本仲有安全修補。

## `WATCH_TOKEN`

一個字串入面有 central 網址同 project key。格式：

```
wt_ + base64url（冇 padding）( {"v":1,"url":"https://watch.example.com","key":"<project key>"} )
```

- `v` 一定係 `1`。
- `url` 係 central 嘅 base URL，冇尾 `/`。
- `key` 係該 project／environment 嘅 key。
- 可以有 `endpoint` 字串，用嚟指定本機 agent endpoint。

套件由 token 填以下設定：

| 設定 | 值 |
| --- | --- |
| `watch.agent.central_url` | `url` 加 `/api/v1/ingest` |
| `watch.agent.project_key` | `key` |
| `watch.transport.endpoint` | token 有 `endpoint` 先填。冇就用預設 Unix socket `storage/watch/agent.sock` |

Token 入面有 project key，當密碼咁處理：放 `.env`，唔好 commit。

### 明確設定優先

以下環境變數仍然有效。有設定，或者已發佈嘅 `config/watch.php` 入面相應嘅值唔係 `null` 或空字串，就蓋過 token 推算出嚟嘅值：

| 環境變數 | 蓋過 |
| --- | --- |
| `WATCH_CENTRAL_URL` | 完整 ingest 位址，例如 `https://watch.example.com/api/v1/ingest` |
| `WATCH_PROJECT_KEY` | project key |
| `WATCH_AGENT_ENDPOINT` | 本機 agent endpoint，`unix:///path/agent.sock` 或者 `tcp://127.0.0.1:9427` |
| `WATCH_SPOOL_PATH` | spool 目錄，預設 `storage/watch/spool` |

## Fail-open

- 冇設定 `WATCH_TOKEN`：app 照常起，唔記 warning。
- `WATCH_TOKEN` 格式錯：略過個 token，app 照常起。每個 process 只記一次 warning，warning 唔包含 token 或 key。
- 連唔到 agent 或者寫入超時：事件改寫入本機 spool，之後由 agent 補送。
- Spool 滿、寫唔到、或者過期：事件會 drop，並按原因計數報上 central。唔會靜默當成已錄。
- 以上任何情況，業務 HTTP response 都唔會變，亦唔會因為套件而出 5xx。

`sample_rate` 預設 `1.0`，意思係「意圖全量」，唔係「保證全量」。

連線同寫入超時預設 100ms 同 300ms。呢兩個數值係起點，未做過效能實測。

## 遮罩

遮罩喺離開來源 app 之前做，所有環境都生效，包括 local。被遮罩嘅值會變成 `[redacted]`。Key 比對唔分大小寫，`-` 同 `_` 視為相同，嵌套 array 都會處理。

預設遮罩嘅 key：

```
authorization  proxy-authorization  cookie  set-cookie
x-xsrf-token   x-csrf-token         x-api-key  api-key  x-auth-token  php-auth-pw
password       password_confirmation  current_password
_token         remember_token  token  access_token  refresh_token  id_token
api_key        apikey  secret  client_secret  signature
card_number    cvv
```

另外：

- Key 名入面有 `password`、`passwd`、`secret`、`token`、`apikey` 或者 `api_key`（例如 `stripe_secret_key`）一樣會遮罩。
- Request payload 預設唔錄（`capture_payload=false`）。開咗之後都係先遮罩先送出。
- Query string 唔會寫入 path。`Referer`、`Origin`、`Location` 呢類 URL header 會去走 query、fragment 同 userinfo。
- 日誌嘅 context 會先遮罩。
- `watch.redact` 只可以加 key。刪走預設 key 唔會令佢失效。

## Agent

Agent 係一個獨立長駐行程，負責收來源 app 嘅事件、寫入本機 spool、再分批用 HTTPS 送去 central：

```bash
php artisan watch:agent
```

Agent 一定要係自己一個行程，由 systemd 或者 Supervisor 監管。唔好放喺：

- PHP-FPM worker
- Octane worker
- 業務 queue worker（包括 Horizon 管嘅 worker）

唔好由 job、排程 closure 或者 web request 入面叫 `watch:agent`。指令只會喺 CLI SAPI 跑。

其他要點：

- 一個 socket 只可以有一個 agent。
- 預設聽 Unix socket `storage/watch/agent.sock`。TCP 只接受 loopback（`127.0.0.0/8`、`::1`、`localhost`）。
- Central 位址要係 `https`。明文 `http` 只可以去本機。
- 來源 app 同 agent 共用 `storage/watch`，兩邊嘅使用者都要有讀寫權限。
- 部署新版本之後要重啟 agent。
- 裝咗 `pcntl` 嘅話，`SIGTERM`／`SIGINT` 會令 agent 做完手上嗰輪先結束。

Supervisor 範例（`numprocs` 一定係 `1`，路徑同使用者按主機修改）：

```ini
[program:watch-agent]
command=/usr/bin/php /var/www/app/artisan watch:agent
directory=/var/www/app
user=www-data
numprocs=1
autostart=true
autorestart=true
stopsignal=TERM
stopwaitsecs=15
```

systemd 範例：

```ini
[Unit]
Description=Watch agent
After=network-online.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/app
ExecStart=/usr/bin/php artisan watch:agent
Restart=always
RestartSec=2
KillSignal=SIGTERM
UMask=0007

[Install]
WantedBy=multi-user.target
```

## 開發

```bash
composer install
vendor/bin/phpunit
```

`phpunit.xml.dist` 喺 PHPUnit 9.5 至 13 都用得。

## License

MIT。見 [LICENSE](LICENSE)。
