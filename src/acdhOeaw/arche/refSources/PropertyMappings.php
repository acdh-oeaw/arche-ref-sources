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
use acdhOeaw\UriNormRules;
use acdhOeaw\UriNormalizer;
use acdhOeaw\UriNormalizerRule;
use acdhOeaw\UriNormalizerException;
use zozlak\RdfConstants as RDF;
use termTemplates\QuadTemplate as QT;
use termTemplates\AnyOfTemplate;
use quickRdf\DataFactory as DF;
use rdfInterface\QuadInterface;

/**
 * Description of PropertyMappings
 *
 * @author zozlak
 */
class PropertyMappings {

    const WIKIDATA_INSTANCE_OF = 'http://www.wikidata.org/prop/direct/P31';

    private UriNormalizer $normalizer;
    private NamedNodeInterface $idProp;

    /**
     * 
     * @var array<string>
     */
    private array $dbNames = [];

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
     * Adds URI normalization rule for a given external database.
     * 
     * @param string $dbName
     * @param UriNormalizerRule $rule
     * @return void
     */
    public function addExternalDatabase(string $dbName, UriNormalizerRule $rule): void {
        $this->rules[$dbName] = $rule;
    }

    /**
     * Adds property mappings for a given class of a given external database.
     * 
     * @param string $dbName
     * @param string $class
     * @param array<mixed> $mappings
     * @return void
     */
    public function addExternalDatabaseClass(string $dbName, string $class,
                                             array $mappings): void {
        $this->dbNames[]     = $dbName;
        $id                  = $this->getId($dbName, $class);
        $this->mappings[$id] = [];
        foreach ($mappings as $i) {
            $this->mappings[$id][] = new PropertyMapping($i);
        }
    }

    public function getRule(string $dbName): UriNormalizerRule {
        return $this->rules[$dbName];
    }

    /**
     * 
     * @return array<string>
     */
    public function mapIdentifiers(DatasetNodeInterface $meta,
                                   ?string $dbName = null): array {
        $classes = $this->getClasses($meta, $dbName);
        $ids     = [];
        foreach ($classes as $class) {
            foreach ($this->mappings[$class] as $mapping) {
                if ($mapping->getProperty()->equals($this->idProp)) {
                    foreach ($mapping->resolve($meta, $this->normalizer, true) as $idQuad) {
                        try {
                            $ids[] = $this->normalizer->normalize($idQuad->getObject()->getValue(), true);
                        } catch (UriNormalizerException) {
                            
                        }
                    }
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
        $classes = $this->getClasses($extDbMeta, $dbName);
        if (count($classes) === 0) {
            throw new RefSourcesException("No mappings found for the external database '$dbName'");
        }
        foreach ($classes as $class) {
            foreach ($this->mappings[$class] as $mapping) {
                $mapping->resolveAndMerge($meta, $extDbMeta, $this->normalizer, $this->idProp === $mapping->getProperty());
            }
        }
    }

    /**
     * 
     * @return array<string>
     */
    public function getDbNames(): array {
        return $this->dbNames;
    }

    public function parseConfig(object $cfg): void {
        $normRules = UriNormRules::getRules();

        foreach ($cfg as $extDbName => $extDbCfg) {
            $rule = array_filter($normRules, fn($x) => $x->name === $extDbName);
            if (count($rule) === 0) {
                die("No normalization rules found for the '$extDbName' external reference database\n");
            }
            $rule = UriNormalizerRule::factory(reset($rule));
            $this->addExternalDatabase($extDbName, $rule);
            foreach ($extDbCfg as $class => $cCfg) {
                $this->addExternalDatabaseClass($extDbName, $class, $cCfg);
            }
        }
    }

    private function getId(string $dbName, string $class): string {
        return $dbName . '|' . $class;
    }

    /**
     * 
     * @param DatasetNodeInterface $meta
     * @param string|null $dbName
     * @return array<string>
     */
    private function getClasses(DatasetNodeInterface $meta, ?string $dbName): array {
        $dbName          ??= $this->matchExternalDatabase($meta->getNode()->getValue());
        $classProperties = [
            DF::namedNode(RDF::RDF_TYPE),
            DF::namedNode(self::WIKIDATA_INSTANCE_OF),
        ];
        $classes         = $meta->getDataset()->listObjects(new QT($meta->getNode(), new AnyOfTemplate($classProperties)))->getValues();
        $classes         = array_map(fn($x) => $this->getId($dbName, $x), $classes);
        $classes         = array_intersect($classes, array_keys($this->mappings));
        return $classes;
    }
}
