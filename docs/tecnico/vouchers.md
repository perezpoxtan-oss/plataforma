# Vouchers de reposición

Réplica de `modules/vouchers` de SEGCAT (`voucher_lista.php`, `voucher_imprimir.php`). Pantalla `/vouchers` (Seguridad → Padrones → Inventarios de Seguridad). **Solo consulta e impresión**: los vouchers nacen de las bajas de Llaves, Gafetes y Equipos con el servicio común `App\Services\Inventarios\Vouchers` (ver [lector.md](lector.md#vouchers-de-reposición-base)) y no se editan ni se borran.

- Controlador: `app/Http/Controllers/Seguridad/VoucherController.php`
- Visibilidad: `app/Services/Vouchers/ConsultaVouchers.php`
- Modelo: `app/Models/VoucherReposicion.php` (tabla `vouchers_reposicion`, migración `2026_10_09_000100_crear_base_inventarios`; sin cambios de esquema)
- Vistas: `resources/views/seguridad/vouchers/{index,imprimir}.blade.php`
- Pruebas: `tests/Feature/Seguridad/VouchersTest.php`

## Quién ve qué

`ConsultaVouchers::consulta($usuario, $permiso)`:

1. Exige el permiso del módulo (`vouchers.ver` para la lista, `vouchers.imprimir` para imprimir).
2. Solo los vouchers cuyo **módulo de origen** puede ver (`VoucherReposicion::moduloOrigen()`: `llaves.ver`, `gafetes.ver`, `equipos.ver`), como SEGCAT.
3. Solo en las sedes donde valen **ambos** permisos (el de vouchers y el del módulo de origen). Un voucher sin sede solo lo ve quien tiene alcance de empresa.
4. Con alcance **propios** en vouchers, solo los que el usuario generó.
5. Siempre dentro de la empresa de trabajo (tenant). Un voucher fuera de todo esto responde **404** al imprimir.

Si el usuario tiene `vouchers.ver` pero ningún permiso de origen, la pantalla lo explica en lugar de mostrar una lista vacía.

## Lista — `GET /vouchers`

Tarjetas con: folio, **CON COBRO / SIN COBRO**, artículo (`origen_descripcion`, con ícono por origen), Origen, Motivo, Sede, Responsable (o "No especificado"), Monto (si hay cobro), ¿Cómo pasó?, "Generado por … · fecha" (`@fecha`) y **Ver / Reimprimir** (con `vouchers.imprimir`).

Filtros en la dirección (se pueden compartir) y en el servidor, con 30 por página:

| Parámetro | Valores |
|---|---|
| `q` | folio, artículo, ¿cómo pasó?, nombre o número de empleado del responsable |
| `sede` | id de sede (solo las del usuario en la lista) |
| `origen` | `llave`, `gafete`, `equipo` (solo los que puede ver) |
| `cobro` | `1` con cobro, `0` sin cobro |
| `desde`, `hasta` | `AAAA-MM-DD`; el día se cuenta en la hora local de la empresa (`HoraLocal`) |

Los valores inválidos se ignoran. Los selectores y las fechas envían el formulario al cambiar (`data-enviar-al-cambiar`).

## Impresión — `GET /vouchers/{id}/imprimir`

"Voucher de Reposición — 3 copias en una sola hoja": una hoja carta con **Copia Seguridad**, **Copia Colaborador** y **Copia Recepción**, separadas por "✂ recortar aquí". Cada copia: folio, empresa, sede, fecha, artículo, motivo, responsable con número de empleado, cómo pasó, **CXC** ($ MXN o "NO APLICA"), "Referencia de pago (a mano)" si hay cobro, quién lo generó y líneas de firma (Seguridad, Colaborador con su nombre, Recepción). Requiere `vouchers.ver` y `vouchers.imprimir`.

## Permisos

| Acción | Permiso |
|---|---|
| Lista | `vouchers.ver` + `<origen>.ver` |
| Ver / Reimprimir | `vouchers.imprimir` (+ lo anterior) |

Plantillas: el **Agente** consulta (Padrones = solo `ver`) pero no imprime; Asistente, Supervisor, Jefe, Director y Administrador imprimen.

## Rutas

| Método | Ruta | Nombre |
|---|---|---|
| GET | `/vouchers` | `vouchers.index` |
| GET | `/vouchers/{id}/imprimir` | `vouchers.imprimir` |

## Unión de colaboradores duplicados

`vouchers_reposicion.colaborador_id` está registrado en `AdministradorColaboradores::REFERENCIAS`: al unir un colaborador provisional con su registro correcto, sus vouchers pasan al correcto.

## Qué se corrigió respecto a SEGCAT

| SEGCAT | Plataforma |
|---|---|
| La lista consultaba el artículo con una consulta por voucher (N+1) y la sede/empresa con `INNER JOIN` (un voucher de una sede borrada desaparecía). | `origen_descripcion` guardado al generarse (no cambia si después se edita la llave o el gafete); sede y responsable con carga anticipada, incluidas sedes dadas de baja. |
| Toda la lista se cargaba completa y se filtraba en el navegador. | Filtros en el servidor con paginación; se agregó rango de fechas y búsqueda por responsable. |
| La visibilidad por sede del usuario se aplicaba con `id_hotel` de la sesión. | Alcance real por permiso: sedes de `vouchers.ver` ∩ sedes del módulo de origen. |
| Imprimir solo verificaba el permiso del módulo de origen. | Exige también `vouchers.imprimir`, además de la visibilidad. |
| "Dañado" guardado con acento como valor. | Clave `danado`; la etiqueta se muestra "Dañado". |
| `onclick` y CSS en línea. | Sin JS en línea; estilos en `plataforma.css` con modos Sol y Noche. |
