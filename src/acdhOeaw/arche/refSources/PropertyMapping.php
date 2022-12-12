<?php

/*
 * The MIT License
 *
 * Copyright 2022 zozlak.
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
use RuntimeException;
use rdfInterface\LiteralInterface as iLiteral;
use rdfInterface\NamedNodeInterface as iNamedNode;
use rdfInterface\QuadInterface as iQuad;
use rdfInterface\DatasetInterface as iDataset;
use rdfInterface\DatasetListQuadPartsInterface as iDatasetLQP;
use rdfInterface\DatasetCompareInterface as iDatasetCompare;
use quickRdf\Dataset;
use quickRdf\DataFactory as DF;
use termTemplates\QuadTemplate as QT;
use termTemplates\NamedNodeTemplate;
use termTemplates\ValueTemplate;
use acdhOeaw\UriNormalizer;

/**
 * Description of PropertyMapping
 *
 * @author zozlak
 */
class PropertyMapping {

    const ACTION_ADD        = 'add';
    const ACTION_REPLACE    = 'replace';
    const ACTION_IF_MISSING = 'if missing';
    const LANG_OVERWRITE    = 'overwrite';
    const LANG_ASSURE       = 'assure';
    const LANG_REMOVE       = 'remove';
    const LANG_PASS         = 'pass';
    const TYPE_ID           = 'id';
    const TYPE_LITERAL      = 'literal';
    const TYPE_RESOURCE     = 'resource';

    /**
     * 
     * @param array<object> $cfg
     * @return array<PropertyMapping>
     */
    static public function fromConfig(array $cfg): array {
        $defs = [];
        foreach ($cfg as $i) {
            $defs[] = new PropertyMapping($i);
        }
        return $defs;
    }

    private iNamedNode $property;
    private string $type;
    private string $action;
    private string $langProcess;
    private string $langValue;
    private int $maxPerLang;
    private string $match;
    private string $skip;

    /**
     * 
     * @var array<iNamedNode>
     */
    private array $path;

    public function __construct(object $cfg) {
        $this->property    = DF::namedNode($cfg->property);
        $this->action      = $cfg->action;
        $this->type        = $cfg->type;
        $this->langProcess = $cfg->langProcess ?? self::LANG_PASS;
        $this->langValue   = $cfg->langValue ?? '';
        $this->maxPerLang  = $cfg->maxPerLang ?? PHP_INT_MAX;
        $this->path        = array_map(fn($x) => DF::namedNode($x), $cfg->path);
        $this->match       = $cfg->match ?? '';
        $this->skip        = $cfg->skip ?? '';
    }

    public function merge(iDatasetCompare $meta, Resource $dbMeta,
                          iNamedNode $subject,
                          UriNormalizer $normalizer): void {
        $id = DF::namedNode($dbMeta->getUri());
        $dbMeta = Util::asRdfInterfaceDataset($dbMeta);
        $dbMeta = $this->resolve($dbMeta, $id, $subject, $normalizer);
        if (count($dbMeta) === 0) {
            return;
        }
        switch ($this->action) {
            case self::ACTION_ADD:
                $meta->add($dbMeta);
                break;
            case self::ACTION_REPLACE:
                $meta->delete(new QT($subject, $this->property));
                $meta->add($dbMeta);
                break;
            case self::ACTION_IF_MISSING:
                if ($meta->none(new QT($subject, $this->property))) {
                    $meta->add($dbMeta);
                }
                break;
            default:
                throw new RuntimeException("Wrong property action " . $this->action);
        }
    }

    /**
     * 
     * @param Dataset $meta
     * @param iNamedNode $id
     * @param iNamedNode|null $subject subject to assign to resolved data
     * @param UriNormalizer $normalizer Allows recursive resolution of URIs.
     * @return Dataset
     */
    public function resolve(Dataset $meta, iNamedNode $id,
                            ?iNamedNode $subject = null,
                            UriNormalizer $normalizer): iDataset {
        $values = $this->resolvePath($meta, $id, $subject, $normalizer);
        $this->filter($values);
        $this->processLang($values);
        return $values;
    }

    /**
     * 
     * @param Dataset $meta
     * @param iNamedNode $id
     * @param iNamedNode|null $subject subject to assign to resolved data
     * @param UriNormalizer $normalizer Allows recursive resolution of URIs.
     * @return Dataset
     */
    public function resolvePath(Dataset $meta, iNamedNode $id,
                                ?iNamedNode $subject = null,
                                UriNormalizer $normalizer): iDataset {
        // fetch triples
        $data = $this->resolveRecursively($meta, $id, $normalizer, $this->path);
        // fix their subject and map the predicate
        $data->forEach(fn(iQuad $x) => $x->withSubject($subject ?? $id)->withPredicate($this->property));
        return $data;
    }

    public function filter(iDataset $values): void {
        $match = $this->match;
        $skip  = $this->skip;
        if (empty($match) && empty($skip)) {
            return;
        }
        $skip = empty($skip) ? '^$' : $skip;
        $values->deleteExcept(fn(iQuad $x) => preg_match("`$match`", $x->getObject()->getValue()) && !preg_match("`$skip`", $x->getObject()->getValue()));
    }

    public function processLang(iDataset $meta): void {
        if ($this->type !== self::TYPE_LITERAL) {
            return;
        }
        $process  = $this->langProcess;
        $value    = $this->langValue;
        $maxCount = $this->maxPerLang;
        $counts   = [];
        $meta->forEach(function (iQuad $x) use ($process, $value, $maxCount,
                                                &$counts) {
            /* @var $literal iLiteral */
            $literal       = $x->getObject();
            $lang          = match ($process) {
                PropertyMapping::LANG_PASS => $literal->getLang(),
                PropertyMapping::LANG_ASSURE => $literal->getLang() ?? $value,
                PropertyMapping::LANG_OVERWRITE => $value,
                PropertyMapping::LANG_REMOVE => null,
                default => throw new RuntimeException("Unsupported lang tag processing mode $process"),
            };
            $lang          ??= '';
            $counts[$lang] = ($counts[$lang] ?? 0) + 1;
            if ($counts[$lang] > $maxCount) {
                return null;
            }
            return $x->withObject($literal->withLang($lang));
        });
    }

    /**
     * 
     * @param iDatasetLQP $meta
     * @param iNamedNode $sbj
     * @param UriNormalizer $normalizer
     * @param array<iNamedNode> $path
     * @return Dataset
     */
    private function resolveRecursively(iDatasetLQP $meta, iNamedNode $sbj,
                                        UriNormalizer $normalizer,
                                        array $path): Dataset {
        if (count($path) < 2) {
            return $meta->copy(new QT($sbj, $path[0]));
        }
        $nnt    = new NamedNodeTemplate(null, ValueTemplate::ANY);
        $prop   = array_shift($path);
        $values = new Dataset();
        foreach ($meta->listObjects(new QT($sbj, $prop, $nnt)) as $newSbj) {
            $newValues = $meta->copy(new QT($newSbj));
            if (count($newValues) > 0) {
                $values->union($this->resolveRecursively($meta, $newSbj, $normalizer, $path));
            } else {
                // just empty object - try to resolve it
                $newMeta = Util::asRdfInterfaceDataset($normalizer->fetch($newSbj->getValue()));
                if ($newMeta !== null) {
                    $values->union($this->resolveRecursively($newMeta, $newSbj, $normalizer, $path));
                }
            }
        }
        return $values;
    }
}
