# Bitácora de auditoría

Pantalla `GET /auditoria` (módulo `auditoria`, área Dirección). Es de solo consulta: nadie edita ni borra la bitácora desde la plataforma. Los movimientos los escribe `AdministradorRoles::auditar()` desde cada módulo.

## Quién ve qué

| Permiso / alcance | Ve |
|---|---|
| `auditoria.ver` con alcance de empresa | Todos los movimientos de la empresa de trabajo |
| `auditoria.ver` con alcance de sede o propios | Solo sus propios movimientos (la bitácora no tiene sede) |
| Super Administrador sin empresa elegida | Movimientos de la plataforma (`empresa_id` nulo: plantillas, identidad, alta de empresas) |
| `auditoria.exportar` | Botón "Exportar a Excel": CSV con BOM UTF-8, mismos filtros, máximo 10,000 filas |

## Lectura (`App\Services\Auditoria\LectorAuditoria`)

- **Evento:** `modulo.accion` se muestra como "Módulo · Acción". El módulo sale del catálogo y la acción de un diccionario; por ejemplo, `colaboradores.fusionado` se ve como "Colaboradores · Unión de duplicado".
- **Registro:** se ve como "Tipo · nombre", por ejemplo "Colaborador · Jorge Méndez Tun". Los nombres se cargan con una consulta por tipo de modelo; si el registro ya no existe dice "#id (ya no existe)".
- **Detalle:** tabla de antes y después con los campos que cambiaron resaltados. Se dibuja con `textContent`, nunca con `innerHTML`.
- **Datos personales:** CURP, RFC, NSS y teléfono ya se guardan enmascarados al auditar (ver Colaboradores).

## Filtros

- **Desde / hasta:** días en la hora local de quien consulta, convertidos a UTC.
- **Usuario:** solo los que aparecen en la bitácora.
- **Módulo:** prefijo del evento.
- **Contiene:** busca en la IP y en el JSON de antes y después.
- Paginación de 50 en 50, con los filtros en la URL.

## Pruebas

`tests/Feature/Direccion/PendientesDireccionTest.php`
