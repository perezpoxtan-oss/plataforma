# Usuarios

Réplica de `modules/usuarios/usuario_lista.php`, `usuario_modal_editar.php` y `usuario_proceso.php` de SEGCAT.

## Pantalla

| Ruta | Permiso | Qué hace |
|---|---|---|
| `GET /usuarios` | `usuarios.ver` | Fichas de usuarios con buscador y filtro por sede |
| `POST /usuarios` | `usuarios.crear` | Alta: nombre, núm. de colaborador, sede, rol, usuario, correo y contraseña |
| `PUT /usuarios/{usuario}` | `usuarios.editar` | Edición; la contraseña en blanco se conserva |
| `PATCH /usuarios/{usuario}/estado` | `usuarios.eliminar` | Desactivar o reactivar (no se borra a nadie) |

## Reglas

- **Un rol y una sede por cuenta**, como en SEGCAT. "Todas las sedes" significa que el usuario ve todas las sedes de su empresa.
- El selector de rol solo muestra los roles **de nivel inferior al del actor**, y el servidor lo vuelve a validar.
- No se administra la **propia cuenta**, ni a usuarios de nivel igual o superior, ni al Super Administrador.
- La lista respeta el **alcance** de `usuarios.ver`: toda la empresa, los usuarios asignados a sus sedes, o solo los que el actor dio de alta.
- Únicos: el usuario y el correo, en toda la plataforma; el número de colaborador, dentro de cada empresa. El número es opcional, para cuentas de soporte.
- Contraseña: mínimo 8 caracteres, con letras y números.
- **Desactivar** o **cambiar la contraseña** cierra de inmediato las sesiones abiertas de esa persona: se borran de la tabla `sessions`.
- Auditoría: `usuarios.creado`, `usuarios.actualizado`, `usuarios.desactivado` y `usuarios.reactivado`, con el antes y el después (incluidos rol y sede).
- El Super Administrador elige la empresa con el selector "Empresa de trabajo".

## Pendiente para Colaboradores

SEGCAT permitía buscar un colaborador para llenar los datos y vincular la cuenta (`id_colaborador`). Se agregará al migrar Colaboradores, con la columna `colaborador_id`.

## Componentes

- `App\Services\Usuarios\AdministradorUsuarios`: alta, edición y estado, con validaciones contra escalamiento.
- `App\Http\Controllers\Administracion\UsuarioController`
- Vista `resources/views/administracion/usuarios/index.blade.php`, tema rojo en `plataforma.css`.
- Edición genérica en diálogo con `data-accion="editar-registro"` y `data-valores`, sin pedir HTML al servidor.
- Migración `2026_10_04_000500`: `users.numero_colaborador`.

## Pruebas

`tests/Feature/Administracion/UsuariosTest.php`
