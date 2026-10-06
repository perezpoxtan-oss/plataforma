# Pases de salida

Réplica de `modules/pases_salida` de SEGCAT (§4.27 del inventario funcional): autoriza y rastrea la salida física de artículos de una sede, con un circuito de firmas. Menú **Operación → Caseta y Control → Pases de salida** (`/pases-salida`).

## Piezas

| Pieza | Archivo |
|---|---|
| Migración | `database/migrations/2026_10_10_000400_crear_pases_de_salida.php` |
| Modelos | `app/Models/PaseSalida.php` (máquina de estados, grupos y roles), `PaseSalidaArticulo.php`, `PaseSalidaFirma.php` |
| Reglas | `app/Services/PasesSalida/AdministradorPasesSalida.php` |
| Controlador | `app/Http/Controllers/Seguridad/PaseSalidaController.php` |
| Vistas | `resources/views/seguridad/pases-salida/index.blade.php`, `_formulario.blade.php` (Nuevo Pase), `_articulo.blade.php` (renglón), `_detalle.blade.php` (Firmas del pase), `imprimir.blade.php` |
| JS / CSS | bloque "Pases de salida" al final de `public/js/plataforma.js`, `public/css/plataforma.css` y `public/css/modos-pantalla.css` |
| Pruebas | `tests/Feature/Seguridad/PasesSalidaTest.php` |
| Demo | `CrearDatosDemo::pasesSalidaDemo()` |

## Tablas

**`pases_salida`** (SEGCAT: `pases_salida`)

| Columna | Notas |
|---|---|
| `empresa_id`, `sede_id` | sede de **origen** (de donde sale) |
| `folio_numero`, `folio` | `PS-000123`, consecutivo **por empresa**; `unique(empresa_id, folio_numero)` y `unique(empresa_id, folio)` |
| `motivo` | `prestamo` · `venta` · `consignacion` · `devolucion` · `reparacion` · `traspaso_definitivo` |
| `requiere_regreso` | `false` solo en venta y traspaso definitivo (un motivo nuevo pedirá regreso) |
| `colaborador_id` | solicitante |
| `destino_tipo` | `sede` · `proveedor` · `colaborador` |
| `sede_destino_id` / `proveedor_id` / `colaborador_destino_id` | según el tipo (uno obligatorio) |
| `destino_direccion`, `destino_telefono` | se llenan solos con la sede o el proveedor; editables |
| `fecha_salida_programada`, `fecha_tentativa_regreso` | fechas sin hora; la tentativa solo si espera regreso |
| `estado` | ver la máquina de estados |
| `motivo_rechazo`, `rechazado_en`, `rechazado_por` | |
| `aprobado_en`, `salio_en`, `recibido_destino_en`, `salio_regreso_en`, `regreso_en` | cuándo se completó cada grupo |
| `creado_por`, `actualizado_por`, fechas | |

**`pases_salida_articulos`**: `cantidad`, `equipo`, `marca`, `modelo`, `serie`, `descripcion` y `equipo_id` (si se escaneó del padrón de Equipos de seguridad).

**`pases_salida_firmas`**: `grupo`, `rol` (`unique(pase_salida_id, rol)`: cada rol firma una vez), `nombre_firma` (mayúsculas), `firma_ruta` (disco privado, ver [firmas](firmas.md)), `creado_por` (usuario que capturó).

Las claves de estado y rol son las de SEGCAT en minúsculas (`PENDIENTE_APROBACION` → `pendiente_aprobacion`, `JEFE_DEPTO` → `jefe_depto`): migrar datos es un `LOWER()`.

## Máquina de estados

```
pendiente_aprobacion --aprobacion (3)--> aprobado --salida_fisica (3)--> salio
salio  (destino sede, con regreso)        --recepcion_destino (4)--> en_destino
en_destino                                 --salida_regreso (6)----> en_transito_regreso
en_transito_regreso                        --regreso (4)-----------> regresado
salio  (destino proveedor o colaborador)   --regreso (4)-----------> regresado
salio  (venta / traspaso definitivo)        = cerrado ("Salió — Cerrado")
pendiente_aprobacion --rechazar (motivo)--> rechazado
```

| Grupo | Roles | Permiso | Sede que firma |
|---|---|---|---|
| `aprobacion` | jefe_depto, contraloria_salida, gerencia | `pases_salida.aprobar` | origen |
| `salida_fisica` | solicitante_salida, recibe_salida, seguridad_salida | `pases_salida.firmar` | origen |
| `recepcion_destino` | entrega_destino, recibe_destino, seguridad_destino, contraloria_destino | `pases_salida.firmar` | **destino** |
| `salida_regreso` | jefe_depto_salida_regreso, contraloria_salida_regreso, gerencia_salida_regreso, solicitante_salida_regreso, seguridad_salida_regreso, traslada_salida_regreso | `pases_salida.firmar` | **destino** |
| `regreso` | recibe_regreso, entrega_regreso, seguridad_regreso, contraloria_regreso | `pases_salida.firmar` | origen |
| Rechazar | — | `pases_salida.aprobar` | origen |

- `PaseSalida::grupoAbierto()` dice qué grupo toca; una firma de otro grupo responde «Este pase ya no está en el punto correcto del circuito para esta firma.»
- El estado avanza cuando firman **todos** los roles del grupo. La firma y el avance van en una transacción con el pase bloqueado (`lockForUpdate`): dos firmas simultáneas no se saltan el circuito.
- **Vencido**: fuera de la propiedad (`salio`, `en_destino`, `en_transito_regreso`), espera regreso y `fecha_tentativa_regreso` < **hoy en la zona horaria de la sede de origen** (`AdministradorPasesSalida::hoyPorSede()`). La insignia "Vencido — Debió Regresar" manda sobre todas.

## Endpoints

| Método y ruta | Permiso | Qué hace |
|---|---|---|
| `GET /pases-salida?filtro=&q=&sede=&pase=` | `ver` | Lista (20 por página) con filtros `todos`, `pendientes`, `aprobados`, `fuera`, `espera_regreso`, `vencidos`, búsqueda (folio, solicitante, número de empleado, artículo o serie) y sede; `pase=ID` abre su detalle |
| `POST /pases-salida` | `crear` | Alta → `pendiente_aprobacion` |
| `GET /pases-salida/{id}` | `ver` | Detalle "Firmas del pase" (HTML para el diálogo; sin AJAX redirige a `?pase=ID`) |
| `POST /pases-salida/{id}/firmas` | `aprobar` o `firmar` según el grupo | Firma de un rol (`rol`, `nombre_firma`, `firma` en base64). Límite 60/min |
| `POST /pases-salida/{id}/rechazar` | `aprobar` | Rechazo con `motivo_rechazo` |
| `GET /pases-salida/{id}/imprimir` | `imprimir` | Hoja carta con todas las firmas |
| `GET /pases-salida/{id}/firmas/{firma}` | `ver` (o `imprimir`) | Imagen de la firma, solo si la firma es de ese pase y el pase está en su alcance |
| `GET /pases-salida/equipos/{equipo}` | `crear` + `equipos.ver` | Datos del equipo escaneado para llenar un renglón (JSON) |

Todo responde **404** si el pase es de otra empresa o está fuera de su alcance; **403** si le falta el permiso del grupo.

Usa además: `GET /lector/resolver` (lector universal, tipos `colaborador` y `equipo`), `GET /colaboradores/buscar` (búsqueda por nombre), `POST /colaboradores/rapido` (Nuevo Colaborador: alta **provisional** para la caseta, ADR-0004) y `POST /proveedores/rapido` (Nuevo Proveedor).

## Permisos y alcance

- **Empresa**: todo. **Sede**: los pases que **salen de** o **van a** sus sedes (la sede destino recibe y autoriza la salida de regreso). **Propios**: además, solo los que registró.
- Registrar: solo en sedes de origen activas de su alcance de `crear`. El solicitante puede ser cualquier colaborador activo de la empresa (también el corporativo, sin sede).
- Plantillas: **Agente** (Operación: ver, crear, editar, imprimir, firmar, en su sede) registra y firma salida, recepción y regreso, **no aprueba ni rechaza**. **Director** aprueba y rechaza en toda la empresa, no registra ni firma la salida. **Asistente** registra e imprime, no firma. **Jefe de seguridad** y **Administrador**, todo en su alcance.
- La pantalla no tiene permisos escritos: el detalle muestra "Firmar" solo si `puedeFirmarGrupo()`; si no, explica qué permiso y en qué sede se necesita.

## Pantalla

- Tarjetas con borde del color de SEGCAT por estado, folio, solicitante, motivo, artículos, fechas, sede → destino, insignia y botón según el estado ("Ver / Aprobar", "Ver / Registrar Salida", "Ver / Confirmar Llegada o Regreso", "Ver / Autorizar Salida de Regreso", "Ver / Confirmar Regreso", "Ver Detalle"); traza "Creado por / Editado por".
- **Nuevo Pase de Salida** (3 pasos): sede de origen (preelegida si tiene una), motivo (aviso de regreso; la fecha tentativa solo aparece si espera regreso), solicitante con el **lector universal** (gafete/QR/NFC/número) o escribiendo su nombre (lista con `colaboradores/buscar`), Departamento y Puesto de solo lectura, destino (la sede de origen no aparece como destino), dirección y teléfono autollenados, fecha de salida = hoy, artículos con renglones dinámicos y **escaneo de equipos** del padrón. Atajos **Nuevo Colaborador** (provisional) y **Nuevo Proveedor**.
- **Firmas del pase**: resumen, artículos, aviso del estado y las secciones del circuito que aplican con su estado (Completo / En curso · n de m / Pendiente / No aplica), miniatura de cada firma, botón **Firmar** por rol con el nombre propuesto (solicitante en sus roles, el usuario en sesión en los de Seguridad, el colaborador que se lo lleva en "Recibe"), **Rechazar Pase** e **Imprimir Pase**.
- Errores de validación dentro del diálogo (alta, firma o rechazo), modos Sol y Noche, celular a 390 px con objetivos de 44 px.

## Auditoría

`pases_salida.creado`, `pases_salida.firmado` (cada firma: grupo, rol, nombre), `pases_salida.aprobado`, `pases_salida.salida_registrada`, `pases_salida.recibido_en_destino`, `pases_salida.salida_de_regreso`, `pases_salida.regresado`, `pases_salida.rechazado`. Registro legible: «Pase de salida · PS-000123».

## Qué se corrigió respecto a SEGCAT

- **Permisos**: SEGCAT usaba `pases_salida.editar` para firmar y rechazar todo. Ahora aprobar/rechazar pide `aprobar` y el resto del circuito `firmar`, y cada grupo se firma con alcance en la sede que corresponde (antes la sede destino ni siquiera podía ver el pase que recibía).
- **Firmas**: se guardaban en base64 dentro de la tabla y se mostraban sin control; ahora van al disco privado, se validan (imagen real, tamaño) y se sirven solo con permiso y alcance.
- **Folio**: era el id global de la tabla (una empresa deducía cuántos pases hacían las demás) y se escribía en dos pasos (`folio = ''` y luego `UPDATE`); ahora es consecutivo por empresa, único y en una sola transacción.
- **Carreras**: dos firmas simultáneas podían contar mal el grupo; ahora el pase se bloquea al firmar.
- **Destino obligatorio**: SEGCAT dejaba guardar un pase "a otra sede" o "a un proveedor" sin decir cuál; ahora es obligatorio y se valida que sea de la empresa y que la sede destino no sea la de origen.
- **Vencidos**: comparaban contra `CURDATE()` del servidor; ahora contra la fecha local de la sede de origen.
- **Datos ajenos**: los catálogos del formulario se armaban concatenando `id_empresa` en el SQL; ahora todo pasa por el filtro de empresa del modelo.
- **Búsqueda**: además de folio y nombre, por número de empleado, artículo y serie. Lista paginada.
- **Rechazo**: se registra quién y cuándo rechazó.

## Pendientes para integración

- Unir duplicados mueve el solicitante (`REFERENCIAS`) y el colaborador destino (`REFERENCIAS_ADICIONALES`, revisión funcional FUN-02).
- Avisos por correo ("pase pendiente de aprobación", "pase vencido") no se incluyeron: requieren una clave nueva en `Empresa::AVISOS`.
