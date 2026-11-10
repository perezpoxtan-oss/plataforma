# Lección 13 — Padrón Vehicular y calcomanía QR

**Para:** Jefe de seguridad, Supervisor, Administrador y Agentes · **Duración:** 15 minutos

## Práctica (en QA)

1. **`admin.demo`:** abre Padrones → Padrón vehicular.
   - Toca la píldora **De una empresa**, luego **Particulares** y luego **Todos**.
   - Busca `uzx 902` (con espacio): aparece UZX902A.
2. Registra un vehículo **Particular** con placas `qrt-55-12`, marca Kia, color gris. Revisa que se guardó como `QRT5512`.
3. Intenta registrar otra vez `QRT 55-12`: el sistema te dice que ya existe y de qué auto se trata.
4. Registra uno **De una empresa** sin elegir empresa ni número económico: el sistema pide los dos. Elige una empresa propietaria, escribe el número económico `TP-20`, tipo **Autobús** y capacidad 20.
5. Cambia el tipo a **Otro** y fíjate que pide describirlo.
6. Imprime la calcomanía de tu vehículo (botón QR) y escanea el código con el celular: abre la ficha.
7. Da de baja el vehículo y reactívalo.
8. **`agente.demo`:** abre el padrón: puede buscar, pero no registrar ni imprimir.

## Para recordar

- Las placas se escriben como sea: el sistema las guarda sin espacios ni guiones y no permite repetirlas en la empresa.
- Solo hay dos categorías. **Particular** (colaborador, huésped, visitante, familiar) no pide empresa y deja elegir, si quieres, al colaborador dueño. **De una empresa** (flotilla, agencia, taxi, transporte) pide empresa propietaria y número económico.
- El QR se genera dentro de la plataforma y solo lleva un código aleatorio: no expone datos y no depende de internet.
- Dar de baja no borra: las bitácoras siguen ligadas al vehículo.

## Autoevaluación

1. ¿Puedo registrar `ABC-123` si ya existe `ABC123`? *(No, son las mismas placas)*
2. ¿En qué categoría son obligatorios la empresa propietaria y el número económico? *(De una empresa)*
5. Un taxi de la caseta, ¿es Particular o De una empresa? *(De una empresa)*
3. ¿Qué pasa si escanean la calcomanía con una cuenta de otra empresa? *(No encuentra el vehículo: solo funciona en tu empresa)*
4. ¿Qué hace un Agente en el padrón? *(Consultar y buscar)*
