# MultivoiceAutoComportamiento

Interfaz web sencilla en PHP para realizar llamadas mediante la API de Siptize.

## Requisitos

- Servidor web Apache con PHP y las extensiones `PDO` y `cURL` habilitadas.
- Permisos de escritura en el directorio donde se aloja el archivo para crear `calls.db`.

## Uso

1. Abra `index.php` en su servidor web.
2. Ingrese la extensión y el código a marcar.
3. El sistema realizará la llamada a través de `https://vpbx.me/api/originatecall/{extension}/{number}?timeout=20&autoAnswer=true` y almacenará la información en `calls.db`.

El historial de llamadas se muestra en la misma página.
