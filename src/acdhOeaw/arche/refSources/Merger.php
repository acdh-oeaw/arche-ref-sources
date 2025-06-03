<?php

/*
 * The MIT License
 *
 * Copyright 2024 zozlak.
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

use Psr\Log\LoggerInterface;
use GuzzleHttp\Exception\ClientException;
use rdfInterface\DatasetInterface;
use rdfInterface\QuadInterface;
use quickRdf\RdfNamespace;
use quickRdf\DatasetNode;
use quickRdfIo\Util as RdfIoUtil;
use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\lib\SearchTerm;
use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\RepoResource;
use acdhOeaw\arche\lib\exception\RepoLibException;
use acdhOeaw\arche\lib\exception\Conflict;
use termTemplates\QuadTemplate as QT;
use termTemplates\PredicateTemplate as PT;

;

/**
 * Description of Merger
 *
 * @author zozlak
 */
class Merger {

    private Repo $repo;
    private Schema $schema;
    private LoggerInterface | null $log;

    public function __construct(Repo | Schema | null $repoOrSchema,
                                LoggerInterface | null $log = null) {
        if ($repoOrSchema instanceof Repo) {
            $this->repo   = $repoOrSchema;
            $this->schema = $repoOrSchema->getSchema();
        } else {
            $this->schema = $repoOrSchema;
        }
        $this->log = $log;
    }

    /**
     * Merges subjects sharing same identifers using only the local data
     */
    public function merge(DatasetInterface $data): void {
        $this->log?->info("### Merging entities locally");
        foreach ($data->listObjects(new PT($this->schema->id)) as $id) {
            $tmpl = new PT($this->schema->id, $id);
            $sbjs = iterator_to_array($data->listSubjects($tmpl));
            if (count($sbjs) > 1) {
                // sorting is only for stable results but completely arbitrary
                // we have no way to check from which source the data of a subject with a given id come
                // so we can no tell how to order subjects according to their importance
                usort($sbjs, fn($a, $b) => $a->getValue() <=> $b->getValue());
                $mainSbj     = array_shift($sbjs);
                $mainSbjMeta = $data->copy(new QT($mainSbj));
                foreach ($sbjs as $sbj) {
                    $this->log?->info("Merging $sbj into $mainSbj");
                    $this->mergeInto($mainSbjMeta, $data->delete(new QT($sbj)));
                }
                $data->delete(new QT($mainSbj));
                $data->add($mainSbjMeta);
            }
        }
    }

    /**
     * Performs an update against the repository
     * @param resource|null $output
     */
    public function update(DatasetInterface $data, bool $test = true,
                           $output = null): void {
        if (!isset($this->repo)) {
            throw new \RuntimeException("No repository defined");
        }

        $nmsp = new RdfNamespace();
        foreach ($this->schema->namespaces ?? [] as $alias => $prefix) {
            $nmsp->add($prefix, $alias);
        }

        $this->log?->info("### Updating entities");
        $sc               = new SearchConfig();
        $sc->metadataMode = RepoResource::META_IDS;
        $st               = new SearchTerm($this->schema->id, [], '=');
        $idTmpl           = new PT($this->schema->id);

        foreach ($data->listSubjects() as $sbj) {
            $sbjMeta = new DatasetNode($sbj, $data->getIterator(new QT($sbj)));
            if ($sbjMeta->none($idTmpl)) {
                $this->log?->info("Skipping $sbj - no identifiers");
                continue;
            }
            $st->value     = $sbjMeta->listObjects($idTmpl)->getValues();
            $repoResources = $this->repo->getResourcesBySearchTerms([$st], $sc);
            $repoResources = iterator_to_array($repoResources);

            try {
                $this->repo->begin();
                if (count($repoResources) === 0) {
                    $this->log?->info("Creating resource $sbj");
                    $mainRes = $this->repo->createResource($sbjMeta);
                } elseif (count($repoResources) === 1) {
                    /** @var RepoResource $mainRes */
                    $mainRes = reset($repoResources);
                    $this->log?->info("Updating metadata of " . $mainRes->getUri() . " with $sbj metadata");
                    $mainRes->setMetadata($sbjMeta);
                    $mainRes->updateMetadata(readMode: RepoResource::META_RESOURCE);
                } else {
                    // just for stable results
                    usort($repoResources, fn($a, $b) => $a->getUri() <=> $b->getUri());
                    $mainRes = array_shift($repoResources);
                    $this->log?->info("Updating metadata of " . $mainRes->getUri() . " with $sbj metadata");
                    // skip identifiers not to cause conflicts
                    $mainRes->setMetadata($sbjMeta->copyExcept(new PT($this->schema->id)));
                    $mainRes->updateMetadata();
                    foreach ($repoResources as $resToMerge) {
                        $this->log?->info("    Merging " . $resToMerge->getUri() . " into " . $mainRes->getUri());
                        /** @var RepoResource $resToMerge */
                        $resToMerge->merge($mainRes->getUri(), readMode: RepoResource::META_RESOURCE);
                    }
                    $mainRes->loadMetadata(true, RepoResource::META_RESOURCE);
                }

                if ($test) {
                    $this->repo->rollback();
                } else {
                    $this->repo->commit();
                }

                if ($output !== null) {
                    RdfIoUtil::serialize($mainRes->getGraph(), 'text/turtle', $output, $nmsp);
                }
            } catch (RepoLibException | ClientException $e) {
                $this->log?->error("Failed to update $sbj with: " . print_r($e, true));
            } finally {
                if ($this->repo->inTransaction()) {
                    try {
                        $this->repo->rollback();
                    } catch (Conflict $e) {
                        
                    }
                }
            }
        }
    }

    private function mergeInto(DatasetInterface $into, DatasetInterface $from): void {
        $this->log?->debug("Merging " . $from->getSubject() . " into " . $into->getSubject());
        $intoSbj = $into->getSubject();
        foreach ($from->listPredicates() as $pred) {
            $tmpl = new PT($pred);
            if ($into->none($tmpl)) {
                $into->add($from->map(fn(QuadInterface $q) => $q->withSubject($intoSbj), $tmpl));
            }
        }
        $into->add($from->map(fn(QuadInterface $q) => $q->withSubject($intoSbj), new PT($this->schema->id)));
    }
}
