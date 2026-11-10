<?php

declare(strict_types=1);

namespace Watch\Laravel\Capture;

use Throwable;
use Watch\Laravel\Context\RequestContext;

class Tags
{
    public static function valid($tag): bool
    {
        return is_string($tag) && preg_match('~^[A-Za-z0-9:._/-]{1,64}$~D', $tag) === 1;
    }

    public static function add($tag): void
    {
        try {
            RequestContext::current(app())->addTag($tag);
        } catch (Throwable $e) {
            // Optional application annotations cannot break a business request.
        }
    }

    public function capture(RequestContext $context, int $status, array $user): array
    {
        try {
            $state = isset($user['state']) ? $user['state'] : 'unresolved';
            $tags = ['status:'.(int) floor($status / 100).'xx', 'auth:'.($state === 'authenticated' ? 'user' : ($state === 'guest' ? 'guest' : 'unresolved'))];
            $fixed = config('watch.capture.tags', []);
            foreach (array_merge(is_array($fixed) ? $fixed : [], $context->tags()) as $tag) {
                if (count($tags) >= 20) {
                    break;
                }
                if (self::valid($tag) && ! in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }

            return ['tags' => $tags];
        } catch (Throwable $e) {
            // Protocol v1 has no source capture.tags state; omit on failure.
            return [];
        }
    }
}
