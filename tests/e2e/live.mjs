#!/usr/bin/env node
/**
 * End-to-end test against the REAL Supertext API: a fresh demo (nothing translated yet)
 * with a real SUPERTEXT_API_KEY. The editor (Translator profile) translates the sample
 * products, category and CMS page into every other shop language through the back office
 * (list → "Translate with Supertext" → Translate), then the database is checked: every item
 * has text of its own in each language, really in the target language (de-CH without "ß"),
 * formatting and links survived, the slug follows the translated name, and translating again
 * (with "Overwrite existing translations") updates the same rows instead of adding any.
 * See "End-to-end tests" in docs/DEVELOPER.md.
 *
 *   BASE_URL=http://localhost:8081/admin-dev DATABASE_URL=mysql://root:root@127.0.0.1:3306/prestashop_live \
 *   EDITOR_EMAIL=… EDITOR_PASSWORD=… [E2E_TARGETS=de-CH,fr-CH,it-CH] node tests/e2e/live.mjs
 */
import { chromium } from 'playwright';
import mysql from 'mysql2/promise';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const B = (process.env.BASE_URL ?? 'http://localhost:8081/admin-dev').replace(/\/$/, '');
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`); })();
const expectedCodes = (process.env.E2E_TARGETS ?? '').split(',').map((t) => t.trim()).filter(Boolean);
const artifacts = fileURLToPath(new URL('./artifacts/', import.meta.url));
mkdirSync(artifacts, { recursive: true });

const failures = [];
function check(name, ok, detail = '') {
	console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${detail ? ` — ${detail}` : ''}`);
	if (!ok) failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
	return ok;
}
const note = (text) => console.log(`note ${text}`);

// Common words, to tell the target languages apart. Lower case, whole words.
const STOPWORDS = {
	en: ['the', 'and', 'is', 'are', 'with', 'for', 'you', 'your', 'our', 'of', 'to', 'we', 'who', 'what'],
	de: ['der', 'die', 'das', 'und', 'ist', 'sind', 'mit', 'für', 'sie', 'ihre', 'ihr', 'unser', 'unsere', 'wir', 'eine', 'ein', 'den', 'zu', 'von', 'was'],
	fr: ['le', 'les', 'et', 'est', 'sont', 'avec', 'pour', 'vous', 'votre', 'vos', 'notre', 'nos', 'nous', 'des', 'une', 'un', 'du', 'de', 'qui', 'que'],
	it: ['il', 'lo', 'gli', 'e', 'è', 'sono', 'con', 'per', 'lei', 'sua', 'suo', 'nostro', 'nostra', 'noi', 'una', 'un', 'del', 'della', 'di', 'che', 'chi'],
};
const words = (text) => text.toLowerCase().match(/[\p{L}]+/gu) ?? [];
const score = (text, lang) => words(text).filter((w) => STOPWORDS[lang].includes(w)).length;
const plain = (v) => String(v ?? '').replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ');
const count = (haystack, needle) => String(haystack ?? '').split(needle).length - 1;

const LEFTOVERS = ['data-st-id', 'data-st-ph', 'st-ph-'];
const FORMATTING = [['bold', '<strong'], ['italics', '<em'], ['links', '<a '], ['link targets', 'href="https://www.supertext.com"'], ['headings', '<h2'], ['list items', '<li']];

// What the module translates (supertext/src/Translation/EntityTypes.php).
const TYPES = {
	product: { table: 'product', key: 'id_product', fields: ['name', 'description_short', 'description', 'meta_title', 'meta_description', 'available_now', 'available_later', 'delivery_in_stock', 'delivery_out_stock'], slugFrom: 'name' },
	category: { table: 'category', key: 'id_category', fields: ['name', 'description', 'additional_description', 'meta_title', 'meta_description'], slugFrom: 'name' },
	cms: { table: 'cms', key: 'id_cms', fields: ['meta_title', 'head_seo_title', 'meta_description', 'content'], slugFrom: 'meta_title' },
};

const db = await mysql.createConnection(need('DATABASE_URL'));
const q = async (sql, params = []) => (await db.query(sql, params))[0];

// --- Setup -------------------------------------------------------------------------------
const [{ id_lang: english } = {}] = await q("SELECT id_lang FROM ps_lang WHERE iso_code = 'en'");
if (!english) throw new Error('No English language: is this the demo?');
const shop = Number((await q("SELECT value FROM ps_configuration WHERE name = 'PS_SHOP_DEFAULT'"))[0]?.value ?? 1);
const targets = await q('SELECT id_lang, iso_code, locale, name FROM ps_lang WHERE active = 1 AND id_lang != ? ORDER BY id_lang', [english]);
const items = {
	product: (await q("SELECT id_product AS id FROM ps_product WHERE reference LIKE 'ST-DEMO-%' ORDER BY id_product")).map((r) => r.id),
	category: (await q("SELECT id_category AS id FROM ps_category_lang WHERE link_rewrite = 'swiss-chocolate' AND id_lang = ? AND id_shop = ?", [english, shop])).map((r) => r.id),
	cms: (await q("SELECT id_cms AS id FROM ps_cms_lang WHERE link_rewrite = 'delivery-and-returns' AND id_lang = ? AND id_shop = ?", [english, shop])).map((r) => r.id),
};
console.log(`Sample content: ${items.product.length} products, ${items.category.length} category, ${items.cms.length} CMS page; targets: ${targets.map((t) => `${t.name} (${t.locale})`).join(', ')}`);
check('demo has target languages', targets.length > 0);
check('demo has the sample content', items.product.length === 2 && items.category.length === 1 && items.cms.length === 1, JSON.stringify(items));

/** Language rows (this shop) of the sample items, by type → "id:lang"; plus row counts and flags. */
async function snapshot() {
	const out = {};
	for (const [type, t] of Object.entries(TYPES)) {
		const rows = await q(`SELECT * FROM ps_${t.table}_lang WHERE ${t.key} IN (?) AND id_shop = ?`, [items[type], shop]);
		out[type] = {
			rows: new Map(rows.map((r) => [`${r[t.key]}:${r.id_lang}`, r])),
			langRows: (await q(`SELECT COUNT(*) AS n FROM ps_${t.table}_lang`))[0].n,
			entities: (await q(`SELECT COUNT(*) AS n FROM ps_${t.table}`))[0].n,
			active: (await q(`SELECT ${t.key} AS id, active FROM ps_${t.table} WHERE ${t.key} IN (?)`, [items[type]])).map((r) => `${r.id}:${r.active}`).join(','),
		};
	}
	return out;
}

const before = await snapshot();
let untouched = true;
for (const [type, t] of Object.entries(TYPES)) {
	for (const id of items[type]) {
		const src = before[type].rows.get(`${id}:${english}`);
		for (const target of targets) {
			const row = before[type].rows.get(`${id}:${target.id_lang}`);
			if (!row || t.fields.some((f) => String(row[f] ?? '') !== String(src[f] ?? ''))) untouched = false;
		}
	}
}
check('fresh demo (nothing translated yet: every language still has the English copy)', untouched);

// --- Browser -----------------------------------------------------------------------------
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const page = await browser.newPage({ viewport: { width: 1280, height: 1000 }, locale: 'en-US' });
page.setDefaultTimeout(60_000);
const go = async (path) => { await page.goto(B + path); await page.waitForLoadState('networkidle'); };
let translateUrl = null; // the module's page, with the route's CSRF token (from the first list)

await go('/index.php');
await page.fill('#email', need('EDITOR_EMAIL'));
await page.fill('#passwd', need('EDITOR_PASSWORD'));
await page.click('#submit_login');
await page.waitForLoadState('networkidle');
await page.waitForTimeout(1000);
if (!check('editor can log in (DEMO_EDITOR_*)', !(await page.locator('#submit_login').isVisible().catch(() => false)))) {
	await page.screenshot({ path: `${artifacts}login.png` });
	throw new Error(failures.join('\n'));
}

/** A grid row's "Translate with Supertext" action (inline or in the row's dropdown). */
async function rowAction(listPath, rowText) {
	await go(listPath);
	const row = page.locator('tr', { hasText: rowText }).first();
	const link = row.locator('a.grid-translate-with-supertext-row-link');
	if (!(await link.first().isVisible().catch(() => false))) await row.locator('.dropdown-toggle').first().click();
	await Promise.all([page.waitForURL(/supertext/), page.locator('a.grid-translate-with-supertext-row-link:visible').first().click()]);
}

const OPEN = {
	// Catalog → Products: tick the sample products, bulk action.
	async product() {
		await go('/index.php?controller=AdminProducts');
		for (const id of items.product) await page.locator(`input[name="product_bulk[]"][value="${id}"]`).check({ force: true });
		await page.locator('.js-bulk-actions-btn').first().click();
		await Promise.all([page.waitForURL(/supertext/), page.locator('button', { hasText: 'Translate with Supertext' }).first().click()]);
	},
	// Catalog → Categories and Design → Pages: the row action.
	category: () => rowAction('/index.php?controller=AdminCategories', 'Swiss chocolate'),
	cms: () => rowAction('/index.php?controller=AdminCmsContent', 'Delivery and returns'),
};

async function openTranslatePage(type) {
	try {
		await OPEN[type]();
		await page.locator('.supertext-page').waitFor();
		check(`${type}: list action opens "Translate with Supertext"`, true);
		translateUrl ??= page.url();
	} catch (e) {
		check(`${type}: list action opens "Translate with Supertext"`, false, e.message.split('\n')[0]);
		if (!translateUrl) throw e;
		// Same page through its own URL, so the remaining checks still run.
		const url = new URL(translateUrl);
		url.searchParams.set('type', type);
		url.searchParams.set('ids', items[type].join(','));
		note(`${type}: opened through the module's page URL instead`);
		await page.goto(url.href);
		await page.locator('.supertext-page').waitFor();
	}
}

/** Translate one type into every target language on the module's page; returns the counts. */
async function translate(type, label, overwrite) {
	await openTranslatePage(type);
	const ui = page.locator('.supertext-page');
	const alerts = (await ui.locator('.alert-danger, .alert-warning:not(.st-overwrite-warning)').allTextContents()).map((t) => t.replace(/\s+/g, ' ').trim()).filter(Boolean);
	check(`${label} ${type}: page without errors or warnings`, alerts.length === 0, alerts.join(' | '));
	const codes = [];
	for (const target of targets) {
		const box = ui.locator(`#st-lang-${target.id_lang}`);
		const usable = (await box.count()) === 1 && !(await box.isDisabled());
		const code = usable ? (await ui.locator(`label[for="st-lang-${target.id_lang}"] code`).textContent())?.trim() : '';
		check(`${label} ${type}: ${target.name} has a Supertext language`, usable && !!code, code);
		if (usable) await box.check();
		if (code) codes.push(code);
	}
	if (expectedCodes.length) check(`${label} ${type}: sends the codes checked against GET features`, codes.join(',') === expectedCodes.join(','), `${codes.join(',')} vs E2E_TARGETS ${expectedCodes.join(',')}`);
	if (overwrite) await ui.locator('#st-overwrite').check();

	const started = Date.now();
	await ui.locator('.st-go').click();
	await ui.locator('.st-summary').waitFor({ state: 'visible', timeout: 900_000 });
	await page.screenshot({ path: `${artifacts}${label}-${type}.png`, fullPage: true });
	const summary = (await ui.locator('.st-summary').textContent()).trim();
	const errors = (await ui.locator('.st-state.error').allTextContents()).map((t) => t.replace(/\s+/g, ' ').trim());
	const done = await ui.locator('.st-state.done').count();
	const jobs = items[type].length * targets.length;
	console.log(`${label} ${type}: ${Math.round((Date.now() - started) / 1000)} s — ${summary}`);
	check(`${label} ${type}: no errors from Supertext`, errors.length === 0 && /\b0 failed\b/.test(summary), errors.join(' | ') || summary);
	check(`${label} ${type}: every item translated into every language`, done === jobs, `${done}/${jobs}`);
}

// --- First translation -------------------------------------------------------------------
for (const type of Object.keys(TYPES)) await translate(type, 'first-run', false);
const first = await snapshot();

for (const target of targets) {
	const lang = target.iso_code.toLowerCase();
	const tag = `${target.name} (${target.locale})`;
	let fields = 0;
	let changed = 0;
	let text = '';
	for (const [type, t] of Object.entries(TYPES)) {
		for (const id of items[type]) {
			const src = first[type].rows.get(`${id}:${english}`);
			const row = first[type].rows.get(`${id}:${target.id_lang}`);
			if (!check(`${tag}: ${type}#${id} has a row`, !!row)) continue;
			for (const f of t.fields) {
				if (!plain(src[f]).trim()) continue;
				fields++;
				const v = String(row[f] ?? '');
				if (!check(`${tag}: ${type}#${id}.${f} filled`, plain(v).trim() !== '')) continue;
				if (v !== String(src[f])) changed++;
				else note(`${tag}: ${type}#${id}.${f} unchanged ("${plain(v).trim().slice(0, 60)}")`);
				text += ` ${plain(v)}`;
				const left = LEFTOVERS.filter((l) => v.includes(l));
				check(`${tag}: ${type}#${id}.${f} has no protocol leftovers`, left.length === 0, left.join(', '));
				for (const [what, needle] of FORMATTING) {
					if (count(src[f], needle)) check(`${tag}: ${type}#${id}.${f} keeps its ${what}`, count(v, needle) === count(src[f], needle), `${count(v, needle)}/${count(src[f], needle)}`);
				}
			}
			// The slug follows the translated name (or title).
			if (row[t.slugFrom] !== src[t.slugFrom]) {
				check(`${tag}: ${type}#${id} slug made from the translated ${t.slugFrom}`, /^[a-z0-9-]+$/.test(row.link_rewrite) && row.link_rewrite !== src.link_rewrite, `${src.link_rewrite} → ${row.link_rewrite}`);
			}
		}
	}
	check(`${tag}: texts were changed by the translation`, fields > 0 && changed / fields >= 0.8, `${changed}/${fields} fields`);
	if (STOPWORDS[lang]) {
		const scores = Object.fromEntries(Object.keys(STOPWORDS).map((l) => [l, score(text, l)]));
		const best = Object.entries(scores).sort((a, b) => b[1] - a[1])[0][0];
		check(`${tag}: text is in the target language`, best === lang, JSON.stringify(scores));
	}
	if (/^de-ch$/i.test(target.locale)) check(`${tag}: Swiss German spelling (no "ß")`, !text.includes('ß'));
}
for (const type of Object.keys(TYPES)) {
	// The module only writes language fields: an item stays as (un)published as it was.
	check(`${type}: published state unchanged`, first[type].active === before[type].active, `${before[type].active} → ${first[type].active}`);
	check(`${type}: no rows added`, first[type].langRows === before[type].langRows && first[type].entities === before[type].entities);
}
// English stays English.
for (const [type, t] of Object.entries(TYPES)) {
	for (const id of items[type]) {
		const a = before[type].rows.get(`${id}:${english}`);
		const b = first[type].rows.get(`${id}:${english}`);
		check(`${type}#${id}: English source unchanged`, t.fields.every((f) => String(a[f] ?? '') === String(b[f] ?? '')));
	}
}

// --- Translating again (overwrite) updates in place -----------------------------------------
for (const type of Object.keys(TYPES)) await translate(type, 'second-run', true);
const second = await snapshot();
for (const [type, t] of Object.entries(TYPES)) {
	check(`${type}: translating again updates in place (no new rows)`, second[type].langRows === first[type].langRows && second[type].entities === first[type].entities,
		`lang rows ${first[type].langRows} → ${second[type].langRows}, items ${first[type].entities} → ${second[type].entities}`);
	for (const id of items[type]) {
		for (const target of targets) {
			const row = second[type].rows.get(`${id}:${target.id_lang}`);
			const src = second[type].rows.get(`${id}:${english}`);
			check(`${type}#${id} ${target.locale}: still translated after the second run`, !!row && t.fields.filter((f) => plain(src[f]).trim()).some((f) => row[f] !== src[f]));
		}
	}
}

await browser.close();
await db.end();

if (failures.length) {
	console.error(`\n${failures.length} check(s) failed:\n- ${failures.join('\n- ')}`);
	process.exit(1);
}
console.log('\nAll end-to-end checks passed.');
