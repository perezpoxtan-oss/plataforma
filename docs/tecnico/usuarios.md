# Usuarios

Réplica de `modules/usuarios/usuario_lista.php`, `usuario_modal_editar.php` y `usuario_proceso.php` de SEGCAT.

## Pantalla

| Ruta | Permiso | Qué hace |
|---|---|---|
| `GET /usuarios` | `usuarios.ver` | Fichas de usuarios con buscador y filtro por sede |
| `POST /usuarios` | `usuarios.crear` | Alta: nombre, núm. de colaborador, sede, rol, usuario, correo y contraseña |
| `PUT /usuarios/{usuario}` | `usuarios.editar` | Edición; la contraseña en blanco se conserva |
| `PATCH /usuarios/{usuario}/estado` | `usuarios.eliminar` | Dar de baja o reactivar (no se borra a nadie) |
| `PATCH /usuarios/{usuario}/desbloquear` | `usuarios.desbloquear` | Quita el bloqueo por intentos fallidos |
| `POST /usuarios/{usuario}/restablecer` | `usuarios.editar` | Restablecer contraseña: enlace por correo (60 min) o contraseña escrita que se cambia al entrar |
| `GET /usuarios/homonimos?nombre=&excluir=` | `usuarios.crear` o `usuarios.editar` | Aviso en vivo de homónimos (JSON, ver abajo) |

## Reglas

- **Un rol y una sede por cuenta**, como en SEGCAT. "Todas las sedes" significa que el usuario ve todas las sedes de su empresa.
- El selector de rol solo muestra los roles **de nivel inferior al del actor**, y el servidor lo vuelve a validar.
- No se administra la **propia cuenta**, ni a usuarios de nivel igual o superior, ni al Super Administrador.
- La lista respeta el **alcance** de `usuarios.ver`: toda la empresa, los usuarios asignados a sus sedes, o solo los que el actor dio de alta.
- Únicos: el usuario y el correo, en toda la plataforma; el número de colaborador, dentro de cada empresa. El número es opcional, para cuentas de soporte.
- Contraseña: mínimo 8 caracteres, con letras y números; máximo 72; no puede ser de las más usadas (`App\Rules\ContrasenaSegura`) ni contener el nombre de usuario o el correo.
- **Dar de baja** o **cambiar la contraseña** cierra de inmediato las sesiones abiertas de esa persona: se borran de la tabla `sessions`.
- Auditoría: `usuarios.creado`, `usuarios.actualizado`, `usuarios.desactivado`, `usuarios.reactivado` y `usuarios.desbloqueado`, con el antes y el después (incluidos rol y sede); las claves de evento no cambiaron aunque la pantalla diga «Dar de baja» / «DE BAJA» (criterio 4). Si se cambió la contraseña solo se anota `contrasena: cambiada`; nunca su valor ni su hash.
- La contraseña la crea su dueño (enlace de bienvenida por omisión); si el administrador la escribe, la persona la cambia en su primer acceso. Ver «La cuenta es de su dueño».

## La cuenta es de su dueño (criterio 6 de docs/criterios-ux.md)

Nadie conoce la contraseña de otra persona: ni quien la da de alta ni quien la restablece. Piezas:

| Pieza | Qué hace |
|---|---|
| `App\Services\Cuenta\RecuperacionContrasena` | Enlaces de un solo uso con el *password broker* de Laravel (`password_reset_tokens`, hash del token) **y** firma temporal de la URL (`URL::temporarySignedRoute`). Dos brokers en `config/auth.php`: `users` (restablecer, 60 min, 1 por minuto) y `bienvenida` (24 h). `solicitar()` es la lógica de «Olvidé mi contraseña»: mismo resultado exista o no la cuenta; solo envía si la cuenta está activa (y su empresa), tiene correo válido, la plataforma tiene SMTP (`CorreoPlataforma::configurado()`) y no pasó de **3 solicitudes por hora por cuenta** (`RateLimiter`, clave con hash del identificador). `restablecer()` guarda, limpia `debe_cambiar_contrasena`, `intentos_fallidos` y `bloqueado_hasta`, cierra sesiones, audita y avisa por campana |
| `App\Http\Controllers\Auth\RecuperacionController` | `GET/POST /olvide-contrasena` (sin sesión; `throttle:5,1` por IP), `GET /restablecer-contrasena/{token}?u=&tipo=&expires=&signature=` (firma válida + token vigente, si no rebota a «Olvidé» con error neutro) y `POST /restablecer-contrasena` (`throttle:10,1`). El tipo de enlace se recuerda en la sesión al abrirlo: el formulario no puede cambiarlo. Sin SMTP la pantalla dice que pida a su administrador, antes de pedir nada |
| `App\Http\Controllers\Cuenta\MiCuentaController` + `App\Services\Cuenta\CuentaPropia` | **Mi cuenta** (`GET /mi-cuenta`, `PUT /mi-cuenta/contrasena`, `throttle:10,1`): sin permiso; cambia la propia contraseña exigiendo la actual y que sea distinta; cierra las **otras** sesiones; nombre, usuario, correo, rol y sede solo se muestran (los administra Usuarios; el correo es el canal de recuperación y debe seguir verificado por quien administra). Enlace en el nombre del usuario (PC), el avatar (celular) y *Menú → Sesión → Mi cuenta* |
| `App\Http\Middleware\ExigirCambioContrasena` (grupo `web`, después de `EstablecerEmpresa`) | Con `users.debe_cambiar_contrasena = true` cualquier ruta redirige a Mi cuenta (JSON: 403) salvo `ExigirCambioContrasena::PERMITIDAS` (Mi cuenta, salir, latido/expiración de sesión y el resumen de la campana) |
| `AdministradorUsuarios::crear(..., $enlace)` | Por omisión (`contrasena_modo=enlace`, solo si hay SMTP) la cuenta nace con una contraseña aleatoria de 40 caracteres que nadie conoce y se envía `BienvenidaUsuario`; con `manual` se guarda la escrita. En ambos casos `debe_cambiar_contrasena = true` (el enlace la limpia al usarse). Devuelve `[User, bool enviado]` |
| `AdministradorUsuarios::restablecer()` | Mismas dos opciones para una cuenta existente (`usuarios.editar`, nivel inferior, dentro del alcance; nunca la propia). La contraseña anterior deja de servir al instante y se cierran sus sesiones. `actualizar()` con contraseña escrita también marca `debe_cambiar_contrasena` |
| `App\Services\Cuenta\AvisosCuenta` | Avisos de seguridad: `bloqueo()` (campana a quien tiene `usuarios.editar` con alcance a la sede de la cuenta —`usuariosConPermiso()` con `Autorizador::permisosEfectivos`—, correo a ellos y al afectado si el aviso `cuenta_bloqueada` de la empresa está encendido; **uno por bloqueo**, no por intento; IP recortada `187.190.x.x`), `desactivadaPorBaja()` y `contrasenaCambiada()` (a la propia persona). `cerrarSesiones()` es la única forma de borrar filas de `sessions` |
| `AdministradorColaboradores::cambiarEstado()` | La **baja** del colaborador desactiva su cuenta vinculada (misma empresa, activa, no Super Administrador): `activo = false`, `desactivado_por_baja_en = now()`, sesiones cerradas, auditoría `usuarios.desactivado_por_baja` y campana. El **reingreso no la reactiva**: el mensaje lo recuerda y se hace en Usuarios (`cambiarEstado(true)` limpia la marca) |
| `MisPendientes` → «Cuentas por revisar» | Para quien tiene `usuarios.editar`, dentro de su alcance: bloqueadas ahora o dadas de baja junto con su colaborador en los últimos 7 días. La lista de Usuarios las muestra arriba (`?revisar=1` solo sirve de enlace) y en la ficha («Desactivada por la baja de su colaborador», «Creará su contraseña al entrar») |

Correos: `App\Mail\BienvenidaUsuario`, `RestablecerContrasena` y `CuentaBloqueada` (vistas `correos/bienvenida`, `restablecer-contrasena`, `cuenta-bloqueada`), enviados con `defer()` por `CorreoPlataforma`; nunca llevan la contraseña ni el correo en la URL (solo token, id y tipo, firmados).

Auditoría nueva (etiquetas en `LectorAuditoria`): `usuarios.contrasena_cambiada` (con `primer_acceso` y origen), `usuarios.recuperacion_solicitada` (con `correo_enviado`), `usuarios.recuperacion_completada` (tipo bienvenida/restablecer), `usuarios.bienvenida_enviada`, `usuarios.enlace_restablecer_enviado` y `usuarios.desactivado_por_baja`. Jamás se anota un valor ni un hash de contraseña.

Migración `2026_10_21_000100_cuenta_propia_y_recuperacion`: `users.debe_cambiar_contrasena` (boolean, default false: los usuarios existentes **no** quedan marcados) y `users.desactivado_por_baja_en` (timestamp nullable). `down()` quita las dos columnas; no toca índices ni llaves. Si solo se revierte el código, las columnas quedan sin usarse y la versión anterior funciona igual.

Decisiones:
- El correo no se cambia desde Mi cuenta: es el canal de recuperación y lo mantiene verificado quien administra usuarios (regla 2: un dato en un solo lugar).
- Sin SMTP configurado, «Olvidé mi contraseña» no pide nada y el alta solo ofrece escribir la contraseña; el administrador la restablece desde Usuarios.
- El Super Administrador recupera su contraseña por el mismo camino (sin campana, con auditoría); nadie lo restablece desde Usuarios.

Pruebas: `tests/Feature/Seguridad/CuentaTest.php`.

## Desbloqueo manual (QA A-03)

- Acción adicional `desbloquear` del módulo `usuarios` → permiso **`usuarios.desbloquear`** (aparece en "Otras acciones" de la Matriz de permisos).
- La ficha de una cuenta con `bloqueado_hasta` en el futuro muestra **BLOQUEADO hasta HH:MM** en la zona horaria de la empresa (`empresas.zona_horaria`).
- El candado aparece si el actor puede `usuarios.desbloquear`, el usuario es administrable (nivel inferior, no es su propia cuenta ni el Super Administrador) y está dentro del **alcance de `usuarios.desbloquear`** (empresa, sus sedes o los que dio de alta). El servidor repite las validaciones en `AdministradorUsuarios::desbloquear`, además del alcance de `usuarios.ver` (fuera de él responde 404).
- Desbloquear pone `bloqueado_hasta = null` e `intentos_fallidos = 0` y audita `usuarios.desbloqueado` con los valores previos. Si la cuenta ya no estaba bloqueada, solo avisa.
- `AdministradorUsuarios::limitarAlcance()` concentra el filtro por alcance (empresa, sedes, propios) que antes estaba en el controlador; lo usan la lista (`usuarios.ver`) y el desbloqueo.
- Plantillas: Administrador (toda la empresa) y Jefe de seguridad (`usuarios.ver` + `usuarios.desbloquear`, alcance su sede; la matriz exige "ver" para cualquier acción). El Super Administrador siempre puede.
- Bases existentes: la migración `2026_10_05_000100_agregar_accion_desbloquear_usuarios` (el despliegue corre `migrate` antes de `db:seed`) crea la acción y la otorga a los roles "Administrador" (plantilla y copias de cada empresa) que ya tienen `usuarios.editar`, con ese mismo alcance, y a los roles "Jefe de seguridad" (`usuarios.ver` y `usuarios.desbloquear`, su sede). Solo agrega; no cambia permisos existentes. En una instalación nueva no hace nada (lo hacen los seeders).
- El Super Administrador elige la empresa con el selector "Empresa de trabajo".

## Homónimos (QA 4)

Caso real: se crearon dos usuarios «Daniela Canul May» (uno vinculado al colaborador #1008 y otro no) sin ningún aviso.

- **Comparación:** `App\Services\Usuarios\HomonimosUsuarios::clave()` → minúsculas, sin acentos (la ñ se conserva) y un solo espacio. «DANIELA  Canúl may» = «Daniela Canul May».
- **Servidor (alta y edición):** si otro usuario de la empresa (sin el Super Administrador ni la cuenta que se edita) tiene el mismo nombre y no llega `confirmar_homonimo=1`, responde error de validación en `confirmar_homonimo`: «Ya existe un usuario con ese nombre: @usuario (Rol). ¿Es la misma persona? Si es otra persona con el mismo nombre, marca «Sí, es otra persona con el mismo nombre» y guarda de nuevo…». Se revisa después de validar los campos, el colaborador, el rol y la sede. Al editar solo se exige si el nombre **cambió** a uno que ya existe. Usuario y correo siguen siendo únicos.
- **En vivo:** `GET /usuarios/homonimos?nombre=Daniela Canul May&excluir={id}` → `{"usuarios": [{"username", "rol", "colaborador", "activo"}], "otros": n, "colaboradores": [resumen], "requiere_confirmacion": bool, "mensaje"}`.
  - `usuarios`: solo los que el actor puede ver (alcance de `usuarios.ver`); `otros` cuenta los de sedes que no tiene a cargo (sin datos).
  - `colaboradores`: activos, sin cuenta (salvo la que se edita), de las sedes del permiso con que entra (`usuarios.crear` o `usuarios.editar`), cuyo nombre completo —o nombre + apellido paterno— coincide. Se sugieren con el botón «Vincular con colaborador #1008 Daniela Canul May».
  - Otra empresa no ve nada; sin `usuarios.crear|editar` → 403; nombre menor de 3 letras o no texto → respuesta vacía.
- **Diálogo:** caja `[data-homonimos]` + casilla `confirmar_homonimo` («Sí, es otra persona con el mismo nombre»), que se muestra cuando hay homónimos; bloque JS «Ajustes QA 4» al final de `public/js/plataforma.js`. Al reabrir con error, el aviso vuelve a aparecer.
- Pruebas: `tests/Feature/Administracion/UsuariosHomonimosTest.php`.

## Vínculo con Colaboradores (resuelto)

SEGCAT permitía buscar un colaborador para llenar los datos y vincular la cuenta (`id_colaborador`). Ya está: columna `users.colaborador_id` y búsqueda en el diálogo (ver [colaboradores.md](colaboradores.md#usuarios--colaborador)).

## Componentes

- `App\Services\Usuarios\AdministradorUsuarios`: alta, edición y estado, con validaciones contra escalamiento.
- `App\Http\Controllers\Administracion\UsuarioController`
- Vista `resources/views/administracion/usuarios/index.blade.php`, tema rojo en `plataforma.css`.
- Edición genérica en diálogo con `data-accion="editar-registro"` y `data-valores`, sin pedir HTML al servidor.
- Migración `2026_10_04_000500`: `users.numero_colaborador`.

## Pruebas

`tests/Feature/Administracion/UsuariosTest.php`, `DesbloqueoUsuariosTest.php`, `UsuariosHomonimosTest.php` y `tests/Feature/Seguridad/CuentaTest.php`

## Ronda 8: ¿el correo es obligatorio en Nuevo Usuario?

Se revisó y **se queda obligatorio**: en SEGCAT `usuarios.correo` es `NOT NULL` y los formularios de alta y edición lo pedían (`required`). Además la plataforma lo usa para los avisos por correo (vouchers con cobro, altas por verificar, procedimientos, pases, autorizaciones) y es el medio para recuperar la contraseña («Olvidé mi contraseña», enlace de bienvenida y restablecer desde Usuarios); un usuario sin correo se quedaría sin avisos y sin recuperación. Sigue siendo único en la plataforma.

> Mejoras de 2026-10 (roles sin números, asistente de permisos, configuración por módulo, avisos por persona, historial y varias sedes): ver [estructura-mejoras.md](estructura-mejoras.md) y [ADR-0016](../decisiones/ADR-0016-mejoras-de-estructura.md).

## Verificación en dos pasos

Cada cuenta puede activar la verificación en dos pasos en Mi cuenta; quien tiene `usuarios.desbloquear` la **restablece** (`PATCH /usuarios/{usuario}/dos-pasos`, mismo alcance y jerarquía que desbloquear, auditoría `usuarios.dos_pasos_restablecida`, aviso a la persona). La ficha muestra «Verificación en dos pasos activa». Detalle en [dos-pasos.md](dos-pasos.md).
