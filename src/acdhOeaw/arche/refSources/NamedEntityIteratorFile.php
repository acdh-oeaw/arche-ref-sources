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
use EasyRdf\Graph;
use EasyRdf\Resource;
use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\RepoResource;

/**
 * Description of NamedEntityIteratorFile
 *
 * @author zozlak
 */
class NamedEntityIteratorFile implements NamedEntityIteratorInterface {

    private Graph $graph;
    private ?string $id;
    private Schema $schema;
    private ?Repo $repo       = null;
    private string $class;
    private string $idMatch;
    private ?string $minModDate = null;
    private ?int $limit      = null;
    private ?int $count;

    public function __construct(string $rdfFilePath, ?string $repoUrl,
                                ?Schema $schema = null, ?string $user = null,
                                ?string $password = null) {
        $this->graph = new Graph();
        $this->graph->parseFile($rdfFilePath);

        $opts = ['auth' => [$user, $password]];
        if (!empty($repoUrl)) {
            $this->repo   = Repo::factoryFromUrl($repoUrl, $opts);
            $this->schema = $this->repo->getSchema();
        } else {
            $this->schema = $schema;
        }
        if (empty($this->schema->id ?? null)) {
            throw new RuntimeException("Both ARCHE repo URL and id property are unknown. Please provide either --repoUrl or --idProp parameter.");
        }
    }

    public function setFilter(string $class, string $idMatch,
                              ?string $minModDate = null, ?int $limit = null): void {
        $this->class      = $class;
        $this->idMatch    = "`$idMatch`";
        $this->minModDate = $minModDate;
        $this->limit      = $limit;
        $this->count      = null;

        if (!empty($this->minModDate) && !isset($this->schema->modificationDate)) {
            throw new RuntimeException("Can't filter by modification date when the ARCHE repo URL is unknown. Please provide the --repoUrl parameter.");
        }
    }

    public function getNamedEntities(): \Generator {
        $n = 1;
        foreach ($this->graph->resources() as $res) {
            if ($n > $this->limit) {
                break;
            }
            if ($this->isMatching($res)) {
                $n++;
                yield new NamedEntityFile($res, $this);
            }
        }
    }

    public function getCount(): int {
        if ($this->count === null) {
            $this->count = 0;
            foreach ($this->graph->resources() as $res) {
                $this->count += (int) $this->isMatching($res);
            }
        }
        return $this->count;
    }

    public function getIdProp(): string {
        return $this->schema->id;
    }

    public function getRepoResource(Resource $meta): RepoResource {
        if ($this->repo === null) {
            throw new RuntimeException("Can't update repository resource when the ARCHE repo URL is unknown. Please provide the --repoUrl parameter.");
        }
        $ids = [];
        foreach ($meta->allResources($this->schema->id) as $id) {
            $ids[] = (string) $id;
        }
        return $this->repo->getResourceByIds($ids);
    }

    private function isMatching(Resource $res): bool {
        if (!$res->isA($this->class)) {
            return false;
        }
        foreach ($res->allResources($this->schema->id) as $id) {
            if (preg_match($this->idMatch, $id)) {
                return true;
            }
        }
        return false;
    }
}
