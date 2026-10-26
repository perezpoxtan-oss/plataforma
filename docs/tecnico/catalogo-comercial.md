# Catálogo comercial y página pública de planes

> Suscripciones (prueba gratis, activación al pagar, vencimiento, gracia, solo consulta, pago anual, excepciones y promociones): ver [suscripciones.md](suscripciones.md). Marcar una solicitud **Pagada** ya no solo cambia su estado: activa al cliente o registra su pago (`Aprovisionamiento::confirmarPago()`), igual que lo hará el webhook.

Petición del dueño: una página pública con los productos disponibles por suscripción o venta directa, planes y paquetes configurables, costos configurables y los pagos en línea «preparados» para después.

Decisiones del dueño: la página vive **dentro de la plataforma** (mismo dominio, la administra el Super Administrador); la suscripción se cobra **por sede al mes**; el catálogo inicial es la plataforma por módulos; precios en **MXN más IVA** (moneda e IVA configurables).

## Pantallas y rutas

| Ruta | Quién | Qué |
|---|---|---|
| `GET /` | Visitante sin sesión | Si la página está publicada, la portada (`PortadaPublica` → `PlanesController@inicio`). Si no, `auth` lo manda a `/login` como siempre. Con sesión: el Inicio (`PanelController`), sin cambios. |
| `GET /planes` | Público | Planes, paquetes, calculadora, módulos y preguntas. |
| `GET /contratar` | Público | Formulario «Contratar o solicitar información». Acepta `?plan=<id o slug>&sedes=N&paquetes[]=ID` (lo manda la calculadora). |
| `POST /contratar` | Público | Registra la solicitud (`throttle:comercial-contratar`: 5 por minuto y 30 por día por IP). |
| `GET /contratar/gracias` | Público | Folio y siguiente paso (solo después de enviar; si no, regresa al formulario). |
| `GET /aviso-de-privacidad` | Público | El aviso de Ajustes; 404 si está vacío. |
| `POST /pagos/webhook/{pasarela}` | Pasarela | **Reservada y apagada**: 404 mientras `config('comercial.webhooks_habilitados')` sea falso o la pasarela no exista. |
| `GET /catalogo-comercial` | Super Administrador | Pestañas Solicitudes, Clientes, Por revisar, Planes, Paquetes, Productos, Promociones y Ajustes (`?pestana=`). Clientes, Por revisar y Promociones: ver [Suscripciones](suscripciones.md). |
| `GET /catalogo-comercial/vista-previa` | Super Administrador | La portada aunque no esté publicada, con la franja «Vista previa». |
| `GET /catalogo-comercial/{tipo}/nuevo`, `POST /catalogo-comercial/{tipo}` | Super Administrador | Alta (`tipo` = productos, planes, paquetes). |
| `GET /catalogo-comercial/{tipo}/{id}/editar`, `PUT /catalogo-comercial/{tipo}/{id}` | Super Administrador | Edición. |
| `PATCH /catalogo-comercial/{tipo}/{id}/estado` | Super Administrador | Dar de baja / Reactivar (`activo`). |
| `PATCH /catalogo-comercial/{tipo}/{id}/mover` | Super Administrador | Subir / bajar un lugar (`direccion=arriba|abajo`); reescribe `orden` 1..n. |
| `PUT /catalogo-comercial/ajustes` | Super Administrador | Ajustes comerciales. |
| `GET|PUT /catalogo-comercial/solicitudes/{id}` | Super Administrador | Atender: estado, referencia de pago, notas internas. |

Mientras la página **no** está publicada, las rutas públicas responden **404** (igual que una dirección inventada) salvo para el Super Administrador, que las ve con la franja de vista previa (y puede probar el formulario completo).

`PortadaPublica` va antes de `auth` gracias a `prependToPriorityList(AuthenticatesRequests::class, PortadaPublica::class)` en `bootstrap/app.php`: sin eso Laravel ordena `auth` primero y la portada nunca se vería. La ruta `/` conserva `auth`.

## Permisos

Módulo `catalogo_comercial` (área Dirección, menú **Estructura → Plataforma**, después de Identidad), **tipo plataforma** con acciones `ver` y `editar`: mismo criterio que Identidad y Respaldos. No se contrata por empresa ni sale en la Matriz; el motor de permisos solo deja pasar al Super Administrador. Las pantallas declaran `catalogo_comercial.ver` / `catalogo_comercial.editar` con `Gate::authorize`.

## Datos

Todas las tablas son **globales de la plataforma**: no llevan `empresa_id` ni usan el scope de tenant (no son datos operativos de una empresa cliente; el interesado todavía no es cliente). InnoDB, auditoría `creado_por` / `actualizado_por` (`RegistraAutor`) y timestamps.

| Tabla | Contenido |
|---|---|
| `productos` | `clave` (única), `nombre`, `descripcion`, `icono` (`bi-*`), `activo`, `orden` |
| `modulo_producto` | Módulos del catálogo que habilita cada producto (FK a `modulos`; nunca módulos de tipo plataforma) |
| `planes` | `nombre`, `slug` (único), `frase`, `descripcion`, `precio_por_sede_mensual` (decimal 10,2), `sedes_minimas`, `usuarios_por_sede` (nulo = ilimitados), `destacado` (solo uno), `cotizar`, `activo`, `publicado`, `orden` |
| `plan_producto` | Productos incluidos en cada plan |
| `paquetes` | `nombre`, `slug`, `descripcion`, `modalidad` (`suscripcion` | `unico`), `precio`, `por_sede`, `activo`, `publicado`, `orden` |
| `paquete_producto` | Productos que incluye cada paquete |
| `paquete_plan` | Planes con los que se puede sumar (vacío = con todos) |
| `solicitudes_compra` | Interesado (`nombre`, `empresa`, `correo`, `telefono`, `sedes`, `mensaje`), `plan_id` (nullOnDelete), desglose congelado (`moneda`, `iva_porcentaje`, `mensual_subtotal/iva/total`, `unico_subtotal/iva/total`, `requiere_cotizacion`, `detalle` JSON con los renglones y su precio de ese momento), `estado` (nueva, contactada, cotizada, pagada, cancelada), `pasarela`, `referencia_pago`, `notas_internas`, `acepta_privacidad` + `privacidad_aceptada_en`, `ip`, `atendida_en`, `folio` único (`SC-aammdd-XXXX`) |

`detalle` es una copia intencional (no normalizada): la solicitud debe conservar lo que se le cotizó aunque después cambien o se borren planes y paquetes.

**Ajustes comerciales** en `configuracion_plataforma` (clave `comercial`, servicio `App\Services\Comercial\AjustesComerciales`): `moneda` (MXN | USD), `iva_porcentaje` (16), `leyenda_iva` («+ IVA»), `landing_publicada` (falso), `portada_titulo`, `portada_subtitulo`, `beneficios` (lista), `preguntas` (lista de [pregunta, respuesta]), `correos_solicitudes`, `texto_legal`, `aviso_privacidad`, `sembrado_en`.

> **Borrador legal:** el `texto_legal` inicial y la ausencia de aviso de privacidad propio son provisionales; el dueño debe pasarlos por su abogado antes de publicar la página.

`notificaciones.empresa_id` pasa a aceptar nulo: avisos **de la plataforma** para el Super Administrador (`CentroNotificaciones::avisarPlataforma()`, tipo `solicitud_compra`). `Notificacion::scopeDe()` quita el filtro de empresa solo para el Super Administrador (sus avisos, siempre limitados a su `user_id`), así la campana los muestra con o sin empresa de trabajo; `NotificacionController` y la campana ya no devuelven cero al Super Administrador sin empresa. `marcarLeida()` actualiza con una consulta directa para que un aviso sin empresa no tome la empresa de trabajo al guardarse.

## Cálculo (una sola regla en servidor y navegador)

`App\Services\Comercial\Calculadora::calcular(?Plan, sedes, paquetes)` y `public/js/landing-calculo.js` (`CalculoPlanes.calcular`) implementan lo mismo, **en centavos y con enteros**:

1. Sedes cobradas = máx(sedes pedidas, `sedes_minimas` del plan); sedes entre 1 y 999.
2. Plan: `precio × sedes cobradas` al mes. Plan con «Cotizar»: sin precio, `requiere_cotizacion = true`.
3. Paquete: `precio × (sedes cobradas si por_sede; si no, 1)`, al mes (suscripción) o pago único (venta directa).
4. Un paquete que no se suma al plan elegido no se cuenta y se reporta en `incompatibles` (el formulario lo rechaza con un error; la calculadora lo desmarca y explica «Solo se suma a …»).
5. IVA = `intdiv(subtotal × IVA_centésimas + 5000, 10000)` (redondeo a la mitad hacia arriba), por separado para lo mensual y lo único.

El navegador solo manda plan, sedes y paquetes; el servidor recalcula y **congela** el resultado en la solicitud (un total enviado por el navegador se ignora). La prueba `test_la_calculadora_del_navegador_da_lo_mismo_que_el_servidor` ejecuta el archivo JS con Node sobre ~80 combinaciones y compara con el servidor (se omite si no hay Node).

## Página pública

- Vistas `resources/views/publico/planes/*` con plantilla propia (sin Bootstrap ni menú de la plataforma): `public/css/landing.css`, `public/js/landing-calculo.js` y `public/js/landing.js`. Sin JavaScript en línea (CSP) y sin librerías nuevas; la fuente es la Plus Jakarta Sans servida por la plataforma.
- Marca: nombre, eslogan, colores y símbolo de **Identidad de la plataforma**.
- Sin JavaScript todo funciona: la calculadora es un formulario GET a `/contratar` y el servidor pinta el desglose; con JavaScript se recalcula al momento (`aria-live`).
- Formulario: CSRF, campo trampa `sitio_web` (respuesta igual a la de una persona, sin guardar ni avisar), límite por IP, validación en el servidor con mensajes en español, aceptación del aviso de privacidad (con enlace si hay aviso en Ajustes).
- `noindex` mientras no esté publicada, fuera de Producción y en el formulario; `Cache-Control: no-store` en el formulario.
- Accesible: enlace «Saltar al contenido», foco visible, menú del celular con `aria-expanded`, preguntas con `<details>`, movimiento solo en la bitácora de la portada y apagado con `prefers-reduced-motion`.
- Vocabulario: la página pública usa «Contratar», «Solicitar información», «Enviar solicitud» y «Entrar» (pedido explícito del dueño para el visitante); la administración usa Guardar, Dar de baja, Reactivar y Atender (criterio 4).

## Pagos en línea: cómo conectarlos

Hoy **no se cobra nada**. La pasarela activa es `manual` (`App\Services\Comercial\Pagos\PasarelaManual`): deja la solicitud «nueva», avisa al Super Administrador por campana y correo (y a `correos_solicitudes`) y manda al interesado un acuse con su folio y desglose. El pago se acuerda por fuera y se anota en la solicitud (estado «Pagada» y referencia).

Interfaz `App\Contracts\PasarelaPago`:

```php
public function clave(): string;                               // «mercadopago», «stripe», «conekta»
public function nombre(): string;
public function crearCobro(SolicitudCompra $s): ResultadoCobro; // estado «redirigir» + urlPago para enviar al interesado a pagar
public function confirmar(Request $request): ?SolicitudCompra;   // aviso de la pasarela (webhook)
```

Pasos para conectar una pasarela (por ejemplo Mercado Pago, Stripe o Conekta):

1. Crear `App\Services\Comercial\Pagos\PasarelaMercadoPago implements PasarelaPago`. Usar su API REST con el cliente HTTP de Laravel (`Http::`); no agregar SDKs que necesiten `exec`/`proc_open` (hospedaje compartido).
2. `crearCobro()`: crear la preferencia / sesión de pago con los importes **de la solicitud** (`mensual_total` para la primera mensualidad o suscripción, `unico_total` para el pago único; nunca recalcular con precios nuevos), guardar el id en `referencia_pago` y devolver `new ResultadoCobro('redirigir', urlPago: …)`. `PlanesController@guardar` ya redirige con `redirect()->away()`.
3. `confirmar()`: validar la **firma** del aviso con la clave secreta, **consultar el pago a la pasarela** (no confiar solo en el cuerpo recibido), comprobar importe y moneda contra la solicitud, marcarla `pagada` con `referencia_pago`, auditar y avisar (campana `solicitud_compra` resuelta). Debe ser idempotente (la pasarela reintenta).
4. Registrar la clase en `Pasarelas::DISPONIBLES`.
5. Claves en el `.env` del servidor (nunca en el repositorio), leídas desde `config/comercial.php` → `claves`.
6. En `.env`: `COMERCIAL_PASARELA=mercadopago` y `COMERCIAL_WEBHOOKS=true`.
7. **Exceptuar la ruta del token CSRF**: en `bootstrap/app.php`, `$middleware->validateCsrfTokens(except: ['pagos/webhook/*']);` (la pasarela no manda token; la seguridad la da la firma). Hoy no se exceptúa porque la ruta está apagada y la prueba de seguridad exige que ninguna ruta salte el CSRF: actualizar `AutenticacionSesionTest::test_toda_ruta_que_cambia_datos_exige_token_csrf` con esa única excepción.
8. Configurar en la pasarela la dirección `https://<dirección de la plataforma>/pagos/webhook/mercadopago`.
9. Pruebas: firma inválida (400, nada cambia), pago aprobado (pagada), aviso repetido (sin duplicar), importe distinto (no se marca).
10. Para cobros recurrentes (suscripción mensual automática) usar el producto de suscripciones de la pasarela; guardar su id en `referencia_pago` y tratar cada aviso de cobro mensual en `confirmar()`.

## Criterios de experiencia de uso

| Regla | Cómo se cumple |
|---|---|
| 1. Campana | Cada solicitud llega a la campana del Super Administrador (y por correo); el interesado recibe acuse con folio. **Excepción a revisar con el dueño:** «Mis pendientes» es por empresa y el Super Administrador sin empresa no tiene; las solicitudes nuevas se cuentan en la pestaña (número rojo) y la campana. |
| 3. Reloj y color | Las solicitudes «Nueva» muestran «Sin atender hace …» en verde, ámbar (> 1 día) y rojo (> 3 días) (`RelojVoBo`). No hay suplente: solo hay un rol que atiende (Super Administrador); los correos extra de Ajustes hacen de respaldo. |
| 4. Verbos | Guardar, Dar de baja, Reactivar, Atender. |
| 5. Siguiente paso | Al guardar: «Ver la página como la verá el público», «Dar de alta otro…», «Atender la siguiente nueva». |
| 7. Avanzado | Sedes mínimas, usuarios, recomendado, cotizar, dirección corta, compatibilidad de paquetes, preguntas y textos legales van plegados. |
| 8. Alta corta | Plan: nombre y precio; producto: nombre y clave; lo demás se completa en la edición. |

## Datos iniciales

`Database\Seeders\CatalogoComercialSeeder` (desde `DatabaseSeeder`) corre **una sola vez** (marca `sembrado_en`; no hace nada si ya hay productos, planes o paquetes): 6 productos (Estructura y usuarios, Caseta y accesos, Padrones, Incidentes y novedades, Recursos Humanos y candidatos, Informes) ligados a los módulos reales; 3 planes (Esencial $890, Profesional $1,490 recomendado, Corporativo «Cotizar» desde 5 sedes) y 3 paquetes (Recursos Humanos y candidatos $390/sede/mes solo con Esencial y Profesional; Puesta en marcha y capacitación $4,500/sede pago único; Kit de lector para caseta $2,800 pago único). **Los precios son de ejemplo** y la página nace **sin publicar**. `plataforma:demo` agrega dos solicitudes de ejemplo (`SC-DEMO-0001` nueva y `SC-DEMO-0002` cotizada) si no hay ninguna.

## Auditoría

`catalogo_comercial.{producto|plan|paquete}_{creado|actualizado|dado_de_baja|reactivado}`, `catalogo_comercial.ajustes_actualizados` y `catalogo_comercial.solicitud_atendida`, con antes y después (etiquetas en `LectorAuditoria`).

## Migración

`2026_10_31_000100_crear_catalogo_comercial`: tablas, `notificaciones.empresa_id` nulo y el módulo (en una base existente; en una instalación nueva lo crean los seeders). `down()` borra el módulo (y recorre el menú), los ajustes `comercial`, los avisos sin empresa, las tablas, y regresa la columna a obligatoria.

## Pruebas

`tests/Feature/Administracion/CatalogoComercialTest.php`: datos iniciales una sola vez; landing oculta (404 y `/` al acceso) y publicada; con sesión `/` es el Inicio; ocultos y de baja no se ven; vista previa del Super Administrador; cálculo (mínimo de sedes, cotizar, incompatibles, IVA y redondeo); calculadora del navegador = servidor (Node); solicitud válida (desglose del servidor, congelado, campana sin empresa, correos), campana con empresa de trabajo elegida, inválida, campo trampa, límite por equipo; ruta de pagos apagada; solo el Super Administrador administra; alta, edición, baja, reactivación y orden; ajustes (publicar, IVA, textos, correos, aviso); atender una solicitud; `down()` y `up()` de la migración. Se ajustaron `MenusReorganizadosTest`, `SuperficieDeRutasTest`, `MatrizDeRutasTest` / `EscenarioAuditoria` y `RecorridoPantallasTest` (rutas nuevas).

## Lista de dependencias y planes coherentes (Etapa 1, C-02 y C-05)

- **`App\Support\DependenciasModulos::REQUIERE`**: módulo => módulos que necesita (solo dependencias duras). Ejemplos: `accesos` → colaboradores, departamentos, autorizaciones; `transporte` → rutas; `equipos_pc` ↔ `recorridos_pc`; `reservas` → departamentos; `recepcion_rh` → candidatos, accesos. `ACCIONES_CON_MODULO`: `colaboradores.datos_personales` solo con `candidatos`.
- **`App\Services\Comercial\CoherenciaCatalogo`**: `modulosDe(plan, paquetes)` (Dirección + productos + submódulos), `faltantes()`, `problemas()` (todos los planes activos, solos y con cada paquete que se les suma), `avisoAlGuardar()` y `completarProducto()`.
- **Al guardar un producto** se le agregan los módulos que sus módulos necesitan (salvo los de Dirección) y el aviso dice «Se agregó X porque Y lo necesita». **Al guardar un plan o paquete** que deja algo sin cubrir se guarda igual, con el aviso en rojo; la pestaña del catálogo muestra «Hay planes que no funcionan como están».
- **Prueba de CI:** `tests/Feature/Nucleo/DependenciasModulosTest.php` falla si la lista nombra un módulo que no existe o si un plan o paquete (de fábrica o publicado) vende un módulo sin lo que necesita.
- **Catálogo de fábrica** (`CatalogoComercialSeeder::PRODUCTOS`, `PLANES`, `PAQUETES`): Estructura (con colaboradores, departamentos, puestos, turnos y autorizaciones), Caseta, Padrones (sin Equipos PC), Incidentes (con Equipos PC), Recursos Humanos (recepción, candidatos, vacantes), Informes y **Reservas y eventos**. Esencial = Estructura + Caseta + Padrones; Profesional = + Incidentes + Informes; Corporativo = + Recursos Humanos. Paquetes: Recursos Humanos (Esencial y Profesional), **Reservas y eventos** (los tres; precio de ejemplo `PRECIO_RESERVAS` = 290), Puesta en marcha y Kit lector.
- **Página pública:** cada producto muestra «Incluye: …» con los nombres de sus módulos reales (`CoherenciaCatalogo::modulosPorProducto()`).
- **Giro al contratar (DD-04):** `solicitudes_compra.rubro_id` (anulable; migración `2026_11_10_000400`). Contratar lo pide (obligatorio, cuatro opciones); «Tu suscripción» lo muestra y lo manda al ampliar. `Aprovisionamiento::activarCliente()` crea la empresa con ese giro (sin giro: hotel, como antes). En un cliente existente el giro no se cambia solo: la solicitud avisa al Super Administrador si es distinto.
- **Instalaciones existentes:** la migración `2026_11_10_000200_organizacion_base_y_paquetes_coherentes` mueve áreas, menú y `modulo_producto`, actualiza las descripciones de ejemplo que nadie editó, crea el producto y el paquete «Reservas y eventos», convierte en excepciones los cambios manuales de Empresas → Módulos y recalcula cada suscripción. Todo queda anotado en `configuracion_plataforma.etapa1_cimientos` y `down()` lo regresa tal cual (el paquete creado solo se borra si ningún cliente lo tiene).
