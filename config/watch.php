<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch for capture. Business paths stay fail-open either way.
    |
    */

    'enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | Sample rate
    |--------------------------------------------------------------------------
    |
    | 1.0 係「意圖全量」，唔係「保證全量」。
    | 任何 sampling、quota、spool drop 或 timeout 都要帶 completeness 訊號，
    | 唔可以靜默當成已經錄到。
    |
    | 入口只決定一次。被 sample out 嘅 request 唔會產生 request 封套。
    |
    */

    'sample_rate' => 1.0,

    /*
    |--------------------------------------------------------------------------
    | Sample seed
    |--------------------------------------------------------------------------
    |
    | Null 用非確定抽樣。整數 seed 令部分抽樣可以重現，測試會設定佢。
    | 預設 null 唔會改變 sample_rate=1.0 嘅「意圖全量」行為。
    |
    */

    'sample_seed' => null,

    /*
    |--------------------------------------------------------------------------
    | Redact
    |--------------------------------------------------------------------------
    |
    | 離開來源 app 之前遮罩。所有環境都生效，包括 local。
    | 比對唔分大小寫，嵌套 array 都要處理。遮罩後嘅值係 [redacted]。
    |
    | 下面嘅預設 key 永遠生效。呢度只可以加 key，唔可以透過刪走設定嚟移除預設。
    |
    */

    'redact' => [
        'Authorization',
        'Cookie',
        'Proxy-Authorization',
        'X-XSRF-TOKEN',
        'password',
        'password_confirmation',
        '_token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Capture payload
    |--------------------------------------------------------------------------
    |
    | Request payload 預設不錄。開啟之後仍然先遮罩，先可以離開來源 app。
    | Query string 唔會寫入 path，亦唔會當成 payload。
    |
    */

    'capture_payload' => false,

    /*
    |--------------------------------------------------------------------------
    | Log buffer limit
    |--------------------------------------------------------------------------
    |
    | 每個 request 自己嘅日誌 buffer。超出嘅紀錄唔會送出，會計 log_buffer_overflow。
    |
    */

    'log_buffer_limit' => 200,

    /*
    |--------------------------------------------------------------------------
    | Transport（來源 app → 本地 agent）
    |--------------------------------------------------------------------------
    |
    | endpoint 為 null 時用 Unix socket storage_path('watch/agent.sock')。
    | 可以寫 unix:///path/to/agent.sock，或者 tcp://127.0.0.1:9427。
    | TCP 只准 loopback（127.0.0.0/8、::1、localhost）。設定咗非 loopback host，
    | 來源 app 會拒絕送出（計 transport_error），agent 亦會拒絕啟動。
    |
    | 超時數值係提案起點，**未實測，唔係已測結果**：
    | 連線提案範圍 50–200ms（預設 100），寫入提案範圍 200–500ms（預設 300）。
    | 實測之後先可以寫成已驗證。
    |
    | 送唔到 agent（連唔到或者寫入超時）就寫入下面嘅 spool；spool 都寫唔到就
    | drop，並按原因計數。
    |
    */

    'transport' => [
        'endpoint' => env('WATCH_AGENT_ENDPOINT'),
        'connect_timeout_ms' => 100,
        'write_timeout_ms' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Spool（有界本地緩衝）
    |--------------------------------------------------------------------------
    |
    | 檔案式，來源 app 同 agent 共用同一個目錄（path 為 null 時係
    | storage_path('watch/spool')），兩邊都要有讀寫權限。寫入用 tmp 檔 +
    | rename，係原子操作。
    |
    | 三個上限任何一個觸頂都會 drop 並計 spool_full；寫入失敗或 ENOSPC 計
    | spool_enospc；超過 max_age_seconds 嘅檔會刪走並計 spool_expired。
    | 每一個計數都係 completeness 訊號，唔會靜默當成已錄。
    |
    | 來源 app 嘅 drop 計數會寫入同一個目錄嘅 drops.ledger（一個細檔，唔計入
    | 上面三個上限），等 agent 報上中央。PHP-FPM 每個 request 完結就冇晒記憶體
    | 狀態，所以計數唔可以只放喺記憶體。寫 ledger 係盡力而為，唔係保證。
    |
    */

    'spool' => [
        'path' => env('WATCH_SPOOL_PATH'),
        'max_bytes' => 50 * 1024 * 1024,
        'max_files' => 1000,
        'max_age_seconds' => 86400,
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent（php artisan watch:agent）
    |--------------------------------------------------------------------------
    |
    | Agent 係獨立長駐行程，由 systemd 或 Supervisor 監管。唔可以住喺 PHP-FPM、
    | Octane worker、或者業務 queue worker 入面。只准喺 CLI SAPI 跑。
    | 部署範例喺 docs/agent.md。
    |
    | central_url 必須係 https；明文 http 只可以係 loopback。否則拒絕啟動。
    | project_key 用 Bearer header 送出，放喺 .env，唔好寫入 repo。
    |
    | 重試係指數 backoff + jitter：delay 由 retry.base_ms 開始每次加倍，上限係
    | retry.max_ms；重試時 ingest_id 唔變。中央答 400／413／422（批次本身有
    | 問題）先會 drop 並計 transport_error；其他失敗（連唔到、5xx、429、401、
    | 404、轉址）一律留喺 spool 再試，最多等到 spool.max_age_seconds。
    |
    | quota.events_per_minute 係每把 project key 嘅本地配額，針對 agent 經
    | socket 收到嘅事件；超出嘅新事件會 drop 並計 quota_exceeded。0 代表唔限。
    |
    | 以上數值（batch_size、request_timeout_ms、retry、quota、
    | status_interval_seconds）同樣係提案起點，**未實測**。
    |
    */

    'agent' => [
        'central_url' => env('WATCH_CENTRAL_URL'),
        'project_key' => env('WATCH_PROJECT_KEY'),
        'batch_size' => 100,
        'request_timeout_ms' => 5000,
        'status_interval_seconds' => 30,
        'retry' => [
            'base_ms' => 500,
            'max_ms' => 30000,
        ],
        'quota' => [
            'events_per_minute' => 6000,
        ],
    ],

];
