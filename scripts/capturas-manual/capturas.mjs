#!/usr/bin/env node
/*
 * Regenera las capturas del Manual de usuario (docs/usuario/img/**) con la
 * versión actual de la plataforma. Cada captura se describe en pantallas.mjs:
 * qué cuenta entra, qué pantalla abre, qué tamaño de ventana usa, en qué modo
 * (Normal, Sol o Noche) y qué hace antes de tomarla (abrir un diálogo, escribir,
 * desplegar una sección…). Se guarda con el MISMO nombre de archivo para no
 * romper los enlaces del manual.
 *
 * Requisitos y pasos: docs/tecnico/capturas-manual.md
 *
 * Uso:
 *   NODE_PATH=$(npm root -g) node scripts/capturas-manual/capturas.mjs [--base URL] [--solo texto] [--lista]
 *     --base   dirección de la app local (por omisión http://127.0.0.1:8190)
 *     --solo   solo las capturas cuyo archivo contiene ese texto (se puede repetir, separadas por coma)
 *     --desde  empieza en la captura número N (para continuar una corrida interrumpida)
 *     --lista  solo muestra qué capturas hay, sin tomarlas
 */
import { createRequire } from 'node:module';
import { createHmac } from 'node:crypto';
import { mkdirSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { pantallas } from './pantallas.mjs';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const AQUI = dirname(fileURLToPath(import.meta.url));
const RAIZ = resolve(AQUI, '../..');
const IMG = join(RAIZ, 'docs/usuario/img');

const args = process.argv.slice(2);
const opcion = (n, def) => { const i = args.indexOf(n); return i >= 0 ? args[i + 1] : def; };
const LOCAL = opcion('--base', 'http://127.0.0.1:8190');
const SOLO = (opcion('--solo', '') || '').split(',').filter(Boolean);
// El navegador abre «plataforma.ejemplo.mx» y lo dirige a la app local: así
// los enlaces y códigos QR muestran una dirección de ejemplo y no la local.
const DOMINIO = 'plataforma.ejemplo.mx';
const BASE = `http://${DOMINIO}`;
const CONTRASENA = 'Prueba.Local.2026';
const CLAVE_SUPERADMIN = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

export const CUENTAS = {
    admin: 'ana.lopez', director: 'diego.navarro', rh: 'rita.campos', jefe: 'julia.torres',
    supervisor: 'sergio.diaz', agente: 'andrea.ruiz', agente2: 'pablo.mendez', solicitante: 'sofia.vega',
    jefedepto: 'julieta.ramos', laguna: 'laura.mendez', plaza: 'mario.ruiz', centro: 'carmen.ortiz',
    corpAdmin: 'carla.ibarra', corpAgente: 'carmen.vega', condoAdmin: 'olga.fuentes', condoAgente: 'oscar.pech',
    fracAdmin: 'fernanda.aguilar', fracAgente: 'fabian.cruz', superadmin: 'soporte',
};

const VISTAS = {
    escritorio: { viewport: { width: 1366, height: 860 } },
    alto: { viewport: { width: 1366, height: 900 } },
    laptop: { viewport: { width: 1280, height: 900 } },
    celular: { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true },
    celular1x: { viewport: { width: 390, height: 844 } },
};

// Lo que no es parte de la pantalla del cliente: franja de «Desarrollo», cursor que parpadea, animaciones
const ESTILO = `
  .franja-ambiente, .lp-franja { display: none !important; }
  html, body, * { scroll-behavior: auto !important; }
  *, *::before, *::after { transition: none !important; animation-duration: 0s !important; animation-delay: 0s !important; caret-color: transparent !important; }
`;

function totp(base32) {
    const alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of base32) { bits += alfabeto.indexOf(c).toString(2).padStart(5, '0'); }
    const bytes = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
    const contador = Buffer.alloc(8);
    contador.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 1000 / 30)));
    const h = createHmac('sha1', bytes).update(contador).digest();
    const o = h[h.length - 1] & 0xf;
    return String((h.readUInt32BE(o) & 0x7fffffff) % 1e6).padStart(6, '0');
}

const sesiones = new Map();

async function iniciarSesion(navegador, cuenta) {
    if (sesiones.has(cuenta)) { return sesiones.get(cuenta); }
    const ctx = await navegador.newContext({ ...VISTAS.escritorio, locale: 'es-MX', timezoneId: 'America/Cancun' });
    const p = await ctx.newPage();
    await p.goto(BASE + '/login');
    await p.fill('[name=username]', CUENTAS[cuenta] ?? cuenta);
    await p.fill('[name=password]', CONTRASENA);
    await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
    if (p.url().includes('/login/verificacion')) {
        // Super Administrador: código de la verificación en dos pasos (clave local conocida)
        let ultimo = '';
        for (let i = 0; i < 3 && p.url().includes('/login/verificacion'); i++) {
            let codigo = totp(CLAVE_SUPERADMIN);
            while (codigo === ultimo) { await p.waitForTimeout(2000); codigo = totp(CLAVE_SUPERADMIN); }
            ultimo = codigo;
            await p.fill('input[name=codigo]', codigo);
            await Promise.all([p.waitForNavigation(), p.locator('form button[type=submit]').first().click()]);
        }
    }
    if (p.url().includes('/login')) { throw new Error(`No se pudo entrar con la cuenta «${cuenta}» (${p.url()})`); }
    const estado = await ctx.storageState();
    await ctx.close();
    sesiones.set(cuenta, estado);
    return estado;
}

/* Ayudas que usan las capturas en pantallas.mjs */
export function ayudas(p) {
    const h = {
        p,
        base: BASE,
        ir: async (url) => { await p.goto(BASE + url); await h.listo(); },
        listo: async () => {
            await p.waitForLoadState('networkidle').catch(() => {});
            await p.addStyleTag({ content: ESTILO }).catch(() => {});
            await p.evaluate(() => document.fonts && document.fonts.ready).catch(() => {});
            await p.waitForTimeout(250);
        },
        esperar: (ms = 400) => p.waitForTimeout(ms),
        clic: async (sel, n = 0) => { await p.locator(sel).nth(n).click(); await p.waitForTimeout(350); },
        texto: async (texto, n = 0) => { await p.getByText(texto, { exact: false }).nth(n).click(); await p.waitForTimeout(350); },
        boton: async (nombre, n = 0) => { await p.getByRole('button', { name: nombre }).nth(n).click(); await p.waitForTimeout(350); },
        // Abre un diálogo con su botón (data-dialogo="id"); sin botón, lo abre directo
        dialogo: async (id, n = 0) => {
            const b = p.locator(`[data-dialogo="${id}"], [data-abrir-dialogo="${id}"]`).filter({ visible: true });
            if (await b.count() > n) { await b.nth(n).click(); } else {
                await p.evaluate((i) => { const d = document.getElementById(i); if (d && !d.open) { d.showModal(); } }, id);
            }
            await p.waitForTimeout(450);
        },
        // Abre el diálogo «id» con el botón de la tarjeta que contiene «texto»
        dialogoDe: async (id, texto) => {
            const ok = await p.evaluate(([i, t]) => {
                const bs = [...document.querySelectorAll(`[data-dialogo="${i}"], [data-abrir-dialogo="${i}"]`)];
                let mejor = null; let largo = Infinity;
                for (const b of bs) {
                    for (let e = b.parentElement; e; e = e.parentElement) {
                        const tx = e.textContent || '';
                        if (tx.includes(t)) { if (tx.length < largo) { largo = tx.length; mejor = b; } break; }
                    }
                }
                if (mejor) { mejor.click(); return true; }
                return false;
            }, [id, texto]);
            if (!ok) { throw new Error(`No encontré el botón de ${id} para «${texto}»`); }
            await p.waitForTimeout(500);
        },
        // Abre el enlace (href que contiene «parte») de la tarjeta que contiene «texto»
        enlaceDe: async (parte, texto) => {
            const href = await p.evaluate(([pa, t]) => {
                let mejor = null; let largo = Infinity;
                for (const a of document.querySelectorAll(`a[href*="${pa}"]`)) {
                    for (let e = a.parentElement; e; e = e.parentElement) {
                        const tx = e.textContent || '';
                        if (tx.includes(t)) { if (tx.length < largo) { largo = tx.length; mejor = a.href; } break; }
                    }
                }
                return mejor;
            }, [parte, texto]);
            if (!href) { throw new Error(`No encontré el enlace «${parte}» de «${texto}»`); }
            await p.goto(href); await h.listo();
        },
        // Pulsa el botón o enlace «boton» (selector o texto) dentro de la tarjeta más chica que contiene «texto»
        botonDe: async (boton, texto) => {
            const ok = await p.evaluate(([b, t]) => {
                const esSel = /^[\[.#a-z]/i.test(b) && /[\[\]#.=]/.test(b);
                const candidatos = esSel ? [...document.querySelectorAll(b)]
                    : [...document.querySelectorAll('button, a, summary, label')].filter((x) => x.textContent.trim().replace(/\s+/g, ' ').includes(b));
                let mejor = null; let largo = Infinity;
                for (const x of candidatos) {
                    if (!x.offsetParent && x.tagName !== 'SUMMARY') { continue; }
                    for (let e = x.parentElement; e; e = e.parentElement) {
                        const tx = e.textContent || '';
                        if (tx.includes(t)) { if (tx.length < largo) { largo = tx.length; mejor = x; } break; }
                    }
                }
                if (mejor) { mejor.scrollIntoView({ block: 'center' }); mejor.click(); return true; }
                return false;
            }, [boton, texto]);
            if (!ok) { throw new Error(`No encontré «${boton}» en la tarjeta de «${texto}»`); }
            await p.waitForTimeout(600);
            await p.waitForLoadState('networkidle').catch(() => {});
        },
        escribir: async (sel, valor) => { const c = p.locator(sel).first(); await c.fill(''); await c.pressSequentially(String(valor), { delay: 15 }); await p.waitForTimeout(700); },
        llenar: async (sel, valor) => { await p.locator(sel).first().fill(String(valor)); },
        elegir: async (sel, valor) => { await p.locator(sel).first().selectOption(valor); await p.waitForTimeout(300); },
        // Elige la opción cuyo texto contiene «texto»
        elegirTexto: async (sel, texto) => {
            const v = await p.locator(sel).first().evaluate((s, t) => { const o = [...s.options].find((x) => x.textContent.includes(t)); return o ? o.value : null; }, texto);
            if (v === null) { throw new Error(`No hay opción «${texto}» en ${sel}`); }
            await p.locator(sel).first().selectOption(v); await p.waitForTimeout(300);
        },
        // Pulsa la opción visible (label, botón) dentro de «dentro» cuyo texto contiene «texto»
        opcion: async (dentro, texto) => {
            await p.locator(`${dentro} label, ${dentro} button`).filter({ visible: true, hasText: texto }).first().click();
            await p.waitForTimeout(450);
        },
        // Elige en el campo visible cuya etiqueta contiene «etiqueta» la opción que contiene «texto» (o escribe si es caja de texto)
        campo: async (dentro, etiqueta, texto) => {
            const ok = await p.evaluate(([d, e, t]) => {
                const ls = [...document.querySelectorAll(`${d} label`)].filter((l) => l.offsetParent && l.textContent.includes(e));
                for (const l of ls) {
                    const c = l.control || (l.htmlFor && document.getElementById(l.htmlFor));
                    if (!c || !c.offsetParent) { continue; }
                    if (c.tagName === 'SELECT') {
                        const o = [...c.options].find((x) => x.textContent.includes(t));
                        if (!o) { continue; }
                        c.value = o.value;
                    } else { c.value = t; c.dispatchEvent(new Event('input', { bubbles: true })); }
                    c.dispatchEvent(new Event('change', { bubbles: true }));
                    return true;
                }
                return false;
            }, [dentro, etiqueta, texto]);
            if (!ok) { throw new Error(`No encontré el campo «${etiqueta}» con «${texto}»`); }
            await p.waitForTimeout(450);
        },
        // Abre el enlace del kiosco que muestra la pantalla del QR (como si el candidato lo escaneara)
        irAlKiosco: async () => {
            const codigo = (await p.locator('.codigo-kiosco').first().textContent()).trim();
            await p.context().clearCookies();
            await p.goto(h.base + '/k'); await h.listo();
            await p.fill('[name=codigo]', codigo);
            await Promise.all([p.waitForNavigation(), p.locator('form [type=submit]').first().click()]);
            await h.listo();
        },
        // Dibuja una firma en el lienzo
        firmar: async (sel) => {
            const c = p.locator(sel).filter({ visible: true }).first();
            await c.scrollIntoViewIfNeeded();
            const b = await c.boundingBox();
            await p.mouse.move(b.x + b.width * 0.1, b.y + b.height * 0.7);
            await p.mouse.down();
            for (const [x, y] of [[0.2, 0.3], [0.3, 0.75], [0.42, 0.25], [0.55, 0.7], [0.68, 0.35], [0.85, 0.6]]) { await p.mouse.move(b.x + b.width * x, b.y + b.height * y, { steps: 6 }); }
            await p.mouse.up(); await p.waitForTimeout(300);
        },
        centrar: async (sel) => { await p.locator(sel).first().evaluate((e) => e.scrollIntoView({ block: 'center' })); await p.waitForTimeout(250); },
        marcar: async (sel, n = 0) => { await p.locator(sel).nth(n).check({ force: true }); },
        // Envía el formulario del diálogo sin la validación del navegador (para mostrar el error del servidor)
        enviarSinValidar: async (sel) => {
            await p.evaluate((s) => { const f = document.querySelector(s); f.noValidate = true; f.querySelectorAll('[required]').forEach((x) => x.removeAttribute('required')); }, sel);
            await Promise.all([p.waitForNavigation().catch(() => {}), p.locator(`${sel} [type=submit]`).last().click()]);
            await h.listo();
        },
        desplegar: async (sel) => { await p.evaluate((s) => document.querySelectorAll(s).forEach((d) => { d.open = true; }), sel); await p.waitForTimeout(250); },
        arriba: () => p.evaluate(() => window.scrollTo(0, 0)),
        bajarHasta: async (sel) => { await p.locator(sel).first().scrollIntoViewIfNeeded(); await p.waitForTimeout(250); },
        ocultar: (sel) => p.addStyleTag({ content: `${sel}{display:none!important}` }),
    };
    return h;
}

async function tomar(navegador, c) {
    const vista = typeof c.vista === 'object' ? { viewport: c.vista } : (VISTAS[c.vista ?? 'escritorio']);
    const estado = c.cuenta ? await iniciarSesion(navegador, c.cuenta) : undefined;
    const ctx = await navegador.newContext({ ...vista, locale: 'es-MX', timezoneId: 'America/Cancun', storageState: estado });
    const modo = c.modo ?? 'normal';
    await ctx.addInitScript((m) => { try { localStorage.setItem('plataforma_modo_pantalla', m); } catch (e) { /* sin almacenamiento */ } }, modo);
    const p = await ctx.newPage();
    p.on('dialog', (d) => d.accept().catch(() => {}));
    const h = ayudas(p);
    try {
        if (c.url) { await h.ir(c.url); }
        if (c.antes) { await c.antes(h); }
        await p.addStyleTag({ content: ESTILO }).catch(() => {});
        // La dirección IP local (Bitácora de auditoría) se muestra como una de red interna de ejemplo
        await p.evaluate(() => {
            const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
            let n; while ((n = w.nextNode())) { if (n.nodeValue.includes('127.0.0.1')) { n.nodeValue = n.nodeValue.replaceAll('127.0.0.1', '10.0.0.25'); } }
        }).catch(() => {});
        await p.waitForTimeout(c.espera ?? 300);
        const ruta = join(IMG, c.archivo);
        mkdirSync(dirname(ruta), { recursive: true });
        const recorte = c.recorte ?? 'pantalla';
        if (recorte === 'pantalla') {
            await p.screenshot({ path: ruta });
        } else if (recorte === 'completa') {
            await p.screenshot({ path: ruta, fullPage: true });
        } else if (typeof recorte === 'function') {
            await recorte(h, ruta);
        } else {
            const el = p.locator(recorte).filter({ visible: true }).first();
            await el.scrollIntoViewIfNeeded().catch(() => {});
            await el.screenshot({ path: ruta });
        }
        return null;
    } finally {
        await ctx.close();
    }
}

async function principal() {
    const lista = pantallas.filter((c) => SOLO.length === 0 || SOLO.some((s) => c.archivo.includes(s))).slice(Math.max(0, parseInt(opcion('--desde', '1'), 10) - 1));
    if (args.includes('--lista')) { lista.forEach((c) => console.log(c.archivo)); return; }
    const navegador = await chromium.launch({ args: [`--host-resolver-rules=MAP ${DOMINIO} ${new URL(LOCAL).host}`] });
    const fallas = [];
    let i = 0;
    for (const c of lista) {
        i++;
        try {
            await tomar(navegador, c);
            console.log(`[${i}/${lista.length}] ok ${c.archivo}`);
        } catch (e) {
            fallas.push(c.archivo);
            console.log(`[${i}/${lista.length}] FALLA ${c.archivo}: ${String(e.message).split('\n')[0]}`);
        }
    }
    await navegador.close();
    console.log(`\nListas: ${lista.length - fallas.length} de ${lista.length}.`);
    if (fallas.length) { console.log('Fallaron:\n  ' + fallas.join('\n  ')); process.exitCode = 1; }
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    principal().catch((e) => { console.error(e); process.exit(1); });
}

export { readFileSync };
