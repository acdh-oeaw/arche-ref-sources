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
use acdhOeaw\arche\lib\RepoResource;
use acdhOeaw\UriNormalizer;

/**
 * Description of RefResourceFile
 *
 * @author zozlak
 */
class NamedEntityFile implements NamedEntityInterface {

    private Resource $res;
    private NamedEntityIteratorFile $iter;
    private UriNormalizer $normalizer;

    public function __construct(Resource $res, NamedEntityIteratorFile $iter) {
        $this->res        = $res;
        $this->iter       = $iter;
        $this->normalizer = UriNormalizer::factory();
    }

    public function getIdentifiers(string $match): array {
        $match = "`$match`";
        $ids   = [];
        foreach ($this->res->allResources($this->iter->getIdProp()) as $id) {
            $id = (string) $id;
            $id = $this->normalizer->normalize($id);
            if (preg_match($match, $id)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    public function getUri(): string {
        return $this->res->getUri();
    }

    public function updateMetadata(Resource $meta, bool $test = true): void {
        $repoRes = $this->iter->getRepoResource($this->res);
        $repo    = $repoRes->getRepo();
        $repo->begin();
        $repoRes->setMetadata($meta);
        $repoRes->updateMetadata(RepoResource::UPDATE_MERGE);
        if ($test) {
            $repo->rollback();
        } else {
            $repo->commit();
        }
    }
}
