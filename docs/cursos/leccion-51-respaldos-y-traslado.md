# Lección 51 · Respaldos y traslado a otro servidor

**Duración:** 25 minutos · **Para:** Super Administrador de la plataforma (prácticas 1 a 5) y Administrador del cliente (práctica 6).

Aprenderás:

1. Qué respalda la plataforma sola y qué no.
2. A generar, descargar, verificar y borrar el paquete de traslado.
3. A restaurar un paquete en un servidor nuevo.
4. A exportar los datos de una empresa para entregarlos al cliente.

## Antes de empezar

Usa la cuenta de **Super Administrador** del ambiente de pruebas y, para la práctica 6, la del **Administrador** de la empresa de prueba. Ten a la mano un equipo de confianza para descargar: los paquetes llevan datos personales.

## Lo que hay que saber

- La **base de datos** se respalda sola cada día y antes de cada actualización (Configuración → Respaldos).
- Las **fotos, identificaciones, documentos y firmas** no van en ese respaldo diario: van en el **paquete de traslado**, que junta la base y todos los archivos en un solo ZIP con una lista de huellas (SHA-256) para comprobar que nada cambió.
- Se conservan los **3 paquetes más recientes**. Un paquete que se queda en el servidor no protege si el servidor se pierde: **descárgalo**.

## Práctica 1 · Generar el paquete

1. **Estructura → Configuración** → tarjeta **Respaldos de la base de datos** → **Respaldos y traslado a otro servidor**.
2. **Generar paquete de traslado** → **Aceptar**.

Comprueba: la barra avanza sola y termina con «Paquete de traslado listo (…)» y el botón **Descargar ahora**. En la lista aparece con su tamaño, archivos, registros y tu nombre.

## Práctica 2 · Descargar y revisar

1. Toca **Descargar ahora** (o **Descargar** en la lista).
2. Abre el ZIP en tu equipo: revisa que traiga `LEEME.txt`, `manifest.json`, `base/base.sql.gz` y la carpeta `archivos`.
3. De regreso en la pantalla, toca **Verificar** en el paquete.

Comprueba: en **Revisiones recientes**, palomita verde «Completo: … archivos revisados, todas las huellas coinciden.» En la **Bitácora de auditoría** aparecen «Generación del paquete de traslado», «Descarga del paquete de traslado» y «Verificación de paquete».

## Práctica 3 · Un paquete dañado no pasa

1. En tu equipo, abre el ZIP descargado, reemplaza dentro cualquier foto por otra imagen y guarda el ZIP.
2. En **Verificar paquete** → **Elegir archivo** → el ZIP modificado → **Verificar paquete**.

Comprueba: cruz roja con «… no coincide con su huella: el archivo cambió o se dañó.» Un paquete así nunca se usa para restaurar.

## Práctica 4 · El automático semanal

1. En **Respaldo automático**, activa **Generar un paquete de traslado automático cada semana** → **Guardar**.
2. Lee el aviso y apágalo de nuevo si tu servidor tiene poco espacio.

Comprueba: el aviso explica que solo se genera si hay espacio suficiente y la Bitácora de auditoría registra «Cambio del paquete automático semanal».

## Práctica 5 · Restaurar en un servidor nuevo (con quien administra el servidor)

1. En el servidor nuevo, con la misma versión instalada y una base **vacía**, sube el paquete fuera de la carpeta pública.
2. En la terminal del panel del hospedaje, dentro de la carpeta de la plataforma: `php artisan plataforma:restaurar-traslado RUTA/DEL/PAQUETE.zip --solo-verificar`.
3. Después: `php artisan plataforma:restaurar-traslado RUTA/DEL/PAQUETE.zip --importar-base` y contesta **yes**.

Comprueba: «todas las huellas coinciden», «Base importada…» y «Todas las tablas tienen los mismos registros que el servidor anterior.» Entra con tu cuenta y revisa empresas, sedes, usuarios y fotos.

## Práctica 6 · Exportar los datos de una empresa (Administrador)

1. **Estructura → Empresas** → en tu empresa, la **flecha hacia abajo** (Exportar datos).
2. **Exportar datos** → **Aceptar** → **Descargar**.
3. Abre las hojas de la carpeta `hojas` con Excel.

Comprueba: el archivo trae tus sedes, colaboradores, usuarios (sin contraseñas), padrones y configuración; no aparece ninguna otra empresa. Si eres Director, no ves el botón: exportar es solo del Administrador (o de quien reciba el permiso **Exportar** de Empresas).

## Para recordar

- La base se respalda sola cada día; los archivos, con el paquete de traslado.
- Descarga los paquetes y guárdalos fuera del servidor; bórralos cuando ya no sirvan.
- Restaurar se hace una sola vez, en el servidor nuevo, desde la terminal y sobre una base vacía.
- Todo (generar, descargar, verificar, restaurar, exportar, borrar) queda en la Bitácora de auditoría.

## Autoevaluación

1. ¿Las fotos de los visitantes van en el respaldo diario? *(No; van en el paquete de traslado)*
2. ¿Cuántos paquetes se conservan en el servidor? *(Los 3 más recientes; y 3 exportaciones por empresa)*
3. ¿Qué pasa si intento importar la base sobre una base con información? *(La orden se niega: solo importa sobre una base vacía)*
4. ¿Cuánto tiempo vale un enlace de descarga? *(30 minutos; si vence, se recarga la página)*
5. ¿La exportación de una empresa trae contraseñas? *(No: ni contraseñas, ni sesiones, ni enlaces del kiosco)*
