# Empresas y Sedes

Réplica de `modules/empresas/empresa_lista.php` y `modules/hoteles/hotel_lista.php` de SEGCAT.

## Empresas (`/empresas`)

| Acción | Quién |
|---|---|
| Ver | `empresas.ver`. El Super Administrador ve todas; un usuario de empresa ve solo la suya ("Mi Empresa") |
| Dar de alta | Solo el Super Administrador: usa `ProvisionarEmpresa`, que activa los módulos y copia los roles base |
| Editar | `empresas.editar`: nombre comercial, razón social, RFC y zona horaria. El rubro solo lo cambia el Super Administrador |
| Desactivar o reactivar | Solo el Super Administrador; al desactivar, sus usuarios quedan fuera en la siguiente petición |

- **RFC:** obligatorio, se guarda en mayúsculas y es único en la plataforma. Formato: 12 caracteres para persona moral y 13 para persona física.
- **Zona horaria:** se elige de una lista con México primero (`App\Support\ZonasHorarias`) y se valida con `timezone:all`.

## Sedes (`/sedes`)

El nombre de la pantalla sale de la terminología del rubro. Por decisión del cliente (QA, 2026-10-04) en todos los rubros se llama **Sedes**; el resto de la terminología (Habitación, Oficina, Departamento, Casa; Huésped, Visita…) sí cambia por rubro.

- **Campos de SEGCAT:** nombre, código, ciudad, estado (`entidad`), calle y número, colonia, código postal y teléfono. Se agrega la **zona horaria propia**, que es opcional: vacía significa la de la empresa (`Sede::zonaHoraria()`).
- **Código:** solo letras, números y guiones, en mayúsculas. Es **único por empresa**; en SEGCAT `codigo_hotel` era único en toda la plataforma.
- **Permisos y alcance:** `sedes.ver`, `sedes.crear`, `sedes.editar` y `sedes.eliminar` (este último desactiva o reactiva). Quien tiene alcance de sede solo ve las sedes asignadas.
- **Super Administrador:** trabaja con la empresa elegida en "Empresa de trabajo".
- **Sin borrado:** las sedes se desactivan para conservar el historial.
- **Auditoría:** `empresas.*` y `sedes.*`, con antes y después.

## Componentes compartidos nuevos

- **Filtro genérico de fichas** en `public/js/plataforma.js`: contenedor `data-fichas="clave"`, buscador `data-filtro-texto` y botones `data-filtro-estado`. El filtro se recuerda durante la sesión del navegador. Un enlace `#sede-12` resalta esa ficha.
- **Temas de color** `tema-azul` (Empresas) y `tema-verde` (Sedes), como en SEGCAT.
- Migración `2026_10_04_000600`: campos de dirección en `sedes` y RFC único en `empresas`.

## Pruebas

`tests/Feature/Administracion/EmpresasYSedesTest.php`

## Logo de la empresa

- Campo `logo` en alta y edición (`EmpresaController::guardarLogo`). Acepta PNG, JPG o WEBP de hasta 512 KB y 2048 × 2048 píxeles; **SVG no**, porque puede llevar código. Con `quitar_logo=1` se quita.
- Se guarda en el disco `public` (`storage/app/public/empresas/logos/`). La columna `empresas.logo_ruta` guarda `storage/empresas/logos/<archivo>` y el archivo anterior se borra.
- Lo usan la ficha de la empresa y las impresiones (gafetes). Queda en la auditoría como `empresas.logo_actualizado`.
- SEGCAT lo guardaba en `uploads/logos/` con el nombre `logo_<id>_<fecha>`. Ahora el nombre es aleatorio y el archivo queda fuera del código.
