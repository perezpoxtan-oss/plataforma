# «Entregar / Recibir» unificado (Etapa 8 · C-45, DD-19)

Decisión: [ADR-0023](../decisiones/ADR-0023-credenciales-y-entregar-recibir.md). Manual: `docs/usuario/entregar-y-recibir.md`.

Regla del dueño: **no se pierde ninguna funcionalidad** de Préstamo de llaves, Gafetes ni Responsivas (pantallas, impresiones, reportes, vouchers, firmas y reglas de la llave de C-17 siguen igual) y el agente de caseta no gana pasos. Se entregó la **versión mínima segura**; lo que queda para después está al final.

## 1. Historial único `movimientos_activo`

Migración `2026_11_18_000200_crear_movimientos_activo` (`down()` borra la tabla; nadie depende de ella).

| Columna | Contenido |
|---|---|
| `activo_tipo`, `activo_id` | `llave`, `gafete`, `equipo`, `credencial` |
| `accion` | `salida`, `recepcion`, `no_regreso`, `baja`, `reactivacion`, `anulado`, `mantenimiento` |
| `resultado` | En la recepción: `ok`, `danado`, `no_regreso` (`MovimientoActivo::RESULTADOS`) |
| `origen_tipo`, `origen_id` | `prestamo_llave`, `acceso`, `acompanante`, `responsiva`, `pase_salida`, `credencial`, `catalogo` |
| `receptor_tipo`, `receptor_id` | Receptor general (C-16). **Sin copia del nombre**: se lee del Padrón o del acceso de origen al mostrarlo, así ARCO y la conservación de cada módulo lo siguen alcanzando |
| `detalle` | Garantía de la llave, folio y modalidad de la responsiva… (sin datos personales) |

`App\Services\Activos\HistorialActivos`:

- **Eventos de Eloquent** (`registrarEventos()`, desde `AppServiceProvider::boot`): `PrestamoLlave` creado/actualizado (prestar, recibir, «No regresó», anular), `EquipoResponsiva` creado/actualizado (entrega y recepción OK / dañado / faltante → `no_regreso`), `Acceso` y `AcompananteAcceso` creados con gafete (salida), y el `estado` de `Llave`, `Gafete` y `Equipo` (baja, reactivación, «No regresó» del gafete y del equipo, recibir lo que no regresó, pase de salida FUERA ↔ DISPONIBLE, EN MANTENIMIENTO). La llave «No regresó» la registra su préstamo (no se duplica).
- **Llamada explícita** donde la Bitácora actualiza en bloque: `MovimientosAccesos::salida()` y `salidaAcompanante()` → `gafetesRecibidos()` (los gafetes devueltos en OK).
- `conResultado('danado', fn)`: marca el resultado de la recepción que ocurre dentro (lo usa la recepción común, § 2).
- Un error al escribir el historial se reporta y **no** detiene la operación.

Pantalla: `GET /activos/{tipo}/{id}/entregas` (`activos.historial`, `HistorialActivosController`), botón `componentes/entregas-recepciones` en las fichas de Llaves, Gafetes y Equipos. Permisos y alcance: los de «Código e identificación» (`<modulo>.ver`, sede y propios; fuera → 404, sin permiso → 403).

## 2. Recepción común OK / Dañado / No regresó

Componente `componentes/recepcion-activo.blade.php` (`resultado` = `ok` | `danado` | `no_regreso`; **OK marcado**: recibir en buen estado no agrega pasos). Los endpoints de siempre lo aceptan y, **sin el campo, se comportan como antes**:

| Módulo | Endpoint | `resultado` |
|---|---|---|
| Préstamo de llaves | `PATCH prestamo_llaves.recibir` | `danado`: se recibe igual (`HistorialActivos::conResultado`) y el aviso ofrece «Dar de baja la llave» a quien tiene `llaves.eliminar`; `no_regreso`: lo mismo que su botón «No regresó». En la ficha va plegado («¿Viene dañada?»). |
| Gafetes | `PATCH gafetes.recibir` (NO REGRESÓ → DISPONIBLE) | `danado`: se recibe y se ofrece «Dar de baja el gafete». Plegado («¿Viene dañado?»). |
| Responsivas | `PATCH responsivas.recibir-equipo` | Alias de `estado_recepcion`: `ok` → ok, `danado` → danado, `no_regreso` → faltante (con su nota, voucher y aviso al supervisor de siempre). |
| Credencial | `PATCH credenciales.recibir` | Ver [credenciales.md](credenciales.md). |
| Lector (`/e/{código}`) | llave prestada (OK / Dañado / No regresó), llave «No regresó» y gafete «No regresó» (OK / Dañado) | `AccionesLector` agrega `recepcion` a la acción; `lector/acciones.blade.php` pinta el componente. El equipo en responsiva conserva «Recibir (OK)» y «Viene dañado o falta: abrir la responsiva». |

## 3. Garantía y firma como ajuste por tipo de objeto

`AjustesCaseta` (Configuración → Activos, apartados «Avanzado» de las tarjetas de llaves y responsivas; grupos `llaves` y `responsivas`):

| Clave | Valores | Omisión | Efecto |
|---|---|---|---|
| `llaves_garantia` | `elegir`, `obligatoria` | `elegir` | Con `obligatoria`, `prestar()` rechaza `tipo_garantia = ninguna` y el diálogo no ofrece «Ninguna (Riesgo)». |
| `responsivas_firma` | `pantalla`, `papel` | `pantalla` | Con `papel`, la responsiva se guarda sin firma digital (`firma_ruta = ''`, la misma marca que deja ARCO); la hoja deja el espacio para firmar a mano y la ficha no muestra «Firma». |

Los gafetes conservan la identificación que se revisa al dar salida en la Bitácora (C-15).

## 4. Lo que queda para después

- **Una sola pantalla «Entregar / Recibir»** que reemplace a Préstamo de llaves, Gafetes y Responsivas (DD-19 la deja a mediano plazo). Se quedó en la versión mínima segura: las tres pantallas, sus impresiones, vouchers, firmas, reportes y las reglas de la llave (C-17) no cambian.
- **Garantía en responsivas y gafetes, firma en el préstamo de llaves**: no existen hoy; agregarlas es un cambio de flujo que debe aprobar el dueño.
- **Historial anterior**: `movimientos_activo` empieza vacío; no se reconstruyó lo previo desde préstamos, accesos y responsivas.
- **Equipo de Protección Civil y Lost & Found** no entran al historial único (no se «entregan y reciben» en caseta).
- El borrado definitivo de una llave, un gafete o un equipo deja sus filas de historial (no son datos personales).

## Pruebas

`tests/Feature/Seguridad/EtapaOchoHistorialTest.php`, `EtapaOchoRecepcionTest.php` y `MigracionEtapa8Test.php`.
