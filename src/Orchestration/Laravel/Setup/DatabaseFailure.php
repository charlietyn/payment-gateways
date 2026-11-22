<?php

declare(strict_types=1);

namespace Ronu\PaymentGateways\Orchestration\Laravel\Setup;

/**
 * Clasifica un fallo de base de datos en un motivo accionable sin exponer su mensaje.
 *
 * El SQLSTATE no basta: PostgreSQL responde `08006` tanto si el servidor no escucha como si
 * rechaza la contraseña («fe_sendauth: no password supplied», «password authentication failed»).
 * El texto del driver se lee aquí para clasificar y nunca sale de esta clase.
 */
final class DatabaseFailure
{
    public const CREDENTIALS = 'database_credentials_rejected_check_password';

    public const UNREACHABLE = 'database_unreachable_check_host';

    public const UNAVAILABLE = 'database_unavailable_check_connection';

    /** MySQL 1045/1698 y SQL Server 18456: acceso denegado. */
    private const CREDENTIAL_CODES = [1045, 1698, 18456];

    /** MySQL/MariaDB: sin servidor, host desconocido o conexión perdida. */
    private const UNREACHABLE_CODES = [2002, 2003, 2005, 2006];

    private const CREDENTIAL_TEXT = ['fe_sendauth', 'password authentication failed', 'no password supplied', 'login failed for user'];

    public static function reason(\Throwable $error): string
    {
        [$state, $code, $text] = self::details($error);
        $text = strtolower($text);
        if (str_starts_with($state, '28') || in_array($code, self::CREDENTIAL_CODES, true)) {
            return self::CREDENTIALS;
        }
        foreach (self::CREDENTIAL_TEXT as $needle) {
            if (str_contains($text, $needle)) {
                return self::CREDENTIALS;
            }
        }
        if (str_starts_with($state, '08') || in_array($code, self::UNREACHABLE_CODES, true)) {
            return self::UNREACHABLE;
        }
        return self::UNAVAILABLE;
    }

    /**
     * Lo que se puede registrar: el SQLSTATE y el código del driver no llevan datos.
     *
     * @return array{sqlstate: string, driver_code: int|null}
     */
    public static function trace(\Throwable $error): array
    {
        [$state, $code] = self::details($error);
        return ['sqlstate' => $state, 'driver_code' => $code];
    }

    /** @return array{0: string, 1: int|null, 2: string} */
    private static function details(\Throwable $error): array
    {
        $state = '';
        $code = null;
        $text = '';
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $info = $current instanceof \PDOException || property_exists($current, 'errorInfo') ? ($current->errorInfo ?? null) : null;
            if (is_array($info)) {
                $state = $state !== '' ? $state : (is_string($info[0] ?? null) ? $info[0] : '');
                $code ??= is_int($info[1] ?? null) ? $info[1] : (is_numeric($info[1] ?? null) ? (int) $info[1] : null);
                $text .= ' '.(is_string($info[2] ?? null) ? $info[2] : '');
            }
            $message = $current->getMessage();
            if ($state === '' && preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $message, $match) === 1) {
                $state = $match[1];
            }
            if ($code === null && preg_match('/SQLSTATE\[[0-9A-Z]{5}\]\s*\[(\d+)\]/', $message, $match) === 1) {
                $code = (int) $match[1];
            }
            $text .= ' '.$message;
        }
        return [$state, $code, $text];
    }
}
