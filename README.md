# pqmultivoiceAutoComportamiento

Interfaz web en PHP para realizar llamadas mediante la API de Siptize.

## Requisitos

- Servidor web Apache con PHP y las extensiones `PDO`, `PDO_MySQL` y `cURL` habilitadas.
- Base de datos MySQL disponible.

## Uso

1. Abra `config.php` para configurar la API key, realizar llamadas inmediatas o configurar múltiples fechas para cada combinación de extensión y código.
2. Seleccione las fechas desde el calendario (puede marcar varias) y guárdelas; los días seleccionados se almacenan en la base de datos y aparecerán resaltados al volver a abrir la página.
3. El historial de llamadas y de fechas programadas se visualiza en `index.php`.
4. Configure las variables de entorno `DB_HOST`, `DB_NAME`, `DB_USER` y `DB_PASS` o edite los archivos PHP con sus credenciales de MySQL.
5. Ejecute periódicamente `run_scheduled.php` (por ejemplo, con `cron`) para que las llamadas pendientes se realicen en la fecha indicada.

La interfaz utiliza Bootstrap para un diseño más amigable y Flatpickr para el calendario de selección múltiple.
