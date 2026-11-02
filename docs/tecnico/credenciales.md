# Credencialización (Etapa 8 · C-44)

Decisión: [ADR-0023](../decisiones/ADR-0023-credenciales-y-entregar-recibir.md). Manual: `docs/usuario/colaboradores.md` → «Cómo entregar, recibir o dar de baja su credencial».

Glosario (C-39, DD-20): **credencial** = el plástico del empleado; **gafete** = el plástico de visita.

## Modelo

Tabla `credenciales` (migración `2026_11_18_000300_credenciales_de_colaboradores`): `colaborador_id` (restrict), `sede_id` (la del colaborador al entregarla), `consecutivo` y `folio` únicos por empresa (`CRED-000001`), `estado`, `etiqueta_nfc` (constancia de la tarjeta que llevaba al entregarse), `entregada_en/_por`, `recibida_en/_por`, `baja_motivo` (`reposicion` | `baja_colaborador`), `baja_en`, `voucher_id`, autoría y timestamps.

Estados (de `EstadoActivo`, C-14):

```
Emitir → DISPONIBLE (RR. HH. la imprime) ─Dar salida→ ASIGNADO
Reponer → la vigente DE BAJA + otra DISPONIBLE con otro código
(Dar salida sin credencial: emite y entrega en un paso) → ASIGNADO ─Recibir OK→ DISPONIBLE (la guarda RR. HH.) ─Dar salida→ ASIGNADO (mismo folio)
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

## Fase 1 de la propuesta aprobada (12 oct)

Migración `2026_11_18_000400_credenciales_fase_uno`: `colaboradores.foto_credencial_ruta`, `credenciales.vence_en` y `credenciales.codigo_qr` (el código del colaborador que lleva impreso esa credencial).

| Regla de la propuesta | Cómo se cumple |
|---|---|
| Una sola vigente por colaborador | `emitir()` bloquea al colaborador y rechaza si hay una no `baja`. |
| Reponer invalida la anterior al instante | `reponer()` = baja (con voucher si extraviada/dañada/robada; sin voucher si vencida o cambio de datos o foto) + `emitir()`. El colaborador recibe otro `codigo_qr`: la anterior responde «No vigente» y el lector ya no lo encuentra con ella. |
| La baja del colaborador la revoca | `bajaPorColaborador()` en «Antes de dar de baja». |
| Verificación pública por QR «Vigente / No vigente» con foto y nombre, sin datos personales y con límite | `GET /credencial/{código}` (`credenciales.verificar`, `VerificarCredencialController`, sin sesión, limitador `credenciales.verificar` 30/min por IP, `no-store`, `noindex`). Vigente = DISPONIBLE o ASIGNADO, sin vencer, colaborador activo y empresa activa, y el código coincide con el impreso. Muestra foto (data URI), nombre, empresa y fecha de vencimiento; nada más. Con sesión redirige a `/e/{código}` (acción de la caseta). `Etiqueta::codigoDeUrl` reconoce `/credencial/…` para los lectores. |
| Aviso «por vencer» en Mis pendientes con reloj | `PendientesActivos`: «Credenciales por vencer» (ámbar, desde `aviso_dias`) y «Credenciales vencidas o por vencer» (rojo si alguna venció y sigue en uso). Solo con vigencia configurada. |

Pantallas y piezas:

- **Foto** (`credenciales.foto`, 20/min): archivo (`capture="user"` abre la cámara del celular) o cámara de la computadora (`getUserMedia`, bloque «Etapa 8 · C-44» de `plataforma.js`, dato `foto_camara`). `ImagenSegura::recodificar` + recorte 3:4 a 600 × 800 JPEG en el disco privado; la anterior se borra. Inventario de datos personales: columna laboral del colaborador.
- **Emitir / Imprimir / Dar salida / Recibir / Reponer / Dar de baja** en la ficha (`ficha/_codigo.blade.php`). «Emitir credencial» y «Reponer credencial» están en la lista de exclusión de `DiezVerbosTest` (acciones de la propuesta aprobada); «Revocar» es **Dar de baja**.
- **Hoja CR80** (`GET /credenciales/imprimir?credenciales[]=…`, `rh/credenciales/imprimir.blade.php`): `@page 85.6mm 54mm`, frente (logo, foto, nombre, puesto, departamento, número, vigencia, QR de verificación, folio) y reverso (propiedad de la empresa, teléfono, texto adicional, firma del titular). Sigue el enfoque del motor de Etiquetas QR (milímetros en `@page`, QR local con bacon, logo de la identidad); es HTML que se imprime o se guarda como PDF. Audita `colaboradores.credencial_impresa`.
- **Lista «Credenciales»** (`GET /credenciales`, botón en Colaboradores): filtros Vigentes / Por vencer / Vencidas / No regresó / De baja / Sin credencial, búsqueda por nombre normalizado o número; «Imprimir seleccionadas» (`POST /credenciales/imprimir`) emite la de quien no tiene y abre la hoja.
- **Ajustes** (`AjustesCredenciales`, Configuración → Estructura → Credenciales del personal, `PUT /configuracion/credenciales`, `configuracion.editar` con alcance de empresa): vigencia `sin` (de fábrica) | `meses` | `anual`, `aviso_dias` (30), `tecnologia` `qr` (de fábrica: no pide tarjeta) | `qr_tarjeta`, teléfono y texto del reverso.
- **Fase 2 (lo barato):** la entrada y salida con la credencial ya funcionan con el lector (QR o tarjeta → «Nuevo ingreso» / «Dar salida», y la salida a la vista acepta la credencial); si la credencial ya no es vigente, la pantalla de la caseta lo dice. «Quién está dentro» es **Gente en sitio** de la Bitácora de accesos (tipo Colaborador).

## Lo que quedó para después (y por qué)

- **PDF real** (archivo .pdf generado en el servidor): no hay biblioteca PDF en el proyecto; agregarla (dompdf) cambia dependencias y el paquete de despliegue en Neubox. La hoja CR80 se imprime o se «Guarda como PDF» desde el navegador al tamaño exacto.
- **Diseño editable del frente y el reverso dentro del editor de plantillas** (mover campos, colores): los campos y el texto legal son una decisión pendiente del dueño; hoy son los de la propuesta, con teléfono y texto configurables.
- **Recorte manual con guía de encuadre**: el recorte es automático al centro (3:4).
- **Vigencia «hasta fin de contrato»**: el colaborador no guarda esa fecha de forma confiable.
- **Gafetes de visitante en CR80**: ya se imprimen con la plantilla «Gafete» (86 × 54) de Etiquetas QR; la opción por empresa queda pendiente.
- **Campana del vencimiento** (además de Mis pendientes) y aviso por correo.
- **Fases 3 y 4** (ZKTeco, Hikvision) y las decisiones de §7 de la propuesta: tecnología de tarjeta (de fábrica, solo QR), marca de equipos, reconocimiento facial, antipassback y relación con asistencia o nómina. No se hicieron.

## Pruebas

`tests/Feature/Seguridad/EtapaOchoCredencialesTest.php`, `EtapaOchoCredencialFaseUnoTest.php` y `MigracionEtapa8Test.php`.
