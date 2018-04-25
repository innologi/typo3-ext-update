<?php
namespace Innologi\TYPO3ExtUpdate\Exception;

/**
 * Incorrect Extension Version Exception
 *
 * @package TYPO3ExtUpdate
 * @author Frenck Lutke
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 2 or later
 */
class IncorrectExtensionVersion extends Exception
{

    /**
     *
     * @var string
     */
    protected $message = 'Source extension \'%1$s\' needs to be updated to version %2$s.';
}
