# Lección 52 · Verificación en dos pasos

**Duración:** 25 minutos · **Para:** todas las personas (prácticas 1 a 4), Administrador del cliente (prácticas 5 y 6) y Super Administrador (prácticas 7 y 8).

Aprenderás:

1. A activar la verificación en dos pasos con tu teléfono y a guardar tus códigos de respaldo.
2. A entrar con el código y a usar «Recordar este dispositivo».
3. Qué hacer si pierdes o cambias tu teléfono (también siendo Super Administrador, sin terminal).
4. A exigirla en tu empresa y a restablecerla a quien perdió su teléfono.

## Antes de empezar

Usa una cuenta del ambiente de pruebas y ten a la mano un teléfono con **Google Authenticator** o **Microsoft Authenticator** (gratis en la tienda del teléfono). Revisa que la fecha y hora del teléfono sean **automáticas**.

## Lo que hay que saber

- Con la verificación en dos pasos, entrar pide **dos cosas**: tu contraseña (lo que sabes) y un código de 6 números de tu teléfono (lo que tienes). El código cambia cada 30 segundos y sirve una sola vez.
- Los **10 códigos de respaldo** sirven para entrar sin el teléfono; cada uno una sola vez. **Se muestran una sola vez.**
- El **Super Administrador** siempre la necesita. Cada empresa decide si la exige a nadie, a los administradores o a todos.

## Práctica 1 · Activarla

1. Abre **Mi cuenta** (tu nombre, arriba a la derecha) → **Verificación en dos pasos** → **Activar la verificación en dos pasos**.
2. En la app del teléfono: **+** → **Escanear código QR** → apunta al código.
3. Escribe el código de 6 números en **Código de la app** → **Guardar**.
4. Toca **Imprimir** (o copia los 10 códigos) → **Ya los guardé: volver al inicio**.

Comprueba: en **Mi cuenta** la tarjeta dice **Activa** y «Te quedan 10 código(s) de respaldo». En tu campana llegó «Activaste la verificación en dos pasos».

## Práctica 2 · Entrar con el código

1. Sal de la plataforma y vuelve a entrar con tu usuario y contraseña.
2. En **Verificación en dos pasos** escribe el código que ves en la app → **Entrar**.
3. Sal otra vez y vuelve a entrar; ahora escribe un código **equivocado**.

Comprueba: con el código correcto llegas a Inicio. Con el equivocado: «El código no es correcto…». Después de 5 equivocados tendrías que esperar 15 minutos.

## Práctica 3 · Recordar este dispositivo

1. Entra marcando **Recordar este dispositivo 30 días** (solo en tu equipo personal).
2. Sal y vuelve a entrar en el mismo navegador.

Comprueba: esta vez solo te pidió la contraseña. Si cambias tu contraseña, te volverá a pedir el código.

## Práctica 4 · Sin teléfono: código de respaldo

1. Sal y entra; en **Código** escribe uno de tus códigos de respaldo (por ejemplo `ABCD-EF23`).
2. En **Mi cuenta → Códigos de respaldo nuevos**, escribe tu contraseña → **Restablecer códigos de respaldo**.

Comprueba: al entrar te avisó «Entraste con un código de respaldo… Te quedan 9». Los códigos nuevos sustituyen a los anteriores (el que ya usaste y los demás dejan de servir).

## Práctica 5 · Exigirla en tu empresa (Administrador)

1. **Estructura → Configuración → Cuentas y seguridad → Verificación en dos pasos**.
2. Elige **Administradores** → **Guardar**.
3. Con una cuenta de administrador que no la tenga, sal y vuelve a entrar.

Comprueba: después de la contraseña la plataforma te lleva a activarla y no deja abrir otras pantallas hasta hacerlo. Regresa el ajuste con **Restablecer valor original** (Nadie).

## Práctica 6 · Restablecerla a quien perdió su teléfono (Administrador)

1. **Estructura → Usuarios** → busca la cuenta (dice «Verificación en dos pasos activa»).
2. Toca el **teléfono** (Restablecer verificación en dos pasos) → **Aceptar**.

Comprueba: «Listo: quitamos la verificación en dos pasos de …». La persona recibe un aviso; entra solo con su contraseña y la activa con su teléfono nuevo. En la **Bitácora de auditoría** aparece «Verificación en dos pasos restablecida». Hazlo solo después de confirmar que es la persona quien lo pide.

## Práctica 7 · Super Administrador

1. Entra con la cuenta de Super Administrador sin la verificación activa.

Comprueba: te lleva a activarla antes de cualquier otra pantalla y, ya activa, no te deja quitarla.

## Práctica 8 · «Perdí mi teléfono» sin terminal (todas las personas y Super Administrador)

1. Con una cuenta que tenga la verificación activa, escribe usuario y contraseña. En la pantalla del código toca **¿No tienes tu teléfono?** → **Perdí mi teléfono: pedir ayuda**.
2. Entra como el **Administrador**: la petición está en la campana, en **Mis pendientes → Cuentas por revisar** y arriba de **Usuarios** («Piden ayuda con su cuenta»). Toca el **teléfono** de su ficha.
3. Como **Super Administrador**: en **Configuración → Plataforma → Super Administradores** da de alta a un segundo Super Administrador (Inicio avisa en rojo mientras haya uno solo). Repite el paso 1 con la cuenta del segundo y, desde la tuya, toca **Restablecer o dar de baja → Quitar sus dos pasos** escribiendo tu contraseña.
4. Intenta **Dar de baja** al segundo Super Administrador.

Comprueba: la petición desaparece sola al restablecer; la persona entra con su contraseña y activa su teléfono nuevo. En el paso 4 la tarjeta dice «No se puede: quedaría menos de dos Super Administradores activos». Nada de esto necesita entrar al servidor.

## Repaso

- ¿Qué pasa si escribes un código que ya usaste? *No sirve: cada código vale una sola vez.*
- ¿Dónde marcas «Recordar este dispositivo»? *Solo en tu equipo personal, nunca en la caseta.*
- ¿Quién puede restablecer la verificación de otra persona? *Quien puede desbloquear usuarios, con su mismo alcance.*
- ¿Y la de un Super Administrador? *Otro Super Administrador, desde Configuración → Plataforma; por eso debe haber al menos dos.*
- ¿Una cuenta de caseta sin correo cómo recupera su contraseña? *Con «Olvidé mi contraseña → Pedir a mi administrador»; el administrador le da una temporal y la persona la cambia al entrar.*
