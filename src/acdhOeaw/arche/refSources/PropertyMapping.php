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

use RuntimeException;
use rdfInterface\LiteralInterface;
use rdfInterface\NamedNodeInterface;
use rdfInterface\TermInterface;
use rdfInterface\QuadInterface;
use rdfInterface\DatasetInterface;
use rdfInterface\DatasetNodeInterface;
use quickRdf\Dataset;
use quickRdf\DataFactory as DF;
use termTemplates\QuadTemplate as QT;
use termTemplates\NamedNodeTemplate;
use acdhOeaw\UriNormalizer;
use acdhOeaw\UriNormalizerException;

/**
 * Defines a single target ARCHE property mapping
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

    private NamedNodeInterface $property;
    private string $type;
    private string $action;
    private string $langProcess;
    private string $langValue;
    private int $maxPerLang;
    private string $match;
    private string $skip;
    private TermInterface $value;

    /**
     * 
     * @var array<NamedNodeInterface>
     */
    private array $path;

    public function __construct(object $cfg) {
        $this->property    = DF::namedNode($cfg->property);
        $this->action      = $cfg->action;
        $this->type        = $cfg->type;
        $this->langProcess = $cfg->langProcess ?? self::LANG_PASS;
        $this->langValue   = $cfg->langValue ?? '';
        $this->maxPerLang  = $cfg->maxPerLang ?? PHP_INT_MAX;
        if (!empty($cfg->value)) {
            $this->value = $cfg->type === 'resource' ? DF::namedNode($cfg->value) : DF::literal($cfg->value);
        } else {
            $this->path = array_map(fn($x) => DF::namedNode($x), $cfg->path);
        }
        $this->match        = $cfg->match ?? '';
        $this->skip         = $cfg->skip ?? '';
    }

    public function resolveAndMerge(DatasetNodeInterface $meta,
                                    DatasetNodeInterface $extDbMeta,
                                    UriNormalizer $normalizer, bool $normalize): void {
        $extDbMeta = $this->resolve($extDbMeta, $normalizer, $normalize, $meta->getNode());
        if (count($extDbMeta->getDataset()) === 0) {
            return;
        }
        $dataset = $meta->getDataset();
        switch ($this->action) {
            case self::ACTION_ADD:
                $dataset->add($extDbMeta);
                break;
            case self::ACTION_REPLACE:
                $dataset->delete(new QT($meta->getNode(), $this->property));
                $dataset->add($extDbMeta);
                break;
            case self::ACTION_IF_MISSING:
                if ($dataset->none(new QT($meta->getNode(), $this->property))) {
                    $dataset->add($extDbMeta);
                }
                break;
            default:
                throw new RuntimeException("Wrong property action " . $this->action);
        }
    }

    /**
     * 
     * @param DatasetNodeInterface $meta
     * @param UriNormalizer $normalizer UriNormalizer object allowing for
     *   recursive resolution of URIs.
     * @param TermInterface|null $subject optional triples subject to be enforeced on
     *   ther returned data
     * @return DatasetNodeInterface
     */
    public function resolve(DatasetNodeInterface $meta,
                            UriNormalizer $normalizer, bool $normalize,
                            ?TermInterface $subject = null): DatasetNodeInterface {
        $values = $this->resolvePath($meta, $normalizer, $subject);
        $this->filter($values->getDataset());
        $this->processLang($values->getDataset());
        if ($normalize) {
            $this->normalize($values->getDataset(), $normalizer);
        }
        return $values;
    }

    /**
     * 
     * @param DatasetNodeInterface $meta
     * @param UriNormalizer $normalizer UriNormalizer object allowing for
     *   recursive resolution of URIs.
     * @param TermInterface|null $subject optional triples subject to be enforeced on
     *   ther returned data
     * @return DatasetNodeInterface
     */
    public function resolvePath(DatasetNodeInterface $meta,
                                UriNormalizer $normalizer,
                                ?TermInterface $subject = null): DatasetNodeInterface {
        $subject ??= $meta->getNode();
        if (!empty($this->value)) {
            $data = new Dataset();
            $data->add(DF::quad($subject, $this->property, $this->value));
        } else {
            $data = $this->resolveRecursively($meta->getDataset(), $meta->getNode(), $normalizer, $this->path);
            // fix their subject and map the predicate
            $data->forEach(fn(QuadInterface $x) => $x->withSubject($subject)->withPredicate($this->property));
        }
        return $meta->withDataset($data)->withNode($subject);
    }

    public function getProperty(): NamedNodeInterface {
        return $this->property;
    }

    private function filter(DatasetInterface $values): void {
        $match = $this->match;
        $skip  = $this->skip;
        if (empty($match) && empty($skip)) {
            return;
        }
        $skip = empty($skip) ? '^$' : $skip;
        $values->deleteExcept(fn(QuadInterface $x) => preg_match("`$match`", $x->getObject()->getValue()) && !preg_match("`$skip`", $x->getObject()->getValue()));
    }

    private function processLang(DatasetInterface $meta): void {
        if ($this->type !== self::TYPE_LITERAL) {
            return;
        }
        $process  = $this->langProcess;
        $value    = $this->langValue;
        $maxCount = $this->maxPerLang;
        $counts   = [];
        $meta->forEach(function (QuadInterface $x) use ($process, $value,
                                                        $maxCount, &$counts) {
            $literal = $x->getObject();
            if (!($literal instanceof LiteralInterface)) {
                return $x->withObject(DF::literal($literal->getValue()));
            }
            /* @var $literal LiteralInterface */
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

    private function normalize(DatasetInterface $meta, UriNormalizer $normalizer): void {
        $meta->forEach(function (QuadInterface $x) use ($normalizer) {
            $obj = $x->getObject();
            if (!($obj instanceof LiteralInterface) && !($obj instanceof NamedNodeInterface)) {
                return $x;
            }
            try {
                $val = $normalizer->normalize($obj->getValue());
                if ($obj instanceof NamedNodeInterface) {
                    $val = DF::namedNode($val);
                } else {
                    $val = DF::literal($val, $obj->getLang(), $obj->getDatatype());
                }
                return $x->withObject($val);
            } catch (UriNormalizerException) {
                return $x;
            }
        });
    }

    /**
     * 
     * @param DatasetInterface $meta
     * @param NamedNodeInterface $sbj
     * @param UriNormalizer $normalizer
     * @param array<NamedNodeInterface> $path
     * @return DatasetInterface
     */
    private function resolveRecursively(DatasetInterface $meta,
                                        NamedNodeInterface $sbj,
                                        UriNormalizer $normalizer, array $path): DatasetInterface {
        if (count($path) < 2) {
            return $meta->copy(new QT($sbj, $path[0]));
        }
        $nnt    = new NamedNodeTemplate(null, NamedNodeTemplate::ANY);
        $prop   = array_shift($path);
        $values = new Dataset();
        foreach ($meta->listObjects(new QT($sbj, $prop, $nnt)) as $newSbj) {
            $newValues = $meta->copy(new QT($newSbj));
            if (count($newValues) > 0) {
                $values->union($this->resolveRecursively($meta, $newSbj, $normalizer, $path));
            } else {
                // just empty object - try to resolve it
                try {
                    $newMeta = $normalizer->fetch($newSbj->getValue());
                    if ($newMeta !== null) {
                        $values->union($this->resolveRecursively($newMeta, $newSbj, $normalizer, $path));
                    }
                } catch (UriNormalizerException $e) {
                    echo "error: unable to resolve the URI: " . $e->getMessage() . "\n";
                }
            }
        }
        return $values;
    }
}
