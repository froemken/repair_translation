<?php
namespace StefanFroemken\RepairTranslation\Tests\Unit\Parser;

/*
 * This file is part of the repair_translation project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

/**
 * Test case.
 *
 * @author Stefan Froemken <froemken@gmail.com>
 */
class QueryParserTest extends \TYPO3\CMS\Core\Tests\UnitTestCase
{
    /**
     * @var \StefanFroemken\RepairTranslation\Parser\QueryParser
     */
    protected $subject;

    public function setUp()
    {
        $this->subject = new \StefanFroemken\RepairTranslation\Parser\QueryParser();
    }

    public function tearDown()
    {
        unset($this->subject);
    }

    /**
     * @test
     */
    public function parseConstraintWithEqualToNummReturnsWhereWithEmptyQuotedString() {
        $propertyValue = new \TYPO3\CMS\Extbase\Persistence\Generic\Qom\PropertyValue(
            'firstName',
            'firstName'
        );
        $comparison = new \TYPO3\CMS\Extbase\Persistence\Generic\Qom\Comparison(
            $propertyValue,
            \TYPO3\CMS\Extbase\Persistence\QueryInterface::OPERATOR_EQUAL_TO,
            NULL
        );
        $where = [];
        $this->subject->parseConstraint($comparison, $where);

        $this->assertSame(
            [''],
            $where
        );
    }
}
