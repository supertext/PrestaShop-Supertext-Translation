<?php

/**
 * Supertext Translation for PrestaShop: translates products, categories and CMS pages
 * into the shop's other languages with Supertext AI.
 *
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PrestaShop\PrestaShop\Adapter\SymfonyContainer;
use PrestaShop\PrestaShop\Core\Grid\Action\Bulk\Type\SubmitBulkAction;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\Type\LinkRowAction;
use PrestaShop\PrestaShop\Core\Grid\Definition\GridDefinitionInterface;
use Supertext\PrestaShop\Api\LanguageCodes;
use Supertext\PrestaShop\Api\Reporting;
use Supertext\PrestaShop\Api\SupertextClient;
use Supertext\PrestaShop\PluginHealth\KeyCheck;
use Supertext\PrestaShop\Settings;

class Supertext extends Module
{
    public const REPOSITORY = 'https://github.com/Supertext/PrestaShop-Supertext-Translation';

    private const HOOKS = [
        'actionProductGridDefinitionModifier',
        'actionCategoryGridDefinitionModifier',
        'actionCmsPageGridDefinitionModifier',
        'displayAdminProductsExtra',
    ];

    public function __construct()
    {
        $this->name                   = 'supertext';
        $this->tab                    = 'i18n_localization';
        $this->version                = '0.1.0';
        $this->author                 = 'Supertext';
        $this->need_instance          = 0;
        $this->bootstrap              = true;
        $this->ps_versions_compliancy = ['min' => '8.1.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->trans('Supertext Translation', [], 'Modules.Supertext.Admin');
        $this->description = $this->trans('Translate products, categories and CMS pages into your other shop languages with Supertext AI.', [], 'Modules.Supertext.Admin');
        $this->confirmUninstall = $this->trans('Remove the Supertext settings? Translations made so far stay in your shop.', [], 'Modules.Supertext.Admin');
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function install(): bool
    {
        return parent::install()
            && $this->registerHook(self::HOOKS)
            && $this->grantViewToAllProfiles()
            && Configuration::updateGlobalValue(Settings::ENVIRONMENT, 'live')
            && Configuration::updateGlobalValue(Settings::TIMEOUT, 180)
            && Configuration::updateGlobalValue(Settings::VERSION_REPORTS, 1);
    }

    /**
     * PrestaShop shows a module's back-office output (the button in the product page's
     * Modules tab) only to profiles with "view" permission on the module, which a new module
     * gets for SuperAdmin only. Let every profile see it; who can translate is still decided
     * by the edit permission on products, categories and pages. Configuring stays SuperAdmin.
     */
    public function grantViewToAllProfiles(): bool
    {
        $roleId = (int) Db::getInstance()->getValue('SELECT id_authorization_role FROM ' . _DB_PREFIX_
            . "authorization_role WHERE slug = 'ROLE_MOD_MODULE_" . pSQL(strtoupper($this->name)) . "_READ'");

        if (!$roleId) {
            return true;
        }

        foreach (Profile::getProfiles((int) Configuration::get('PS_LANG_DEFAULT')) as $profile) {
            Db::getInstance()->execute('INSERT IGNORE INTO ' . _DB_PREFIX_ . 'module_access (id_profile, id_authorization_role) VALUES ('
                . (int) $profile['id_profile'] . ', ' . $roleId . ')');
        }

        return true;
    }

    public function uninstall(): bool
    {
        foreach (Settings::ALL as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    /* ------------------------------------------------------------------ settings page */

    public function getContent(): string
    {
        $messages = '';

        if (Tools::isSubmit('submitSupertextSettings')) {
            [$html, $saved] = $this->saveSettings();
            $messages .= $html;

            if ($saved) {
                // Commando Center: check the key and report the save (never blocks or fails saving).
                $check = Settings::reporting()->afterSettingsSaved(Settings::apiKey())?->check;
                $messages .= $check !== null ? $this->displayKeyCheck($check) : '';
            }
        } elseif (Tools::isSubmit('submitSupertextTest')) {
            $check = Settings::reporting()->testConnection(Settings::apiKey())?->check;
            $messages .= $check !== null ? $this->displayKeyCheck($check) : $this->displayError($this->noKeyMessage());
        } else {
            Settings::reportAfterResponse();
        }

        Settings::migrateLanguages();

        $languages = [];
        $saved     = Settings::languages();

        foreach (Language::getLanguages(false) as $language) {
            $id         = (int) $language['id_lang'];
            $locale     = Settings::localeOf(new Language($id));
            $code       = $saved[$id]['code'] ?? '';
            $tone       = $saved[$id]['tone'] ?? 'default';
            $review     = $saved[$id]['review'] ?? false;
            $suggestion = LanguageCodes::suggest($locale);
            $selected   = $code !== '' ? $code : (string) $suggestion;
            $target     = LanguageCodes::targetOrNull($locale, $code);
            $politeness = $target !== null ? LanguageCodes::supportsPoliteness($target) : null;

            $languages[] = [
                'id'                => $id,
                'name'              => $language['name'],
                'locale'            => $locale,
                'code'              => $selected,
                'choose'            => $selected === '',
                'custom'            => $selected !== '' && !LanguageCodes::isTarget($selected),
                'target'            => $target,
                'tone'              => $tone,
                'review'            => $review,
                // Formal/informal only where the chosen target offers it (unknown codes: shown, Supertext decides).
                'politeness'        => $politeness !== false,
                'isDefault'         => $id === (int) Configuration::get('PS_LANG_DEFAULT'),
                'active'            => (bool) $language['active'],
            ];
        }

        $key   = (string) Configuration::getGlobalValue(Settings::API_KEY);
        $check = Settings::reporting()->lastCheck();

        $this->context->smarty->assign([
            'st'           => [
                'formUrl'        => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]),
                'hasKey'         => $key !== '',
                'keyHint'        => $key !== '' ? '…' . substr(Settings::apiKey(), -4) : '',
                'keyFromEnv'     => Settings::apiKeyFromEnvironment(),
                'endpointFromEnv' => Settings::endpointFromEnvironment(),
                'environment'    => Settings::environment(),
                'endpoint'       => Settings::customEndpoint(),
                'reviewUrl'      => Settings::reviewUrlSetting(),
                'reviewUrlFromEnv' => Settings::reviewUrlFromEnvironment(),
                'reviewOff'      => Settings::reviewUrl() === '',
                'timeout'        => Settings::timeout(),
                'keyCheck'       => $check === null ? null : [
                    'status'  => $check->status,
                    'message' => $this->keyCheckMessage($check),
                    'time'    => Tools::displayDate(date('Y-m-d H:i:s', (int) strtotime($check->checkedAt)), true),
                ],
                'versionReports'        => Settings::versionReportsSetting(),
                'versionReportsEnabled' => Settings::versionReportsEnabled(),
                'versionReportsFromEnv' => Reporting::forcedByEnvironment(),
                'languages'      => $languages,
                'targets'        => LanguageCodes::options((string) ($this->context->language->locale ?? 'en')),
                'languagesUrl'   => $this->context->link->getAdminLink('AdminLanguages'),
                'signupUrl'      => Settings::SIGNUP_URL,
                'apiKeyUrl'      => Settings::API_KEY_URL,
                'version'        => $this->version,
                'releaseUrl'     => preg_match('/^\d+\.\d+\.\d+$/', $this->version) ? self::REPOSITORY . '/releases/tag/v' . $this->version : '',
                'docsUrl'        => self::REPOSITORY . '/blob/main/docs/USER_GUIDE.md',
                'productsUrl'    => $this->context->link->getAdminLink('AdminProducts'),
            ],
        ]);

        return $messages . $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    /** @return array{0: string, 1: bool} the message and whether the settings were saved */
    private function saveSettings(): array
    {
        $key = trim((string) Tools::getValue('api_key'));

        if (Tools::getValue('remove_api_key')) {
            Configuration::updateGlobalValue(Settings::API_KEY, '');
        } elseif ($key !== '') {
            Configuration::updateGlobalValue(Settings::API_KEY, SupertextClient::normalizeKey($key));
        }

        $environment = (string) Tools::getValue('environment');
        Configuration::updateGlobalValue(Settings::ENVIRONMENT, in_array($environment, ['live', 'staging', 'testing'], true) ? $environment : 'live');

        $endpoint = trim((string) Tools::getValue('endpoint'));

        if ($endpoint !== '' && !preg_match('#^https?://\S+$#i', $endpoint)) {
            return [$this->displayError($this->trans('The custom API base URL must start with https://.', [], 'Modules.Supertext.Admin')), false];
        }

        $reviewUrl = trim((string) Tools::getValue('review_url'));

        if ($reviewUrl !== '' && !preg_match('#^https?://\S+$#i', $reviewUrl)) {
            return [$this->displayError($this->trans('The review service URL must start with https://.', [], 'Modules.Supertext.Admin')), false];
        }

        Configuration::updateGlobalValue(Settings::ENDPOINT, $endpoint);
        // '' turns Translation review off for every language.
        Configuration::updateGlobalValue(Settings::REVIEW_URL, $reviewUrl);
        Configuration::updateGlobalValue(Settings::TIMEOUT, max(30, min(1800, (int) Tools::getValue('timeout', 180))));
        Configuration::updateGlobalValue(Settings::VERSION_REPORTS, Tools::getValue('version_reports') ? 1 : 0);

        $languages = [];
        $saved     = Settings::languages();

        foreach ((array) Tools::getValue('languages', []) as $id => $row) {
            $code = trim((string) ($row['code'] ?? ''));
            $tone = (string) ($row['tone'] ?? 'default');
            $review = !empty($row['review']);

            // Only Supertext's codes (the list), or the code already saved (one an earlier version accepted).
            if ($code !== '' && !LanguageCodes::isTarget($code) && $code !== ($saved[(int) $id]['code'] ?? '')) {
                return [$this->displayError($this->trans('"%code%" is not a Supertext language. Choose one from the list.', ['%code%' => $code], 'Modules.Supertext.Admin')), false];
            }

            if ($code !== '' && LanguageCodes::supportsPoliteness($code) === false) {
                $tone = 'default';
            }

            if ($code !== '' || $tone !== 'default' || $review) {
                $languages[(int) $id] = ['code' => $code, 'tone' => in_array($tone, Settings::TONES, true) ? $tone : 'default', 'review' => $review];
            }
        }

        Settings::saveLanguages($languages);

        return [$this->displayConfirmation($this->trans('Settings saved.', [], 'Modules.Supertext.Admin')), true];
    }

    /** The key check as a notice: success, warning (Supertext unreachable or an error) or error (rejected, missing). */
    private function displayKeyCheck(KeyCheck $check): string
    {
        $message = $this->keyCheckMessage($check);

        return match ($check->status) {
            KeyCheck::OK                         => $this->displayConfirmation($message),
            KeyCheck::INVALID, KeyCheck::MISSING => $this->displayError($message),
            default                                 => $this->displayWarning($message),
        };
    }

    /** The Commando Center's key-check messages, with the account and API key links for "invalid" and "missing" (HTML). */
    private function keyCheckMessage(KeyCheck $check): string
    {
        $links = [
            '%signup%' => '<a href="' . Settings::SIGNUP_URL . '" target="_blank" rel="noopener">supertext.com</a>',
            '%apikey%' => '<a href="' . Settings::API_KEY_URL . '" target="_blank" rel="noopener">supertext.com → Integrations → API</a>',
        ];
        $status = htmlspecialchars((string) ($check->httpStatus ?? $check->errorCode ?? '?'), ENT_QUOTES, 'UTF-8');

        return match ($check->status) {
            KeyCheck::OK          => $this->trans('API key checked: the connection to Supertext works.', [], 'Modules.Supertext.Admin'),
            KeyCheck::INVALID     => $this->trans('Supertext rejected the API key. Check that you copied the whole key, or generate a new one at %apikey% (requires the Admin role). No Supertext account yet? Create one at %signup%.', $links, 'Modules.Supertext.Admin'),
            KeyCheck::MISSING     => $this->trans('No API key is set yet. No Supertext account yet? Create one at %signup%. Generate your API key at %apikey% (requires the Admin role).', $links, 'Modules.Supertext.Admin'),
            KeyCheck::UNREACHABLE => $this->trans('The API key couldn\'t be checked because Supertext can\'t be reached. The settings are saved; check again later.', [], 'Modules.Supertext.Admin'),
            default                  => $this->trans('The API key couldn\'t be checked: Supertext answered with an error (%status%). The settings are saved; check again later.', ['%status%' => $status], 'Modules.Supertext.Admin'),
        };
    }

    private function noKeyMessage(): string
    {
        return $this->trans('No API key is saved yet.', [], 'Modules.Supertext.Admin') . ' '
            . $this->trans('No Supertext account yet? Create one at %signup%. Generate your API key at %apikey% (requires the Admin role).', [
                '%signup%' => '<a href="' . Settings::SIGNUP_URL . '" target="_blank" rel="noopener">supertext.com</a>',
                '%apikey%' => '<a href="' . Settings::API_KEY_URL . '" target="_blank" rel="noopener">supertext.com → Integrations → API</a>',
            ], 'Modules.Supertext.Admin');
    }

    /* ------------------------------------------------------------------ back office hooks */

    public function hookActionProductGridDefinitionModifier(array $params): void
    {
        $this->addGridActions($params['definition'], 'product', 'id_product');
    }

    public function hookActionCategoryGridDefinitionModifier(array $params): void
    {
        $this->addGridActions($params['definition'], 'category', 'id_category');
    }

    public function hookActionCmsPageGridDefinitionModifier(array $params): void
    {
        $this->addGridActions($params['definition'], 'cms', 'id_cms');
    }

    /** Product page (Modules tab): a button that opens the translate page for this product. */
    public function hookDisplayAdminProductsExtra(array $params): string
    {
        $id = (int) ($params['id_product'] ?? Tools::getValue('id_product'));

        if ($id <= 0) {
            return '';
        }

        $this->context->smarty->assign('st', [
            'url'       => $this->route('supertext_translate', ['type' => 'product', 'ids' => (string) $id]),
            'hasApiKey' => Settings::apiKey() !== '',
        ]);

        return $this->display(__FILE__, 'views/templates/admin/product-extra.tpl');
    }

    /** "Translate with Supertext" as a bulk action and as a row action of a list. */
    private function addGridActions(GridDefinitionInterface $definition, string $type, string $idField): void
    {
        $label = $this->trans('Translate with Supertext', [], 'Modules.Supertext.Admin');

        $definition->getBulkActions()->add(
            (new SubmitBulkAction('supertext_translate'))
                ->setName($label)
                ->setOptions(['submit_route' => 'supertext_translate'])
        );

        foreach ($definition->getColumns() as $column) {
            if ($column->getId() !== 'actions') {
                continue;
            }

            $actions = $column->getOptions()['actions'] ?? null;

            if (is_object($actions) && method_exists($actions, 'add')) {
                $actions->add(
                    (new LinkRowAction('supertext_translate'))
                        ->setName($label)
                        ->setIcon('translate')
                        ->setOptions([
                            'route'              => 'supertext_translate',
                            'route_param_name'   => 'ids',
                            'route_param_field'  => $idField,
                            'extra_route_params' => ['type' => $type],
                        ])
                );
            }
        }
    }

    /** @param array<string, string> $parameters */
    private function route(string $name, array $parameters = []): string
    {
        return SymfonyContainer::getInstance()->get('router')->generate($name, $parameters);
    }
}
