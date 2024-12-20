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

use termTemplates\PredicateTemplate as PT;
use acdhOeaw\UriNormalizer;
use acdhOeaw\UriNormalizerException;
use acdhOeaw\arche\lib\RepoInterface;
use acdhOeaw\arche\lib\RepoResource;
use rdfInterface\DatasetNodeInterface;

/**
 * Description of RefResourceRepo
 *
 * @author zozlak
 */
class NamedEntityRepo implements NamedEntityInterface {

    private RepoResource $res;
    private RepoInterface $repo;

    public function __construct(RepoResource $res) {
        $this->res  = $res;
        $this->repo = $res->getRepo();
    }

    public function getMetadata(): DatasetNodeInterface {
        return $this->res->getMetadata();
    }

    /**
     * 
     * @paramUriNormalizer $normalizer
     * @return array<string>
     */
    public function getIdentifiers(UriNormalizer $normalizer): array {
        $idProp = $this->repo->getSchema()->id;
        $ids    = [];
        foreach ($this->res->getGraph()->listObjects(new PT($idProp)) as $id) {
            try {
                $ids[] = $normalizer->normalize((string) $id, true);
            } catch (UriNormalizerException) {
                
            }
        }
        return $ids;
    }

    public function getUri(): string {
        return $this->res->getUri();
    }
}
