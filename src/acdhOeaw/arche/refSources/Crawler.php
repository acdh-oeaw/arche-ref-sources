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

use Generator;
use Psr\Log\LoggerInterface;
use rdfInterface\DatasetInterface;
use rdfInterface\DatasetNodeInterface;
use quickRdfIo\RdfIoException;
use acdhOeaw\UriNormalizer;
use acdhOeaw\UriNormalizerCache;
use acdhOeaw\UriNormalizerException;
use acdhOeaw\arche\lib\Schema;

/**
 * Description of Crawler
 *
 * @author zozlak
 */
class Crawler {

    /** @phpstan-ignore property.onlyWritten */
    private Schema $schema;
    private PropertyMappings $mappings;
    private UriNormalizer $normalizer;
    private LoggerInterface | null $log;

    public function __construct(object $refSrcsCfg, Schema $schema,
                                LoggerInterface | null $log = null) {
        $this->schema     = $schema;
        $this->normalizer = new UriNormalizer(idProp: $schema->id, cache: new UriNormalizerCache());
        $this->mappings   = new PropertyMappings($this->normalizer, $schema->id);
        $this->mappings->parseConfig($refSrcsCfg);
        $this->log        = $log;
    }

    /**
     * 
     * @return Generator<ProcessEntityResult>
     */
    public function crawl(NamedEntityIteratorInterface $source): Generator {
        $this->log?->info("### Crawling source entities");
        $NT = null;
        $N  = 1;
        foreach ($source->getNamedEntities() as $entity) {
            $NT ??= $source->count(); // valid only after iterator initialization
            $NN = round(100 * $N / $NT);
            $this->log?->info($entity->getUri() . " ($N/$NT $NN%)");
            $N++;
            yield $this->processEntity($entity);
        }
    }

    private function processEntity(NamedEntityInterface $entity): ProcessEntityResult {
        // collect data from all external databases reachable from this entity
        $entityExtMeta = [];
        $idsToProcess  = $entity->getIdentifiers($this->normalizer);
        $idsProcessed  = [];
        while (count($idsToProcess) > 0) {
            try {
                $id                = array_pop($idsToProcess);
                $idsProcessed[$id] = 1;
                // don't even try to resolve identifiers for which there's no mapping
                $this->mappings->matchExternalDatabase($id);
                $this->log?->info("    fetching data for $id");
                $meta              = $this->normalizer->fetch($id);
                $uriStr            = $meta->getNode()->getValue();
                $extDbName         = $this->mappings->matchExternalDatabase($uriStr);
                if (!isset($entityExtMeta[$extDbName])) {
                    $entityExtMeta[$extDbName] = [];
                } if (!isset($entityExtMeta[$extDbName][$uriStr])) {
                    $entityExtMeta[$extDbName][$uriStr] = $meta;
                    foreach ($this->mappings->mapIdentifiers($meta, $extDbName) as $i) {
                        if (!isset($idsProcessed[$i]) && !in_array($i, $idsToProcess)) {
                            $idsToProcess[] = $i;
                        }
                    }
                }
            } catch (RefSourcesException | UriNormalizerException $e) {
                $this->log?->debug("    unsupported source: " . $e->getMessage());
            } catch (RdfIoException $e) {
                $this->log?->warning("    " . $e->getMessage());
            }
        }
        $dbNames = $this->mappings->getDbNames();
        uksort($entityExtMeta, fn($a, $b) => array_search($a, $dbNames) <=> array_search($b, $dbNames));

        // update entity's metadata
        $entityMeta     = $entity->getMetadata();
        $entityMetaOrig = $entityMeta->getDataset()->copy();
        foreach ($entityExtMeta as $extDbName => $extDbMetas) {
            foreach ($extDbMetas as $extDbMeta) {
                try {
                    $this->mappings->resolveAndMerge($extDbName, $entityMeta, $extDbMeta);
                } catch (RefSourcesException $e) {
                    $this->log?->debug("      " . $e->getMessage());
                }
            }
        }
        // return merged and original metadata
        return new ProcessEntityResult($entityMeta, $entityMetaOrig);
    }
}
