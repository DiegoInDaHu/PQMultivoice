# MultivoiceAutoComportamiento

Interfaz web sencilla en PHP para realizar llamadas mediante la API de Siptize.

## Requisitos

- Servidor web Apache con PHP y las extensiones `PDO`, `PDO_MySQL` y `cURL` habilitadas.
- Base de datos MySQL disponible.

## Uso

1. Abra `index.php` en su servidor web.
2. Ingrese la extensión y el código a marcar.
3. Configure las variables de entorno `DB_HOST`, `DB_NAME`, `DB_USER` y `DB_PASS` o edite el archivo `index.php` con sus credenciales de MySQL.
4. El sistema realizará la llamada a través de `https://vpbx.me/api/originatecall/{extension}/{number}?timeout=20&autoAnswer=true` y almacenará la información en la tabla `calls` de MySQL.

El historial de llamadas se muestra en la misma página.
