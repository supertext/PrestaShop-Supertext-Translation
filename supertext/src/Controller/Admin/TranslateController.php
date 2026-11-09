<?php

/**
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PrestaShop\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Supertext\PrestaShop\Api\Reporting;
use Supertext\PrestaShop\Api\SupertextException;
use Supertext\PrestaShop\Files\FileExchange;
use Supertext\PrestaShop\Files\FileExchangeException;
use Supertext\PrestaShop\Files\PrestaShopItemStore;
use Supertext\PrestaShop\Settings;
use Supertext\PrestaShop\Translation\EntityTranslator;
use Supertext\PrestaShop\Translation\EntityTypes;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The "Translate with Supertext" page (opened from the Products, Categories and Pages
 * lists and from the product page) and the endpoint it calls once per item and language.
 */
class TranslateController extends FrameworkBundleAdminController
{
    private const MAX_ITEMS = 100;

    /** GET ?type=product&ids=1,2&source=1, or POST from a list's bulk action (product_bulk[] …). */
    public function translateAction(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $selection = EntityTypes::fromBulkSubmission($request->request->all());

            if ($selection === null) {
                $this->addFlash('error', $this->t('Select at least one item to translate.'));

                return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('admin_products_index'));
            }

            return $this->redirectToRoute('supertext_translate', ['type' => $selection[0], 'ids' => implode(',', $selection[1])]);
        }

        $type = (string) $request->query->get('type', 'product');

        if (!isset(EntityTypes::TYPES[$type])) {
            throw $this->createNotFoundException();
        }

        $definition = EntityTypes::get($type);
        $ids        = EntityTypes::ids($request->query->all()['ids'] ?? $request->query->get('id', ''));
        $ids        = \array_slice(array_values(array_filter($ids, static fn (int $id): bool => EntityTranslator::exists($type, $id))), 0, self::MAX_ITEMS);
        $languages  = \Language::getLanguages(false);
        $sourceId   = (int) $request->query->get('source', \Configuration::get('PS_LANG_DEFAULT'));

        if (!\in_array($sourceId, array_map(static fn (array $l): int => (int) $l['id_lang'], $languages), true)) {
            $sourceId = (int) \Configuration::get('PS_LANG_DEFAULT');
        }

        $source  = new \Language($sourceId);
        $targets = array_values(array_filter($languages, static fn (array $l): bool => (int) $l['id_lang'] !== $sourceId));
        $items   = [];

        foreach ($ids as $id) {
            $states = EntityTranslator::states($type, $id, $sourceId, array_map(static fn (array $l): int => (int) $l['id_lang'], $targets));

            $items[] = [
                'id'      => $id,
                'title'   => EntityTranslator::title($type, $id, $sourceId) ?: '#' . $id,
                'editUrl' => EntityTranslator::editUrl($type, $id),
                'states'  => $states,
            ];
        }

        $targetRows = array_map(static fn (array $l): array => [
            'id'     => (int) $l['id_lang'],
            'name'   => $l['name'],
            'code'   => Settings::targetCodeOrNull(new \Language((int) $l['id_lang'])),
            'active' => (bool) $l['active'],
        ], $targets);

        $response = $this->render('@Modules/supertext/views/templates/admin/translate.html.twig', [
            'layoutTitle'   => $this->t('Translate with Supertext'),
            'type'          => $type,
            'typeLabel'     => $this->typeLabel($type),
            'items'         => $items,
            'canEdit'       => self::canEdit($definition['tab']),
            'source'        => ['id' => $sourceId, 'name' => $source->name],
            'languages'     => array_map(static fn (array $l): array => ['id' => (int) $l['id_lang'], 'name' => $l['name']], $languages),
            'targets'       => $targetRows,
            'hasApiKey'     => Settings::apiKey() !== '',
            'settingsUrl'   => \Context::getContext()->link->getAdminLink('AdminModules', true, [], ['configure' => 'supertext']),
            'signupUrl'     => Settings::SIGNUP_URL,
            'apiKeyUrl'     => Settings::API_KEY_URL,
            'runUrl'        => $this->generateUrl('supertext_translate_run'),
            'exportUrl'     => $this->generateUrl('supertext_export'),
            'importUrl'     => $this->generateUrl('supertext_import'),
            'maxUpload'     => FileExchange::MAX_UPLOAD_BYTES,
            'selfUrl'       => $this->generateUrl('supertext_translate', ['type' => $type, 'ids' => implode(',', $ids)]),
            'listUrl'       => \Context::getContext()->link->getAdminLink($definition['tab']),
            'enableSidebar' => false,
        ]);

        Settings::reportAfterResponse();

        return $response;
    }

    /** POST type, id, source, target, overwrite: translates one item into one language. */
    public function runAction(Request $request): JsonResponse
    {
        $type      = (string) $request->request->get('type');
        $id        = (int) $request->request->get('id');
        $overwrite = $request->request->getBoolean('overwrite');

        if (!isset(EntityTypes::TYPES[$type]) || $id <= 0) {
            return $this->json(['ok' => false, 'message' => $this->t('Invalid request.')], 400);
        }

        if (!self::canEdit(EntityTypes::get($type)['tab'])) {
            return $this->json(['ok' => false, 'message' => $this->t('You are not allowed to edit this item.')], 403);
        }

        $source = new \Language((int) $request->request->get('source'));
        $target = new \Language((int) $request->request->get('target'));

        if (!\Validate::isLoadedObject($source) || !\Validate::isLoadedObject($target) || (int) $source->id === (int) $target->id) {
            return $this->json(['ok' => false, 'message' => $this->t('Choose a target language other than the source language.')], 400);
        }

        if (Settings::apiKey() === '') {
            return $this->json(['ok' => false, 'message' => $this->t('Supertext is not set up yet: an administrator needs to enter the API key in the module settings.')]);
        }

        // One translation can take a while; don't let a short PHP limit cut it off.
        if (\function_exists('set_time_limit')) {
            @set_time_limit(Settings::timeout() + 60);
        }

        try {
            $result = EntityTranslator::create()->translate($type, $id, $source, $target, $overwrite, (int) \Context::getContext()->shop->id);
        } catch (SupertextException|\PrestaShopException $e) {
            \PrestaShopLogger::addLog('Supertext: ' . $e->getMessage(), 2, null, EntityTypes::get($type)['class'], $id, true);

            if ($e instanceof SupertextException && $e->isAuthenticationFailure()) {
                $status = $e->getCode() === 403 ? 403 : 401;
                Settings::reportAfterResponse(static fn (Reporting $reporting, string $key) => $reporting->authenticationFailed($key, $status));
            } else {
                Settings::reportAfterResponse();
            }

            return $this->json(['ok' => false, 'message' => $e instanceof SupertextException ? $this->errorMessage($e) : $e->getMessage()]);
        }

        Settings::reportAfterResponse();

        $kept = \count($result['kept']);

        if ($result['status'] === 'unchanged') {
            $message = $this->t('Already translated, unchanged');
        } else {
            $message = $this->t('Translated (%count% fields)', ['%count%' => \count($result['translated'])]);

            if ($kept > 0) {
                $message .= ' · ' . $this->t('%count% kept', ['%count%' => $kept]);
            }
        }

        return $this->json(['ok' => true, 'status' => $result['status'], 'message' => $message]);
    }

    /** POST type, id, source, targets[], format: downloads the item as an XLIFF or HTML file. */
    public function exportAction(Request $request): Response
    {
        $type   = (string) $request->request->get('type');
        $id     = (int) $request->request->get('id');
        $source = (int) $request->request->get('source');
        $back   = $this->generateUrl('supertext_translate', ['type' => $type, 'ids' => $id, 'source' => $source]);

        if (!isset(EntityTypes::TYPES[$type]) || $id <= 0) {
            throw $this->createNotFoundException();
        }

        if (!self::canEdit(EntityTypes::get($type)['tab'])) {
            $this->addFlash('error', $this->t('You are not allowed to edit this item.'));

            return $this->redirect($back);
        }

        $targets = EntityTypes::ids($request->request->all()['targets'] ?? []);
        $format  = $request->request->get('format') === FileExchange::HTML ? FileExchange::HTML : FileExchange::XLIFF;

        try {
            $file = (new FileExchange(new PrestaShopItemStore((int) \Context::getContext()->shop->id)))->export($type, $id, $source, $targets, $format);
        } catch (FileExchangeException $e) {
            $this->addFlash('error', $this->fileError($e->errorCode, $e->parameters));

            return $this->redirect($back);
        }

        return new Response($file['content'], 200, [
            'Content-Type'           => $file['contentType'],
            'Content-Disposition'    => 'attachment; filename="' . $file['name'] . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'no-store',
        ]);
    }

    /** POST file (multipart), include_changed, overwrite: imports a translated XLIFF or HTML file. */
    public function importAction(Request $request): JsonResponse
    {
        $upload = $request->files->get('file');

        if (!$upload instanceof UploadedFile) {
            return $this->json(['ok' => false, 'message' => $this->fileError(FileExchangeException::INVALID_FILE, [])], 400);
        }

        try {
            // Refused before anything is read; PHP's own upload limit counts as too large too.
            if (\in_array($upload->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new FileExchangeException(FileExchangeException::TOO_LARGE);
            }

            if (!$upload->isValid()) {
                throw new FileExchangeException(FileExchangeException::INVALID_FILE);
            }

            FileExchange::checkSize((int) $upload->getSize());

            $content = (string) file_get_contents($upload->getPathname());
            $results = (new FileExchange(new PrestaShopItemStore((int) \Context::getContext()->shop->id)))->import(
                $content,
                $request->request->getBoolean('include_changed'),
                $request->request->getBoolean('overwrite'),
                static fn (string $type): bool => self::canEdit(EntityTypes::get($type)['tab']),
            );
        } catch (FileExchangeException $e) {
            return $this->json(['ok' => false, 'message' => $this->fileError($e->errorCode, $e->parameters)]);
        } finally {
            // Nothing is kept: PHP removes the temporary upload at the end of the request anyway.
            if ($upload->isValid() && is_file($upload->getPathname())) {
                @unlink($upload->getPathname());
            }
        }

        $jobs = [];

        foreach ($results as $result) {
            $counts = $result['counts'];
            $lines  = [];

            if ($result['error'] === null) {
                $lines[] = $this->t('Imported: %count%', ['%count%' => $counts['ok']]);

                foreach ([
                    'kept'         => $this->t('%count% kept', ['%count%' => $counts['kept']]),
                    'changed'      => $this->t('Skipped, source changed since the export: %count%', ['%count%' => $counts['changed']]),
                    'untranslated' => $this->t('Not translated in the file: %count%', ['%count%' => $counts['untranslated']]),
                    'invalid'      => $this->t('Formatting changed, not imported: %count%', ['%count%' => $counts['invalid']]),
                    'missing'      => $this->t('Not in the file: %count%', ['%count%' => $counts['missing']]),
                ] as $key => $line) {
                    if ($counts[$key] > 0) {
                        $lines[] = $line;
                    }
                }
            }

            $jobs[] = [
                'title'    => $result['title'],
                'language' => $result['language'],
                'error'    => $result['error'] !== null ? $this->fileError($result['error']['code'], $result['error']['parameters']) : null,
                'lines'    => $lines,
            ];
        }

        return $this->json(['ok' => true, 'jobs' => $jobs]);
    }

    /** @param array<string, string|int> $parameters */
    private function fileError(string $code, array $parameters): string
    {
        if (str_starts_with($code, 'message:')) {
            return $this->t(substr($code, 8), $parameters);
        }

        return match ($code) {
            FileExchangeException::NOTHING           => $this->t('Nothing to translate.'),
            FileExchangeException::NOT_FOUND         => $this->t('The item this file was exported from no longer exists.'),
            FileExchangeException::NO_LANGUAGE       => $this->t('The shop has no language %language%.', $parameters),
            FileExchangeException::HTML_ONE_LANGUAGE => $this->t('Choose one language for an HTML file, or one or more for XLIFF.'),
            FileExchangeException::MISSING_METADATA  => $this->t('This file wasn\'t exported by the Supertext module, or its header was removed.'),
            FileExchangeException::TOO_LARGE         => $this->t('The file is too large (at most 10 MB).'),
            'other_system'                           => $this->t('This file belongs to another system (%plugin%).', $parameters),
            'not_allowed'                            => $this->t('You are not allowed to edit this item.'),
            'save_failed'                            => $this->t('The translation could not be saved: %error%', $parameters),
            default                                  => $this->t('This file isn\'t a valid XLIFF or HTML translation file.'),
        };
    }

    private static function canEdit(string $tab): bool
    {
        $employee = \Context::getContext()->employee;

        return $employee !== null && \Access::isGranted('ROLE_MOD_TAB_' . strtoupper($tab) . '_UPDATE', (int) $employee->id_profile);
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'category' => $this->t('Categories'),
            'cms'      => $this->t('Pages'),
            default    => $this->t('Products'),
        };
    }

    /** The exception's message in the employee's language; Supertext's own detail stays as sent. */
    private function errorMessage(SupertextException $e): string
    {
        $message = $this->t($e->template(), $e->parameters());

        return $e->detail() !== '' ? $message . ' (' . $e->detail() . ')' : $message;
    }

    /** @param array<string, mixed> $parameters */
    private function t(string $text, array $parameters = []): string
    {
        return \Context::getContext()->getTranslator()->trans($text, $parameters, 'Modules.Supertext.Admin');
    }
}
