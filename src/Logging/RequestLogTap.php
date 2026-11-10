<?php

declare(strict_types=1);

namespace Watch\Laravel\Logging;

use Illuminate\Log\Logger;

final class RequestLogTap
{
    public function __construct(private readonly RequestLogProcessor $processor) {}

    public function __invoke(Logger $logger, mixed ...$arguments): void
    {
        $underlying = $logger->getLogger();
        if (! method_exists($underlying, 'getProcessors')) {
            return;
        }

        foreach ($underlying->getProcessors() as $existing) {
            if ($existing instanceof RequestLogProcessor) {
                return;
            }
        }

        $logger->pushProcessor($this->processor);
    }
}
