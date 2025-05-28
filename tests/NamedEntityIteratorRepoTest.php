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
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\lib\ingest\MetadataCollection;
use acdhOeaw\arche\refSources\NamedEntityIteratorRepo as NEIR;

/**
 * Description of NamedEntityIteratorRepoTest
 *
 * @author zozlak
 */
class NamedEntityIteratorRepoTest extends \PHPUnit\Framework\TestCase {

    static private PDO $pdo;

    static public function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
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

    public function testFilter(): void {
        $guzzleOpts = ['auth' => ['admin', 'admin']];
        $repo       = Repo::factoryFromUrl('http://127.0.0.1/api/', $guzzleOpts);
        $schema     = $repo->getSchema();
        $mc         = new MetadataCollection($repo, __DIR__ . '/data/iteratorRepo.ttl', 'text/turtle');
        $repo->begin();
        $mc->import($repo->getBaseUrl(), MetadataCollection::CREATE);
        $repo->commit();

        $iter = new NEIR($repo);

        $iter->setFilter([[NEIR::FILTER_CLASS, $schema->classes->person]]);
        $iter->getNamedEntities()->current();
        $this->assertEquals(18, $iter->count());

        $iter->setFilter([[NEIR::FILTER_CLASS, $schema->classes->place]]);
        $iter->getNamedEntities()->current();
        $this->assertEquals(1, $iter->count());

        $iter->setFilter([[NEIR::FILTER_CLASS, $schema->classes->person]], limit: 3);
        $n = 0;
        foreach ($iter->getNamedEntities() as $i) {
            $n++;
        }
        $this->assertEquals(3, $iter->count());
        $this->assertEquals(3, $n);

        $filters = [
            [NEIR::FILTER_CLASS, $schema->classes->person],
            [NEIR::FILTER_ID, 'stuhec'],
        ];
        $iter->setFilter($filters, limit: 30);
        $iter->getNamedEntities()->current();
        $this->assertEquals(1, $iter->count());

        $filters = [
            [NEIR::FILTER_CLASS, $schema->classes->person],
            [NEIR::FILTER_MIN_MOD_DATE, date('Y-m-d H:i:s', time() + 1)],
        ];
        $iter->setFilter($filters);
        $iter->getNamedEntities()->current();
        $this->assertEquals(0, $iter->count());

        $filters = [
            [NEIR::FILTER_CLASS, $schema->classes->person],
            [NEIR::FILTER_MIN_MOD_DATE, date('Y-m-d H:i:s', time() - 10)],
        ];
        $iter->setFilter($filters, limit: 5);
        $iter->getNamedEntities()->current();
        $this->assertEquals(5, $iter->count());
    }
}
