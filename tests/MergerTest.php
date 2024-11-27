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

use PDO;
use rdfInterface\QuadInterface;
use quickRdf\Dataset;
use quickRdf\DataFactory as DF;
use termTemplates\QuadTemplate as QT;
use termTemplates\PredicateTemplate as PT;
use termTemplates\ValueTemplate as VT;
use quickRdfIo\Util as RdfIoUtil;
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\RepoResource;
use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\lib\ingest\MetadataCollection;
use acdhOeaw\arche\refSources\Merger;

/**
 * Description of MergerTest
 *
 * @author zozlak
 */
class MergerTest extends \PHPUnit\Framework\TestCase {

    static private Schema $schema;
    static private PDO $pdo;

    static public function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $repo         = Repo::factoryFromUrl('https://arche.acdh.oeaw.ac.at/api/');
        self::$schema = $repo->getSchema();

        self::$pdo = new PDO('pgsql: host=127.0.0.1 port=5432 user=www-data');
    }

    private int $resIdMax;

    public function setUp(): void {
        parent::setUp();

        $this->resIdMax = self::$pdo->query("SELECT max(id) FROM resources")->fetchColumn();
    }

    public function tearDown(): void {
        parent::tearDown();

        $query = self::$pdo->prepare("DELETE FROM resources WHERE id > ?");
        $query->execute([$this->resIdMax]);
    }

    public function testMerge(): void {
        $merger = new Merger(self::$schema);
        $data   = new Dataset();
        $data->add(RdfIoUtil::parse(__DIR__ . '/data/merge.input', new DF(), 'text/turtle'));
        $merger->merge($data);
        //echo "@@@\n" . RdfIoUtil::serialize($data, 'text/turtle') . "\n";

        $expected = new Dataset();
        $expected->add(RdfIoUtil::parse(__DIR__ . "/data/merge.output", new DF(), 'text/turtle'));
        // for more meaningfull failure messages let's compare differences with ''
        $this->assertEquals('', RdfIoUtil::serialize($expected->copyExcept($data), 'text/turtle'), 'Missing in merged metadata');
        $this->assertEquals('', RdfIoUtil::serialize($data->copyExcept($expected), 'text/turtle'), 'Additional in merged metadata');
    }

    /**
     * A complex test bringing together:
     * - initial repository content with duplicated named entities scattered along
     *   multiple independent resources
     * - pre-preparred external reference sources harvesting results which will
     *   cause merging of existing independent repository resources
     * 
     * @return void
     */
    public function testUpdate(): void {
        $guzzleOpts = ['auth' => ['admin', 'admin']];
        $repo       = Repo::factoryFromUrl('http://127.0.0.1/api/', $guzzleOpts);

        // initialize the repository content and take a metadata snapshot
        $mc       = new MetadataCollection($repo, __DIR__ . '/data/update.repo', 'text/turtle');
        $repo->begin();
        // concurrency 1 for predictable resource creation order
        $origRes  = $mc->import($repo->getBaseUrl(), MetadataCollection::CREATE, concurrency: 1);
        $repo->commit();
        $origData = $this->collectResMetadata($origRes);
        $refData  = new Dataset();
        $refData->add(RdfIoUtil::parse(__DIR__ . '/data/update.output', new DF(), 'text/turtle'));
        $inData   = new Dataset();
        $inData->add(RdfIoUtil::parse(__DIR__ . '/data/update.input', new DF(), 'text/turtle'));
        $merger   = new Merger($repo);

        // test scenario - repository resources should stay untouched, $output should contain merged data
        $output  = tmpfile();
        $merger->update($inData, true, $output);
        $outData = $this->collectResults($output);
        $curData = $this->collectResMetadata($origRes);
        $this->assertEquals('', RdfIoUtil::serialize($origData->copyExcept($curData), 'text/turtle'), 'Was originally, missing after the update()');
        $this->assertEquals('', RdfIoUtil::serialize($curData->copyExcept($origData), 'text/turtle'), 'Added by the update()');
        $this->assertEquals('', RdfIoUtil::serialize($refData->copyExcept($outData), 'text/turtle'), 'Missing data');
        $this->assertEquals('', RdfIoUtil::serialize($outData->copyExcept($refData), 'text/turtle'), 'Additional data');

        // update scenario - repository resources and output should match and contain merged data
        $output  = tmpfile();
        $merger->update($inData, false, $output);
        $outData = $this->collectResults($output);
        $curData = $this->collectResMetadata2($output, $repo);
        $this->assertEquals('', RdfIoUtil::serialize($refData->copyExcept($outData), 'text/turtle'), 'Missing data');
        $this->assertEquals('', RdfIoUtil::serialize($outData->copyExcept($refData), 'text/turtle'), 'Additional data');
        $this->assertEquals('', RdfIoUtil::serialize($refData->copyExcept($curData), 'text/turtle'), 'Missing data');
        $this->assertEquals('', RdfIoUtil::serialize($outData->copyExcept($curData), 'text/turtle'), 'Additional data');
    }

    /**
     * 
     * @param resource $output
     */
    private function collectResults(mixed $output): Dataset {
        $result = new Dataset();
        fseek($output, 0);
        $result->add(RdfIoUtil::parse($output, new DF(), 'text/turtle'));
        $this->sanitizeResMetadata($result);
        //echo $result;
        return $result;
    }

    /**
     * 
     * @param array<RepoResource> $resources
     */
    private function collectResMetadata(array $resources): Dataset {
        $data = new Dataset();
        foreach ($resources as $i) {
            /** @var RepoResource $i */
            $i->loadMetadata(true);
            $data->add($i->getGraph());
        }
        return $data;
    }

    /**
     * 
     * @param resource $output
     */
    private function collectResMetadata2(mixed $output, Repo $repo): Dataset {
        $result    = new Dataset();
        fseek($output, 0);
        $result->add(RdfIoUtil::parse($output, new DF(), 'text/turtle'));
        $resources = $result->listSubjects()->getValues();
        $resources = array_map(fn($x) => new RepoResource($x, $repo), $resources);
        $data      = $this->collectResMetadata($resources);
        $this->sanitizeResMetadata($data);
        return $data;
    }

    private function sanitizeResMetadata(Dataset $data): void {
        // get rid of technical triples added by the repo
        $data->delete(new PT(new VT('/acl|Date|Role|createdBy/', VT::REGEX)));
        $data->delete(new PT(self::$schema->id, new VT('http://127.0.0.1', VT::STARTS)));

        // fix subjects
        $sbjOrg = $data->getSubject(new PT(self::$schema->id, 'https://d-nb.info/gnd/2016751-9'));
        $data->forEach(fn(QuadInterface $q) => $q->withSubject(DF::namedNode('http://organisation')), new QT($sbjOrg));

        $sbjOrg = $data->getSubject(new PT(self::$schema->id, 'https://d-nb.info/gnd/10056703-4'));
        $data->forEach(fn(QuadInterface $q) => $q->withSubject(DF::namedNode('http://newOrganisation')), new QT($sbjOrg));

        $sbjPer = $data->getSubject(new PT(self::$schema->id, 'https://d-nb.info/gnd/118838113'));
        $data->forEach(fn(QuadInterface $q) => $q->withSubject(DF::namedNode('http://person')), new QT($sbjPer));
    }
}
