<?php

declare(strict_types=1);

namespace Watch\Laravel\Console;

use Illuminate\Console\Command;
use Watch\Laravel\WatchServiceProvider;

final class InstallCommand extends Command
{
    protected $signature = 'watch:install {--force : Overwrite an existing watch.php configuration file}';

    protected $description = 'Publish the Watch configuration and print agent setup guidance';

    public function handle(): int
    {
        $parameters = [
            '--provider' => WatchServiceProvider::class,
            '--tag' => 'watch-config',
        ];

        if ($this->option('force')) {
            $parameters['--force'] = true;
        }

        $exit = $this->call('vendor:publish', $parameters);

        $this->newLine();
        $this->line('Watch 設定檔已處理（冇 --force 就唔會覆寫現有 config/watch.php）。');
        $this->line('Agent 設定指引（呢個 command 唔會啟動 agent）：');
        $this->line('- 喺 .env 設定 WATCH_TOKEN（由 central 嘅 /projects 頁複製）就用得。');
        $this->line('- 已設定嘅 WATCH_CENTRAL_URL、WATCH_PROJECT_KEY、WATCH_AGENT_ENDPOINT 會蓋過 WATCH_TOKEN。');
        $this->line('- 用獨立長駐行程跑 php artisan watch:agent，由 systemd 或 Supervisor 監管。');
        $this->line('- 唔好放喺 PHP-FPM、Octane worker、或者業務 queue worker 入面。');
        $this->line('- 只聽 Unix socket，或者只綁 127.0.0.1。');
        $this->line('- 短超時起點係未量測提案，唔係已測結果：連線 50–200ms、傳送 200–500ms。');
        $this->line('- 業務路徑保持 fail-open。sample_rate=1.0 係意圖全量，唔係保證全量。');

        return is_int($exit) ? $exit : self::SUCCESS;
    }
}
