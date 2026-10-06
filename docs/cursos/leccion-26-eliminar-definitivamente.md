# Lección 26 — Eliminar definitivamente

**Para:** Administrador del cliente · **Duración:** 15 minutos

## Qué vas a aprender

- La diferencia entre **dar de baja** y **eliminar definitivamente**.
- Borrar un registro capturado por error, confirmando con su nombre.
- Qué hacer cuando la plataforma dice que el registro ya se usa.
- Dónde queda la copia de lo que borraste.

## Práctica (en QA)

1. **`admin.demo`**: abre Recursos Humanos → **Departamentos**. Toca el lápiz de **Compras (duplicado)**. Al pie de la ventana toca **Eliminar definitivamente**.
2. Lee el aviso rojo. Escribe `compras` y fíjate que **Eliminar** sigue apagado. Completa `compras (duplicado)` (en minúsculas): se enciende. **No lo toques todavía**; toca **Cancelar**.
3. Cierra y abre el lápiz de **Seguridad**. Toca **Eliminar definitivamente**: dice que tiene colaboradores y llaves, y ofrece **Dar de baja**. Toca **Cerrar** (no lo des de baja).
4. Regresa a **Compras (duplicado)**, repite el paso 2 y ahora sí toca **Eliminar**. Lee el aviso verde.
5. Abre Padrones → **Gafetes**. Debajo de los filtros abre **Eliminar tipos de gafete sin usar**, toca el bote de **VIP (prueba)**, escribe `VIP (prueba)` y elimina. La píldora desaparece.
6. Abre Padrones → **Catálogo de llaves**, edita **HDC-BOD-01** y toca **Eliminar definitivamente**: tiene préstamos; la ventana te manda a «Dar de baja» de su ficha.
7. Abre Padrones → **Rutas de transporte** → **Hotel Demo Centro** → pestaña **Paraderos**, edita **PARADERO DE PRUEBA** y elimínalo (escribe `paradero de prueba`). Prueba también con un paradero que ya usa una ruta: dice cuántas paradas lo usan.
8. Abre Estructura → **Bitácora de auditoría**, filtra el módulo **Departamentos** y abre el renglón «Eliminado definitivo»: ahí está la copia de todos sus datos.
9. Entra como **`agente.demo`** y abre **Gafetes**: no hay botón ni lista para eliminar.

## Para recordar

- **Dar de baja** = esconder y conservar el historial (reversible). **Eliminar definitivamente** = borrar (no se deshace).
- Solo se borra lo que **nadie usa**. Si algo lo usa, la plataforma te dice qué y te ofrece la baja.
- Bitácoras y evidencias (accesos, novedades, préstamos, responsivas, pases, transporte, vouchers, auditoría) **nunca** se eliminan.
- Siempre queda una copia en la **Bitácora de auditoría**.
- Por omisión solo lo puede hacer el **Administrador**.

## Autoevaluación

1. Capturé dos veces el mismo vehículo y uno ya entró por la caseta. ¿Cuál puedo eliminar? *(El que no tiene registros en la bitácora de accesos; el otro solo se da de baja)*
2. ¿Puedo eliminar un pase de salida mal hecho? *(No: es evidencia; se rechaza o anula)*
3. ¿Por qué no se puede eliminar la sede «Hotel Demo Centro»? *(Tiene accesos, llaves, colaboradores… y si fuera la única sede tampoco)*
4. Eliminé un puesto por error. ¿Cómo lo recupero? *(En la Bitácora de auditoría están sus datos para capturarlo de nuevo)*
5. Mi Jefe de seguridad quiere borrar llaves mal capturadas. ¿Qué hago? *(Darle en la Matriz de permisos «Eliminar definitivamente» en Catálogo de llaves; solo podrá en sus sedes)*
