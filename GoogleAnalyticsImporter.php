<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\GoogleAnalyticsImporter;

use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\DataTable;
use Piwik\Date;
use Piwik\Option;
use Piwik\Period\Factory;
use Piwik\Piwik;
use Piwik\Plugin\ViewDataTable;
use Piwik\Plugins\Referrers\API;
use Piwik\Site;
use Psr\Log\LoggerInterface;

class GoogleAnalyticsImporter extends \Piwik\Plugin
{
    const OPTION_ARCHIVING_FINISHED_FOR_SITE_PREFIX = 'GoogleAnalyticsImporter.archivingFinished.';

    private static $keywordMethods = [
        'Referrers.getKeywords',
        'Referrers.getKeywordsForPageUrl',
        'Referrers.getKeywordsForPageTitle',
        'Referrers.getKeywordsFromSearchEngineId',
        'Referrers.getKeywordsFromCampaignId',
    ];

    private static $unsupportedDataTableReports = [
        "Actions.getDownloads",
        "Actions.getDownload",
        "Actions.getOutlinks",
        "Actions.getOutlink",
        "Actions.getVisitInformationPerLocalTime",
    ];

    public function getListHooksRegistered()
    {
        return [
            'AssetManager.getJavaScriptFiles'        => 'getJsFiles',
            'AssetManager.getStylesheetFiles'        => 'getStylesheetFiles',
            'CronArchive.archiveSingleSite.finish' => 'archivingFinishedForSite',
            'Visualization.beforeRender' => 'configureImportedReportView',
            'Translate.getClientSideTranslationKeys' => 'getClientSideTranslationKeys',
            'API.Request.dispatch.end' => 'translateNotSetLabels',
            'SitesManager.deleteSite.end'            => 'onSiteDeleted',
            'Template.jsGlobalVariables' => 'addImportedDateRangesForSite',
        ];
    }

    public function addImportedDateRangesForSite(&$out)
    {
        $range = $this->getImportedSiteImportDateRange();
        if (empty($range)) {
            return;
        }

        list($startDate, $endDate) = $range;

        $out .= "\npiwik.importedFromGoogleStartDate = " . json_encode($startDate->toString()) . ";\n";
        $out .= "piwik.importedFromGoogleEndDate = " . json_encode($endDate->toString()) . ";\n";
    }

    public function onSiteDeleted($idSite)
    {
        $importStatus = StaticContainer::get(ImportStatus::class);
        try {
            $importStatus->deleteStatus($idSite);
        } catch (\Exception $ex) {
            // ignore
        }
    }

    public function getJsFiles(&$jsFiles)
    {
        $jsFiles[] = "plugins/GoogleAnalyticsImporter/angularjs/import-status/import-status.controller.js";
        $jsFiles[] = "plugins/GoogleAnalyticsImporter/angularjs/import-scheduler/import-scheduler.controller.js";
        $jsFiles[] = "plugins/GoogleAnalyticsImporter/angularjs/widget-events/widget-events.run.js";
    }

    public function getStylesheetFiles(&$stylesheets)
    {
        $stylesheets[] = "plugins/GoogleAnalyticsImporter/stylesheets/styles.less";
    }

    public function getClientSideTranslationKeys(&$translationKeys)
    {
        $translationKeys[] = 'GoogleAnalyticsImporter_InvalidDateFormat';
        $translationKeys[] = 'GoogleAnalyticsImporter_LogDataRequiredForReport';
    }

    public function translateNotSetLabels(&$returnedValue, $params)
    {
        if (!($returnedValue instanceof DataTable\DataTableInterface)) {
            return;
        }

        $translation = Piwik::translate('GoogleAnalyticsImporter_NotSetInGA');
        $labelToLookFor = RecordImporter::NOT_SET_IN_GA_LABEL;

        $method = Common::getRequestVar('method');
        if (in_array($method, self::$keywordMethods)) {
            $translation = API::getKeywordNotDefinedString();
            $labelToLookFor = '(not provided)';
        }

        $returnedValue->filter(function (DataTable $table) use ($translation, $labelToLookFor) {
            $row = $table->getRowFromLabel($labelToLookFor);
            if (!empty($row)) {
                $row->setColumn('label', $translation);
            }

            foreach ($table->getRows() as $childRow) {
                $subtable = $childRow->getSubtable();
                if ($subtable) {
                    $this->translateNotSetLabels($subtable, []);
                }
            }
        });
    }

    public function configureImportedReportView(ViewDataTable $view)
    {
        $table = $view->getDataTable();
        if (empty($table)
            || !($table instanceof DataTable)
        ) {
            return;
        }

        $period = Common::getRequestVar('period', false);
        $date = Common::getRequestVar('date', false);
        if (empty($period) || empty($date)) {
            return;
        }

        $module = Common::getRequestVar('module');
        $action = Common::getRequestVar('action');

        if ($module == 'Live') {
            if ($table->getRowsCount() > 0
                || !$this->isInImportedDateRange($period, $date)
            ) {
                return;
            }

            $view->config->show_footer_message .= '<br/>' . Piwik::translate('GoogleAnalyticsImporter_LiveDataUnavailableForImported');
            return;
        }

        // check for unsupported reports
        $method = "$module.$action";
        if (in_array($method, self::$unsupportedDataTableReports)) {
            if ($table->getRowsCount() > 0
                || !$this->isInImportedDateRange($period, $date)
            ) {
                return;
            }

            $view->config->show_footer_message .= '<br/>' . Piwik::translate('GoogleAnalyticsImporter_UnsupportedReportInImportRange');
            return;
        }

        // check report based on period
        if ($period === 'day') {
            // for day periods we can tell if the report is in GA through metadata
            $isImportedFromGoogle = $table->getMetadata(RecordImporter::IS_IMPORTED_FROM_GOOGLE_METADATA_NAME);
            if (!$isImportedFromGoogle) {
                return;
            }

            $view->config->show_footer_message .= '<br/>' . Piwik::translate('GoogleAnalyticsImporter_ThisReportWasImportedFromGoogle');
        } else {
            // for non-day periods, we can't tell if the report is all GA data or mixed, so we guess based on
            // whether the data is in the imported date range
            if (!$this->isInImportedDateRange($period, $date)) {
                return;
            }

            $view->config->show_footer_message .= '<br/>' . Piwik::translate('GoogleAnalyticsImporter_ThisReportWasImportedFromGoogleMultiPeriod');
        }
    }

    public function archivingFinishedForSite($idSite, $completed)
    {
        /** @var LoggerInterface $logger */
        $logger = StaticContainer::get(LoggerInterface::class);

        /** @var ImportStatus $importStatus */
        $importStatus = StaticContainer::get(ImportStatus::class);

        try {
            $importStatus->getImportStatus($idSite);
        } catch (\Exception $ex) {
            return;
        }

        $dateFinished = getenv(Tasks::DATE_FINISHED_ENV_VAR);
        if (empty($dateFinished)) {
            $logger->debug('Archiving for imported site was finished, but date environment variable not set. Cannot mark day as complete.');
            return;
        }

        $dateFinished = Date::factory($dateFinished);

        if (!$completed) {
            $logger->info("Archiving for imported site (ID = {idSite}) was not completed successfully. Will try again next run.", [
                'idSite' => $idSite,
            ]);
            return;
        }

        $importStatus->importArchiveFinished($idSite, $dateFinished);
    }

    private function isInImportedDateRange($period, $date) // TODO: cache the result of this
    {
        $range = $this->getImportedSiteImportDateRange();
        if (empty($range)) {
            return false;
        }

        list($startDate, $endDate) = $range;

        $periodObj = Factory::build($period, $date);
        if ($startDate->isLater($periodObj->getDateEnd())
            || $endDate->isEarlier($periodObj->getDateStart())
        ) {
            return false;
        }

        return true;
    }

    private function getImportedSiteImportDateRange()
    {
        $idSite = Common::getRequestVar('idSite', false);
        if (empty($idSite)) {
            return null;
        }

        $importStatus = StaticContainer::get(ImportStatus::class);
        try {
            $status = $importStatus->getImportStatus($idSite);
        } catch (\Exception $ex) {
            $status = [];
        }

        $lastDateImported = isset($status['last_date_imported']) ? $status['last_date_imported'] : null;

        $importedDateRange = $importStatus->getImportedDateRange($idSite);
        if (empty($importedDateRange)
            || empty($importedDateRange[0])
            || empty($importedDateRange[1])
        ) {
            return null;
        }

        $startDate = Date::factory($importedDateRange[0] ?: Site::getCreationDateFor($idSite));
        $endDate = Date::factory($importedDateRange[1] ?: $lastDateImported ?: $startDate);

        return [$startDate, $endDate];
    }
}
