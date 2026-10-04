# Roles y Permisos

Réplica de `modules/roles/rol_lista.php` y `modules/permisos/permisos_lista.php` de SEGCAT sobre el motor de permisos (ver `nucleo.md` y ADR-0002).

## Pantallas

| Ruta | Permiso | Qué hace |
|---|---|---|
| `GET /roles` | `roles.ver` | Fichas de roles: nivel, descripción, usuarios, quién creó o editó |
| `POST /roles` | `roles.crear` | Nuevo rol (diálogo) |
| `PUT /roles/{rol}` | `roles.editar` | Nombre, descripción, nivel, activo |
| `DELETE /roles/{rol}` | `roles.eliminar` | Solo si no tiene usuarios |
| `GET /permisos?rol=` | `permisos.ver` | Matriz de permisos de un rol |
| `PUT /permisos/{rol}` | `permisos.editar` | Guarda la matriz completa del rol |
| `POST /empresa-activa` | Super Administrador | Elige la empresa de trabajo (o las plantillas) |

En SEGCAT ambas pantallas usaban los permisos del módulo `permisos`. Ahora **Roles** tiene los suyos (`roles.*`), así se puede dejar a alguien ajustar permisos sin dejarlo crear o borrar roles.

## Reglas

- Nombre y nivel jerárquico son únicos por empresa. Menor número = más privilegios.
- Nadie crea, edita, sube de nivel ni elimina un rol de **nivel igual o superior al suyo**. Tampoco se toca su propio rol.
- En la matriz solo se valida **lo que cambia**. Agregar, quitar o ampliar un permiso exige tenerlo con un alcance igual o mayor. Lo que el rol ya tenía, otorgado por alguien de más nivel, se conserva al guardar.
- Marcar cualquier acción agrega "Ver" (en el navegador y en el servidor). Quitar "Ver" quita las demás acciones del módulo.
- El **alcance** se elige por módulo y aplica a todas sus acciones: *Solo los propios*, *Su sede* o *Toda la empresa*.
- La matriz muestra solo los módulos **contratados** por la empresa. Los permisos de módulos no visibles se conservan al guardar.
- Módulos y acciones salen del catálogo de la base de datos: lo que llegue del navegador y no exista en el catálogo se ignora.
- Desactivar un rol retira de inmediato sus permisos a quienes lo tienen; el motor ignora roles inactivos.
- Todo cambio queda en `auditoria` con los eventos `roles.creado`, `roles.actualizado`, `roles.eliminado` y `permisos.rol_actualizado`, incluyendo el antes y el después.

## Super Administrador

Un selector "Empresa de trabajo" (`EmpresaDeTrabajo`, sesión `empresa_activa_id`) define sobre qué empresa opera. Sin elegir, trabaja sobre las **plantillas de la plataforma**: los roles que se copian a cada empresa nueva.

## Componentes

- `App\Http\Controllers\Administracion\RolController`, `PermisoController`, `EmpresaActivaController`
- `App\Services\Permisos\AdministradorRoles`: `crearRol`, `actualizarRol`, `eliminarRol`, `sincronizarPermisos`
- `App\Support\Tenancy\EmpresaDeTrabajo`
- Vistas en `resources/views/administracion/`; estilos en la sección "Componentes de pantallas de administración" de `public/css/plataforma.css`; comportamiento en `public/js/plataforma.js`. Los diálogos usan `data-abrir-dialogo`, `data-cerrar-dialogo` y `data-confirmar`, sin `onclick`.
- `CatalogoSeeder::RUTAS` enlaza cada módulo migrado con su pantalla. Los demás siguen mostrando el aviso "en migración".

## Pruebas

`tests/Feature/Administracion/RolesYPermisosTest.php`
