#!/usr/bin/env node
/**
 * Records the demo movie (docs/images/demo.gif and an MP4) from a FRESH demo whose module talks to
 * stand-in.mjs (SUPERTEXT_API_ENDPOINT=http://127.0.0.1:8765/v1/), like screenshots.mjs. The demo
 * editor opens the English product "Dark chocolate praline box", translates it with Supertext into
 * German and French from the product list and checks the German version on the product page.
 * See "Demo movie" in docs/DEVELOPER.md.
 *
 *   BASE_URL=http://localhost:8090/admin-dev DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… node movie.mjs
 *
 * Voice: Piper (pip install piper-tts), voice "alba" downloaded once into movie/voice-cache/
 * (git-ignored); MOVIE_VOICE=0 makes a silent movie. MOVIE_OUT: where the MP4 goes (default
 * movie/out/PrestaShop-Supertext-Translation.mp4, git-ignored; the MP4 is not committed). Needs ffmpeg.
 */
import { chromium } from 'playwright'
import { fileURLToPath } from 'node:url'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { MovieKit } from './movie/movie-kit.mjs'

const B = (process.env.BASE_URL || 'http://localhost:8090/admin-dev').replace(/\/$/, '')
const here = (p) => fileURLToPath(new URL(p, import.meta.url))
const out = process.env.MOVIE_OUT || here('movie/out/PrestaShop-Supertext-Translation.mp4')
const gif = here('../../docs/images/demo.gif')
const need = (name) => process.env[name] || (() => { throw new Error(`Set ${name}`) })()
const PRODUCT = 'Dark chocolate praline box'
const nameField = () => page.locator('input[id^="product_header_name_"]:visible').first()

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined })
const page = await browser.newPage({ viewport: { width: 1280, height: 720 }, deviceScaleFactor: 1, locale: 'en-US' })
page.setDefaultTimeout(90_000) // PrestaShop builds its cache on the first visit of a page

// Sign in as the demo editor (not recorded).
await page.goto(`${B}/index.php`)
await page.fill('#email', need('DEMO_EDITOR_EMAIL'))
await page.fill('#passwd', need('DEMO_EDITOR_PASSWORD'))
await page.click('#submit_login')
await page.waitForLoadState('networkidle')

/** Catalog → Products, and the row of the sample product. */
const productRow = () => page.locator('tr', { hasText: 'ST-DEMO-PRALINES' }).first()
async function products() {
  await page.goto(`${B}/index.php?controller=AdminProducts`)
  await productRow().waitFor()
}
await products()
const productUrl = await productRow().locator('a', { hasText: PRODUCT }).first().evaluate((a) => a.href)
// Warm the product page up (first visits are slow), then start on it.
await page.goto(productUrl)
await page.waitForLoadState('networkidle')
await page.goto(productUrl)
await page.waitForLoadState('networkidle')
await page.locator('#product_header_name_dropdown').waitFor()

const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'prestashop-movie-'))
const kit = await MovieKit.start(page, { workDir, assets: here('movie/assets'), voiceCache: here('movie/voice-cache') })
const ripple = () => page.evaluate(([x, y]) => window.__stk?.ripple(x, y), [kit.cursor.x, kit.cursor.y])

/** Clicks a link and cuts the page load out of the movie. */
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

// 1. The English product.
await kit.say("Here's a product in English, in the PrestaShop back office.", {
  caption: `A product in English: “${PRODUCT}”`,
  during: async () => { await kit.hold(500); await kit.moveTo(nameField()) },
})

// 2. Catalog → Products, tick it, Bulk actions → Translate with Supertext.
const nav = page.locator('#subtab-AdminProducts a').first()
await kit.say('Open the product list, under Catalog, Products.', {
  caption: 'Open <b>Catalog → Products</b>',
  during: () => kit.moveTo(nav),
})
await open(nav, () => productRow().waitFor())
await kit.say('Tick the product.', {
  caption: 'Tick the product',
  during: async () => {
    await kit.moveTo(productRow().locator('td').first())
    await ripple()
    await productRow().locator('input[name="product_bulk[]"]').check({ force: true })
  },
})
const bulk = page.locator('.js-bulk-actions-btn').first()
const translateAction = page.locator('button', { hasText: 'Translate with Supertext' }).first()
await kit.say('Open Bulk actions, and choose Translate with Supertext.', {
  caption: '<b>Bulk actions</b> → <b>Translate with Supertext</b>',
  during: async () => {
    await kit.click(bulk)
    await translateAction.waitFor({ state: 'visible' })
    await kit.hold(400)
    await kit.moveTo(translateAction)
  },
})
await open(translateAction, () => page.locator('.supertext-page .st-go').waitFor())

// 3. Pick the languages and start.
const root = page.locator('.supertext-page')
await kit.say('Keep German and French, and untick Italian.', {
  caption: 'Choose the languages: <b>Deutsch</b> and <b>Français</b>',
  during: async () => {
    await kit.hold(700)
    await kit.click(root.locator('label', { hasText: 'Italiano' }).first())
  },
})
const go = root.locator('.st-go')
await kit.say('Then click Translate.', { caption: 'Click <b>Translate</b>', during: () => kit.moveTo(go), after: 0 })

// The stand-in answers at once: let the first request wait until the progress state has been seen.
let release
const released = new Promise((resolve) => (release = resolve))
await page.route(/supertext/, async (route) => {
  if (route.request().method() === 'POST') await released
  await route.continue()
})
await ripple()
await kit.say('Supertext translates the content.', {
  caption: 'Supertext translates the content…',
  during: async () => {
    await go.click()
    await kit.hold(1300)
    release()
  },
})
kit.pause()
await root.locator('.st-summary').waitFor({ state: 'visible', timeout: 300_000 })
kit.resume()
await kit.sync()

// 4. The result: the German version on the product page.
await kit.say('A few seconds later, the product is translated into German and French.', {
  caption: 'Done: translated into German and French',
  during: async () => { await kit.hold(300); await kit.moveTo(root.locator('.st-summary')) },
})
const link = root.locator('.st-table a', { hasText: PRODUCT }).first()
await kit.say("Let's open the product and check the German version.", {
  caption: 'Open the product to check it',
  during: () => kit.moveTo(link),
})
await open(link, () => page.locator('#product_header_name_dropdown').waitFor())
await kit.say('Switch the language to German.', {
  caption: 'Switch the language to <b>de</b>',
  during: async () => {
    await kit.click(page.locator('#product_header_name_dropdown'))
    const de = page.locator('.js-locale-item[data-locale="de"]').first()
    await de.waitFor({ state: 'visible' })
    await kit.hold(300)
    await kit.click(de)
  },
})
await kit.say('The German version is ready. Review it, and save the product as usual.', {
  caption: 'The German version is ready – review it and save',
  during: async () => {
    await kit.moveTo(nameField())
    await kit.hold(900)
    await page.locator('#product_description_description_short').evaluate((e) => e.scrollIntoView({ behavior: 'smooth', block: 'center' }))
    await kit.hold(900)
    await kit.moveTo(page.locator('iframe[id^="product_description_description_short_"]:visible').first())
  },
})
await kit.caption('')

fs.mkdirSync(path.dirname(out), { recursive: true })
await kit.finish(out, { system: 'PrestaShop', repo: 'PrestaShop-Supertext-Translation', gif })
await browser.close()
fs.rmSync(workDir, { recursive: true, force: true })
console.log(`Saved ${out} and ${gif} (${(fs.statSync(gif).size / 1e6).toFixed(2)} MB)`)
