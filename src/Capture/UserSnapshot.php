<?php

declare(strict_types=1);

namespace Watch\Laravel\Capture;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use ReflectionProperty;
use Throwable;

class UserSnapshot
{
    public function capture(Container $container, Request $request): array
    {
        try {
            if (! Limits::enabled(config('watch.capture.user.enabled', true), true)) {
                return $this->result(['state' => 'disabled'], 'disabled');
            }
            if (! $container->resolved('auth')) {
                return $this->result(['state' => 'unavailable'], 'not_captured');
            }
            $auth = $container->make('auth');
            $configured = config('watch.capture.user.guards', []);
            $guards = [config('auth.defaults.guard', 'web')];
            foreach (is_array($configured) ? $configured : [] as $guard) {
                if (count($guards) >= 20) {
                    break;
                }
                if (is_string($guard) && ! in_array($guard, $guards, true)) {
                    $guards[] = $guard;
                }
            }
            $unresolved = false;
            foreach ($guards as $name) {
                if (! is_string($name) || $name === '' || Limits::bytes($name, 64) !== $name) {
                    $unresolved = true;
                    continue;
                }
                $guard = $auth->guard($name);
                if (! method_exists($guard, 'hasUser')) {
                    $unresolved = true;
                    continue;
                }
                if ($guard->hasUser() !== true) {
                    continue;
                }
                // GuardHelpers::$user is already in memory. Calling user(),
                // check(), id() or a guard-specific getter can resolve providers.
                $user = $this->property($guard, 'user');
                if (! $user instanceof Model) {
                    $unresolved = true;
                    continue;
                }
                // getAuthIdentifier() uses an Eloquent accessor, and even
                // getAttributes() can merge cached custom casts. Read the raw
                // loaded ID/name/email only, with no user-defined callbacks.
                $attributes = $this->property($user, 'attributes');
                $identifier = $this->property($user, 'primaryKey');
                $fields = config('watch.capture.user.fields', ['id']);
                $fields = is_array($fields) ? $fields : ['id'];
                $safe = ['state' => 'authenticated', 'guard' => $name];
                if (in_array('id', $fields, true) && is_string($identifier) && isset($attributes[$identifier])) {
                    $id = $attributes[$identifier];
                    if ((is_string($id) || is_int($id)) && Limits::bytes((string) $id, 191) === (string) $id) {
                        $safe['id'] = $id;
                    }
                }
                foreach (['name', 'email'] as $field) {
                    if (in_array($field, $fields, true) && isset($attributes[$field]) && is_string($attributes[$field]) && Limits::bytes($attributes[$field], 191) === $attributes[$field]) {
                        $safe[$field] = $attributes[$field];
                    }
                }

                return $this->result($safe, 'captured');
            }
            // Inspect only loaded session/cookie keys, without starting a
            // session, decrypting cookies or loading a remembered user.
            if ($request->hasSession()) {
                foreach ($request->session()->all() as $key => $value) {
                    if (is_string($key) && strpos($key, 'login_') === 0 && $value !== null) {
                        $unresolved = true;
                    }
                }
            }
            foreach ($request->cookies->keys() as $key) {
                if (strpos($key, 'remember_') === 0) {
                    $unresolved = true;
                }
            }

            return $this->result(['state' => $unresolved ? 'unresolved' : 'guest'], 'captured');
        } catch (Throwable $e) {
            return $this->result(['state' => 'unavailable'], 'error');
        }
    }

    private function property($object, string $name)
    {
        $property = new ReflectionProperty($object, $name);
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        return $property->getValue($object);
    }

    private function result(array $user, string $state): array
    {
        return ['user' => $user, 'capture' => ['user' => $state]];
    }
}
