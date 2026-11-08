#!/usr/bin/env node
/**
 * Records the install and configuration movie (MP4 only, no GIF) from a demo whose module talks to
 * stand-in.mjs, like movie.mjs, but with the module NOT installed yet (uninstall it and remove
 * modules/supertext first) and without SUPERTEXT_API_KEY. An administrator builds the zip (terminal: ./build.sh),
 * uploads it in Modules → Module Manager, enters the API key in Configure, sees the key check, checks the
 * Supertext language per shop language and shows where editors find "Translate with Supertext".
 * See "Demo movie" in docs/DEVELOPER.md.
 *
 *   BASE_URL=http://localhost:8090/admin-dev DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… node movie-install.mjs
 *
 * Needs dist/supertext-<version>.zip (./build.sh). The key typed is the made-up st-demo-0000-0000-0000
 * (the stand-in accepts any key). MOVIE_VOICE=0 makes a silent movie. MOVIE_OUT: where the MP4 goes (default
 * movie/out/PrestaShop-Supertext-Translation-install.mp4, git-ignored). Needs ffmpeg.
 */
import { chromium } from 'playwright'
import { fileURLToPath } from 'node:url'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { MovieKit } from './movie/movie-kit.mjs'

const B = (process.env.BASE_URL || 'http://localhost:8090/admin-dev').replace(/\/$/, '')
const here = (p) => fileURLToPath(new URL(p, import.meta.url))
const out = process.env.MOVIE_OUT || here('movie/out/PrestaShop-Supertext-Translation-install.mp4')
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`) })()
const FAKE_KEY = 'st-demo-0000-0000-0000'
const version = fs.readFileSync(here('../../supertext/supertext.php'), 'utf8').match(/\$this->version\s*=\s*'([\d.]+)'/)[1]
const zip = here(`../../dist/supertext-${version}.zip`)
if (!fs.existsSync(zip)) throw new Error(`Run ./build.sh first (${zip} is missing)`)

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined })
const page = await browser.newPage({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1, locale: 'en-US' })
page.setDefaultTimeout(90_000) // PrestaShop builds its cache on the first visit of a page
// The settings page notes that the endpoint comes from SUPERTEXT_API_ENDPOINT (the stand-in): hide it, as screenshots.mjs does.
await page.addInitScript(() => document.addEventListener('DOMContentLoaded', () => {
  const s = document.createElement('style'); s.textContent = '.st-endpoint-env{display:none!important}'; document.head.appendChild(s)
}))

// Sign in as the demo administrator (not recorded): installing and configuring is an administrator's task.
await page.goto(`${B}/index.php`)
await page.fill('#email', need('DEMO_ADMIN_EMAIL'))
await page.fill('#passwd', need('DEMO_ADMIN_PASSWORD'))
await page.click('#submit_login')
await page.waitForLoadState('networkidle')
// Back-office links carry tokens: take them from the menu. Visit each page once (first visits are slow).
const href = (id) => page.locator(`#subtab-${id} a`).first().evaluate((a) => a.href)
const links = { modules: await href('AdminModulesSf'), products: await href('AdminProducts') }
await page.goto(await href('AdminParentLocalization'))
links.languages = await page.locator('a[href*="languages"]', { hasText: /^\s*Languages\s*$/ }).first().evaluate((a) => a.href)
for (const url of Object.values(links)) { await page.goto(url); await page.waitForLoadState('networkidle') }

const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'prestashop-movie-install-'))
await page.goto('about:blank')
const kit = await MovieKit.start(page, { workDir, assets: here('movie/assets'), voiceCache: here('movie/voice-cache') })
const ripple = () => page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [kit.cursor.x, kit.cursor.y])
/** Shows a back-office page; its loading time is cut. */
async function show(url, ready) {
  kit.pause()
  await page.goto(url)
  await ready()
  await page.waitForLoadState('networkidle')
  kit.resume()
  await kit.sync()
}
/** Clicks a link or button that loads a page; the loading time is cut. */
async function open(locator, loaded) {
  await kit.moveTo(locator)
  await ripple()
  kit.pause()
  await locator.click()
  await loaded()
  await page.waitForLoadState('networkidle')
  kit.resume()
  await kit.sync()
}

// 1. Terminal: the module zip (docs/INSTALLATION.md, step 1).
await kit.say('Download the module zip from the releases page on GitHub, or build it from the repository with build dot s h.', {
  caption: 'Download the zip from <b>GitHub Releases</b>, or build it: <b>./build.sh</b>',
  during: () => kit.terminal({ title: 'PrestaShop-Supertext-Translation', lines: [
    { cmd: './build.sh' },
    { out: `dist/supertext-${version}.zip` },
  ] }),
})
await kit.hold(300)

// 2. The shop's languages (they must exist first).
await show(links.languages, () => page.locator('table').first().waitFor())
await kit.say('Your shop needs its languages first. These are under International, Localization, Languages.', {
  caption: 'The shop’s languages: <b>International → Localization → Languages</b>',
  during: async () => { await kit.hold(500); await kit.moveTo(page.locator('td', { hasText: 'Deutsch (Schweiz)' }).first()) },
})

// 3. Modules → Module Manager → Upload a module.
await show(links.modules, () => page.locator('#page-header-desc-configuration-add_module').waitFor())
const upload = page.locator('#page-header-desc-configuration-add_module')
await kit.say('Then open Modules, Module Manager, and click Upload a module.', {
  caption: '<b>Modules → Module Manager</b> → <b>Upload a module</b>',
  during: async () => {
    await kit.click(upload)
    await page.locator('#module-modal-import .dropzone, #importDropzone').first().waitFor({ state: 'visible' })
    await kit.sync()
  },
})
const dropzone = page.locator('#importDropzone, #module-modal-import .dropzone').first()
await kit.say(`Drop the zip file here. PrestaShop installs the module right away.`, {
  caption: `Drop <b>supertext-${version}.zip</b> – PrestaShop installs it`,
  during: async () => {
    await kit.moveTo(dropzone)
    await kit.hold(400)
    await ripple()
    await page.locator('#module-modal-import input.dz-hidden-input').setInputFiles(zip)
    await kit.hold(1200)
  },
})
const configure = page.locator('#module-modal-import a, #module-modal-import button', { hasText: /Configure/ }).first()
kit.pause()
await configure.waitFor({ state: 'visible', timeout: 300_000 })
kit.resume()
await kit.sync()
await kit.say('Installed. Click Configure.', { caption: 'Module installed: click <b>Configure</b>', during: () => kit.moveTo(configure) })
await open(configure, () => page.locator('#supertext-api-key').waitFor())

// 4. The API key.
const keyField = page.locator('#supertext-api-key')
await kit.say('Generate your API key at supertext.com, under Integrations, A P I. No account yet? Create one at supertext.com.', {
  caption: 'API key: <b>supertext.com → Integrations → API</b> (Admin role)<br>No account yet? Create one at <b>supertext.com</b>',
  during: async () => { await kit.hold(600); await kit.type(keyField, FAKE_KEY) },
})
const saveConnection = page.locator('#supertext-connection button[name=submitSupertextSettings]')
await kit.say('Paste it into Supertext API key, and click Save.', {
  caption: 'Paste it into <b>Supertext API key</b>, click <b>Save</b>',
  during: () => kit.moveTo(saveConnection),
  after: 0,
})
await open(saveConnection, () => page.locator('#supertext-languages').waitFor())
const ok = page.getByText('the connection to Supertext works', { exact: false }).first()
await kit.say('Saving checks the key: the connection to Supertext works.', {
  caption: 'Key check: <b>the connection to Supertext works</b>',
  during: async () => { await kit.hold(300); await kit.moveTo(ok) },
})

// 5. The Supertext language per shop language.
const languages = page.locator('#supertext-languages')
const german = languages.locator('tbody tr').filter({ has: page.locator('td:first-child', { hasText: 'Deutsch' }) }).first()
await kit.say('Under Languages, every shop language has its Supertext language, already suggested.', {
  caption: '<b>Languages</b>: a <b>Supertext language</b> per shop language',
  during: async () => {
    await languages.evaluate((e) => window.scrollBy({ top: e.getBoundingClientRect().top - 70, behavior: 'smooth' }))
    await kit.hold(1000)
    await kit.moveTo(german.locator('select.st-language'))
  },
})
await kit.say('For Deutsch, Schweiz, we keep German, Switzerland. Pick another variant here if you need one.', {
  caption: 'Deutsch (Schweiz): <b>German (Switzerland) (de-CH)</b>',
  during: async () => {
    await ripple()
    await german.locator('select.st-language').selectOption('de-CH') // a native list doesn't show in the recording
    await kit.hold(400)
  },
})
await kit.say('Translation review can be switched on per language. We leave it off.', {
  caption: '<b>Translation review</b>: optional, per language (left off)',
  during: async () => { await kit.hold(200); await kit.moveTo(german.locator('input[type=checkbox]').first()) },
})
const saveLanguages = languages.locator('button[name=submitSupertextSettings]')
await kit.say('Click Save.', { caption: 'Click <b>Save</b>', during: () => kit.moveTo(saveLanguages), after: 0 })
await open(saveLanguages, () => page.locator('#supertext-languages').waitFor())

// 6. Where editors find it.
await show(links.products, async () => {
  await page.locator('tr', { hasText: 'ST-DEMO-PRALINES' }).first().waitFor()
  // The demo keeps no files between starts, so its product thumbnails are gone: hide the broken images.
  await page.addStyleTag({ content: 'table td img{display:none!important}' })
})
const row = page.locator('tr', { hasText: 'ST-DEMO-PRALINES' }).first()
const action = page.locator('button', { hasText: 'Translate with Supertext' }).first()
await kit.say("That's it. Editors now tick products, categories or pages, and choose Translate with Supertext in the bulk actions.", {
  caption: 'Done: editors find <b>Translate with Supertext</b> in the <b>Bulk actions</b>',
  during: async () => {
    await kit.moveTo(row.locator('td').first())
    await ripple()
    await row.locator('input[name="product_bulk[]"]').check({ force: true })
    await kit.click(page.locator('.js-bulk-actions-btn').first())
    await action.waitFor({ state: 'visible' })
    await kit.hold(300)
    await kit.moveTo(action)
  },
  after: 200,
})
await kit.caption('')

fs.mkdirSync(path.dirname(out), { recursive: true })
await kit.finish(out, { system: 'PrestaShop', repo: 'PrestaShop-Supertext-Translation', kind: 'install' })
await browser.close()
fs.rmSync(workDir, { recursive: true, force: true })
console.log(`Saved ${out}`)
