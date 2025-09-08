# MultivoiceAutoComportamiento

Interfaz web sencilla en PHP para realizar llamadas mediante la API de Siptize.

## Requisitos

- Servidor web Apache con PHP y las extensiones `PDO`, `PDO_MySQL` y `cURL` habilitadas.
- Base de datos MySQL disponible.

## Uso

1. Abra `index.php` en su servidor web.
2. Ingrese la extensión y el código a marcar para realizar una llamada inmediata.
3. Para programar una llamada futura, complete el formulario **Programar llamada** indicando la fecha y hora.
4. Configure las variables de entorno `DB_HOST`, `DB_NAME`, `DB_USER` y `DB_PASS` o edite el archivo `index.php` con sus credenciales de MySQL.
5. El sistema realizará la llamada a través de `https://vpbx.me/api/originatecall/{extension}/{number}?timeout=20&autoAnswer=true` y almacenará la información en la tabla `calls` de MySQL.

Las llamadas programadas se guardan en la tabla `scheduled_calls`. Ejecute periódicamente `run_scheduled.php` (por ejemplo, con `cron`) para que las llamadas pendientes se realicen en la fecha y hora indicadas.

El historial y las llamadas programadas se muestran en la misma página.
