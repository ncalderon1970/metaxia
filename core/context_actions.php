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
