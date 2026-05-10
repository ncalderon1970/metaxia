<?php
declare(strict_types=1);

/**
 * Metis · Barra contextual de acciones
 *
 * Utilidad liviana para estandarizar las acciones superiores de cada módulo.
 * No ejecuta lógica de negocio ni valida permisos; solo normaliza la definición
 * visual que luego renderiza core/layout_header.php.
 */

if (!function_exists('metis_context_action')) {
    function metis_context_action(
        string $label,
        string $url,
        string $icon = 'bi-chevron-right',
        string $variant = 'secondary',
        bool $visible = true,
        string $target = '',
        string $rel = ''
    ): array {
        return [
            'label'   => $label,
            'url'     => $url,
            'icon'    => $icon,
            'variant' => $variant,
            'visible' => $visible,
            'target'  => $target,
            'rel'     => $rel,
        ];
    }
}

if (!function_exists('metis_context_actions')) {
    function metis_context_actions(array $actions): array
    {
        $out = [];

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            if (array_key_exists('visible', $action) && !$action['visible']) {
                continue;
            }

            $label = trim((string)($action['label'] ?? ''));
            $url   = trim((string)($action['url'] ?? ''));

            if ($label === '' || $url === '') {
                continue;
            }

            $variant = trim((string)($action['variant'] ?? 'secondary'));
            $allowed = ['primary', 'secondary', 'success', 'warning', 'danger', 'dark', 'soft'];
            if (!in_array($variant, $allowed, true)) {
                $variant = 'secondary';
            }

            $out[] = [
                'label'   => $label,
                'url'     => $url,
                'icon'    => trim((string)($action['icon'] ?? 'bi-chevron-right')) ?: 'bi-chevron-right',
                'variant' => $variant,
                'visible' => true,
                'target'  => trim((string)($action['target'] ?? '')),
                'rel'     => trim((string)($action['rel'] ?? '')),
            ];
        }

        return $out;
    }
}

if (!function_exists('metis_context_config_flag')) {
    function metis_context_config_flag(PDO $pdo, string $configKey, string $itemKey, bool $default = false): bool
    {
        try {
            $stmt = $pdo->prepare("SELECT valor FROM sistema_config WHERE clave = ? LIMIT 1");
            $stmt->execute([$configKey]);
            $raw = $stmt->fetchColumn();
            $cfg = $raw ? (json_decode((string)$raw, true) ?: []) : [];

            if (!array_key_exists($itemKey, $cfg)) {
                return $default;
            }

            return (int)($cfg[$itemKey]['visible'] ?? 0) === 1;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('metis_topbar_action_visible')) {
    function metis_topbar_action_visible(PDO $pdo, string $key, bool $default = false): bool
    {
        return metis_context_config_flag($pdo, 'acciones_expediente_topbar', $key, $default);
    }
}

