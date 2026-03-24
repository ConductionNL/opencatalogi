<?php
/**
 * Service for managing federated search operations.
 *
 * Performs local search via OpenRegister and federates queries to remote
 * catalog directories, merging results and facets from all sources.
 *
 * @category Service
 * @package  OCA\OpenCatalogi\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenCatalogi.nl
 */

namespace OCA\OpenCatalogi\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use OCP\IURLGenerator;

/**
 * Service for managing federated search operations.
 *
 * Performs local search via OpenRegister and federates queries to remote
 * catalog directories, merging results and facets from all sources.
 */
class SearchService
{

    /**
     * The HTTP client.
     *
     * @var Client
     */
    public $client;

    /**
     * SearchService constructor.
     *
     * @param DirectoryService $directoryService The directory service.
     * @param IURLGenerator    $urlGenerator     The URL generator.
     */
    public function __construct(
        private readonly DirectoryService $directoryService,
        private readonly IURLGenerator $urlGenerator
    ) {
        $this->client = new Client();

    }//end __construct()

    /**
     * Merge facets from existing and new aggregations.
     *
     * @param array $existingAggregation The existing aggregation array.
     * @param array $newAggregation      The new aggregation array to merge.
     *
     * @return array Merged facets
     */
    public function mergeFacets(array $existingAggregation, array $newAggregation): array
    {
        $results        = [];
        $existingAggMap = [];
        $newAggregationMapped = [];

        // Map existing aggregation.
        foreach ($existingAggregation as $value) {
            $existingAggMap[$value['_id']] = $value['count'];
        }

        // Merge new aggregation with existing.
        foreach ($newAggregation as $value) {
            $newAggregationMapped[$value['_id']] = $value['count'];
            if (isset($existingAggMap[$value['_id']]) === true) {
                $newAggregationMapped[$value['_id']] = ($existingAggMap[$value['_id']] + $value['count']);
            }
        }

        // Combine results.
        $merged = array_merge(
            array_diff($existingAggMap, $newAggregationMapped),
            array_diff($newAggregationMapped, $existingAggMap)
        );
        foreach ($merged as $key => $value) {
            $results[] = [
                '_id'   => $key,
                'count' => $value,
            ];
        }

        return $results;

    }//end mergeFacets()

    /**
     * Merge existing and new aggregations.
     *
     * @param array|null $existingAggregations The existing aggregations.
     * @param array|null $newAggregations      The new aggregations to merge.
     *
     * @return array Merged aggregations
     */
    private function mergeAggregations(?array $existingAggregations, ?array $newAggregations): array
    {
        if ($newAggregations === null) {
            return [];
        }

        $result = $existingAggregations ?? [];

        foreach ($newAggregations as $key => $aggregation) {
            if (isset($result[$key]) === false) {
                $result[$key] = $aggregation;
                continue;
            }

            $result[$key] = $this->mergeFacets($result[$key], $aggregation);
        }

        return $result;

    }//end mergeAggregations()

    /**
     * Comparison function for sorting result arrays.
     *
     * @param array $a The first array to compare.
     * @param array $b The second array to compare.
     *
     * @return integer The comparison result.
     */
    public function sortResultArray(array $a, array $b): int
    {
        return ($a['_score'] <=> $b['_score']);

    }//end sortResultArray()

    /**
     * Perform a federated search operation.
     *
     * Queries remote catalog directories and merges results with the provided
     * local results. Local search is handled by the caller via OpenRegister.
     *
     * @param array $parameters   Search parameters.
     * @param array $localResults Local results from OpenRegister (with 'results' and 'facets' keys).
     *
     * @return array Federated search results.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function search(array $parameters, array $localResults=[]): array
    {
        $results      = ($localResults['results'] ?? []);
        $aggregations = ($localResults['facets'] ?? []);
        $totalResults = ($localResults['total'] ?? count($results));
        $limit        = ($parameters['_limit'] ?? 30);
        $page         = ($parameters['_page'] ?? 1);

        $directory = $this->directoryService->getDirectory(['_limit' => 1000]);

        // Return early if directory is empty.
        if (count($directory) === 0) {
            return $this->buildResponse(facets: $aggregations, results: $results, limit: $limit, page: $page, total: $totalResults);
        }

        $searchEndpoints = [];

        // Prepare search endpoints.
        $promises = [];
        $indexUrl = $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute(routeName: "opencatalogi.directory.index")
        );
        foreach ($directory as $instance) {
            if ($instance['default'] === false
                || isset($parameters['_catalogi']) === true
                && in_array($instance['catalog'], $parameters['_catalogi']) === false
                || $instance['search'] === $indexUrl
            ) {
                continue;
            }

            $searchEndpoints[$instance['search']][] = $instance['catalog'];
        }

        unset($parameters['_catalogi']);

        // Perform asynchronous requests to search endpoints.
        foreach ($searchEndpoints as $searchEndpoint => $catalogi) {
            $parameters['_catalogi'] = $catalogi;
            $promises[] = $this->client->getAsync($searchEndpoint, ['query' => $parameters]);
        }

        $responses = Utils::settle($promises)->wait();

        // Process responses.
        foreach ($responses as $response) {
            if ($response['state'] === 'fulfilled') {
                $responseData = json_decode(
                    json: $response['value']->getBody()->getContents(),
                    associative: true
                );

                $results = array_merge(
                    $results,
                    $responseData['results']
                );

                usort($results, [$this, 'sortResultArray']);

                $aggregations = $this->mergeAggregations($aggregations, $responseData['facets']);
            }
        }

        return $this->buildResponse(facets: $aggregations, results: $results, limit: $limit, page: $page, total: $totalResults);

    }//end search()

    /**
     * Build a standardized search response array.
     *
     * @param array $facets  The aggregated facets.
     * @param array $results The search results.
     * @param mixed $limit   The page size limit.
     * @param mixed $page    The current page number.
     * @param int   $total   The total number of results.
     *
     * @return array The formatted response.
     */
    private function buildResponse(array $facets, array $results, mixed $limit, mixed $page, int $total): array
    {
        $pages = max(1, (int) ceil($total / $limit));

        return [
            'facets'  => $facets,
            'results' => $results,
            'count'   => count($results),
            'limit'   => (int) $limit,
            'page'    => (int) $page,
            'pages'   => $pages,
            'total'   => $total,
        ];

    }//end buildResponse()

    /**
     * This function adds a single query param to the given $vars array.
     *
     * Will check if request query $name has [...] inside the parameter.
     * Works recursive for nested brackets.
     * Also checks for queryParams ending on [] and adds value to an array.
     * If none of the above this function will just add [queryParam] = $value to $vars.
     *
     * @param array  $vars    The vars array we are going to store the query parameter in
     * @param string $name    The full $name of the query param
     * @param string $nameKey The key portion of the query param name
     * @param string $value   The full $value of the query param
     *
     * @return void
     */
    private function recursiveRequestQueryKey(array &$vars, string $name, string $nameKey, string $value): void
    {
        $matches      = [];
        $matchesCount = preg_match(pattern: '/(\[[^[\]]*])/', subject: $name, matches:$matches);
        if ($matchesCount > 0) {
            $key  = $matches[0];
            $name = str_replace(search: $key,  replace:'', subject: $name);
            $key  = trim(string: $key, characters: '[]');
            if (empty($key) === false) {
                $vars[$nameKey] = ($vars[$nameKey] ?? []);
                $this->recursiveRequestQueryKey(
                    vars: $vars[$nameKey],
                    name: $name,
                    nameKey: $key,
                    value: $value
                );
                return;
            }

            $vars[$nameKey][] = $value;
            return;
        }

        $vars[$nameKey] = $value;

    }//end recursiveRequestQueryKey()

    /**
     * Parses the request query string and returns it as an array of queries.
     *
     * @param string $queryString The input query string from the request.
     *
     * @return array The resulting array of query parameters.
     */
    public function parseQueryString(string $queryString=''): array
    {
        $vars = [];

        $pairs = explode(separator: '&', string: $queryString);
        foreach ($pairs as $pair) {
            $kvpair = explode(separator: '=', string: $pair);

            $key = urldecode(string: $kvpair[0]);
            if (empty($key) === true) {
                continue;
            }

            $value = '';
            if (count(value: $kvpair) === 2) {
                $value = urldecode(string: $kvpair[1]);
            }

            $this->recursiveRequestQueryKey(
                vars: $vars,
                name: $key,
                nameKey: explode(
                    separator: '[',
                    string: $key
                )[0],
                value: $value
            );
        }//end foreach

        return $vars;

    }//end parseQueryString()
}//end class
