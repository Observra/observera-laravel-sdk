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

            // "No user" is never memoised: it is often only true so far — a log
            // line written before the auth middleware runs sees no user yet, and
            // caching that blanked the email on the whole request row. The guard
            // caches its own lookup, so asking again is cheap.
            if (! $request->hasSession() && ! $request->bearerToken() && ! $request->user()) {
                return $empty;
            }

            $user = $request->user();

            if (! $user) {
                return $empty;
            }

            $pii = (bool) config('observera.send_pii', true); // false: id only

            return $this->resolved = [
                'id' => (string) ($user->getAuthIdentifier() ?? ''),
                'email' => $pii ? (string) ($this->attr($user, config('observera.user_email_attribute', 'email')) ?? '') : '',
                'name' => $pii ? (string) ($this->attr($user, config('observera.user_name_attribute', 'name')) ?? '') : '',
            ];
        } catch (\Throwable) {
            // A broken guard must never break the app it is instrumenting.
            return $empty;
        }
    }

    /**
     * Forget the memo. Called at the start of every request and job: Octane and
     * queue workers reuse the process, and a stale memo would attribute one
     * user's activity to the next person served by the same worker.
     */
    public function reset(): void
    {
        $this->resolved = null;
    }

    /**
     * Keyed, truncated hash (HMAC-SHA256 with APP_KEY) for identifiers that must
     * correlate but never leave the app raw: session ids, ip|ua, cache keys.
     * Without the app key a plain sha256 hash would be brute-forceable for IPs.
     */
    public static function hash(string $value, int $length = 32): string
    {
        try {
            $key = (string) config('app.key', '');
        } catch (\Throwable) {
            $key = '';
        }

        return substr(hash_hmac('sha256', $value, $key !== '' ? $key : 'observera'), 0, $length);
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
