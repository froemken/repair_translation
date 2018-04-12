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
class QueryParserTest extends \Nimut\TestingFramework\TestCase\UnitTestCase
{
    /**
     * @var \StefanFroemken\RepairTranslation\Parser\QueryParser
     */
    protected $subject;

    /**
     * @var \TYPO3\CMS\Core\Database\DatabaseConnection
     */
    protected $dbProphecy;

    public function setUp()
    {
        $this->subject = new \StefanFroemken\RepairTranslation\Parser\QueryParser();
        $this->dbProphecy = $this->prophesize('TYPO3\\CMS\\Core\\Database\\DatabaseConnection');
        $GLOBALS['TYPO3_DB'] = $this->dbProphecy->reveal();
    }

    public function tearDown()
    {
        unset($this->subject);
        parent::tearDown();
    }

    /**
     * @test
     */
    public function parseConstraintWithEqualToNummReturnsWhereWithEmptyQuotedString() {
        $this->dbProphecy->fullQuoteStr((string)NULL, 'sys_file_reference')->shouldBeCalled()->willReturn('\'\'');
        $propertyValue = new \TYPO3\CMS\Extbase\Persistence\Generic\Qom\PropertyValue(
            'firstName',
            'tx_myext_domain_model_person'
        );
        $comparison = new \TYPO3\CMS\Extbase\Persistence\Generic\Qom\Comparison(
            $propertyValue,
            \TYPO3\CMS\Extbase\Persistence\QueryInterface::OPERATOR_EQUAL_TO,
            NULL
        );
        $where = [];
        $this->subject->parseConstraint($comparison, $where);

        $this->assertSame(
            array('tx_myext_domain_model_person.firstName=\'\''),
            $where
        );
    }
}
