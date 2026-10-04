# Núcleo: empresas, sedes, módulos y permisos

## Tablas

| Tabla | Para qué |
|---|---|
| `rubros` | Giro del cliente (hotel, corporativo, condominio, fraccionamiento) y su terminología visible |
| `empresas` | Clientes (tenants). Zona horaria, idioma y moneda propios |
| `sedes` | Sedes de cada empresa; zona horaria propia o heredada |
| `users` | Cuentas de acceso; `empresa_id` nulo solo para el Super Administrador |
| `areas` | Agrupan módulos por actividad: Organización, Seguridad, Reportes, Administración (y las que se agreguen) |
| `modulos` | Funciones del sistema; `padre_id` para submódulos; `tipo` sistema o configurable |
| `acciones` | ver, crear, editar, eliminar, aprobar, firmar, imprimir, exportar, reabrir, configurar |
| `modulo_acciones` | Qué acciones aplican a cada módulo |
| `empresa_modulos` | Módulos activos por empresa (plan contratado), con nombre visible opcional |
| `roles` | Roles por empresa; `empresa_id` nulo = plantilla |
| `rol_permisos` | Acción concedida a un rol y su alcance |
| `usuario_roles` | Roles de cada usuario, opcionalmente limitados a una sede |
| `auditoria` | Quién cambió qué, con valores anteriores y nuevos |
| `configuracion_plataforma` | Identidad (nombre, logos, colores) y parámetros generales |

## Uso en código

```php
// En una vista
@can('accesos.crear') ... @endcan

// En un controlador, con alcance sobre un registro
$this->authorize('novedades.editar', $novedad);

// En rutas
Route::get('/accesos', ...)->middleware('can:accesos.ver');
```

## Comandos

| Comando | Qué hace |
|---|---|
| `php artisan plataforma:instalar` | Carga o actualiza el catálogo de áreas, módulos, acciones, rubros y plantillas de rol (idempotente) |
| `php artisan plataforma:superadmin correo@dominio --hash='$2y$...'` | Crea el Super Administrador conservando la contraseña de SEGCAT; sin `--hash` genera una temporal |

Decisiones: ADR-0002.
