<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\GoogleAnalyticsImporter;


use Piwik\Container\StaticContainer;
use Piwik\Plugins\SitesManager\API;
use Piwik\Site;
use Psr\Log\LoggerInterface;

class GoogleGoalMapper
{
    const FUNNELS_URL = 'https://plugins.matomo.org/Funnels';

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param \Google_Service_Analytics_Goal $gaGoal
     * @throws CannotImportGoalException
     */
    public function map(\Google_Service_Analytics_Goal $gaGoal, $idSite)
    {
        $urls = API::getInstance()->getSiteUrlsFromId($idSite);

        $result = $this->mapBasicGoalProperties($gaGoal);

        if ($gaGoal->getEventDetails()) {
            $this->mapEventGoal($result, $gaGoal);
        } else if ($gaGoal->getUrlDestinationDetails()) {
            $this->mapUrlDestinationGoal($result, $gaGoal, $urls);
        } else if ($gaGoal->getVisitTimeOnSiteDetails()) {
            $this->mapVisitDurationGoal($result, $gaGoal);
        } else {
            throw new CannotImportGoalException($gaGoal, 'unsupported goal type');
        }

        return $result;
    }

    private function mapEventGoal(array &$result, \Google_Service_Analytics_Goal $gaGoal)
    {
        $eventDetails = $gaGoal->getEventDetails();
        if (count($eventDetails->getEventConditions()) > 1) {
            throw new CannotImportGoalException($gaGoal, 'uses multiple event conditions');
        }

        $conditions = $eventDetails->getEventConditions();

        /** @var \Google_Service_Analytics_GoalEventDetailsEventConditions $condition */
        $condition = reset($conditions);

        switch (strtolower($condition->getType())) {
            case 'category':
                $result['match_attribute'] = 'event_category';
                break;
            case 'action':
                $result['match_attribute'] = 'event_action';
                break;
            case 'label':
                $result['match_attribute'] = 'event_name';
                break;
            case 'value':
                throw new CannotImportGoalException($gaGoal, 'goals based on event value are not supported in matomo');
        }

        list($patternType, $pattern) = $this->mapMatchType($gaGoal, $condition->getMatchType(), $condition->getExpression());
        $result['pattern'] = $pattern;

        // force 'contains', since GA does not include hostname in URL match
        if ($patternType == 'exact') {
            $patternType = 'contains';
        }

        $result['pattern_type'] = $patternType;

        if ($eventDetails->useEventValue) {
            $result['use_event_value_as_revenue'] = true;
        }
    }

    private function mapUrlDestinationGoal(array &$result, \Google_Service_Analytics_Goal $gaGoal, $siteUrls)
    {
        $urlMatchDetails = $gaGoal->getUrlDestinationDetails();

        $result['match_attribute'] = 'url';

        list($patternType, $pattern) = $this->mapMatchType($gaGoal, $urlMatchDetails->getMatchType(), $urlMatchDetails->getUrl(), $siteUrls);
        $result['pattern_type'] = $patternType;
        $result['pattern'] = $pattern;

        $result['case_sensitive'] = (bool)$urlMatchDetails->getCaseSensitive();

        if (empty($urlMatchDetails->getSteps())) {
            return;
        }

        if (!\Piwik\Plugin\Manager::getInstance()->isPluginActivated('Funnels')) {
            throw new CannotImportGoalException($gaGoal, 'multiple steps in a URL destination goal found, this is only supported in Matomo through the <a href="' .
                self::FUNNELS_URL . '">Funnels</a> plugin');
        }

        $result['funnel'] = $this->mapFunnelSteps($gaGoal, $urlMatchDetails);
    }

    private function mapVisitDurationGoal(array &$result, \Google_Service_Analytics_Goal $gaGoal)
    {
        $visitDurationGoalDetails = $gaGoal->getVisitTimeOnSiteDetails();

        $result['match_attribute'] = 'visit_duration';
        $result['pattern_type'] = $this->mapComparisonType($gaGoal, $visitDurationGoalDetails->getComparisonType());
        $result['pattern'] = $visitDurationGoalDetails->getComparisonValue();
    }

    public function mapManualGoal(\Google_Service_Analytics_Goal $gaGoal)
    {
        $result = $this->mapBasicGoalProperties($gaGoal);
        $result['match_attribute'] = 'manually';
        $result['pattern'] = 'manually';
        $result['pattern_type'] = 'contains';
        return $result;
    }

    private function mapBasicGoalProperties(\Google_Service_Analytics_Goal $gaGoal)
    {
        $result = [
            'name' => $gaGoal->getName(),
            'description' => '(imported from Google Analytics, original id = ' . $gaGoal->getId() . ')',
            'match_attribute' => false,
            'pattern' => false,
            'pattern_type' => false,
            'case_sensitive' => false,
            'revenue' => false,
            'allow_multiple_conversions' => false,
            'use_event_value_as_revenue' => false,
        ];

        $value = $gaGoal->getValue();
        if (!empty($value)) {
            $result['revenue'] = $value;
        }

        return $result;
    }

    private function mapMatchType($gaGoal, $matchType, $patternValue, $siteUrls = [])
    {
        switch (strtolower($matchType)) {
            case 'regexp':
                return ['regex', $patternValue];
            case 'head':
            case 'begins_with':
                return ['regex', '^' . preg_quote($patternValue)];
            case 'exact':
                if (!$this->urlHasSiteUrlPrefix($patternValue, $siteUrls)) {
                    $baseUrl = $siteUrls[0];
                    if (substr($baseUrl, -1, 1) != '/' && substr($patternValue, 0, 1) != '/') {
                        $baseUrl .= '/';
                    }
                    $patternValue = $baseUrl . $patternValue;
                }
                return ['exact', $patternValue];
            default:
                throw new CannotImportGoalException($gaGoal, "unknown goal match type, '$matchType'");
        }
    }

    private function mapComparisonType($gaGoal, $comparisonType)
    {
        if (strtolower($comparisonType) == 'greater_than') {
            return 'greater_than';
        }

        throw new CannotImportGoalException($gaGoal, 'Unsupported comparison type \'' . $comparisonType . '\'');
    }

    private function mapFunnelSteps(\Google_Service_Analytics_Goal $gaGoal, \Google_Service_Analytics_GoalUrlDestinationDetails $urlMatchDetails)
    {
        $steps = [];

        /** @var \Google_Service_Analytics_GoalUrlDestinationDetailsSteps $step */
        foreach ($urlMatchDetails->getSteps() as $step) {
            $steps[] = [
                'name' => $step->getName(),
                'pattern' => $step->getUrl(),
                'pattern_type' => 'path_equals',
                'required' => false,
            ];
        }

        if ($urlMatchDetails->getFirstStepRequired()) {
            $steps[0]['required'] = true;
        }

        return $steps;
    }

    public function getGoalIdFromDescription($goal)
    {
        if (!preg_match('/id = ([^)]+)\)/', $goal['description'], $matches)) {
            return null;
        }

        $matches[1] = trim($matches[1]);
        if (empty($matches[1])) {
            return null;
        }

        $this->logger->debug('Found goal "{goalName}" to be mapped to GA goal with ID = {gaGoalId}.', [
            'goalName' => $goal['name'],
            'gaGoalId' => $matches[1],
        ]);

        return $matches[1];
    }

    private function urlHasSiteUrlPrefix($patternValue, $siteUrls)
    {
        foreach ($siteUrls as $siteUrl) {
            if (strpos($patternValue, $siteUrl) === 0) {
                return true;
            }
        }
        return false;
    }
}
