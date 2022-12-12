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

use quickRdf\DataFactory;
use quickRdf\Dataset;
use rdfInterface\DatasetInterface;
use rdfInterface\LiteralInterface;
use EasyRdf\Graph;
use EasyRdf\Resource;
use EasyRdf\Literal;

/**
 * Description of Util
 *
 * @author zozlak
 */
class Util {

    static public function asEasyRdfResource(DatasetInterface $d,
                                             ?string $uri = null): Resource {
        $g = new Graph();
        $r = $g->resource(empty($uri) ? $d[0]->getSubject()->getValue() : $uri);
        foreach ($d as $i) {
            $p = $i->getPredicate()->getValue();
            $o = $i->getObject();
            $v = $o->getValue();
            if ($o instanceof LiteralInterface) {
                $r->addLiteral($p, new Literal($v, $o->getLang(), empty($o->getLang()) ? $o->getDatatype() : null));
            } else {
                $r->addResource($p, $v);
            }
        }
        return $r;
    }

    static public function asRdfInterfaceDataset(Resource $res): Dataset {
        $df  = new DataFactory();
        $d   = new Dataset();
        $sbj = $df->namedNode($res->getUri());
        foreach ($res->propertyUris() as $p) {
            $pred = $df->namedNode($p);
            foreach ($res->all($p) as $o) {
                if ($o instanceof Literal) {
                    $d->add($df::quad($sbj, $pred, $df::literal($o->getValue(), $o->getLang(), $o->getDatatype())));
                } else {
                    $d->add($df::quad($sbj, $pred, $o->isBNode() ? $df::blankNode($o->getUri()) : $df::namedNode($o->getUri())));
                }
            }
        }
        return $d;
    }
}
