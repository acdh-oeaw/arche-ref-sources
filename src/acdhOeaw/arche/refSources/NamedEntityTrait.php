<?php

/*
 * The MIT License
 *
 * Copyright 2023 zozlak.
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

use quickRdf\DataFactory;
use acdhOeaw\arche\lib\RepoResource;
use rdfInterface\DatasetNodeInterface;
use termTemplates\QuadTemplate as QT;
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\SearchTerm;
use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\exception\NotFound;

/**
 * Description of NamedEntityTrait
 *
 * @author zozlak
 */
trait NamedEntityTrait {

    /**
     * 
     * @param DatasetNodeInterface $meta
     * @param bool $test
     * @return array<string>
     */
    public function updateMetadata(Repo $repo, DatasetNodeInterface $meta, bool $test = true): array {
        if ($repo->inTransaction()) {
            $repo->rollback();
        }
        $schema = $repo->getSchema();

        // merge all matching resources
        $merged                 = [];
        $ids                    = iterator_to_array($meta->getIterator(new QT(predicate: DataFactory::namedNode($schema->id))));
        $ids                    = array_map(fn($x) => $x->getObject()->getValue(), $ids);
        $st                     = new SearchTerm($schema->id, $ids, '=');
        $sc                     = new SearchConfig();
        $sc->metadataMode       = RepoResource::META_RESOURCE;
        $sc->resourceProperties = [$schema->creationDate];
        try {
            $mainUri       = $meta->getNode()->getValue();
            $repoResources = $repo->getResourcesBySearchTerms([$st], $sc);
            $repoResources = iterator_to_array($repoResources);
            // check if $meta's node makes sense and substitute if not
            $inRepo        = array_sum(array_map(fn($x) => $x->getUri() === $mainUri, $repoResources));
            if ($inRepo === 0) {
                $mainUri = $repoResources[0]->getUri();
            }
            $mainRes = array_filter($repoResources, fn($x) => $x->getUri() === $mainUri);
            /* @var RepoResource $mainRes */
            $mainRes = reset($mainRes);
            
            // merge all matching repo resources with the main one
            $repo->begin();
            foreach ($repoResources as $repoResource) {
                /* @var RepoResource $repoResource */
                if ($repoResource->getUri() === $mainUri) {
                    continue;
                }
                $merged[] = $repoResource->getUri();
                $repoResource->merge($mainUri, RepoResource::META_NONE);
            }

            // update the main resource
            $mainRes->setMetadata($meta);
            $mainRes->updateMetadata(RepoResource::UPDATE_MERGE);
        } catch (NotFound) {
            $repo->begin();
            $repo->createResource($meta);
        }
        if ($test) {
            $repo->rollback();
        } else {
            $repo->commit();
        }

        // return merge results
        return $merged;
    }
}
