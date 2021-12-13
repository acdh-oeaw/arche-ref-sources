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

use GuzzleHttp\Client;
use zozlak\RdfConstants as RDF;
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

    private Client $client;
    private Repo $repo;
    private Schema $schema;
    private array $searchTerms;
    private SearchConfig $searchConfig;

    public function __construct(string $repoUrl, ?string $user = null,
                                ?string $password = null) {
        $this->client = new Client();
        $opts         = ['auth' => [$user, $password]];
        $this->repo   = Repo::factoryFromUrl($repoUrl, $opts);
        $this->schema = $this->repo->getSchema();
    }

    public function setFilter(string $class, string $idMatch,
                              ?string $minModDate = null, ?int $limit = null): void {
        $this->searchTerms = [
            new SearchTerm(RDF::RDF_TYPE, $class),
            new SearchTerm($this->schema->id, $idMatch, '~'),
        ];
        if (!empty($minModDate)) {
            $this->searchTerms[] = new SearchTerm($this->schema->modificationDate, $minModDate, '>=', SearchTerm::TYPE_DATETIME);
        }

        $this->searchConfig               = new SearchConfig();
        $this->searchConfig->limit        = $limit;
        $this->searchConfig->metadataMode = RepoResourceInterface::META_RESOURCE;
    }

    public function getNamedEntities(): \Generator {
        foreach ($this->repo->getResourcesBySearchTerms($this->searchTerms, $this->searchConfig) as $res) {
            yield new NamedEntityRepo($res);
        }
    }

    public function getCount(): int {
        return $this->searchConfig->count;
    }
}
