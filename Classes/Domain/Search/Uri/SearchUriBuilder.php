<?php
namespace ApacheSolrForTypo3\Solr\Domain\Search\Uri;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\Grouping\GroupItem;
use ApacheSolrForTypo3\Solr\Domain\Search\SearchRequest;
use ApacheSolrForTypo3\Solr\Event\Routing\BeforeProcessCachedVariablesEvent;
use ApacheSolrForTypo3\Solr\Event\Routing\BeforeReplaceVariableInCachedUrlEvent;
use ApacheSolrForTypo3\Solr\Event\Routing\PostProcessUriEvent;
use ApacheSolrForTypo3\Solr\Routing\RoutingService;
use ApacheSolrForTypo3\Solr\System\Url\UrlHelper;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Http\Uri;
use ApacheSolrForTypo3\Solr\Utility\ParameterSortingUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;

/**
 * SearchUriBuilder
 *
 * Responsibility:
 *
 * The SearchUriBuilder is responsible to build uris, that are used in the
 * searchContext. It can use the previous request with it's persistent
 * arguments to build the url for a search sub request.
 *
 * @author Frans Saris <frans@beech.it>
 * @author Timo Hund <timo.hund@dkd.de>
 */
class SearchUriBuilder
{

    /**
     * @var UriBuilder
     */
    protected $uriBuilder;

    /**
     * @var array
     */
    protected static $preCompiledLinks = [];

    /**
     * @var integer
     */
    protected static $hitCount;

    /**
     * @var integer
     */
    protected static $missCount;

    /**
     * @var array
     */
    protected static $additionalArgumentsCache = [];

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * @var RoutingService
     */
    protected $routingService;

    /**
     * @param UriBuilder $uriBuilder
     */
    public function injectUriBuilder(UriBuilder $uriBuilder)
    {
        $this->uriBuilder = $uriBuilder;
    }

    /**
     * @param RoutingService $routingService
     */
    public function injectRoutingService(RoutingService $routingService)
    {
        $this->routingService = $routingService;
    }

    /**
     * @param EventDispatcherInterface $eventDispatcher
     */
    public function injectEventDispatcher(EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @param SearchRequest $previousSearchRequest
     * @param $facetName
     * @param $facetValue
     * @return string
     */
    public function getAddFacetValueUri(SearchRequest $previousSearchRequest, $facetName, $facetValue): string
    {
        $persistentAndFacetArguments = $previousSearchRequest
            ->getCopyForSubRequest()->removeAllGroupItemPages()->addFacetValue($facetName, $facetValue)
            ->getAsArray();

        $additionalArguments = $this->getAdditionalArgumentsFromRequestConfiguration($previousSearchRequest);
        $additionalArguments = is_array($additionalArguments) ? $additionalArguments : [];

        $arguments = $persistentAndFacetArguments + $additionalArguments;

        $this->sortFilterParametersIfNecessary($previousSearchRequest, $arguments);

        $pageUid = $this->getTargetPageUidFromRequestConfiguration($previousSearchRequest);
        return $this->buildLinkWithInMemoryCache($pageUid, $arguments);
    }

    /**
     * Removes all other facet values for this name and only set's the passed value for the facet.
     *
     * @param SearchRequest $previousSearchRequest
     * @param $facetName
     * @param $facetValue
     * @return string
     */
    public function getSetFacetValueUri(SearchRequest $previousSearchRequest, $facetName, $facetValue): string
    {
        $previousSearchRequest = $previousSearchRequest
            ->getCopyForSubRequest()->removeAllGroupItemPages()->removeAllFacetValuesByName($facetName);

        return $this->getAddFacetValueUri($previousSearchRequest, $facetName, $facetValue);
    }

    /**
     * @param SearchRequest $previousSearchRequest
     * @param $facetName
     * @param $facetValue
     * @return string
     */
    public function getRemoveFacetValueUri(SearchRequest $previousSearchRequest, $facetName, $facetValue): string
    {
        $persistentAndFacetArguments = $previousSearchRequest
            ->getCopyForSubRequest()->removeAllGroupItemPages()->removeFacetValue($facetName, $facetValue)
            ->getAsArray();

        $additionalArguments = [];
        if ($previousSearchRequest->getContextTypoScriptConfiguration()->getSearchFacetingFacetLinkUrlParametersUseForFacetResetLinkUrl()) {
            $additionalArguments = $this->getAdditionalArgumentsFromRequestConfiguration($previousSearchRequest);
        }
        $arguments = $persistentAndFacetArguments + $additionalArguments;

        $this->sortFilterParametersIfNecessary($previousSearchRequest, $arguments);

        $pageUid = $this->getTargetPageUidFromRequestConfiguration($previousSearchRequest);
        return $this->buildLinkWithInMemoryCache($pageUid, $arguments);
    }

    /**
     * @param SearchRequest $previousSearchRequest
     * @param $facetName
     * @return string
     */
    public function getRemoveFacetUri(SearchRequest $previousSearchRequest, $facetName): string
    {
        $persistentAndFacetArguments = $previousSearchRequest
            ->getCopyForSubRequest()->removeAllGroupItemPages()->removeAllFacetValuesByName($facetName)
            ->getAsArray();

        $additionalArguments = [];
        if ($previousSearchRequest->getContextTypoScriptConfiguration()->getSearchFacetingFacetLinkUrlParametersUseForFacetResetLinkUrl()) {
            $additionalArguments = $this->getAdditionalArgumentsFromRequestConfiguration($previousSearchRequest);
        }

        $arguments = $persistentAndFacetArguments + $additionalArguments;

        $this->sortFilterParametersIfNecessary($previousSearchRequest, $arguments);

        $pageUid = $this->getTargetPageUidFromRequestConfiguration($previousSearchRequest);
        return $this->buildLinkWithInMemoryCache($pageUid, $arguments);
    }

    /**
     * @param SearchRequest $previousSearchRequest
     * @return string
     */
    public function getRemoveAllFacetsUri(SearchRequest $previousSearchRequest): string
    {
        $persistentAndFacetArguments = $previousSearchRequest
            ->getCopyForSubRequest()->removeAllGroupItemPages()->removeAllFacets()
            ->getAsArray();

        $additionalArguments = [];
        if ($previousSearchRequest->getContextTypoScriptConfiguration()->getSearchFacetingFacetLinkUrlParametersUseForFacetResetLinkUrl()) {
            $additionalArguments = $this->getAdditionalArgumentsFromRequestConfiguration($previousSearchRequest);
        }

        $arguments = $persistentAndFacetArguments + $additionalArguments;

        $this->sortFilterParametersIfNecessary($previousSearchRequest, $arguments);

        $pageUid = $this->getTargetPageUidFromRequestConfiguration($previousSearchRequest);
        return $this->buildLinkWithInMemoryCache($pageUid, $arguments);
    }

    /**
     * @param SearchRequest $previousSearchRequest
     * @param $page
     * @return string
     */
    public function getResultPageUri(SearchRequest $previousSearchRequest, $page): string
    {
        $persistentAndFacetArguments = $previousSearchRequest
            ->getCopyForSubRequest()->setPage($page)
            ->getAsArray();

        $pageUid = $this->getTargetPageUidFromRequestConfiguration($previousSearchRequest);
        return $this->buildLinkWithInMemoryCache($pageUid, $persistentAndFacetArguments);
    }

    /**
     * @param SearchRequest $previousSearchRequest
     * @param GroupItem $groupItem
     * @param int $page
     * @return string
     */
    public function getResultGroupItemPageUri(SearchRequest $previousSearchRequest, GroupItem $groupItem, int $page): string
    {
        $persistentAndFacetArguments = $previousSearchRequest
            ->getCopyForSubRequest()->setGroupItemPage($groupItem->getGroup()->getGroupName(), $groupItem->getGroupValue(), $page)
            ->getAsArray();
        $pageUid = $this->getTargetPageUidFromRequestConfiguration($previousSearchRequest);
        return $this->buildLinkWithInMemoryCache($pageUid, $persistentAndFacetArguments);
    }
    /**
     * @param SearchRequest $previousSearchRequest
     * @param $queryString
     * @return string
     */
    public function getNewSearchUri(SearchRequest $previousSearchRequest, $queryString): string
    {
        /** @var $request SearchRequest */
        $contextConfiguration = $previousSearchRequest->getContextTypoScriptConfiguration();
        $contextSystemLanguage = $previousSearchRequest->getContextSystemLanguageUid();
        $contextPageUid = $previousSearchRequest->getContextPageUid();

        $request = GeneralUtility::makeInstance(
            SearchRequest::class,
            [],
            /** @scrutinizer ignore-type */ $contextPageUid,
            /** @scrutinizer ignore-type */ $contextSystemLanguage,
            /** @scrutinizer ignore-type */ $contextConfiguration
        );
        $arguments = $request->setRawQueryString($queryString)->getAsArray();

        $this->sortFilterParametersIfNecessary($previousSearchRequest, $arguments);

        $pageUid = $this->getTargetPageUidFromRequestConfiguration($previousSearchRequest);
        return $this->buildLinkWithInMemoryCache($pageUid, $arguments);
    }

    /**
     * @param SearchRequest $previousSearchRequest
     * @param $sortingName
     * @param $sortingDirection
     * @return string
     */
    public function getSetSortingUri(SearchRequest $previousSearchRequest, $sortingName, $sortingDirection): string
    {
        $persistentAndFacetArguments = $previousSearchRequest
            ->getCopyForSubRequest()->setSorting($sortingName, $sortingDirection)
            ->getAsArray();

        $pageUid = $this->getTargetPageUidFromRequestConfiguration($previousSearchRequest);
        return $this->buildLinkWithInMemoryCache($pageUid, $persistentAndFacetArguments);
    }

    /**
     * @param SearchRequest $previousSearchRequest
     * @return string
     */
    public function getRemoveSortingUri(SearchRequest $previousSearchRequest): string
    {
        $persistentAndFacetArguments = $previousSearchRequest
            ->getCopyForSubRequest()->removeSorting()
            ->getAsArray();

        $pageUid = $this->getTargetPageUidFromRequestConfiguration($previousSearchRequest);
        return $this->buildLinkWithInMemoryCache($pageUid, $persistentAndFacetArguments);
    }

    /**
     * @param SearchRequest $previousSearchRequest
     * @return string
     */
    public function getCurrentSearchUri(SearchRequest $previousSearchRequest): string
    {
        $persistentAndFacetArguments = $previousSearchRequest
            ->getCopyForSubRequest()
            ->getAsArray();

        $pageUid = $this->getTargetPageUidFromRequestConfiguration($previousSearchRequest);
        return $this->buildLinkWithInMemoryCache($pageUid, $persistentAndFacetArguments);
    }

    /**
     * @param SearchRequest $request
     * @return array
     */
    protected function getAdditionalArgumentsFromRequestConfiguration(SearchRequest $request): array
    {
        if ($request->getContextTypoScriptConfiguration() == null) {
            return [];
        }

        $reQuestId = $request->getId();
        if (isset(self::$additionalArgumentsCache[$reQuestId])) {
            return self::$additionalArgumentsCache[$reQuestId];
        }

        self::$additionalArgumentsCache[$reQuestId] = $request->getContextTypoScriptConfiguration()
            ->getSearchFacetingFacetLinkUrlParametersAsArray();

        return self::$additionalArgumentsCache[$reQuestId];
    }

    /**
     * @param SearchRequest $request
     * @return int|null
     */
    protected function getTargetPageUidFromRequestConfiguration(SearchRequest $request): ?int
    {
        if ($request->getContextTypoScriptConfiguration() == null) {
            return null;
        }

        return $request->getContextTypoScriptConfiguration()->getSearchTargetPage();
    }

    /**
     * Build the link with an i memory cache that reduces the amount of required typolink calls.
     *
     * @param int|null $pageUid
     * @param array $arguments
     * @return string
     */
    protected function buildLinkWithInMemoryCache(?int $pageUid, array $arguments): string
    {
        $values = [];
        $structure = $arguments;
        $this->getSubstitution($structure, $values);
        $hash = md5($pageUid . json_encode($structure));
        if (isset(self::$preCompiledLinks[$hash])) {
            self::$hitCount++;
            $uriCacheTemplate = self::$preCompiledLinks[$hash];
        } else {
            self::$missCount++;
            $this->uriBuilder->reset()->setTargetPageUid($pageUid);
            $uriCacheTemplate = $this->uriBuilder->setArguments($structure)->setUseCacheHash(false)->build();

            /* @var UrlHelper $urlHelper */
            $urlHelper = GeneralUtility::makeInstance(UrlHelper::class, $uriCacheTemplate);
            self::$preCompiledLinks[$hash] = (string)$urlHelper;
        }

        $keys = array_map(function($value) {
            return urlencode($value);
        }, array_keys($values));
        $values = array_map(function($value) {
            return urlencode($value);
        }, $values);

        $routingConfigurations = $this->routingService
            ->fetchEnhancerByPageUid($pageUid);
        $enhancedRouting = count($routingConfigurations) > 0;
        $this->routingService->reset();
        if ($enhancedRouting && is_array($routingConfigurations[0])) {
            $this->routingService->fromRoutingConfiguration($routingConfigurations[0]);
        }

        /* @var Uri $uri */
        $uri = GeneralUtility::makeInstance(
            Uri::class,
            $uriCacheTemplate
        );

        $urlEvent = new BeforeReplaceVariableInCachedUrlEvent($uri, $enhancedRouting);
        /* @var BeforeReplaceVariableInCachedUrlEvent $urlEvent */
        $urlEvent = $this->eventDispatcher->dispatch($urlEvent);
        $uriCacheTemplate = (string)$urlEvent->getUri();

        $variableEvent = new BeforeProcessCachedVariablesEvent(
            $uri,
            $routingConfigurations,
            $keys,
            $values
        );
        $this->eventDispatcher->dispatch($variableEvent);

        $values = $variableEvent->getVariableValues();
        // Take care that everything is urlencoded!
        $keys = array_map(function($value) {
            // @TODO: With only PHP 8 support, replace this with str_contains()
            if (strpos($value, '###') === false) {
                return $value;
            }
            return urlencode($value);
        }, array_keys($values));

        $uri = str_replace($keys, $values, $uriCacheTemplate);
        $uri = GeneralUtility::makeInstance(
            Uri::class,
            $uri
        );
        $uriEvent = new PostProcessUriEvent($uri, $routingConfigurations);
        $this->eventDispatcher->dispatch($uriEvent);
        $uri = $uriEvent->getUri();
        return (string)$uri;
    }

    /**
     * Flushes the internal in memory cache.
     *
     * @return void
     */
    public function flushInMemoryCache()
    {
        self::$preCompiledLinks = [];
    }

    /**
     * This method is used to build two arrays from a nested array. The first one represents the structure.
     * In this structure the values are replaced with the pass to the value. At the same time the values get collected
     * in the $values array, with the path as key. This can be used to build a comparable hash from the arguments
     * in order to reduce the amount of typolink calls
     *
     *
     * Example input
     *
     * $data = [
     *  'foo' => [
     *      'bar' => 111
     *   ]
     * ]
     *
     * will return:
     *
     * $structure = [
     *  'foo' => [
     *      'bar' => '###foo:bar###'
     *   ]
     * ]
     *
     * $values = [
     *  '###foo:bar###' => 111
     * ]
     *
     * @param array $structure
     * @param array $values
     * @param array $branch
     */
    protected function getSubstitution(array &$structure, array  &$values, array $branch = []): void
    {
        /*
         * Adds information about the filter facet to the placeholder.
         *
         * This feature allows to handle even placeholder in RouteEnhancer
         */
        $filter = false;
        if (count($branch) > 0 && $branch[count($branch) - 1] === 'filter') {
            $filter = true;
        }
        foreach ($structure as $key => &$value) {
            $branch[] = $key;
            if (is_array($value)) {
                $this->getSubstitution($value, $values, $branch);
            } else {
                if ($filter) {
                    [$facetType, $facetValue] = explode(':', $value);
                    $branch[] = $facetType;
                }
                $path = '###' . implode(':', $branch) . '###';
                $values[$path] = $value;
                $structure[$key] = $path;
                if ($filter) {
                    array_pop($branch);
                }
            }
            array_pop($branch);
        }
    }

    /**
     * Sorts filter arguments if enabled.
     *
     *
     * @param SearchRequest $searchRequest
     * @param array $arguments
     */
    protected function sortFilterParametersIfNecessary(SearchRequest $searchRequest, array &$arguments)
    {
        if (!$searchRequest->isActiveFacetsSorted()) {
            return;
        }

        $pluginNameSpace = $searchRequest->getContextTypoScriptConfiguration()->getSearchPluginNamespace();
        if (!empty($arguments[$pluginNameSpace]['filter']) && is_array($arguments[$pluginNameSpace]['filter'])) {
            $arguments[$pluginNameSpace]['filter'] = ParameterSortingUtility::sortByType(
                $arguments[$pluginNameSpace]['filter'],
                $searchRequest->getActiveFacetsUrlParameterStyle()
            );
        }
    }
}
