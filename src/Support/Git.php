<?php

namespace Observera\Laravel\Support;

/**
 * Reads the checkout's HEAD straight from .git — no shell, no git binary
 * (deploy images often have neither). Every field is null when unreadable.
 */
final class Git
{
    /** @return array{sha: ?string, branch: ?string} */
    public static function head(string $basePath): array
    {
        try {
            $head = $basePath.'/.git/HEAD';
            if (! is_readable($head)) {
                return ['sha' => null, 'branch' => null];
            }
            $ref = trim((string) file_get_contents($head));

            if (! str_starts_with($ref, 'ref:')) {
                return ['sha' => self::sha($ref), 'branch' => null]; // detached HEAD (typical in CI)
            }

            $name = trim(substr($ref, 4));
            $branch = str_starts_with($name, 'refs/heads/') ? substr($name, 11) : null;
            $refFile = $basePath.'/.git/'.$name;
            $sha = is_readable($refFile) ? trim((string) file_get_contents($refFile)) : self::packed($basePath, $name);

            return ['sha' => self::sha((string) $sha), 'branch' => $branch];
        } catch (\Throwable) {
            return ['sha' => null, 'branch' => null];
        }
    }

    /** Refs gc'd into .git/packed-refs ("<sha> refs/heads/main" lines). */
    private static function packed(string $basePath, string $name): ?string
    {
        $file = $basePath.'/.git/packed-refs';
        if (! is_readable($file)) {
            return null;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_ends_with($line, ' '.$name)) {
                return strtok($line, ' ') ?: null;
            }
        }

        return null;
    }

    private static function sha(string $value): ?string
    {
        return preg_match('/^[0-9a-f]{40}$/', $value) ? $value : null;
    }
}
