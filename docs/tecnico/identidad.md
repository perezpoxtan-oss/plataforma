# Identidad de la plataforma

Pantalla `GET|PUT /identidad` (módulo `identidad`, permisos `identidad.ver` e `identidad.editar`).

## Módulo de tipo plataforma

`identidad` es el primer módulo con `tipo = plataforma` (`Modulo::TIPO_PLATAFORMA`):

- No se activa en las empresas (`ProvisionarEmpresa`) ni se copia a las plantillas de rol (`RolesPlantillaSeeder`).
- No aparece en la Matriz de permisos.
- Por eso solo el Super Administrador lo ve y lo usa; el motor de permisos no necesita ninguna regla especial.

El menú lo muestra en **Estructura → Plataforma**.

## Qué se configura

| Campo | Dónde se ve |
|---|---|
| Nombre | Título del acceso |
| Nombre corto | Barra superior, cabecera del celular, pestaña |
| Eslogan | Debajo del nombre en el acceso |
| Titular | Pie: "© año titular" |
| Color principal / acento | Botones, menú activo, enlaces, barra del celular (`--color-primario`, `--color-acento`) |
| Símbolo | Barra, acceso y vista previa |
| Ícono de pestaña | `<link rel="icon">` |
| Correo y teléfono de soporte | Reservados para avisos y ayuda |

Los valores viven en `configuracion_plataforma` (clave `identidad`) y se leen con `App\Support\Identidad`.

## Seguridad

- Los colores solo se aceptan como `#RRGGBB`, porque se insertan en CSS. También se validan al leerlos y en la vista previa.
- Las imágenes se aceptan en PNG, JPG o WEBP. SVG está prohibido porque puede llevar código. Tamaño máximo: 512 KB el símbolo y 256 KB el ícono, con máximo 2048 px por lado.
- Los archivos van al disco `public` en `identidad/` con nombre aleatorio. Al reemplazar o quitar una imagen, se borra la anterior.
- Cada cambio queda en `auditoria` con el evento `identidad.actualizada`, con el antes y el después.

## Vista previa

La columna derecha refleja en vivo el nombre, los colores y el símbolo elegido antes de guardar (`public/js/plataforma.js`, sección Identidad).

## Pruebas

`tests/Feature/Administracion/IdentidadTest.php`
