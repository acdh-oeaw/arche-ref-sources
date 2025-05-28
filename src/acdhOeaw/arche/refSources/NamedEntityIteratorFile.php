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

use BadMethodCallException;
use quickRdf\Dataset;
use quickRdf\DatasetNode;
use quickRdf\DataFactory as DF;
use termTemplates\QuadTemplate as QT;
use termTemplates\PredicateTemplate as PT;
use termTemplates\ValueTemplate as VT;
use quickRdfIo\Util as RdfIoUtil;
use zozlak\RdfConstants as RDF;
use acdhOeaw\arche\lib\Schema;

/**
 * Description of NamedEntityIteratorFile
 *
 * @author zozlak
 */
class NamedEntityIteratorFile implements NamedEntityIteratorInterface {

    private Dataset $graph;
    private Schema $schema;

    /**
     * 
     * @var array<array{string, \termTemplates\QuadTemplate}>
     */
    private array $filters = [];
    private ?int $limit    = null;

    /**
     * 
     * @var array<\rdfInterface\TermInterface>
     */
    private array $matching;

    /**
     * 
     * @param string|resource $rdfFilePath
     * @param Schema $schema
     */
    public function __construct(mixed $rdfFilePath, Schema $schema,
                                string | null $format = null) {
        $this->graph  = new Dataset();
        $this->graph->add(RdfIoUtil::parse($rdfFilePath, new DF(), $format));
        $this->schema = $schema;
    }

    /**
     * 
     * @param array<array{string, scalar}> $filters
     * @return void
     */
    public function setFilter(array $filters, int | null $limit = null): void {
        $this->filters = [];
        foreach ($filters as $filter) {
            $this->filters[] = match ($filter[0]) {
                self::FILTER_CLASS => ['any', new PT(DF::namedNode(RDF::RDF_TYPE), DF::namedNode($filter[1]))],
                self::FILTER_ID => ['any', new PT($this->schema->id, new VT("`" . $filter[1] . "`", VT::REGEX))],
                self::FILTER_MIN_MOD_DATE => ['any', new PT($this->schema->modificationDate, new VT($filter[1], VT::GREATER_EQUAL))],
                self::FILTER_NO_PROPERTY => ['none', new PT(DF::namedNode($filter[1]))],
                default => throw new \BadMethodCallException("Unsupported filter type " . $filter[0]),
            };
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
            $meta = DatasetNode::factory($i)->withDataset($meta);
            yield new NamedEntityFile($meta, $this);
        }
    }

    public function count(): int {
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
        $n              = $this->limit ?? PHP_INT_MAX;
        foreach ($this->graph->listSubjects() as $sbj) {
            $tmp     = $this->graph->copy(new QT($sbj));
            $matches = true;
            foreach ($this->filters as $filter) {
                $func = $filter[0];
                $matches &= $tmp->$func($filter[1]);
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
