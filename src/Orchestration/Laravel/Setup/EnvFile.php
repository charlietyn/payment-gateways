<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel\Setup;

use Dotenv\Dotenv;

/** Conserva comentarios, claves ajenas y EOL. Nunca registra contenido. */
final class EnvFile implements ValueStore
{
    public function __construct(private readonly string $path)
    {
    }

    public function read(): array
    {
        return array_filter(Dotenv::parse($this->contents()), is_string(...));
    }

    public function write(array $values): string
    {
        $before = $this->contents();
        $current = Dotenv::parse($before);
        $changes = array_filter($values, static fn (string $value, string $key): bool => ($current[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);
        if ($changes === []) {
            return 'unchanged';
        }
        $eol = str_contains($before, "\r\n") ? "\r\n" : "\n";
        $after = $before;
        foreach ($changes as $key => $value) {
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/D', $key) || strpbrk($value, "\r\n\0") !== false) {
                throw new SetupFailure('invalid_env_value');
            }
            // Double quotes with escaped interpolation, slash and quote are valid phpdotenv syntax.
            $line = $key.'="'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
            $pattern = '/^[ \t]*(?:export[ \t]+)?'.preg_quote($key, '/').'[ \t]*=.*(?:\r?\n|$)/m';
            if (preg_match($pattern, $after)) {
                $seen = false;
                $after = preg_replace_callback($pattern, static function () use (&$seen, $line, $eol): string {
                    if ($seen) {
                        return '';
                    }
                    $seen = true;
                    return $line.$eol;
                }, $after) ?? throw new SetupFailure('env_replace_failed');
            } else {
                $after .= ($after !== '' && !str_ends_with($after, "\n") ? $eol : '').$line.$eol;
            }
        }
        $this->replace($before, $after);
        return $before === '' ? 'created' : 'updated';
    }

    private function contents(): string
    {
        if (is_link($this->path)) {
            throw new SetupFailure('env_symlink_refused');
        }
        if (!is_file($this->path)) {
            return '';
        }
        return file_get_contents($this->path) ?: '';
    }

    private function replace(string $before, string $after): void
    {
        if (!is_dir(dirname($this->path))) {
            throw new SetupFailure('env_directory_missing');
        }
        $lock = fopen($this->path.'.payments-lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new SetupFailure('env_locked');
        }
        $temporary = $this->path.'.payments-'.bin2hex(random_bytes(8));
        try {
            if ($before !== $this->contents()) {
                throw new SetupFailure('env_changed_concurrently');
            }
            if ($before !== '') {
                self::privateWrite($this->path.'.backup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6)), $before);
            }
            self::privateWrite($temporary, $after);
            if (!rename($temporary, $this->path)) {
                throw new SetupFailure('env_write_failed');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function privateWrite(string $path, string $content): void
    {
        $file = fopen($path, 'x');
        if ($file === false) {
            throw new SetupFailure('file_already_exists');
        }
        try {
            chmod($path, 0600);
            if (fwrite($file, $content) !== strlen($content) || !fflush($file)) {
                throw new SetupFailure('file_write_failed');
            }
        } finally {
            fclose($file);
        }
    }
}
