# Usuarios

Aquí se dan de alta las cuentas para entrar a la plataforma: **Estructura → Organización Interna → Usuarios**.

![Usuarios](img/usuarios/1-lista.png)

![Usuarios con una cuenta bloqueada](img/ajustes/1-usuario-bloqueado.png)

## ¿De qué empresa son?

La plataforma atiende a varias empresas, y cada usuario pertenece a **una**. Debajo del título se ve cuál: "Usuarios de «Hotel Demo»". En el alta y en la edición, arriba de la sede, aparece **Empresa: Hotel Demo**: el usuario se crea en esa empresa y la **sede** solo limita lo que ve dentro de ella.

El Super Administrador cambia de empresa con el selector **Empresa de trabajo** de arriba.

![Alta con la empresa visible](img/ajustes2/01-alta-usuario-con-empresa.png)

## Buscar

Escribe en el buscador un nombre, usuario, correo o rol. Si la empresa tiene varias sedes, filtra también por sede. El filtro se mantiene mientras trabajas.

## Dar de alta

1. Toca **Nuevo Usuario**.
2. Captura:
   - **Núm. Colaborador:** opcional. Escribe el número o el nombre del colaborador y elígelo de la lista (ver abajo).
   - **Nombre completo**.
   - **Sede:** la sede donde trabaja, o *Todas las sedes* si debe ver todas.
   - **Rol**.
   - **Usuario**, **correo** y **contraseña:** mínimo 8 caracteres, con letras y números.
3. Toca **Registrar Usuario**. La persona ya puede entrar con su usuario o su correo.

![Alta](img/usuarios/2-alta.png)

En la lista de roles solo aparecen los que tú puedes asignar, es decir, los de nivel inferior al tuyo.

Si cierras la ventana (Cancelar, la X o la tecla Esc), al volver a abrirla aparece **vacía**. Si al registrar hubo un error (por ejemplo, un usuario repetido), la ventana se vuelve a abrir con lo que capturaste para que lo corrijas; si en vez de corregir la cierras, la próxima vez abre vacía.

| Regresa con el error | Cerrada y vuelta a abrir |
|---|---|
| ![Con error](img/ajustes2/02-alta-con-error-conserva-datos.png) | ![Limpia](img/ajustes2/03-alta-reabierta-limpia.png) |

### Vincular la cuenta con su colaborador

Si la persona ya está en **Colaboradores**, no vuelvas a teclear sus datos:

1. En **Núm. Colaborador** escribe al menos 2 letras o números (por ejemplo, "mari" o "1007").
2. Elige a la persona en la lista: se llenan su número y su **nombre completo**, y aparece "Vinculado a Colaboradores".

![Buscar colaborador](img/usuarios/4-num-colaborador.png)

![Vinculado](img/usuarios/5-vinculado-a-colaborador.png)

- Si ya tiene una cuenta, te avisa en vez de mostrarla: búscala en la lista principal.
- Solo aparecen colaboradores activos de tu empresa (y de tus sedes, si tu rol es de una sede).
- Si cambias el número a mano, el vínculo se quita. Las cuentas de soporte pueden quedarse sin colaborador.
- La ficha del usuario dice **Vinculado a Colaborador (activo)**. Si el colaborador se dio de baja, dice **(¡inactivo! revisar)**: revisa si la cuenta debe desactivarse.

## Editar

Toca el **lápiz**. Si dejas la contraseña en blanco, no cambia. Si la cambias, la persona tendrá que volver a entrar.

## Desactivar o reactivar

- El botón **⊘** desactiva la cuenta: la persona ya no puede entrar y, si estaba dentro, se le cierra la sesión.
- El botón verde **↺** la reactiva.

No se borra a nadie, para conservar el historial.

## Desbloquear una cuenta

Tras **5 intentos fallidos** seguidos, la cuenta se bloquea 15 minutos. En su ficha aparece la etiqueta **BLOQUEADO hasta HH:MM** (hora de la empresa).

![Cuenta bloqueada](img/ajustes/2-ficha-bloqueada.png)

Si la persona ya recordó su contraseña y no puede esperar:

1. Toca el **candado** de su ficha.
2. Confirma. Verás «Nombre» desbloqueado; ya puede entrar.

![Desbloqueada](img/ajustes/3-desbloqueado.png)

El candado solo aparece si tu rol tiene el permiso **Desbloquear** (por omisión: Administrador y Jefe de seguridad), la persona es de nivel inferior al tuyo y está dentro de tu alcance (por ejemplo, de tu sede). El desbloqueo queda registrado en la bitácora de auditoría.

> Si la persona olvidó su contraseña, además de desbloquearla cámbiale la contraseña con el **lápiz**.

No puedes editar tu propia cuenta ni la de alguien de tu mismo nivel o superior.

![Celular](img/usuarios/3-celular.png)
