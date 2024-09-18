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

    const SAMPLE_INPUT_VOCABS = ' <https://vocabs.acdh.oeaw.ac.at/iso6393/pol> <https://vocabs.acdh.oeaw.ac.at/schema#hasIdentifier> <https://vocabs.acdh.oeaw.ac.at/iso6393/pol>.';
    const SAMPLE_INPUT_GND    = ' <https://d-nb.info/gnd/118523147> <https://vocabs.acdh.oeaw.ac.at/schema#hasIdentifier> <https://d-nb.info/gnd/118523147> .';

    static private object $cfg;
    static private Schema $schema;

    static public function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        self::$cfg = json_decode(json_encode(yaml_parse_file(__DIR__ . '/../config-sample.yaml')));

        $repo         = Repo::factoryFromUrl('https://arche.acdh.oeaw.ac.at/api/');
        self::$schema = $repo->getSchema();
    }

    private Crawler $crawler;

    public function setUp(): void {
        parent::setUp();
        $this->crawler = new Crawler(self::$cfg->referenceSources, self::$schema);
    }

    public function testVocabsSimple(): void {
        $source = new NamedEntityIteratorFile(self::SAMPLE_INPUT_VOCABS, self::$schema);
        foreach ($this->crawler->crawl($source) as $n => $data) {
            list($fullMeta, $oldMeta) = $data;
            $this->assertCount(1, $oldMeta);
            $ref = $this->getExpectedMeta('testVocabsSimple');
            $this->assertEquals("", (string) $ref->copyExcept($fullMeta));
            $this->assertEquals("", (string) $fullMeta->copyExcept($ref));
        }
        $this->assertEquals(0, $n);
    }

    public function testIfMissing(): void {
        $inputMeta = "
            <https://vocabs.acdh.oeaw.ac.at/iso6393/pol> <" . self::$schema->id . "> <https://vocabs.acdh.oeaw.ac.at/iso6393/pol> ;
                <" . self::$schema->label . "> \"polski\"@pl .
        ";
        $source    = new NamedEntityIteratorFile($inputMeta, self::$schema);
        foreach ($this->crawler->crawl($source) as $n => $data) {
            list($fullMeta, $oldMeta) = $data;
            $this->assertTrue($oldMeta->equals($fullMeta));
            $ref = $this->getExpectedMeta($inputMeta);
            $this->assertTrue($ref->equals($fullMeta));
        }
        $this->assertEquals(0, $n);
    }

    public function testGndSimple(): void {
        $source = new NamedEntityIteratorFile(self::SAMPLE_INPUT_GND, self::$schema);
        foreach ($this->crawler->crawl($source) as $n => $data) {
            list($fullMeta, $oldMeta) = $data;
            $this->assertCount(1, $oldMeta);
            $ref = $this->getExpectedMeta('testGndSimple');
            $this->assertEquals("", (string) $ref->copyExcept($fullMeta));
            $this->assertEquals("", (string) $fullMeta->copyExcept($ref));
        }
        $this->assertEquals(0, $n);
    }
    
    private function getExpectedMeta(string $testName): Dataset {
        $d     = new Dataset();
        $input = file_exists(__DIR__ . '/data/' . $testName . '.ttl') ? __DIR__ . '/data/' . $testName . '.ttl' : $testName;
        $d->add(RdfIoUtil::parse($input, new DF(), 'text/turtle'));
        return $d;
    }
}
