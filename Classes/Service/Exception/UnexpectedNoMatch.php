<?php
namespace Innologi\TYPO3ExtUpdate\Service\Exception;

/**
 * Unexpected NoMatch Exception
 *
 * @package TYPO3ExtUpdate
 * @author Frenck Lutke
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 2 or later
 */
class UnexpectedNoMatch extends Exception
{

    /**
     *
     * @var string
     */
    protected $message = 'The following values for \'%1$s\' should match but do not: %2$s';
}
