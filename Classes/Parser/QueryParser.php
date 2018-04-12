<?php
namespace StefanFroemken\RepairTranslation\Parser;

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
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Extbase\DomainObject\AbstractDomainObject;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom;

/**
 * QueryParser, converting the qom to string representation
 */
class QueryParser
{
    /**
     * Transforms a constraint into WHERE statements
     *
     * getPreparedQuery and getConstraint of Extbase DataMapper
     * always works with AndConstraints. So, we don't need to check against OR and JOIN
     *
     * @param Qom\ConstraintInterface $constraint The constraint
     * @param array &$where The where fields
     *
     * @return void
     */
    public function parseConstraint(Qom\ConstraintInterface $constraint = null, array &$where)
    {
        if ($constraint instanceof Qom\AndInterface) {
            $this->parseConstraint($constraint->getConstraint1(), $where);
            $this->parseConstraint($constraint->getConstraint2(), $where);
        } elseif ($constraint instanceof Qom\OrInterface) {
            $this->parseConstraint($constraint->getConstraint1(), $where);
            $this->parseConstraint($constraint->getConstraint2(), $where);
        } elseif ($constraint instanceof Qom\NotInterface) {
            $this->parseConstraint($constraint->getConstraint(), $where);
        } elseif ($constraint instanceof Qom\ComparisonInterface) {
            $where[] = sprintf(
                '%s.%s=%s',
                $constraint->getOperand1()->getSelectorName(),
                $constraint->getOperand1()->getPropertyName(),
                $this->getValue($constraint->getOperand2())
            );
        }
    }

    /**
     * Get Value of operand2
     *
     * @param mixed $operand
     *
     * @return string
     */
    protected function getValue($operand)
    {
        if ($operand instanceof AbstractDomainObject) {
            $value = (string)(int)$operand->_getProperty('_localizedUid');
        } elseif (MathUtility::canBeInterpretedAsInteger($operand)) {
            $value = (string)$operand;
        } else {
            $value = $this->getDatabaseConnection()->fullQuoteStr((string)$operand, 'sys_file_reference');
        }
        return $value;
    }

    /**
     * Get TYPO3s Database Connection
     *
     * @return \TYPO3\CMS\Core\Database\DatabaseConnection
     */
    protected function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }
}
