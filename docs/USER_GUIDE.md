# User guide — Supertext Translation for PrestaShop

For shop editors. Once an administrator has set up the module (see [INSTALLATION.md](INSTALLATION.md)), you translate products, categories and CMS pages from the PrestaShop back office. Supertext writes the translation into the item's other languages; you review it like any other edit.

The module's screens follow your back-office language (English, German, French or Italian; set it in *Your profile*).

## Try it on the demo

The Supertext PrestaShop demo (ask Supertext for the address and a back-office login) has two English sample products, the category *Swiss chocolate* and the CMS page *Delivery and returns*, with German (Switzerland), French (Switzerland) and Italian (Switzerland) set up as languages. Translate them as described below, then switch the shop to another language.

## Translate products from the list

*Screenshots: PrestaShop 9.2 with the demo content.*

1. Open **Catalog → Products** and tick the product(s) you want to translate.
2. Open **Bulk actions** and choose **Translate with Supertext**.

   ![Catalog → Products with the products Milk chocolate bar with hazelnuts and Dark chocolate praline box ticked, and the Bulk actions menu open with Translate with Supertext at the bottom](images/products-bulk-action.png)

3. The **Translate with Supertext** page lists the products with a column per language showing how far each is translated already. Choose the source language (*Translate from*, the shop's default language unless you change it) and tick the languages to translate into. All other languages are ticked.

   <img src="images/translate-page.png" alt="The Translate with Supertext page for two products: translate from English into Deutsch (Schweiz) de-CH, Français (Suisse) fr-CH and Italiano (Svizzera) it-CH, all ticked; Overwrite existing translations is off; both products are Not translated in every language" width="720">

4. Click **Translate**. Each product and language takes a few seconds; the table shows the result as it goes, and a summary when everything is done.

   <img src="images/translate-results.png" alt="After translating: Milk chocolate bar with hazelnuts translated (7 fields) and Dark chocolate praline box translated (9 fields) in all three languages, and the summary Done: 6 translated, 0 unchanged, 0 failed." width="720">

The translation is saved in the product's own fields for that language, straight away: open the product and switch the language next to the product name (or in the field's language tabs) to see it. The product's status doesn't change.

<img src="images/translated-product.png" alt="The product page in German: product name Pralinenbox mit dunkler Schokolade and the German summary Zwölf handgemachte Pralinen mit 70 % dunkler Schokolade aus Bern." width="720">

## Translate categories and CMS pages

**Catalog → Categories** and **Design → Pages** have the same **Translate with Supertext** bulk action. To translate a single item, you can also open the menu at the end of its row (⋮) and choose **Translate with Supertext**; it works the same way on the product list.

![Catalog → Categories: the row menu of the category Swiss chocolate with Translate with Supertext](images/category-row-action.png)

## Translate from the product page

On a product page, open the **Modules** tab and click **Configure** under *Supertext Translation*, then **Translate with Supertext**. It translates the **saved** product, so save your changes first.

![The Supertext Translation box in the product page's Modules tab, with the Translate with Supertext button](images/product-modules-tab.png)

## Existing translations

Without any option, the module only fills fields that are **empty or still identical to the source language** (PrestaShop copies the default language into new languages, so that is what an untranslated field looks like). Fields that already have their own translation are kept, and the page says so: *Translated (5 fields) · 2 kept*, or *Already translated, unchanged* when there was nothing left to do. The language columns show *Not translated*, *Partly translated* or *Translated* before you start.

To replace existing translations, tick **Overwrite existing translations**. The page warns you first: changes made by hand in those languages are lost.

<img src="images/overwrite-warning.png" alt="Translate with Supertext for the CMS page Delivery and returns, already translated into German: Overwrite existing translations is ticked and a warning explains that fields that are already translated will be replaced and changes made by hand are lost" width="720">

## Export for translation and import translated files

Instead of translating through the Supertext API, you can download an item as a file, have it translated elsewhere and import the result. The *Translate with Supertext* page has two boxes below the table (for employees who may edit the items):

**Export for translation**

1. Open *Translate with Supertext* for the item (row action or bulk action in the list, as above) and choose the source language in *Translate from*.
2. Under **Export for translation**, pick the item, the **File format** and the languages to translate into:
   - **XLIFF 1.2 (for translation tools)**: one file for one or more languages. Choose this for translation tools such as Trados, memoQ or Phrase: formatting becomes protected tags that translators can move but not break.
   - **HTML (one language per file)**: exactly one language. You can upload it to Supertext's web app or give it to anyone who translates HTML. Translators must leave the file's head and the `data-st-id` attributes alone.
3. Click **Download file**. The file contains the same fields an API translation sends (see *What is translated*), all fields with text in the source language. An item without text says *Nothing to translate.*

Send the file to your translator or translation agency, or upload it to Supertext's web app.

**Import translation**

1. On a *Translate with Supertext* page (any list), choose the translated file under **Import translation** (XLIFF or HTML, at most 10 MB).
2. Tick **Overwrite existing translations** if fields that already have their own translation in that language should be replaced (as for API translations, they are kept otherwise). Tick **Also import texts whose source changed since the export** if texts edited in the source language after the export should still be imported (off by default: they would no longer match).
3. Click **Import translation**. The file says which item and language it belongs to; the module writes the translations into that item's language fields, the friendly URL included, as an API translation would.

The summary lists each item and language from the file:

| Line | Meaning |
| --- | --- |
| *Imported: n* | Fields written. |
| *n kept* | Already translated in the shop and *Overwrite existing translations* wasn't ticked. |
| *Skipped, source changed since the export: n* | The source text was edited after the export; tick *Also import texts whose source changed since the export* to import them anyway. |
| *Not translated in the file: n* | The translator left these empty (XLIFF only). |
| *Formatting changed, not imported: n* | The translation broke or added formatting (tags, links); fix it in the file and import again. |
| *Not in the file: n* | Fields that have text now but weren't in the file (e.g. filled in after the export). |

An HTML file can't mark a text as untranslated: imported unchanged, it writes the source text into the target language. Errors: *This file isn't a valid XLIFF or HTML translation file*, *This file wasn't exported by the Supertext module, or its header was removed*, *This file belongs to another system (…)* (exported by another Supertext plugin), *The item this file was exported from no longer exists*, *The shop has no language …*, *The file is too large (at most 10 MB)*.

## What is translated

| Item | Fields |
| --- | --- |
| Products | Name, summary, description, meta title, meta description, label when in stock, label when out of stock (back-orders allowed), delivery time of in-stock products, delivery time of out-of-stock products |
| Categories | Name, description, additional description, meta title, meta description |
| CMS pages | Title, meta title, meta description, page content |

- Rich text (summary, descriptions, page content) keeps its formatting, links and lists.
- The **friendly URL** follows the translated name (or the page title) while it is still the source language's URL, or when you overwrite. A friendly URL you set by hand is kept.
- **Not translated** (yet): tags, image captions, features and attribute values, combinations, attachments, customization fields, brands, suppliers, and the shop's theme and email texts (use *International → Translations* for those).

## Review

Open the item, switch to the language and read the fields; the translation is live as soon as the item is active in the shop. To check it in the shop, open the product, category or page there and switch the language.

**Translation review:** if your administrator turned on *Translation review* for a language, reviewers (your colleagues or Supertext linguists) check and correct the translations on the shop's website. Their corrections come into PrestaShop the next time you translate the items into that language: after a review, translate them again with *Overwrite existing translations* ticked. Texts reviewed once keep their corrected wording in later translations.

## When something goes wrong

The table shows the reason for each item and language that fails; the other ones still run.

| Message | What to do |
| --- | --- |
| *Supertext is not set up yet* | An administrator needs to enter the API key in the module settings. |
| *You are not allowed to edit this item* | Your profile can't edit this kind of item; ask an administrator. |
| *Authentication failed* | The API key is no longer valid; tell your administrator. |
| *The language … has no Supertext language* | The language has no obvious Supertext code (e.g. `en-IE`, `de-LI`) and your administrator hasn't chosen one in the module settings (*Configure → Languages*). Ask them to; the other languages are translated. On the *Translate with Supertext* page such a language can't be ticked and says *No Supertext language yet*. |
| *Supertext does not support this language pair (en → …)* | Supertext can't translate into this language, or its Supertext language in the module settings is wrong. Tell your administrator. |
| *Supertext offers no formal or informal tone for this language* | The tone set for this language isn't available at Supertext (only with a code the module's list doesn't know). Ask your administrator to set it to *Supertext default*. |
| *Too many requests to Supertext* | Wait a moment and click **Translate** again. Items already done show *Already translated, unchanged*. |
| *Your Supertext translation limit is exceeded* | Your Supertext plan's volume is used up; tell your administrator. |
| *The translation could not be saved: …* | PrestaShop rejected a translated value. Translate that field by hand. |
| *Timed out waiting for the Supertext translation* | Very long texts. Try again, or ask your administrator to raise the timeout. |

## Support

Questions, problems or ideas: use the [Supertext support form](https://integrations.supertext.com/en/support?platform=prestashop). Mention the version of PrestaShop and of the plugin, what you did and what you expected; an error message helps a lot. You get an answer by email, in your language.
