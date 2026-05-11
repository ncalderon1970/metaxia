<?php
declare(strict_types=1);

function metis_valor_historico(?string $snapshot, ?string $anual = null, ?string $legacy = null): string
{
    $snapshot = trim((string)$snapshot);
    if ($snapshot !== '') return $snapshot;
    $anual = trim((string)$anual);
    if ($anual !== '') return $anual;
    return trim((string)$legacy);
}
