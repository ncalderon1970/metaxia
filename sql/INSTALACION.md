# Metis — Guía de instalación SQL (módulos nuevos)

## Orden de ejecución

Ejecutar en phpMyAdmin o HeidiSQL sobre la base de datos `metis`:

```
1. 07_comunidad_educativa.sql   ← Tablas alumnos, docentes, asistentes, apoderados
```

El resto de los módulos (alertas, reportes, admin) usan tablas existentes.

## Verificar que existen estas tablas previas:
- colegios, roles, permisos, usuarios, rol_permiso
- casos, caso_historial, caso_participantes, caso_alertas, caso_seguimiento
- estado_caso, modulos_catalogo, colegio_modulos, logs_sistema

## Nuevos módulos creados

| Módulo | URL |
|--------|-----|
| Alumnos | /modules/alumnos/index.php |
| Docentes | /modules/docentes/index.php |
| Asistentes | /modules/asistentes/index.php |
| Apoderados | /modules/apoderados/index.php |
| Alertas | /modules/alertas/index.php |
| Reportes | /modules/reportes/index.php |
| Admin | /modules/admin/index.php |
