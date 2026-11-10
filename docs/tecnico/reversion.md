# Cómo regresar una entrega (reversión)

Guía breve para el dueño del proyecto. No necesita datos del servidor: todo se hace desde GitHub, desde la plataforma y, si hace falta, con el proveedor de hospedaje.

## Qué pasa solo, sin que nadie intervenga

- Cada entrega aprobada (PR integrado en `develop` con las pruebas en verde) **se instala sola en QA** en los minutos siguientes.
- El servidor **conserva las 3 versiones más recientes** instaladas, para poder regresar a cualquiera de ellas.
- Antes de actualizar la base de datos, el servidor **hace un respaldo** de la base (aparece en **Configuración → Respaldos** con el motivo «antes-de-actualizar»).
- Si al instalar una versión **el sitio no responde**, el servidor **regresa solo a la versión anterior**, marca la nueva como fallida y no la vuelve a intentar hasta que llegue otra.
- Si falla la actualización de la base (una migración), **se queda la versión anterior** funcionando y el sitio sale del modo de mantenimiento.
- Producción nunca cambia sola: solo instala la versión escrita en el **archivo de versión** autorizada.

## Procedimiento manual

### a) Regresar el código (QA)

1. En GitHub, abre el **PR ya integrado** que quieres deshacer.
2. Toca el botón **Revert**. GitHub crea un PR de reversión.
3. Aprueba e integra ese PR de reversión en `develop`.
4. QA se actualiza solo con la versión revertida (mismo camino que cualquier entrega: pruebas en verde → paquete → instalación).

### b) Regresar la base de datos (solo si hace falta)

La mayoría de las reversiones **no** necesitan tocar la base (ver «Qué no se deshace»). Si sí hace falta:

1. Entra a la plataforma como Super Administrador: **Estructura → Configuración → Respaldos de la base de datos**.
2. Descarga el respaldo con motivo **antes-de-actualizar** de la fecha de la entrega que vas a deshacer (o el diario anterior).
3. Pide a tu **proveedor de hospedaje** (o a quien administre la base) que **restaure ese archivo** sobre la base de datos de ese ambiente.
4. Lo capturado entre la instalación y la restauración **se pierde**: avisa antes a los usuarios y haz la restauración en una hora de poco movimiento.

### c) Regresar Producción

1. Decide a qué versión regresar (una `vMAYOR.MENOR.PARCHE` anterior que ya estuvo en Producción).
2. Cambia la **versión autorizada** en el archivo de versión del servidor a esa versión anterior (si antes se escribió su huella, escríbela también).
3. En la siguiente revisión automática el servidor instala esa versión. El sitio entra unos segundos en mantenimiento y vuelve.
4. Si además hay que regresar la base, sigue el apartado b) con el respaldo de Producción.

## Qué NO se deshace con un «Revert»

Un revert regresa el **código**; la base de datos no se desmigra sola. Las migraciones están pensadas para que la versión anterior del código funcione con la base nueva:

- Las **columnas y tablas nuevas** se quedan en la base, vacías o sin usarse. No hacen daño: la versión anterior simplemente no las lee.
- Los **permisos o acciones nuevas** del catálogo se quedan creados; la versión anterior no los muestra.
- Los **datos ya capturados** con la versión nueva (por ejemplo, una etapa nueva de un candidato) se conservan tal cual; si la versión anterior no conoce ese valor, la pantalla lo muestra como texto o lo trata como «otro».
- Lo que una migración **convirtió** (por ejemplo, etapas renombradas) solo regresa con el `down()` de esa migración o restaurando el respaldo.

Por eso cada migración trae su `down()` probado y la integración continua ejecuta **migrar → revertir → migrar** en cada PR: garantiza que, si hiciera falta, la base se puede regresar con `migrate:rollback` sin romper nada.

## Qué debe traer cada PR

Toda descripción de PR lleva un apartado **«## Cómo revertir»** escrito por quien lo abre, con:

1. Si basta con el botón **Revert** o si además hay que regresar la base.
2. Qué migraciones agrega y qué deja en la base si solo se revierte el código.
3. Qué datos capturados con la versión nueva se conservan y cómo los ve la versión anterior.
4. Cualquier paso manual (por ejemplo, volver a apagar un aviso en Configuración).
