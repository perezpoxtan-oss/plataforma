# Lección 3 — Alta y control de usuarios

**Para:** Administrador del cliente · **Duración:** 15 minutos

## Práctica (en QA, como `admin.demo`)

1. Da de alta a "Laura Gómez", con sede *Hotel Demo Playa*, rol *Agente*, usuario `laura.gomez` y una contraseña de 8 caracteres con números.
2. Cierra sesión y entra como `laura.gomez`. Verifica que ve Operación pero no Estructura.
3. Vuelve como `admin.demo`, cámbiale el rol a *Supervisor* y comprueba qué ve ahora.
4. Da de baja a Laura (círculo rojo, **Dar de baja**) e intenta entrar con su cuenta: no debe poder.
5. Reactívala con la flecha circular (**Reactivar**).

## Para recordar

- Usa *Todas las sedes* solo para quien realmente supervisa todo.
- Da de baja en lugar de borrar: así el historial queda intacto.
- Cambiar la contraseña de alguien cierra su sesión abierta.

## Autoevaluación

1. ¿Por qué no aparece "Administrador" en la lista de roles al dar de alta? *(Solo puedes asignar roles de nivel inferior al tuyo)*
2. ¿Qué pasa con la sesión de alguien a quien das de baja? *(Se cierra de inmediato)*
