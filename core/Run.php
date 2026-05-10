<?php
declare(strict_types=1);

final class Run
{
    public static function formatOrFail(string $run): string
    {
        $formatted = self::format($run);

        if ($formatted === null) {
            throw new RuntimeException('El RUN ingresado no es válido. Verifica el número y el dígito verificador.');
        }

        return $formatted;
    }

    public static function format(string $run): ?string
    {
        $run = strtoupper(trim($run));
        $run = str_replace(['.', ' ', "\t", "\n", "\r"], '', $run);
        $run = str_replace(['‐', '-', '‒', '–', '—'], '-', $run);

        if ($run === '') {
            return null;
        }

        if (str_contains($run, '-')) {
            $parts = explode('-', $run);
            $dv = array_pop($parts);
            $body = implode('', $parts);
        } else {
            $body = substr($run, 0, -1);
            $dv = substr($run, -1);
        }

        $body = preg_replace('/[^0-9]/', '', (string)$body);
        $dv = preg_replace('/[^0-9K]/', '', (string)$dv);

        if ($body === '' || $dv === '') {
            return null;
        }

        if (!preg_match('/^[0-9]{1,8}$/', $body)) {
            return null;
        }

        if (!preg_match('/^[0-9K]$/', $dv)) {
            return null;
        }

        $expected = self::calculateDv($body);

        if ($dv !== $expected) {
            return null;
        }

        return $body . '-' . $dv;
    }

    public static function isValid(string $run): bool
    {
        return self::format($run) !== null;
    }

    private static function calculateDv(string $body): string
    {
        $sum = 0;
        $factor = 2;

        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $sum += ((int)$body[$i]) * $factor;
            $factor++;

            if ($factor > 7) {
                $factor = 2;
            }
        }

        $result = 11 - ($sum % 11);

        if ($result === 11) {
            return '0';
        }

        if ($result === 10) {
            return 'K';
        }

        return (string)$result;
    }
}