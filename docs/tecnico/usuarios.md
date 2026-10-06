# Usuarios

Réplica de `modules/usuarios/usuario_lista.php`, `usuario_modal_editar.php` y `usuario_proceso.php` de SEGCAT.

## Pantalla

| Ruta | Permiso | Qué hace |
|---|---|---|
| `GET /usuarios` | `usuarios.ver` | Fichas de usuarios con buscador y filtro por sede |
| `POST /usuarios` | `usuarios.crear` | Alta: nombre, núm. de colaborador, sede, rol, usuario, correo y contraseña |
| `PUT /usuarios/{usuario}` | `usuarios.editar` | Edición; la contraseña en blanco se conserva |
| `PATCH /usuarios/{usuario}/estado` | `usuarios.eliminar` | Desactivar o reactivar (no se borra a nadie) |
| `PATCH /usuarios/{usuario}/desbloquear` | `usuarios.desbloquear` | Quita el bloqueo por intentos fallidos |
| `GET /usuarios/homonimos?nombre=&excluir=` | `usuarios.crear` o `usuarios.editar` | Aviso en vivo de homónimos (JSON, ver abajo) |

## Reglas

- **Un rol y una sede por cuenta**, como en SEGCAT. "Todas las sedes" significa que el usuario ve todas las sedes de su empresa.
- El selector de rol solo muestra los roles **de nivel inferior al del actor**, y el servidor lo vuelve a validar.
- No se administra la **propia cuenta**, ni a usuarios de nivel igual o superior, ni al Super Administrador.
- La lista respeta el **alcance** de `usuarios.ver`: toda la empresa, los usuarios asignados a sus sedes, o solo los que el actor dio de alta.
- Únicos: el usuario y el correo, en toda la plataforma; el número de colaborador, dentro de cada empresa. El número es opcional, para cuentas de soporte.
- Contraseña: mínimo 8 caracteres, con letras y números; máximo 72; no puede ser de las más usadas (`App\Rules\ContrasenaSegura`) ni contener el nombre de usuario o el correo.
- **Desactivar** o **cambiar la contraseña** cierra de inmediato las sesiones abiertas de esa persona: se borran de la tabla `sessions`.
- Auditoría: `usuarios.creado`, `usuarios.actualizado`, `usuarios.desactivado`, `usuarios.reactivado` y `usuarios.desbloqueado`, con el antes y el después (incluidos rol y sede). Si se cambió la contraseña solo se anota `contrasena: cambiada`; nunca su valor ni su hash.

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

`tests/Feature/Administracion/UsuariosTest.php`, `DesbloqueoUsuariosTest.php` y `UsuariosHomonimosTest.php`
