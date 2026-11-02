# Credencialización (Etapa 8 · C-44)

Decisión: [ADR-0023](../decisiones/ADR-0023-credenciales-y-entregar-recibir.md). Manual: `docs/usuario/colaboradores.md` → «Cómo entregar, recibir o dar de baja su credencial».

Glosario (C-39, DD-20): **credencial** = el plástico del empleado; **gafete** = el plástico de visita.

## Modelo

Tabla `credenciales` (migración `2026_11_18_000300_credenciales_de_colaboradores`): `colaborador_id` (restrict), `sede_id` (la del colaborador al entregarla), `consecutivo` y `folio` únicos por empresa (`CRED-000001`), `estado`, `etiqueta_nfc` (constancia de la tarjeta que llevaba al entregarse), `entregada_en/_por`, `recibida_en/_por`, `baja_motivo` (`reposicion` | `baja_colaborador`), `baja_en`, `voucher_id`, autoría y timestamps.

Estados (de `EstadoActivo`, C-14):

```
(Dar salida) → ASIGNADO ─Recibir OK→ DISPONIBLE (la guarda RR. HH.) ─Dar salida→ ASIGNADO (mismo folio)
               ASIGNADO ─Recibir Dañado / Dar de baja con voucher→ DE BAJA
               ASIGNADO ─No regresó→ NO REGRESÓ ─Recibir→ DISPONIBLE | ─Dar de baja→ DE BAJA
               cualquiera vigente ─baja del colaborador→ DE BAJA (baja_colaborador)
```

A lo más una vigente (no `baja`) por colaborador: `darSalida()` bloquea al colaborador (`lockForUpdate`) y reutiliza la DISPONIBLE.

La credencial **no** es un tipo del lector: su QR y su tarjeta son los del colaborador (`colaboradores.codigo_qr` / `etiqueta_nfc`), así el lector lo sigue encontrando a él. Al darla de baja por extravío, robo o daño, `apagarIdentificacion()` quita la tarjeta y **cambia el QR** del colaborador: el plástico perdido deja de identificarlo (auditoría `colaboradores.etiqueta_quitada`).

## Servicio y rutas

`App\Services\Credenciales\AdministradorCredenciales` (dentro del Tenant):

| Método | Ruta | Nombre |
|---|---|---|
| `darSalida($actor, $colaborador, ?$etiqueta)` | `POST /colaboradores/{colaborador}/credencial` | `credenciales.salida` |
| `recibir($actor, $credencial, ok|danado|no_regreso)` | `PATCH /credenciales/{credencial}/recibir` | `credenciales.recibir` |
| `darDeBaja($actor, $credencial, $entrada)` | `POST /credenciales/{credencial}/baja` | `credenciales.baja` |
| `bajaPorColaborador($actor, $colaborador)` | (desde `AntesDeDarDeBaja::aplicar`) | — |

- Permiso nuevo **«Colaboradores → Credencial»** (`colaboradores.credencial`), con el alcance de sedes de Colaboradores (`AdministradorColaboradores::limitar`); fuera del alcance o de otra empresa → 404; sin permiso → 403. La migración lo da a los roles que ya tenían `colaboradores.editar`, con el mismo alcance; en instalaciones nuevas lo dan las plantillas (Administrador y Recursos Humanos). `down()` lo quita.
- No se entrega a un alta provisional ni a un colaborador de baja.
- Voucher: `VoucherReposicion::ORIGENES['credencial'] = ['Credencial', 'colaboradores', Credencial::class]`; el responsable siempre es el colaborador; firma física por omisión; costo recordado con la referencia `credencial`. «Recuperado» en Vouchers no reactiva la credencial (se entrega otra con **Dar salida**).
- «No regresó» avisa en la campana (tipo `activo_no_regreso`, referencia `activo_no_regreso_credencial`, botón «Dar de baja» que abre la ficha `#codigo`) a quien tiene el permiso en esa sede; se resuelve al recibirla o darla de baja. Mis pendientes: «Credenciales que no regresaron» (`PendientesActivos`, solo si hay).
- Auditoría: `colaboradores.credencial_entregada`, `…_recibida`, `…_no_regreso`, `…_baja`.
- Historial: cada paso escribe en `movimientos_activo` (`activo_tipo = credencial`); «Entregas y recepciones» de la credencial pide `colaboradores.ver` con su alcance.

## Pantalla

Pestaña **Código y etiqueta** de la ficha del colaborador (`ficha/_codigo.blade.php`): estado, folio, quién la entregó y recibió; **Dar salida** (con «Avanzado: tarjeta NFC / RFID del plástico»), **Recibir** con la recepción común (`componentes/recepcion-activo`) y «Dar de baja con voucher» plegado. Tras guardar, el aviso ofrece **Imprimir credencial** (Etiquetas QR filtrada) y, tras la baja, **Imprimir voucher** (criterio 5).

## «Antes de dar de baja» (C-20)

`deColaborador()` agrega el aviso «Tiene su credencial» (CRED-… · estado, enlace a la ficha) y `aplicar()` llama a `bajaPorColaborador()` antes de apagar la etiqueta NFC; el resumen dice «su credencial se dio de baja». Sin voucher: no hubo pérdida.

## Pruebas

`tests/Feature/Seguridad/EtapaOchoCredencialesTest.php` y `MigracionEtapa8Test.php`.
