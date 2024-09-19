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

namespace acdhOeaw\arche\refSources\tests;

use quickRdf\Dataset;
use quickRdf\DataFactory as DF;
use quickRdfIo\Util as RdfIoUtil;
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\refSources\Crawler;
use acdhOeaw\arche\refSources\NamedEntityIteratorFile;

/**
 * Description of ReferenceSourcesTest
 *
 * @author zozlak
 */
class CrawlerTest extends \PHPUnit\Framework\TestCase {

    static private Schema $schema;

    static public function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $repo         = Repo::factoryFromUrl('https://arche.acdh.oeaw.ac.at/api/');
        self::$schema = $repo->getSchema();
    }

    public function testVocabs(): void {
        $this->runTestFromData('vocabs');
    }

    public function testGnd(): void {
        $this->runTestFromData('gnd');
    }

    private function runTestFromData(string $testName): void {
        $source   = new NamedEntityIteratorFile(__DIR__ . "/data/$testName.input", self::$schema, 'text/turtle');
        $crawler  = $this->getCrawler($testName);
        $expected = new Dataset();
        $expected->add(RdfIoUtil::parse(__DIR__ . "/data/$testName.output", new DF(), 'text/turtle'));
        if (file_exists(__DIR__ . "/data/$testName.old")) {
            $expectedOld = new Dataset();
            $expectedOld->add(RdfIoUtil::parse(__DIR__ . "/data/$testName.old", new DF(), 'text/turtle'));
        }

        $oldMeta    = new Dataset();
        $mergedMeta = new Dataset();
        foreach ($crawler->crawl($source) as $data) {
            $mergedMeta->add($data[0]);
            $oldMeta->add($data[1]);
        }
        // for more meaningfull failure messages let's compare differences with ''
        $this->assertEquals('', (string) $expected->copyExcept($mergedMeta));
        $this->assertEquals('', (string) $mergedMeta->copyExcept($expected));
        if (isset($expectedOld)) {
            $this->assertEquals('', (string) $expectedOld->copyExcept($oldMeta));
            $this->assertEquals('', (string) $oldMeta->copyExcept($expectedOld));
        }
    }

    private function getCrawler(string $testName): Crawler {
        $cfg = json_decode(json_encode(yaml_parse_file(__DIR__ . "/data/$testName.yaml")));
        return new Crawler($cfg->referenceSources, self::$schema);
    }
}
