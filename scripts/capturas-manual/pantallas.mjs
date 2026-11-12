/*
 * Una entrada por captura del Manual de usuario (docs/usuario/img/<archivo>).
 *   archivo  ruta dentro de docs/usuario/img (mismo nombre que usa el .md)
 *   cuenta   clave de CUENTAS en capturas.mjs (sin cuenta: pantalla pública)
 *   url      pantalla que se abre
 *   vista    escritorio (1366×860, por omisión) | alto (1366×900) | laptop (1280×900)
 *            | celular (390×844 al doble de resolución) | celular1x | {width, height}
 *   modo     normal (por omisión) | sol | noche
 *   antes    async (h) => { … }  pasos antes de la captura (ver ayudas() en capturas.mjs)
 *   recorte  pantalla (por omisión) | completa | selector CSS del elemento a recortar
 * Las capturas que guardan algo en la base van al final de su grupo; el
 * proceso completo se corre sobre una base recién preparada (ver docs/tecnico/capturas-manual.md).
 */

const D = (id) => `#${id}`; // diálogo
const F = (id) => `#${id} form`; // formulario de un diálogo

// Error dentro de la ventana: se envía el formulario sin la validación del navegador
const errorEnDialogo = (url, dialogo, llenar) => async (h) => {
    await h.dialogo(dialogo);
    if (llenar) { await llenar(h); }
    await h.enviarSinValidar(F(dialogo));
};

const colaboradores = [
    { archivo: 'colaboradores/1-lista.png', cuenta: 'admin', url: '/colaboradores' },
    { archivo: 'colaboradores/11-rh-por-validar.png', cuenta: 'rh', url: '/colaboradores', vista: { width: 1366, height: 760 },
        antes: async (h) => { await h.elegir('[data-filtro-colab="registro"]', 'provisional'); } },
    { archivo: 'colaboradores/12-validar.png', cuenta: 'admin', url: '/colaboradores',
        antes: async (h) => { await h.dialogoDe('dialogoValidarColaborador', 'Beto Hernandez'); } },
    { archivo: 'colaboradores/13-es-duplicado.png', cuenta: 'admin', url: '/colaboradores',
        antes: async (h) => { await h.dialogoDe('dialogoFusionarColaborador', 'Beto Hernandez'); } },
    { archivo: 'colaboradores/14-numero-y-nombre.png', cuenta: 'admin', url: '/colaboradores',
        antes: async (h) => {
            await h.dialogo('dialogoNuevoColaborador');
            await h.escribir('#dialogoNuevoColaborador [name=num_empleado]', '1001');
            await h.llenar('#dialogoNuevoColaborador [name=nombre]', 'Beto');
            await h.escribir('#dialogoNuevoColaborador [name=apellido_paterno]', 'Hernandez');
            await h.llenar('#dialogoNuevoColaborador [name=telefono]', '998 123 4567');
            await h.esperar(900);
        } },
    { archivo: 'colaboradores/15-error-en-dialogo.png', cuenta: 'admin', url: '/colaboradores',
        antes: errorEnDialogo('/colaboradores', 'dialogoNuevoColaborador', async (h) => {
            await h.llenar('#dialogoNuevoColaborador [name=num_empleado]', '2046');
            await h.llenar('#dialogoNuevoColaborador [name=nombre]', 'Beto');
            await h.llenar('#dialogoNuevoColaborador [name=apellido_paterno]', 'Hernandez');
            await h.llenar('#dialogoNuevoColaborador [name=telefono]', '123');
            await h.llenar('#dialogoNuevoColaborador [name=curp]', 'ABCD123456HDFXYZ01');
        }) },
    { archivo: 'colaboradores/16-ficha-pestanas.png', cuenta: 'admin', url: '/colaboradores/7/expediente', vista: { width: 1280, height: 852 } },
    { archivo: 'colaboradores/17-ficha-celular.png', cuenta: 'admin', url: '/colaboradores/7/expediente', vista: { width: 390, height: 816 },
        antes: async (h) => { await h.texto('Historial'); } },
    { archivo: 'colaboradores/2-alta.png', cuenta: 'admin', url: '/colaboradores',
        antes: async (h) => {
            await h.dialogo('dialogoNuevoColaborador');
            await h.escribir('#dialogoNuevoColaborador [name=num_empleado]', '2045');
            await h.llenar('#dialogoNuevoColaborador [name=nombre]', 'Juan');
            await h.llenar('#dialogoNuevoColaborador [name=apellido_paterno]', 'Pérez');
            await h.llenar('#dialogoNuevoColaborador [name=apellido_materno]', 'López');
            await h.llenar('#dialogoNuevoColaborador [name=telefono]', '998 123 4567');
            await h.esperar(600);
        } },
    { archivo: 'colaboradores/3-editar.png', cuenta: 'admin', url: '/colaboradores',
        antes: async (h) => { await h.dialogoDe('dialogoEditarColaborador', 'Ramón Ek Balam'); } },
    { archivo: 'colaboradores/4-sedes-adicionales.png', cuenta: 'admin', url: '/colaboradores',
        antes: async (h) => { await h.dialogoDe('dialogoSedesColaborador', 'Ramón Ek Balam'); } },
    { archivo: 'colaboradores/5-celular.png', cuenta: 'admin', url: '/colaboradores', vista: 'celular' },
    { archivo: 'colaboradores/6-celular-alta.png', cuenta: 'admin', url: '/colaboradores', vista: 'celular',
        antes: async (h) => { await h.dialogo('dialogoNuevoColaborador'); } },
    { archivo: 'colaboradores/7-noche.png', cuenta: 'admin', url: '/colaboradores', modo: 'noche' },
    { archivo: 'colaboradores/9-caseta-alta-provisional.png', cuenta: 'agente', url: '/accesos', vista: { width: 1366, height: 860 },
        antes: async (h) => { await h.dialogo('dialogoRegistroRapidoColaborador'); }, recorte: D('dialogoRegistroRapidoColaborador') },
];

const departamentos = [
    { archivo: 'departamentos/1-departamentos.png', cuenta: 'admin', url: '/departamentos' },
    { archivo: 'departamentos/2-sedes-donde-aplica.png', cuenta: 'admin', url: '/departamentos',
        antes: async (h) => {
            await h.dialogo('dialogoNuevoDepartamento');
            await h.escribir('#dialogoNuevoDepartamento [name=nombre]', 'Spa y Bienestar');
            await h.p.locator('#dialogoNuevoDepartamento [name=todas_las_sedes][type=checkbox]').uncheck({ force: true });
            await h.p.locator('#dialogoNuevoDepartamento [name="sedes[]"]').nth(1).check({ force: true });
        } },
    { archivo: 'departamentos/3-puestos.png', cuenta: 'admin', url: '/puestos' },
    { archivo: 'departamentos/4-editar-puesto.png', cuenta: 'admin', url: '/puestos',
        antes: async (h) => { await h.dialogoDe('dialogoEditarPuesto', 'Auxiliar Administrativo'); } },
    { archivo: 'departamentos/5-celular.png', cuenta: 'admin', url: '/departamentos', vista: 'celular' },
    { archivo: 'departamentos/6-parecido.png', cuenta: 'admin', url: '/departamentos',
        antes: async (h) => {
            await h.dialogo('dialogoNuevoDepartamento');
            await h.escribir('#dialogoNuevoDepartamento [name=nombre]', 'Recepcion');
            await h.p.locator('#dialogoNuevoDepartamento [name=todas_las_sedes][type=checkbox]').uncheck({ force: true });
            await h.p.locator('#dialogoNuevoDepartamento [name="sedes[]"]').nth(1).check({ force: true });
            await h.esperar(800);
        } },
    { archivo: 'departamentos/7-error-en-dialogo.png', cuenta: 'admin', url: '/departamentos',
        antes: errorEnDialogo('/departamentos', 'dialogoNuevoDepartamento', async (h) => {
            await h.llenar('#dialogoNuevoDepartamento [name=nombre]', 'Spa y Bienestar');
            await h.p.locator('#dialogoNuevoDepartamento [name=todas_las_sedes][type=checkbox]').uncheck({ force: true });
        }) },
    { archivo: 'departamentos/8-sin-responsable.png', cuenta: 'rh', url: '/departamentos', vista: 'alto' },
];

const turnos = [
    { archivo: 'turnos/1-turnos.png', cuenta: 'admin', url: '/turnos' },
    { archivo: 'turnos/2-alta-turno.png', cuenta: 'admin', url: '/turnos',
        antes: async (h) => {
            await h.dialogo('dialogoNuevoTurno');
            await h.escribir('#dialogoNuevoTurno [name=nombre]', 'Nocturno Fin de Semana');
            await h.llenar('#dialogoNuevoTurno [name=hora_inicio]', '22:00');
            await h.llenar('#dialogoNuevoTurno [name=hora_fin]', '06:00');
            await h.esperar(800);
        } },
    { archivo: 'turnos/3-sedes-que-usan.png', cuenta: 'admin', url: '/turnos', antes: async (h) => { await h.dialogo('dialogoSedesTurno1'); } },
    { archivo: 'turnos/4-celular.png', cuenta: 'admin', url: '/turnos', vista: 'celular' },
    { archivo: 'turnos/5-modo-noche.png', cuenta: 'admin', url: '/turnos', modo: 'noche' },
    { archivo: 'turnos/6-modo-sol.png', cuenta: 'admin', url: '/turnos', modo: 'sol' },
    { archivo: 'turnos/7-ya-existe.png', cuenta: 'admin', url: '/turnos',
        antes: async (h) => {
            await h.dialogo('dialogoNuevoTurno');
            await h.escribir('#dialogoNuevoTurno [name=nombre]', 'Matutino');
            await h.llenar('#dialogoNuevoTurno [name=hora_inicio]', '22:00');
            await h.llenar('#dialogoNuevoTurno [name=hora_fin]', '06:00');
            await h.esperar(800);
        } },
    { archivo: 'turnos/8-error-en-dialogo.png', cuenta: 'admin', url: '/turnos',
        antes: errorEnDialogo('/turnos', 'dialogoNuevoTurno', async (h) => {
            await h.llenar('#dialogoNuevoTurno [name=nombre]', 'Guardia Especial');
            await h.llenar('#dialogoNuevoTurno [name=hora_inicio]', '22:00');
            await h.llenar('#dialogoNuevoTurno [name=hora_fin]', '22:00');
        }) },
    { archivo: 'asistencia/6-turno-dias.png', cuenta: 'admin', url: '/turnos',
        antes: async (h) => { await h.dialogoDe('dialogoEditarTurno', 'Matutino'); await h.desplegar('#dialogoEditarTurno details'); },
        recorte: D('dialogoEditarTurno') },
];

const usuarios = [
    { archivo: 'usuarios/1-lista.png', cuenta: 'admin', url: '/usuarios' },
    { archivo: 'usuarios/12-noche.png', cuenta: 'admin', url: '/usuarios', modo: 'noche' },
    { archivo: 'usuarios/13-sol.png', cuenta: 'admin', url: '/usuarios', modo: 'sol' },
    { archivo: 'usuarios/3-celular.png', cuenta: 'admin', url: '/usuarios', vista: 'celular' },
    { archivo: 'usuarios/4-num-colaborador.png', cuenta: 'admin', url: '/usuarios',
        antes: async (h) => { await h.dialogo('dialogoNuevoUsuario'); await h.escribir('#dialogoNuevoUsuario [name=numero_colaborador]', 'dani'); await h.esperar(900); } },
    { archivo: 'usuarios/5-alta-por-pasos.png', cuenta: 'admin', url: '/usuarios', vista: { width: 1366, height: 868 },
        antes: async (h) => { await h.dialogo('dialogoNuevoUsuario'); }, recorte: D('dialogoNuevoUsuario') },
    { archivo: 'usuarios/5-vinculado-a-colaborador.png', cuenta: 'admin', url: '/usuarios',
        antes: async (h) => {
            await h.dialogo('dialogoNuevoUsuario');
            await h.escribir('#dialogoNuevoUsuario [name=numero_colaborador]', '1008');
            await h.esperar(900);
            await h.p.locator('#dialogoNuevoUsuario').getByText('Daniela Canul May').first().click();
            await h.esperar(900);
        } },
    { archivo: 'usuarios/6-sugerencia-vincular.png', cuenta: 'admin', url: '/usuarios',
        antes: async (h) => { await h.dialogo('dialogoNuevoUsuario'); await h.escribir('#dialogoNuevoUsuario [name=name]', 'Daniela Canul May'); await h.p.keyboard.press('Tab'); await h.esperar(1000); } },
    { archivo: 'usuarios/7-ya-existe.png', cuenta: 'admin', url: '/usuarios',
        antes: async (h) => { await h.dialogo('dialogoNuevoUsuario'); await h.escribir('#dialogoNuevoUsuario [name=name]', 'Andrea Ruiz'); await h.p.keyboard.press('Tab'); await h.esperar(1000); } },
    { archivo: 'usuarios/8-error-sin-confirmar.png', cuenta: 'admin', url: '/usuarios',
        antes: errorEnDialogo('/usuarios', 'dialogoNuevoUsuario', async (h) => {
            await h.llenar('#dialogoNuevoUsuario [name=name]', 'Andrea Ruiz');
            await h.llenar('#dialogoNuevoUsuario [name=username]', 'andrea.ruiz2');
            await h.p.locator('#dialogoNuevoUsuario [name=rol_id]').selectOption({ index: 2 });
            await h.p.locator('#dialogoNuevoUsuario [name=contrasena_modo][value=manual]').check({ force: true }).catch(() => {});
            await h.llenar('#dialogoNuevoUsuario [name=password]', 'Caseta.Norte.2026');
        }) },
    { archivo: 'usuarios/9-usuario-repetido.png', cuenta: 'admin', url: '/usuarios',
        antes: async (h) => { await h.dialogo('dialogoNuevoUsuario'); await h.escribir('#dialogoNuevoUsuario [name=username]', 'sergio.diaz'); await h.p.keyboard.press('Tab'); await h.esperar(1000); await h.centrar('#dialogoNuevoUsuario [id$=_aviso_cuenta]'); } },
    // Al final: bloquean y desbloquean una cuenta
    { archivo: 'usuarios/10-ficha-bloqueada.png', cuenta: 'admin', url: '/usuarios' },
    { archivo: 'usuarios/11-desbloqueado.png', cuenta: 'admin', url: '/usuarios',
        antes: async (h) => {
            const b = h.p.locator('button[title*="esbloquear"], button[aria-label*="esbloquear"]').first();
            if (await b.count()) { await b.click(); await h.listo(); }
        } },
];

const empresas = [
    { archivo: 'empresas/1-empresas.png', cuenta: 'admin', url: '/empresas' },
    { archivo: 'empresas/2-sedes.png', cuenta: 'admin', url: '/sedes' },
    { archivo: 'empresas/3-alta-sede.png', cuenta: 'admin', url: '/sedes',
        antes: async (h) => {
            await h.dialogo('dialogoNuevaSede');
            await h.llenar('#dialogoNuevaSede [name=nombre]', 'Hotel Aeropuerto');
            await h.llenar('#dialogoNuevaSede [name=codigo]', 'AER');
            await h.escribir('#dialogoNuevaSede [name=ciudad]', 'Cancún');
        } },
    { archivo: 'empresas/4-editar-empresa.png', cuenta: 'admin', url: '/empresas', antes: async (h) => { await h.dialogo('dialogoEditarEmpresa'); } },
    { archivo: 'empresas/5-alta-empresas.png', cuenta: 'superadmin', url: '/empresas' },
    { archivo: 'empresas/5-error-en-dialogo.png', cuenta: 'admin', url: '/sedes',
        antes: errorEnDialogo('/sedes', 'dialogoNuevaSede', async (h) => {
            await h.llenar('#dialogoNuevaSede [name=nombre]', 'Hotel Aeropuerto');
            await h.llenar('#dialogoNuevaSede [name=codigo]', 'CEN');
            await h.llenar('#dialogoNuevaSede [name=ciudad]', 'Cancún');
            await h.llenar('#dialogoNuevaSede [name=entidad]', 'Quintana Roo');
            await h.llenar('#dialogoNuevaSede [name=codigo_postal]', '775');
        }) },
    { archivo: 'empresas/6-modulos-contratados.png', cuenta: 'superadmin', url: '/empresas', vista: { width: 1366, height: 868 },
        antes: async (h) => {
            // Una empresa sin suscripción: sus módulos se eligen a mano
            const id = await h.p.evaluate(() => [...document.querySelectorAll('dialog[id^="dialogoModulos"]')]
                .filter((d) => !/suscripci/i.test(d.textContent)).map((d) => d.id)[0]);
            await h.dialogo(id);
        }, recorte: 'dialog[open]' },
];

const roles = [
    { archivo: 'roles/1-roles.png', cuenta: 'admin', url: '/roles' },
    { archivo: 'roles/10-que-hace-este-rol.png', cuenta: 'admin', url: '/permisos', vista: { width: 1366, height: 1200 },
        recorte: async (h, ruta) => {
            const s = h.p.locator('section, .tarjeta, .card').filter({ hasText: '¿Qué hace este rol?' }).last();
            await s.screenshot({ path: ruta });
        } },
    { archivo: 'roles/11-avanzado.png', cuenta: 'admin', url: '/permisos', vista: { width: 1366, height: 1200 },
        recorte: async (h, ruta) => {
            const s = h.p.locator('details, section').filter({ hasText: 'Avanzado: permiso por permiso' }).last();
            await s.evaluate((d) => { if (d.tagName === 'DETAILS') { d.open = true; } });
            await h.esperar(300);
            await s.screenshot({ path: ruta });
        } },
    { archivo: 'roles/2-editar-rol.png', cuenta: 'admin', url: '/roles',
        antes: async (h) => { await h.clic('button[aria-label="Editar Jefe de seguridad"]'); } },
    { archivo: 'roles/3-matriz.png', cuenta: 'admin', url: '/permisos',
        antes: async (h) => { await h.p.getByRole('link', { name: 'Director', exact: true }).first().click(); await h.listo(); await h.desplegar('details'); await h.centrar('table'); } },
    { archivo: 'roles/4-matriz-celular.png', cuenta: 'admin', url: '/permisos', vista: 'celular',
        antes: async (h) => { await h.p.getByRole('link', { name: 'Director', exact: true }).first().click(); await h.listo(); await h.desplegar('details'); await h.centrar('table'); } },
    { archivo: 'roles/5-selector-empresa.png', cuenta: 'superadmin', url: '/permisos' },
    { archivo: 'roles/8-nuevo-rol-posicion.png', cuenta: 'admin', url: '/roles',
        antes: async (h) => {
            await h.dialogo('dialogoNuevoRol');
            await h.escribir('#dialogoNuevoRol [name=nombre]', 'Coordinador de turno');
            await h.elegirTexto('#dialogoNuevoRol [name=posicion_relacion]', 'Debajo');
            await h.elegirTexto('#dialogoNuevoRol [name=posicion_rol]', 'Jefe de seguridad');
            await h.elegirTexto('#dialogoNuevoRol [name=copiar_de]', 'Supervisor');
            await h.esperar(400);
        }, recorte: D('dialogoNuevoRol') },
    { archivo: 'roles/9-lo-que-vera.png', cuenta: 'admin', url: '/permisos?rol=7', vista: { width: 1366, height: 1200 },
        recorte: async (h, ruta) => {
            const s = h.p.locator('section, .tarjeta, .card').filter({ hasText: 'Lo que verá una persona' }).last();
            await s.screenshot({ path: ruta });
        } },
];


const ident = async (h, texto) => { await h.botonDe('[data-ver-identificacion]', texto); await h.esperar(700); };

const gafetes = [
    { archivo: 'gafetes/lista.png', cuenta: 'admin', url: '/gafetes' },
    { archivo: 'gafetes/agente.png', cuenta: 'agente', url: '/gafetes' },
    { archivo: 'gafetes/baja-voucher-responsable.png', cuenta: 'admin', url: '/gafetes', vista: { width: 1366, height: 868 },
        antes: async (h) => {
            await h.dialogo('dialogoBajaGafete', 2);
            await h.p.locator('#dialogoBajaGafete [name=firma_modo]').last().check({ force: true });
            await h.esperar(400);
        }, recorte: D('dialogoBajaGafete') },
    { archivo: 'gafetes/de-baja-con-voucher.png', cuenta: 'admin', url: '/gafetes', vista: { width: 1366, height: 760 },
        antes: async (h) => { await h.clic('[data-filtro-estado="gafetes"][data-valor="0"]'); } },
    { archivo: 'gafetes/editar.png', cuenta: 'admin', url: '/gafetes', antes: async (h) => { await h.dialogo('dialogoEditarGafete'); } },
    { archivo: 'gafetes/error-en-dialogo.png', cuenta: 'admin', url: '/gafetes',
        antes: errorEnDialogo('/gafetes', 'dialogoLoteGafetes', async (h) => {
            await h.p.locator('#dialogoLoteGafetes [name=sede_id]').selectOption({ index: 1 });
            await h.elegirTexto('#dialogoLoteGafetes [name=tipo_gafete_id]', 'Nuevo tipo');
            await h.llenar('#dialogoLoteGafetes [name=cantidad]', '10');
        }) },
    { archivo: 'gafetes/generar-lote-tipo-nuevo.png', cuenta: 'admin', url: '/gafetes',
        antes: async (h) => {
            await h.dialogo('dialogoLoteGafetes');
            await h.p.locator('#dialogoLoteGafetes [name=sede_id]').selectOption({ index: 1 });
            await h.elegirTexto('#dialogoLoteGafetes [name=tipo_gafete_id]', 'Nuevo tipo');
            await h.llenar('#dialogoLoteGafetes [name=cantidad]', '10');
            await h.escribir('#dialogoLoteGafetes [name=nombre_tipo_nuevo]', 'Capital Humano');
        } },
    { archivo: 'gafetes/generar-lote.png', cuenta: 'admin', url: '/gafetes',
        antes: async (h) => {
            await h.dialogo('dialogoLoteGafetes');
            await h.p.locator('#dialogoLoteGafetes [name=sede_id]').selectOption({ index: 1 });
            await h.elegirTexto('#dialogoLoteGafetes [name=tipo_gafete_id]', 'Contratista');
            await h.escribir('#dialogoLoteGafetes [name=cantidad]', '10');
        } },
    { archivo: 'gafetes/identificacion.png', cuenta: 'admin', url: '/gafetes', antes: async (h) => { await h.clic('[data-ver-identificacion]'); } },
    { archivo: 'gafetes/marcar-e-imprimir.png', cuenta: 'admin', url: '/gafetes',
        antes: async (h) => { for (let i = 0; i < 3; i++) { await h.marcar('[data-casilla-gafete]', i); } } },
    { archivo: 'gafetes/impresion-doble-vista.png', cuenta: 'admin', url: '/gafetes',
        antes: async (h) => {
            for (let i = 0; i < 3; i++) { await h.marcar('[data-casilla-gafete]', i); }
            await h.p.evaluate(() => document.getElementById('formImprimirGafetes').removeAttribute('target'));
            await Promise.all([h.p.waitForNavigation(), h.p.click('[data-imprimir-gafetes]')]);
            await h.listo();
        } },
    { archivo: 'gafetes/movil.png', cuenta: 'admin', url: '/gafetes', vista: 'celular' },
    { archivo: 'gafetes/noche.png', cuenta: 'admin', url: '/gafetes', modo: 'noche' },
    { archivo: 'gafetes/sol.png', cuenta: 'admin', url: '/gafetes', modo: 'sol' },
    // Guarda un lote de 3 gafetes
    { archivo: 'gafetes/siguiente-paso-lote.png', cuenta: 'admin', url: '/gafetes', vista: { width: 1366, height: 330 },
        antes: async (h) => {
            await h.dialogo('dialogoLoteGafetes');
            await h.p.locator('#dialogoLoteGafetes [name=sede_id]').selectOption({ index: 1 });
            await h.elegirTexto('#dialogoLoteGafetes [name=tipo_gafete_id]', 'Contratista');
            await h.llenar('#dialogoLoteGafetes [name=cantidad]', '3');
            await Promise.all([h.p.waitForNavigation(), h.p.locator('#dialogoLoteGafetes [type=submit]').last().click()]);
            await h.listo(); await h.arriba();
        } },
];

const llavesNueva = async (h, nombre) => {
    await h.dialogo('dialogoNuevaLlave');
    await h.p.locator('#dialogoNuevaLlave [name=sede_id]').selectOption({ index: 1 });
    await h.desplegar('#dialogoNuevaLlave details');
    if (nombre) { await h.escribir('#dialogoNuevaLlave [name=nomenclatura]', nombre); await h.esperar(900); await h.centrar('#dialogoNuevaLlave [name=nomenclatura]'); }
};

const llaves = [
    { archivo: 'llaves/01-lista.png', cuenta: 'admin', url: '/llaves' },
    { archivo: 'llaves/02-alta.png', cuenta: 'admin', url: '/llaves', vista: { width: 1366, height: 860 },
        antes: async (h) => { await h.dialogo('dialogoNuevaLlave'); }, recorte: D('dialogoNuevaLlave') },
    { archivo: 'llaves/02b-alta-horarios.png', cuenta: 'admin', url: '/llaves', vista: { width: 1366, height: 868 },
        antes: async (h) => { await h.dialogo('dialogoNuevaLlave'); await h.desplegar('#dialogoNuevaLlave details'); await h.centrar('#dialogoNuevaLlave [name="horario_nombre[]"]'); },
        recorte: D('dialogoNuevaLlave') },
    { archivo: 'llaves/03-edicion.png', cuenta: 'admin', url: '/llaves', antes: async (h) => { await h.dialogoDe('dialogoEditarLlave', 'HDC-BOD-01'); } },
    { archivo: 'llaves/04-baja.png', cuenta: 'admin', url: '/llaves', vista: { width: 1366, height: 868 },
        antes: async (h) => {
            await h.clic('button[aria-label="Dar de baja HDP-MANT-02"]');
            await h.marcar('#dialogoBajaLlave [name=aplica_cobro]');
        }, recorte: D('dialogoBajaLlave') },
    { archivo: 'llaves/05-etiquetas.png', cuenta: 'admin', url: '/llaves',
        antes: async (h) => {
            for (let i = 0; i < 3; i++) { await h.marcar('[data-chk-llave]', i); }
            await h.p.evaluate(() => document.getElementById('formEtiquetasLlaves').removeAttribute('target'));
            await Promise.all([h.p.waitForNavigation(), h.p.click('[data-imprimir-llaves]')]);
            await h.listo();
        } },
    { archivo: 'llaves/06-movil.png', cuenta: 'admin', url: '/llaves', vista: 'celular' },
    { archivo: 'llaves/07-noche.png', cuenta: 'admin', url: '/llaves', modo: 'noche' },
    { archivo: 'llaves/08-sol.png', cuenta: 'admin', url: '/llaves', modo: 'sol' },
    { archivo: 'llaves/09-agente-movil.png', cuenta: 'agente', url: '/llaves', vista: 'celular' },
    { archivo: 'llaves/10-nombre-parecido.png', cuenta: 'admin', url: '/llaves', antes: async (h) => { await llavesNueva(h, 'HDC 101'); } },
    { archivo: 'llaves/11-nombre-repetido.png', cuenta: 'admin', url: '/llaves', antes: async (h) => { await llavesNueva(h, 'HDC-101'); } },
    { archivo: 'llaves/12-error-en-dialogo.png', cuenta: 'admin', url: '/llaves',
        antes: errorEnDialogo('/llaves', 'dialogoNuevaLlave', async (h) => {
            await h.p.locator('#dialogoNuevaLlave [name=sede_id]').selectOption({ index: 1 });
            await h.llenar('#dialogoNuevaLlave [name=nomenclatura]', 'HDC-103');
            await h.llenar('#dialogoNuevaLlave [name=descripcion]', 'Habitación 103');
            await h.elegirTexto('#dialogoNuevaLlave [name=alcance]', 'specífica');
        }) },
    { archivo: 'llaves/13-identificacion.png', cuenta: 'admin', url: '/llaves', antes: async (h) => { await ident(h, 'HDC-BOD-01'); } },
    { archivo: 'llaves/14-cascada-area.png', cuenta: 'admin', url: '/llaves', vista: 'laptop',
        antes: async (h) => {
            await h.dialogo('dialogoNuevaLlave');
            await h.p.locator('#dialogoNuevaLlave [name=sede_id]').selectOption({ index: 1 });
            await h.llenar('#dialogoNuevaLlave [name=nomenclatura]', 'HDC-P2-AMA');
            await h.llenar('#dialogoNuevaLlave [name=descripcion]', 'Ama de llaves piso 2');
            await h.elegirTexto('#dialogoNuevaLlave [name=alcance]', 'specífica');
            await h.esperar(600);
            await h.p.locator('#dialogoNuevaLlave [data-pildora-edificio]').filter({ visible: true, hasText: 'Torre A' }).first().click();
            await h.esperar(600);
            await h.p.locator('#dialogoNuevaLlave [data-pildora-piso]').filter({ visible: true, hasText: 'Piso 2' }).first().click();
            await h.esperar(600);
            await h.centrar('#dialogoNuevaLlave [name=tipo_dispositivo]');
        } },
];

const equipos = [
    { archivo: 'equipos/01-lista.png', cuenta: 'admin', url: '/equipos' },
    { archivo: 'equipos/02-alta.png', cuenta: 'admin', url: '/equipos',
        antes: async (h) => {
            await h.dialogo('dialogoNuevoEquipo');
            await h.p.locator('#dialogoNuevoEquipo [name=sede_id]').selectOption({ index: 1 });
            await h.elegirTexto('#dialogoNuevoEquipo [name=tipo_equipo_id]', 'Botiquín');
            await h.llenar('#dialogoNuevoEquipo [name=marca]', 'MOTOROLA');
            await h.llenar('#dialogoNuevoEquipo [name=modelo]', 'DEP 450');
            await h.escribir('#dialogoNuevoEquipo [name=numero_serie]', 'RAD-8830');
            await h.llenar('#dialogoNuevoEquipo [name=costo]', '4800');
        } },
    { archivo: 'equipos/03-editar.png', cuenta: 'admin', url: '/equipos', antes: async (h) => { await h.dialogoDe('dialogoEditarEquipo', 'BK-CEN-01'); } },
    { archivo: 'equipos/04-baja.png', cuenta: 'admin', url: '/equipos', vista: { width: 1366, height: 868 },
        antes: async (h) => { await h.dialogoDe('dialogoBajaEquipo', 'BK-CEN-01'); await h.marcar('#dialogoBajaEquipo [name=aplica_cobro]'); },
        recorte: D('dialogoBajaEquipo') },
    { archivo: 'equipos/05-ver-qr.png', cuenta: 'admin', url: '/equipos', antes: async (h) => { await ident(h, 'BK-CEN-01'); } },
    { archivo: 'equipos/07-etiqueta.png', cuenta: 'admin', url: '/equipos', antes: async (h) => { await h.enlaceDe('/etiqueta', 'BK-CEN-01'); } },
    { archivo: 'equipos/08-celular.png', cuenta: 'admin', url: '/equipos', vista: 'celular' },
    { archivo: 'equipos/10-noche.png', cuenta: 'admin', url: '/equipos', modo: 'noche' },
    { archivo: 'equipos/12-sol.png', cuenta: 'admin', url: '/equipos', modo: 'sol' },
    { archivo: 'equipos/13-agente.png', cuenta: 'agente', url: '/equipos' },
    { archivo: 'equipos/14-error-en-dialogo.png', cuenta: 'admin', url: '/equipos',
        antes: errorEnDialogo('/equipos', 'dialogoNuevoEquipo', async (h) => {
            await h.p.locator('#dialogoNuevoEquipo [name=sede_id]').selectOption({ index: 1 });
            await h.elegirTexto('#dialogoNuevoEquipo [name=tipo_equipo_id]', 'Botiquín');
            await h.llenar('#dialogoNuevoEquipo [name=marca]', 'MOTOROLA');
            await h.llenar('#dialogoNuevoEquipo [name=modelo]', 'DEP 450');
            await h.llenar('#dialogoNuevoEquipo [name=costo]', '4800');
        }) },
];

const pcNuevo = async (h, serie, sede = true) => {
    await h.dialogo('dialogoNuevoEquipoPc');
    if (sede) {
        await h.p.locator('#dialogoNuevoEquipoPc [name=sede_id]').selectOption({ index: 1 });
        await h.elegirTexto('#dialogoNuevoEquipoPc [name=categoria]', 'Extintor');
        await h.llenar('#dialogoNuevoEquipoPc [name=referencia]', 'Junto al elevador de servicio');
    }
    if (serie) { await h.escribir('#dialogoNuevoEquipoPc [name=numero_serie]', serie); await h.esperar(900); }
};

const equiposPc = [
    { archivo: 'equipos-pc/1-lista.png', cuenta: 'admin', url: '/equipos-pc' },
    { archivo: 'equipos-pc/10-noche.png', cuenta: 'admin', url: '/equipos-pc', modo: 'noche' },
    { archivo: 'equipos-pc/11-sol.png', cuenta: 'admin', url: '/equipos-pc', modo: 'sol' },
    { archivo: 'equipos-pc/12-nuevo-celular.png', cuenta: 'admin', url: '/equipos-pc', vista: 'celular', antes: async (h) => { await h.dialogo('dialogoNuevoEquipoPc'); } },
    { archivo: 'equipos-pc/13-error-en-dialogo.png', cuenta: 'admin', url: '/equipos-pc',
        antes: errorEnDialogo('/equipos-pc', 'dialogoNuevoEquipoPc', async (h) => {
            await h.p.locator('#dialogoNuevoEquipoPc [name=sede_id]').selectOption({ index: 1 });
            await h.elegirTexto('#dialogoNuevoEquipoPc [name=categoria]', 'Extintor');
            await h.llenar('#dialogoNuevoEquipoPc [name=referencia]', 'Junto al elevador de servicio');
        }) },
    { archivo: 'equipos-pc/13-proxima-recarga.png', cuenta: 'admin', url: '/equipos-pc', vista: { width: 390, height: 900 },
        antes: async (h) => { await h.dialogo('dialogoNuevoEquipoPc'); await h.centrar('#dialogoNuevoEquipoPc [name=proxima_recarga]'); } },
    { archivo: 'equipos-pc/14-ya-existe.png', cuenta: 'admin', url: '/equipos-pc', antes: async (h) => { await pcNuevo(h, 'EXT-99'); } },
    { archivo: 'equipos-pc/15-ultima-revision.png', cuenta: 'admin', url: '/equipos-pc', vista: { width: 1366, height: 760 } },
    { archivo: 'equipos-pc/3-nuevo.png', cuenta: 'admin', url: '/equipos-pc', antes: async (h) => { await pcNuevo(h, 'EXT-50'); } },
    { archivo: 'equipos-pc/4-editar.png', cuenta: 'admin', url: '/equipos-pc', antes: async (h) => { await h.dialogoDe('dialogoEditarEquipoPc', 'BOT-01'); } },
    { archivo: 'equipos-pc/5-ver-qr.png', cuenta: 'admin', url: '/equipos-pc', antes: async (h) => { await ident(h, 'BOT-01'); } },
    { archivo: 'equipos-pc/6-etiqueta.png', cuenta: 'admin', url: '/equipos-pc/8/etiqueta' },
    { archivo: 'equipos-pc/8-agente-celular.png', cuenta: 'agente', url: '/equipos-pc', vista: 'celular' },
];

const zonaNueva = async (h, nombre, tipo, cupo) => {
    await h.dialogo('dialogoNuevaZona');
    await h.p.locator('#dialogoNuevaZona [name=sede_id]').selectOption({ index: 1 });
    await h.llenar('#dialogoNuevaZona [name=nombre]', nombre);
    if (tipo) { await h.elegirTexto('#dialogoNuevaZona [name=tipo]', tipo); }
    await h.escribir('#dialogoNuevaZona [name=cupo_total]', cupo);
};

const estacionamientos = [
    { archivo: 'estacionamientos/01-cupos-por-sede.png', cuenta: 'admin', url: '/estacionamientos' },
    { archivo: 'estacionamientos/02-nueva-zona.png', cuenta: 'admin', url: '/estacionamientos', antes: async (h) => { await zonaNueva(h, 'Estacionamiento Colaboradores Norte', null, '40'); } },
    { archivo: 'estacionamientos/03-zona-descarga.png', cuenta: 'admin', url: '/estacionamientos', antes: async (h) => { await zonaNueva(h, 'Andén de Almacén', 'Descarga', '4'); } },
    { archivo: 'estacionamientos/04-editar.png', cuenta: 'admin', url: '/estacionamientos', antes: async (h) => { await h.dialogoDe('dialogoEditarZona', 'Estacionamiento Colaboradores'); } },
    { archivo: 'estacionamientos/05-filtro-descarga.png', cuenta: 'admin', url: '/estacionamientos', antes: async (h) => { await h.clic('[data-filtro-tipo="zonas"][data-valor="zona_descarga"]'); } },
    { archivo: 'estacionamientos/06-celular.png', cuenta: 'admin', url: '/estacionamientos', vista: 'celular' },
    { archivo: 'estacionamientos/07-noche.png', cuenta: 'admin', url: '/estacionamientos', modo: 'noche' },
    { archivo: 'estacionamientos/08-sol.png', cuenta: 'admin', url: '/estacionamientos', modo: 'sol' },
    { archivo: 'estacionamientos/09-error-en-dialogo.png', cuenta: 'admin', url: '/estacionamientos',
        antes: async (h) => { await zonaNueva(h, 'Estacionamiento Colaboradores', null, '10'); await h.enviarSinValidar(F('dialogoNuevaZona')); } },
    { archivo: 'estacionamientos/10-agente.png', cuenta: 'agente', url: '/estacionamientos' },
    { archivo: 'estacionamientos/11-quien-ocupa.png', cuenta: 'admin', url: '/estacionamientos',
        antes: async (h) => { await h.desplegar('details'); },
        recorte: async (h, ruta) => {
            const el = h.p.locator('details').first().locator('xpath=ancestor::*[contains(@class,"ficha-card")][1]');
            await el.screenshot({ path: ruta });
        } },
];

const vehiculos = [
    { archivo: 'vehiculos/v1-lista.png', cuenta: 'admin', url: '/vehiculos' },
    { archivo: 'vehiculos/v12-celular.png', cuenta: 'admin', url: '/vehiculos', vista: 'celular' },
    { archivo: 'vehiculos/v13-placas-repetidas.png', cuenta: 'admin', url: '/vehiculos',
        antes: async (h) => {
            await h.dialogo('dialogoNuevoVehiculo');
            await h.elegirTexto('#dialogoNuevoVehiculo [name=tipo]', 'SUV');
            await h.llenar('#dialogoNuevoVehiculo [name=marca]', 'NISSAN');
            await h.llenar('#dialogoNuevoVehiculo [name=modelo]', 'NP300');
            await h.llenar('#dialogoNuevoVehiculo [name=color]', 'BLANCO');
            await h.escribir('#dialogoNuevoVehiculo [name=placas]', 'XTR901C'); await h.esperar(900);
        } },
    { archivo: 'vehiculos/v14-error-en-dialogo.png', cuenta: 'admin', url: '/vehiculos',
        antes: errorEnDialogo('/vehiculos', 'dialogoNuevoVehiculo', async (h) => {
            await h.llenar('#dialogoNuevoVehiculo [name=placas]', 'A');
            await h.elegirTexto('#dialogoNuevoVehiculo [name=tipo]', 'SUV');
            await h.llenar('#dialogoNuevoVehiculo [name=marca]', 'NISSAN');
            await h.llenar('#dialogoNuevoVehiculo [name=modelo]', 'NP300');
            await h.llenar('#dialogoNuevoVehiculo [name=color]', 'BLANCO');
        }) },
    { archivo: 'vehiculos/v15-agente.png', cuenta: 'agente', url: '/vehiculos' },
    { archivo: 'vehiculos/v6-editar.png', cuenta: 'admin', url: '/vehiculos', antes: async (h) => { await h.dialogoDe('dialogoEditarVehiculo', 'TX1187'); } },
    { archivo: 'vehiculos/v7-calcomania.png', cuenta: 'admin', url: '/vehiculos/19/calcomania' },
    { archivo: 'vehiculos/v8-identificacion.png', cuenta: 'admin', url: '/vehiculos', antes: async (h) => { await ident(h, 'TX4410'); } },
    { archivo: 'vehiculos/v9-noche.png', cuenta: 'admin', url: '/vehiculos', modo: 'noche' },
];

const personas = [
    { archivo: 'personas/01-lista.png', cuenta: 'admin', url: '/personas' },
    { archivo: 'personas/02-filtro-contratistas.png', cuenta: 'admin', url: '/personas', vista: 'alto', antes: async (h) => { await h.texto('Contratistas'); } },
    { archivo: 'personas/03-alta.png', cuenta: 'admin', url: '/personas', vista: { width: 1366, height: 868 },
        antes: async (h) => {
            await h.dialogo('dialogoNuevaPersona');
            await h.escribir('#dialogoNuevaPersona [name=nombre_completo]', 'Juan Pérez Canul');
            await h.elegirTexto('#dialogoNuevaPersona [name=tipo]', 'Proveedor');
            await h.esperar(900);
        }, recorte: D('dialogoNuevaPersona') },
    { archivo: 'personas/05-editar.png', cuenta: 'admin', url: '/personas', vista: 'alto', antes: async (h) => { await h.dialogoDe('dialogoEditarPersona', 'Andrés Herrera'); } },
    { archivo: 'personas/08-celular.png', cuenta: 'admin', url: '/personas', vista: 'celular' },
    { archivo: 'personas/09-celular-alta.png', cuenta: 'admin', url: '/personas', vista: 'celular', antes: async (h) => { await h.dialogo('dialogoNuevaPersona'); } },
    { archivo: 'personas/14-error-en-dialogo.png', cuenta: 'admin', url: '/personas',
        antes: errorEnDialogo('/personas', 'dialogoNuevaPersona', async (h) => {
            await h.desplegar('#dialogoNuevaPersona details');
            await h.llenar('#dialogoNuevaPersona [name=nombre_completo]', 'Juan Pérez López');
            await h.llenar('#dialogoNuevaPersona [name=folio_identificacion]', '12');
            await h.llenar('#dialogoNuevaPersona [name=telefono]', '123');
            await h.llenar('#dialogoNuevaPersona [name=motivo_visita]', 'Entrega de mercancía');
        }) },
    { archivo: 'personas/15-folio-y-nombre.png', cuenta: 'admin', url: '/personas', vista: { width: 1366, height: 868 },
        antes: async (h) => {
            const folio = await h.p.evaluate(async () => '');
            await h.dialogo('dialogoNuevaPersona');
            await h.desplegar('#dialogoNuevaPersona details');
            await h.escribir('#dialogoNuevaPersona [name=nombre_completo]', 'laura mendez rios');
            await h.esperar(800);
            await h.escribir('#dialogoNuevaPersona [name=folio_identificacion]', 'G48291736' + folio);
            await h.esperar(900);
            await h.centrar('#dialogoNuevaPersona [name=folio_identificacion]');
        }, recorte: D('dialogoNuevaPersona') },
    { archivo: 'personas/16-desde-empresa-externa.png', cuenta: 'admin', url: '/proveedores/4', vista: { width: 1366, height: 868 },
        antes: async (h) => { await h.texto('Personal'); await h.texto('Agregar persona'); await h.esperar(600); }, recorte: 'dialog[open]' },
    { archivo: 'personas/17-unir-duplicado.png', cuenta: 'admin', url: '/personas', vista: { width: 1366, height: 868 },
        antes: async (h) => {
            await h.clic('button[aria-label^="Unir a Laura Méndez Ríos"]'); await h.esperar(1200);
            await h.escribir('#dialogoUnirDuplicado input[type=search]', 'Laura Mendez'); await h.esperar(1200);
            await h.p.locator('#dialogoUnirDuplicado input[type=radio]').first().check({ force: true }).catch(() => {});
        },
        recorte: 'dialog[open]' },
];

const proveedorNuevo = async (h, nombre) => {
    await h.dialogo('dialogoNuevoProveedor');
    await h.escribir('#dialogoNuevoProveedor [name=nombre]', nombre);
    await h.esperar(800);
};

const proveedores = [
    { archivo: 'proveedores/01-lista.png', cuenta: 'admin', url: '/proveedores' },
    { archivo: 'proveedores/02-filtro-categoria.png', cuenta: 'admin', url: '/proveedores', antes: async (h) => { await h.p.locator('main').getByText('Contratistas', { exact: true }).first().click(); await h.esperar(500); } },
    { archivo: 'proveedores/03-alta.png', cuenta: 'admin', url: '/proveedores',
        antes: async (h) => {
            await proveedorNuevo(h, 'Lavandería Industrial del Sur');
            await h.llenar('#dialogoNuevoProveedor [name=rfc]', 'LIS150101PA2');
            await h.escribir('#dialogoNuevoProveedor [name=telefono]', '998 555 1234');
        } },
    { archivo: 'proveedores/04-editar.png', cuenta: 'admin', url: '/proveedores', antes: async (h) => { await h.dialogoDe('dialogoEditarProveedor', 'Abarrotes del Caribe S.A. de C.V.'); } },
    { archivo: 'proveedores/05-sedes.png', cuenta: 'admin', url: '/proveedores', antes: async (h) => { await h.dialogo('dialogoSedesProveedor10'); } },
    { archivo: 'proveedores/06-ficha-flotilla.png', cuenta: 'admin', url: '/proveedores/2', antes: async (h) => { await h.texto('Flotilla'); } },
    { archivo: 'proveedores/06-ficha-personal.png', cuenta: 'admin', url: '/proveedores/2', antes: async (h) => { await h.texto('Personal'); } },
    { archivo: 'proveedores/06-ficha-resumen.png', cuenta: 'admin', url: '/proveedores/2' },
    { archivo: 'proveedores/07-movil-lista.png', cuenta: 'admin', url: '/proveedores', vista: 'celular' },
    { archivo: 'proveedores/10-noche-lista.png', cuenta: 'admin', url: '/proveedores', modo: 'noche' },
    { archivo: 'proveedores/14-supervisor-alta.png', cuenta: 'supervisor', url: '/proveedores', antes: async (h) => { await h.dialogo('dialogoNuevoProveedor'); } },
    { archivo: 'proveedores/15-ya-existe.png', cuenta: 'admin', url: '/proveedores',
        antes: async (h) => {
            await proveedorNuevo(h, 'Abarrotes del Caribe SA');
            await h.llenar('#dialogoNuevoProveedor [name=rfc]', 'LIS150101PA2');
            await h.llenar('#dialogoNuevoProveedor [name=telefono]', '998 555 1234');
        } },
    { archivo: 'proveedores/16-error-en-dialogo.png', cuenta: 'admin', url: '/proveedores',
        antes: errorEnDialogo('/proveedores', 'dialogoNuevoProveedor', async (h) => {
            await h.llenar('#dialogoNuevoProveedor [name=nombre]', 'Lavandería Industrial del Sur');
            await h.llenar('#dialogoNuevoProveedor [name=rfc]', '123');
            await h.llenar('#dialogoNuevoProveedor [name=telefono]', '12');
        }) },
    { archivo: 'proveedores/16-una-sede-un-toque.png', cuenta: 'supervisor', url: '/proveedores',
        recorte: async (h, ruta) => {
            await h.p.getByText('Quitar de mi sede').first().locator('xpath=ancestor::*[contains(@class,"ficha-card")][1]').screenshot({ path: ruta });
        } },
];

const rutaNueva = async (h) => { await h.botonDe('Nueva Llegada', 'Llegadas'); await h.esperar(500); };

const rutas = [
    { archivo: 'rutas/01-lista-sedes.png', cuenta: 'admin', url: '/rutas' },
    { archivo: 'rutas/02-sede-llegadas.png', cuenta: 'admin', url: '/rutas/sede/1' },
    { archivo: 'rutas/03-alta-ruta.png', cuenta: 'admin', url: '/rutas/sede/1',
        antes: async (h) => {
            await h.dialogo('dialogoNuevaRuta');
            await h.llenar('#dialogoNuevaRuta [name=nombre]', 'RUTA 5 - PUERTO JUÁREZ');
            await h.elegirTexto('#dialogoNuevaRuta [name=turno_id]', 'Matutino');
            await h.elegirTexto('#dialogoNuevaRuta [name=proveedor_id]', 'Shuttle');
            await h.llenar('#dialogoNuevaRuta [name="horarios[0][nombre]"]', 'Lunes a viernes');
        } },
    { archivo: 'rutas/04-error-en-dialogo.png', cuenta: 'admin', url: '/rutas/sede/1', antes: errorEnDialogo('/rutas/sede/1', 'dialogoNuevaRuta') },
    { archivo: 'rutas/05-editar-ruta.png', cuenta: 'admin', url: '/rutas/sede/1', antes: async (h) => { await h.dialogoDe('dialogoEditarRuta', 'REGIÓN 94'); } },
    { archivo: 'rutas/08-paraderos.png', cuenta: 'admin', url: '/rutas/sede/1', antes: async (h) => { await h.texto('Paraderos ('); } },
    { archivo: 'rutas/09-hoja-del-dia.png', cuenta: 'admin', url: '/rutas/sede/1/dia' },
    { archivo: 'rutas/10-horarios-plegados.png', cuenta: 'admin', url: '/rutas/sede/1', vista: { width: 1366, height: 868 },
        antes: async (h) => {
            await h.dialogo('dialogoNuevaRuta');
            await h.llenar('#dialogoNuevaRuta [name="horarios[0][hora_inicio]"]', '05:30');
            await h.llenar('#dialogoNuevaRuta [name="horarios[0][hora_fin]"]', '06:40');
            await h.p.locator('#dialogoNuevaRuta').getByText('Agregar otro Horario').first().click();
            await h.esperar(600);
            await h.centrar('#dialogoNuevaRuta [name="horarios[1][nombre]"]');
        }, recorte: D('dialogoNuevaRuta') },
    { archivo: 'rutas/11-hoja-semana.png', cuenta: 'admin', url: '/rutas/sede/1/semana' },
    { archivo: 'rutas/12-movil-sede.png', cuenta: 'admin', url: '/rutas/sede/1', vista: 'celular' },
    { archivo: 'rutas/16-noche-sede.png', cuenta: 'admin', url: '/rutas/sede/1', modo: 'noche' },
    { archivo: 'rutas/19-agente-sede.png', cuenta: 'agente', url: '/rutas/sede/1' },
];

const espacios = [
    { archivo: 'espacios/0-sedes.png', cuenta: 'admin', url: '/espacios' },
    { archivo: 'espacios/1-zonas.png', cuenta: 'admin', url: '/espacios/sede/1' },
    { archivo: 'espacios/10-migas.png', cuenta: 'admin', url: '/espacios/2', vista: 'alto' },
    { archivo: 'espacios/10-secciones.png', cuenta: 'admin', url: '/espacios/sede/1?pestana=secciones' },
    { archivo: 'espacios/11-mover.png', cuenta: 'admin', url: '/espacios/2',
        antes: async (h) => { await h.botonDe('[data-dialogo="dialogoMoverEspacio"], [title*="Mover"]', '101'); await h.elegirTexto('#dialogoMoverEspacio select', 'Piso 2'); },
        recorte: D('dialogoMoverEspacio') },
    { archivo: 'espacios/12-eliminar-varias.png', cuenta: 'admin', url: '/espacios/2', vista: { width: 1366, height: 860 },
        antes: async (h) => {
            await h.marcar('input[aria-label="Marcar 102"]'); await h.marcar('input[aria-label="Marcar 103"]'); await h.esperar(400);
        },
        recorte: async (h, ruta) => {
            const barra = await h.p.locator('[data-eliminar-varias]').first().boundingBox();
            const a = await h.p.locator('input[aria-label="Marcar 103"]').locator('xpath=ancestor::*[contains(@class,"ficha-card")][1]').boundingBox();
            const y0 = Math.max(0, barra.y - 14);
            await h.p.screenshot({ path: ruta, clip: { x: 100, y: y0, width: 1166, height: Math.min(860 - y0, a.y + a.height - y0 + 16) } });
        } },
    { archivo: 'espacios/2-habitaciones.png', cuenta: 'admin', url: '/espacios/2' },
    { archivo: 'espacios/3-lote.png', cuenta: 'admin', url: '/espacios/2', antes: async (h) => { await h.dialogo('dialogoLote'); } },
    { archivo: 'espacios/4-detalle.png', cuenta: 'admin', url: '/espacios/3' },
    { archivo: 'espacios/5-celular.png', cuenta: 'admin', url: '/espacios/2', vista: 'celular' },
    { archivo: 'espacios/6-asignar-seccion.png', cuenta: 'admin', url: '/espacios/sede/1',
        antes: async (h) => {
            await h.ir('/espacios/sede/1?pestana=secciones');
            const id = await h.p.evaluate(() => [...document.querySelectorAll('dialog[id^="dialogoAsignar"]')].map((d) => d.id)[0]);
            await h.dialogo(id);
        } },
    { archivo: 'espacios/7-nombre-parecido.png', cuenta: 'admin', url: '/espacios/sede/1',
        antes: async (h) => { await h.dialogo('dialogoNuevaZona'); await h.escribir('#dialogoNuevaZona [name=nombre]', 'Torre-A'); await h.esperar(900); } },
    { archivo: 'espacios/8-ya-existe.png', cuenta: 'admin', url: '/espacios/sede/1',
        antes: async (h) => { await h.dialogo('dialogoNuevaZona'); await h.escribir('#dialogoNuevaZona [name=nombre]', 'Torre A'); await h.esperar(900); } },
    { archivo: 'espacios/9-pisos.png', cuenta: 'admin', url: '/espacios/1' },
];

const residentes = [
    { archivo: 'residentes/lista.png', cuenta: 'condoAdmin', url: '/residentes', vista: { width: 1280, height: 760 } },
    { archivo: 'residentes/alta.png', cuenta: 'condoAdmin', url: '/residentes', antes: async (h) => { await h.dialogo('dialogoNuevoResidente'); }, recorte: D('dialogoNuevoResidente') },
];

// Recorta el primer elemento «sel» que contiene «texto»
const recorteDe = (sel, texto) => async (h, ruta) => {
    let el = h.p.locator(sel).filter({ hasText: texto }).first();
    if (await el.count() === 0) { el = h.p.locator(sel).first(); }
    await el.scrollIntoViewIfNeeded();
    await el.screenshot({ path: ruta });
};
const ING = '#dialogoIngreso';
const ingreso = async (h, tipo) => { await h.clic('.btn-nuevo-ingreso'); if (tipo) { await h.opcion(ING, tipo); } };

const accesos = [
    { archivo: 'accesos/aprobar-por-excepcion.png', cuenta: 'supervisor', url: '/accesos?pestana=pendientes', recorte: recorteDe('.ficha-acceso', 'por excepción') },
    { archivo: 'accesos/canalizar-supervisor.png', cuenta: 'agente', url: '/accesos?pestana=pendientes', recorte: recorteDe('.ficha-acceso', 'Canalizar al supervisor') },
    { archivo: 'accesos/celular-agente.png', cuenta: 'agente', url: '/accesos', vista: 'celular1x' },
    { archivo: 'accesos/celular-nuevo-ingreso.png', cuenta: 'agente', url: '/accesos', vista: 'celular1x', antes: async (h) => { await ingreso(h); } },
    { archivo: 'accesos/celular-pendientes.png', cuenta: 'agente', url: '/accesos?pestana=pendientes', vista: 'celular1x', recorte: 'completa' },
    { archivo: 'accesos/dar-salida.png', cuenta: 'agente', url: '/accesos', vista: 'alto',
        antes: async (h) => { await h.escribir('[data-salida-vista] input', 'rob'); await h.esperar(1200); } },
    { archivo: 'accesos/eventos-de-hoy.png', cuenta: 'agente', url: '/accesos', recorte: 'details.eventos-hoy' },
    { archivo: 'accesos/gente-en-sitio-condominio.png', cuenta: 'condoAgente', url: '/accesos', vista: { width: 1366, height: 783 } },
    { archivo: 'accesos/gente-en-sitio.png', cuenta: 'agente', url: '/accesos', recorte: 'completa' },
    { archivo: 'accesos/historial.png', cuenta: 'agente', url: '/accesos?pestana=historial', recorte: 'completa' },
    { archivo: 'accesos/noche.png', cuenta: 'agente', url: '/accesos?pestana=pendientes', modo: 'noche', vista: { width: 1366, height: 1000 } },
    { archivo: 'accesos/sol.png', cuenta: 'agente', url: '/accesos?pestana=pendientes', modo: 'sol', vista: { width: 1366, height: 1000 } },
    { archivo: 'accesos/nuevo-ingreso-condominio.png', cuenta: 'condoAgente', url: '/accesos', vista: { width: 1366, height: 868 },
        antes: async (h) => {
            await ingreso(h, 'Visita');
            await h.escribir(`${ING} input[name=nombre]:visible`, 'MARTA SOLÍS');
            await h.escribir(`${ING} [data-visita-colaborador] [data-a-quien-texto]`, 'ALEJANDRA');
            await h.esperar(900);
            await h.p.locator(`${ING} [data-visita-colaborador] [data-sugerencias] button, ${ING} [data-visita-colaborador] [data-sugerencias] li`).first().click().catch(() => {});
            await h.esperar(600);
            await h.centrar(`${ING} [data-visita-colaborador]`);
        }, recorte: ING },
    { archivo: 'accesos/nuevo-ingreso-corporativo.png', cuenta: 'corpAgente', url: '/accesos', vista: { width: 1366, height: 868 },
        antes: async (h) => { await ingreso(h, 'Visitante'); }, recorte: ING },
    { archivo: 'accesos/nuevo-ingreso-pendiente.png', cuenta: 'agente', url: '/accesos', vista: 'alto',
        antes: async (h) => {
            await ingreso(h, 'Personal Externo');
            await h.escribir(`${ING} input[name=nombre]:visible`, 'INGRID SOLÍS PECH');
            await h.opcion(ING, 'Visita a persona o área');
            await h.escribir(`${ING} [data-visita-colaborador] [data-a-quien-texto]`, 'MANTENIMIENTO');
            await h.esperar(900);
            await h.p.locator(`${ING} [data-visita-colaborador] [data-sugerencias] button, ${ING} [data-visita-colaborador] [data-sugerencias] li`).first().click().catch(() => {});
            await h.esperar(600);
            await h.centrar(`${ING} [data-boton-registrar]`);
        } },
    { archivo: 'accesos/nuevo-ingreso-proveedor.png', cuenta: 'agente', url: '/accesos', vista: { width: 1366, height: 968 },
        antes: async (h) => {
            await ingreso(h, 'Proveedor');
            await h.escribir(`${ING} input[name=nombre]:visible`, 'LUIS CHI CANUL');
            await h.escribir(`${ING} input[name=empresa_procedencia]:visible`, 'GAS DEL SUR');
            await h.centrar(`${ING} input[name=nombre]:visible`);
        }, recorte: ING },
    { archivo: 'accesos/nuevo-ingreso.png', cuenta: 'agente', url: '/accesos', vista: { width: 1366, height: 868 }, antes: async (h) => { await ingreso(h); }, recorte: ING },
    { archivo: 'accesos/pendientes.png', cuenta: 'agente', url: '/accesos?pestana=pendientes', vista: { width: 1366, height: 1332 } },
    { archivo: 'accesos/rebasaron-su-tiempo.png', cuenta: 'agente', url: '/accesos?rebasaron=1', recorte: async (h, ruta) => { await h.p.locator('.ficha-acceso').first().screenshot({ path: ruta }); } },
    { archivo: 'accesos/sale-con-lo-mismo.png', cuenta: 'agente', url: '/accesos', vista: { width: 1366, height: 868 },
        antes: async (h) => {
            await h.botonDe('[data-dar-salida]', 'JUAN CHAN PECH');
            await h.p.locator('#dialogoDarSalida input[type=checkbox]').last().uncheck({ force: true }).catch(() => {});
            await h.p.locator('#dialogoDarSalida textarea, #dialogoDarSalida input[type=text]').filter({ visible: true }).last().fill('Lleva también una caja').catch(() => {});
        }, recorte: '#dialogoDarSalida' },
    { archivo: 'accesos/tarjeta-espera-escalada.png', cuenta: 'agente', url: '/accesos?pestana=pendientes', recorte: recorteDe('.ficha-acceso', 'Se escaló') },
    { archivo: 'accesos/trae-herramienta.png', cuenta: 'agente', url: '/accesos', vista: { width: 1366, height: 900 },
        antes: async (h) => {
            await ingreso(h, 'Proveedor');
            const d = h.p.locator(`${ING} details`).filter({ hasText: 'herramienta' }).first();
            await d.evaluate((x) => { x.open = true; });
            await h.llenar(`${ING} [name="articulos[0][articulo]"]`, 'TALADRO');
            await h.llenar(`${ING} [name="articulos[0][serie]"]`, 'DW-123');
            await h.llenar(`${ING} [name="articulos[1][articulo]"]`, 'ESCALERA');
            await d.evaluate((x) => x.scrollIntoView({ block: 'start' }));
            await h.esperar(300);
        }, recorte: async (h, ruta) => { await h.p.locator(`${ING} details`).filter({ hasText: 'herramienta' }).first().screenshot({ path: ruta }); } },
    { archivo: 'recepcion-y-candidatos/caseta-ingreso-candidato.png', cuenta: 'agente', url: '/accesos', vista: 'alto',
        antes: async (h) => {
            await ingreso(h, 'Personal Externo');
            await h.escribir(`${ING} input[name=nombre]:visible`, 'LAURA GÓMEZ PECH');
            await h.opcion(ING, 'Recursos Humanos');
            await h.campo(ING, '¿A qué viene', 'cita').catch(() => {});
            await h.centrar(`${ING} [data-acceso-motivo]`);
        } },
    { archivo: 'recepcion-y-candidatos/caseta-pendientes-esperando.png', cuenta: 'agente', url: '/accesos?pestana=pendientes', vista: { width: 1366, height: 1332 } },
    // Guarda un ingreso que queda pendiente de autorización
    { archivo: 'accesos/mensaje-registrado-pendiente.png', cuenta: 'agente', url: '/accesos', vista: { width: 1366, height: 420 },
        antes: async (h) => {
            await ingreso(h, 'Personal Externo');
            await h.escribir(`${ING} input[name=nombre]:visible`, 'INGRID SOLÍS PECH');
            await h.opcion(ING, 'Visita a persona o área');
            await h.escribir(`${ING} [data-visita-colaborador] [data-a-quien-texto]`, 'MANTENIMIENTO');
            await h.esperar(900);
            await h.p.locator(`${ING} [data-visita-colaborador] [data-sugerencias] button, ${ING} [data-visita-colaborador] [data-sugerencias] li`).first().click().catch(() => {});
            await h.esperar(600);
            await Promise.all([h.p.waitForNavigation(), h.p.click(`${ING} [data-boton-registrar]`)]);
            await h.listo(); await h.arriba();
        } },
];

const RC = 'recepcion-y-candidatos';
const ficha = (nombre) => async (h) => { await h.ir('/candidatos'); await h.enlaceDe('/candidatos/', nombre); };
const fichaY = (nombre, mas) => async (h) => { await ficha(nombre)(h); await mas(h); };

const candidatos = [
    { archivo: `${RC}/autorizaciones-bandeja-jefe.png`, cuenta: 'jefe', url: '/autorizaciones', vista: 'alto' },
    { archivo: `${RC}/autorizaciones-delegar.png`, cuenta: 'rh', url: '/autorizaciones',
        antes: async (h) => { await h.boton(/Delegar por otra persona/); await h.llenar('#dialogoDelegar [name=motivo]', 'Vacaciones, junta fuera'); } },
    { archivo: `${RC}/autorizaciones-responsables.png`, cuenta: 'rh', url: '/autorizaciones/responsables', vista: { width: 1366, height: 1358 } },
    { archivo: `${RC}/responsables-sin-quien-autorice.png`, cuenta: 'rh', url: '/autorizaciones/responsables', vista: { width: 1366, height: 1358 },
        recorte: async (h, ruta) => { await h.p.locator('main .fichas-grid, main [class*="grid"]').filter({ hasText: 'Elegir responsables' }).last().screenshot({ path: ruta }); } },
    { archivo: `${RC}/campana-rh.png`, cuenta: 'rh', url: '/', vista: 'alto', antes: async (h) => { await campana(h); } },
    { archivo: `${RC}/candidato-canalizar.png`, cuenta: 'rh', vista: 'alto',
        antes: fichaY('Juan Pérez Chan', async (h) => { await h.dialogo('dialogoCanalizar'); }) },
    { archivo: `${RC}/candidato-considerar-rechazar.png`, cuenta: 'rh', vista: 'alto',
        antes: fichaY('Laura Gómez Poot', async (h) => {
            await h.clic('[data-etapa-destino="rechazado"]');
            await h.llenar('#dialogoEtapa textarea', 'No cubre el turno nocturno que pide la vacante.');
        }) },
    { archivo: `${RC}/candidato-contratar.png`, cuenta: 'rh', vista: 'alto', antes: fichaY('Mariela Canché Dzib', async (h) => { await h.dialogo('dialogoContratar'); }) },
    { archivo: `${RC}/candidato-error-en-dialogo.png`, cuenta: 'rh', vista: 'alto',
        antes: fichaY('Laura Gómez Poot', async (h) => { await h.clic('[data-etapa-destino="rechazado"]'); await h.enviarSinValidar('#dialogoEtapa form'); }) },
    { archivo: `${RC}/candidato-evaluacion-rh.png`, cuenta: 'rh', vista: 'alto', antes: fichaY('Luis Ángel Chi Canul', async (h) => { await h.dialogo('dialogoEvaluacion'); }) },
    { archivo: `${RC}/candidato-ficha-canalizado.png`, cuenta: 'rh', vista: { width: 1326, height: 756 }, antes: ficha('Juan Pérez Chan') },
    { archivo: `${RC}/candidato-ficha-evaluado.png`, cuenta: 'rh', vista: { width: 1326, height: 940 }, antes: ficha('Laura Gómez Poot') },
    { archivo: `${RC}/candidato-ficha-revision.png`, cuenta: 'rh', vista: 'alto', antes: ficha('Miguel Ángel Cauich Pech') },
    { archivo: `${RC}/candidato-postulaciones.png`, cuenta: 'rh', vista: 'alto',
        antes: fichaY('Fernanda Ruiz Kú', async (h) => { await h.centrar('text=Postulaciones'); }) },
    { archivo: `${RC}/candidato-solicitud-incompleta.png`, cuenta: 'rh', vista: 'alto', antes: ficha('Mariana Ek Balam') },
    { archivo: `${RC}/candidatos-lista.png`, cuenta: 'rh', url: '/candidatos', vista: 'alto' },
    { archivo: `${RC}/candidatos-nuevo-dialogo.png`, cuenta: 'rh', url: '/candidatos', antes: async (h) => { await h.dialogo('dialogoCandidato'); } },
    { archivo: `${RC}/candidatos-nuevo-duplicado.png`, cuenta: 'rh', url: '/candidatos', vista: 'alto',
        antes: async (h) => { await h.dialogo('dialogoCandidato'); await h.escribir('#dialogoCandidato [name=telefono]', '9982989632'); await h.p.keyboard.press('Tab'); await h.esperar(1200); await h.centrar('#dialogoCandidato [name=telefono]'); } },
    { archivo: `${RC}/entrevistas-lista.png`, cuenta: 'jefedepto', url: '/entrevistas', vista: 'alto' },
    { archivo: `${RC}/entrevistar.png`, cuenta: 'jefedepto', url: '/entrevistas', vista: 'alto', antes: async (h) => { await h.enlaceDe('/entrevistas/', 'Juan Pérez Chan'); } },
    { archivo: `${RC}/entrevistar-evaluacion.png`, cuenta: 'jefedepto', url: '/entrevistas', vista: 'alto',
        antes: async (h) => { await h.enlaceDe('/entrevistas/', 'Juan Pérez Chan'); },
        recorte: async (h, ruta) => { await h.p.locator('main section, main .tarjeta, main form').filter({ hasText: 'Tu evaluación' }).last().screenshot({ path: ruta }); } },
    { archivo: `${RC}/kiosco-tableta-qr.png`, cuenta: 'rh', url: '/rh/recepcion/kiosco',
        antes: async (h) => { await h.botonDe('QR', 'Karla Pérez Uc'); await h.esperar(1000); } },
    { archivo: `${RC}/menu-recursos-humanos-jefe.png`, cuenta: 'jefedepto', url: '/entrevistas', vista: 'alto',
        antes: async (h) => { await h.p.locator('nav').getByText('Recursos Humanos').first().click(); await h.esperar(600); } },
    { archivo: `${RC}/mis-pendientes-jefe.png`, cuenta: 'jefedepto', url: '/', vista: 'alto', antes: async (h) => { await h.clic('[data-mis-pendientes], button[aria-label*="pendientes"]'); await h.esperar(700); } },
    { archivo: `${RC}/mis-pendientes-rh.png`, cuenta: 'rh', url: '/', vista: 'alto', antes: async (h) => { await h.clic('[data-mis-pendientes], button[aria-label*="pendientes"]'); await h.esperar(700); } },
    { archivo: `${RC}/movil-candidato-ficha.png`, cuenta: 'rh', vista: 'celular1x', antes: ficha('Juan Pérez Chan') },
    { archivo: `${RC}/movil-recepcion.png`, cuenta: 'rh', url: '/rh/recepcion', vista: 'celular1x' },
    { archivo: `${RC}/noche-recepcion.png`, cuenta: 'rh', url: '/rh/recepcion', modo: 'noche' },
    { archivo: `${RC}/recepcion-ajustes.png`, cuenta: 'rh', url: '/rh/recepcion/ajustes', vista: { width: 1366, height: 1038 } },
    { archivo: `${RC}/recepcion-criterios.png`, cuenta: 'rh', url: '/candidatos/criterios', vista: 'alto' },
    { archivo: `${RC}/recepcion-metricas.png`, cuenta: 'rh', url: '/rh/recepcion/metricas', vista: { width: 1366, height: 1238 } },
    { archivo: `${RC}/recepcion-panel.png`, cuenta: 'rh', url: '/rh/recepcion', vista: { width: 1366, height: 1543 } },
    // Formulario que el candidato llena en su celular (kiosco): se abre con el enlace del QR
    { archivo: `${RC}/kiosco-formulario.png`, cuenta: 'rh', url: '/rh/recepcion/kiosco', vista: 'celular',
        antes: async (h) => { await h.botonDe('QR', 'Karla Pérez Uc'); await h.esperar(1000); await h.irAlKiosco(); } },
    { archivo: 'solicitud-empleo/kiosco-solicitud-movil.png', cuenta: 'rh', url: '/rh/recepcion/kiosco', vista: 'celular',
        antes: async (h) => { await h.botonDe('QR', 'Karla Pérez Uc'); await h.esperar(1000); await h.irAlKiosco(); } },
    { archivo: 'solicitud-empleo/kiosco-firma-movil.png', cuenta: 'rh', url: '/rh/recepcion/kiosco', vista: 'celular',
        antes: async (h) => { await h.botonDe('QR', 'Karla Pérez Uc'); await h.esperar(1000); await h.irAlKiosco(); await h.marcar('input[type=checkbox][name*="declar"], input[type=checkbox][name*="acepta"]').catch(() => {}); await h.firmar('canvas'); },
        recorte: async (h, ruta) => { await h.p.locator('fieldset, section, .tarjeta').filter({ hasText: 'firma' }).filter({ has: h.p.locator('canvas') }).last().screenshot({ path: ruta }); } },
    { archivo: 'solicitud-empleo/editar-solicitud.png', cuenta: 'rh', antes: fichaY('Mariela Canché Dzib', async (h) => { await h.dialogo('dialogoCv'); }) },
    { archivo: 'solicitud-empleo/ficha-solicitud.png', cuenta: 'rh', antes: ficha('Mariela Canché Dzib') },
    { archivo: 'solicitud-empleo/hoja-impresa.png', cuenta: 'rh', antes: fichaY('Mariela Canché Dzib', async (h) => { await h.ir(new URL(h.p.url()).pathname + '/solicitud'); }), recorte: 'completa' },
];

const vacantes = [
    { archivo: 'vacantes/vacantes-lista.png', cuenta: 'rh', url: '/vacantes' },
    { archivo: 'vacantes/vacantes-noche.png', cuenta: 'rh', url: '/vacantes', modo: 'noche' },
    { archivo: 'vacantes/ajustes-bolsa.png', cuenta: 'rh', url: '/vacantes', antes: async (h) => { await h.dialogo('dialogoBolsa'); } },
    { archivo: 'vacantes/candidatos-de-la-vacante.png', cuenta: 'rh', url: '/vacantes/4/candidatos', vista: 'alto' },
    { archivo: 'vacantes/cartel.png', cuenta: 'rh', url: '/vacantes/1/cartel', vista: { width: 1366, height: 1078 } },
    { archivo: 'vacantes/cerrar-vacante.png', cuenta: 'rh', url: '/vacantes', antes: async (h) => { await h.dialogo('dialogoCerrar1'); } },
    { archivo: 'vacantes/empleos-lista-movil.png', url: '/empleos/hotel-ejemplo-tiu2e2', vista: 'celular' },
    { archivo: 'vacantes/empleos-detalle-movil.png', url: '/empleos/hotel-ejemplo-tiu2e2', vista: 'celular', antes: async (h) => { await h.enlaceDe('/empleos/hotel-ejemplo-tiu2e2/', 'Camarista'); } },
    { archivo: 'vacantes/empleos-postular-movil.png', url: '/empleos/hotel-ejemplo-tiu2e2', vista: 'celular1x',
        antes: async (h) => { await h.enlaceDe('/empleos/hotel-ejemplo-tiu2e2/', 'Camarista'); await h.enlaceDe('/postular', 'Postularme'); }, recorte: 'completa' },
    { archivo: 'vacantes/nueva-vacante.png', cuenta: 'jefedepto', url: '/vacantes', vista: 'alto',
        antes: async (h) => { await h.dialogo('dialogoVacante'); await h.elegirTexto('#dialogoVacante [name=puesto_id]', 'Agente de Seguridad').catch(() => {}); await h.llenar('#dialogoVacante [name=titulo]', 'Agente de Seguridad'); } },
    { archivo: 'vacantes/vacantes-agente-movil.png', cuenta: 'agente', url: '/vacantes', vista: 'celular' },
];

// Recorta el elemento más interno (sección, tarjeta, fieldset…) que contiene «texto»
const seccion = (texto, dentro = 'main', altoMax = 0, primera = false) => async (h, ruta) => {
    const todas = h.p.locator(`${dentro} :is(section, fieldset, details, .tarjeta, .card, .ficha-card, .res-bloque, article, form)`).filter({ hasText: texto });
    const el = primera ? todas.first() : todas.last();
    await el.evaluate((e) => e.scrollIntoView({ block: 'start' }));
    await h.esperar(250);
    if (!altoMax) { await el.screenshot({ path: ruta }); return; }
    const b = await el.evaluate((e) => { const r = e.getBoundingClientRect(); return { x: r.left + scrollX, y: r.top + scrollY, width: r.width, height: r.height }; });
    await h.p.screenshot({ path: ruta, fullPage: true, clip: { x: b.x, y: b.y, width: b.width, height: Math.min(b.height, altoMax) } });
};
const campana = async (h) => {
    await h.p.locator('.btn-campana').filter({ visible: true }).first().click();
    await h.p.waitForFunction(() => [...document.querySelectorAll('.campana-lista')].some((l) => l.offsetParent && !/Cargando/.test(l.textContent)), null, { timeout: 15000 }).catch(() => {});
    await h.esperar(500);
};
const panelCampana = async (h, ruta) => { await h.p.locator('.campana-panel').filter({ visible: true }).first().screenshot({ path: ruta }); };
const EXP = '#dialogoExpediente';

const novedades = [
    { archivo: 'novedades/lista.png', cuenta: 'admin', url: '/novedades' },
    { archivo: 'novedades/celular.png', cuenta: 'agente', url: '/novedades', vista: 'celular' },
    { archivo: 'novedades/expediente.png', cuenta: 'admin', url: '/novedades?abrir=1' },
    { archivo: 'novedades/area-heredada.png', cuenta: 'admin', url: '/novedades?abrir=1', vista: { width: 1366, height: 1000 }, recorte: `${EXP} .preguntas-base` },
    { archivo: 'novedades/accidente.png', cuenta: 'admin', url: '/novedades?abrir=2', vista: { width: 1366, height: 1100 },
        antes: async (h) => { await h.clic('#moduloMedico .accordion-button'); await h.esperar(600); }, recorte: '#moduloMedico' },
    { archivo: 'novedades/aviso-turno.png', cuenta: 'admin', url: '/novedades?abrir=1', vista: { width: 1366, height: 1000 },
        antes: async (h) => { await h.campo(EXP, 'Estatus', 'Pendiente de Turno'); },
        recorte: async (h, ruta) => {
            const et = h.p.locator(`${EXP} label`).filter({ hasText: 'Estatus Final' }).first();
            await et.evaluate((e) => e.scrollIntoView({ block: 'center' })); await h.esperar(300);
            const a = await et.boundingBox();
            const b = await h.p.locator(`${EXP} :is(div, p)`).filter({ hasText: 'siguiente turno' }).filter({ visible: true }).last().boundingBox();
            const caja = await h.p.locator(`${EXP} .preguntas-base`).boundingBox();
            await h.p.screenshot({ path: ruta, clip: { x: caja.x, y: a.y - 8, width: caja.width, height: b.y + b.height - a.y + 16 } });
        } },
    { archivo: 'novedades/campana-canalizado.png', cuenta: 'jefe', url: '/', antes: campana, recorte: panelCampana },
    { archivo: 'novedades/nuevo-ticket.png', cuenta: 'agente', url: '/novedades', vista: { width: 1366, height: 968 }, antes: async (h) => { await h.dialogo('dialogoAltaTicket'); }, recorte: D('dialogoAltaTicket') },
    { archivo: 'novedades/ticket-objeto.png', cuenta: 'agente', url: '/novedades', vista: { width: 1366, height: 868 },
        antes: async (h) => {
            await h.dialogo('dialogoAltaTicket');
            await h.p.locator('#dialogoAltaTicket [name=sede_id]').selectOption({ index: 1 }).catch(() => {});
            await h.elegirTexto('#dialogoAltaTicket [name=categoria]', 'Lost');
            await h.llenar('#dialogoAltaTicket [name=lf_objeto]', 'PARAGUAS NEGRO');
            await h.llenar('#dialogoAltaTicket [name=lf_ubicacion_bodega]', 'ANAQUEL 3');
        }, recorte: D('dialogoAltaTicket') },
    { archivo: 'novedades/pendiente-turno.png', cuenta: 'admin', url: '/novedades', recorte: recorteDe('.ficha-card, article, .tarjeta-ticket, .card', '#00002') },
    { archivo: 'novedades/pendiente-turno-celular.png', cuenta: 'admin', url: '/novedades', vista: 'celular1x', recorte: recorteDe('.ficha-card, article, .tarjeta-ticket, .card', '#00002') },
    { archivo: 'novedades/tickets-departamento.png', cuenta: 'fracAdmin', url: '/novedades/departamento', vista: { width: 1280, height: 640 } },
];

const lostFound = [
    { archivo: 'lost-found-y-robo/archivo.png', cuenta: 'admin', url: '/lost-found' },
    { archivo: 'lost-found-y-robo/celular.png', cuenta: 'agente', url: '/lost-found', vista: 'celular' },
    { archivo: 'lost-found-y-robo/agregar-objeto.png', cuenta: 'admin', url: '/lost-found', antes: async (h) => { await h.desplegar('main details'); }, recorte: async (h, ruta) => {
            const d = h.p.locator('main details').filter({ hasText: 'Agregar el objeto' }).first();
            await d.locator('xpath=..').screenshot({ path: ruta });
        } },
    { archivo: 'lost-found-y-robo/cerrar-entregar.png', cuenta: 'admin', url: '/lost-found', antes: async (h) => { await h.botonDe('Cerrar / Entregar', 'LF-000001'); }, recorte: D('dialogoCerrarArticulo') },
    { archivo: 'lost-found-y-robo/ficha.png', cuenta: 'admin', url: '/lost-found/articulos/1' },
    { archivo: 'lost-found-y-robo/ficha-de-hechos.png', cuenta: 'admin', url: '/robos', vista: { width: 1366, height: 1400 },
        antes: async (h) => {
            await h.ir('/novedades?abrir=8'); await h.esperar(1500);
            const href = await h.p.locator('a[href*="ficha-hechos"]').first().getAttribute('href');
            await h.p.goto(href); await h.listo();
        }, recorte: 'main' },
    { archivo: 'lost-found-y-robo/registrar-objeto.png', cuenta: 'agente', url: '/lost-found', vista: { width: 1366, height: 868 },
        antes: async (h) => {
            await h.dialogo('dialogoObjetoLf');
            await h.p.locator('#dialogoObjetoLf [name=sede_id]').selectOption({ index: 1 }).catch(() => {});
            await h.llenar('#dialogoObjetoLf [name=lf_objeto]', 'CARTERA CAFÉ');
            await h.elegirTexto('#dialogoObjetoLf [name=lf_tipo_valor]', 'Alto');
            await h.llenar('#dialogoObjetoLf [name=ubicacion]', 'LOBBY, JUNTO A LOS SILLONES');
            await h.llenar('#dialogoObjetoLf [name=lf_ubicacion_bodega]', 'CAJA B');
            await h.llenar('#dialogoObjetoLf [name=reportado_por]', 'ANDREA RUIZ');
        }, recorte: D('dialogoObjetoLf') },
    { archivo: 'lost-found-y-robo/robos.png', cuenta: 'admin', url: '/robos' },
    // Guarda un objeto encontrado
    { archivo: 'lost-found-y-robo/objeto-registrado.png', cuenta: 'agente', url: '/lost-found', vista: { width: 1366, height: 520 },
        antes: async (h) => {
            await h.dialogo('dialogoObjetoLf');
            await h.p.locator('#dialogoObjetoLf [name=sede_id]').selectOption({ index: 1 }).catch(() => {});
            await h.llenar('#dialogoObjetoLf [name=lf_objeto]', 'CARTERA CAFÉ');
            await h.elegirTexto('#dialogoObjetoLf [name=lf_tipo_valor]', 'Alto');
            await h.llenar('#dialogoObjetoLf [name=ubicacion]', 'LOBBY, JUNTO A LOS SILLONES');
            await h.llenar('#dialogoObjetoLf [name=reportado_por]', 'ANDREA RUIZ');
            await Promise.all([h.p.waitForNavigation(), h.p.locator('#dialogoObjetoLf [type=submit]').last().click()]);
            await h.listo(); await h.arriba();
        } },
];

const pases = [
    { archivo: 'pases-salida/lista.png', cuenta: 'admin', url: '/pases-salida' },
    { archivo: 'pases-salida/celular.png', cuenta: 'admin', url: '/pases-salida', vista: 'celular' },
    { archivo: 'pases-salida/aprobar.png', cuenta: 'jefedepto', url: '/pases-salida/1', vista: { width: 1366, height: 868 }, antes: async (h) => { await h.dialogo('dialogoAprobarPase'); }, recorte: D('dialogoAprobarPase') },
    { archivo: 'pases-salida/caseta-salida.png', cuenta: 'agente', url: '/pases-salida/14', vista: { width: 1366, height: 968 },
        antes: async (h) => { await h.dialogo('dialogoPasoPase'); }, recorte: D('dialogoPasoPase') },
    { archivo: 'pases-salida/caseta-nuevo-regreso.png', cuenta: 'agente', url: '/pases-salida/14', vista: { width: 1366, height: 968 },
        antes: async (h) => { await h.dialogo('dialogoPasoPase'); }, recorte: seccion('ya pasó', '#dialogoPasoPase') },
    { archivo: 'pases-salida/circuito-firmas-caseta.png', cuenta: 'admin', url: '/pases-salida/circuito', vista: { width: 1366, height: 1200 }, antes: async (h) => { await h.desplegar('main details'); }, recorte: seccion('Firmas en caseta') },
    { archivo: 'pases-salida/circuito-firmas-que-tardan.png', cuenta: 'admin', url: '/pases-salida/circuito', recorte: seccion('Firmas que tardan') },
    { archivo: 'pases-salida/circuito-valor.png', cuenta: 'admin', url: '/pases-salida/circuito', vista: { width: 1366, height: 1200 },
        antes: async (h) => { await h.desplegar('main details'); }, recorte: seccion('Condición de valor') },
    { archivo: 'pases-salida/fechas-programadas.png', cuenta: 'admin', url: '/pases-salida', vista: { width: 1366, height: 968 },
        antes: async (h) => { await h.dialogo('dialogoNuevoPase'); await h.desplegar('#dialogoNuevoPase details'); },
        recorte: async (h, ruta) => { const el = h.p.locator('#dialogoNuevoPase [name=fecha_salida_programada]').locator('xpath=ancestor::div[contains(@class,"row") or contains(@class,"grid")][1]'); await el.scrollIntoViewIfNeeded(); await el.screenshot({ path: ruta }); } },
    { archivo: 'pases-salida/ficha.png', cuenta: 'admin', url: '/pases-salida/13', vista: { width: 1366, height: 927 } },
    { archivo: 'pases-salida/imprimir.png', cuenta: 'admin', url: '/pases-salida/13/imprimir', recorte: 'main, .hoja, body > div' },
    { archivo: 'pases-salida/jefe-departamento.png', cuenta: 'admin', url: '/pases-salida/1', recorte: async (h, ruta) => { const el = h.p.locator('ol.lista-aprobaciones').filter({ visible: true }).first(); await el.scrollIntoViewIfNeeded(); await el.screenshot({ path: ruta }); } },
    { archivo: 'pases-salida/llegan-a-mi-sede.png', cuenta: 'agente2', url: '/pases-salida', vista: { width: 1366, height: 740 }, antes: async (h) => { await h.texto('Llegan a mi sede'); } },
    { archivo: 'pases-salida/nuevo-pase.png', cuenta: 'admin', url: '/pases-salida', vista: { width: 1366, height: 968 }, antes: async (h) => { await h.dialogo('dialogoNuevoPase'); }, recorte: D('dialogoNuevoPase') },
    { archivo: 'pases-salida/recorrido.png', cuenta: 'admin', url: '/pases-salida/13', recorte: seccion('Recorrido del pase') },
    { archivo: 'pases-salida/recorrido-vencido.png', cuenta: 'admin', url: '/pases-salida/12', recorte: seccion('Recorrido del pase') },
    { archivo: 'pases-salida/recorrido-celular.png', cuenta: 'admin', url: '/pases-salida/13', vista: 'celular', recorte: seccion('Recorrido del pase') },
    { archivo: 'pases-salida/reloj-firma.png', cuenta: 'admin', url: '/pases-salida', recorte: recorteDe('.ficha-card, article, .tarjeta-pase, .card', 'Esperando firma') },
];

const prestamo = [
    { archivo: 'prestamo-llaves/llaves-en-uso.png', cuenta: 'admin', url: '/prestamo-llaves' },
    { archivo: 'prestamo-llaves/celular.png', cuenta: 'agente', url: '/prestamo-llaves', vista: 'celular' },
    { archivo: 'prestamo-llaves/historial-entregas.png', cuenta: 'admin', url: '/prestamo-llaves', antes: async (h) => { await h.texto('Historial de Entregas'); } },
    { archivo: 'prestamo-llaves/historial-llave.png', cuenta: 'admin', url: '/prestamo-llaves', antes: async (h) => { await h.botonDe('[data-historial-llave], button[title*="istorial"]', 'HDP-ALBERCA'); }, recorte: D('dialogoHistorialLlave') },
    { archivo: 'prestamo-llaves/no-regreso.png', cuenta: 'admin', url: '/prestamo-llaves', recorte: recorteDe('.ficha-card, article, .card, .tarjeta-llave', 'NO REGRES') },
    { archivo: 'prestamo-llaves/prestar.png', cuenta: 'agente', url: '/prestamo-llaves', vista: { width: 1366, height: 868 }, antes: async (h) => { await h.dialogo('dialogoPrestarLlave'); }, recorte: D('dialogoPrestarLlave') },
    { archivo: 'prestamo-llaves/semaforo.png', cuenta: 'agente', url: '/prestamo-llaves' },
    // Recibe una llave escaneándola
    { archivo: 'prestamo-llaves/recibir-escaneando.png', cuenta: 'agente', url: '/prestamo-llaves', vista: { width: 1366, height: 560 },
        antes: async (h) => {
            const c = h.p.locator('main input[type=text], main input[type=search]').filter({ visible: true }).first();
            await c.fill('HDC-BOD-01'); await c.press('Enter');
            await h.p.waitForLoadState('networkidle'); await h.listo(); await h.arriba();
        } },
];

const responsivas = [
    { archivo: 'responsivas/en-campo.png', cuenta: 'agente', url: '/responsivas' },
    { archivo: 'responsivas/celular.png', cuenta: 'agente', url: '/responsivas', vista: 'celular' },
    { archivo: 'responsivas/hoja.png', cuenta: 'admin', url: '/responsivas/2/hoja' },
    { archivo: 'responsivas/nuevo-resguardo.png', cuenta: 'agente', url: '/responsivas', vista: { width: 1366, height: 868 }, antes: async (h) => { await h.dialogo('dialogoNuevoResguardo'); }, recorte: D('dialogoNuevoResguardo') },
    { archivo: 'responsivas/recibir-equipo.png', cuenta: 'agente', url: '/responsivas', vista: { width: 1366, height: 868 },
        antes: async (h) => {
            await h.desplegar('main details');
            await h.botonDe('Dañado o falta', 'Radio de Comunicación');
            await h.clic('#dialogoRecibirEquipo label.opcion-recibir.danado');
            await h.llenar('#dialogoRecibirEquipo [name=nota]', 'Antena doblada');
        }, recorte: D('dialogoRecibirEquipo') },
    { archivo: 'responsivas/recibir.png', cuenta: 'agente', url: '/responsivas', antes: async (h) => { await h.desplegar('main details'); }, recorte: recorteDe('.ficha-card, article, .card', 'CENRES-000002') },
    { archivo: 'responsivas/turno.png', cuenta: 'agente', url: '/responsivas', recorte: recorteDe('.ficha-card, article, .card', 'CENRES-000002') },
];

const vouchers = [
    { archivo: 'vouchers/lista.png', cuenta: 'admin', url: '/vouchers' },
    { archivo: 'vouchers/celular.png', cuenta: 'admin', url: '/vouchers', vista: 'celular' },
    { archivo: 'vouchers/recuperado.png', cuenta: 'admin', url: '/vouchers', vista: { width: 1366, height: 868 }, antes: async (h) => { await h.boton('Recuperado'); }, recorte: D('dialogoRecuperadoVoucher') },
    { archivo: 'vouchers/voucher.png', cuenta: 'admin', url: '/vouchers', antes: async (h) => { await h.enlaceDe('/imprimir', 'HDP-MANT-02').catch(async () => { await h.enlaceDe('/imprimir', 'VR-'); }); } },
];

const transporte = [
    { archivo: 'transporte/lista.png', cuenta: 'admin', url: '/transporte' },
    { archivo: 'transporte/celular.png', cuenta: 'agente', url: '/transporte', vista: 'celular' },
    { archivo: 'transporte/registrar.png', cuenta: 'agente', url: '/transporte', vista: { width: 1366, height: 828 }, antes: async (h) => { await h.dialogo('dialogoAltaTransporte'); }, recorte: D('dialogoAltaTransporte') },
    { archivo: 'transporte/taxi.png', cuenta: 'agente', url: '/transporte', vista: { width: 1366, height: 828 },
        antes: async (h) => { await h.dialogo('dialogoAltaTransporte'); await h.opcion('#dialogoAltaTransporte', 'no llegó'); await h.centrar('#dialogoAltaTransporte [name=estatus]'); }, recorte: D('dialogoAltaTransporte') },
    { archivo: 'transporte/reportes.png', cuenta: 'admin', url: '/transporte/reportes', vista: { width: 1366, height: 1000 } },
    { archivo: 'transporte/vale.png', cuenta: 'admin', url: '/transporte/50/vale', vista: { width: 1366, height: 1000 } },
    { archivo: 'transporte/vobo-campana.png', cuenta: 'admin', url: '/transporte', antes: campana, recorte: panelCampana },
];

const recorridos = [
    { archivo: 'recorridos-pc/lista.png', cuenta: 'admin', url: '/recorridos-pc' },
    { archivo: 'recorridos-pc/detalle.png', cuenta: 'admin', url: '/recorridos-pc', antes: async (h) => { await h.enlaceDe('/recorridos-pc/', 'Recorrido #00002'); } },
    { archivo: 'recorridos-pc/punto.png', cuenta: 'agente', url: '/recorridos-pc', antes: async (h) => { await h.enlaceDe('/recorridos-pc/', 'Recorrido #00003'); } },
    { archivo: 'recorridos-pc/celular.png', cuenta: 'agente', url: '/recorridos-pc', vista: 'celular', antes: async (h) => { await h.enlaceDe('/recorridos-pc/', 'Recorrido #00003'); } },
    { archivo: 'recorridos-pc/nuevo.png', cuenta: 'agente', url: '/recorridos-pc', antes: async (h) => { await h.dialogo('dialogoNuevoRecorrido'); }, recorte: D('dialogoNuevoRecorrido') },
    { archivo: 'recorridos-pc/reporte.png', cuenta: 'admin', url: '/recorridos-pc/reporte' },
];

const procedimientos = [
    { archivo: 'procedimientos/lista.png', cuenta: 'admin', url: '/procedimientos' },
    { archivo: 'procedimientos/celular.png', cuenta: 'agente', url: '/procedimientos', vista: 'celular' },
    { archivo: 'procedimientos/ficha.png', cuenta: 'admin', url: '/procedimientos/1' },
    { archivo: 'procedimientos/hoja.png', cuenta: 'admin', url: '/procedimientos/1/imprimir' },
    { archivo: 'procedimientos/lectura.png', cuenta: 'agente', url: '/procedimientos/1/leer', vista: 'celular' },
    { archivo: 'procedimientos/nuevo.png', cuenta: 'admin', url: '/procedimientos', vista: { width: 1366, height: 968 },
        antes: async (h) => {
            await h.dialogo('dialogoNuevoProcedimiento');
            await h.llenar('#dialogoNuevoProcedimiento [name=clave]', 'PRO-006');
            await h.elegirTexto('#dialogoNuevoProcedimiento [name=categoria_id]', 'Emergencias');
        }, recorte: D('dialogoNuevoProcedimiento') },
];

const RN = '/reservas/nueva';
const reservas = [
    { archivo: 'reservas/areas.png', cuenta: 'admin', url: '/reservas', vista: 'alto' },
    { archivo: 'reservas/sedes.png', cuenta: 'admin', url: '/reservas', vista: 'alto' },
    { archivo: 'reservas/calendario.png', cuenta: 'admin', url: '/reservas/calendario', vista: 'alto' },
    { archivo: 'reservas/configuracion.png', cuenta: 'admin', url: '/reservas/configuracion', vista: 'alto' },
    { archivo: 'reservas/informes.png', cuenta: 'admin', url: '/reservas/informes', vista: 'alto' },
    { archivo: 'reservas/mias.png', cuenta: 'admin', url: '/reservas/mis-reservas', vista: 'alto' },
    { archivo: 'reservas/ordenes.png', cuenta: 'jefe', url: '/reservas/ordenes', vista: 'alto' },
    { archivo: 'reservas/por-aprobar.png', cuenta: 'jefedepto', url: '/reservas/por-aprobar', vista: 'alto' },
    { archivo: 'reservas/movil-areas.png', cuenta: 'admin', url: '/reservas', vista: 'celular', antes: async (h) => { await h.centrar('text=Reservar'); } },
    { archivo: 'reservas/zona-interiores.png', cuenta: 'admin', url: '/reservas', vista: 'alto', antes: async (h) => { await h.enlaceDe('/reservas', 'Entrar a sus espacios'); } },
    { archivo: 'reservas/area-interiores.png', cuenta: 'admin', url: '/reservas/configuracion', vista: 'alto', antes: async (h) => { await h.enlaceDe('/reservas/configuracion/areas/', 'Centro de negocios'); } },
    { archivo: 'reservas/catalogos-baja.png', cuenta: 'admin', url: '/reservas/configuracion', vista: 'alto', antes: async (h) => { await h.p.locator('main :is(a, button)').filter({ hasText: /dados de baja/i }).first().click().catch(() => {}); await h.listo(); await h.p.locator('main h2, main h3').filter({ hasText: 'Montajes' }).first().evaluate((e) => e.scrollIntoView({ block: 'start' })); } },
    { archivo: 'reservas/dialogo-agregar.png', cuenta: 'admin', url: '/reservas/configuracion', vista: 'alto', antes: async (h) => { await h.dialogo('dialogoAgregarArea'); await h.esperar(800); } },
    { archivo: 'reservas/dialogo-rechazar.png', cuenta: 'jefedepto', url: '/reservas/por-aprobar', vista: { width: 1366, height: 868 },
        antes: async (h) => {
            await h.p.locator('main a').filter({ hasText: 'Capacitación anual' }).first().click(); await h.listo();
            const id = await h.p.evaluate(() => [...document.querySelectorAll('dialog[id^="dialogoRechazar"]')].map((d) => d.id)[0]); await h.dialogo(id); await h.p.locator('dialog[open] input[type=radio]').first().check({ force: true }).catch(() => {}); },
        recorte: 'dialog[open]' },
    { archivo: 'reservas/dialogo-servicio.png', cuenta: 'admin', url: '/reservas/configuracion', vista: { width: 1366, height: 868 }, antes: async (h) => { await h.dialogoDe('dialogoEditar-servicios', 'Coffee break'); }, recorte: D('dialogoEditar-servicios') },
    { archivo: 'reservas/servicios-sugeridos.png', cuenta: 'admin', url: '/reservas/configuracion', vista: { width: 1366, height: 1200 }, recorte: seccion('Servicios y requerimientos') },
    { archivo: 'reservas/eliminar-bloqueado.png', cuenta: 'admin', url: '/reservas/configuracion', vista: 'alto',
        antes: async (h) => { await h.botonDe('button[title*="liminar"], button[aria-label*="liminar"]', 'Salón Maya'); await h.listo(); await h.arriba(); } },
    { archivo: 'reservas/orden-cuando.png', cuenta: 'admin', url: RN,
        antes: async (h) => { await h.p.locator('main select').first().selectOption('1'); await h.esperar(600); await h.p.locator('[name=reserva_area_id]').selectOption({ index: 1 }).catch(() => {}); await h.esperar(500); await h.llenar('[name=fecha]', new Date(Date.now() + 14 * 864e5).toISOString().slice(0, 10)); await h.llenar('[name=hora_inicio]', '09:00'); await h.llenar('[name=hora_fin]', '13:30'); await h.llenar('[name=montaje_minutos]', '30'); await h.llenar('[name=desmontaje_minutos]', '15'); await h.llenar('[name=indicaciones_montaje]', 'Tener montaje listo 30 min antes'); },
        recorte: seccion('¿Dónde y cuándo?') },
    { archivo: 'reservas/orden-excepcion-aforo.png', cuenta: 'admin', url: RN,
        antes: async (h) => { await h.p.locator('main select').first().selectOption('1'); await h.esperar(600); await h.p.locator('[name=reserva_area_id]').selectOption({ index: 1 }).catch(() => {}); await h.esperar(500); await h.llenar('[name=titulo]', 'Capacitación de inducción'); await h.escribir('[name=asistentes]', '300'); await h.esperar(500); await h.llenar('[name=motivo_excepcion_aforo]', 'Se agregan sillas; lo autorizó la gerencia').catch(() => {}); },
        recorte: seccion('¿Para qué?') },
    { archivo: 'reservas/orden-servicios.png', cuenta: 'admin', url: RN, vista: { width: 1366, height: 1700 },
        antes: async (h) => { for (const n of [1, 3]) { await h.marcar(`[name="servicios[${n}][usar]"]`).catch(() => {}); } },
        recorte: seccion('Servicios por departamento') },
    { archivo: 'reservas/orden-celular.png', cuenta: 'admin', url: RN, vista: 'celular',
        antes: async (h) => { for (const n of [1, 3]) { await h.marcar(`[name="servicios[${n}][usar]"]`).catch(() => {}); } },
        recorte: seccion('Servicios por departamento') },
    { archivo: 'reservas/orden-ficha.png', cuenta: 'admin', url: '/reservas/mis-reservas', vista: { width: 1366, height: 1800 }, antes: async (h) => { await h.enlaceDe('/reservas/', 'Congreso regional'); }, recorte: 'main' },
    { archivo: 'reservas/orden-impresion.png', cuenta: 'admin', url: '/reservas/mis-reservas', antes: async (h) => { await h.enlaceDe('/reservas/', 'Congreso regional'); await h.ir(new URL(h.p.url()).pathname + '/imprimir'); },
        recorte: async (h, ruta) => { await h.p.locator(':is(.hoja, article, section, main > div)').filter({ hasText: 'Orden de evento' }).filter({ hasNotText: 'Imprimir' }).first().screenshot({ path: ruta }); } },
    // Al final: quitan un área de reservas
    // Bloqueos (preparar.php deja uno en la Sala de juntas Ceiba) y el calendario de la sala con sus horas libres
    { archivo: 'reservas/bloqueos.png', cuenta: 'admin', url: '/reservas/bloqueos', vista: 'alto' },
    { archivo: 'reservas/calendario-bloqueo.png', cuenta: 'admin', url: '/reservas/bloqueos', vista: 'alto', antes: async (h) => { await h.enlaceDe('/reservas/calendario', 'Mantenimiento del aire'); } },
    { archivo: 'reservas/calendario-horas-libres.png', cuenta: 'admin', url: '/reservas/bloqueos', vista: 'alto',
        antes: async (h) => { await h.enlaceDe('/reservas/calendario', 'Mantenimiento del aire'); const u = new URL(h.p.url()); await h.ir(u.pathname + u.search + '&vista=dia'); } },
    { archivo: 'reservas/bloqueo-formulario.png', cuenta: 'admin', url: '/reservas/bloqueos/nuevo', vista: { width: 1366, height: 1100 },
        antes: async (h) => {
            await h.p.locator('[name=reserva_area_id]').evaluate((s) => { const o = [...s.options].find((x) => x.text.includes('Salón Maya')); if (o) { s.value = o.value; s.dispatchEvent(new Event('change', { bubbles: true })); } });
            // El día de la «Capacitación anual» de los datos de ejemplo (8 días hábiles después), para que se vea el impacto
            const d = new Date(Date.now() + 8 * 864e5); while (d.getDay() === 0 || d.getDay() === 6) { d.setDate(d.getDate() + 1); }
            const dia = d.toISOString().slice(0, 10);
            await h.p.evaluate((f) => { for (const n of ['fecha_desde', 'fecha_hasta']) { const c = document.querySelector(`[name=${n}]`); c.value = f; c.dispatchEvent(new Event('change', { bubbles: true })); } }, dia);
            await h.llenar('[name=motivo]', 'Mantenimiento del aire acondicionado'); await h.esperar(1500);
        }, recorte: 'main' },
    { archivo: 'reservas/eliminada-archivada.png', cuenta: 'admin', url: '/reservas/configuracion', vista: 'alto',
        antes: async (h) => { await h.botonDe('button[title*="liminar"], button[aria-label*="liminar"]', 'Sala Holbox'); await h.listo(); await h.arriba(); } },
    { archivo: 'reservas/mostrar-quitadas.png', cuenta: 'admin', url: '/reservas/configuracion', vista: 'alto',
        antes: async (h) => { await h.p.locator('main :is(a, button)').filter({ hasText: /quitadas/i }).first().click().catch(() => {}); await h.listo(); await h.p.locator('main h2, main h3').filter({ hasText: 'Áreas reservables' }).first().evaluate((e) => e.scrollIntoView({ block: 'start' })); } },
];

const ARCH = new URL('./archivos/', import.meta.url).pathname;
const VA = '#dialogoVerificarAlta';
const verificar = (texto, camino) => async (h) => {
    await h.botonDe('Verificar', texto);
    await h.esperar(800);
    if (camino) { await h.opcion(VA, camino); }
};

const altas = [
    { archivo: 'altas-por-verificar/01-inicio-aviso-altas-por-verificar.png', cuenta: 'admin', url: '/' },
    { archivo: 'altas-por-verificar/02-vehiculos-pendientes-de-verificar.png', cuenta: 'admin', url: '/vehiculos', antes: async (h) => { await h.p.locator('main button').filter({ hasText: 'Por verificar' }).first().click(); await h.esperar(500); } },
    { archivo: 'altas-por-verificar/03-verificar-vehiculo-es-correcto.png', cuenta: 'admin', url: '/vehiculos', antes: verificar('XTR901C') },
    { archivo: 'altas-por-verificar/04-verificar-vehiculo-ya-existia.png', cuenta: 'admin', url: '/vehiculos', antes: verificar('ABC128A', 'Es el mismo') },
    { archivo: 'altas-por-verificar/05-verificar-vehiculo-rechazar.png', cuenta: 'admin', url: '/vehiculos',
        antes: async (h) => { await verificar('XTR901C', 'Rechazar')(h); await h.llenar(`${VA} [name=motivo_rechazo]`, 'Las placas no existen; capturadas por error.'); } },
    { archivo: 'altas-por-verificar/06-ficha-rechazada.png', cuenta: 'admin', url: '/vehiculos', recorte: recorteDe('.ficha-card', 'ZZZ000') },
    { archivo: 'altas-por-verificar/08-verificar-empresa-ya-existia.png', cuenta: 'admin', url: '/proveedores', vista: 'alto', antes: verificar('Abarrotes del Caribe S.A. de C.V.', 'Es el mismo') },
    { archivo: 'altas-por-verificar/10-verificar-persona-es-correcto.png', cuenta: 'admin', url: '/personas', vista: 'alto', antes: verificar('Laura Mendez Rios') },
    { archivo: 'altas-por-verificar/13-agente-ve-padron-sin-verificar.png', cuenta: 'agente', url: '/vehiculos' },
    { archivo: 'altas-por-verificar/15-accesos-es-alguno-de-estos-placas.png', cuenta: 'agente', url: '/accesos', vista: 'alto',
        antes: async (h) => {
            await ingreso(h, 'Personal Externo');
            await h.opcion(ING, 'Vehículo (Auto');
            await h.escribir('#ingreso_placas', 'ABC-12-3B'); await h.esperar(1500);
            await h.centrar('#ingreso_placas');
        } },
    { archivo: 'altas-por-verificar/17-alta-rapida-persona-parecidos.png', cuenta: 'agente', url: '/accesos', vista: 'alto',
        antes: async (h) => { await h.dialogo('dialogoRegistroRapidoPersona'); await h.escribir('#dialogoRegistroRapidoPersona [name=nombre_completo]', 'Ricardo Ortega'); await h.esperar(1200); } },
    { archivo: 'altas-por-verificar/19-movil-inicio.png', cuenta: 'admin', url: '/', vista: 'celular' },
    { archivo: 'altas-por-verificar/21-movil-verificar-ya-existia.png', cuenta: 'admin', url: '/vehiculos', vista: 'celular', antes: verificar('ABC128A', 'Es el mismo') },
    { archivo: 'altas-por-verificar/23-noche-verificar.png', cuenta: 'admin', url: '/vehiculos', modo: 'noche', antes: verificar('XTR901C') },
    { archivo: 'altas-por-verificar/25-sol-verificar.png', cuenta: 'admin', url: '/vehiculos', modo: 'sol', antes: verificar('XTR901C') },
    { archivo: 'altas-por-verificar/26-error-en-dialogo.png', cuenta: 'admin', url: '/vehiculos',
        antes: async (h) => { await verificar('XTR901C', 'Rechazar')(h); await h.enviarSinValidar(`${VA} form`); } },
    { archivo: 'altas-por-verificar/40-bandeja-unica.png', cuenta: 'admin', url: '/altas-por-verificar?pestana=colaboradores', vista: { width: 1366, height: 900 }, recorte: 'main' },
    { archivo: 'antes-de-dar-de-baja/revision.png', cuenta: 'admin', url: '/antes-de-dar-de-baja/colaboradores/5', recorte: 'main' },
];

const asistencia = [
    { archivo: 'asistencia/1-asistencia-del-dia.png', cuenta: 'admin', url: '/asistencia', vista: { width: 1280, height: 1196 } },
    { archivo: 'asistencia/2-justificar.png', cuenta: 'admin', url: '/asistencia', vista: { width: 1280, height: 900 },
        antes: async (h) => {
            await h.dialogo('dialogoJustificar');
            await h.elegir('#justificar_motivo', 'permiso').catch(() => {});
            await h.llenar('#justificar_nota', 'Permiso firmado por su jefa').catch(() => {});
        }, recorte: D('dialogoJustificar') },
    { archivo: 'asistencia/3-reporte-por-periodo.png', cuenta: 'admin', url: '/asistencia/periodo', vista: { width: 1280, height: 989 } },
    { archivo: 'asistencia/4-detalle-colaborador.png', cuenta: 'admin', url: '/asistencia/periodo?colaborador=7', vista: { width: 1280, height: 900 }, recorte: '#detalle' },
    { archivo: 'asistencia/5-avanzado.png', cuenta: 'admin', url: '/asistencia', vista: { width: 1280, height: 900 }, antes: async (h) => { await h.desplegar('#ajustes-asistencia'); }, recorte: '#ajustes-asistencia' },
    { archivo: 'asistencia/7-jefe-departamento.png', cuenta: 'jefedepto', url: '/asistencia', vista: 'laptop' },
];

const auditoria = [
    { archivo: 'auditoria/1-bitacora.png', cuenta: 'admin', url: '/auditoria' },
    { archivo: 'auditoria/2-detalle.png', cuenta: 'admin', url: '/auditoria', antes: async (h) => { await h.clic('main [data-detalle-auditoria], main button[title*="etalle"], main button[aria-label*="etalle"]'); await h.esperar(800); } },
    { archivo: 'auditoria/3-celular.png', cuenta: 'admin', url: '/auditoria', vista: 'celular' },
    { archivo: 'auditoria/4-ver-historial.png', cuenta: 'admin', url: '/colaboradores/7/expediente', vista: 'alto',
        antes: async (h) => { const href = await h.p.locator('a[href*="/auditoria"]').first().getAttribute('href'); await h.p.goto(href); await h.listo(); } },
];

const SA = 'superadmin';
const catalogo = [
    { archivo: 'catalogo-comercial/solicitudes.png', cuenta: SA, url: '/catalogo-comercial', vista: 'alto' },
    { archivo: 'catalogo-comercial/planes.png', cuenta: SA, url: '/catalogo-comercial?pestana=planes', vista: 'alto' },
    { archivo: 'catalogo-comercial/plan-nuevo.png', cuenta: SA, url: '/catalogo-comercial/planes/nuevo', vista: { width: 1406, height: 900 } },
    { archivo: 'catalogo-comercial/ajustes.png', cuenta: SA, url: '/catalogo-comercial?pestana=ajustes', vista: { width: 1406, height: 1389 } },
    { archivo: 'catalogo-comercial/solicitud.png', cuenta: SA, url: '/catalogo-comercial', vista: { width: 1406, height: 1025 }, antes: async (h) => { await h.p.goto(await h.p.locator('a[href*="/catalogo-comercial/solicitudes/"]').first().evaluate((a) => a.href)); await h.listo(); } },
    { archivo: 'catalogo-comercial/pagina-publica.png', url: '/', vista: 'alto' },
    { archivo: 'suscripciones/calculadora-anual.png', url: '/planes', vista: { width: 1366, height: 1174 },
        antes: async (h) => { await h.p.locator('#calculadora').evaluate((e) => e.scrollIntoView({ block: 'start' })); await h.opcion('#calculadora', 'Anual').catch(() => {}); await h.p.locator('#calculadora').evaluate((e) => e.scrollIntoView({ block: 'start' })); } },
    { archivo: 'suscripciones/clientes.png', cuenta: SA, url: '/catalogo-comercial?pestana=clientes', vista: { width: 1431, height: 900 } },
    { archivo: 'suscripciones/por-revisar.png', cuenta: SA, url: '/catalogo-comercial?pestana=revisar', vista: { width: 1431, height: 900 } },
    { archivo: 'suscripciones/promociones.png', cuenta: SA, url: '/catalogo-comercial?pestana=promociones', vista: { width: 1431, height: 900 } },
    { archivo: 'suscripciones/prueba-por-aprobar.png', cuenta: SA, url: '/catalogo-comercial?estado=todas', vista: { width: 1366, height: 1000 },
        antes: async (h) => {
            const hrefs = await h.p.locator('a[href*="/catalogo-comercial/solicitudes/"]').evaluateAll((as) => [...new Set(as.map((a) => a.href))]);
            for (const u of hrefs) { await h.p.goto(u); await h.listo(); if (await h.p.getByText('Prueba gratis por aprobar').count()) { return; } }
            throw new Error('Ninguna solicitud tiene una prueba gratis por aprobar');
        }, recorte: async (h, ruta) => { await h.p.locator('h2').filter({ hasText: 'Prueba gratis por aprobar' }).first().locator('xpath=ancestor::*[contains(@class,"tarjeta") or self::section][1]').screenshot({ path: ruta }); } },
    { archivo: 'suscripciones/solo-consulta.png', cuenta: 'centro', url: '/sedes', vista: 'alto' },
    { archivo: 'suscripciones/tu-suscripcion.png', cuenta: 'laguna', url: '/empresas', vista: 'alto' },
];

const CF = '/configuracion';
const configuracion = [
    { archivo: 'configuracion/secciones-indice.png', cuenta: 'admin', url: CF, vista: { width: 1366, height: 860 }, recorte: async (h, ruta) => { await h.p.screenshot({ path: ruta, clip: { x: 32, y: 120, width: 1302, height: 725 } }); } },
    { archivo: 'configuracion/1-correo-y-respaldos.png', cuenta: SA, url: CF, antes: async (h) => { await h.p.locator('#t-correo').evaluate((e) => e.scrollIntoView({ block: 'start' })); await h.p.evaluate(() => window.scrollBy(0, -170)); await h.esperar(300); } },
    { archivo: 'configuracion/5-respaldos.png', cuenta: SA, url: CF, antes: async (h) => { await h.centrar('text=Respaldos de la base de datos'); } },
    { archivo: 'configuracion/plataforma.png', cuenta: SA, url: CF, recorte: seccion('Ajustes de toda la plataforma') },
    { archivo: 'configuracion/version-php.png', cuenta: SA, url: CF, recorte: seccion('Versión de PHP del servidor') },
    { archivo: 'configuracion/4-lost-found.png', cuenta: 'admin', url: CF, antes: async (h) => { await h.p.locator('main h3').filter({ hasText: 'Lost & Found: días de resguardo' }).first().evaluate((e) => e.scrollIntoView({ block: 'center' })); await h.esperar(300); } },
    { archivo: 'configuracion/6-bitacora-novedades.png', cuenta: 'admin', url: CF, vista: { width: 1366, height: 1200 }, recorte: seccion('nombre y hallazgos', 'main', 522) },
    { archivo: 'configuracion/7-interruptor-pases.png', cuenta: 'admin', url: CF, recorte: seccion('Pases de salida: avisos por correo') },
    { archivo: 'configuracion/8-auditoria-su-sede.png', cuenta: 'admin', url: CF, recorte: seccion('qué ve «Su sede»') },
    { archivo: 'configuracion/avisos-resumen.png', cuenta: 'admin', url: CF, recorte: seccion('Cada sección de arriba') },
    { archivo: 'configuracion/caseta-procedimientos.png', cuenta: 'admin', url: CF, recorte: seccion('acuse «Leí y entendí»') },
    { archivo: 'configuracion/caseta-y-activos.png', cuenta: 'admin', url: CF, recorte: seccion('llaves fuera mucho tiempo') },
    { archivo: 'configuracion/restablecer.png', cuenta: 'admin', url: CF, recorte: seccion('llaves fuera mucho tiempo') },
    { archivo: 'configuracion/dos-pasos-politica.png', cuenta: 'admin', url: CF, recorte: seccion('Verificación en dos pasos') },
    { archivo: 'configuracion/equipos-pc-frecuencia.png', cuenta: 'admin', url: CF, antes: async (h) => { await h.desplegar('main details'); }, recorte: seccion('frecuencia de revisión') },
    { archivo: 'configuracion/nombres-menu.png', cuenta: 'admin', url: CF, vista: { width: 1366, height: 1200 }, antes: async (h) => { await h.desplegar('main details'); }, recorte: seccion('Nombres en el menú', 'main', 560) },
    { archivo: 'configuracion/permanencia.png', cuenta: 'admin', url: CF, recorte: seccion('Tiempo de permanencia en sitio') },
    { archivo: 'configuracion/privacidad-conservacion.png', cuenta: 'admin', url: CF, recorte: seccion('Conservación de datos personales') },
    { archivo: 'configuracion/reloj-y-suplente.png', cuenta: 'admin', url: CF, vista: { width: 1366, height: 2600 }, antes: async (h) => { await h.desplegar('main details'); }, recorte: seccion('Reloj y suplente') },
    { archivo: 'etiquetas-qr/09-impresion-padrones.png', cuenta: 'admin', url: '/etiquetas/plantillas', recorte: async (h, ruta) => { await h.p.locator('main form').filter({ hasText: /imprimen/i }).first().screenshot({ path: ruta }); } },
    { archivo: 'reservas/ajustes-montaje.png', cuenta: 'admin', url: CF, vista: { width: 1366, height: 1000 }, recorte: seccion('Departamento que hace el montaje') },
    { archivo: 'configura-tu-empresa/01-tarjeta.png', cuenta: 'laguna', url: '/', vista: 'laptop', recorte: seccion('Configura tu empresa') },
    { archivo: 'configura-tu-empresa/02-celular.png', cuenta: 'laguna', url: '/', vista: 'celular1x', recorte: seccion('Configura tu empresa') },
    { archivo: 'mi-cuenta/avisos-navegador.png', cuenta: 'admin', url: '/mi-cuenta', recorte: seccion('Avisos en este navegador') },
    { archivo: 'mi-cuenta/delegar-mis-aprobaciones.png', cuenta: 'admin', url: '/mi-cuenta', recorte: seccion('Delegar mis aprobaciones') },
    { archivo: 'mi-cuenta/dos-pasos-activar.png', cuenta: 'admin', url: '/mi-cuenta/dos-pasos', vista: { width: 1366, height: 1000 }, recorte: 'section[aria-labelledby="t-activar"]' },
    { archivo: 'menus/mis-avisos.png', cuenta: 'admin', url: '/mi-cuenta', vista: { width: 1366, height: 1600 }, recorte: seccion('Mis avisos', 'main', 1474) },
];

const eliminar = [
    { archivo: 'eliminar-definitivamente/boton-en-editar.png', cuenta: 'admin', url: '/departamentos', antes: async (h) => { await h.dialogoDe('dialogoEditarDepartamento', 'Compras (duplicado)'); }, recorte: D('dialogoEditarDepartamento') },
    { archivo: 'eliminar-definitivamente/confirmar.png', cuenta: 'admin', url: '/departamentos',
        antes: async (h) => {
            await h.dialogoDe('dialogoEditarDepartamento', 'Compras (duplicado)');
            await h.p.locator('#dialogoEditarDepartamento').getByText('Eliminar definitivamente').click(); await h.esperar(900);
            await h.escribir('#dialogoBorrarDefinitivo [name=confirmacion]', 'Compras (duplicado)');
        }, recorte: D('dialogoBorrarDefinitivo') },
    { archivo: 'eliminar-definitivamente/en-uso.png', cuenta: 'admin', url: '/departamentos',
        antes: async (h) => { await h.dialogoDe('dialogoEditarDepartamento', 'Alimentos y Bebidas'); await h.p.locator('#dialogoEditarDepartamento').getByText('Eliminar definitivamente').click(); await h.esperar(1200); },
        recorte: D('dialogoBorrarDefinitivo') },
];

const equiposAcceso = [
    { archivo: 'equipos-de-acceso/lista.png', cuenta: 'admin', url: '/configuracion/equipos-acceso', recorte: '.fichas-grid' },
    { archivo: 'equipos-de-acceso/configurar-zkteco.png', cuenta: 'admin', url: '/configuracion/equipos-acceso', vista: 'celular1x', antes: async (h) => { await h.desplegar('main details'); }, recorte: recorteDe('.ficha-card', 'Checador de personal') },
    { archivo: 'equipos-de-acceso/nuevo-equipo.png', cuenta: 'admin', url: '/configuracion/equipos-acceso', vista: { width: 1366, height: 868 },
        antes: async (h) => { await h.clic('.ficha-create'); }, recorte: 'dialog[open]' },
];

const etiquetas = [
    { archivo: 'etiquetas-qr/01-imprimir.png', cuenta: 'admin', url: '/etiquetas' },
    { archivo: 'etiquetas-qr/02-seleccion.png', cuenta: 'admin', url: '/etiquetas',
        antes: async (h) => { await h.p.locator('main :is(button, a)').filter({ hasText: /^\s*Llaves/ }).first().click(); await h.listo(); for (let i = 0; i < 3; i++) { await h.marcar('main input[type=checkbox][name]:visible', i + 1).catch(() => {}); } } },
    { archivo: 'etiquetas-qr/04-historial.png', cuenta: 'admin', url: '/etiquetas/historial' },
    { archivo: 'etiquetas-qr/05-reimprimir.png', cuenta: 'admin', url: '/etiquetas/historial', antes: async (h) => { await h.boton('Imprimir otra vez'); } },
    { archivo: 'etiquetas-qr/06-plantillas.png', cuenta: 'admin', url: '/etiquetas/plantillas' },
    { archivo: 'etiquetas-qr/07-plantilla-nueva.png', cuenta: 'admin', url: '/etiquetas/plantillas/nueva', vista: { width: 1366, height: 1309 } },
    { archivo: 'etiquetas-qr/08-movil.png', cuenta: 'admin', url: '/etiquetas', vista: 'celular' },
    { archivo: 'etiquetas-qr/09-historial-noche.png', cuenta: 'admin', url: '/etiquetas/historial', modo: 'noche' },
    { archivo: 'etiquetas-qr/10-sol.png', cuenta: 'admin', url: '/etiquetas', modo: 'sol' },
    { archivo: 'etiquetas-qr/11-agente.png', cuenta: 'agente', url: '/etiquetas' },
    { archivo: 'etiquetas-qr/03-hoja.png', cuenta: 'admin', url: '/etiquetas/historial', antes: async (h) => { await h.enlaceDe('/etiquetas/impresiones/', 'Impresión núm. 1'); } },
    { archivo: 'etiquetas-qr/10-hoja-padron-recordatorio.png', cuenta: 'admin', url: '/llaves', vista: { width: 1366, height: 520 },
        antes: async (h) => {
            for (let i = 0; i < 1; i++) { await h.marcar('[data-chk-llave]', i); }
            await h.p.evaluate(() => document.getElementById('formEtiquetasLlaves').removeAttribute('target'));
            await Promise.all([h.p.waitForNavigation(), h.p.click('[data-imprimir-llaves]')]); await h.listo();
        } },
    { archivo: 'etiquetas-qr/12-escanear-llave.png', cuenta: 'agente', url: '/llaves', vista: 'celular',
        antes: async (h) => { await ident(h, 'HDC-MASTER-01'); const url = (await h.p.locator('dialog[open] code, dialog[open] [data-enlace-qr], dialog[open] input[readonly]').first().evaluate((e) => e.value || e.textContent)).trim(); await h.p.goto(url.replace(/^https?:\/\/[^/]+/, h.base)); await h.listo(); } },
];

const identidad = [
    { archivo: 'identidad/1-identidad.png', cuenta: SA, url: '/identidad', vista: { width: 1366, height: 1324 } },
    { archivo: 'identidad/4-contraste-bajo.png', cuenta: SA, url: '/identidad', vista: 'alto',
        antes: async (h) => { const c = h.p.locator('main input[type=text][name*="color"], main input[name*="primario"]').first(); await c.fill('#facc15'); await c.dispatchEvent('input'); await c.dispatchEvent('change'); await h.esperar(600); await h.centrar('text=Colores'); } },
];

const importar = [
    { archivo: 'importar-desde-excel/00-boton.png', cuenta: 'rh', url: '/colaboradores', vista: { width: 1366, height: 330 } },
    { archivo: 'importar-desde-excel/01-plantilla-y-archivo.png', cuenta: 'rh', url: '/importar/colaboradores', vista: 'alto' },
    { archivo: 'importar-desde-excel/02-vista-previa.png', cuenta: 'rh', url: '/importar/colaboradores', vista: { width: 1366, height: 1054 },
        antes: async (h) => { await h.p.setInputFiles('input[type=file]', ARCH + 'colaboradores.csv'); await Promise.all([h.p.waitForNavigation(), h.p.locator('main form [type=submit]').filter({ visible: true }).first().click()]); await h.listo(); } },
    { archivo: 'importar-desde-excel/03-resultado.png', cuenta: 'rh', url: '/importar/colaboradores', vista: { width: 1366, height: 1067 },
        antes: async (h) => {
            await h.p.setInputFiles('input[type=file]', ARCH + 'colaboradores.csv'); await Promise.all([h.p.waitForNavigation(), h.p.locator('main form [type=submit]').filter({ visible: true }).first().click()]); await h.listo();
            await Promise.all([h.p.waitForNavigation({ timeout: 180000 }), h.p.locator('form[action$="/guardar"] [type=submit]').first().click()]); await h.listo();
        } },
    { archivo: 'importar-estructura/00-boton.png', cuenta: 'laguna', url: '/sedes', vista: { width: 1280, height: 868 } },
    { archivo: 'importar-estructura/01-pantalla.png', cuenta: 'laguna', url: '/importar/estructura', vista: { width: 1280, height: 868 }, recorte: 'completa' },
    { archivo: 'importar-estructura/03-con-errores.png', cuenta: 'laguna', url: '/importar/estructura', vista: { width: 1280, height: 868 },
        antes: async (h) => { await h.p.setInputFiles('#archivo', ARCH + 'estructura-con-errores.csv'); await Promise.all([h.p.waitForNavigation(), h.p.click('button.boton-importar')]); await h.listo(); }, recorte: 'completa' },
    { archivo: 'importar-estructura/02-vista-previa.png', cuenta: 'laguna', url: '/importar/estructura', vista: { width: 1280, height: 868 },
        antes: async (h) => { await h.p.setInputFiles('#archivo', ARCH + 'estructura.csv'); await Promise.all([h.p.waitForNavigation(), h.p.click('button.boton-importar')]); await h.listo(); }, recorte: 'completa' },
    { archivo: 'importar-estructura/04-resultado.png', cuenta: 'laguna', url: '/importar/estructura', vista: { width: 1280, height: 868 },
        antes: async (h) => {
            await h.p.setInputFiles('#archivo', ARCH + 'estructura.csv'); await Promise.all([h.p.waitForNavigation(), h.p.click('button.boton-importar')]); await h.listo();
            await Promise.all([h.p.waitForNavigation({ timeout: 180000 }), h.p.click('form[action$="/guardar"] button[type=submit]')]); await h.listo();
        }, recorte: 'completa' },
];

const informes = [
    { archivo: 'informes/bitacora.png', cuenta: 'director', url: '/informes/bitacora-del-dia', vista: 'alto' },
    { archivo: 'informes/bitacora-imprimir.png', cuenta: 'director', url: '/informes/bitacora-del-dia/imprimir', vista: 'alto' },
    { archivo: 'informes/ejecutivo.png', cuenta: 'director', url: '/informes/ejecutivo', vista: 'alto' },
    { archivo: 'informes/ejecutivo-imprimir.png', cuenta: 'director', url: '/informes/ejecutivo/imprimir', vista: 'alto' },
    { archivo: 'informes/ejecutivo-listado.png', cuenta: 'director', url: '/informes/ejecutivo', vista: { width: 1366, height: 1200 },
        recorte: async (h, ruta) => {
            const t = h.p.locator('main :is(h2, h3, h4, p, strong)').filter({ hasText: /^\s*Colaboradores activos\s*$/ }).first();
            await t.evaluate((e) => e.scrollIntoView({ block: 'start' })); await h.esperar(300);
            const a = await t.boundingBox();
            const b = await h.p.locator('main :is(a, button, summary)').filter({ hasText: 'listado de registros' }).first().boundingBox();
            await h.p.screenshot({ path: ruta, clip: { x: 83, y: a.y - 10, width: 1200, height: b.y + b.height - a.y + 40 } });
        } },
    { archivo: 'informes/inicio-enlace-tablero.png', cuenta: 'director', url: '/', vista: 'laptop', recorte: seccion('Ver el tablero') },
    { archivo: 'informes/tablero.png', cuenta: 'director', url: '/informes/tablero', vista: 'alto' },
    { archivo: 'informes/tablero-detalle.png', cuenta: 'director', url: '/informes/tablero', vista: 'alto', antes: async (h) => { await h.texto('Personas en sitio ahora'); await h.esperar(1200); } },
    { archivo: 'informes/tablero-imprimir.png', cuenta: 'director', url: '/informes/tablero/imprimir', vista: 'alto' },
    { archivo: 'informes/tablero-movil.png', cuenta: 'director', url: '/informes/tablero', vista: 'celular1x' },
    { archivo: 'informes/tendencias.png', cuenta: 'director', url: '/informes/tendencias', vista: 'alto' },
    { archivo: 'informes/tendencias-accesos.png', cuenta: 'director', url: '/informes/tendencias', vista: { width: 1366, height: 1200 }, recorte: seccion('Ingresos por periodo') },
    { archivo: 'informes/tendencias-color.png', cuenta: 'director', url: '/informes/tendencias', recorte: async (h, ruta) => { await h.p.locator('main :is(.cifras, .fichas-cifras, [class*="cifras"])').filter({ hasText: 'antes' }).first().screenshot({ path: ruta }); } },
    { archivo: 'informes/tendencias-filtro.png', cuenta: 'director', url: '/informes/tendencias', recorte: 'main form' },
    { archivo: 'informes/tendencias-imprimir.png', cuenta: 'director', url: '/informes/tendencias/imprimir', vista: 'alto' },
];

const inicio = [
    { archivo: 'inicio/acceso-pc.png', url: '/login' },
    { archivo: 'inicio/acceso-celular.png', url: '/login', vista: 'celular' },
    { archivo: 'inicio/acceso-error.png', url: '/login',
        antes: async (h) => { await h.llenar('[name=username]', 'juan.perez'); await h.llenar('[name=password]', 'contrasena-mala'); await Promise.all([h.p.waitForNavigation(), h.p.click('button[type=submit]')]); await h.listo(); } },
    { archivo: 'inicio/dos-pasos-codigo.png', url: '/login', vista: { width: 900, height: 860 },
        antes: async (h) => { await h.llenar('[name=username]', 'soporte'); await h.llenar('[name=password]', 'Prueba.Local.2026'); await Promise.all([h.p.waitForNavigation(), h.p.click('button[type=submit]')]); await h.listo(); },
        recorte: async (h, ruta) => { await h.p.screenshot({ path: ruta, clip: { x: 0, y: 0, width: 900, height: 659 } }); } },
    { archivo: 'inicio/inicio-pendientes.png', cuenta: 'jefe', url: '/', vista: 'alto' },
    { archivo: 'inicio/sesion-finalizada.png', cuenta: 'agente', url: '/sesion/expirada' },
    { archivo: 'inicio/sesion-por-cerrar.png', cuenta: 'agente',
        antes: async (h) => {
            await h.p.clock.install();
            await h.ir('/');
            await h.p.clock.fastForward('18:10');
            await h.p.waitForSelector('#modalSesionPorExpirar.show', { timeout: 15000 });
            await h.esperar(500);
        } },
    { archivo: 'primeros-pasos/acceso.png', url: '/login' },
    { archivo: 'primeros-pasos/inicio.png', cuenta: 'agente', url: '/' },
    { archivo: 'primeros-pasos/celular-inicio.png', cuenta: 'agente', url: '/', vista: 'celular' },
    { archivo: 'primeros-pasos/manual-indice.png', cuenta: 'agente', url: '/manual' },
    { archivo: 'primeros-pasos/manual-pagina.png', cuenta: 'agente', url: '/manual/prestamo-llaves' },
    { archivo: 'primeros-pasos/ayuda-contextual.png', cuenta: 'agente', url: '/prestamo-llaves',
        recorte: async (h, ruta) => { const t = await h.p.locator('main h1').first().locator('xpath=ancestor::*[2]').boundingBox(); await h.p.screenshot({ path: ruta, clip: { x: t.x - 8, y: t.y - 8, width: Math.min(420, t.width + 16), height: t.height + 16 } }); } },
    { archivo: 'manual/buscar-contenido.png', cuenta: 'admin', url: '/manual?q=desbloquear', vista: 'alto' },
    { archivo: 'manual/celular-pagina.png', cuenta: 'agente', url: '/manual/accesos', vista: 'celular' },
    { archivo: 'manual/noche.png', cuenta: 'agente', url: '/manual/accesos', vista: 'celular', modo: 'noche' },
    { archivo: 'manual/sol.png', cuenta: 'agente', url: '/manual/accesos', vista: 'celular', modo: 'sol' },
];

const menuDe = (texto) => async (h) => { await h.p.locator('header nav, nav').getByText(texto, { exact: true }).first().click(); await h.esperar(600); };
const menus = [
    { archivo: 'menus/campana.png', cuenta: 'admin', url: '/', antes: campana },
    { archivo: 'menus/celular-menu.png', cuenta: 'admin', url: '/', vista: 'celular', antes: async (h) => { await h.p.locator('nav').getByText('Menú', { exact: true }).last().click(); await h.esperar(700); await h.p.getByRole('button', { name: /Operación/ }).filter({ visible: true }).last().click().catch(() => {}); await h.esperar(600); } },
    { archivo: 'menus/menu-operacion.png', cuenta: 'admin', url: '/', antes: menuDe('Operación') },
    { archivo: 'menus/menu-padrones.png', cuenta: 'admin', url: '/', antes: menuDe('Padrones') },
    { archivo: 'menus/mis-pendientes.png', cuenta: 'admin', url: '/', antes: async (h) => { await h.clic('[data-mis-pendientes], button[aria-label*="pendientes"]'); await h.esperar(700); } },
    { archivo: 'menus/mis-pendientes-agrupados.png', cuenta: 'rh', url: '/', antes: async (h) => { await h.clic('[data-mis-pendientes], button[aria-label*="pendientes"]'); await h.esperar(700); },
        recorte: async (h, ruta) => { await h.p.locator('[class*="pendientes-panel"], [class*="panel-pendientes"], [data-panel-pendientes]').filter({ visible: true }).first().screenshot({ path: ruta }); } },
    { archivo: 'menus/modo-noche.png', cuenta: 'admin', url: '/accesos', modo: 'noche' },
    { archivo: 'menus/modo-sol.png', cuenta: 'admin', url: '/accesos', modo: 'sol' },
];

const privacidad = [
    { archivo: 'privacidad/bandeja.png', cuenta: 'admin', url: '/solicitudes-arco', recorte: 'main' },
    { archivo: 'privacidad/atender.png', cuenta: 'admin', url: '/solicitudes-arco', vista: { width: 1280, height: 1471 }, antes: async (h) => { await h.enlaceDe('/solicitudes-arco/', 'ARCO-'); } },
    { archivo: 'privacidad/formulario-publico.png', url: '/privacidad/3lgc4uiycyye/solicitud', vista: { width: 900, height: 1166 } },
];

const traslado = [
    { archivo: 'traslado/1-paquete-listo.png', cuenta: SA, url: '/configuracion/traslado', vista: 'alto' },
    { archivo: 'traslado/3-verificar.png', cuenta: SA, url: '/configuracion/traslado', recorte: seccion('Verificar paquete') },
    { archivo: 'traslado/4-semanal.png', cuenta: SA, url: '/configuracion/traslado', recorte: seccion('Respaldo automático') },
    { archivo: 'traslado/5-boton-exportar.png', cuenta: SA, url: '/empresas',
        recorte: async (h, ruta) => { const b = h.p.locator('main a[href*="/exportaciones"], main button[title*="xportar"]').first(); const r = await b.locator('xpath=..').boundingBox(); await h.p.screenshot({ path: ruta, clip: r }); } },
    { archivo: 'traslado/6-exportar-empresa.png', cuenta: 'admin', url: '/empresas/1/exportaciones', vista: 'alto' },
];

export const pantallas = [
    ...colaboradores, ...departamentos, ...turnos, ...usuarios, ...empresas, ...roles,
    ...gafetes, ...llaves, ...equipos, ...equiposPc, ...estacionamientos, ...vehiculos, ...personas, ...proveedores, ...rutas, ...espacios, ...residentes,
    ...accesos, ...candidatos, ...vacantes,
    ...novedades, ...lostFound, ...pases, ...prestamo, ...responsivas, ...vouchers, ...transporte, ...recorridos, ...procedimientos, ...reservas,
    ...altas, ...asistencia, ...auditoria, ...catalogo, ...configuracion, ...eliminar, ...equiposAcceso, ...etiquetas, ...identidad,
    ...informes, ...inicio, ...menus, ...privacidad, ...traslado, ...importar,
];
