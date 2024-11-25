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

use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\refSources\NamedEntityIteratorFile;

/**
 * Description of NamedEntityIteratorFileTest
 *
 * @author zozlak
 */
class NamedEntityIteratorFileTest extends \PHPUnit\Framework\TestCase {

    const SCHEMA = [
        'id'               => 'http://id',
        'modificationDate' => 'http://modDate',
    ];

    public function testFilter(): void {
        $schema = new Schema(self::SCHEMA);
        $iter   = new NamedEntityIteratorFile(__DIR__ . '/data/iteratorFile.ttl', $schema, 'text/turtle');

        $this->assertEquals(6, $iter->count());

        $iter->setFilter(class: 'http://person');
        $this->assertCount(3, $iter);

        $iter->setFilter(class: 'http://person', limit: 2);
        $this->assertCount(2, $iter);

        $iter->setFilter(idMatch: '2$');
        $this->assertCount(2, $iter);

        $iter->setFilter(minModDate: '2024-10-05T12:34:56');
        $this->assertCount(2, $iter);

        $iter->setFilter('http://person', '[23]$', '2024-09-10T00:00:00', 100);
        $this->assertCount(1, $iter);

        $iter->setFilter(class: 'http://other');
        $this->assertCount(0, $iter);
    }
}
