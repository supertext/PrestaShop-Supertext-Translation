#!/usr/bin/env node
/**
 * Regenerates docs/images from a freshly started demo whose module talks to stand-in.mjs
 * (SUPERTEXT_API_ENDPOINT=http://127.0.0.1:8765/v1/). See docs/DEVELOPER.md -> Docs screenshots.
 *
 *   BASE_URL (default http://localhost:8090/admin-dev)
 *   DEMO_ADMIN_EMAIL / DEMO_ADMIN_PASSWORD     settings screens (SuperAdmin)
 *   DEMO_EDITOR_EMAIL / DEMO_EDITOR_PASSWORD   translating (Translator profile)
 */
import { chromium } from 'playwright'

const B = (process.env.BASE_URL || 'http://localhost:8090/admin-dev').replace(/\/$/, '')
const OUT = new URL('../../docs/images', import.meta.url).pathname
const pad = (r, p = 8) => ({ x: Math.max(0, r.x - p), y: Math.max(0, r.y - p), width: r.width + 2 * p, height: r.height + 2 * p })
const union = (...rs) => {
  const x = Math.min(...rs.map((r) => r.x)), y = Math.min(...rs.map((r) => r.y))
  return { x, y, width: Math.max(...rs.map((r) => r.x + r.width)) - x, height: Math.max(...rs.map((r) => r.y + r.height)) - y }
}

const browser = await chromium.launch()

async function session(email, password) {
  if (!email || !password) throw new Error('Set the DEMO_* email and password variables.')
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage()
  await page.goto(`${B}/index.php`)
  await page.fill('#email', email)
  await page.fill('#passwd', password)
  await page.click('#submit_login')
  await page.waitForLoadState('networkidle')
  const css = '*{animation:none!important;transition:none!important;caret-color:transparent!important} .st-endpoint-env{display:none!important}'
  const shot = async (name, clip) => {
    await page.addStyleTag({ content: css })
    await page.waitForTimeout(300)
    await page.screenshot({ path: `${OUT}/${name}.png`, ...(clip ? { clip } : {}) })
    console.log(`  ${name}.png`)
  }
  const box = async (selector, p) => pad(await page.locator(selector).first().boundingBox(), p)
  const go = async (path) => { await page.goto(B + path); await page.waitForLoadState('networkidle') }
  return { page, shot, box, go }
}

// --- Installation guide (SuperAdmin): enters the API key the user guide needs -----------
{
  const { page, shot, box, go } = await session(process.env.DEMO_ADMIN_EMAIL, process.env.DEMO_ADMIN_PASSWORD)

  await go('/index.php?controller=AdminModules&configure=supertext')
  await page.fill('#supertext-api-key', 'Supertext-Auth-Key docs-demo-key')
  await page.locator('#supertext-connection button[name=submitSupertextSettings]').click()
  await page.waitForLoadState('networkidle')
  await page.locator('button[name=submitSupertextTest]').click()
  await page.waitForLoadState('networkidle')
  await page.locator('text=API key checked: the connection to Supertext works.').first().waitFor()
  // The time of the key check changes with every run: show a fixed one, so the image only
  // changes when the screen does.
  await page.locator('#supertext-key-check small').evaluateAll((els) => els.forEach((e) => { e.textContent = 'Checked 10/01/2026 09:00:00' }))
  await shot('module-settings', union(await box('.alert-success', 2), await box('#supertext-connection', 2)))

  // An example override: formal German (not saved).
  await page.locator('#supertext-languages select[name$="[tone]"]').nth(1).selectOption('more')
  await page.locator('#supertext-languages').evaluate((e) => e.scrollIntoView({ block: 'start' }))
  await shot('module-languages', union(await box('#supertext-languages', 2), await box('#supertext-languages table', 2), await box('#supertext-languages .panel-footer', 2)))

  await go('/index.php?controller=AdminLanguages')
  const table = await page.locator('table').first().boundingBox()
  await shot('languages', { x: 208, y: 0, width: 1072, height: table.y + table.height + 8 })
}

// --- User guide (editor account) -------------------------------------------------------
{
  const { page, shot, box, go } = await session(process.env.DEMO_EDITOR_EMAIL, process.env.DEMO_EDITOR_PASSWORD)

  // Products list: two sample products ticked, bulk actions open.
  await go('/index.php?controller=AdminProducts')
  const ids = await page.locator('tr', { hasText: /ST-DEMO-/ }).locator('input[name="product_bulk[]"]').evaluateAll((els) => els.map((e) => e.value))
  for (const id of ids) await page.locator(`input[name="product_bulk[]"][value="${id}"]`).check({ force: true })
  await page.locator('.js-bulk-actions-btn').first().click()
  await page.locator('.dropdown-menu.show, .dropdown-menu:visible').first().waitFor()
  const menu = await page.locator('button', { hasText: 'Translate with Supertext' }).first().boundingBox()
  const rows = await page.locator('tr', { hasText: /ST-DEMO-PRALINES/ }).first().boundingBox()
  const header = await page.locator('.card-header, h3', { hasText: /Products/ }).first().boundingBox()
  await shot('products-bulk-action', pad(union(header, menu, rows)))

  await page.locator('button', { hasText: 'Translate with Supertext' }).first().click()
  await page.waitForLoadState('networkidle')
  await shot('translate-page', await box('.supertext-page .card'))

  await page.locator('.st-go').click()
  await page.locator('.st-summary').waitFor({ state: 'visible', timeout: 120000 })
  await shot('translate-results', await box('.supertext-page .card'))

  // The German translation on the product page.
  await page.locator('.st-table a', { hasText: 'Dark chocolate praline box' }).click()
  await page.waitForLoadState('networkidle')
  await page.locator('#product_header_name_dropdown').click()
  await page.locator('.js-locale-item[data-locale="de"]').first().click()
  await page.waitForTimeout(800)
  await shot('translated-product', { x: 208, y: 52, width: 1072, height: 760 })

  // Product page, Modules tab.
  await page.locator('.nav-link', { hasText: /^\s*Modules\s*$/ }).first().click()
  await page.waitForTimeout(500)
  await page.locator('#product_extra_modules button[data-target="module-supertext"]').click()
  await page.locator('.supertext-product-extra').waitFor({ state: 'visible' })
  await shot('product-modules-tab', await box('.supertext-product-extra', 0))

  // Categories list: the row action; then a page that is already translated: overwrite warning.
  await go('/index.php?controller=AdminCategories')
  const catRow = page.locator('tr', { hasText: 'Swiss chocolate' }).first()
  await catRow.locator('.dropdown-toggle').first().click()
  await page.locator('a.grid-translate-with-supertext-row-link:visible').first().waitFor()
  await shot('category-row-action', pad(union(await page.locator('table thead').first().boundingBox(), await catRow.boundingBox(), await page.locator('a.grid-translate-with-supertext-row-link:visible').first().boundingBox())))

  await page.locator('a.grid-translate-with-supertext-row-link:visible').first().click()
  await page.waitForLoadState('networkidle')
  await page.locator('#st-lang-' + (await page.locator('.st-target').nth(1).getAttribute('value'))).uncheck()
  await page.locator('#st-lang-' + (await page.locator('.st-target').nth(2).getAttribute('value'))).uncheck()
  await page.locator('.st-go').click()
  await page.locator('.st-summary').waitFor({ state: 'visible', timeout: 120000 })
  await page.reload()
  await page.waitForLoadState('networkidle')
  await page.check('#st-overwrite')
  await shot('overwrite-warning', await box('.supertext-page .card'))
}

await browser.close()
console.log(`Screenshots written to ${OUT}`)
