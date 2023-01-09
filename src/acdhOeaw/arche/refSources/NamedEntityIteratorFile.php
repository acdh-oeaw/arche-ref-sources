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

use EasyRdf\Resource;
use quickRdf\Dataset;
use quickRdf\DataFactory;
use termTemplates\QuadTemplate as QT;
use termTemplates\ValueTemplate as VT;
use rdfHelpers\DatasetNode;
use quickRdfIo\Util as ioUtil;
use zozlak\RdfConstants as RDF;
use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\RepoResource;

/**
 * Description of NamedEntityIteratorFile
 *
 * @author zozlak
 */
class NamedEntityIteratorFile implements NamedEntityIteratorInterface {

    private Dataset $graph;
    private Schema $schema;
    private Repo $repo;
    private array $filters = [];
    private ?int $limit   = null;
    private array $matching;

    public function __construct(string $rdfFilePath, Repo $repo) {
        $this->graph  = new Dataset();
        $this->graph->add(ioUtil::parse($rdfFilePath, new DataFactory()));
        $this->repo   = $repo;
        $this->schema = $this->repo->getSchema();
    }

    public function setFilter(?string $class = null, ?string $idMatch = null,
                              ?string $minModDate = null, ?int $limit = null): void {
        $this->filters = [];
        if (!empty($class)) {
            $this->filters[] = new QT(null, DataFactory::namedNode(RDF::RDF_TYPE), DataFactory::namedNode($class));
        }
        if (!empty($idMatch)) {
            $this->filters[] = new QT(null, DataFactory::namedNode($this->schema->id), new VT("`$idMatch`", VT::REGEX));
        }
        if (!empty($minModDate)) {
            $this->filters[] = new QT(null, DataFactory::namedNode($this->schema->modificationDate), new VT($minModDate, VT::GREATER_EQUAL));
        }
        $this->limit = $limit;
        unset($this->matching);
    }

    public function getNamedEntities(): \Generator {
        if (!isset($this->matching)) {
            $this->findMatching();
        }
        foreach ($this->matching as $i) {
            $meta = $this->graph->copy(new QT($i));
            $meta = new DatasetNode($meta, $i);
            yield new NamedEntityFile($meta, $this, $this->repo);
        }
    }

    public function getCount(): int {
        if (!isset($this->matching)) {
            $this->findMatching();
        }
        return count($this->matching);
    }

    public function getIdProp(): string {
        return $this->schema->id;
    }

    private function findMatching(): void {
        $this->matching = [];
        $n              = $this->limit;
        foreach ($this->graph->listSubjects() as $sbj) {
            $tmp     = $this->graph->copy(new QT($sbj));
            $matches = true;
            foreach ($this->filters as $f) {
                $matches &= $tmp->any($f);
            }
            if ($matches) {
                $this->matching[] = $sbj;
                $n--;
            }
            if ($n === 0) {
                break;
            }
        }
    }
}
