<?php

/*
 * The MIT License
 *
 * Copyright 2021 Austrian Centre for Digital Humanities.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace acdhOeaw\arche\refSources;

use RuntimeException;
use zozlak\RdfConstants as RDF;
use zozlak\queryPart\QueryPart;
use quickRdf\DataFactory as DF;
use termTemplates\PredicateTemplate as PT;
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\RepoResourceInterface;
use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\lib\SearchTerm;
use acdhOeaw\arche\lib\SearchConfig;

/**
 * Description of NamedEntityIteratorRepo
 *
 * @author zozlak
 */
class NamedEntityIteratorRepo implements NamedEntityIteratorInterface {

    const POST_FILTERS = [self::FILTER_NO_PROPERTY];

    private Repo $repo;
    private Schema $schema;
    private QueryPart $query;
    private SearchConfig $searchConfig;

    public function __construct(Repo $repo) {
        $this->repo         = $repo;
        $this->schema       = $this->repo->getSchema();
        $this->searchConfig = new SearchConfig();
    }

    /**
     * 
     * @param array<array{string, scalar}> $filters
     * @return void
     */
    public function setFilter(array $filters, int | null $limit = null): void {
        static $nonRelProp = [RDF::RDF_TYPE];

        if (count($filters) === 0) {
            throw new RefSourcesException('At least one filter has to be defined. Iterating a whole repository is not allowed.');
        }

        /** @var QueryPart $query */
        $query = "SELECT id FROM";
        $param = [];
        foreach ($filters as $n => $filter) {
            $filter = match ($filter[0]) {
                self::FILTER_CLASS => new SearchTerm(RDF::RDF_TYPE, $filter[1]),
                self::FILTER_ID => new SearchTerm($this->schema->id, $filter[1], '~'),
                self::FILTER_MIN_MOD_DATE => new SearchTerm($this->schema->modificationDate, $filter[1], '>=', SearchTerm::TYPE_DATETIME),
                self::FILTER_NO_PROPERTY => new QueryPart("SELECT id FROM resources r WHERE NOT EXISTS (SELECT 1 FROM metadata WHERE id = r.id AND property = ?)", [
                    $filter[1]]),
                default => throw new \BadMethodCallException("Unsupported filter type " . $filter[0]),
            };
            if ($filter instanceof SearchTerm) {
                $filter = $filter->getSqlQuery($this->repo->getBaseUrl(), $this->schema->id, $nonRelProp);
            }
            $query .= $n > 0 ? ' JOIN' : '';
            $query .= ' (' . $filter->query . ') t' . $n;
            $query .= $n > 0 ? ' USING (id)' : '';
            $param = array_merge($param, $filter->param);
        }
        $this->query = new QueryPart($query, $param);

        $this->searchConfig               = new SearchConfig();
        $this->searchConfig->limit        = $limit;
        $this->searchConfig->metadataMode = RepoResourceInterface::META_RESOURCE;
    }

    /**
     * 
     * @return \Generator<NamedEntityRepo>
     */
    public function getNamedEntities(): \Generator {
        if (!isset($this->query)) {
            throw new RuntimeExceptio("Set at least one filter first. Iterating a whole repository is not allowed.");
        }
        foreach ($this->repo->getResourcesBySqlQuery($this->query->query, $this->query->param, $this->searchConfig) as $res) {
            yield new NamedEntityRepo($res);
        }
    }

    public function count(): int {
        return min($this->searchConfig->count ?? 0, $this->searchConfig->limit ?? PHP_INT_MAX);
    }
}
