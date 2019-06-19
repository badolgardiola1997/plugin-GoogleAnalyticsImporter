<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\GoogleAnalyticsImporter\Importers\CustomVariables;


use Piwik\Common;
use Piwik\Config;
use Piwik\Container\StaticContainer;
use Piwik\DataTable;
use Piwik\Date;
use Piwik\Metrics;
use Piwik\Piwik;
use Piwik\Plugins\Actions\Actions\ActionSiteSearch;
use Piwik\Plugins\CustomVariables\Archiver;
use Piwik\Plugins\CustomVariables\Model;
use Piwik\Plugins\GoogleAnalyticsImporter\GoogleAnalyticsQueryService;
use Piwik\Plugins\GoogleAnalyticsImporter\ImportConfiguration;
use Piwik\Site;
use Psr\Log\LoggerInterface;

class RecordImporter extends \Piwik\Plugins\GoogleAnalyticsImporter\RecordImporter
{
    const PLUGIN_NAME = 'CustomVariables';

    protected $maximumRowsInDataTableLevelZero;
    protected $maximumRowsInSubDataTable;

    /**
     * @var ImportConfiguration
     */
    private $importConfiguration;

    /**
     * @var array
     */
    private $metadataFlat;

    public function __construct(GoogleAnalyticsQueryService $gaQuery, $idSite, LoggerInterface $logger)
    {
        parent::__construct($gaQuery, $idSite, $logger);

        if (Site::isEcommerceEnabledFor($this->getIdSite())) {
            $this->maximumRowsInDataTableLevelZero = Archiver::MAX_ROWS_WHEN_ECOMMERCE;
            $this->maximumRowsInSubDataTable = Archiver::MAX_ROWS_WHEN_ECOMMERCE;
        } else {
            $this->maximumRowsInDataTableLevelZero = Config::getInstance()->General['datatable_archiving_maximum_rows_custom_variables'];
            $this->maximumRowsInSubDataTable = Config::getInstance()->General['datatable_archiving_maximum_rows_subtable_custom_variables'];
        }

        $this->importConfiguration = StaticContainer::get(ImportConfiguration::class);
    }

    public function queryGoogleAnalyticsApi(Date $day)
    {
        $this->metadataFlat = [];

        $record = new DataTable();

        for ($i = 1; $i < $this->importConfiguration->getNumCustomVariables() + 1; ++$i) {
            $this->queryCustomVariableSlot($i, $day, $record);
        }

        $this->querySiteSearchCategories($day, $record);
        $this->queryEcommerce($day, $record);

        $blob = $record->getSerialized($this->maximumRowsInDataTableLevelZero, $this->maximumRowsInSubDataTable, Metrics::INDEX_NB_VISITS);
        $this->insertBlobRecord(Archiver::CUSTOM_VARIABLE_RECORD_NAME, $blob);
        Common::destroy($record);

        unset($this->metadataFlat);
    }

    private function queryCustomVariableSlot($index, Date $day, DataTable $record)
    {
        $keyField = 'ga:customVarName' . $index;
        $valueField = 'ga:customVarValue' . $index;

        $gaQuery = $this->getGaQuery();
        $table = $gaQuery->query($day, $dimensions = [$keyField, $valueField], $this->getVisitMetrics());
        $this->processCustomVarQuery($record, $table, Model::SCOPE_VISIT, $keyField, $valueField);
        Common::destroy($table);

        $table = $gaQuery->query($day, $dimensions = [$keyField, $valueField], $this->getActionMetrics());
        $this->processCustomVarQuery($record, $table, Model::SCOPE_PAGE, $keyField, $valueField);
        Common::destroy($table);

        $table = $gaQuery->query($day, $dimensions = [$keyField, $valueField], [Metrics::INDEX_GOALS]);
        $this->processCustomVarQuery($record, $table, Model::SCOPE_CONVERSION, $keyField, $valueField);
        Common::destroy($table);
    }

    private function processCustomVarQuery(DataTable $record, DataTable $table, $scope, $keyField, $valueField)
    {
        foreach ($table->getRows() as $row) {
            $key = $row->getMetadata($keyField);
            $value = $this->cleanValue($row->getMetadata($valueField));

            $this->addMetadata($keyField, $key, $scope, $row);

            $topLevelRow = $this->addRowToTable($record, $row, $key);
            $this->addRowToSubtable($topLevelRow, $row, $value);
        }
    }

    private function querySiteSearchCategories(Date $day, DataTable $record)
    {
        $gaQuery = $this->getGaQuery();
        $table = $gaQuery->query($day, $dimensions = ['ga:searchCategory'], $this->getActionMetrics());

        foreach ($table->getRows() as $row) {
            $searchCategory = $row->getMetadata('ga:searchCategory');
            $topLevelRow = $this->addRowToTable($record, $row, ActionSiteSearch::CVAR_KEY_SEARCH_CATEGORY);
            $this->addRowToSubtable($topLevelRow, $row, $searchCategory);
        }

        Common::destroy($table);
    }

    private function queryEcommerce(Date $day, DataTable $record)
    {
        if (!Site::isEcommerceEnabledFor($this->getIdSite())) {
            // TODO: log here
            return;
        }

        $mappings = [
            'ga:productSku' => '_pks',
            'ga:productName' => '_pkn',
            'ga:productCategory' => '_pkc',
        ];

        $gaQuery = $this->getGaQuery();
        foreach ($mappings as $gaDimension => $cvarName) {
            $table = $gaQuery->query($day, $dimensions = [$gaDimension], $this->getEcommerceMetrics());

            foreach ($table->getRows() as $row) {
                $cvarValue = $row->getMetadata($gaDimension);
                $topLevelRow = $this->addRowToTable($record, $row, $cvarName);
                $this->addRowToSubtable($topLevelRow, $row, $cvarValue);
            }

            Common::destroy($table);
        }
    }

    private function cleanValue($value)
    {
        if (strlen($value)) {
            return $value;
        }
        return Archiver::LABEL_CUSTOM_VALUE_NOT_DEFINED;
    }

    private function addMetadata($keyField, $label, $scope, DataTable\Row $row)
    {
        $index = (int) str_replace('custom_var_k', '', $keyField);

        $uniqueId = $label . 'scope' . $scope . 'index' . $index;

        if (isset($this->metadataFlat[$uniqueId])) {
            return;
        }

        $this->metadataFlat[$uniqueId] = true;

        $slots = $row->getMetadata('slots') ?: [];
        $slots[] = ['scope' => $scope, 'index' => $index];
        $row->setMetadata('slots', $slots);
    }
}