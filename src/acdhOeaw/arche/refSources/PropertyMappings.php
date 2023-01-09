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

use rdfInterface\DatasetNodeInterface;
use rdfInterface\NamedNodeInterface;
use acdhOeaw\UriNormalizer;
use acdhOeaw\UriNormalizerRule;
use acdhOeaw\UriNormalizerException;

/**
 * Description of PropertyMappings
 *
 * @author zozlak
 */
class PropertyMappings {

    private UriNormalizer $normalizer;
    private NamedNodeInterface $idProp;

    /**
     * 
     * @var array<string, UriNormalizerRule>
     */
    private array $rules = [];

    /**
     * 
     * @var array<string, array<PropertyMapping>>
     */
    private array $mappings = [];

    public function __construct(UriNormalizer $normalizer,
                                NamedNodeInterface $idProp) {
        $this->normalizer = $normalizer;
        $this->idProp     = $idProp;
    }

    /**
     * 
     * @param string $dbName
     * @param UriNormalizerRule $rule
     * @param array<mixed> $mappings
     * @return void
     */
    public function addExternalDatabase(string $dbName, UriNormalizerRule $rule,
                                        array $mappings): void {
        $this->rules[$dbName]    = $rule;
        $this->mappings[$dbName] = [];
        foreach ($mappings as $i) {
            $this->mappings[$dbName][] = new PropertyMapping($i);
        }
    }

    /**
     * 
     * @return array<NamedNodeInterface>
     */
    public function mapIdentifiers(DatasetNodeInterface $meta,
                                   ?string $dbName = null): array {
        $dbName ??= $this->matchExternalDatabase($meta->getNode()->getValue());
        $ids    = [];
        foreach ($this->mappings[$dbName] as $mapping) {
            if ($mapping->getProperty()->equals($this->idProp)) {
                foreach ($mapping->resolve($meta, $this->normalizer, true) as $idQuad) {
                    $ids[] = $idQuad->getObject()->getValue();
                }
            }
        }
        return array_unique($ids);
    }

    public function matchExternalDatabase(string $uri): string {
        foreach ($this->rules as $dbName => $rule) {
            if (preg_match("`$rule->match`", $uri)) {
                return $dbName;
            }
        }
        throw new RefSourcesException("No matching external database found for uri '$uri'");
    }

    public function resolveAndMerge(string $dbName, DatasetNodeInterface $meta,
                                    DatasetNodeInterface $extDbMeta): void {
        if (!isset($this->mappings[$dbName])) {
            throw new RefSourcesException("No mappings defined for the external database '$dbName'");
        }
        foreach ($this->mappings[$dbName] as $mapping) {
            $mapping->resolveAndMerge($meta, $extDbMeta, $this->normalizer, $this->idProp === $mapping->getProperty());
        }
    }
}
