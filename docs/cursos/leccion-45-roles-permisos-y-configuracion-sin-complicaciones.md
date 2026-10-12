# Lección 45 · Roles, permisos y configuración sin complicaciones

**Duración:** 35 minutos · **Para:** Administradores, Jefes de seguridad, Recursos Humanos y Super Administrador.

Aprenderás:

1. A crear un rol **debajo o arriba de otro** sin escribir números, y **a partir de otro rol**.
2. A dar permisos con **¿Qué hace este rol?** y a ver **lo que verá** una persona con ese rol.
3. A usar la **Configuración por módulo** y su interruptor de avisos.
4. A leer **Inicio** y **Mis pendientes** (mismos renglones, sin ceros).
5. A elegir **tus avisos** en Mi cuenta.
6. A ver el **historial** de un registro y qué ve «Su sede» en la bitácora.
7. A dar **varias sedes** a una cuenta y a elegir los **módulos** de una empresa.

## Antes de empezar

Usa la empresa de práctica con `admin.demo` (Administrador), `director.demo`, `jefe.demo` (Jefe de seguridad), `rh.demo` (Recursos Humanos), `supervisor.demo`, `agente.demo` y, para la práctica 7, una cuenta de Super Administrador.

## Práctica 1 · Un rol nuevo sin números (admin.demo)

1. **Estructura → Roles → Nuevo Rol**. Nombre «Coordinador de turno».
2. En **¿Dónde va en la jerarquía?** elige **Debajo de** «Jefe de seguridad». Lee la línea de abajo: «Quedará debajo de «Jefe de seguridad» y arriba de «Asistente» (nivel 35)».
3. En **Permisos iniciales** elige **Los mismos que «Supervisor»** y toca **Crear Rol**.

Comprueba: se abre la Matriz de ese rol con el mensaje «…creado con los permisos de «Supervisor»…» y **Lo que verá** muestra Operación, Padrones y Recursos Humanos.

## Práctica 2 · ¿Qué hace este rol? (admin.demo)

1. En la Matriz, con «Coordinador de turno» elegido, en **¿Qué hace este rol?** marca **Agente** y toca **Guardar** → **Aceptar**.
2. Revisa **Lo que verá**: ya no aparecen las pantallas que solo tenía el Supervisor.
3. Abre **Avanzado: permiso por permiso**, quita **Ver** de *Préstamo de llaves* y mira cómo **Lo que verá** cambia antes de guardar. No guardes.
4. Busca *Tablero* en el área Seguridad: dice **Próximamente** y no se puede marcar.
5. Abre el rol **Jefe de departamento**: en *Pases de salida* dice «Distinto por acción: Ver: Su sede · Crear: Solo los propios».

## Práctica 3 · Configuración por módulo (admin.demo)

1. **Estructura → Configuración**. Arriba lee la **Versión instalada** y toca el botón **Pases de salida**.
2. Apaga **Avisos de este módulo** y toca **Guardar avisos**: «Avisos actualizados. Apagados por módulo: Pases de salida.»
3. Vuelve a encenderlo y guarda.
4. En **Cuentas y seguridad** revisa **Bitácora de auditoría: qué ve «Su sede»** (déjalo en «Solo sus propios movimientos»).

## Práctica 4 · Inicio y Mis pendientes (rh.demo)

1. Entra con `rh.demo`. **Inicio** muestra «Colaboradores por verificar» y «Candidatos» con su desglose por etapa.
2. Abre **Mis pendientes**: los mismos renglones, en el mismo orden; los que están en cero no aparecen.

## Práctica 5 · Mis avisos (jefe.demo)

1. **Mi cuenta → Mis avisos**. Quita la marca de «Un pase de salida venció y no ha regresado» y toca **Guardar**.
2. Lee el mensaje: lo que espera tu respuesta sigue en **Mis pendientes**.
3. Vuelve a marcarla y guarda.

## Práctica 6 · Historial y avisos sensibles (admin.demo y director.demo)

1. En **Roles**, toca el **reloj** de «Coordinador de turno»: la bitácora muestra solo sus movimientos.
2. En **Configuración → Cuentas y seguridad** marca el aviso de **movimientos sensibles** y guarda.
3. Cambia un permiso de «Coordinador de turno». Entra con `director.demo`: su campana dice «Movimiento sensible: Matriz de permisos · Cambio de permisos».
4. Regresa con `admin.demo` y apaga ese aviso.

## Práctica 7 · Varias sedes y módulos (admin.demo y Super Administrador)

1. Con `admin.demo`, en **Usuarios** edita a `supervisor.demo`: en **¿También atiende otras sedes?** marca la otra sede y guarda. Su ficha dice «Hotel … + Hotel …».
2. Con el Super Administrador, en **Empresas** toca la **cuadrícula** de la empresa de práctica: quita *Vacantes* y guarda. Con `rh.demo` ya no aparece en el menú. Vuelve a activarlo.

## Repaso

- Los roles se acomodan por posición; el número queda en Avanzado.
- La matriz completa es «Avanzado»: lo normal es elegir un perfil y revisar **Lo que verá**.
- Por omisión nada cambia: los interruptores empiezan encendidos, «Su sede» ve lo propio y los avisos sensibles están apagados.
