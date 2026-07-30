<?php

namespace Observera\Laravel\Instrumentation;

use Illuminate\Http\Request;

/**
 * Resolves who the authenticated user is, once per request.
 *
 * An id alone is useless in a dashboard — nobody recognises "user 4812". The
 * email is what makes a log line or an error actionable, so it travels with
 * every event type alongside the id.
 *
 * Memoised because it is read on every log line, and a naive implementation
 * would hit the guard (and possibly the database) per line.
 */
class Identity
{
    /** @var array{id: string, email: string, name: string}|null */
    private ?array $resolved = null;

    /**
     * @return array{id: string, email: string, name: string}
     */
    public function current(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $empty = ['id' => '', 'email' => '', 'name' => ''];

        try {
            // In console/queue contexts there is no request and often no guard;
            // resolving through the container would boot the session for nothing.
            if (! app()->bound('request')) {
                return $empty; // not memoised: a queue worker may later have one
            }

            /** @var Request $request */
            $request = app('request');

            if (! $request->hasSession() && ! $request->bearerToken() && ! $request->user()) {
                return $this->resolved = $empty;
            }

            $user = $request->user();

            if (! $user) {
                return $this->resolved = $empty;
            }

            return $this->resolved = [
                'id' => (string) ($user->getAuthIdentifier() ?? ''),
                'email' => (string) ($this->attr($user, config('observera.user_email_attribute', 'email')) ?? ''),
                'name' => (string) ($this->attr($user, config('observera.user_name_attribute', 'name')) ?? ''),
            ];
        } catch (\Throwable) {
            // A broken guard must never break the app it is instrumenting.
            return $empty;
        }
    }

    /** Forget the memo — the queue worker reuses the process across jobs. */
    public function reset(): void
    {
        $this->resolved = null;
    }

    /** Read an attribute without assuming the user model has it. */
    protected function attr(object $user, string $key): ?string
    {
        try {
            $value = $user->{$key} ?? null;

            return is_scalar($value) ? (string) $value : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
